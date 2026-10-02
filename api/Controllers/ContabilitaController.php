<?php
/**
 * Contabilità Controller — Fatture CRUD + Overview finanziaria
 */

require_once __DIR__ . '/../Shared/Indicatori.php';
require_once __DIR__ . '/../Shared/CommessaService.php';

class ContabilitaController {
    private const STATI_FATTURA = ['emessa', 'inviata', 'pagata', 'scaduta'];
    /** Descrizione delle fatture entrate da un elenco Excel: solo i totali, le righe arrivano con l'XML. */
    public const DA_ELENCO = 'Importata dall\'elenco fatture';
    /** Stessa cosa, scritta dal vecchio import della Lista Fatture di Sistemi. */
    private const DA_ELENCO_VECCHIO = 'Importata dalla lista fatture di Sistemi';
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
                sc.nome as sottocliente_nome,
                i.descrizione AS commessa_descrizione, i.tipo_commessa AS commessa_tipo, YEAR(i.data_incarico) AS commessa_anno
            FROM {$this->prefix}fatture f
            LEFT JOIN {$this->prefix}clienti c ON c.id = f.cliente_id
            LEFT JOIN {$this->prefix}sottoclienti sc ON sc.id = f.sottocliente_id
            LEFT JOIN {$this->prefix}incarichi i ON i.id = f.incarico_id
            WHERE YEAR(f.data_emissione) = ?";
        $params = [$year];

