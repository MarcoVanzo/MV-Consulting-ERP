<?php
/**
 * TrasferteSync — porta gli eventi di Google Calendar nella tabella trasferte.
 *
 * Una riga per evento e per giorno (google_event_id = "<evento>_<Y-m-d>"). Regole:
 * - gli eventi che non sono trasferte ("Non disponibile", "Annullato"...) non si importano;
 * - una riga ritoccata a mano (modifica_manuale = 1) non si riscrive più;
 * - a lettura completa di un calendario, le righe che non corrispondono più a nessun evento
 *   (evento cancellato o spostato di giorno) si eliminano, salvo quelle ritoccate a mano,
 *   che si contano e si segnalano;
 * - i giorni di un mese con la nota spese presentata non si toccano: niente righe nuove, aggiornate
 *   o eliminate (i totali della nota sono congelati finché non la si riapre).
 * Le query sono SQL portabile: tests/trasferte_cli.php le prova su SQLite.
 */
declare(strict_types=1);

require_once __DIR__ . '/TrasferteRegole.php';
require_once __DIR__ . '/CalendarioMatcher.php';
require_once __DIR__ . '/Spese.php';

class TrasferteSync
{
    private PDO $pdo;
    private string $prefix;
    private bool $manuale;
    private CalendarioMatcher $matcher;

    /** id delle righe che corrispondono a un evento letto in questa sincronizzazione */
    private array $viste = [];
    public array $dateToccate = [];
    public int $importate = 0;
    public int $aggiornate = 0;
    public int $rimosse = 0;
    public int $manualiOrfane = 0;
    /** Giorni saltati perché il loro mese ha la nota spese presentata */
    public int $congelati = 0;
    /** AAAA-MM => nota presentata sì/no */
    private array $mesiPresentati = [];
    public array $senzaCliente = [];

