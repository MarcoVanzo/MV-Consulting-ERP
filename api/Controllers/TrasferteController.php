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
                sc.nome as sottocliente_nome
            FROM {$this->prefix}trasferte t
            LEFT JOIN {$this->prefix}clienti c ON c.id = t.cliente_id
            LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = t.sottocliente_id
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
            'km_bloccati'      => !empty($data['km_bloccati']) ? 1 : 0
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
            Logger::logAction('UPDATE', 'trasferte', $id, ['data_trasferta' => $fields['data_trasferta'], 'cliente_id' => $fields['cliente_id']]);
            Response::json(true, 'Trasferta aggiornata', ['id' => $id]);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO {$this->prefix}trasferte ($cols) VALUES ($placeholders)";
            $this->pdo->prepare($sql)->execute(array_values($fields));
            $id = $this->pdo->lastInsertId();
            Logger::logAction('INSERT', 'trasferte', $id, ['data_trasferta' => $fields['data_trasferta'], 'cliente_id' => $fields['cliente_id']]);
        }

        // Auto-calcula rotta
        // Auto-calcula rotta solo se la trasferta non è bloccata
        // Ma per ricalcolare tutta la giornata potremmo volerlo comunque,
        // la logica dentro calcolaKmPerData salterà quelle bloccate.
        $this->calcolaKmPerData($fields['data_trasferta']);

        Response::json(true, $id ? 'Trasferta aggiornata' : 'Trasferta creata', ['id' => $id]);
    }

    public function delete($id) {
        $this->pdo->prepare("DELETE FROM {$this->prefix}trasferte WHERE id = ?")->execute([$id]);
        Logger::logAction('DELETE', 'trasferte', $id);
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
            } catch (\Exception $e) {}
        }
        Response::json(true, "Calcolo eseguito per tutte le trasferte del periodo selezionato ($countAffected aggiornate).");
    }

    /**
     * Calcola i KM automatici per una specifica giornata considerando Base -> Mattino -> Pomeriggio -> Base
     */
    public function calcolaKmPerData($date) {
        if (!$date) return ['success' => false, 'message' => "Data mancante"];

        // Recupera trasferte della giornata
        $sql = "SELECT t.*, c.indirizzo, c.citta, sc.indirizzo as sc_indirizzo, sc.citta as sc_citta 
                FROM {$this->prefix}trasferte t
                LEFT JOIN {$this->prefix}clienti c ON c.id = t.cliente_id
                LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = t.sottocliente_id
                WHERE data_trasferta = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date]);
        $trasferte = $stmt->fetchAll();

        // Se non abbiamo trasferte, non facciamo nulla. Ma magari stiamo cancellando, in tal caso restano 0 km.
        if (empty($trasferte)) {
            return ['success' => false, 'message' => "Nessuna trasferta trovata per questa data."];
        }

        // Funzione helper locale per il geocoding (Nominatim OpenStreetMap)
        // Usa cache in-memory per evitare chiamate ripetute e rispetta il rate limit (1 req/sec)
        $geocode = function($address) {
            $cacheKey = md5(strtolower(trim($address)));
            if (isset(self::$geocodeCache[$cacheKey])) {
                return self::$geocodeCache[$cacheKey];
            }

            // Rispetta il rate limit di Nominatim (max 1 req/sec)
            usleep(1100000); // 1.1 secondi

            $url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($address) . "&format=json&limit=1";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, "MVC-ERP/1.0");
            curl_setopt($ch, CURLOPT_TIMEOUT, 10);
            curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 5);
            $res = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErr = curl_error($ch);
            curl_close($ch);

            if ($curlErr) {
                error_log("[Trasferte] Geocode CURL error for '$address': $curlErr");
                return null;
            }
            if ($httpCode !== 200) {
                error_log("[Trasferte] Geocode HTTP $httpCode for '$address'");
                return null;
            }

            $data = json_decode($res, true);
            if (!empty($data) && isset($data[0]['lat'], $data[0]['lon'])) {
                $result = ['lat' => floatval($data[0]['lat']), 'lon' => floatval($data[0]['lon'])];
                self::$geocodeCache[$cacheKey] = $result;
                return $result;
            }

            error_log("[Trasferte] Geocode: nessun risultato per '$address'");
            self::$geocodeCache[$cacheKey] = null; // cache anche i miss per non riprovare
            return null;
        };

        // Controlla se la data precedente aveva pernottamento
        $prevDate = date('Y-m-d', strtotime($date . ' - 1 day'));
        $sqlPrev = "SELECT t.*, c.indirizzo, c.citta, sc.indirizzo as sc_indirizzo, sc.citta as sc_citta 
                FROM {$this->prefix}trasferte t
                LEFT JOIN {$this->prefix}clienti c ON c.id = t.cliente_id
                LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = t.sottocliente_id
                WHERE data_trasferta = ? AND (t.pernottamento = 1 OR t.alloggio > 0)
                ORDER BY t.fascia_oraria DESC LIMIT 1";
        $stmtPrev = $this->pdo->prepare($sqlPrev);
        $stmtPrev->execute([$prevDate]);
        $prevPernottamento = $stmtPrev->fetch();

        // Indirizzo base (Partenza e Rientro)
        $baseAddr = "Via Manzoni 5, Zero Branco, TV";
        $baseCoord = $geocode($baseAddr);
        
        if (!$baseCoord) {
            return ['success' => false, 'message' => "Errore nella geocodifica dell'indirizzo base."];
        }

        $startCoord = $baseCoord;
        if ($prevPernottamento) {
            $prevInd = !empty($prevPernottamento['sc_indirizzo']) ? $prevPernottamento['sc_indirizzo'] : $prevPernottamento['indirizzo'];
            $prevCit = !empty($prevPernottamento['sc_citta']) ? $prevPernottamento['sc_citta'] : $prevPernottamento['citta'];
            $prevAddr = trim(($prevInd ?? '') . ' ' . ($prevCit ?? ''));
            if ($prevAddr) {
                $c = $geocode($prevAddr);
                if ($c) $startCoord = $c;
            }
        }

        // Dividi in mattino e pomeriggio per stabilire l'ordine della rotta
        $mattino = null;
        $pomeriggio = null;
        $fallback = []; 
        
        foreach ($trasferte as $t) {
            $ind = !empty($t['sc_indirizzo']) ? $t['sc_indirizzo'] : $t['indirizzo'];
            $cit = !empty($t['sc_citta']) ? $t['sc_citta'] : $t['citta'];
            $addr = trim(($ind ?? '') . ' ' . ($cit ?? ''));
            if (empty($addr)) continue;
            
            $coord = $geocode($addr);
            if (!$coord) continue;

            $item = ['id' => $t['id'], 'coord' => $coord];
            if ($t['fascia_oraria'] === 'mattino') {
                $mattino = $item;
            } elseif ($t['fascia_oraria'] === 'pomeriggio') {
                $pomeriggio = $item;
            } else {
                $fallback[] = $item;
            }
        }

        // Verifica se OGGI c'è un pernottamento
        $oggiPernotta = false;
        foreach ($trasferte as $t) {
            if ($t['pernottamento'] == 1 || floatval($t['alloggio'] ?? 0) > 0) {
                $oggiPernotta = true;
                break;
            }
        }

        $waypoints = [$startCoord];

        if ($mattino) $waypoints[] = $mattino['coord'];
        if (empty($mattino) && !empty($fallback)) {
            $waypoints[] = $fallback[0]['coord'];
            array_shift($fallback);
        }
        
        if ($pomeriggio) $waypoints[] = $pomeriggio['coord'];
        if (empty($pomeriggio) && !empty($fallback)) {
            $waypoints[] = $fallback[0]['coord'];
            array_shift($fallback);
        }
        
        if (!$oggiPernotta) {
            $waypoints[] = $baseCoord;
        }

        // Se l'unico waypoint è la base (es. nessun cliente con indirizzo valido) -> azzeriamo i km e terminiamo
        if (count($waypoints) <= 2) {
            $sqlUpd = "UPDATE {$this->prefix}trasferte SET km_andata = 0, km_ritorno = 0 WHERE data_trasferta = ? AND km_bloccati = 0";
            $this->pdo->prepare($sqlUpd)->execute([$date]);
            error_log("[Trasferte] Data $date: clienti privi di indirizzo valido, KM azzerati per le non-bloccate.");
            return ['success' => true, 'message' => "Clienti privi di indirizzo. KM azzerati.", 'data' => ['totale_km' => 0, 'aggiornate' => count($trasferte)]];
        }

        // Creazione url OSRM
        $points = [];
        foreach ($waypoints as $wp) {
            $points[] = $wp['lon'] . "," . $wp['lat'];
        }
        $coordStr = implode(";", $points);

        $osrmUrl = "http://router.project-osrm.org/route/v1/driving/$coordStr?overview=false";
        
        $ch = curl_init($osrmUrl);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 15);
        curl_setopt($ch, CURLOPT_CONNECTTIMEOUT, 10);
        $osrmRes = curl_exec($ch);
        $osrmErr = curl_error($ch);
        $osrmHttpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($osrmErr) {
            error_log("[Trasferte] OSRM CURL error for date $date: $osrmErr");
            return ['success' => false, 'message' => "Errore connessione al servizio di routing: $osrmErr"];
        }
        
        $osrmData = json_decode($osrmRes, true);
        
        if (!isset($osrmData['routes'][0])) {
            error_log("[Trasferte] OSRM nessun percorso per data $date (HTTP $osrmHttpCode). Response: " . substr($osrmRes, 0, 500));
            return ['success' => false, 'message' => "Impossibile calcolare il percorso su strada."];
        }

        $distanceMeters = $osrmData['routes'][0]['distance'];
        $totKm = round($distanceMeters / 1000, 1);

        $affectedIds = array_filter([$mattino['id'] ?? null, $pomeriggio['id'] ?? null]);
        if (empty($affectedIds)) {
            foreach ($trasferte as $t) {
                $ind = !empty($t['sc_indirizzo']) ? $t['sc_indirizzo'] : $t['indirizzo'];
                $cit = !empty($t['sc_citta']) ? $t['sc_citta'] : $t['citta'];
                if (trim(($ind ?? '') . ($cit ?? '')) !== '') {
                    $affectedIds[] = $t['id'];
                }
            }
        }

        $count = count($affectedIds);
        if ($count == 0) {
            return ['success' => false, 'message' => "Nessun cliente valido geocodificato per il calcolo."];
        }

        $kmPerTappaAndata = round(($totKm / 2) / $count, 1);
        $kmPerTappaRitorno = round(($totKm / 2) / $count, 1);

        // Update in DB solo delle tappe valide E non bloccate (le altre a zero se non bloccate)
        $sqlZero = "UPDATE {$this->prefix}trasferte SET km_andata = 0, km_ritorno = 0 WHERE data_trasferta = ? AND km_bloccati = 0";
        $this->pdo->prepare($sqlZero)->execute([$date]);

        $sqlUpd = "UPDATE {$this->prefix}trasferte SET km_andata = ?, km_ritorno = ? WHERE id = ? AND km_bloccati = 0";
        $stmtUpd = $this->pdo->prepare($sqlUpd);
        
        foreach ($affectedIds as $tid) {
            $stmtUpd->execute([$kmPerTappaAndata, $kmPerTappaRitorno, $tid]);
        }

        return ['success' => true, 'message' => "KM calcolati automaticamente: $totKm km totali ($count trasferte aggiornate).", 'data' => ['totale_km' => $totKm, 'aggiornate' => $count]];
    }
}