        // Filtri con le stesse definizioni degli Indicatori (docs/indicatori.md)
        if ($stato === 'da_incassare') {
            $sql .= " AND f.stato <> 'pagata'";
        } elseif ($stato === 'scaduta') {
            $sql .= " AND f.stato <> 'pagata' AND f.data_scadenza IS NOT NULL AND f.data_scadenza < ?";
            $params[] = date('Y-m-d');
        } elseif ($stato && in_array($stato, self::STATI_FATTURA, true)) {
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

        // KPI: definizioni uniche in Indicatori (docs/indicatori.md)
        $kpis = (new Indicatori($this->pdo, $p))->fatture((int)$year, $esclusi);

        // Fatturato mensile (per grafico): IVA inclusa, come i KPI della scheda
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
            COALESCE(SUM(f.imponibile), 0) as fatturato
            FROM {$p}fatture f
            LEFT JOIN {$p}clienti c ON c.id = f.cliente_id
            WHERE YEAR(f.data_emissione) = ?" . str_replace('cliente_id', 'f.cliente_id', $filtro) . "
            GROUP BY f.cliente_id ORDER BY fatturato DESC LIMIT 5");
        $stmt3->execute([$year]);
        $topClienti = $stmt3->fetchAll();

        // Clienti con fatture nell'anno, per la scelta di chi contare
        $stmt4 = $this->pdo->prepare("SELECT COALESCE(f.cliente_id, 0) AS id, COALESCE(c.ragione_sociale, 'Senza cliente') AS nome,
                COALESCE(SUM(f.imponibile), 0) AS fatturato, COUNT(*) AS num_fatture
            FROM {$p}fatture f LEFT JOIN {$p}clienti c ON c.id = f.cliente_id
            WHERE YEAR(f.data_emissione) = ?
            GROUP BY COALESCE(f.cliente_id, 0), c.ragione_sociale ORDER BY nome");
        $stmt4->execute([$year]);
        $clientiAnno = $stmt4->fetchAll();

        Response::json(true, '', [
            'kpis' => $kpis,
            'mensile' => $monthly,
            'top_clienti' => $topClienti,
            'anzianita' => (new Indicatori($this->pdo, $p))->anzianitaCrediti((int)$year, $esclusi),
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
        return Indicatori::chiaveClientiEsclusi();
    }

    private function clientiEsclusi(): array {
        return (new Indicatori($this->pdo, $this->prefix))->clientiEsclusi();
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
        $stmtClienti = $this->pdo->query("SELECT id, partita_iva, codice_fiscale, ragione_sociale FROM {$this->prefix}clienti");
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

        // Clienti esteri senza partita IVA: in fattura un codice fittizio («US000000», «00000000») che non identifica
        // nessuno: si riconoscono dalla ragione sociale, altrimenti ogni variante del codice creerebbe un doppione
        $fittizio = fn(string $v) => (bool)preg_match('/^[A-Z]{0,2}0+$/', $v);
        if ($fittizio($xmlPiva)) $xmlPiva = '';
        if ($fittizio($xmlCf)) $xmlCf = '';
        $xmlNome = mb_strtolower(preg_replace('/\s+/', ' ', trim((string)($header->CessionarioCommittente->DatiAnagrafici->Anagrafica->Denominazione ?? ''))), 'UTF-8');

        foreach ($allClienti as $c) {
            if (!$xmlPiva && !$xmlCf) {
                if ($xmlNome !== '' && mb_strtolower(preg_replace('/\s+/', ' ', trim((string)($c['ragione_sociale'] ?? ''))), 'UTF-8') === $xmlNome) {
                    $clienteId = $c['id'];
                    break;
                }
                continue;
            }
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
                // Società: Denominazione; persona fisica: Nome e Cognome
                $anag = $header->CessionarioCommittente->DatiAnagrafici->Anagrafica;
                $ragioneSociale = trim((string)($anag->Denominazione ?? ''))
                    ?: trim((string)($anag->Nome ?? '') . ' ' . (string)($anag->Cognome ?? ''))
                    ?: 'Cliente Sconosciuto';
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

                // Commessa della riga dai protocolli scritti nel testo: se ha un sottocliente, è quello della riga
                $incRiga = CommessaService::incaricoDellaRiga($descrizione, $allIncarichi);
                $sottoclienteId = $incRiga && $incRiga['sottocliente_id'] ? (int)$incRiga['sottocliente_id'] : null;

                // Altrimenti tentiamo di validare in DB tra i sottoclienti del cliente individuato
                if ($clienteId && !$sottoclienteId) {
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

                // Un record per sottocliente e commessa: due commesse dello stesso sottocliente restano separate.
                // Senza sottocliente → 'none' (blocco principale), senza commessa → 0
                $groupKey = ($sottoclienteId ?: 'none') . '|' . ($incRiga ? (int)$incRiga['id'] : 0);

                if (!isset($raggruppamenti[$groupKey])) {
                    $raggruppamenti[$groupKey] = [
                        'sottocliente_id' => $sottoclienteId ? (int)$sottoclienteId : null,
                        'incarico_id' => $incRiga ? (int)$incRiga['id'] : null,
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

            // 3. Il documento c'è già? Se è entrato solo dall'elenco Excel (totale senza righe) lo si completa
            //    tenendo il suo id (pagamenti e riconciliazioni restano agganciati); se è già dettagliato si salta.
            $esistenti = $this->righeDocumento($numeroFattura, $dataEmissione, $clienteId ? (int)$clienteId : null, $segno, $tipoDocumento, $conTipoDoc);
            $soloElenco = $esistenti && !array_filter($esistenti, fn($r) => !self::daElenco((string)$r['descrizione']));
            if ($esistenti && !$soloElenco) {
                $errors[] = "Fattura n. $numeroFattura già presente, caricamento ignorato.";
                $raggruppamenti = [];
            }
            $daRiusare = $soloElenco ? $esistenti : [];
            if ($soloElenco) $errors[] = "Fattura n. $numeroFattura: era entrata dall'elenco, completata con le righe della fattura.";

            // 3a. Insert (o completamento della riga dall'elenco), con la commessa trovata riga per riga
            foreach ($raggruppamenti as $data) {
                $sid = $data['sottocliente_id'];
                $incaricoId = $data['incarico_id'];
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
                $protocolliTrovati = array_keys($data['protocolli'] ?? []);

                if ($incaricoId) {
                    $errors[] = "Fattura n. $numeroFattura collegata automaticamente all'incarico #$incaricoId"
                        . ($protocolliTrovati ? ' (Prot. ' . implode(', ', $protocolliTrovati) . ')' : '') . '.';
                } elseif ($protocolliTrovati) {
                    $errors[] = "Fattura n. $numeroFattura: protocollo trovato (" . implode(', ', $protocolliTrovati) . ") ma nessun incarico corrispondente in archivio.";
                }

                // Riferimento all'offerta ("Rif. OFF-2026-004"), suggerito dall'ERP nel testo della fattura
                if (!$incaricoId) {
                    $incaricoId = (new CommessaService($this->pdo, $this->prefix))
                        ->trovaIncaricoPerRiferimento(implode("\n", $data['descrizioni']), $clienteId ? (int)$clienteId : null);
                    if ($incaricoId) {
                        $errors[] = "Fattura n. $numeroFattura collegata all'incarico #$incaricoId dal riferimento all'offerta.";
                    }
                }

                // Commessa ricorrente (canone): rata libera dello stesso importo prevista entro 40 giorni
                if (!$incaricoId && !$isNotaCredito) {
                    $incaricoId = (new CommessaService($this->pdo, $this->prefix))
                        ->trovaIncaricoPerRata($clienteId ? (int)$clienteId : null, (float)$imponibile, (string)$dataEmissione);
                    if ($incaricoId) {
                        $errors[] = "Fattura n. $numeroFattura collegata all'incarico #$incaricoId: rata di pari importo.";
                    }
                }

                $vecchia = array_shift($daRiusare);
                if ($vecchia) {
                    $this->pdo->prepare("UPDATE {$this->prefix}fatture SET cliente_id = ?, sottocliente_id = ?, incarico_id = ?, imponibile = ?,
                        iva_percentuale = ?, importo_iva = ?, importo_totale = ?, descrizione = ?, data_scadenza = COALESCE(?, data_scadenza)"
                        . ($conTipoDoc ? ', tipo_documento = ?' : '') . " WHERE id = ?")
                        ->execute(array_merge([$clienteId, $sid, $incaricoId, $imponibile, $ivaPerc, $importoIva, $importoTotale, $testoDesc, $dataScadenza],
                            $conTipoDoc ? [$tipoDocumento] : [], [$vecchia['id']]));
                    $fatturaId = (int)$vecchia['id'];
                } else {
                    // Una fattura già pagata (dall'elenco) resta pagata anche nelle parti nuove
                    $base = $soloElenco ? $esistenti[0] : ['stato' => 'emessa', 'data_pagamento' => null];
                    $stmtIns = $this->pdo->prepare("INSERT INTO {$this->prefix}fatture 
                        (numero_fattura, data_emissione, cliente_id, sottocliente_id, incarico_id, imponibile, iva_percentuale, importo_iva, importo_totale, stato, data_pagamento, descrizione, data_scadenza"
                        . ($conTipoDoc ? ', tipo_documento' : '') . ")
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?" . ($conTipoDoc ? ', ?' : '') . ")");
                    $valori = [$numeroFattura, $dataEmissione, $clienteId, $sid, $incaricoId, $imponibile, $ivaPerc, $importoIva, $importoTotale,
                        $base['stato'] ?: 'emessa', $base['data_pagamento'] ?? null, $testoDesc, $dataScadenza];
                    if ($conTipoDoc) $valori[] = $tipoDocumento;
                    $stmtIns->execute($valori);
                    $fatturaId = (int)$this->pdo->lastInsertId();
                    $imported++;
                }

                // Ricalcola l'incarico collegato (se trovato)
                if ($incaricoId) {
                    $this->recalculateLinkedIncarico($fatturaId);
                }
            }
            // Righe dall'elenco avanzate (raro: più righe per lo stesso documento): le riconciliazioni passano alla prima
            foreach ($daRiusare as $r) {
                $this->pdo->prepare("UPDATE {$this->prefix}riconciliazioni SET documento_id = ? WHERE tipo = 'fattura' AND documento_id = ?")
                    ->execute([(int)$esistenti[0]['id'], (int)$r['id']]);
                $this->pdo->prepare("DELETE FROM {$this->prefix}fatture WHERE id = ?")->execute([(int)$r['id']]);
            }

            // Nota di credito che storna per intero fatture aperte dello stesso cliente: si chiudono entrambe,
            // altrimenti la fattura resterebbe «scaduta» per sempre (scadenzario, dashboard, solleciti)
            if ($isNotaCredito && $clienteId) {
                $chiuse = $this->chiudiStornate($body, (int)$clienteId, $numeroFattura, $dataEmissione);
                if ($chiuse) $errors[] = "Nota di credito n. $numeroFattura: storna per intero la fattura $chiuse, segnate chiuse entrambe.";
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

    /** Import della "Lista Fatture" di Sistemi (.xlsx, campo file): passa dall'import unico degli elenchi. */
    public function importListaFatture() {
        require_once __DIR__ . '/ImportaController.php';
        (new ImportaController())->lista(['verso' => 'attiva']);
    }

    /**
     * Fatture emesse da un elenco (Lista Fatture di Sistemi o portale): crea quelle che mancano.
     * Quelle già presenti (stesso numero, anno e verso) non si toccano: se il totale è diverso lo segnala.
     * Il cliente si riconosce per nome; se non c'è in anagrafica si crea col solo nome e la P.IVA si cerca
     * poi sul web (AnagraficaAuto): così il primo import XML lo ritrova per partita IVA invece di duplicarlo.
     */
    public function importLista(array $letto, string $nomeFile) {
        require_once __DIR__ . '/../Shared/AnagraficaAuto.php';
        $conTipoDoc = $this->colonnaTipoDocumento();
        $clienti = $this->pdo->query("SELECT id, partita_iva, codice_fiscale, ragione_sociale FROM {$this->prefix}clienti")->fetchAll(PDO::FETCH_ASSOC);
        $perNome = [];
        $out = ['verso' => 'attiva', 'num_imported' => 0, 'num_existing' => 0, 'num_different' => 0, 'num_without_client' => 0,
            'anagrafiche_create' => [], 'errors' => $letto['avvisi']];

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
                            . ', nel file ' . number_format($d['totale'], 2, ',', '.') . '.';
                    }
                    continue;
                }
                $clienteId = null;
                if ($d['cliente'] !== '') {
                    if (!isset($perNome[$d['cliente']])) {
                        [$perNome[$d['cliente']], $creato] = AnagraficaAuto::trovaOCrea($this->pdo, $this->prefix, 'cliente', $d['cliente'], $clienti);
                        if ($creato) $out['anagrafiche_create'][] = $d['cliente'];
                    }
                    $clienteId = $perNome[$d['cliente']];
                } else {
                    $out['num_without_client']++;
                }
                $ivaPerc = abs($d['imponibile']) > 0.004 ? round($d['iva'] / $d['imponibile'] * 100, 2) : 0.0;
                $descr = ($d['nota_credito'] ? "[Nota di credito]\n" : '') . self::DA_ELENCO
                    . ($d['registro'] !== '' ? " (registro {$d['registro']})" : '');
                $valori = [$d['numero'], $d['data'], $clienteId, $d['imponibile'], $ivaPerc, $d['iva'], $d['totale'], $descr];
                if ($conTipoDoc) $valori[] = $d['nota_credito'] ? 'TD04' : 'TD01';
                $ins->execute($valori);
                $out['num_imported']++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[Contabilita::importLista] ' . $e->getMessage());
            Response::json(false, 'Import annullato: ' . $e->getMessage());
        }
        $out['da_cercare'] = AnagraficaAuto::daCercare($this->pdo, $this->prefix);
        Audit::log('IMPORT', 'fatture', null, null, null, ['file' => $nomeFile, 'nuove' => $out['num_imported'], 'gia_presenti' => $out['num_existing']]);
        Response::json(true, 'Elenco fatture emesse importato', $out);
    }

    /**
     * Import dell'avviso di pagamento del cliente («Pagamento Fornitore» di Unindustria).
     * Ogni bonifico dell'avviso diventa un movimento atteso con le sue fatture (Riconciliatore::registraAvviso),
     * che l'accredito sull'estratto conto poi conferma. Per ogni fattura si controlla anche il pagamento:
     * importo, data del documento e valuta rispetto alla scadenza dei termini (CommessaService::scadenzaAttesa).
     */
    public function importPaymentPdf($data) {
        $pages = $data['pages'] ?? [];
        if (empty($pages) || !is_array($pages)) {
            Response::json(false, 'Nessun dato di testo trovato');
            return;
        }
        require_once __DIR__ . '/../Shared/AvvisoPagamentoParser.php';
        require_once __DIR__ . '/../Shared/CommessaService.php';
        require_once __DIR__ . '/RiconciliazioneController.php';
        $avviso = AvvisoPagamentoParser::leggi($pages);
        if (!$avviso['bonifici']) {
            Response::json(false, 'Nessuna riga di pagamento trovata nel PDF', [
                'text_preview' => mb_substr(preg_replace('/\s+/', ' ', implode(' ', $pages)), 0, 500)
            ]);
            return;
        }
        if (!Riconciliatore::tabellePresenti($this->pdo, $this->prefix)) {
            Response::json(false, 'Riconciliazione non attiva: lancia le migrazioni prima di importare gli avvisi');
            return;
        }
        $ric = new Riconciliatore($this->pdo, $this->prefix, fn($id) => $this->recalculateLinkedIncarico($id));
        $svc = new CommessaService($this->pdo, $this->prefix);
        $userId = isset($GLOBALS['userContext']['id']) ? (int)$GLOBALS['userContext']['id'] : null;
        $eur = fn($v) => '€' . number_format((float)$v, 2, ',', '.');
        $gg = fn($v) => date('d/m/Y', strtotime($v));

        $matched = 0;
        $details = [];
        $controlli = [];
        $alreadyPaid = [];
        $notFound = [];
        $movimenti = [];
        $numRighe = 0;

        $this->pdo->beginTransaction();
        try {
            foreach ($avviso['bonifici'] as $bonifico) {
                if (abs($bonifico['totale'] - $bonifico['somma_righe']) > 0.01) {
                    $controlli[] = "⚠️ Bonifico del {$gg($bonifico['valuta'])}: totale {$eur($bonifico['totale'])} ma le righe sommano {$eur($bonifico['somma_righe'])}.";
                }
                $docs = [];
                $numeri = [];
                $pagate = 0;
                $esiti = [];
                foreach ($bonifico['righe'] as $riga) {
                    $numRighe++;
                    $num = $riga['numero'];
                    $righeDb = $this->fatturePerAvviso($riga);
                    if (!$righeDb) {
                        $notFound[] = "Fattura n. $num del {$gg($riga['data_documento'])} ({$eur($riga['importo'])}): non trovata in archivio.";
                        continue;
                    }
                    if ($righeDb[0]['data_emissione'] !== $riga['data_documento']) {
                        $controlli[] = "⚠️ Fattura n. $num: nell'avviso è del {$gg($riga['data_documento'])}, in archivio del {$gg($righeDb[0]['data_emissione'])}.";
                    }
                    $controlli[] = $this->controlloValuta($svc, (int)$righeDb[0]['id'], $num, $riga['valuta']);

                    // La stessa fattura può avere più righe (una per sottocliente): conta la somma
                    $somma = array_sum(array_map(fn($r) => (float)$r['importo_totale'], $righeDb));
                    $aperte = array_filter($righeDb, fn($r) => $r['stato'] !== 'pagata');
                    if (!$aperte) {
                        $alreadyPaid[] = "Fattura n. $num (" . count($righeDb) . " righe, {$eur($somma)}): già segnata come pagata.";
                        continue;
                    }
                    if (abs($somma - $riga['importo']) > 2.0) {
                        $notFound[] = "Fattura n. $num: importo nell'avviso {$eur($riga['importo'])} ≠ in archivio {$eur($somma)}"
                            . " (differenza " . $eur(abs($somma - $riga['importo'])) . "): non segnata pagata.";
                        continue;
                    }
                    array_push($docs, ...$ric->vociAvviso($righeDb, $riga['importo']));
                    $pagate += count($aperte);
                    $numeri[] = $num;
                    $esiti[] = "✅ Fattura n. $num — {$eur($riga['importo'])} → pagata con valuta {$gg($riga['valuta'])}";
                }
                if (!$docs) continue;
                try {
                    $movimenti[] = $ric->registraAvviso([
                        'data' => $bonifico['valuta'],
                        'importo' => $bonifico['totale'],
                        'descrizione' => 'Avviso di pagamento — fatture ' . implode(', ', $numeri),
                        'file_nome' => trim((string)($data['file_nome'] ?? '')),
                    ], $docs, $userId, 2.0);
                    $matched += $pagate;
                    array_push($details, ...$esiti);
                } catch (RuntimeException $e) {
                    // Un documento incoerente non deve far fallire l'intero avviso: si segnala il bonifico e si prosegue
                    $notFound[] = 'Bonifico del ' . $gg($bonifico['valuta']) . ' non registrato (fatture ' . implode(', ', $numeri) . '): ' . $e->getMessage();
                }
            }
            if ($movimenti) {
                // L'accredito collegato all'avviso diventa "Incassi clienti"
                RiconciliazioneController::classifica($this->pdo, $this->prefix);
                foreach ($movimenti as $movimentoId) {
                    $mov = $ric->movimento($movimentoId);
                    foreach ($ric->documenti()->delMovimento($movimentoId) as $d) {
                        Audit::log('UPDATE', 'fatture', $d['id'], null, null, [
                            'azione' => 'pagamento_da_pdf', 'stato' => 'pagata', 'data_pagamento' => $mov['data_valuta'] ?? null,
                            'movimento_id' => $movimentoId, 'importo' => $d['importo'],
                        ]);
                    }
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

        Response::json(true, "Analisi pagamento PDF completata", [
            'num_matched' => $matched,
            'num_already_paid' => count($alreadyPaid),
            'num_not_found' => count($notFound),
            'totale_pagamento' => $avviso['totale'],
            'data_pagamento' => max(array_column($avviso['bonifici'], 'valuta')),
            'num_bonifici' => count($avviso['bonifici']),
            'num_righe_trovate' => $numRighe,
            'movimento_id' => $movimenti[0] ?? null,
            'messages' => array_values(array_filter(array_merge($notFound, $controlli, $details, $alreadyPaid))),
        ]);
    }

    /**
     * Righe in archivio della fattura di una riga dell'avviso: stesso numero (anche «29» ↔ «29/001» ↔ «029»)
     * e stesso anno; se più documenti hanno quel numero, quello con la data dell'avviso.
     */
    private function fatturePerAvviso(array $riga): array {
        $base = ltrim($riga['numero_base'], '0') ?: '0';
        $pad = str_pad($base, 3, '0', STR_PAD_LEFT);
        $stmt = $this->pdo->prepare("SELECT id, numero_fattura, data_emissione, importo_totale, stato, sottocliente_id, cliente_id
            FROM {$this->prefix}fatture
            WHERE importo_totale > 0 AND (numero_fattura IN (?, ?, ?) OR numero_fattura LIKE ? OR numero_fattura LIKE ? OR numero_fattura LIKE ?)
              AND data_emissione BETWEEN ? AND ?
            ORDER BY id");
        $anno = substr((string)$riga['data_documento'], 0, 4);
        $stmt->execute([$riga['numero'], $base, $pad, "$base/%", "$pad/%", "%/$base", "$anno-01-01", "$anno-12-31"]);
        $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $stessaData = array_values(array_filter($righe, fn($r) => $r['data_emissione'] === $riga['data_documento']));
        if ($stessaData) return $stessaData;
        // Data diversa: va bene solo se il documento è uno (altrimenti non si sa quale)
        return count(array_unique(array_column($righe, 'data_emissione'))) === 1 ? $righe : [];
    }

    /** Valuta del bonifico rispetto alla scadenza dei termini: puntuale, in anticipo o in ritardo. */
    private function controlloValuta(CommessaService $svc, int $fatturaId, string $numero, string $valuta): ?string {
        $attesa = $svc->scadenzaAttesa($fatturaId);
        if (!$attesa) return null;
        [$scadenza, $termini] = $attesa;
        $giorni = (int)round((strtotime($valuta) - strtotime($scadenza)) / 86400);
        if ($giorni === 0) return null;
        $quando = date('d/m/Y', strtotime($scadenza));
        return $giorni > 0
            ? "⚠️ Fattura n. $numero: pagata $giorni gg in ritardo (valuta " . date('d/m/Y', strtotime($valuta)) . ", scadenza $quando, $termini)."
            : "ℹ️ Fattura n. $numero: pagata " . (-$giorni) . " gg in anticipo (valuta " . date('d/m/Y', strtotime($valuta)) . ", scadenza $quando, $termini).";
    }

    /**
     * Se la nota di credito indica le fatture collegate (DatiFattureCollegate) e il suo totale le copre
     * esattamente, fatture e nota diventano «pagata» (chiuse per storno). Restituisce i numeri chiusi o ''.
     */
    private function chiudiStornate(SimpleXMLElement $body, int $clienteId, string $numeroNc, string $dataNc): string {
        $collegate = [];
        foreach ($body->DatiGenerali->DatiFattureCollegate as $fc) {
            $n = trim((string)($fc->IdDocumento ?? ''));
            if ($n !== '') $collegate[] = $n;
        }
        if (!$collegate) return '';
        $p = $this->prefix;
        $in = implode(',', array_fill(0, count($collegate), '?'));
        $stmt = $this->pdo->prepare("SELECT id, importo_totale FROM {$p}fatture
            WHERE cliente_id = ? AND numero_fattura IN ($in) AND importo_totale > 0 AND stato <> 'pagata'");
        $stmt->execute(array_merge([$clienteId], $collegate));
        $fatture = $stmt->fetchAll();
        $stmt = $this->pdo->prepare("SELECT id, importo_totale FROM {$p}fatture
            WHERE cliente_id = ? AND numero_fattura = ? AND data_emissione = ? AND importo_totale < 0 AND stato <> 'pagata'");
        $stmt->execute([$clienteId, $numeroNc, $dataNc]);
        $note = $stmt->fetchAll();
        $totF = array_sum(array_column($fatture, 'importo_totale'));
        $totNc = array_sum(array_column($note, 'importo_totale'));
        if (!$fatture || !$note || abs($totF + $totNc) > 0.01) return ''; // storno parziale: resta aperto, lo gestisce la riconciliazione
        $ids = array_merge(array_column($fatture, 'id'), array_column($note, 'id'));
        $this->pdo->prepare("UPDATE {$p}fatture SET stato = 'pagata', data_pagamento = ?, metodo_pagamento = 'Nota di credito'
            WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")")->execute(array_merge([$dataNc], $ids));
        // Solo i totali della commessa: l'aggancio alle rate qui non c'entra (e darebbe avvisi fuori luogo)
        $stmt = $this->pdo->prepare("SELECT DISTINCT incarico_id FROM {$p}fatture WHERE incarico_id IS NOT NULL AND id IN ("
            . implode(',', array_fill(0, count($ids), '?')) . ")");
        $stmt->execute($ids);
        require_once __DIR__ . '/IncarchiController.php';
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $inc) (new IncarchiController())->recalculate((int)$inc);
        return implode(', ', array_unique($collegate));
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
    private static function daElenco(string $descrizione): bool {
        return str_contains($descrizione, self::DA_ELENCO) || str_contains($descrizione, self::DA_ELENCO_VECCHIO);
    }

    /**
     * Record già presenti dello stesso documento (numero, anno, fattura o nota di credito): quelli del cliente
     * e quelli entrati dall'elenco, che possono avere un cliente diverso (creato dal nome) o nessuno.
     * Il numero comprende il registro («69/001»), quindi non si confonde tra registri.
     */
    private function righeDocumento(string $numero, string $data, ?int $clienteId, int $segno, string $tipo, bool $conTipoDoc): array {
        $sql = "SELECT id, descrizione, cliente_id, stato, data_pagamento FROM {$this->prefix}fatture
            WHERE numero_fattura = ? AND YEAR(data_emissione) = YEAR(?)";
        $par = [$numero, $data];
        if ($conTipoDoc) {
            $sql .= " AND (tipo_documento = ? OR (tipo_documento IS NULL AND importo_totale " . ($segno < 0 ? '<' : '>=') . " 0))";
            $par[] = $tipo;
        } else {
            $sql .= " AND importo_totale " . ($segno < 0 ? '<' : '>=') . " 0";
        }
        $sql .= " ORDER BY id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($par);
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
            fn($r) => ($clienteId && (int)$r['cliente_id'] === $clienteId) || self::daElenco((string)$r['descrizione'])));
    }

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
