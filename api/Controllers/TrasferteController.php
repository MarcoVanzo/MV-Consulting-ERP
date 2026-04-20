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
            Response::json(true, 'Trasferta creata', ['id' => $this->pdo->lastInsertId()]);
        }
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
}
