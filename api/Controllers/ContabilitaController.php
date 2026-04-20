<?php
/**
 * Contabilità Controller — Fatture CRUD + Overview finanziaria
 */

class ContabilitaController {
    private $pdo;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    public function list() {
        $year = $_GET['year'] ?? date('Y');
        $stato = $_GET['stato'] ?? null;

        $sql = "SELECT f.*, 
                c.ragione_sociale as cliente_nome,
                sc.nome as sottocliente_nome
            FROM {$this->prefix}fatture f
            LEFT JOIN {$this->prefix}clienti c ON c.id = f.cliente_id
            LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = f.sottocliente_id
            WHERE YEAR(f.data_emissione) = ?";
        $params = [$year];

        if ($stato) {
            $sql .= " AND f.stato = ?";
            $params[] = $stato;
        }

        $sql .= " ORDER BY f.data_emissione DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        Response::json(true, '', $stmt->fetchAll());
    }

    public function save($data) {
        $id = $data['id'] ?? null;

        $imponibile = floatval($data['imponibile'] ?? 0);
        $ivaPerc = floatval($data['iva_percentuale'] ?? 22);
        $importoIva = round($imponibile * $ivaPerc / 100, 2);
        $importoTotale = round($imponibile + $importoIva, 2);

        $fields = [
            'numero_fattura'    => trim($data['numero_fattura'] ?? ''),
            'data_emissione'    => $data['data_emissione'] ?? date('Y-m-d'),
            'cliente_id'        => !empty($data['cliente_id']) ? (int)$data['cliente_id'] : null,
            'sottocliente_id'   => !empty($data['sottocliente_id']) ? (int)$data['sottocliente_id'] : null,
            'descrizione'       => trim($data['descrizione'] ?? ''),
            'imponibile'        => $imponibile,
            'iva_percentuale'   => $ivaPerc,
            'importo_iva'       => $importoIva,
            'importo_totale'    => $importoTotale,
            'stato'             => $data['stato'] ?? 'emessa',
            'data_scadenza'     => !empty($data['data_scadenza']) ? $data['data_scadenza'] : null,
            'data_pagamento'    => !empty($data['data_pagamento']) ? $data['data_pagamento'] : null,
            'metodo_pagamento'  => trim($data['metodo_pagamento'] ?? ''),
            'note'              => trim($data['note'] ?? '')
        ];

        if (empty($fields['numero_fattura'])) {
            Response::json(false, 'Numero fattura obbligatorio');
        }

        if ($id) {
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = "$k = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $sql = "UPDATE {$this->prefix}fatture SET " . implode(', ', $sets) . " WHERE id = ?";
            $this->pdo->prepare($sql)->execute($vals);
            Response::json(true, 'Fattura aggiornata', ['id' => $id]);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO {$this->prefix}fatture ($cols) VALUES ($placeholders)";
            $this->pdo->prepare($sql)->execute(array_values($fields));
            Response::json(true, 'Fattura creata', ['id' => $this->pdo->lastInsertId()]);
        }
    }

    public function delete($id) {
        $this->pdo->prepare("DELETE FROM {$this->prefix}fatture WHERE id = ?")->execute([$id]);
        Response::json(true, 'Fattura eliminata');
    }

    /**
     * Overview finanziaria — KPI e aggregazioni
     */
    public function overview() {
        $year = $_GET['year'] ?? date('Y');
        $p = $this->prefix;

        // Fatturato totale
        $stmt = $this->pdo->prepare("SELECT 
            COALESCE(SUM(importo_totale), 0) as fatturato_totale,
            COALESCE(SUM(CASE WHEN stato = 'pagata' THEN importo_totale ELSE 0 END), 0) as totale_pagato,
            COALESCE(SUM(CASE WHEN stato IN ('emessa','inviata') THEN importo_totale ELSE 0 END), 0) as in_attesa,
            COALESCE(SUM(CASE WHEN stato = 'scaduta' THEN importo_totale ELSE 0 END), 0) as scaduto,
            COUNT(*) as num_fatture,
            COUNT(CASE WHEN stato = 'pagata' THEN 1 END) as num_pagate,
            COUNT(CASE WHEN stato IN ('emessa','inviata') THEN 1 END) as num_attesa,
            COUNT(CASE WHEN stato = 'scaduta' THEN 1 END) as num_scadute
            FROM {$p}fatture WHERE YEAR(data_emissione) = ?");
        $stmt->execute([$year]);
        $kpis = $stmt->fetch();

        // Fatturato mensile (per grafico)
        $stmt2 = $this->pdo->prepare("SELECT 
            MONTH(data_emissione) as mese,
            COALESCE(SUM(importo_totale), 0) as fatturato,
            COALESCE(SUM(CASE WHEN stato = 'pagata' THEN importo_totale ELSE 0 END), 0) as pagato
            FROM {$p}fatture WHERE YEAR(data_emissione) = ?
            GROUP BY MONTH(data_emissione) ORDER BY mese ASC");
        $stmt2->execute([$year]);
        $monthly = $stmt2->fetchAll();

        // Top clienti per fatturato
        $stmt3 = $this->pdo->prepare("SELECT 
            c.ragione_sociale,
            COALESCE(SUM(f.importo_totale), 0) as fatturato
            FROM {$p}fatture f
            LEFT JOIN {$p}clienti c ON c.id = f.cliente_id
            WHERE YEAR(f.data_emissione) = ?
            GROUP BY f.cliente_id ORDER BY fatturato DESC LIMIT 5");
        $stmt3->execute([$year]);
        $topClienti = $stmt3->fetchAll();

        Response::json(true, '', [
            'kpis' => $kpis,
            'mensile' => $monthly,
            'top_clienti' => $topClienti,
            'anno' => $year
        ]);
    }
}
