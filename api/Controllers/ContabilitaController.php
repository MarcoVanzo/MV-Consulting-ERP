<?php
/**
 * Contabilità Controller — Fatture CRUD + Overview finanziaria
 */

class ContabilitaController {
    private const STATI_FATTURA = ['emessa', 'inviata', 'pagata', 'scaduta'];
    private $pdo;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    public function list() {
        $year = $_POST['year'] ?? $_GET['year'] ?? date('Y');
        $stato = $_POST['stato'] ?? $_GET['stato'] ?? null;

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
            'incarico_id'       => !empty($data['incarico_id']) ? (int)$data['incarico_id'] : null,
            'descrizione'       => trim($data['descrizione'] ?? ''),
            'imponibile'        => $imponibile,
            'iva_percentuale'   => $ivaPerc,
            'importo_iva'       => $importoIva,
            'importo_totale'    => $importoTotale,
            'stato'             => in_array($data['stato'] ?? '', self::STATI_FATTURA, true) ? $data['stato'] : 'emessa',
            'data_scadenza'     => !empty($data['data_scadenza']) ? $data['data_scadenza'] : null,
            'data_pagamento'    => !empty($data['data_pagamento']) ? $data['data_pagamento'] : null,
            'metodo_pagamento'  => trim($data['metodo_pagamento'] ?? ''),
            'note'              => trim($data['note'] ?? '')
        ];

        if (empty($fields['numero_fattura'])) {
            Response::json(false, 'Numero fattura obbligatorio');
        }

