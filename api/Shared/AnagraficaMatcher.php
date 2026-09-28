<?php
/**
 * AnagraficaMatcher — riconosce clienti, sottoclienti e fornitori già in anagrafica
 * partendo da partita IVA / codice fiscale o da un nome (anche scritto in modo diverso:
 * "S.c.ar.l." contro "Società consortile", nomi abbreviati, punteggiatura).
 *
 * $testo può essere il solo nome letto dall'AI oppure l'intero testo di un documento.
 */
declare(strict_types=1);

class AnagraficaMatcher
{
    // Forme giuridiche e parole vuote da ignorare nel confronto dei nomi
    private const STOP_WORDS = ['srl', 'spa', 'sas', 'snc', 'scarl', 'soc', 'societa', 'società',
        'consortile', 'responsabilita', 'responsabilità', 'limitata', 'illimitata',
        'azioni', 'accomandita', 'semplice', 'cooperativa', 'coop',
        'a', 'e', 'di', 'del', 'dei', 'della', 'delle', 'in', 'con', 'per', 'da',
        'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una'];

    /** P.IVA o CF normalizzato: maiuscolo, senza spazi e senza prefisso paese IT. */
    public static function normalizzaCodice(?string $codice): string
    {
        $c = strtoupper(preg_replace('/\s+/', '', (string)$codice) ?? '');
        return strpos($c, 'IT') === 0 && strlen($c) === 13 ? substr($c, 2) : $c;
    }

    /**
     * Cliente in anagrafica: prima per P.IVA/CF, poi per nome.
     * Con $cercaCodiciNelTesto cerca le P.IVA dentro $testo (documento intero).
     */
    public static function trovaCliente(PDO $pdo, string $prefix, ?string $piva, ?string $cf, string $testo, bool $cercaCodiciNelTesto = false): ?int
    {
        $rows = $pdo->query("SELECT id, partita_iva, codice_fiscale, ragione_sociale FROM {$prefix}clienti")->fetchAll();
        return self::trova($rows, 'ragione_sociale', $piva, $cf, $testo, $cercaCodiciNelTesto);
    }

    public static function trovaFornitore(PDO $pdo, string $prefix, ?string $piva, ?string $cf, string $nome): ?int
    {
        $rows = $pdo->query("SELECT id, partita_iva, codice_fiscale, ragione_sociale FROM {$prefix}fornitori WHERE deleted_at IS NULL")->fetchAll();
        return self::trova($rows, 'ragione_sociale', $piva, $cf, $nome, false);
    }

