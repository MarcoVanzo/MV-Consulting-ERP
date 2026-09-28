<?php
/**
 * AnagraficaAuto — clienti e fornitori creati dagli elenchi di fatture, con la partita IVA cercata sul web.
 *
 * Gli elenchi Excel del portale fatture danno solo la ragione sociale: se il soggetto non è in anagrafica
 * si crea col solo nome e piva_ricerca = 'da_cercare' (v088–v089). Poi, una anagrafica per richiesta
 * (la ricerca web dura decine di secondi), cerca() chiede a Claude di trovare la P.IVA sul web, la
 * controlla (cifra di controllo) e la salva. Se la stessa P.IVA è già di un'altra anagrafica dello stesso
 * tipo, quella creata dall'elenco era un doppione: le sue fatture passano all'altra e lei si toglie.
 */
declare(strict_types=1);

require_once __DIR__ . '/AnagraficaMatcher.php';

class AnagraficaAuto
{
    private const TABELLE = ['cliente' => 'clienti', 'fornitore' => 'fornitori'];

    /**
     * Id dell'anagrafica con questo nome; se non c'è la crea (da cercare).
     * $righe: anagrafiche già caricate [{id, partita_iva, codice_fiscale, ragione_sociale}], aggiornate qui.
     * @return array{0:int, 1:bool} id e se è stata creata ora
     */
    public static function trovaOCrea(PDO $pdo, string $prefix, string $tipo, string $nome, array &$righe): array
    {
        $id = AnagraficaMatcher::trovaTra($righe, null, null, $nome);
        if ($id) return [$id, false];
        $nota = 'Creato dall\'elenco fatture il ' . date('d/m/Y') . ': partita IVA cercata in automatico.';
        if ($tipo === 'fornitore') {
            $pdo->prepare("INSERT INTO {$prefix}fornitori (ragione_sociale, tipo, note, piva_ricerca) VALUES (?, 'fornitore', ?, 'da_cercare')")
                ->execute([$nome, $nota]);
        } else {
            $pdo->prepare("INSERT INTO {$prefix}clienti (ragione_sociale, note, piva_ricerca) VALUES (?, ?, 'da_cercare')")
                ->execute([$nome, $nota]);
        }
        $id = (int)$pdo->lastInsertId();
        $righe[] = ['id' => $id, 'partita_iva' => null, 'codice_fiscale' => null, 'ragione_sociale' => $nome];
        return [$id, true];
    }

