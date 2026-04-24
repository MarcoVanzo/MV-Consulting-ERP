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
            Audit::log('UPDATE', 'fatture', $id, null, null, ['numero_fattura' => $fields['numero_fattura'], 'importo_totale' => $fields['importo_totale']])
            Response::json(true, 'Fattura aggiornata', ['id' => $id]);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO {$this->prefix}fatture ($cols) VALUES ($placeholders)";
            $this->pdo->prepare($sql)->execute(array_values($fields));
            $newId = $this->pdo->lastInsertId();
            Audit::log('INSERT', 'fatture', $newId, null, null, ['numero_fattura' => $fields['numero_fattura'], 'importo_totale' => $fields['importo_totale']])
            Response::json(true, 'Fattura creata', ['id' => $newId]);
        }
    }

    public function delete($id) {
        $this->pdo->prepare("DELETE FROM {$this->prefix}fatture WHERE id = ?")->execute([$id]);
        Audit::log('DELETE', 'fatture', $id, null, null, null)
        Response::json(true, 'Fattura eliminata');
    }

    /**
     * Overview finanziaria — KPI e aggregazioni
     */
    public function overview() {
        $year = $_POST['year'] ?? $_GET['year'] ?? date('Y');
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
            COALESCE(SUM(CASE WHEN stato = 'pagata' THEN importo_totale ELSE 0 END), 0) as pagato,
            COUNT(id) as num_fatture
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
                    'iva_percentuale' => $aliquotaIva, // Assume stessa IVA
                    'descrizioni' => []
                ];
            }
            $raggruppamenti[$groupKey]['imponibile'] += $prezzoTotale;
            $raggruppamenti[$groupKey]['descrizioni'][] = $descrizione;
        }

        // 3. Eseguiamo gli Insert/Update su `fatture`
        foreach ($raggruppamenti as $sk => $data) {
            $sid = ($sk === 'none') ? null : (int)$sk;
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
                // Skip
                $errors[] = "Fattura n. $numeroFattura già presente, caricamento ignorato.";
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
                $righe[] = [
                    'numero' => $numFattura,
                    'importo' => $importo
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
        foreach ($righe as $riga) {
            $numFattura = $riga['numero'];
            $importo = $riga['importo'];

            // Cerca TUTTE le righe con questo numero fattura (match esatto, padding, suffisso, prefisso/001)
            $numPadded = str_pad($numFattura, 3, '0', STR_PAD_LEFT);
            $stmt = $this->pdo->prepare("SELECT id, numero_fattura, importo_totale, stato, sottocliente_id
                FROM {$this->prefix}fatture 
                WHERE numero_fattura = ? 
                   OR numero_fattura = ? 
                   OR numero_fattura LIKE ? 
                   OR numero_fattura LIKE ?
                   OR numero_fattura LIKE ?
                ORDER BY id ASC");
            $stmt->execute([$numFattura, $numPadded, "%/$numFattura", "$numFattura/%", "$numPadded/%"]);
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

            // Match trovato! Aggiorna TUTTE le righe di questa fattura come pagate
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
                    ])
                    $idsAggiornati[] = $r['id'];
                }
            }

            $matched += count($idsAggiornati);
            $details[] = "✅ Fattura n. {$numFattura} — €" . number_format($importo, 2, ',', '.') . " → {$numRigheDb} righe aggiornate come Pagate ({$dataPagamento})";
        }

        $messages = array_merge($details, $alreadyPaid, $notFound);

        Response::json(true, "Analisi pagamento PDF completata", [
            'num_matched' => $matched,
            'num_already_paid' => count($alreadyPaid),
            'num_not_found' => count($notFound),
            'totale_pagamento' => $totalePagamento,
            'data_pagamento' => $dataPagamento,
            'num_righe_trovate' => count($righe),
            'messages' => $messages
        ]);
    }
}