        if ($id) {
            // Incarico collegato prima della modifica: se cambia va ricalcolato anche il vecchio
            $stmtOld = $this->pdo->prepare("SELECT incarico_id, stato, importo_totale FROM {$this->prefix}fatture WHERE id = ?");
            $stmtOld->execute([$id]);
            $old = $stmtOld->fetch() ?: [];
            $oldIncaricoId = $old['incarico_id'] ?? null;
            // Pagamento registrato da un movimento bancario: stato e importo si cambiano annullando la riconciliazione
            if ($old && $this->riconciliazioni((int)$id)
                && ((($old['stato'] ?? '') === 'pagata' && $fields['stato'] !== 'pagata') || abs((float)$old['importo_totale'] - $importoTotale) > 0.005)) {
                Response::json(false, 'La fattura è abbinata a un movimento bancario: per cambiarne stato o importo annulla prima la riconciliazione (Contabilità › Riconciliazione).');
            }

            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = "$k = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $sql = "UPDATE {$this->prefix}fatture SET " . implode(', ', $sets) . " WHERE id = ?";
            $this->pdo->prepare($sql)->execute($vals);
            Audit::log('UPDATE', 'fatture', $id, null, null, ['numero_fattura' => $fields['numero_fattura'], 'importo_totale' => $fields['importo_totale']]);

            // Ricalcola incarico collegato (se presente)
            $this->recalculateLinkedIncarico($id);
            if ($oldIncaricoId && (int)$oldIncaricoId !== (int)$fields['incarico_id']) {
                require_once __DIR__ . '/IncarchiController.php';
                (new IncarchiController())->recalculate($oldIncaricoId);
            }

            Response::json(true, 'Fattura aggiornata', ['id' => $id]);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO {$this->prefix}fatture ($cols) VALUES ($placeholders)";
            $this->pdo->prepare($sql)->execute(array_values($fields));
            $newId = $this->pdo->lastInsertId();
            Audit::log('INSERT', 'fatture', $newId, null, null, ['numero_fattura' => $fields['numero_fattura'], 'importo_totale' => $fields['importo_totale']]);

            // Ricalcola incarico collegato (se presente)
            $this->recalculateLinkedIncarico($newId);

            Response::json(true, 'Fattura creata', ['id' => $newId]);
        }
    }

    public function delete($id) {
        if ($this->riconciliazioni((int)$id)) {
            Response::json(false, 'La fattura è abbinata a un movimento bancario: annulla prima la riconciliazione (Contabilità › Riconciliazione).');
        }
        // Prima recupera l'incarico_id per ricalcolo successivo
        $stmtInc = $this->pdo->prepare("SELECT incarico_id FROM {$this->prefix}fatture WHERE id = ?");
        $stmtInc->execute([$id]);
        $incaricoId = $stmtInc->fetchColumn();

        $this->pdo->prepare("DELETE FROM {$this->prefix}fatture WHERE id = ?")->execute([$id]);
        Audit::log('DELETE', 'fatture', $id, null, null, null);

        // Ricalcola incarico se era collegato
        if ($incaricoId) {
            require_once __DIR__ . '/IncarchiController.php';
            $incCtrl = new IncarchiController();
            $incCtrl->recalculate($incaricoId);
        }

        Response::json(true, 'Fattura eliminata');
    }

    /**
     * Overview finanziaria — KPI e aggregazioni
     */
    public function overview() {
        $year = $_POST['year'] ?? $_GET['year'] ?? date('Y');
        $p = $this->prefix;
        // Clienti tolti da KPI e grafico (scelta dell'utente, salvata sul server). 0 = fatture senza cliente
        $esclusi = $this->clientiEsclusi();
        // Id già interi (clientiEsclusi): scritti nella query, come numeri
        $filtro = $esclusi ? ' AND COALESCE(cliente_id, 0) NOT IN (' . implode(',', $esclusi) . ')' : '';

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
            FROM {$p}fatture WHERE YEAR(data_emissione) = ?$filtro");
        $stmt->execute([$year]);
        $kpis = $stmt->fetch();

        // Fatturato mensile (per grafico)
        $stmt2 = $this->pdo->prepare("SELECT 
            MONTH(data_emissione) as mese,
            COALESCE(SUM(importo_totale), 0) as fatturato,
            COALESCE(SUM(CASE WHEN stato = 'pagata' THEN importo_totale ELSE 0 END), 0) as pagato,
            COUNT(id) as num_fatture
            FROM {$p}fatture WHERE YEAR(data_emissione) = ?$filtro
            GROUP BY MONTH(data_emissione) ORDER BY mese ASC");
        $stmt2->execute([$year]);
        $monthly = $stmt2->fetchAll();

        // Top clienti per fatturato
        $stmt3 = $this->pdo->prepare("SELECT 
            c.ragione_sociale,
            COALESCE(SUM(f.importo_totale), 0) as fatturato
            FROM {$p}fatture f
            LEFT JOIN {$p}clienti c ON c.id = f.cliente_id
            WHERE YEAR(f.data_emissione) = ?" . str_replace('cliente_id', 'f.cliente_id', $filtro) . "
            GROUP BY f.cliente_id ORDER BY fatturato DESC LIMIT 5");
        $stmt3->execute([$year]);
        $topClienti = $stmt3->fetchAll();

        // Clienti con fatture nell'anno, per la scelta di chi contare
        $stmt4 = $this->pdo->prepare("SELECT COALESCE(f.cliente_id, 0) AS id, COALESCE(c.ragione_sociale, 'Senza cliente') AS nome,
                COALESCE(SUM(f.importo_totale), 0) AS fatturato, COUNT(*) AS num_fatture
            FROM {$p}fatture f LEFT JOIN {$p}clienti c ON c.id = f.cliente_id
            WHERE YEAR(f.data_emissione) = ?
            GROUP BY COALESCE(f.cliente_id, 0), c.ragione_sociale ORDER BY nome");
        $stmt4->execute([$year]);
        $clientiAnno = $stmt4->fetchAll();

        Response::json(true, '', [
            'kpis' => $kpis,
            'mensile' => $monthly,
            'top_clienti' => $topClienti,
            'clienti' => $clientiAnno,
            'esclusi' => $esclusi,
            'anno' => $year
        ]);
    }

    /** Salva i clienti da togliere da KPI e grafico: esclusi = JSON [id, ...] (0 = senza cliente). */
    public function salvaFiltroClienti($data) {
        $ids = json_decode((string)($data['esclusi'] ?? '[]'), true);
        if (!is_array($ids)) Response::json(false, 'Elenco clienti non valido', null, 422);
        $ids = array_values(array_unique(array_map('intval', array_filter($ids, 'is_numeric'))));
        $this->pdo->prepare("REPLACE INTO {$this->prefix}settings (setting_key, setting_value) VALUES (?, ?)")
            ->execute([$this->chiaveFiltroClienti(), json_encode($ids)]);
        Response::json(true, 'Scelta salvata', ['esclusi' => $ids]);
    }

    /** Una scelta per utente: chi entra nel conteggio è una preferenza di visualizzazione. */
    private function chiaveFiltroClienti(): string {
        return 'fatture_clienti_esclusi_u' . (int)($GLOBALS['userContext']['id'] ?? 0);
    }

    private function clientiEsclusi(): array {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM {$this->prefix}settings WHERE setting_key = ?");
            $stmt->execute([$this->chiaveFiltroClienti()]);
            $v = json_decode((string)$stmt->fetchColumn(), true);
            return is_array($v) ? array_values(array_map('intval', $v)) : [];
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Import raw PDF text pages and extract invoice DB entries
     */
    public function importPdfData($data) {
        $pages = $data['pages'] ?? [];
        if (empty($pages) || !is_array($pages)) {
            Response::json(false, 'Nessun dato di testo trovato');
            return;
        }

        $imported = 0;
        $errors = [];

        // Preload clients by VAT/CF mapping
        $stmtClienti = $this->pdo->query("SELECT id, partita_iva, codice_fiscale FROM {$this->prefix}clienti");
        $allClienti = $stmtClienti->fetchAll();

        foreach ($pages as $i => $text) {
            $text = preg_replace('/\s+/', ' ', $text); // Normalize whitespace

            // Extract VAT (Partita IVA or Codice Fiscale)
            // Look for lengths of 11 (PIVA) or 16 (CF) alphanumeric without spaces
            preg_match_all('/\b([A-Z0-9]{11,16})\b/i', $text, $vatMatches);
            
            $clienteId = null;
            $foundVat = null;
            if (!empty($vatMatches[1])) {
                foreach ($vatMatches[1] as $candidate) {
                    $candidate = strtoupper(trim($candidate));
                    foreach ($allClienti as $c) {
                        if (($c['partita_iva'] && strtoupper(str_replace(' ', '', $c['partita_iva'])) === $candidate) || 
                            ($c['codice_fiscale'] && strtoupper(str_replace(' ', '', $c['codice_fiscale'])) === $candidate)) {
                            $clienteId = $c['id'];
                            $foundVat = $candidate;
                            break 2;
                        }
                    }
                }
            }

            // Extract Invoice Number
            // "Fattura N. 10/2026" or "Documento N. 10"
            $numero = null;
            if (preg_match('/(?:Fattura\s+N\.|Documento\s+N\.|Fattura N|Nr\.|Numero)[:\s]*([a-zA-Z0-9\-\/]+)/i', $text, $m)) {
                $numero = trim($m[1], " /.-");
            }

            // Extract Date "del 10/05/2026" or "Data: 10/05/2026"
            $dataEmissione = date('Y-m-d');
            if (preg_match('/(?:del|Data|Data Documento|Data Emissione)[\s:]*(\d{2}[\/\-]\d{2}[\/\-]\d{4})/i', $text, $m)) {
                $parts = preg_split('/[\/\-]/', trim($m[1]));
                if (count($parts) === 3) {
                    // Usually DD/MM/YYYY
                    if (strlen($parts[2]) === 4) {
                        $dataEmissione = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
                    }
                }
            }

            // Extract Totale Documento
            $totale = 0;
            if (preg_match('/(?:Totale(?: Documento| Fattura)?|Importo Totale)[\s:€E]*([\d\.,]+)/i', $text, $m)) {
                $totale = (float)str_replace(['.', ','], ['', '.'], $m[1]);
            }

            // Extract Imponibile
            $imponibile = 0;
            if (preg_match('/(?:Imponibile|Totale Imponibile)[\s:€E]*([\d\.,]+)/i', $text, $m)) {
                $imponibile = (float)str_replace(['.', ','], ['', '.'], $m[1]);
            }

            if ($totale > 0 && $imponibile === 0) {
                $imponibile = round($totale / 1.22, 2); // Default to 22% backwards
            }

            if (!$numero || $totale <= 0) {
                // If it doesn't look like an invoice page, skip it
                $errors[] = "Pagina " . ($i + 1) . ": Dati insufficienti (Num: $numero, Tot: $totale).";
                continue;
            }

            if (!$clienteId) {
                // We parse it, but client not found (Assign as NULL for later manual merge)
                $errors[] = "Pagina " . ($i + 1) . ": Fattura $numero letta, ma nessun cliente trovato (P.IVA trovata: $foundVat).";
            }

            // Upsert / Insert ignore duplicate
            $stmtCheck = $this->pdo->prepare("SELECT id FROM {$this->prefix}fatture WHERE numero_fattura = ? AND YEAR(data_emissione) = YEAR(?)");
            $stmtCheck->execute([$numero, $dataEmissione]);
            $existing = $stmtCheck->fetchColumn();

            if ($existing) {
                // Skip
                $errors[] = "Pagina " . ($i + 1) . ": Fattura $numero già presente, caricamento ignorato.";
            } else {
                // Insert
                $importoIva = round($totale - $imponibile, 2);
                $stmtIns = $this->pdo->prepare("INSERT INTO {$this->prefix}fatture 
                    (numero_fattura, data_emissione, cliente_id, imponibile, iva_percentuale, importo_iva, importo_totale, stato, descrizione)
                    VALUES (?, ?, ?, ?, ?, ?, ?, 'emessa', 'Importato da PDF')");
                $stmtIns->execute([$numero, $dataEmissione, $clienteId, $imponibile, 22.00, $importoIva, $totale]);
                $imported++;
            }
        }

        Response::json(true, 'Analisi PDF terminata', [
            'num_imported' => $imported,
            'errors' => $errors
        ]);
    }

    /**
     * Import Fattura Elettronica dall'XML nativo
     */
    public function importXmlData($data) {
        $xmlContent = $data['xml'] ?? '';
        if (empty($xmlContent)) {
            Response::json(false, 'Nessun contenuto XML fornito');
            return;
        }

        // Rimuove i namespace dall'XML per evitare problemi con SimpleXML
        $xmlContent = preg_replace('/(<\/?)(?!xml)[a-zA-Z0-9_-]+:/i', '$1', $xmlContent); // Removes all ns prefixes like p:
        $xmlContent = preg_replace('/\sxmlns=[\'"].*?[\'"]/i', '', $xmlContent); // Removes default namespaces
        $xmlContent = preg_replace('/\sxmlns:[a-zA-Z0-9_-]+=[\'"].*?[\'"]/i', '', $xmlContent); // Removes prefixed namespaces

        // Una FatturaPA non ha DOCTYPE: se c'è, il file non è una fattura (e le entità non vanno espanse)
        if (stripos($xmlContent, '<!DOCTYPE') !== false) {
            Response::json(false, 'File XML non valido: contiene una dichiarazione DOCTYPE');
            return;
        }
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false) {
            $errors = [];
            foreach(libxml_get_errors() as $err) {
                $errors[] = $err->message;
            }
            Response::json(false, 'XML malformato', $errors);
            return;
        }

        // Estrazione testata fattura
        // Se l'XML root è <FatturaElettronica>, i child sono FatturaElettronicaHeader e FatturaElettronicaBody
        $header = $xml->FatturaElettronicaHeader;
        $body = $xml->FatturaElettronicaBody;

        if (!$header || !$body) {
            Response::json(false, 'Nodi principali mancanti nell\'XML (non è una fattura elettronica standard?)');
            return;
        }

        // Cliente (CessionarioCommittente/DatiAnagrafici/IdFiscaleIVA/IdCodice o CodiceFiscale)
        $clientePaese = (string)($header->CessionarioCommittente->DatiAnagrafici->IdFiscaleIVA->IdPaese ?? '');
        $clientePartitaIva = (string)($header->CessionarioCommittente->DatiAnagrafici->IdFiscaleIVA->IdCodice ?? '');
        $clienteCodiceFiscale = (string)($header->CessionarioCommittente->DatiAnagrafici->CodiceFiscale ?? '');

        if (!$clientePartitaIva && !$clienteCodiceFiscale) {
            Response::json(false, 'Dati fiscali (Partita IVA / Codice Fiscale) non trovati nell\'XML.');
            return;
        }

        // Un file può essere un lotto con più documenti (più FatturaElettronicaBody): si importano tutti
        $bodies = [];
        foreach ($xml->FatturaElettronicaBody as $b) $bodies[] = $b;
        $numeroFattura = (string)($bodies[0]->DatiGenerali->DatiGeneraliDocumento->Numero ?? '');
        if (!array_filter($bodies, fn($b) => (string)($b->DatiGenerali->DatiGeneraliDocumento->Numero ?? '') !== '')) {
            Response::json(false, 'Numero fattura non trovato nell\'XML');
            return;
        }
        $conTipoDoc = $this->colonnaTipoDocumento();

        // Pre-carico tutti i clienti e sottoclienti
        $stmtClienti = $this->pdo->query("SELECT id, partita_iva, codice_fiscale FROM {$this->prefix}clienti");
        $allClienti = $stmtClienti->fetchAll();
        $stmtSotto = $this->pdo->query("SELECT id, cliente_id, nome FROM {$this->prefix}sottoclienti");
        $allSottoclienti = $stmtSotto->fetchAll();

        // 1. Trovo il cliente principale per Partita IVA o CF
        $clienteId = null;
        
        // Normalizzo PIVA/CF XML
        $xmlPiva = strtoupper(str_replace(' ', '', $clientePartitaIva));
        if (strpos($xmlPiva, 'IT') === 0) $xmlPiva = substr($xmlPiva, 2);
        
        $xmlCf = strtoupper(str_replace(' ', '', $clienteCodiceFiscale));
        if (strpos($xmlCf, 'IT') === 0) $xmlCf = substr($xmlCf, 2);

        foreach ($allClienti as $c) {
            $dbPiva = strtoupper(str_replace(' ', '', $c['partita_iva'] ?? ''));
            if (strpos($dbPiva, 'IT') === 0) $dbPiva = substr($dbPiva, 2);
            
            $dbCf = strtoupper(str_replace(' ', '', $c['codice_fiscale'] ?? ''));
            if (strpos($dbCf, 'IT') === 0) $dbCf = substr($dbCf, 2);

            if (($xmlPiva && $xmlPiva === $dbPiva) || ($xmlCf && $xmlCf === $dbCf)) {
                $clienteId = $c['id'];
                break;
            }
        }

        $imported = 0;
        $errors = [];

        // Tutto l'import del file in un'unica transazione: o entra tutto o niente
        $this->pdo->beginTransaction();
        try {
            if (!$clienteId && ($clientePartitaIva || $clienteCodiceFiscale)) {
                $ragioneSociale = (string)($header->CessionarioCommittente->DatiAnagrafici->Anagrafica->Denominazione ?? 'Cliente Sconosciuto');
                $indirizzo = (string)($header->CessionarioCommittente->Sede->Indirizzo ?? '');
                $cap = (string)($header->CessionarioCommittente->Sede->CAP ?? '');
                $comune = (string)($header->CessionarioCommittente->Sede->Comune ?? '');
                $provincia = (string)($header->CessionarioCommittente->Sede->Provincia ?? '');
            
                $stmtInsertC = $this->pdo->prepare("INSERT INTO {$this->prefix}clienti (ragione_sociale, partita_iva, codice_fiscale, indirizzo, citta, cap, provincia) VALUES (?, ?, ?, ?, ?, ?, ?)");
                $resC = $stmtInsertC->execute([$ragioneSociale, $clientePartitaIva, $clienteCodiceFiscale, $indirizzo, $comune, $cap, $provincia]);
            
                if ($resC) {
                    $clienteId = $this->pdo->lastInsertId();
                    $errors[] = "Cliente '$ragioneSociale' creato automaticamente.";
                } else {
                    $errInfo = $stmtInsertC->errorInfo();
                    $errors[] = "Impossibile creare il Cliente '$ragioneSociale': " . ($errInfo[2] ?? 'Errore MySQL');
                    $clienteId = null;
                }
            } elseif (!$clienteId) {
                $errors[] = "Impossibile creare il Cliente: P.IVA o CF mancanti nell'XML.";
            }

            foreach ($bodies as $body) {
            [$numeroFattura, $dataEmissione, $tipoDocumento, $isNotaCredito, $segno, $dataScadenza] = $this->datiDocumento($body);
            if ($numeroFattura === '') {
                $errors[] = 'Un documento del lotto non ha il numero: saltato.';
                continue;
            }
            // 2. Analisi delle righe e raggruppamento per Sottocliente
            $raggruppamenti = [];

            // Pre-carico gli incarichi del cliente per il match protocollo
            $allIncarichi = [];
            if ($clienteId) {
                $stmtInc = $this->pdo->prepare("SELECT id, numero_protocollo, sottocliente_id, cliente_id FROM {$this->prefix}incarichi WHERE cliente_id = ? AND numero_protocollo IS NOT NULL AND numero_protocollo != ''");
                $stmtInc->execute([$clienteId]);
                $allIncarichi = $stmtInc->fetchAll();
            }

            $linee = $body->DatiBeniServizi->DettaglioLinee;
            foreach ($linee as $linea) {
                $descrizione = (string)$linea->Descrizione;
                $prezzoTotale = (float)$linea->PrezzoTotale;
                $aliquotaIva = (float)($linea->AliquotaIVA ?? 22.00);

                // Cerchiamo il nome del sottocliente con la regex "presso (nome) Prot."
                $sotto_nome_trovato = null;
                if (preg_match('/presso\s+(.*?)\s+Prot\./i', $descrizione, $m)) {
                    $sotto_nome_trovato = trim($m[1]);
                }

                // Estraiamo il numero protocollo dalla descrizione della riga
                // PRIORITÀ 1: Codice alfanumerico con punti (es. SZ.DPS.F142.26)
                $protocolloRiga = null;
                if (preg_match('/\b([A-Z]{1,5}\.[A-Z]{2,5}\.[A-Z0-9]{2,10}(?:\.[A-Z0-9]{1,6})*)\b/i', $descrizione, $mAlpha)) {
                    $protocolloRiga = strtoupper(trim($mAlpha[1]));
                }
                // PRIORITÀ 2 (fallback): "Prot. n. 1350/2026"
                if (!$protocolloRiga && preg_match('/Prot\.?\s*n\.?\s*(\d+\s*\/\s*\d{4})/i', $descrizione, $mProt)) {
                    $protocolloRiga = preg_replace('/\s+/', '', trim($mProt[1]));
                }

                // Tentiamo di validare in DB tra i sottoclienti del cliente individuato
                $sottoclienteId = null;
                if ($clienteId) {
                    if ($sotto_nome_trovato) {
                        // Search existing with the exact string found in regex
                        $searchSotto = strtolower(str_replace([' ', '.', ','], '', $sotto_nome_trovato));
                        foreach ($allSottoclienti as $sc) {
                            if ($sc['cliente_id'] == $clienteId) {
                                $dbSotto = strtolower(str_replace([' ', '.', ','], '', $sc['nome']));
                                if (strpos($dbSotto, $searchSotto) !== false || strpos($searchSotto, $dbSotto) !== false) {
                                    $sottoclienteId = $sc['id'];
                                    break;
                                }
                            }
                        }
                    
                        // Se non esiste, lo creo
                        if (!$sottoclienteId) {
                            $stmtInsertS = $this->pdo->prepare("INSERT INTO {$this->prefix}sottoclienti 
                                (cliente_id, nome, partita_iva, codice_fiscale, riferimento, indirizzo, citta, cap, provincia, pec, sdi, email) 
                                VALUES (?, ?, '', '', '', '', '', '', '', '', '', '')");
                            $resS = $stmtInsertS->execute([$clienteId, $sotto_nome_trovato]);
                            if ($resS) {
                                $sottoclienteId = $this->pdo->lastInsertId();
                                // Aggiorno la cache array per non ricrearlo in righe successive della stessa fattura
                                $allSottoclienti[] = ['id' => $sottoclienteId, 'cliente_id' => $clienteId, 'nome' => $sotto_nome_trovato];
                                $errors[] = "Sottocliente '$sotto_nome_trovato' creato automaticamente.";
                            } else {
                                // Fallback se l'insert fallisce
                                $errInfo = $stmtInsertS->errorInfo();
                                $errors[] = "Impossibile creare il sottocliente '$sotto_nome_trovato': " . ($errInfo[2] ?? 'Errore MySQL');
                                $sottoclienteId = null;
                            }
                        }
                    } else {
                        // FALLBACK: Se la regex non ha catturato nulla (es. "viaggio a...", "trasferta per...") 
                        // controlliamo se il nome di uno dei sottoclienti compare direttamente nella descrizione.
                        $descClean = mb_strtolower($descrizione, 'UTF-8');
                        foreach ($allSottoclienti as $sc) {
                            if ($sc['cliente_id'] == $clienteId && !empty($sc['nome'])) {
                                $dbSotto = mb_strtolower(trim($sc['nome']), 'UTF-8');
                                if (mb_strlen($dbSotto, 'UTF-8') >= 4) {
                                    $escapedDbSotto = preg_quote($dbSotto, '/');
                                    // Check if name is found as a whole word
                                    if (preg_match('/\b' . $escapedDbSotto . '\b/iu', $descClean)) {
                                        $sottoclienteId = $sc['id'];
                                        break;
                                    }
                                    // Partial string matching for longer names
                                    if (mb_strlen($dbSotto, 'UTF-8') >= 7 && mb_strpos($descClean, $dbSotto) !== false) {
                                        $sottoclienteId = $sc['id'];
                                        break;
                                    }
                                }
                            }
                        }
                    }
                }

                // Prepariamo una chiave per accumulare importi dello stesso sottocliente.
                // Se nessun sottocliente -> ID = 'none' (finisce nel blocco principale senza sottocliente)
                $groupKey = $sottoclienteId ? $sottoclienteId : 'none';

                if (!isset($raggruppamenti[$groupKey])) {
                    $raggruppamenti[$groupKey] = [
                        'imponibile' => 0.0,
                        'iva' => 0.0,
                        'aliquote' => [],
                        'descrizioni' => [],
                        'protocolli' => []  // Raccogliamo i protocolli trovati nelle righe
                    ];
                }
                $raggruppamenti[$groupKey]['imponibile'] += $prezzoTotale;
                // IVA calcolata riga per riga con la sua aliquota (poi riallineata ai DatiRiepilogo)
                $raggruppamenti[$groupKey]['iva'] += $prezzoTotale * $aliquotaIva / 100;
                $raggruppamenti[$groupKey]['aliquote'][(string)$aliquotaIva] = true;
                $raggruppamenti[$groupKey]['descrizioni'][] = $descrizione;
                if ($protocolloRiga) {
                    $raggruppamenti[$groupKey]['protocolli'][$protocolloRiga] = true;
                }
            }

            // 2b. Totali ufficiali dai DatiRiepilogo (somma su tutte le aliquote)
            $riepImponibile = 0.0;
            $riepImposta = 0.0;
            $hasRiepilogo = false;
            foreach ($body->DatiBeniServizi->DatiRiepilogo as $riep) {
                $riepImponibile += (float)$riep->ImponibileImporto;
                $riepImposta += (float)$riep->Imposta;
                $hasRiepilogo = true;
            }

            // Arrotonda i gruppi e scarica sull'ultimo lo scarto rispetto al riepilogo
            // (con un solo gruppo coincide esattamente con i DatiRiepilogo)
            foreach ($raggruppamenti as $sk => $g) {
                $raggruppamenti[$sk]['imponibile'] = round($g['imponibile'], 2);
                $raggruppamenti[$sk]['iva'] = round($g['iva'], 2);
            }
            if ($hasRiepilogo && !empty($raggruppamenti)) {
                $lastKey = array_key_last($raggruppamenti);
                $sumImp = array_sum(array_column($raggruppamenti, 'imponibile'));
                $sumIva = array_sum(array_column($raggruppamenti, 'iva'));
                $raggruppamenti[$lastKey]['imponibile'] = round($raggruppamenti[$lastKey]['imponibile'] + ($riepImponibile - $sumImp), 2);
                $raggruppamenti[$lastKey]['iva'] = round($raggruppamenti[$lastKey]['iva'] + ($riepImposta - $sumIva), 2);
            }

            // 3. Eseguiamo gli Insert/Update su `fatture`, con match incarico tramite protocollo
            foreach ($raggruppamenti as $sk => $data) {
                $sid = ($sk === 'none') ? null : (int)$sk;
                $imponibile = round($segno * $data['imponibile'], 2);
                $importoIva = round($segno * $data['iva'], 2);
                $importoTotale = round($imponibile + $importoIva, 2);
                // Aliquota: quella unica delle righe, altrimenti quella effettiva del gruppo
                $aliquote = array_keys($data['aliquote']);
                if (count($aliquote) === 1) {
                    $ivaPerc = (float)$aliquote[0];
                } else {
                    $ivaPerc = $data['imponibile'] != 0 ? round($data['iva'] / $data['imponibile'] * 100, 2) : 0.0;
                }
                $testoDesc = ($isNotaCredito ? "[Nota di credito]\n" : '') . implode("\n", $data['descrizioni']);

                // 3a. Cerchiamo l'incarico corrispondente tramite protocollo
                $incaricoId = null;
                $protocolliTrovati = array_keys($data['protocolli'] ?? []);
                if (!empty($protocolliTrovati) && !empty($allIncarichi)) {
                    foreach ($protocolliTrovati as $prot) {
                        $protNorm = preg_replace('/\s+/', '', strtolower($prot));
                        foreach ($allIncarichi as $inc) {
                            $incProtNorm = preg_replace('/\s+/', '', strtolower($inc['numero_protocollo']));
                            if ($protNorm === $incProtNorm) {
                                // Match trovato! Verifica anche il sottocliente se presente
                                if ($sid && $inc['sottocliente_id'] && $sid != $inc['sottocliente_id']) {
                                    continue; // Sottocliente diverso, skip
                                }
                                $incaricoId = $inc['id'];
                                $errors[] = "Fattura n. $numeroFattura collegata automaticamente all'incarico #$incaricoId (Prot. $prot).";
                                break 2;
                            }
                        }
                    }
                    if (!$incaricoId && !empty($protocolliTrovati)) {
                        $errors[] = "Fattura n. $numeroFattura: protocollo trovato (" . implode(', ', $protocolliTrovati) . ") ma nessun incarico corrispondente in archivio.";
                    }
                }

                // 3a-bis. Riferimento all'offerta ("Rif. OFF-2026-004"), suggerito dall'ERP nel testo della fattura
                if (!$incaricoId) {
                    require_once __DIR__ . '/../Shared/CommessaService.php';
                    $incaricoId = (new CommessaService($this->pdo, $this->prefix))
                        ->trovaIncaricoPerRiferimento(implode("\n", $data['descrizioni']), $clienteId ? (int)$clienteId : null);
                    if ($incaricoId) {
                        $errors[] = "Fattura n. $numeroFattura collegata all'incarico #$incaricoId dal riferimento all'offerta.";
                    }
                }

                // 3b. Verifica esistenza di questa riga (Fattura + Cliente + EventualSottocliente)
                $chkSql = "SELECT id FROM {$this->prefix}fatture WHERE numero_fattura = ? AND YEAR(data_emissione) = YEAR(?)";
                $chkParams = [$numeroFattura, $dataEmissione];
            
                if ($clienteId) {
                    $chkSql .= " AND cliente_id = ?";
                    $chkParams[] = $clienteId;
                }
            
                if ($sid) {
                    $chkSql .= " AND sottocliente_id = ?";
                    $chkParams[] = $sid;
                } else {
                    $chkSql .= " AND sottocliente_id IS NULL";
                }

                // Stesso numero ma tipo diverso (fattura / nota di credito): documenti diversi
                if ($conTipoDoc) {
                    $chkSql .= " AND (tipo_documento = ? OR (tipo_documento IS NULL AND importo_totale " . ($segno < 0 ? '<' : '>=') . " 0))";
                    $chkParams[] = $tipoDocumento;
                } else {
                    $chkSql .= " AND importo_totale " . ($segno < 0 ? '<' : '>=') . " 0";
                }

                $stmtCheck = $this->pdo->prepare($chkSql);
                $stmtCheck->execute($chkParams);
                $existing = $stmtCheck->fetchColumn();

                if ($existing) {
                    // Skip
                    $errors[] = "Fattura n. $numeroFattura già presente, caricamento ignorato.";
                } else {
                    // Insert con eventuale incarico_id collegato
                    $stmtIns = $this->pdo->prepare("INSERT INTO {$this->prefix}fatture 
                        (numero_fattura, data_emissione, cliente_id, sottocliente_id, incarico_id, imponibile, iva_percentuale, importo_iva, importo_totale, stato, descrizione, data_scadenza"
                        . ($conTipoDoc ? ', tipo_documento' : '') . ")
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, 'emessa', ?, ?" . ($conTipoDoc ? ', ?' : '') . ")");
                    $valori = [$numeroFattura, $dataEmissione, $clienteId, $sid, $incaricoId, $imponibile, $ivaPerc, $importoIva, $importoTotale, $testoDesc, $dataScadenza];
                    if ($conTipoDoc) $valori[] = $tipoDocumento;
                    $stmtIns->execute($valori);
                    $newFatturaId = $this->pdo->lastInsertId();
                    $imported++;

                    // Ricalcola l'incarico collegato (se trovato)
                    if ($incaricoId) {
                        $this->recalculateLinkedIncarico($newFatturaId);
                    }
                }
            }

            } // fine documenti del lotto

            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[Contabilita::importXmlData] ' . $e->getMessage());
            Response::json(false, "Import XML annullato (fattura n. $numeroFattura): " . $e->getMessage());
            return;
        }

        Response::json(true, 'Analisi XML terminata', [
            'num_imported' => $imported,
            'errors' => $errors
        ]);
    }

    /**
     * Import della "Lista Fatture" di Sistemi (.xlsx, campo file): crea le fatture che mancano.
     * Quelle già presenti (stesso numero, anno e verso) non si toccano: se il totale è diverso lo segnala.
     * Il cliente si riconosce per nome; se non c'è in anagrafica la fattura entra senza cliente
     * (crearlo solo dal nome lo duplicherebbe al primo import XML, che cerca per P.IVA).
     */
    public function importListaFatture() {
        require_once __DIR__ . '/../Shared/ListaFattureParser.php';
        require_once __DIR__ . '/../Shared/AnagraficaMatcher.php';
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) Response::json(false, 'Caricamento del file non riuscito');
        if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            Response::json(false, 'Serve il file Excel .xlsx (il vecchio .xls va risalvato come .xlsx)');
        }
        try {
            $letto = ListaFattureParser::fatture(ListaFattureParser::leggiXlsx($f['tmp_name']));
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
        if (!$letto['fatture']) Response::json(false, 'Nessuna fattura nel file', ['errors' => $letto['avvisi']]);

        $conTipoDoc = $this->colonnaTipoDocumento();
        $clienti = $this->pdo->query("SELECT id, partita_iva, codice_fiscale, ragione_sociale FROM {$this->prefix}clienti")->fetchAll(PDO::FETCH_ASSOC);
        $perNome = [];
        $out = ['num_imported' => 0, 'num_existing' => 0, 'num_different' => 0, 'num_without_client' => 0, 'errors' => $letto['avvisi']];
        $senzaCliente = [];

        $this->pdo->beginTransaction();
        try {
            // Stesso numero ma verso diverso (fattura / nota di credito): documenti diversi, come nell'import XML
            $chk = fn(string $op) => $this->pdo->prepare("SELECT COUNT(*), COALESCE(SUM(importo_totale), 0) FROM {$this->prefix}fatture
                WHERE numero_fattura = ? AND YEAR(data_emissione) = YEAR(?) AND importo_totale $op 0");
            $chkNota = $chk('<');
            $chkFattura = $chk('>=');
            $ins = $this->pdo->prepare("INSERT INTO {$this->prefix}fatture
                (numero_fattura, data_emissione, cliente_id, imponibile, iva_percentuale, importo_iva, importo_totale, stato, descrizione"
                . ($conTipoDoc ? ', tipo_documento' : '') . ")
                VALUES (?, ?, ?, ?, ?, ?, ?, 'emessa', ?" . ($conTipoDoc ? ', ?' : '') . ")");
            foreach ($letto['fatture'] as $d) {
                $stmt = $d['nota_credito'] ? $chkNota : $chkFattura;
                $stmt->execute([$d['numero'], $d['data']]);
                [$n, $somma] = $stmt->fetch(PDO::FETCH_NUM);
                if ((int)$n > 0) {
                    $out['num_existing']++;
                    if (abs((float)$somma - $d['totale']) > 0.01) {
                        $out['num_different']++;
                        $out['errors'][] = "Fattura {$d['numero']}: nell'ERP il totale è " . number_format((float)$somma, 2, ',', '.')
                            . ', in Sistemi ' . number_format($d['totale'], 2, ',', '.') . '.';
                    }
                    continue;
                }
                $clienteId = $d['cliente'] === '' ? null
                    : ($perNome[$d['cliente']] ??= AnagraficaMatcher::trovaTra($clienti, null, null, $d['cliente']));
                if (!$clienteId) {
                    $out['num_without_client']++;
                    if ($d['cliente'] !== '') $senzaCliente[$d['cliente']] = true;
                }
                $ivaPerc = abs($d['imponibile']) > 0.004 ? round($d['iva'] / $d['imponibile'] * 100, 2) : 0.0;
                $descr = ($d['nota_credito'] ? "[Nota di credito]\n" : '') . 'Importata dalla lista fatture di Sistemi'
                    . ($d['registro'] !== '' ? " (registro {$d['registro']})" : '');
                $valori = [$d['numero'], $d['data'], $clienteId, $d['imponibile'], $ivaPerc, $d['iva'], $d['totale'], $descr];
                if ($conTipoDoc) $valori[] = $d['nota_credito'] ? 'TD04' : 'TD01';
                $ins->execute($valori);
                $out['num_imported']++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[Contabilita::importListaFatture] ' . $e->getMessage());
            Response::json(false, 'Import annullato: ' . $e->getMessage());
        }
        foreach (array_keys($senzaCliente) as $nome) {
            $out['errors'][] = "Cliente \"$nome\" non trovato in anagrafica: fatture importate senza cliente, da completare.";
        }
        Audit::log('IMPORT', 'fatture', null, null, null, ['file' => (string)$f['name'], 'nuove' => $out['num_imported'], 'gia_presenti' => $out['num_existing']]);
        Response::json(true, 'Lista fatture importata', $out);
    }

    /**
     * Import PDF di conferma pagamento (es. "Pagamento Fornitore")
     * Parsing specifico per bonifici ricevuti da clienti (es. Unindustria)
     * Aggiorna le fatture esistenti come "pagata"
     */
    public function importPaymentPdf($data) {
        $pages = $data['pages'] ?? [];
        if (empty($pages) || !is_array($pages)) {
            Response::json(false, 'Nessun dato di testo trovato');
            return;
        }

        $fullText = implode(' ', $pages);
        $fullText = preg_replace('/\s+/', ' ', $fullText);

        $matched = 0;
        $notFound = [];
        $alreadyPaid = [];
        $details = [];

        // 1. Estrai la data del pagamento dalla riga "Treviso, DD/MM/YY"
        $dataPagamento = date('Y-m-d');
        if (preg_match('/(?:Treviso|Milano|Padova|Roma)[,\s]+(\d{1,2}\/\d{2}\/\d{2,4})/i', $fullText, $mData)) {
            $parts = explode('/', trim($mData[1]));
            if (count($parts) === 3) {
                $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
                $month = $parts[1];
                $year = $parts[2];
                if (strlen($year) === 2) $year = '20' . $year;
                $dataPagamento = "$year-$month-$day";
            }
        }

        // 2. Estrai data valuta (dalla riga delle fatture, es. "10/04/26 Fissa")
        $dataValuta = null;
        if (preg_match('/(\d{2}\/\d{2}\/\d{2,4})\s+Fissa/i', $fullText, $mVal)) {
            $parts = explode('/', trim($mVal[1]));
            if (count($parts) === 3) {
                $day = str_pad($parts[0], 2, '0', STR_PAD_LEFT);
                $month = $parts[1];
                $year = $parts[2];
                if (strlen($year) === 2) $year = '20' . $year;
                $dataValuta = "$year-$month-$day";
            }
        }

        // Se abbiamo la data valuta, usiamola come data pagamento effettivo
        if ($dataValuta) {
            $dataPagamento = $dataValuta;
        }

        // 3. Estrai le righe della tabella
        // Pattern: numero_fattura  data_doc  data_valuta Fissa  importo
        // Es: "1    30/01/26        10/04/26 Fissa         7.960,50"
        $righe = [];
        if (preg_match_all('/\b(\d{1,4})\s+(\d{2}\/\d{2}\/\d{2,4})\s+\d{2}\/\d{2}\/\d{2,4}\s+Fissa\s+([\d\.,]+)/i', $fullText, $matches, PREG_SET_ORDER)) {
            foreach ($matches as $m) {
                $numFattura = trim($m[1]);
                $importo = (float)str_replace(['.', ','], ['', '.'], $m[3]);
                // Anno della fattura dalla data documento (es. 30/01/26 → 2026)
                $annoFattura = null;
                $dParts = explode('/', trim($m[2]));
                if (count($dParts) === 3) {
                    $annoFattura = strlen($dParts[2]) === 2 ? (int)('20' . $dParts[2]) : (int)$dParts[2];
                }
                $righe[] = [
                    'numero' => $numFattura,
                    'importo' => $importo,
                    'anno' => $annoFattura
                ];
            }
        }

        // 4. Estrai anche il totale pagamento per verifica
        $totalePagamento = 0;
        if (preg_match('/TOTALE\s+PAGAMENTO\s*\*{0,3}\s*EURO\s+([\d\.,]+)/i', $fullText, $mTot)) {
            $totalePagamento = (float)str_replace(['.', ','], ['', '.'], $mTot[1]);
        }

        if (empty($righe)) {
            Response::json(false, 'Nessuna riga di pagamento trovata nel PDF', [
                'text_preview' => substr($fullText, 0, 500)
            ]);
            return;
        }

        // 5. Per ogni riga del PDF, cerca TUTTE le righe in DB con quel numero fattura
        //    (la stessa fattura può avere più righe, una per sottocliente)
        //    Confronta la SOMMA degli importi con l'importo del PDF
        // Con la riconciliazione attiva (migrazione lanciata) l'avviso confluisce nei movimenti bancari
        require_once __DIR__ . '/RiconciliazioneController.php';
        $ric = Riconciliatore::tabellePresenti($this->pdo, $this->prefix)
            ? new Riconciliatore($this->pdo, $this->prefix, fn($id) => $this->recalculateLinkedIncarico($id))
            : null;
        $docsAvviso = [];
        $numeriAvviso = [];
        $movimentoId = null;

        // Tutti gli aggiornamenti del PDF in un'unica transazione
        $this->pdo->beginTransaction();
        try {
            foreach ($righe as $riga) {
                $numFattura = $riga['numero'];
                $importo = $riga['importo'];

                // Cerca TUTTE le righe con questo numero fattura (match esatto, padding, suffisso, prefisso/001)
                // limitate all'anno della fattura, se noto (la numerazione riparte ogni anno)
                $numPadded = str_pad($numFattura, 3, '0', STR_PAD_LEFT);
                $sqlCerca = "SELECT id, numero_fattura, importo_totale, stato, sottocliente_id, cliente_id
                    FROM {$this->prefix}fatture 
                    WHERE (numero_fattura = ? 
                       OR numero_fattura = ? 
                       OR numero_fattura LIKE ? 
                       OR numero_fattura LIKE ?
                       OR numero_fattura LIKE ?)";
                $paramsCerca = [$numFattura, $numPadded, "%/$numFattura", "$numFattura/%", "$numPadded/%"];
                if (!empty($riga['anno'])) {
                    $sqlCerca .= " AND YEAR(data_emissione) = ?";
                    $paramsCerca[] = $riga['anno'];
                }
                $sqlCerca .= " ORDER BY id ASC";
                $stmt = $this->pdo->prepare($sqlCerca);
                $stmt->execute($paramsCerca);
                $righeDb = $stmt->fetchAll();

                if (empty($righeDb)) {
                    $notFound[] = "Fattura n. $numFattura (€" . number_format($importo, 2, ',', '.') . "): non trovata in archivio.";
                    continue;
                }

                // Calcola la somma totale di tutte le righe con questo numero fattura
                $sommaTotaleDb = 0;
                $numRigheDb = count($righeDb);
                $tutteGiaPagate = true;
                foreach ($righeDb as $r) {
                    $sommaTotaleDb += floatval($r['importo_totale']);
                    if ($r['stato'] !== 'pagata') $tutteGiaPagate = false;
                }

                // Se sono tutte già pagate
                if ($tutteGiaPagate) {
                    $alreadyPaid[] = "Fattura n. $numFattura ({$numRigheDb} righe, €" . number_format($sommaTotaleDb, 2, ',', '.') . "): tutte già segnate come pagate.";
                    continue;
                }

                // Verifica che la somma corrisponda (tolleranza ±2€ per arrotondamenti)
                $diff = abs($sommaTotaleDb - $importo);
                if ($diff > 2.0) {
                    $notFound[] = "Fattura n. $numFattura: importo PDF €" . number_format($importo, 2, ',', '.') . 
                        " ≠ somma DB €" . number_format($sommaTotaleDb, 2, ',', '.') . 
                        " ({$numRigheDb} righe, diff: €" . number_format($diff, 2, ',', '.') . ").";
                    continue;
                }

                // Match trovato! Tutte le righe di questa fattura diventano pagate
                if ($ric) {
                    // Riconciliazione attiva: l'avviso diventa un movimento atteso con le sue riconciliazioni
                    // (una voce per documento: le righe con lo stesso numero_fattura/anno/cliente)
                    $numRighe = count(array_filter($righeDb, fn($r) => $r['stato'] !== 'pagata'));
                    array_push($docsAvviso, ...$ric->vociAvviso($righeDb, $importo));
                    $matched += $numRighe;
                    $numeriAvviso[] = $numFattura;
                    $details[] = "✅ Fattura n. {$numFattura} — €" . number_format($importo, 2, ',', '.') . " → {$numRighe} righe aggiornate come Pagate ({$dataPagamento})";
                    continue;
                }
                $idsAggiornati = [];
                foreach ($righeDb as $r) {
                    if ($r['stato'] !== 'pagata') {
                        $stmtUpd = $this->pdo->prepare("UPDATE {$this->prefix}fatture 
                            SET stato = 'pagata', 
                                data_pagamento = ?, 
                                metodo_pagamento = 'bonifico'
                            WHERE id = ?");
                        $stmtUpd->execute([$dataPagamento, $r['id']]);
                        Audit::log('UPDATE', 'fatture', $r['id'], null, null, [
                            'azione' => 'pagamento_da_pdf',
                            'stato' => 'pagata',
                            'data_pagamento' => $dataPagamento,
                            'importo_riga' => $r['importo_totale']
                        ]);
                        $idsAggiornati[] = $r['id'];
                    }
                }

                $matched += count($idsAggiornati);
                $details[] = "✅ Fattura n. {$numFattura} — €" . number_format($importo, 2, ',', '.') . " → {$numRigheDb} righe aggiornate come Pagate ({$dataPagamento})";

                // Ricalcola incarichi collegati alle fatture pagate
                foreach ($righeDb as $r) {
                    if (in_array($r['id'], $idsAggiornati)) {
                        $this->recalculateLinkedIncarico($r['id']);
                    }
                }
            }

            // Avviso → movimento atteso + riconciliazioni; se l'accredito è già sull'estratto conto viene collegato
            if ($ric && $docsAvviso) {
                $totaleAvviso = $totalePagamento > 0 ? $totalePagamento : array_sum(array_column($righe, 'importo'));
                try {
                    $movimentoId = $ric->registraAvviso([
                        'data' => $dataPagamento,
                        'importo' => $totaleAvviso,
                        'descrizione' => 'Avviso di pagamento — fatture ' . implode(', ', $numeriAvviso),
                        'file_nome' => trim((string)($data['file_nome'] ?? '')),
                    ], $docsAvviso, isset($GLOBALS['userContext']['id']) ? (int)$GLOBALS['userContext']['id'] : null, 2.0);
                } catch (RuntimeException $e) {
                    // Un documento incoerente non deve far fallire l'intero import: si segnala e basta
                    $matched = 0;
                    $details = [];
                    $notFound[] = 'Pagamenti non registrati: ' . $e->getMessage();
                    $docsAvviso = [];
                }
            }
            if ($ric && $movimentoId) {
                // L'accredito collegato all'avviso diventa "Incassi clienti"
                RiconciliazioneController::classifica($this->pdo, $this->prefix);
                foreach ($ric->documenti()->delMovimento($movimentoId) as $d) {
                    Audit::log('UPDATE', 'fatture', $d['id'], null, null, [
                        'azione' => 'pagamento_da_pdf', 'stato' => 'pagata', 'data_pagamento' => $dataPagamento,
                        'movimento_id' => $movimentoId, 'importo' => $d['importo'],
                    ]);
                }
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) {
                $this->pdo->rollBack();
            }
            error_log('[Contabilita::importPaymentPdf] ' . $e->getMessage());
            Response::json(false, 'Import pagamento annullato: ' . $e->getMessage());
            return;
        }

        $messages = array_merge($details, $alreadyPaid, $notFound);

        Response::json(true, "Analisi pagamento PDF completata", [
            'num_matched' => $matched,
            'num_already_paid' => count($alreadyPaid),
            'num_not_found' => count($notFound),
            'totale_pagamento' => $totalePagamento,
            'data_pagamento' => $dataPagamento,
            'num_righe_trovate' => count($righe),
            'movimento_id' => $movimentoId,
            'messages' => $messages
        ]);
    }

    /**
     * Dati di un documento del file: [numero, data, tipo, nota di credito?, segno, scadenza].
     * TD04 e TD08 sono note di credito (importi salvati in negativo); la scadenza è la prima
     * DataScadenzaPagamento indicata (come nell'import delle fatture dei fornitori).
     */
    private function datiDocumento(SimpleXMLElement $body): array {
        $gen = $body->DatiGenerali->DatiGeneraliDocumento;
        $tipo = strtoupper(trim((string)($gen->TipoDocumento ?? 'TD01'))) ?: 'TD01';
        $nc = in_array($tipo, ['TD04', 'TD08'], true);
        $scadenza = null;
        foreach ($body->DatiPagamento as $dp) {
            foreach ($dp->DettaglioPagamento as $det) {
                $d = (string)($det->DataScadenzaPagamento ?? '');
                if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && (!$scadenza || $d < $scadenza)) $scadenza = $d;
            }
        }
        return [trim((string)($gen->Numero ?? '')), (string)($gen->Data ?? date('Y-m-d')), $tipo, $nc, $nc ? -1 : 1, $scadenza];
    }

    /** Colonna fatture.tipo_documento presente? (migrazione v065) */
    private function colonnaTipoDocumento(): bool {
        try {
            $this->pdo->query("SELECT tipo_documento FROM {$this->prefix}fatture WHERE 1 = 0");
            return true;
        } catch (\Throwable $e) {
            return false;
        }
    }

    /** Riconciliazioni bancarie del record: se ce ne sono, cancellarlo o riaprirlo le lascerebbe orfane. */
    private function riconciliazioni(int $fatturaId): int {
        require_once __DIR__ . '/../Shared/Riconciliatore.php';
        return Riconciliatore::riconciliazioniDi($this->pdo, $this->prefix, 'fattura', $fatturaId);
    }

    /**
     * Ricalcola l'incarico collegato a una fattura (helper interno)
     */
    private function recalculateLinkedIncarico($fatturaId) {
        $stmt = $this->pdo->prepare("SELECT incarico_id FROM {$this->prefix}fatture WHERE id = ?");
        $stmt->execute([$fatturaId]);
        $incaricoId = $stmt->fetchColumn();
        // Se la fattura ha cambiato incarico, la rata della commessa precedente torna libera
        $this->pdo->prepare("UPDATE {$this->prefix}incarichi_rate SET fattura_id = NULL WHERE fattura_id = ? AND incarico_id <> ?")
            ->execute([$fatturaId, (int)$incaricoId]);
        if ($incaricoId) {
            require_once __DIR__ . '/../Shared/CommessaService.php';
            (new CommessaService($this->pdo, $this->prefix))->collegaFatturaARata((int)$fatturaId);
            require_once __DIR__ . '/IncarchiController.php';
            $incCtrl = new IncarchiController();
            $incCtrl->recalculate($incaricoId);
        }
    }
}