    /** Anagrafiche ancora da cercare: [{tipo, id, nome}] (anche quelle rimaste da un import precedente). */
    public static function daCercare(PDO $pdo, string $prefix): array
    {
        $out = [];
        foreach (self::TABELLE as $tipo => $t) {
            $filtro = $tipo === 'fornitore' ? ' AND deleted_at IS NULL' : '';
            foreach ($pdo->query("SELECT id, ragione_sociale FROM {$prefix}$t WHERE piva_ricerca = 'da_cercare'$filtro ORDER BY id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $out[] = ['tipo' => $tipo, 'id' => (int)$r['id'], 'nome' => $r['ragione_sociale']];
            }
        }
        return $out;
    }

    /**
     * Cerca sul web la P.IVA di un'anagrafica da cercare e la salva.
     * $cerca(nome, tipo) → testo della risposta AI (sostituibile nei test).
     * @return array{esito:string, partita_iva:?string, unito_a:?int, messaggio:string}
     *         esito: trovata | unita (era un doppione) | non_trovata
     */
    public static function cerca(PDO $pdo, string $prefix, string $tipo, int $id, ?callable $cerca = null): array
    {
        $t = self::TABELLE[$tipo] ?? null;
        if (!$t) throw new InvalidArgumentException('Tipo di anagrafica non valido');
        $stmt = $pdo->prepare("SELECT id, ragione_sociale, partita_iva, piva_ricerca FROM {$prefix}$t WHERE id = ?");
        $stmt->execute([$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r) throw new RuntimeException('Anagrafica non trovata');
        $nome = (string)$r['ragione_sociale'];
        if ($r['piva_ricerca'] !== 'da_cercare') {
            return ['esito' => $r['partita_iva'] ? 'trovata' : 'non_trovata', 'partita_iva' => $r['partita_iva'], 'unito_a' => null, 'messaggio' => "$nome: già cercata."];
        }

        $testo = ($cerca ?? [self::class, 'chiediAlWeb'])($nome, $tipo);
        $dati = self::leggiRisposta($testo);
        $piva = $dati ? self::partitaIvaValida((string)($dati['partita_iva'] ?? '')) : null;
        $nostra = AnagraficaMatcher::normalizzaCodice((string)getenv('AZIENDA_PARTITA_IVA'));
        if ($piva && $nostra !== '' && AnagraficaMatcher::normalizzaCodice($piva) === $nostra) $piva = null;
        if (!$piva) {
            $pdo->prepare("UPDATE {$prefix}$t SET piva_ricerca = 'non_trovata' WHERE id = ?")->execute([$id]);
            return ['esito' => 'non_trovata', 'partita_iva' => null, 'unito_a' => null, 'messaggio' => "$nome: partita IVA non trovata, da inserire a mano."];
        }
        $cf = strtoupper(preg_replace('/\s+/', '', (string)($dati['codice_fiscale'] ?? '')));
        $cf = preg_match('/^([0-9]{11}|[A-Z0-9]{16})$/', $cf) ? $cf : null;

        // Stessa P.IVA già in anagrafica (stesso tipo): questa era un doppione creato dall'elenco
        $gemello = null;
        foreach (AnagraficaMatcher::conPartitaIva($pdo, $prefix, $piva) as $a) {
            if ($a['id'] !== $id && ($a['tipo'] === 'cliente') === ($tipo === 'cliente')) { $gemello = $a; break; }
        }
        $pdo->beginTransaction();
        try {
            if ($gemello) {
                self::unisci($pdo, $prefix, $tipo, $id, $gemello['id']);
                $pdo->commit();
                return ['esito' => 'unita', 'partita_iva' => $piva, 'unito_a' => $gemello['id'],
                    'messaggio' => "$nome: è {$gemello['nome']} (P.IVA $piva), già in anagrafica. Fatture spostate lì."];
            }
            $pdo->prepare("UPDATE {$prefix}$t SET partita_iva = ?, codice_fiscale = COALESCE(codice_fiscale, ?), piva_ricerca = 'trovata' WHERE id = ?")
                ->execute([$piva, $cf, $id]);
            $pdo->commit();
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            throw $e;
        }
        return ['esito' => 'trovata', 'partita_iva' => $piva, 'unito_a' => null, 'messaggio' => "$nome: P.IVA $piva."];
    }

    /** Sposta fatture (e costi) dal doppione $da all'anagrafica $a e toglie il doppione. */
    private static function unisci(PDO $pdo, string $prefix, string $tipo, int $da, int $a): void
    {
        if ($tipo === 'fornitore') {
            // Una fattura già presente anche sull'altro fornitore (stesso numero e data) è la stessa: si tiene quella
            $stmt = $pdo->prepare("SELECT id, numero, data_emissione FROM {$prefix}fatture_passive WHERE fornitore_id = ?");
            $stmt->execute([$da]);
            $esiste = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}fatture_passive WHERE fornitore_id = ? AND numero = ? AND data_emissione = ?");
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $f) {
                $esiste->execute([$a, $f['numero'], $f['data_emissione']]);
                if ((int)$esiste->fetchColumn() > 0) $pdo->prepare("DELETE FROM {$prefix}fatture_passive WHERE id = ?")->execute([$f['id']]);
                else $pdo->prepare("UPDATE {$prefix}fatture_passive SET fornitore_id = ? WHERE id = ?")->execute([$a, $f['id']]);
            }
            $pdo->prepare("UPDATE {$prefix}commessa_costi SET fornitore_id = ? WHERE fornitore_id = ?")->execute([$a, $da]);
            $pdo->prepare("UPDATE {$prefix}fornitori SET deleted_at = CURRENT_TIMESTAMP, piva_ricerca = 'trovata' WHERE id = ?")->execute([$da]);
            return;
        }
        foreach (['fatture', 'incarichi', 'offerte', 'spese'] as $t) {
            $pdo->prepare("UPDATE {$prefix}$t SET cliente_id = ? WHERE cliente_id = ?")->execute([$a, $da]);
        }
        $pdo->prepare("DELETE FROM {$prefix}clienti WHERE id = ?")->execute([$da]);
    }