    /**
     * Anagrafiche (clienti e fornitori/partner) che hanno già questa partita IVA: evita i doppioni
     * quando si crea un soggetto nuovo. @return array<array{tipo:string, id:int, nome:string}>
     */
    public static function conPartitaIva(PDO $pdo, string $prefix, string $piva): array
    {
        $piva = self::normalizzaCodice($piva);
        if ($piva === '') return [];
        $out = [];
        $fonti = [['cliente', "SELECT id, partita_iva, ragione_sociale FROM {$prefix}clienti WHERE partita_iva LIKE ?"],
            ['fornitore', "SELECT id, partita_iva, ragione_sociale, tipo FROM {$prefix}fornitori WHERE deleted_at IS NULL AND partita_iva LIKE ?"]];
        foreach ($fonti as [$tipo, $sql]) {
            $stmt = $pdo->prepare($sql);
            $stmt->execute(['%' . $piva . '%']);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                if (self::normalizzaCodice($r['partita_iva']) !== $piva) continue;
                $out[] = ['tipo' => $tipo === 'fornitore' ? ($r['tipo'] ?? 'fornitore') : 'cliente', 'id' => (int)$r['id'], 'nome' => $r['ragione_sociale']];
            }
        }
        return $out;
    }

    /** Sottocliente del cliente dato, riconosciuto per nome. */
    public static function trovaSottocliente(PDO $pdo, string $prefix, int $clienteId, string $testo): ?int
    {
        $stmt = $pdo->prepare("SELECT id, nome FROM {$prefix}sottoclienti WHERE cliente_id = ?");
        $stmt->execute([$clienteId]);
        $subs = $stmt->fetchAll();

        $textLower = mb_strtolower($testo, 'UTF-8');
        $normalize = fn($s) => preg_replace('/[\s.,;:\-\'"\(\)]+/', '', mb_strtolower(trim($s), 'UTF-8'));
        $normText = $normalize($testo);

        $bestId = null;
        $bestScore = 0;
        foreach ($subs as $sc) {
            $nome = mb_strtolower(trim($sc['nome']), 'UTF-8');
            if (mb_strlen($nome, 'UTF-8') < 2) continue;

            // 1. Nome contenuto nel testo, o testo (nome letto) contenuto nel nome
            if (mb_strpos($textLower, $nome) !== false) return (int)$sc['id'];
            $normSotto = $normalize($nome);
            if (mb_strlen($normSotto, 'UTF-8') >= 3 && $normText !== ''
                && (mb_strpos($normText, $normSotto) !== false || mb_strpos($normSotto, $normText) !== false)) {
                if ($bestScore < 900) { $bestId = (int)$sc['id']; $bestScore = 900; }
                continue;
            }

            // 2. Parole significative in comune (es. "ASL Roma 2" → "asl" + "roma")
            $words = array_filter(
                preg_split('/[\s.,;:\-\'"\(\)]+/', $nome, -1, PREG_SPLIT_NO_EMPTY),
                fn($w) => mb_strlen($w, 'UTF-8') >= 3 && !in_array($w, self::STOP_WORDS, true)
            );
            if (!$words) continue;
            $matched = 0;
            foreach ($words as $w) {
                if (preg_match('/\b' . preg_quote($w, '/') . '\b/u', $textLower)) $matched++;
            }
            $score = $matched / count($words);
            if (($matched >= 2 && $score > $bestScore) || (count($words) === 1 && $matched === 1 && $bestScore < 0.5)) {
                $bestId = (int)$sc['id'];
                $bestScore = $score;
            }
        }
        return $bestId;
    }

    /** Come trovaCliente/trovaFornitore su righe già caricate [{id, partita_iva, codice_fiscale, ragione_sociale}]. */
    public static function trovaTra(array $rows, ?string $piva, ?string $cf, string $testo, bool $cercaCodiciNelTesto = false): ?int
    {
        return self::trova($rows, 'ragione_sociale', $piva, $cf, $testo, $cercaCodiciNelTesto);
    }

    /** $parola compare in $testo come parola intera (non dentro un'altra: "Rossi" non trova "Rossini"). */
    private static function contiene(string $testo, string $parola): bool
    {
        return $parola !== '' && (bool)preg_match('/(?<![\p{L}\p{N}])' . preg_quote($parola, '/') . '(?![\p{L}\p{N}])/u', $testo);
    }

    /**
     * Ricerca per codice fiscale/P.IVA, poi per nome. Se più anagrafiche corrispondono allo stesso
     * modo il risultato è ambiguo e si restituisce null: meglio chiedere che indovinare.
     */
    private static function trova(array $rows, string $campoNome, ?string $piva, ?string $cf, string $testo, bool $cercaCodiciNelTesto): ?int
    {
        $codice = fn($r) => [self::normalizzaCodice($r['partita_iva'] ?? ''), self::normalizzaCodice($r['codice_fiscale'] ?? '')];
        // 1. Codici dichiarati (dal documento)
        foreach (array_filter([self::normalizzaCodice($piva), self::normalizzaCodice($cf)]) as $c) {
            foreach ($rows as $r) {
                if (in_array($c, array_filter($codice($r)), true)) return (int)$r['id'];
            }
        }
        // 1b. Codici trovati nel testo: validi solo se indicano una sola anagrafica
        if ($cercaCodiciNelTesto && preg_match_all('/\b(?:IT)?([A-Z0-9]{11,16})\b/i', $testo, $m)) {
            $trovati = [];
            foreach ($m[1] as $c) {
                $c = strtoupper($c);
                foreach ($rows as $r) {
                    if (in_array($c, array_filter($codice($r)), true)) $trovati[(int)$r['id']] = true;
                }
            }
            if (count($trovati) === 1) return (int)array_key_first($trovati);
            if (count($trovati) > 1) return null;
        }

        // 2. Nome
        $textLower = mb_strtolower(trim($testo), 'UTF-8');
        if ($textLower === '') return null;
        $diretti = [];
        $punteggi = [];
        foreach ($rows as $r) {
            $nome = mb_strtolower(trim((string)$r[$campoNome]), 'UTF-8');
            if (mb_strlen($nome, 'UTF-8') < 3) continue;

            // Nome intero nel testo, o testo (nome letto dall'AI) dentro il nome
            if (self::contiene($textLower, $nome) || (mb_strlen($textLower, 'UTF-8') >= 4 && self::contiene($nome, $textLower))) {
                $diretti[(int)$r['id']] = $nome;
                continue;
            }

            // Parole chiave significative del nome presenti nel testo come parole intere
            $cleaned = preg_replace('/\s+/', ' ', trim(preg_replace('/[.\-\',;:\/\\\\()&]+/', ' ', $nome)));
            $keywords = array_values(array_unique(array_filter(
                explode(' ', $cleaned),
                fn($w) => mb_strlen($w, 'UTF-8') >= 4 && !in_array($w, self::STOP_WORDS, true)
            )));
            if (!$keywords) continue;
            $matched = 0;
            foreach ($keywords as $kw) {
                if (self::contiene($textLower, $kw)) $matched++;
            }
            if ($matched >= 2) $punteggi[(int)$r['id']] = $matched / count($keywords) + $matched / 100;
            // Nome di una sola parola significativa (es. "Unindustria"): basta quella
            elseif (count($keywords) === 1 && $matched === 1) $punteggi[(int)$r['id']] = 0.5;
        }
        if ($diretti) {
            if (count($diretti) === 1) return (int)array_key_first($diretti);
            // Più nomi nel testo: vale il più lungo solo se contiene tutti gli altri ("Alfa" e "Alfa Servizi")
            arsort($diretti);
            uasort($diretti, fn($a, $b) => mb_strlen($b, 'UTF-8') <=> mb_strlen($a, 'UTF-8'));
            $lungo = reset($diretti);
            foreach ($diretti as $n) if (!self::contiene($lungo, $n)) return null;
            return (int)array_key_first($diretti);
        }
        if (!$punteggi) return null;
        arsort($punteggi);
        $valori = array_values($punteggi);
        if (count($valori) > 1 && abs($valori[0] - $valori[1]) < 0.0001) return null;
        return (int)array_key_first($punteggi);
    }
}
