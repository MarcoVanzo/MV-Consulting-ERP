<?php
/**
 * Incarichi Controller — Gestione Commesse / Assignments
 * CRUD + Overview + PDF Import + Ricalcolo automatico importi
 */

class IncarchiController {
    private $pdo;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    /**
     * Lista incarichi con join clienti/sottoclienti
     */
    public function list() {
        $year = $_POST['year'] ?? $_GET['year'] ?? date('Y');
        $clienteId = $_POST['cliente_id'] ?? $_GET['cliente_id'] ?? null;

        $sql = "SELECT i.*, 
                c.ragione_sociale as cliente_nome,
                sc.nome as sottocliente_nome
            FROM {$this->prefix}incarichi i
            LEFT JOIN {$this->prefix}clienti c ON c.id = i.cliente_id
            LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = i.sottocliente_id
            WHERE YEAR(i.data_incarico) = ?";
        $params = [$year];

        if ($clienteId) {
            $sql .= " AND i.cliente_id = ?";
            $params[] = $clienteId;
        }

        $sql .= " ORDER BY c.ragione_sociale ASC, sc.nome ASC, i.data_incarico DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        Response::json(true, '', $stmt->fetchAll());
    }

    /**
     * Lista incarichi aperti per un cliente specifico (per il selector nel form fattura)
     */
    public function getByCliente() {
        $clienteId = $_POST['cliente_id'] ?? $_GET['cliente_id'] ?? 0;
        if (!$clienteId) {
            Response::json(true, '', []);
            return;
        }

        $sql = "SELECT i.id, i.data_incarico, i.tipo_commessa, i.descrizione,
                    i.importo_totale, i.importo_fatturato, i.importo_pagato, i.stato,
                    i.num_giornate,
                    sc.nome as sottocliente_nome,
                    (i.importo_totale - i.importo_fatturato) as residuo
                FROM {$this->prefix}incarichi i
                LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = i.sottocliente_id
                WHERE i.cliente_id = ? AND i.stato != 'pagato'
                ORDER BY i.data_incarico DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$clienteId]);
        Response::json(true, '', $stmt->fetchAll());
    }

    /**
     * CRUD Incarico — Save (Create / Update)
     */
    public function save($data) {
        $id = $data['id'] ?? null;

        $fields = [
            'cliente_id'      => !empty($data['cliente_id']) ? (int)$data['cliente_id'] : null,
            'sottocliente_id' => !empty($data['sottocliente_id']) ? (int)$data['sottocliente_id'] : null,
            'data_incarico'   => $data['data_incarico'] ?? date('Y-m-d'),
            'tipo_commessa'   => $data['tipo_commessa'] ?? 'assistenza',
            'descrizione'     => trim($data['descrizione'] ?? ''),
            'num_giornate'    => floatval($data['num_giornate'] ?? 0),
            'importo_totale'  => floatval($data['importo_totale'] ?? 0),
            'note'            => trim($data['note'] ?? '')
        ];

        if (empty($fields['cliente_id'])) {
            Response::json(false, 'Cliente obbligatorio');
            return;
        }
        if ($fields['importo_totale'] <= 0) {
            Response::json(false, 'Importo totale deve essere maggiore di zero');
            return;
        }

        if ($id) {
            // Update
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = "$k = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $sql = "UPDATE {$this->prefix}incarichi SET " . implode(', ', $sets) . " WHERE id = ?";
            $this->pdo->prepare($sql)->execute($vals);

            // Ricalcola stato
            $this->recalculate($id);

            Audit::log('UPDATE', 'incarichi', $id, null, null, [
                'tipo_commessa' => $fields['tipo_commessa'],
                'importo_totale' => $fields['importo_totale']
            ]);
            Response::json(true, 'Incarico aggiornato', ['id' => $id]);
        } else {
            // Insert
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO {$this->prefix}incarichi ($cols) VALUES ($placeholders)";
            $this->pdo->prepare($sql)->execute(array_values($fields));
            $newId = $this->pdo->lastInsertId();
            Audit::log('INSERT', 'incarichi', $newId, null, null, [
                'tipo_commessa' => $fields['tipo_commessa'],
                'importo_totale' => $fields['importo_totale']
            ]);
            Response::json(true, 'Incarico creato', ['id' => $newId]);
        }
    }

    /**
     * Elimina un incarico (slega le fatture collegate)
     */
    public function delete($id) {
        // Prima slega le fatture
        $this->pdo->prepare("UPDATE {$this->prefix}fatture SET incarico_id = NULL WHERE incarico_id = ?")->execute([$id]);
        // Poi elimina l'incarico
        $this->pdo->prepare("DELETE FROM {$this->prefix}incarichi WHERE id = ?")->execute([$id]);
        Audit::log('DELETE', 'incarichi', $id, null, null, null);
        Response::json(true, 'Incarico eliminato');
    }

    /**
     * Overview — KPI incarichi per anno
     */
    public function overview() {
        $year = $_POST['year'] ?? $_GET['year'] ?? date('Y');
        $p = $this->prefix;

        // KPI globali incarichi
        $stmt = $this->pdo->prepare("SELECT 
            COUNT(*) as num_incarichi,
            COALESCE(SUM(importo_totale), 0) as totale_incarichi,
            COALESCE(SUM(importo_fatturato), 0) as totale_fatturato,
            COALESCE(SUM(importo_pagato), 0) as totale_pagato,
            COALESCE(SUM(importo_totale - importo_fatturato), 0) as residuo_da_fatturare,
            COALESCE(SUM(importo_fatturato - importo_pagato), 0) as fatturato_non_pagato,
            COUNT(CASE WHEN stato = 'attivo' THEN 1 END) as num_attivi,
            COUNT(CASE WHEN stato = 'parziale' THEN 1 END) as num_parziali,
            COUNT(CASE WHEN stato = 'fatturato' THEN 1 END) as num_fatturati,
            COUNT(CASE WHEN stato = 'pagato' THEN 1 END) as num_pagati
            FROM {$p}incarichi WHERE YEAR(data_incarico) = ?");
        $stmt->execute([$year]);
        $kpis = $stmt->fetch();

        // Per tipo commessa
        $stmt2 = $this->pdo->prepare("SELECT 
            tipo_commessa,
            COUNT(*) as conteggio,
            COALESCE(SUM(importo_totale), 0) as totale
            FROM {$p}incarichi WHERE YEAR(data_incarico) = ?
            GROUP BY tipo_commessa ORDER BY totale DESC");
        $stmt2->execute([$year]);
        $perTipo = $stmt2->fetchAll();

        // Per cliente (top)
        $stmt3 = $this->pdo->prepare("SELECT 
            c.ragione_sociale,
            COUNT(i.id) as num_incarichi,
            COALESCE(SUM(i.importo_totale), 0) as totale,
            COALESCE(SUM(i.importo_fatturato), 0) as fatturato,
            COALESCE(SUM(i.importo_pagato), 0) as pagato
            FROM {$p}incarichi i
            LEFT JOIN {$p}clienti c ON c.id = i.cliente_id
            WHERE YEAR(i.data_incarico) = ?
            GROUP BY i.cliente_id ORDER BY totale DESC LIMIT 10");
        $stmt3->execute([$year]);
        $perCliente = $stmt3->fetchAll();

        // Verifica pagamenti: fatture emesse non pagate
        $stmt4 = $this->pdo->prepare("SELECT 
            f.id, f.numero_fattura, f.data_emissione, f.importo_totale, f.stato,
            f.data_scadenza,
            c.ragione_sociale as cliente_nome,
            sc.nome as sottocliente_nome
            FROM {$p}fatture f
            LEFT JOIN {$p}clienti c ON c.id = f.cliente_id
            LEFT JOIN {$p}sottoclienti sc ON sc.id = f.sottocliente_id
            WHERE YEAR(f.data_emissione) = ? AND f.stato IN ('emessa','inviata','scaduta')
            ORDER BY f.data_emissione ASC");
        $stmt4->execute([$year]);
        $fattureNonPagate = $stmt4->fetchAll();

        Response::json(true, '', [
            'kpis' => $kpis,
            'per_tipo' => $perTipo,
            'per_cliente' => $perCliente,
            'fatture_non_pagate' => $fattureNonPagate,
            'anno' => $year
        ]);
    }

    /**
     * Import PDF incarico — Parsing testo e estrazione dati
     * Usa pdf.js dal frontend per estrarre il testo, qui analizziamo
     */
    public function importPdf($data) {
        $pages = $data['pages'] ?? [];
        if (empty($pages) || !is_array($pages)) {
            Response::json(false, 'Nessun dato di testo trovato');
            return;
        }

        $fullText = implode(' ', $pages);
        $fullText = preg_replace('/\s+/', ' ', $fullText);

        $extracted = [
            'data_incarico' => null,
            'importo_totale' => 0,
            'num_giornate' => 0,
            'tipo_commessa' => 'assistenza',
            'cliente_id' => null,
            'sottocliente_id' => null,
            'descrizione' => ''
        ];

        // Estrai data (DD/MM/YYYY o YYYY-MM-DD)
        if (preg_match('/(\d{2}[\/\-]\d{2}[\/\-]\d{4})/i', $fullText, $m)) {
            $parts = preg_split('/[\/\-]/', trim($m[1]));
            if (count($parts) === 3 && strlen($parts[2]) === 4) {
                $extracted['data_incarico'] = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            }
        }

        // Estrai importo (€ XX.XXX,XX o Euro XX.XXX,XX)
        if (preg_match('/(?:€|euro|importo|compenso|corrispettivo)[\s:]*([0-9\.\,]+)/i', $fullText, $m)) {
            $extracted['importo_totale'] = (float)str_replace(['.', ','], ['', '.'], $m[1]);
        }

        // Estrai numero giornate
        if (preg_match('/(\d+(?:[\.,]\d+)?)\s*(?:giornat[ae]|giorn[io]|gg)/i', $fullText, $m)) {
            $extracted['num_giornate'] = (float)str_replace(',', '.', $m[1]);
        }

        // Rileva tipo commessa
        $textLower = mb_strtolower($fullText, 'UTF-8');
        if (strpos($textLower, 'dpo') !== false || strpos($textLower, 'data protection') !== false || strpos($textLower, 'protezione dati') !== false) {
            $extracted['tipo_commessa'] = 'dpo';
        } elseif (strpos($textLower, 'formazione') !== false || strpos($textLower, 'corso') !== false || strpos($textLower, 'training') !== false) {
            $extracted['tipo_commessa'] = 'formazione';
        } else {
            $extracted['tipo_commessa'] = 'assistenza';
        }

        // Cerca cliente per P.IVA / CF
        preg_match_all('/\b([A-Z0-9]{11,16})\b/i', $fullText, $vatMatches);
        $stmtClienti = $this->pdo->query("SELECT id, partita_iva, codice_fiscale, ragione_sociale FROM {$this->prefix}clienti");
        $allClienti = $stmtClienti->fetchAll();

        if (!empty($vatMatches[1])) {
            foreach ($vatMatches[1] as $candidate) {
                $candidate = strtoupper(str_replace(' ', '', $candidate));
                foreach ($allClienti as $c) {
                    $dbPiva = strtoupper(str_replace([' ', 'IT'], '', $c['partita_iva'] ?? ''));
                    $dbCf = strtoupper(str_replace([' ', 'IT'], '', $c['codice_fiscale'] ?? ''));
                    if (($dbPiva && $dbPiva === $candidate) || ($dbCf && $dbCf === $candidate)) {
                        $extracted['cliente_id'] = $c['id'];
                        break 2;
                    }
                }
            }
        }

        // Se non trovato per P.IVA, cerca per nome nell testo
        if (!$extracted['cliente_id']) {
            foreach ($allClienti as $c) {
                $nomeCliente = mb_strtolower(trim($c['ragione_sociale']), 'UTF-8');
                if (mb_strlen($nomeCliente, 'UTF-8') >= 4 && mb_strpos($textLower, $nomeCliente) !== false) {
                    $extracted['cliente_id'] = $c['id'];
                    break;
                }
            }
        }

        // Cerca sottocliente
        if ($extracted['cliente_id']) {
            $stmtSotto = $this->pdo->prepare("SELECT id, nome FROM {$this->prefix}sottoclienti WHERE cliente_id = ?");
            $stmtSotto->execute([$extracted['cliente_id']]);
            $subs = $stmtSotto->fetchAll();
            foreach ($subs as $sc) {
                $nomeSotto = mb_strtolower(trim($sc['nome']), 'UTF-8');
                if (mb_strlen($nomeSotto, 'UTF-8') >= 4 && mb_strpos($textLower, $nomeSotto) !== false) {
                    $extracted['sottocliente_id'] = $sc['id'];
                    break;
                }
            }
        }

        // Descrizione: prime 500 char del testo
        $extracted['descrizione'] = mb_substr(trim($fullText), 0, 500, 'UTF-8');

        Response::json(true, 'Analisi PDF completata', $extracted);
    }

    /**
     * Ricalcola importo_fatturato e importo_pagato di un incarico
     * dalle fatture collegate
     */
    public function recalculate($incaricoId) {
        $p = $this->prefix;

        // Somma fatturato
        $stmt = $this->pdo->prepare("SELECT 
            COALESCE(SUM(importo_totale), 0) as fatturato,
            COALESCE(SUM(CASE WHEN stato = 'pagata' THEN importo_totale ELSE 0 END), 0) as pagato
            FROM {$p}fatture WHERE incarico_id = ?");
        $stmt->execute([$incaricoId]);
        $row = $stmt->fetch();

        $fatturato = floatval($row['fatturato']);
        $pagato = floatval($row['pagato']);

        // Leggi importo totale dell'incarico
        $stmtInc = $this->pdo->prepare("SELECT importo_totale FROM {$p}incarichi WHERE id = ?");
        $stmtInc->execute([$incaricoId]);
        $incarico = $stmtInc->fetch();
        if (!$incarico) return;

        $importoTotale = floatval($incarico['importo_totale']);

        // Determina stato
        $stato = 'attivo';
        if ($pagato >= $importoTotale - 0.01) {
            $stato = 'pagato';
        } elseif ($fatturato >= $importoTotale - 0.01) {
            $stato = 'fatturato';
        } elseif ($fatturato > 0) {
            $stato = 'parziale';
        }

        $stmtUpd = $this->pdo->prepare("UPDATE {$p}incarichi 
            SET importo_fatturato = ?, importo_pagato = ?, stato = ? WHERE id = ?");
        $stmtUpd->execute([$fatturato, $pagato, $stato, $incaricoId]);
    }

    /**
     * Ricalcola TUTTI gli incarichi (batch, utile per manutenzione)
     */
    public function recalculateAll() {
        $p = $this->prefix;
        $stmt = $this->pdo->query("SELECT id FROM {$p}incarichi");
        $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        foreach ($ids as $id) {
            $this->recalculate($id);
        }
        Response::json(true, count($ids) . ' incarichi ricalcolati');
    }
}
