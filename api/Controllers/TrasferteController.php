<?php
/**
 * Trasferte Controller — CRUD + rendiconto viaggi
 */

class TrasferteController {
    private $pdo;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    public function list() {
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? null;

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
        $totPedaggio = 0;
        $totVitto = 0;
        $totAlloggio = 0;
        $totAltre = 0;
        foreach ($trasferte as $t) {
            $totKm += floatval($t['km_andata'] ?? 0) + floatval($t['km_ritorno'] ?? 0);
            $totPedaggio += floatval($t['pedaggio'] ?? 0);
            $totVitto += floatval($t['vitto'] ?? 0);
            $totAlloggio += floatval($t['alloggio'] ?? 0);
            $totAltre += floatval($t['altre_spese'] ?? 0);
        }

        Response::json(true, '', [
            'trasferte' => $trasferte,
            'totali' => [
                'num_trasferte' => count($trasferte),
                'km_totali' => round($totKm, 1),
                'pedaggio' => round($totPedaggio, 2),
                'vitto' => round($totVitto, 2),
                'alloggio' => round($totAlloggio, 2),
                'altre_spese' => round($totAltre, 2),
                'totale_spese' => round($totPedaggio + $totVitto + $totAlloggio + $totAltre, 2)
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
            'pedaggio'         => floatval($data['pedaggio'] ?? 0),
            'vitto'            => floatval($data['vitto'] ?? 0),
            'alloggio'         => floatval($data['alloggio'] ?? 0),
            'altre_spese'      => floatval($data['altre_spese'] ?? 0),
            'note_spese'       => trim($data['note_spese'] ?? '')
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
            Response::json(true, 'Trasferta aggiornata', ['id' => $id]);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO {$this->prefix}trasferte ($cols) VALUES ($placeholders)";
            $this->pdo->prepare($sql)->execute(array_values($fields));
            $id = $this->pdo->lastInsertId();
        }

        // Auto-calcula rotta
        $this->calcolaKmPerData($fields['data_trasferta']);

        Response::json(true, $id ? 'Trasferta aggiornata' : 'Trasferta creata', ['id' => $id]);
    }

    public function delete($id) {
        $this->pdo->prepare("DELETE FROM {$this->prefix}trasferte WHERE id = ?")->execute([$id]);
        Response::json(true, 'Trasferta eliminata');
    }

    /**
     * Rendiconto mensile raggruppato per cliente
     */
    public function rendiconto() {
        $year = $_GET['year'] ?? date('Y');
        $month = $_GET['month'] ?? date('m');

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
            $spese = floatval($r['pedaggio']) + floatval($r['vitto']) + floatval($r['alloggio']) + floatval($r['altre_spese']);
            $grouped[$key]['trasferte'][] = $r;
            $grouped[$key]['totale_km'] += $km;
            $grouped[$key]['totale_spese'] += $spese;
        }

        Response::json(true, '', ['rendiconto' => array_values($grouped), 'anno' => $year, 'mese' => $month]);
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
     * Calcola i KM automatici per una specifica giornata considerando Base -> Mattino -> Pomeriggio -> Base
     */
    public function calcolaKmPerData($date) {
        if (!$date) return ['success' => false, 'message' => "Data mancante"];

        // Recupera trasferte della giornata
        $sql = "SELECT t.*, c.indirizzo, c.citta 
                FROM {$this->prefix}trasferte t
                LEFT JOIN {$this->prefix}clienti c ON c.id = t.cliente_id
                WHERE data_trasferta = ?";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$date]);
        $trasferte = $stmt->fetchAll();

        // Se non abbiamo trasferte, non facciamo nulla. Ma magari stiamo cancellando, in tal caso restano 0 km.
        if (empty($trasferte)) {
            return ['success' => false, 'message' => "Nessuna trasferta trovata per questa data."];
        }

        // Funzione helper locale per il geocoding (Nominatim OpenStreetMap)
        $geocode = function($address) {
            $url = "https://nominatim.openstreetmap.org/search?q=" . urlencode($address) . "&format=json&limit=1";
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_USERAGENT, "MVC-ERP/1.0");
            $res = curl_exec($ch);
            curl_close($ch);
            $data = json_decode($res, true);
            if (!empty($data)) {
                return ['lat' => floatval($data[0]['lat']), 'lon' => floatval($data[0]['lon'])];
            }
            return null;
        };

        // Indirizzo base (Partenza e Rientro)
        $baseAddr = "Via Manzoni 5, Zero Branco, TV";
        $baseCoord = $geocode($baseAddr);
        
        if (!$baseCoord) {
            return ['success' => false, 'message' => "Errore nella geocodifica dell'indirizzo base."];
        }

        // Dividi in mattino e pomeriggio per stabilire l'ordine della rotta
        $mattino = null;
        $pomeriggio = null;
        $fallback = []; 
        
        foreach ($trasferte as $t) {
            $addr = trim(($t['indirizzo'] ?? '') . ' ' . ($t['citta'] ?? ''));
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

        $waypoints = [$baseCoord];

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
        
        $waypoints[] = $baseCoord;

        // Se l'unico waypoint è la base (es. nessun cliente con indirizzo valido) -> azzeriamo i km e terminiamo
        if (count($waypoints) <= 2) {
            $sqlUpd = "UPDATE {$this->prefix}trasferte SET km_andata = 0, km_ritorno = 0 WHERE data_trasferta = ?";
            $this->pdo->prepare($sqlUpd)->execute([$date]);
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
        $osrmRes = curl_exec($ch);
        curl_close($ch);
        
        $osrmData = json_decode($osrmRes, true);
        
        if (!isset($osrmData['routes'][0])) {
            return ['success' => false, 'message' => "Impossibile calcolare il percorso su strada."];
        }

        $distanceMeters = $osrmData['routes'][0]['distance'];
        $totKm = round($distanceMeters / 1000, 1);

        $affectedIds = array_filter([$mattino['id'] ?? null, $pomeriggio['id'] ?? null]);
        if (empty($affectedIds)) {
            foreach ($trasferte as $t) {
                if (trim(($t['indirizzo'] ?? '') . ($t['citta'] ?? '')) !== '') {
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

        // Update in DB solo delle tappe valide (le altre a zero)
        $sqlZero = "UPDATE {$this->prefix}trasferte SET km_andata = 0, km_ritorno = 0 WHERE data_trasferta = ?";
        $this->pdo->prepare($sqlZero)->execute([$date]);

        $sqlUpd = "UPDATE {$this->prefix}trasferte SET km_andata = ?, km_ritorno = ? WHERE id = ?";
        $stmtUpd = $this->pdo->prepare($sqlUpd);
        
        foreach ($affectedIds as $tid) {
            $stmtUpd->execute([$kmPerTappaAndata, $kmPerTappaRitorno, $tid]);
        }

        return ['success' => true, 'message' => "KM calcolati automaticamente: $totKm km totali ($count trasferte aggiornate).", 'data' => ['totale_km' => $totKm, 'aggiornate' => $count]];
    }
}
