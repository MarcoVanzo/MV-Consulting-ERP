<?php
/**
 * Incarichi Controller — Gestione Commesse / Assignments
 * CRUD + Overview + PDF Import + Ricalcolo automatico importi
 */

require_once __DIR__ . '/../Shared/Documenti.php';
require_once __DIR__ . '/../Shared/Indicatori.php';
require_once __DIR__ . '/../Shared/DocumentAi.php';
require_once __DIR__ . '/../Shared/AnagraficaMatcher.php';
require_once __DIR__ . '/../Shared/IncaricoPdfParser.php';

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

        $p = $this->prefix;
        $sql = "SELECT i.*, 
                c.ragione_sociale as cliente_nome,
                sc.nome as sottocliente_nome,
                o.numero as offerta_numero,
                (SELECT COUNT(*) FROM {$p}incarichi_rate r WHERE r.incarico_id = i.id) as num_rate,
                (SELECT COALESCE(SUM(cc.importo_previsto), 0) FROM {$p}commessa_costi cc WHERE cc.incarico_id = i.id) as costi_previsti
            FROM {$p}incarichi i
            LEFT JOIN {$p}clienti c ON c.id = i.cliente_id
            LEFT JOIN {$p}sottoclienti sc ON sc.id = i.sottocliente_id
            LEFT JOIN {$p}offerte o ON o.id = i.offerta_id
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
     * Alla creazione nasce anche il piano di fatturazione: una rata unica di saldo,
     * da dettagliare poi nella scheda commessa.
     */
    public function save($data) {
        $p = $this->prefix;
        $id = !empty($data['id']) ? (int)$data['id'] : null;

        $fields = [
            'cliente_id'           => !empty($data['cliente_id']) ? (int)$data['cliente_id'] : null,
            'sottocliente_id'      => !empty($data['sottocliente_id']) ? (int)$data['sottocliente_id'] : null,
            'data_incarico'        => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($data['data_incarico'] ?? '')) ? $data['data_incarico'] : date('Y-m-d'),
            'tipo_commessa'        => in_array($data['tipo_commessa'] ?? '', array_keys(CommessaService::TIPI), true) ? $data['tipo_commessa'] : 'assistenza',
            'numero_protocollo'    => !empty($data['numero_protocollo']) ? trim($data['numero_protocollo']) : null,
            'descrizione'          => trim((string)($data['descrizione'] ?? '')) ?: null,
            'num_giornate'         => floatval($data['num_giornate'] ?? 0),
            'importo_totale'       => round(floatval($data['importo_totale'] ?? 0), 2),
            'giorni_pagamento'     => max(0, (int)($data['giorni_pagamento'] ?? 30)),
            'condizioni_pagamento' => trim((string)($data['condizioni_pagamento'] ?? '')) ?: null,
            'note'                 => trim($data['note'] ?? '')
        ];
        // PDF archiviato dall'import: si accetta solo un riferimento valido in storage/documenti
        if (!empty($data['pdf_path']) && Documenti::percorso($data['pdf_path'])) {
            $fields['pdf_path'] = $data['pdf_path'];
        }

        if (empty($fields['cliente_id'])) {
            Response::json(false, 'Cliente obbligatorio');
        }
        if ($fields['importo_totale'] <= 0) {
            Response::json(false, 'Importo totale deve essere maggiore di zero');
        }

        $this->pdo->beginTransaction();
        // Sottocliente letto dal PDF ma non ancora in anagrafica
        $nuovoSotto = trim((string)($data['sottocliente_nuovo'] ?? ''));
        if (!$fields['sottocliente_id'] && $nuovoSotto !== '') {
            $this->pdo->prepare("INSERT INTO {$p}sottoclienti (cliente_id, nome) VALUES (?, ?)")->execute([$fields['cliente_id'], $nuovoSotto]);
            $fields['sottocliente_id'] = (int)$this->pdo->lastInsertId();
        }

        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $this->pdo->prepare("UPDATE {$p}incarichi SET $sets WHERE id = ?")->execute(array_merge(array_values($fields), [$id]));
            // Piano con una sola rata ancora da fatturare: segue il nuovo importo
            $stmt = $this->pdo->prepare("SELECT id, fattura_id FROM {$p}incarichi_rate WHERE incarico_id = ?");
            $stmt->execute([$id]);
            $rate = $stmt->fetchAll();
            if (count($rate) === 1 && !$rate[0]['fattura_id']) {
                $this->pdo->prepare("UPDATE {$p}incarichi_rate SET importo = ?, percentuale = 100, giorni_pagamento = ? WHERE id = ?")
                    ->execute([$fields['importo_totale'], $fields['giorni_pagamento'], $rate[0]['id']]);
            }
            // Piano con più rate (o già fatturato): non si ritocca da solo, ma si avvisa se non torna
            $stmt = $this->pdo->prepare("SELECT COUNT(*) AS n, COALESCE(SUM(importo), 0) AS somma FROM {$p}incarichi_rate WHERE incarico_id = ?");
            $stmt->execute([$id]);
            $piano = $stmt->fetch();
            $warning = null;
            if ((int)$piano['n'] > 0 && abs((float)$piano['somma'] - $fields['importo_totale']) > 0.01) {
                $warning = 'Le rate del piano sommano a ' . number_format((float)$piano['somma'], 2, ',', '.')
                    . ' € ma l\'importo dell\'incarico è ' . number_format($fields['importo_totale'], 2, ',', '.')
                    . ' €: aggiorna il piano di fatturazione nella scheda commessa.';
            }
            $this->pdo->commit();
            $this->recalculate($id);

            Audit::log('UPDATE', 'incarichi', $id, null, null, [
                'tipo_commessa' => $fields['tipo_commessa'],
                'importo_totale' => $fields['importo_totale']
            ]);
            Response::json(true, 'Incarico aggiornato' . ($warning ? '. Attenzione: ' . $warning : ''), ['id' => $id, 'warning' => $warning]);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $this->pdo->prepare("INSERT INTO {$p}incarichi ($cols) VALUES ($placeholders)")->execute(array_values($fields));
            $newId = (int)$this->pdo->lastInsertId();
            $dataFatt = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($data['data_fatturazione'] ?? '')) ? $data['data_fatturazione'] : null;
            $this->pdo->prepare("INSERT INTO {$p}incarichi_rate (incarico_id, ordine, descrizione, percentuale, importo, data_prevista, giorni_pagamento)
                VALUES (?, 1, 'Saldo', 100, ?, ?, ?)")->execute([$newId, $fields['importo_totale'], $dataFatt, $fields['giorni_pagamento']]);
            $this->pdo->commit();
            Audit::log('INSERT', 'incarichi', $newId, null, null, [
                'tipo_commessa' => $fields['tipo_commessa'],
                'importo_totale' => $fields['importo_totale']
            ]);
            Response::json(true, 'Incarico creato', ['id' => $newId]);
        }
    }

    /**
     * Elimina un incarico (slega le fatture collegate; l'offerta d'origine torna "inviata")
     */
    public function delete($id) {
        $p = $this->prefix;
        $this->pdo->beginTransaction();
        $this->pdo->prepare("UPDATE {$p}fatture SET incarico_id = NULL WHERE incarico_id = ?")->execute([$id]);
        $this->pdo->prepare("UPDATE {$p}offerte SET stato = 'inviata', incarico_id = NULL, data_esito = NULL WHERE incarico_id = ?")->execute([$id]);
        // I costi nati sull'offerta tornano all'offerta, gli altri spariscono con l'incarico (FK)
        $this->pdo->prepare("UPDATE {$p}commessa_costi SET incarico_id = NULL WHERE incarico_id = ? AND offerta_id IS NOT NULL")->execute([$id]);
        $this->pdo->prepare("DELETE FROM {$p}incarichi WHERE id = ?")->execute([$id]);
        $this->pdo->commit();
        Audit::log('DELETE', 'incarichi', $id, null, null, null);
        Response::json(true, 'Incarico eliminato');
    }

    /**
     * Overview — KPI incarichi per anno
     */
    public function overview() {
        $year = $_POST['year'] ?? $_GET['year'] ?? date('Y');
        $p = $this->prefix;

        // KPI: definizioni uniche in Indicatori (docs/indicatori.md) + conteggi per stato della commessa
        $ind = new Indicatori($this->pdo, $p);
        $kpis = $ind->commesse((int)$year);
        $stmt = $this->pdo->prepare("SELECT stato, COUNT(*) AS n FROM {$p}incarichi
            WHERE data_incarico BETWEEN ? AND ? GROUP BY stato");
        $stmt->execute(["$year-01-01", "$year-12-31"]);
        $chiavi = ['attivo' => 'num_attivi', 'parziale' => 'num_parziali', 'fatturato' => 'num_fatturati', 'pagato' => 'num_pagati'];
        foreach ($chiavi as $k) $kpis[$k] = 0;
        foreach ($stmt->fetchAll() as $r) {
            if (isset($chiavi[$r['stato']])) $kpis[$chiavi[$r['stato']]] = (int)$r['n'];
        }
        $kpisFatture = $ind->fatture((int)$year, $ind->clientiEsclusi());

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
            'kpis_fatture' => $kpisFatture,
            'per_tipo' => $perTipo,
            'per_cliente' => $perCliente,
            'fatture_non_pagate' => $fattureNonPagate,
            'anno' => $year
        ]);
    }

    /**
     * Import della lettera d'incarico (es. Unindustria).
     * Via principale: Claude legge il PDF (file). Riserva: parser a regole sul testo estratto da pdf.js (pages[]).
     * Restituisce i dati per precompilare il form: il salvataggio resta manuale, dopo la verifica.
     */
    public function importPdf($data) {
        $p = $this->prefix;
        $pages = is_array($data['pages'] ?? null) ? $data['pages'] : [];
        $avvisi = [];
        $ref = null;
        try {
            $ref = Documenti::salvaUpload('file');
        } catch (RuntimeException $e) {
            $avvisi[] = $e->getMessage();
        }

        $extracted = null;
        $metodo = 'regole';
        $pdfPath = Documenti::percorso($ref);
        if ($pdfPath && ClaudeClient::isConfigured()) {
            try {
                $ai = DocumentAi::estraiIncarico(['pdf_base64' => base64_encode((string)file_get_contents($pdfPath))]);
                $metodo = 'ai';
                $clienteId = AnagraficaMatcher::trovaCliente($this->pdo, $p, $ai['cliente']['partita_iva'] ?? null,
                    $ai['cliente']['codice_fiscale'] ?? null, (string)($ai['cliente']['nome'] ?? ''));
                $sottoNome = trim((string)($ai['sottocliente']['nome'] ?? ''));
                $sottoId = ($clienteId && $sottoNome !== '') ? AnagraficaMatcher::trovaSottocliente($this->pdo, $p, $clienteId, $sottoNome) : null;
                $extracted = [
                    'cliente_id' => $clienteId,
                    'sottocliente_id' => $sottoId,
                    // Sottocliente letto ma non in anagrafica: il form propone di crearlo
                    'sottocliente_nuovo' => ($clienteId && !$sottoId && $sottoNome !== '') ? $sottoNome : null,
                    'data_incarico' => $ai['data_incarico'],
                    'importo_totale' => $ai['importo_totale'] !== null ? round((float)$ai['importo_totale'], 2) : 0,
                    'num_giornate' => $ai['num_giornate'] ?? 0,
                    'tipo_commessa' => $ai['tipo_commessa'],
                    'numero_protocollo' => $ai['numero_protocollo'],
                    'descrizione' => $ai['descrizione'],
                    'condizioni_pagamento' => $ai['condizioni_pagamento'],
                    'giorni_pagamento' => $ai['giorni_pagamento'],
                ];
                if (!$clienteId) $avvisi[] = 'Cliente "' . ($ai['cliente']['nome'] ?? '?') . '" non trovato in anagrafica.';
                if (!empty($ai['note_estrazione'])) $avvisi[] = $ai['note_estrazione'];
            } catch (RuntimeException $e) {
                error_log('[Incarichi::importPdf] AI: ' . $e->getMessage());
                $avvisi[] = 'Lettura AI non riuscita (' . $e->getMessage() . '): dati letti con il metodo a regole, verificali.';
            }
        } elseif (!ClaudeClient::isConfigured()) {
            $avvisi[] = 'Lettura AI non attiva (manca ANTHROPIC_API_KEY): dati letti con il metodo a regole, verificali.';
        }

        if ($extracted === null) {
            if (!$pages) {
                Documenti::elimina($ref);
                Response::json(false, 'Nessun testo leggibile nel PDF');
            }
            $x = IncaricoPdfParser::parse($pages);
            $clienteId = AnagraficaMatcher::trovaCliente($this->pdo, $p, null, null, $x['full_text'], true);
            $extracted = [
                'cliente_id' => $clienteId,
                'sottocliente_id' => $clienteId ? AnagraficaMatcher::trovaSottocliente($this->pdo, $p, $clienteId, $x['full_text']) : null,
                'data_incarico' => $x['data_incarico'],
                'importo_totale' => $x['importo_totale'],
                'num_giornate' => $x['num_giornate'],
                'tipo_commessa' => $x['tipo_commessa'],
                'numero_protocollo' => $x['numero_protocollo'],
            ];
        }
        $extracted['pdf_path'] = $pdfPath ? $ref : null;
        $extracted['metodo'] = $metodo;
        $extracted['avvisi'] = $avvisi;
        Response::json(true, 'Analisi PDF completata', $extracted);
    }

    public function documento($id) {
        $stmt = $this->pdo->prepare("SELECT pdf_path, numero_protocollo FROM {$this->prefix}incarichi WHERE id = ?");
        $stmt->execute([(int)$id]);
        $i = $stmt->fetch();
        if (!$i) Response::json(false, 'Incarico non trovato', null, 404);
        Documenti::invia($i['pdf_path'], 'incarico-' . ($i['numero_protocollo'] ?: $id));
    }

    /**
     * Ricalcola importo_fatturato e importo_pagato di un incarico
     * dalle fatture collegate
     */
    public function recalculate($incaricoId) {
        $p = $this->prefix;

        // Somma fatturato: imponibile (netto IVA), confrontabile con l'importo dell'incarico
        $stmt = $this->pdo->prepare("SELECT 
            COALESCE(SUM(imponibile), 0) as fatturato,
            COALESCE(SUM(CASE WHEN stato = 'pagata' THEN imponibile ELSE 0 END), 0) as pagato
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
