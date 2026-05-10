<?php
/**
 * Trasferte Controller — CRUD + rendiconto viaggi
 */

class TrasferteController {
    private $pdo;
    private $prefix;
    private static $geocodeCache = [];

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    public function list() {
        $year = $_POST['year'] ?? $_GET['year'] ?? date('Y');
        $month = $_POST['month'] ?? $_GET['month'] ?? null;

        $sql = "SELECT t.*, 
                c.ragione_sociale as cliente_nome,
                sc.nome as sottocliente_nome,
                m.nome as mezzo_nome, m.targa as mezzo_targa
            FROM {$this->prefix}trasferte t
            LEFT JOIN {$this->prefix}clienti c ON c.id = t.cliente_id
            LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = t.sottocliente_id
            LEFT JOIN {$this->prefix}mezzi m ON m.id = t.mezzo_id
            WHERE YEAR(t.data_trasferta) = ?";
        $params = [$year];

        if ($month) {
            $sql .= " AND MONTH(t.data_trasferta) = ?";
            $params[] = $month;
        }

        $sql .= " ORDER BY t.data_trasferta DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $trasferte = $stmt->fetchAll();

        // Calculate totals
        $totKm = 0;
        $totVitto = 0;
        $totAlloggio = 0;
        foreach ($trasferte as $t) {
            $totKm += floatval($t['km_andata'] ?? 0) + floatval($t['km_ritorno'] ?? 0);
            $totVitto += floatval($t['vitto'] ?? 0);
            $totAlloggio += floatval($t['alloggio'] ?? 0);
        }

        Response::json(true, '', [
            'trasferte' => $trasferte,
            'totali' => [
                'num_trasferte' => count($trasferte),
                'km_totali' => round($totKm, 1),
                'vitto' => round($totVitto, 2),
                'alloggio' => round($totAlloggio, 2),
                'totale_spese' => round($totVitto + $totAlloggio, 2)
            ]
        ]);
    }

    public function save($data) {
        $id = $data['id'] ?? null;
        $fields = [
            'cliente_id'       => !empty($data['cliente_id']) ? (int)$data['cliente_id'] : null,
            'sottocliente_id'  => !empty($data['sottocliente_id']) ? (int)$data['sottocliente_id'] : null,
            'data_trasferta'   => $data['data_trasferta'] ?? date('Y-m-d'),
            'descrizione'      => trim($data['descrizione'] ?? ''),
            'luogo_partenza'   => trim($data['luogo_partenza'] ?? 'Padova'),
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
            'mezzo_id'         => !empty($data['mezzo_id']) ? (int)$data['mezzo_id'] : null
        ];

        if ($id) {
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = "$k = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $sql = "UPDATE {$this->prefix}trasferte SET " . implode(', ', $sets) . " WHERE id = ?";
            $this->pdo->prepare($sql)->execute($vals);
            Audit::log('UPDATE', 'trasferte', $id, null, null, ['data_trasferta' => $fields['data_trasferta'], 'cliente_id' => $fields['cliente_id']]);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO {$this->prefix}trasferte ($cols) VALUES ($placeholders)";
            $this->pdo->prepare($sql)->execute(array_values($fields));
            $id = $this->pdo->lastInsertId();
            Audit::log('INSERT', 'trasferte', $id, null, null, ['data_trasferta' => $fields['data_trasferta'], 'cliente_id' => $fields['cliente_id']]);
        }

        // Auto-calcula rotta
        // Auto-calcula rotta solo se la trasferta non è bloccata
        // Ma per ricalcolare tutta la giornata potremmo volerlo comunque,
        // la logica dentro calcolaKmPerData salterà quelle bloccate.
        $kmResult = $this->calcolaKmPerData($fields['data_trasferta']);

        $msg = $id ? 'Trasferta aggiornata' : 'Trasferta creata';
        if (!empty($kmResult['message'])) {
            $msg .= ' — KM: ' . $kmResult['message'];
        }
        Response::json(true, $msg, ['id' => $id, 'km_result' => $kmResult]);
    }

