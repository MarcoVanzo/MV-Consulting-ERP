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
            Logger::logAction('UPDATE', 'fatture', $id, ['numero_fattura' => $fields['numero_fattura'], 'importo_totale' => $fields['importo_totale']]);
            Response::json(true, 'Fattura aggiornata', ['id' => $id]);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO {$this->prefix}fatture ($cols) VALUES ($placeholders)";
            $this->pdo->prepare($sql)->execute(array_values($fields));
            $newId = $this->pdo->lastInsertId();
            Logger::logAction('INSERT', 'fatture', $newId, ['numero_fattura' => $fields['numero_fattura'], 'importo_totale' => $fields['importo_totale']]);
            Response::json(true, 'Fattura creata', ['id' => $newId]);
        }
    }

    public function delete($id) {
        $this->pdo->prepare("DELETE FROM {$this->prefix}fatture WHERE id = ?")->execute([$id]);
        Logger::logAction('DELETE', 'fatture', $id);
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
                // Update
                $stmtUpd = $this->pdo->prepare("UPDATE {$this->prefix}fatture SET cliente_id = ?, imponibile = ?, importo_totale = ?, data_emissione = ? WHERE id = ?");
                $stmtUpd->execute([$clienteId, $imponibile, $totale, $dataEmissione, $existing]);
                $imported++;
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

        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($xmlContent);
        if ($xml === false) {
            $errors = [];
            foreach(libxml_get_errors() as $err) {
                $errors[] = $err->message;
            }
            Response::json(false, 'XML malformato', $errors);
            return;
        }

        // Register namespaces for FPR12
        $namespaces = $xml->getNamespaces(true);
        $p = isset($namespaces['p']) ? 'p' : ''; 
        if ($p) $xml->registerXPathNamespace('p', $namespaces['p']);

        // Estrazione testata fattura
        $header = $xml->FatturaElettronicaHeader;
        $body = $xml->FatturaElettronicaBody;

        if (!$header || !$body) {
            Response::json(false, 'Nodi principali mancanti nell\'XML (non è una fattura elettronica standard?)');
            return;
        }

        // Cliente (CessionarioCommittente/DatiAnagrafici/IdFiscaleIVA/IdCodice o CodiceFiscale)
        $clientePartitaIva = (string)($header->CessionarioCommittente->DatiAnagrafici->IdFiscaleIVA->IdCodice ?? '');
        $clienteCodiceFiscale = (string)($header->CessionarioCommittente->DatiAnagrafici->CodiceFiscale ?? '');

        // Dati Generali
        $numeroFattura = (string)($body->DatiGenerali->DatiGeneraliDocumento->Numero ?? '');
        $dataEmissione = (string)($body->DatiGenerali->DatiGeneraliDocumento->Data ?? date('Y-m-d'));

        if (!$numeroFattura) {
            Response::json(false, 'Numero fattura non trovato nell\'XML');
            return;
        }

        // Pre-carico tutti i clienti e sottoclienti
        $stmtClienti = $this->pdo->query("SELECT id, partita_iva, codice_fiscale FROM {$this->prefix}clienti");
        $allClienti = $stmtClienti->fetchAll();
        $stmtSotto = $this->pdo->query("SELECT id, cliente_id, nome FROM {$this->prefix}sottoclienti");
        $allSottoclienti = $stmtSotto->fetchAll();

        // 1. Trovo il cliente principale per Partita IVA o CF
        $clienteId = null;
        foreach ($allClienti as $c) {
            if (($clientePartitaIva && strtoupper(str_replace(' ', '', $c['partita_iva'])) === strtoupper($clientePartitaIva)) || 
                ($clienteCodiceFiscale && strtoupper(str_replace(' ', '', $c['codice_fiscale'])) === strtoupper($clienteCodiceFiscale))) {
                $clienteId = $c['id'];
                break;
            }
        }

        $imported = 0;
        $errors = [];

        if (!$clienteId && ($clientePartitaIva || $clienteCodiceFiscale)) {
            $ragioneSociale = (string)($header->CessionarioCommittente->DatiAnagrafici->Anagrafica->Denominazione ?? 'Cliente Sconosciuto');
            $indirizzo = (string)($header->CessionarioCommittente->Sede->Indirizzo ?? '');
            $cap = (string)($header->CessionarioCommittente->Sede->CAP ?? '');
            $comune = (string)($header->CessionarioCommittente->Sede->Comune ?? '');
            $provincia = (string)($header->CessionarioCommittente->Sede->Provincia ?? '');
            
            $stmtInsertC = $this->pdo->prepare("INSERT INTO {$this->prefix}clienti (ragione_sociale, partita_iva, codice_fiscale, indirizzo, citta, cap, provincia) VALUES (?, ?, ?, ?, ?, ?, ?)");
            $stmtInsertC->execute([$ragioneSociale, $clientePartitaIva, $clienteCodiceFiscale, $indirizzo, $comune, $cap, $provincia]);
            $clienteId = $this->pdo->lastInsertId();
            $errors[] = "Cliente '$ragioneSociale' creato automaticamente.";
        } elseif (!$clienteId) {
            $errors[] = "Impossibile creare il Cliente: P.IVA o CF mancanti nell'XML.";
        }

        // 2. Analisi delle righe e raggruppamento per Sottocliente
        $raggruppamenti = [];

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

            // Tentiamo di validare in DB tra i sottoclienti del cliente individuato
            $sottoclienteId = null;
            if ($sotto_nome_trovato && $clienteId) {
                // Search existing
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
                    $stmtInsertS = $this->pdo->prepare("INSERT INTO {$this->prefix}sottoclienti (cliente_id, nome) VALUES (?, ?)");
                    $stmtInsertS->execute([$clienteId, $sotto_nome_trovato]);
                    $sottoclienteId = $this->pdo->lastInsertId();
                    
                    // Aggiorno la cache array per non ricrearlo in righe successive della stessa fattura
                    $allSottoclienti[] = ['id' => $sottoclienteId, 'cliente_id' => $clienteId, 'nome' => $sotto_nome_trovato];
                    
                    $errors[] = "Sottocliente '$sotto_nome_trovato' creato automaticamente.";
                }
            }

            // Prepariamo una chiave per accumulare importi dello stesso sottocliente.
            // Se nessun sottocliente -> ID = 0 (finisce nel blocco principale senza sottocliente)
            $groupKey = $sottoclienteId ? $sottoclienteId : '0';

            if (!isset($raggruppamenti[$groupKey])) {
                $raggruppamenti[$groupKey] = [
                    'imponibile' => 0.0,
                    'iva_percentuale' => $aliquotaIva, // Assume stessa IVA
                    'descrizioni' => []
                ];
            }
            $raggruppamenti[$groupKey]['imponibile'] += $prezzoTotale;
            $raggruppamenti[$groupKey]['descrizioni'][] = $descrizione;
        }

        // 3. Eseguiamo gli Insert/Update su `fatture`
        foreach ($raggruppamenti as $sk => $data) {
            $sid = ($sk === '0') ? null : (int)$sk;
            $imponibile = round($data['imponibile'], 2);
            $importoIva = round($imponibile * $data['iva_percentuale'] / 100, 2);
            $importoTotale = round($imponibile + $importoIva, 2);
            $testoDesc = implode("\n", $data['descrizioni']);

            // Verifica esistenza di questa riga (Fattura + Cliente + EventualSottocliente)
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

            $stmtCheck = $this->pdo->prepare($chkSql);
            $stmtCheck->execute($chkParams);
            $existing = $stmtCheck->fetchColumn();

            if ($existing) {
                // Update
                $stmtUpd = $this->pdo->prepare("UPDATE {$this->prefix}fatture SET imponibile = ?, importo_totale = ?, importo_iva = ?, descrizione = ? WHERE id = ?");
                $stmtUpd->execute([$imponibile, $importoTotale, $importoIva, $testoDesc, $existing]);
                $imported++;
            } else {
                // Insert
                $stmtIns = $this->pdo->prepare("INSERT INTO {$this->prefix}fatture 
                    (numero_fattura, data_emissione, cliente_id, sottocliente_id, imponibile, iva_percentuale, importo_iva, importo_totale, stato, descrizione)
                    VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'emessa', ?)");
                $stmtIns->execute([$numeroFattura, $dataEmissione, $clienteId, $sid, $imponibile, $data['iva_percentuale'], $importoIva, $importoTotale, $testoDesc]);
                $imported++;
            }
        }

        Response::json(true, 'Analisi XML terminata', [
            'num_imported' => $imported,
            'errors' => $errors
        ]);
    }
}