    public function __construct(PDO $pdo, string $prefix, bool $haColonnaManuale)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
        $this->manuale = $haColonnaManuale;
        $clienti = $pdo->query("SELECT id, ragione_sociale FROM {$prefix}clienti WHERE ragione_sociale != ''")->fetchAll(PDO::FETCH_ASSOC);
        $sottoclienti = $pdo->query("SELECT id, nome, cliente_id FROM {$prefix}sottoclienti WHERE nome != ''")->fetchAll(PDO::FETCH_ASSOC);
        $this->matcher = new CalendarioMatcher($clienti, $sottoclienti);
    }

    public function importaEvento(string $calId, array $event): void
    {
        $summary = trim((string)($event['summary'] ?? ''));
        if (($event['status'] ?? '') === 'cancelled' || TrasferteRegole::daSaltare($summary)) return;

        $giorni = TrasferteRegole::giorniEvento($event['start'] ?? [], $event['end'] ?? []);
        if (!$giorni) return;

        $location = (string)($event['location'] ?? '');
        $description = (string)($event['description'] ?? '');
        $descDb = trim("Titolo Originario: $summary\n" . ($description !== '' ? "Descrizione: $description" : ''));
        $fascia = TrasferteRegole::fasciaEvento($event['start'] ?? [], $event['end'] ?? []);
        [$clienteId, $sottoId] = $this->matcher->abbina($summary, $location, $description);
        if (!$clienteId && count($this->senzaCliente) < 8) $this->senzaCliente[] = $summary;

        $colManuale = $this->manuale ? ', modifica_manuale' : ', 0 AS modifica_manuale';
        $cerca = $this->pdo->prepare("SELECT id, cliente_id, sottocliente_id, descrizione, fascia_oraria, luogo_arrivo $colManuale
            FROM {$this->prefix}trasferte WHERE google_event_id = ? OR (google_event_id = ? AND data_trasferta = ?)");

        foreach ($giorni as $giorno) {
            if ($this->presentato($giorno)) { $this->congelati++; continue; }
            $uniqueId = $event['id'] . '_' . $giorno;
            $cerca->execute([$uniqueId, $event['id'], $giorno]);
            $riga = $cerca->fetch(PDO::FETCH_ASSOC);
            $cerca->closeCursor();

            if ($riga) {
                $this->viste[(int)$riga['id']] = true;
                if (!empty($riga['modifica_manuale'])) continue;
                $this->aggiorna($riga, $giorno, $clienteId, $sottoId, $fascia, $location, $descDb);
                continue;
            }

            $this->pdo->prepare("INSERT INTO {$this->prefix}trasferte
                (data_trasferta, luogo_arrivo, descrizione, google_event_id, google_calendar_id, cliente_id, sottocliente_id, fascia_oraria)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
                ->execute([$giorno, $location, $descDb, $uniqueId, $calId, $clienteId, $sottoId, $fascia]);
            $this->viste[(int)$this->pdo->lastInsertId()] = true;
            $this->importate++;
            $this->dateToccate[$giorno] = true;
        }
    }

    private function aggiorna(array $riga, string $giorno, ?int $clienteId, ?int $sottoId, string $fascia, string $location, string $descDb): void
    {
        $nuovo = [
            'cliente_id' => $riga['cliente_id'],
            'sottocliente_id' => $riga['sottocliente_id'],
            'fascia_oraria' => $fascia,
            'luogo_arrivo' => $location,
            'descrizione' => $descDb,
        ];
        // Il cliente si assegna se manca, o si precisa quando ora si riconosce il sottocliente
        if ($clienteId && (empty($riga['cliente_id'])
            || ($riga['cliente_id'] != $clienteId && $sottoId && empty($riga['sottocliente_id'])))) {
            $nuovo['cliente_id'] = $clienteId;
            $nuovo['sottocliente_id'] = $sottoId;
        }

        $cambiati = [];
        foreach ($nuovo as $k => $v) {
            if (trim((string)$riga[$k]) !== trim((string)$v)) $cambiati[$k] = $v;
        }
        if (!$cambiati) return;

        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($cambiati)));
        $this->pdo->prepare("UPDATE {$this->prefix}trasferte SET $sets WHERE id = ?")
            ->execute([...array_values($cambiati), $riga['id']]);
        $this->aggiornate++;
        // La sola descrizione non cambia il percorso: il ricalcolo km serve per gli altri campi
        if (array_diff_key($cambiati, ['descrizione' => true])) $this->dateToccate[$giorno] = true;
    }

    /**
     * Elimina le righe del calendario nel periodo che non corrispondono più a nessun evento.
     * Da chiamare solo se il calendario è stato letto per intero: con una lettura parziale
     * si cancellerebbero trasferte vere.
     */
    public function rimuoviAssenti(string $calId, string $da, string $a): void
    {
        $colManuale = $this->manuale ? 'modifica_manuale' : '0 AS modifica_manuale';
        $stmt = $this->pdo->prepare("SELECT id, data_trasferta, $colManuale FROM {$this->prefix}trasferte
            WHERE google_calendar_id = ? AND google_event_id IS NOT NULL AND data_trasferta BETWEEN ? AND ?");
        $stmt->execute([$calId, $da, $a]);
        $del = $this->pdo->prepare("DELETE FROM {$this->prefix}trasferte WHERE id = ?");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if (isset($this->viste[(int)$r['id']]) || $this->presentato((string)$r['data_trasferta'])) continue;
            if (!empty($r['modifica_manuale'])) { $this->manualiOrfane++; continue; }
            $del->execute([$r['id']]);
            $this->rimosse++;
            $this->dateToccate[$r['data_trasferta']] = true;
        }
    }

    private function presentato(string $giorno): bool
    {
        $mese = substr($giorno, 0, 7);
        return $this->mesiPresentati[$mese] ??= Spese::mesePresentato($this->pdo, $this->prefix, [$giorno]) !== null;
    }

    public function riepilogo(array $calendariFalliti): string
    {
        $msg = "Sincronizzazione completata: {$this->importate} nuove, {$this->aggiornate} aggiornate, {$this->rimosse} rimosse.";
        if ($this->manualiOrfane) {
            $msg .= " {$this->manualiOrfane} trasferte modificate a mano non corrispondono più a un evento del calendario: controllale.";
        }
        if ($this->congelati) {
            $msg .= " {$this->congelati} giorni non toccati: sono in mesi con la nota spese già presentata.";
        }
        if ($calendariFalliti) {
            $msg .= ' Calendari non letti (riprova): ' . implode(', ', $calendariFalliti) . '.';
        }
        if ($this->senzaCliente) {
            $msg .= ' Eventi senza cliente riconosciuto: ' . implode(' | ', array_slice($this->senzaCliente, 0, 5)) . '.';
        }
        return $msg;
    }
}