    /** Domanda a Claude con ricerca web; risposta attesa: un oggetto JSON (vedi leggiRisposta). */
    public static function chiediAlWeb(string $nome, string $tipo): string
    {
        require_once __DIR__ . '/ClaudeClient.php';
        $ruolo = $tipo === 'fornitore' ? 'un fornitore che ci ha mandato una fattura' : 'un cliente a cui abbiamo emesso una fattura';
        $system = 'Cerchi dati anagrafici di aziende italiane ed estere su fonti pubbliche (siti aziendali, registri, '
            . 'elenchi di partite IVA). Rispondi solo con un oggetto JSON, senza altro testo.';
        $domanda = "Trova la partita IVA di questa azienda, $ruolo di MV Consulting S.r.l. (società di consulenza italiana).\n"
            . "Ragione sociale come compare in fattura: «{$nome}»\n\n"
            . "Se l'azienda ha una sede o stabile organizzazione in Italia che fattura (es. \"Sede Secondaria\"), vale la sua partita IVA italiana. "
            . "Se ci sono più aziende con nomi simili e non sai quale sia, non tirare a indovinare.\n"
            . "Rispondi con: {\"trovata\": true|false, \"partita_iva\": \"11 cifre, oppure prefisso paese + numero se estera\", "
            . "\"codice_fiscale\": \"se diverso dalla P.IVA, altrimenti vuoto\", \"ragione_sociale\": \"denominazione ufficiale\", \"fonte\": \"URL\"}";
        return ClaudeClient::cercaSulWeb($system, $domanda, 5);
    }

    /** Oggetto JSON dentro la risposta (anche se circondato da testo); null se manca o se non trovata. */
    public static function leggiRisposta(string $testo): ?array
    {
        if (!preg_match('/\{.*\}/s', $testo, $m)) return null;
        $d = json_decode($m[0], true);
        return is_array($d) && !empty($d['trovata']) ? $d : null;
    }

    /** P.IVA normalizzata se valida: italiana a 11 cifre con cifra di controllo, o estera col prefisso paese. */
    public static function partitaIvaValida(string $piva): ?string
    {
        $p = strtoupper(preg_replace('/[\s.\-]+/', '', $piva));
        if (preg_match('/^(IT)?(\d{11})$/', $p, $m)) return self::controlloItaliana($m[2]) ? $m[2] : null;
        // Estera: prefisso di un paese UE (o CHE, GB…) + 2–13 caratteri
        if (preg_match('/^(?!IT)[A-Z]{2}[A-Z0-9]{2,13}$/', $p) && preg_match('/\d{2,}/', $p)) return $p;
        return null;
    }

    private static function controlloItaliana(string $n): bool
    {
        if ($n === '00000000000') return false;
        $s = 0;
        for ($i = 0; $i < 10; $i++) {
            $c = (int)$n[$i];
            if ($i % 2 === 1) { $c *= 2; if ($c > 9) $c -= 9; }
            $s += $c;
        }
        return (10 - $s % 10) % 10 === (int)$n[10];
    }
}
