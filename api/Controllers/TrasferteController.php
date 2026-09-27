<?php
/**
 * Trasferte Controller — CRUD + rendiconto viaggi
 */

require_once __DIR__ . '/../Shared/TrasferteRegole.php';
require_once __DIR__ . '/../Shared/Percorsi.php';

class TrasferteController {
    private const CHIAVE_COSTO_KM = 'trasferte_costo_km';

    private $pdo;
    private $prefix;
    private Percorsi $percorsi;
    private static ?bool $haColonnaManuale = null;

    /** $percorsi si passa solo nei test, per non chiamare Nominatim e OSRM */
    public function __construct(?Percorsi $percorsi = null) {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
        $this->percorsi = $percorsi ?? new Percorsi($this->pdo, $this->prefix);
    }

    /** Intervallo [primo giorno, ultimo giorno] di un anno o di un mese: si filtra per range, non con YEAR() */
    private function periodo($year, $month): array {
        $y = (int)$year ?: (int)date('Y');
        $m = (int)$month;
        if ($m >= 1 && $m <= 12) {
            $da = sprintf('%04d-%02d-01', $y, $m);
            return [$da, date('Y-m-t', strtotime($da))];
        }
        return ["$y-01-01", "$y-12-31"];
    }

    public function list() {
        [$da, $a] = $this->periodo($_POST['year'] ?? $_GET['year'] ?? date('Y'), $_POST['month'] ?? $_GET['month'] ?? null);

        $sql = "SELECT t.*,
                c.ragione_sociale as cliente_nome, c.citta as cliente_citta,
                sc.nome as sottocliente_nome, sc.citta as sottocliente_citta,
                m.nome as mezzo_nome, m.targa as mezzo_targa
            FROM {$this->prefix}trasferte t
            LEFT JOIN {$this->prefix}clienti c ON c.id = t.cliente_id
            LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = t.sottocliente_id
            LEFT JOIN {$this->prefix}mezzi m ON m.id = t.mezzo_id
            WHERE t.data_trasferta BETWEEN ? AND ?
            ORDER BY t.data_trasferta DESC, t.id ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$da, $a]);
        $trasferte = $stmt->fetchAll();

        $totKm = 0;
        $totVitto = 0;
        $totAlloggio = 0;
        foreach ($trasferte as $t) {
            $totKm += floatval($t['km_andata'] ?? 0) + floatval($t['km_ritorno'] ?? 0);
            $totVitto += floatval($t['vitto'] ?? 0);
            $totAlloggio += floatval($t['alloggio'] ?? 0);
        }
        $giornate = TrasferteRegole::giornate($trasferte);

        Response::json(true, '', [
            'trasferte' => $trasferte,
            'giornate' => $giornate,
            'costo_km' => $this->costoKm(),
            'totali' => [
                'num_trasferte' => count($trasferte),
                'km_totali' => round($totKm, 1),
                'vitto' => round($totVitto, 2),
                'alloggio' => round($totAlloggio, 2),
                'totale_spese' => round($totVitto + $totAlloggio, 2),
                'indennita' => round(array_sum(array_column($giornate, 'indennita')), 2)
            ]
        ]);
    }

    public function save($data) {
        $id = $data['id'] ?? null;
        $isUpdate = (bool)$id;
        if ($errore = TrasferteRegole::errore($data, $isUpdate)) {
            Response::json(false, $errore, null, 422);
        }

        $fields = [
            'cliente_id'       => !empty($data['cliente_id']) ? (int)$data['cliente_id'] : null,
            'sottocliente_id'  => !empty($data['sottocliente_id']) ? (int)$data['sottocliente_id'] : null,
            'data_trasferta'   => $data['data_trasferta'] ?? date('Y-m-d'),
            'descrizione'      => trim($data['descrizione'] ?? ''),
            'luogo_partenza'   => trim($data['luogo_partenza'] ?? Percorsi::indirizzoBase()),
            'luogo_arrivo'     => trim($data['luogo_arrivo'] ?? ''),
            'fascia_oraria'    => $data['fascia_oraria'] ?? 'intera',
            'google_event_id'  => $data['google_event_id'] ?? null,
            'google_calendar_id' => $data['google_calendar_id'] ?? null,
            'km_andata'        => floatval($data['km_andata'] ?? 0),
            'km_ritorno'       => floatval($data['km_ritorno'] ?? 0),
            'vitto'            => floatval($data['vitto'] ?? 0),
            'alloggio'         => floatval($data['alloggio'] ?? 0),
            'note_spese'       => trim($data['note_spese'] ?? ''),
            'pernottamento'    => !empty($data['pernottamento']) ? 1 : 0,
            'km_bloccati'      => !empty($data['km_bloccati']) ? 1 : 0,
            // '' / null / 0 = nessun mezzo: in update toglie il mezzo assegnato
            'mezzo_id'         => (int)($data['mezzo_id'] ?? 0) > 0 ? (int)$data['mezzo_id'] : null
        ];

        $oldDate = null;

        if ($isUpdate) {
            $stmt = $this->pdo->prepare("SELECT data_trasferta FROM {$this->prefix}trasferte WHERE id = ?");
            $stmt->execute([$id]);
            $oldDate = $stmt->fetchColumn();
            if ($oldDate === false) {
                Response::json(false, 'Trasferta non trovata', null, 404);
            }

            // In UPDATE solo i campi inviati: quelli assenti (es. google_event_id) non vanno azzerati
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                if (!array_key_exists($k, $data)) continue;
                $sets[] = "$k = ?";
                $vals[] = $v;
            }
            // Una trasferta ritoccata a mano non si fa più riscrivere dalla sincronizzazione Google
            if ($sets && $this->haColonnaManuale()) $sets[] = 'modifica_manuale = 1';
            if ($sets) {
                $vals[] = $id;
                $sql = "UPDATE {$this->prefix}trasferte SET " . implode(', ', $sets) . " WHERE id = ?";
                $this->pdo->prepare($sql)->execute($vals);
            }
            if (!array_key_exists('data_trasferta', $data)) {
                $fields['data_trasferta'] = $oldDate;
            }
            Audit::log('UPDATE', 'trasferte', $id, null, null, ['data_trasferta' => $fields['data_trasferta'], 'cliente_id' => $fields['cliente_id']]);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO {$this->prefix}trasferte ($cols) VALUES ($placeholders)";
            $this->pdo->prepare($sql)->execute(array_values($fields));
            $id = $this->pdo->lastInsertId();
            Audit::log('INSERT', 'trasferte', $id, null, null, ['data_trasferta' => $fields['data_trasferta'], 'cliente_id' => $fields['cliente_id']]);
        }

        // Ricalcolo km della giornata e delle vicine (la notte fuori sposta la partenza del giorno dopo).
        // Se la data è cambiata, anche la vecchia giornata ha perso una tappa.
        $date = [$fields['data_trasferta']];
        if ($oldDate && $oldDate !== $fields['data_trasferta']) $date[] = $oldDate;
        $esiti = $this->ricalcolaIntorno($date);
        $kmResult = $esiti[$fields['data_trasferta']] ?? [];

        $msg = $isUpdate ? 'Trasferta aggiornata' : 'Trasferta creata';
        if (!empty($kmResult['message'])) {
            $msg .= ' — KM: ' . $kmResult['message'];
        }
        Response::json(true, $msg, ['id' => $id, 'km_result' => $kmResult]);
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("SELECT data_trasferta FROM {$this->prefix}trasferte WHERE id = ?");
        $stmt->execute([$id]);
        $date = $stmt->fetchColumn();
        if ($date === false) {
            Response::json(false, 'Trasferta non trovata', null, 404);
        }

        $this->pdo->prepare("DELETE FROM {$this->prefix}trasferte WHERE id = ?")->execute([$id]);
        Audit::log('DELETE', 'trasferte', $id, null, null, null);

        // Ricalcola i km delle trasferte rimaste nella giornata e in quelle vicine
        $this->ricalcolaIntorno([$date]);
        Response::json(true, 'Trasferta eliminata');
    }

    /**
     * Assegna un mezzo a tutte le trasferte del periodo. Il mezzo non cambia il percorso:
     * nessun ricalcolo km (prima si risalvava ogni trasferta, con un ricalcolo per ciascuna).
     */
    public function setMezzo($data) {
        [$da, $a] = $this->periodo($data['year'] ?? date('Y'), $data['month'] ?? null);
        $mezzoId = (int)($data['mezzo_id'] ?? 0) > 0 ? (int)$data['mezzo_id'] : null;
        if ($mezzoId !== null) {
            $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->prefix}mezzi WHERE id = ?");
            $stmt->execute([$mezzoId]);
            if (!(int)$stmt->fetchColumn()) Response::json(false, 'Mezzo non trovato', null, 404);
        }
        $stmt = $this->pdo->prepare("UPDATE {$this->prefix}trasferte SET mezzo_id = ? WHERE data_trasferta BETWEEN ? AND ?");
        $stmt->execute([$mezzoId, $da, $a]);
        $n = $stmt->rowCount();
        Audit::log('UPDATE', 'trasferte', null, null, null, ['mezzo_id' => $mezzoId, 'da' => $da, 'a' => $a, 'righe' => $n]);
        Response::json(true, $mezzoId ? "Mezzo assegnato a $n trasferte" : "Mezzo rimosso da $n trasferte", ['aggiornate' => $n]);
    }

    /** Costo al km condiviso da tutti i browser (prima stava nel localStorage di ciascuno) */
    public function impostazioni() {
        Response::json(true, '', ['costo_km' => $this->costoKm()]);
    }

    public function salvaCostoKm($data) {
        $v = $data['costo_km'] ?? '';
        if (!is_numeric($v) || (float)$v < 0 || (float)$v > 5) {
            Response::json(false, 'Costo al km non valido (tra 0 e 5 €)', null, 422);
        }
        $valore = number_format((float)$v, 4, '.', '');
        $this->pdo->prepare("REPLACE INTO {$this->prefix}settings (setting_key, setting_value) VALUES (?, ?)")
            ->execute([self::CHIAVE_COSTO_KM, $valore]);
        Audit::log('UPDATE', 'settings', self::CHIAVE_COSTO_KM, null, null, ['costo_km' => $valore]);
        Response::json(true, 'Costo al km salvato', ['costo_km' => (float)$valore]);
    }

    private function costoKm(): ?float {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM {$this->prefix}settings WHERE setting_key = ?");
            $stmt->execute([self::CHIAVE_COSTO_KM]);
            $v = $stmt->fetchColumn();
            return $v === false || $v === null ? null : (float)$v;
        } catch (PDOException $e) {
            return null;
        }
    }

    /** La colonna modifica_manuale arriva con la migrazione v067: finché manca, il codice non la usa */
    public function haColonnaManuale(): bool {
        if (self::$haColonnaManuale === null) {
            try {
                $this->pdo->query("SELECT modifica_manuale FROM {$this->prefix}trasferte LIMIT 1");
                self::$haColonnaManuale = true;
            } catch (PDOException $e) {
                self::$haColonnaManuale = false;
            }
        }
        return self::$haColonnaManuale;
    }

    /**
     * Rendiconto mensile raggruppato per cliente
     */
    public function rendiconto() {
        [$da, $a] = $this->periodo($_POST['year'] ?? $_GET['year'] ?? date('Y'), $_POST['month'] ?? $_GET['month'] ?? date('m'));

        $sql = "SELECT t.*,
                c.ragione_sociale as cliente_nome,
                sc.nome as sottocliente_nome
            FROM {$this->prefix}trasferte t
            LEFT JOIN {$this->prefix}clienti c ON c.id = t.cliente_id
            LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = t.sottocliente_id
            WHERE t.data_trasferta BETWEEN ? AND ?
            ORDER BY c.ragione_sociale ASC, t.data_trasferta ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$da, $a]);
        $rows = $stmt->fetchAll();

        // Group by client
        $grouped = [];
        foreach ($rows as $r) {
            $key = $r['cliente_id'] ?: 'senza_cliente';
            if (!isset($grouped[$key])) {
                $grouped[$key] = [
                    'cliente_nome' => $r['cliente_nome'] ?: 'Senza Cliente',
                    'trasferte' => [],
                    'totale_km' => 0,
                    'totale_spese' => 0
                ];
            }
            $km = floatval($r['km_andata']) + floatval($r['km_ritorno']);
            $spese = floatval($r['vitto']) + floatval($r['alloggio']);
            $grouped[$key]['trasferte'][] = $r;
            $grouped[$key]['totale_km'] += $km;
            $grouped[$key]['totale_spese'] += $spese;
        }

        Response::json(true, '', ['rendiconto' => array_values($grouped), 'anno' => substr($da, 0, 4), 'mese' => substr($da, 5, 2)]);
    }

    /**
     * Endpoint API
     */
    public function togglePernottamento() {
        $date = $_POST['data'] ?? ($_GET['data'] ?? null);
        $state = (isset($_POST['state']) && $_POST['state'] == '1') ? 1 : 0;

        if (!$date || TrasferteRegole::errore(['data_trasferta' => $date], false)) {
            Response::json(false, "Data mancante o non valida");
        }

        $sql = "UPDATE {$this->prefix}trasferte SET pernottamento = ? WHERE data_trasferta = ?";
        $this->pdo->prepare($sql)->execute([$state, $date]);

        // La notte fuori cambia il rientro di oggi e la partenza di domani
        $this->ricalcolaIntorno([$date]);

        Response::json(true, 'Stato pernottamento aggiornato.');
    }

    /**
     * Endpoint API (invocato dal frontend)
     */
    public function calcolaKmGiorno() {
        $date = $_POST['data'] ?? ($_GET['data'] ?? null);
        if (!$date) {
            Response::json(false, "Data mancante");
        }
        $res = $this->calcolaKmPerData($date);
        if ($res['success']) {
            Response::json(true, $res['message'], $res['data'] ?? []);
        } else {
            Response::json(false, $res['message']);
        }
    }

    /**
     * Endpoint API API (invocato dal frontend per ricalcolare tutte le trasferte)
     */
    public function calcolaTuttiKm() {
        // Geocoding con rate limit 1 req/s: la prima volta su un anno intero può durare minuti
        set_time_limit(0);
        ignore_user_abort(true);
        [$da, $a] = $this->periodo($_POST['year'] ?? ($_GET['year'] ?? date('Y')), $_POST['month'] ?? ($_GET['month'] ?? null));

        $stmt = $this->pdo->prepare("SELECT DISTINCT data_trasferta FROM {$this->prefix}trasferte WHERE data_trasferta BETWEEN ? AND ? ORDER BY data_trasferta");
        $stmt->execute([$da, $a]);
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $countAffected = 0;
        $falliti = 0;
        foreach ($dates as $date) {
            try {
                $res = $this->calcolaKmPerData($date);
                if ($res['success']) $countAffected += $res['data']['aggiornate'] ?? 0;
                else $falliti++;
            } catch (\Exception $e) {
                $falliti++;
                error_log("[Trasferte::calcolaTuttiKm] Errore per data $date: " . $e->getMessage());
            }
        }
        $msg = "Calcolo eseguito per le trasferte del periodo selezionato ($countAffected aggiornate).";
        if ($falliti) $msg .= " $falliti giornate non calcolate: controlla indirizzi dei clienti.";
        Response::json(true, $msg);
    }

    /**
     * Ricalcola le giornate indicate e quelle subito prima e dopo: il percorso di un giorno
     * dipende dal giorno prima (si parte da dove si è dormiti) e da quello dopo (si rientra
     * solo se domani non si riparte da fuori). Restituisce l'esito per data.
     */
    public function ricalcolaIntorno(array $date): array {
        $tutte = [];
        foreach ($date as $d) {
            foreach (['-1 day', '+0 day', '+1 day'] as $delta) {
                $tutte[date('Y-m-d', strtotime("$d $delta"))] = true;
            }
        }
        $tutte = array_keys($tutte);
        sort($tutte);
        $esiti = [];
        foreach ($tutte as $d) {
            try {
                $esiti[$d] = $this->calcolaKmPerData($d);
            } catch (\Exception $e) {
                error_log("[Trasferte] Ricalcolo km fallito per $d: " . $e->getMessage());
            }
        }
        return $esiti;
    }

    /**
     * Calcola i KM automatici di una giornata: partenza (base o luogo del pernottamento della
     * notte prima) → tutte le tappe in ordine (mattino, giornata intera, pomeriggio) → base,
     * salvo che si dorma fuori e domani ci sia un'altra trasferta.
     */
    public function calcolaKmPerData($date) {
        if (!$date) return ['success' => false, 'message' => "Data mancante"];

        $trasferte = $this->fetchTrasferteConIndirizzi($date);
        if (empty($trasferte)) {
            return ['success' => false, 'message' => "Nessuna trasferta trovata per questa data."];
        }

        $baseCoord = $this->percorsi->geocode(Percorsi::indirizzoBase());
        if (!$baseCoord) {
            return ['success' => false, 'message' => "Errore nella geocodifica dell'indirizzo base."];
        }

        $ieri = date('Y-m-d', strtotime("$date -1 day"));
        $domani = date('Y-m-d', strtotime("$date +1 day"));
        $trasferteIeri = $this->fetchTrasferteConIndirizzi($ieri);
        $partenzaDaFuori = $this->hasPernottamento($trasferteIeri);
        $startCoord = $partenzaDaFuori ? ($this->ultimaTappa($trasferteIeri) ?? $baseCoord) : $baseCoord;
        $rientro = !($this->hasPernottamento($trasferte) && $this->ciSonoTrasferte($domani));

        $tappe = [];
        foreach (TrasferteRegole::ordinaTappe($trasferte) as $t) {
            $addr = $this->extractAddress($t);
            $coord = $addr !== '' ? $this->percorsi->geocode($addr) : null;
            if ($coord) $tappe[] = ['id' => $t['id'], 'coord' => $coord, 'bloccata' => !empty($t['km_bloccati'])];
        }

        if (!$tappe) {
            $this->zeroKmForDate($date);
            return ['success' => true, 'message' => "Clienti privi di indirizzo. KM azzerati.", 'data' => ['totale_km' => 0, 'aggiornate' => count($trasferte)]];
        }

        $daAggiornare = array_values(array_map(fn($t) => $t['id'], array_filter($tappe, fn($t) => !$t['bloccata'])));
        if (!$daAggiornare) {
            return ['success' => true, 'message' => "KM bloccati su tutte le trasferte della giornata: nessun ricalcolo.", 'data' => ['totale_km' => 0, 'aggiornate' => 0]];
        }

        $punti = array_merge([$startCoord], array_column($tappe, 'coord'), $rientro ? [$baseCoord] : []);
        $routeResult = $this->percorsi->km($punti);
        if (!$routeResult['success']) return $routeResult;

        $totKm = $routeResult['totKm'];
        // I km delle trasferte bloccate sono già fissati: si distribuisce solo il resto del percorso
        $kmBloccati = 0.0;
        foreach ($trasferte as $t) {
            if (!empty($t['km_bloccati'])) $kmBloccati += (float)$t['km_andata'] + (float)$t['km_ritorno'];
        }
        $daDistribuire = max(0.0, round($totKm - $kmBloccati, 1));
        [$andata, $ritorno] = TrasferteRegole::ripartisciKm($daDistribuire, count($daAggiornare), $partenzaDaFuori, $rientro);

        $this->zeroKmForDate($date);
        $stmt = $this->pdo->prepare("UPDATE {$this->prefix}trasferte SET km_andata = ?, km_ritorno = ? WHERE id = ? AND km_bloccati = 0");
        foreach ($daAggiornare as $tid) $stmt->execute([$andata, $ritorno, $tid]);

        $count = count($daAggiornare);
        $msg = "KM calcolati automaticamente: $totKm km totali ($count trasferte aggiornate)";
        if ($kmBloccati > 0) $msg .= ", di cui $kmBloccati km già fissati sulle trasferte bloccate";
        return ['success' => true, 'message' => $msg . '.', 'data' => ['totale_km' => $totKm, 'km_bloccati' => $kmBloccati, 'distribuiti' => $daDistribuire, 'aggiornate' => $count]];
    }

    // ── Private helpers ──────────────────────────────────

    private function fetchTrasferteConIndirizzi(string $date): array {
        $sql = "SELECT t.*, c.indirizzo, c.citta, sc.indirizzo as sc_indirizzo, sc.citta as sc_citta
                FROM {$this->prefix}trasferte t
                LEFT JOIN {$this->prefix}clienti c ON c.id = t.cliente_id
                LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = t.sottocliente_id
                WHERE data_trasferta = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date]);
        return $stmt->fetchAll();
    }

    private function ciSonoTrasferte(string $date): bool {
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->prefix}trasferte WHERE data_trasferta = ?");
        $stmt->execute([$date]);
        return (int)$stmt->fetchColumn() > 0;
    }

    /** Coordinate dell'ultima tappa della giornata: è lì che si è dormito */
    private function ultimaTappa(array $trasferte): ?array {
        foreach (array_reverse(TrasferteRegole::ordinaTappe($trasferte)) as $t) {
            $addr = $this->extractAddress($t);
            if ($addr !== '' && ($c = $this->percorsi->geocode($addr))) return $c;
        }
        return null;
    }

    private function extractAddress(array $row): string {
        $ind = !empty($row['sc_indirizzo']) ? $row['sc_indirizzo'] : ($row['indirizzo'] ?? '');
        $cit = !empty($row['sc_citta']) ? $row['sc_citta'] : ($row['citta'] ?? '');
        return trim("$ind $cit");
    }

    private function hasPernottamento(array $trasferte): bool {
        foreach ($trasferte as $t) {
            if ($t['pernottamento'] == 1 || floatval($t['alloggio'] ?? 0) > 0) return true;
        }
        return false;
    }

    private function zeroKmForDate(string $date): void {
        $this->pdo->prepare("UPDATE {$this->prefix}trasferte SET km_andata = 0, km_ritorno = 0 WHERE data_trasferta = ? AND km_bloccati = 0")->execute([$date]);
    }
}