    public function delete($id) {
        $this->pdo->prepare("DELETE FROM {$this->prefix}trasferte WHERE id = ?")->execute([$id]);
        Audit::log('DELETE', 'trasferte', $id, null, null, null);
        Response::json(true, 'Trasferta eliminata');
    }

    /**
     * Rendiconto mensile raggruppato per cliente
     */
    public function rendiconto() {
        $year = $_POST['year'] ?? $_GET['year'] ?? date('Y');
        $month = $_POST['month'] ?? $_GET['month'] ?? date('m');

        $sql = "SELECT t.*, 
                c.ragione_sociale as cliente_nome,
                sc.nome as sottocliente_nome
            FROM {$this->prefix}trasferte t
            LEFT JOIN {$this->prefix}clienti c ON c.id = t.cliente_id
            LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = t.sottocliente_id
            WHERE YEAR(t.data_trasferta) = ? AND MONTH(t.data_trasferta) = ?
            ORDER BY c.ragione_sociale ASC, t.data_trasferta ASC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$year, $month]);
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

        Response::json(true, '', ['rendiconto' => array_values($grouped), 'anno' => $year, 'mese' => $month]);
    }

    /**
     * Endpoint API
     */
    public function togglePernottamento() {
        $date = $_POST['data'] ?? ($_GET['data'] ?? null);
        $state = (isset($_POST['state']) && $_POST['state'] == '1') ? 1 : 0;
        
        if (!$date) {
            Response::json(false, "Data mancante");
            return;
        }

        $sql = "UPDATE {$this->prefix}trasferte SET pernottamento = ? WHERE data_trasferta = ?";
        $this->pdo->prepare($sql)->execute([$state, $date]);
        
        // Recalculate km for the date
        $this->calcolaKmPerData($date);
        
        // Recalculate km for the next day as well, because this day's overnight stay affects next day's base
        $nextDate = date('Y-m-d', strtotime($date . ' + 1 day'));
        $this->calcolaKmPerData($nextDate);
        
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
        $year = $_POST['year'] ?? ($_GET['year'] ?? date('Y'));
        $month = $_POST['month'] ?? ($_GET['month'] ?? null);

        $sql = "SELECT DISTINCT data_trasferta FROM {$this->prefix}trasferte WHERE YEAR(data_trasferta) = ?";
        $params = [$year];
        if ($month) {
            $sql .= " AND MONTH(data_trasferta) = ?";
            $params[] = $month;
        }

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $dates = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $countAffected = 0;
        foreach ($dates as $date) {
            try {
                $res = $this->calcolaKmPerData($date);
                if ($res['success']) $countAffected += $res['data']['aggiornate'] ?? 0;
            } catch (\Exception $e) {
                error_log("[Trasferte::calcolaTuttiKm] Errore per data $date: " . $e->getMessage());
            }
        }
        Response::json(true, "Calcolo eseguito per tutte le trasferte del periodo selezionato ($countAffected aggiornate).");
    }

    /**
     * Calcola i KM automatici per una specifica giornata considerando Base -> Mattino -> Pomeriggio -> Base
     */
    public function calcolaKmPerData($date) {
        if (!$date) return ['success' => false, 'message' => "Data mancante"];

        $trasferte = $this->fetchTrasferteConIndirizzi($date);
        if (empty($trasferte)) {
            return ['success' => false, 'message' => "Nessuna trasferta trovata per questa data."];
        }

        $baseAddr = getenv('BASE_ADDRESS') ?: "Via Manzoni 5, Zero Branco, TV";
        $baseCoord = $this->geocode($baseAddr);
        if (!$baseCoord) {
            return ['success' => false, 'message' => "Errore nella geocodifica dell'indirizzo base."];
        }

        $prevPernottamento = $this->fetchPreviousOvernightStay($date);
        $startCoord = $this->resolveStartCoord($baseCoord, $prevPernottamento);
        $tappe = $this->getTrasferteTappe($trasferte, $date);
        $oggiPernotta = $this->hasPernottamento($trasferte);
        $wpResult = $this->buildWaypoints($startCoord, $baseCoord, $tappe, $oggiPernotta);

        if (!$wpResult['hasClient']) {
            $this->zeroKmForDate($date);
            return ['success' => true, 'message' => "Clienti privi di indirizzo. KM azzerati.", 'data' => ['totale_km' => 0, 'aggiornate' => count($trasferte)]];
        }

        $routeResult = $this->fetchOsrmRoute($wpResult['waypoints'], $date);
        if (!$routeResult['success']) return $routeResult;

        $affectedIds = $this->collectAffectedIds($tappe, $trasferte);
        if (empty($affectedIds)) {
            return ['success' => false, 'message' => "Nessun cliente valido geocodificato per il calcolo."];
        }

        $totKm = $routeResult['totKm'];
        $this->distributeKm($date, $affectedIds, $totKm, $oggiPernotta, $prevPernottamento);
        $count = count($affectedIds);
        return ['success' => true, 'message' => "KM calcolati automaticamente: $totKm km totali ($count trasferte aggiornate).", 'data' => ['totale_km' => $totKm, 'aggiornate' => $count]];
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

    private function geocode(string $address): ?array {
        $cacheKey = md5(strtolower(trim($address)));
        if (isset(self::$geocodeCache[$cacheKey])) return self::$geocodeCache[$cacheKey];

        usleep(1100000); // Rate limit Nominatim (1 req/sec)

        $url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($address) . "&format=json&limit=1&countrycodes=it";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_USERAGENT, "MV-Consulting-ERP/1.0 (marco@mv-consulting.it)");
        curl_setopt($ch, CURLOPT_TIMEOUT, 10);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
        $res = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlErr = curl_error($ch);
        curl_close($ch);

        if ($curlErr) { error_log("[Trasferte] Geocode CURL error: $curlErr"); return self::$geocodeCache[$cacheKey] = null; }
        if ($httpCode !== 200) { error_log("[Trasferte] Geocode HTTP $httpCode for '$address'"); return self::$geocodeCache[$cacheKey] = null; }

        $data = json_decode($res, true);
        if (!empty($data) && isset($data[0]['lat'], $data[0]['lon'])) {
            return self::$geocodeCache[$cacheKey] = ['lat' => floatval($data[0]['lat']), 'lon' => floatval($data[0]['lon'])];
        }
        error_log("[Trasferte] Geocode: nessun risultato per '$address'");
        return self::$geocodeCache[$cacheKey] = null;
    }

    private function fetchPreviousOvernightStay(string $date) {
        $sql = "SELECT t.*, c.indirizzo, c.citta, sc.indirizzo as sc_indirizzo, sc.citta as sc_citta 
                FROM {$this->prefix}trasferte t
                LEFT JOIN {$this->prefix}clienti c ON c.id = t.cliente_id
                LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = t.sottocliente_id
                WHERE data_trasferta < ? ORDER BY data_trasferta DESC, t.fascia_oraria DESC LIMIT 1";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date]);
        $last = $stmt->fetch();
        if ($last && ($last['pernottamento'] == 1 || floatval($last['alloggio'] ?? 0) > 0)) {
            if (round((strtotime($date) - strtotime($last['data_trasferta'])) / 86400) <= 4) return $last;
        }
        return false;
    }

    private function resolveStartCoord(array $baseCoord, $prevPernottamento): array {
        if (!$prevPernottamento) return $baseCoord;
        $addr = $this->extractAddress($prevPernottamento);
        if ($addr) { $c = $this->geocode($addr); if ($c) return $c; }
        return $baseCoord;
    }

    private function extractAddress(array $row): string {
        $ind = !empty($row['sc_indirizzo']) ? $row['sc_indirizzo'] : ($row['indirizzo'] ?? '');
        $cit = !empty($row['sc_citta']) ? $row['sc_citta'] : ($row['citta'] ?? '');
        return trim("$ind $cit");
    }

    private function getTrasferteTappe(array $trasferte, string $date): array {
        $mattino = null; $pomeriggio = null; $fallback = [];
        foreach ($trasferte as $t) {
            $addr = $this->extractAddress($t);
            if (empty($addr)) continue;
            $coord = $this->geocode($addr);
            if (!$coord) continue;
            $item = ['id' => $t['id'], 'coord' => $coord];
            if ($t['fascia_oraria'] === 'mattino') $mattino = $item;
            elseif ($t['fascia_oraria'] === 'pomeriggio') $pomeriggio = $item;
            else $fallback[] = $item;
        }
        return compact('mattino', 'pomeriggio', 'fallback');
    }

    private function hasPernottamento(array $trasferte): bool {
        foreach ($trasferte as $t) {
            if ($t['pernottamento'] == 1 || floatval($t['alloggio'] ?? 0) > 0) return true;
        }
        return false;
    }

    private function buildWaypoints(array $startCoord, array $baseCoord, array $tappe, bool $oggiPernotta): array {
        $waypoints = [$startCoord];
        $hasClient = false;
        $fb = $tappe['fallback'];

        if ($tappe['mattino']) { $waypoints[] = $tappe['mattino']['coord']; $hasClient = true; }
        elseif (!empty($fb)) { $waypoints[] = array_shift($fb)['coord']; $hasClient = true; }

        if ($tappe['pomeriggio']) { $waypoints[] = $tappe['pomeriggio']['coord']; $hasClient = true; }
        elseif (!empty($fb)) { $waypoints[] = array_shift($fb)['coord']; $hasClient = true; }

        if (!$oggiPernotta) $waypoints[] = $baseCoord;
        return ['waypoints' => $waypoints, 'hasClient' => $hasClient];
    }

    private function zeroKmForDate(string $date): void {
        $this->pdo->prepare("UPDATE {$this->prefix}trasferte SET km_andata = 0, km_ritorno = 0 WHERE data_trasferta = ? AND km_bloccati = 0")->execute([$date]);
    }

    private function fetchOsrmRoute(array $waypoints, string $date): array {
        $points = array_map(fn($wp) => $wp['lon'] . "," . $wp['lat'], $waypoints);
        $url = "https://router.project-osrm.org/route/v1/driving/" . implode(";", $points) . "?overview=false";
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $res = curl_exec($ch);
        $err = curl_error($ch);
        curl_close($ch);
        if ($err) { error_log("[Trasferte] OSRM CURL error: $err"); return ['success' => false, 'message' => "Errore routing: $err"]; }
        $data = json_decode($res, true);
        if (!isset($data['routes'][0])) { error_log("[Trasferte] OSRM nessun percorso per $date"); return ['success' => false, 'message' => "Impossibile calcolare il percorso."]; }
        return ['success' => true, 'totKm' => round($data['routes'][0]['distance'] / 1000, 1)];
    }

    private function collectAffectedIds(array $tappe, array $trasferte): array {
        $ids = array_filter([$tappe['mattino']['id'] ?? null, $tappe['pomeriggio']['id'] ?? null]);
        if (empty($ids)) {
            foreach ($trasferte as $t) { if ($this->extractAddress($t) !== '') $ids[] = $t['id']; }
        }
        return $ids;
    }

    private function distributeKm(string $date, array $ids, float $totKm, bool $oggiPernotta, $prevPernottamento): void {
        $n = count($ids);
        if ($oggiPernotta && !$prevPernottamento)      { $a = round($totKm / $n, 1); $r = 0; }
        elseif (!$oggiPernotta && $prevPernottamento)   { $a = 0; $r = round($totKm / $n, 1); }
        elseif ($oggiPernotta && $prevPernottamento)    { $a = round($totKm / $n, 1); $r = 0; }
        else                                            { $a = round(($totKm / 2) / $n, 1); $r = $a; }

        $this->zeroKmForDate($date);
        $stmt = $this->pdo->prepare("UPDATE {$this->prefix}trasferte SET km_andata = ?, km_ritorno = ? WHERE id = ? AND km_bloccati = 0");
        foreach ($ids as $tid) $stmt->execute([$a, $r, $tid]);
    }
}
