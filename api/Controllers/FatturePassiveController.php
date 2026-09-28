<?php
/**
 * Fatture Passive Controller — fatture ricevute da partner e fornitori.
 * Entrano a mano o dall'XML SDI (scaricato da Sistemi o dal cassetto fiscale) e si
 * collegano al costo della commessa: così lo scadenzario sa quando pagarle e il margine è reale.
 */

require_once __DIR__ . '/../Shared/AnagraficaMatcher.php';
require_once __DIR__ . '/../Shared/CommessaService.php';

class FatturePassiveController {
    private $pdo;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    public function list() {
        $p = $this->prefix;
        $year = (int)($_POST['year'] ?? $_GET['year'] ?? date('Y'));
        $stato = $_POST['stato'] ?? $_GET['stato'] ?? '';
        $sql = "SELECT fp.*, fo.ragione_sociale AS fornitore_nome, cl.ragione_sociale AS cliente_nome,
                c.descrizione AS costo_descrizione, c.condizione_pagamento
            FROM {$p}fatture_passive fp
            LEFT JOIN {$p}fornitori fo ON fo.id = fp.fornitore_id
            LEFT JOIN {$p}commessa_costi c ON c.id = fp.costo_id
            LEFT JOIN {$p}incarichi i ON i.id = fp.incarico_id
            LEFT JOIN {$p}clienti cl ON cl.id = i.cliente_id
            WHERE YEAR(fp.data_emissione) = ?";
        $params = [$year];
        if (in_array($stato, ['da_pagare', 'pagata'], true)) {
            $sql .= " AND fp.stato = ?";
            $params[] = $stato;
        }
        $stmt = $this->pdo->prepare($sql . " ORDER BY fp.data_emissione DESC, fp.id DESC");
        $stmt->execute($params);
        Response::json(true, '', $stmt->fetchAll());
    }

    public function save($data) {
        $p = $this->prefix;
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $imponibile = round((float)($data['imponibile'] ?? 0), 2);
        $iva = round((float)($data['importo_iva'] ?? 0), 2);
        $ritenuta = round((float)($data['ritenuta'] ?? 0), 2);
        $costoId = !empty($data['costo_id']) ? (int)$data['costo_id'] : null;
        $fields = [
            'fornitore_id'   => !empty($data['fornitore_id']) ? (int)$data['fornitore_id'] : null,
            'costo_id'       => $costoId,
            'incarico_id'    => $costoId ? $this->incaricoDelCosto($costoId) : (!empty($data['incarico_id']) ? (int)$data['incarico_id'] : null),
            'numero'         => trim((string)($data['numero'] ?? '')),
            'data_emissione' => $this->data($data['data_emissione'] ?? null) ?? date('Y-m-d'),
            'descrizione'    => trim((string)($data['descrizione'] ?? '')) ?: null,
            'imponibile'     => $imponibile,
            'importo_iva'    => $iva,
            'ritenuta'       => $ritenuta,
            'importo_totale' => round($imponibile + $iva - $ritenuta, 2),
            'data_scadenza'  => $this->data($data['data_scadenza'] ?? null),
            'data_pagamento' => $this->data($data['data_pagamento'] ?? null),
            'note'           => trim((string)($data['note'] ?? '')) ?: null,
        ];
        $fields['stato'] = $fields['data_pagamento'] ? 'pagata' : 'da_pagare';
        if ($fields['numero'] === '') Response::json(false, 'Numero fattura obbligatorio');
        if (!$fields['fornitore_id']) Response::json(false, 'Fornitore obbligatorio');
        if (!$fields['data_scadenza']) {
            $fields['data_scadenza'] = date('Y-m-d', strtotime($fields['data_emissione'] . ' +' . $this->giorniPagamento($fields['fornitore_id'], $costoId) . ' days'));
        }

        // Chiave unica fornitore + numero + data: il duplicato è un errore dell'utente, non del server
        $msgDup = 'La fattura n. ' . $fields['numero'] . ' del ' . date('d/m/Y', strtotime($fields['data_emissione']))
            . ' è già registrata per questo fornitore';
        try {
            if ($id) {
                $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
                $this->pdo->prepare("UPDATE {$p}fatture_passive SET $sets WHERE id = ?")->execute(array_merge(array_values($fields), [$id]));
            } else {
                $cols = implode(', ', array_keys($fields));
                $ph = implode(', ', array_fill(0, count($fields), '?'));
                $this->pdo->prepare("INSERT INTO {$p}fatture_passive ($cols) VALUES ($ph)")->execute(array_values($fields));
                $id = (int)$this->pdo->lastInsertId();
            }
        } catch (PDOException $e) {
            if ((int)($e->errorInfo[1] ?? 0) === 1062) Response::json(false, $msgDup, null, 409);
            throw $e;
        }
        Audit::log(!empty($data['id']) ? 'UPDATE' : 'INSERT', 'fatture_passive', (string)$id, null, ['numero' => $fields['numero'], 'totale' => $fields['importo_totale']]);
        Response::json(true, 'Fattura fornitore salvata', ['id' => $id]);
    }

    public function delete($id) {
        $this->bloccaSeRiconciliata((int)$id);
        $this->pdo->prepare("DELETE FROM {$this->prefix}fatture_passive WHERE id = ?")->execute([(int)$id]);
        Audit::log('DELETE', 'fatture_passive', (string)$id, null, null);
        Response::json(true, 'Fattura fornitore eliminata');
    }

    /** Una fattura abbinata a un movimento bancario non si cancella né si riapre a mano */
    private function bloccaSeRiconciliata(int $id): void {
        require_once __DIR__ . '/../Shared/Riconciliatore.php';
        if (Riconciliatore::riconciliazioniDi($this->pdo, $this->prefix, 'fattura_passiva', $id)) {
            Response::json(false, 'La fattura è abbinata a un movimento bancario: annulla prima la riconciliazione (Contabilità › Riconciliazione).');
        }
    }

    /** Segna pagata (o annulla il pagamento con data vuota). */
    public function setPagata($data) {
        $id = (int)($data['id'] ?? 0);
        $dataPag = $this->data($data['data_pagamento'] ?? null);
        if (($data['annulla'] ?? '') === '1') {
            $this->bloccaSeRiconciliata($id);
            $dataPag = null;
        } elseif (!$dataPag) $dataPag = date('Y-m-d');
        $this->pdo->prepare("UPDATE {$this->prefix}fatture_passive SET data_pagamento = ?, stato = ? WHERE id = ?")
            ->execute([$dataPag, $dataPag ? 'pagata' : 'da_pagare', $id]);
        Audit::log('UPDATE', 'fatture_passive', (string)$id, null, ['data_pagamento' => $dataPag]);
        Response::json(true, $dataPag ? 'Pagamento registrato' : 'Pagamento annullato');
    }

    /** Import dell'XML FatturaPA ricevuto dal partner. */
    public function importXml($data) {
        $p = $this->prefix;
        $xml = $this->loadXml((string)($data['xml'] ?? ''));
        $header = $xml->FatturaElettronicaHeader;
        $cedente = $header->CedentePrestatore->DatiAnagrafici;
        $piva = (string)($cedente->IdFiscaleIVA->IdCodice ?? '');
        $cf = (string)($cedente->CodiceFiscale ?? '');
        $nome = trim((string)($cedente->Anagrafica->Denominazione ?? '')) ?: trim((string)($cedente->Anagrafica->Nome ?? '') . ' ' . (string)($cedente->Anagrafica->Cognome ?? ''));

        $nostraPiva = AnagraficaMatcher::normalizzaCodice((string)getenv('AZIENDA_PARTITA_IVA'));
        if ($nostraPiva !== '' && AnagraficaMatcher::normalizzaCodice($piva) === $nostraPiva) {
            Response::json(false, 'È una fattura emessa da MV Consulting: importala dalla scheda Fatture');
        }

        $messaggi = [];
        $this->pdo->beginTransaction();
        $fornitoreId = AnagraficaMatcher::trovaFornitore($this->pdo, $p, $piva, $cf, $nome);
        if (!$fornitoreId) {
            $sede = $header->CedentePrestatore->Sede;
            $this->pdo->prepare("INSERT INTO {$p}fornitori (ragione_sociale, tipo, partita_iva, codice_fiscale, note) VALUES (?, 'partner', ?, ?, ?)")
                ->execute([$nome ?: 'Fornitore sconosciuto', $piva ?: null, $cf ?: null,
                    trim((string)($sede->Indirizzo ?? '') . ' ' . (string)($sede->CAP ?? '') . ' ' . (string)($sede->Comune ?? ''))]);
            $fornitoreId = (int)$this->pdo->lastInsertId();
            $messaggi[] = "Fornitore '$nome' creato in anagrafica.";
        }

        $importate = 0;
        foreach ($xml->FatturaElettronicaBody as $body) {
            $gen = $body->DatiGenerali->DatiGeneraliDocumento;
            $numero = (string)($gen->Numero ?? '');
            $dataEm = (string)($gen->Data ?? date('Y-m-d'));
            $segno = strtoupper((string)($gen->TipoDocumento ?? '')) === 'TD04' ? -1 : 1;

            $imponibile = 0.0; $iva = 0.0;
            foreach ($body->DatiBeniServizi->DatiRiepilogo as $r) {
                $imponibile += (float)$r->ImponibileImporto;
                $iva += (float)$r->Imposta;
            }
            $ritenuta = 0.0;
            foreach ($gen->DatiRitenuta as $rit) $ritenuta += (float)$rit->ImportoRitenuta;
            $descr = [];
            foreach ($body->DatiBeniServizi->DettaglioLinee as $l) $descr[] = trim((string)$l->Descrizione);
            $descrizione = implode("\n", array_filter($descr));

            $scadenza = null;
            foreach ($body->DatiPagamento as $dp) {
                foreach ($dp->DettaglioPagamento as $det) {
                    $s = (string)($det->DataScadenzaPagamento ?? '');
                    if ($s && (!$scadenza || $s < $scadenza)) $scadenza = $s;
                }
            }

            [$costoId, $incaricoId] = $this->abbinaCosto($fornitoreId, $descrizione, round($imponibile, 2));
            if (!$scadenza) {
                $scadenza = date('Y-m-d', strtotime($dataEm . ' +' . $this->giorniPagamento($fornitoreId, $costoId) . ' days'));
            }

            $imp = round($segno * $imponibile, 2);
            $iv = round($segno * $iva, 2);
            $rit = round($segno * $ritenuta, 2);
            $stmt = $this->pdo->prepare("SELECT id, descrizione FROM {$p}fatture_passive WHERE fornitore_id = ? AND numero = ? AND data_emissione = ?");
            $stmt->execute([$fornitoreId, $numero, $dataEm]);
            $gia = $stmt->fetch(PDO::FETCH_ASSOC);
            if ($gia && str_contains((string)$gia['descrizione'], self::DA_ELENCO)) {
                // Entrata dall'elenco col solo totale: l'XML porta imponibile, IVA, ritenuta, righe e scadenza
                $this->pdo->prepare("UPDATE {$p}fatture_passive SET descrizione = ?, imponibile = ?, importo_iva = ?, ritenuta = ?, importo_totale = ?,
                        data_scadenza = ?, costo_id = COALESCE(costo_id, ?), incarico_id = COALESCE(incarico_id, ?) WHERE id = ?")
                    ->execute([$descrizione, $imp, $iv, $rit, round($imp + $iv - $rit, 2), $scadenza, $costoId, $incaricoId, $gia['id']]);
                $importate++;
                $messaggi[] = "Fattura $numero di $nome completata con i dati dell'XML.";
                continue;
            }
            if ($gia) {
                $messaggi[] = "Fattura $numero di $nome già presente.";
                continue;
            }
            try {
                $this->pdo->prepare("INSERT INTO {$p}fatture_passive
                        (fornitore_id, incarico_id, costo_id, numero, data_emissione, descrizione, imponibile, importo_iva, ritenuta, importo_totale, data_scadenza)
                        VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
                    ->execute([$fornitoreId, $incaricoId, $costoId, $numero, $dataEm, $descrizione, $imp, $iv, $rit, round($imp + $iv - $rit, 2), $scadenza]);
            } catch (PDOException $e) {
                // Stesso numero due volte nello stesso file: si segnala e si prosegue
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                $messaggi[] = "Fattura $numero di $nome già presente.";
                continue;
            }
            $importate++;
            $messaggi[] = "Fattura $numero di $nome importata" . ($costoId ? ' e collegata alla commessa.' : ': da collegare a una commessa.');
        }
        $this->pdo->commit();
        Audit::log('IMPORT', 'fatture_passive', null, null, null, ['fornitore' => $nome, 'importate' => $importate]);
        Response::json(true, 'Import completato', ['num_imported' => $importate, 'messages' => $messaggi]);
    }

    /** Descrizione delle fatture entrate da un elenco: l'XML, quando arriva, le completa (importXml). */
    public const DA_ELENCO = 'Dall\'elenco fatture ricevute: solo il totale, imponibile e IVA arrivano con l\'XML.';

    /**
     * Fatture ricevute da un elenco Excel (portale fatture): una per riga, col fornitore riconosciuto per nome
     * o creato (la P.IVA si cerca poi sul web, AnagraficaAuto). L'elenco dà solo il totale: finché non arriva
     * l'XML imponibile = totale e IVA = 0.
     * Le stesse righe entrate per errore tra le emesse (vecchio import «Lista fatture», senza cliente) si tolgono.
     */
    public function importLista(array $letto, string $nomeFile) {
        require_once __DIR__ . '/../Shared/AnagraficaAuto.php';
        require_once __DIR__ . '/../Shared/Riconciliatore.php';
        $p = $this->prefix;
        $fornitori = $this->pdo->query("SELECT id, partita_iva, codice_fiscale, ragione_sociale FROM {$p}fornitori WHERE deleted_at IS NULL")->fetchAll(PDO::FETCH_ASSOC);
        $perNome = [];
        $out = ['verso' => 'passiva', 'num_imported' => 0, 'num_existing' => 0, 'num_tolte_emesse' => 0,
            'anagrafiche_create' => [], 'errors' => $letto['avvisi']];

        $this->pdo->beginTransaction();
        try {
            $esiste = $this->pdo->prepare("SELECT id FROM {$p}fatture_passive WHERE fornitore_id = ? AND numero = ? AND data_emissione = ?");
            $ins = $this->pdo->prepare("INSERT INTO {$p}fatture_passive
                (fornitore_id, incarico_id, costo_id, numero, data_emissione, descrizione, imponibile, importo_iva, ritenuta, importo_totale, data_scadenza)
                VALUES (?, NULL, NULL, ?, ?, ?, ?, 0, 0, ?, ?)");
            $emessaSbagliata = $this->pdo->prepare("SELECT id FROM {$p}fatture WHERE numero_fattura = ? AND data_emissione = ?
                AND cliente_id IS NULL AND ABS(importo_totale - ?) < 0.01 AND descrizione LIKE '%Importata dalla lista fatture di Sistemi%'");
            foreach ($letto['fatture'] as $d) {
                // Tolta dalle emesse se c'era finita per errore (e non è già abbinata alla banca)
                $emessaSbagliata->execute([$d['numero'], $d['data'], abs($d['totale'])]);
                foreach ($emessaSbagliata->fetchAll(PDO::FETCH_COLUMN) as $idEmessa) {
                    if (Riconciliatore::riconciliazioniDi($this->pdo, $p, 'fattura', (int)$idEmessa)) continue;
                    $this->pdo->prepare("DELETE FROM {$p}fatture WHERE id = ?")->execute([(int)$idEmessa]);
                    $out['num_tolte_emesse']++;
                }
                if ($d['cliente'] === '') {
                    $out['errors'][] = "Fattura {$d['numero']}: manca il fornitore, saltata.";
                    continue;
                }
                if (!isset($perNome[$d['cliente']])) {
                    [$perNome[$d['cliente']], $creato] = AnagraficaAuto::trovaOCrea($this->pdo, $p, 'fornitore', $d['cliente'], $fornitori);
                    if ($creato) $out['anagrafiche_create'][] = $d['cliente'];
                }
                $fornitoreId = $perNome[$d['cliente']];
                $esiste->execute([$fornitoreId, $d['numero'], $d['data']]);
                if ($esiste->fetchColumn()) { $out['num_existing']++; continue; }
                $scadenza = date('Y-m-d', strtotime($d['data'] . ' +' . $this->giorniPagamento($fornitoreId, null) . ' days'));
                $ins->execute([$fornitoreId, $d['numero'], $d['data'], ($d['nota_credito'] ? "[Nota di credito]\n" : '') . self::DA_ELENCO,
                    $d['totale'], $d['totale'], $scadenza]);
                $out['num_imported']++;
            }
            $this->pdo->commit();
        } catch (\Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[FatturePassive::importLista] ' . $e->getMessage());
            Response::json(false, 'Import annullato: ' . $e->getMessage());
        }
        $out['da_cercare'] = AnagraficaAuto::daCercare($this->pdo, $p);
        Audit::log('IMPORT', 'fatture_passive', null, null, null, ['file' => $nomeFile, 'nuove' => $out['num_imported'], 'gia_presenti' => $out['num_existing']]);
        Response::json(true, 'Elenco fatture ricevute importato', $out);
    }

    // ── Helper ──────────────────────────────────────────

    /**
     * Costo di commessa a cui appartiene la fattura del partner:
     * 1) riferimento all'offerta nella descrizione (OFF-AAAA-NNN) → costo di quel fornitore su quell'incarico
     * 2) un solo costo del fornitore ancora da fatturare del tutto
     * 3) tra più costi aperti, quello con il residuo uguale all'importo
     */
    private function abbinaCosto(int $fornitoreId, string $descrizione, float $imponibile): array {
        $p = $this->prefix;
        $incRif = (new CommessaService($this->pdo, $p))->trovaIncaricoPerRiferimento($descrizione, null);
        $stmt = $this->pdo->prepare("SELECT c.id, c.incarico_id, c.importo_previsto,
                c.importo_previsto - COALESCE((SELECT SUM(x.imponibile) FROM {$p}fatture_passive x WHERE x.costo_id = c.id), 0) AS residuo
            FROM {$p}commessa_costi c WHERE c.fornitore_id = ? AND c.incarico_id IS NOT NULL ORDER BY c.id DESC");
        $stmt->execute([$fornitoreId]);
        $costi = $stmt->fetchAll();
        if ($incRif) {
            foreach ($costi as $c) {
                if ((int)$c['incarico_id'] === $incRif) return [(int)$c['id'], $incRif];
            }
        }
        $aperti = array_values(array_filter($costi, fn($c) => (float)$c['residuo'] > 0.5));
        if (count($aperti) === 1) return [(int)$aperti[0]['id'], (int)$aperti[0]['incarico_id']];
        foreach ($aperti as $c) {
            if (abs((float)$c['residuo'] - $imponibile) <= 1.0) return [(int)$c['id'], (int)$c['incarico_id']];
        }
        return [null, $incRif];
    }

    private function incaricoDelCosto(int $costoId): ?int {
        $stmt = $this->pdo->prepare("SELECT incarico_id FROM {$this->prefix}commessa_costi WHERE id = ?");
        $stmt->execute([$costoId]);
        return ((int)$stmt->fetchColumn()) ?: null;
    }

    private function giorniPagamento(?int $fornitoreId, ?int $costoId): int {
        if ($costoId) {
            $stmt = $this->pdo->prepare("SELECT giorni_pagamento FROM {$this->prefix}commessa_costi WHERE id = ?");
            $stmt->execute([$costoId]);
            $g = $stmt->fetchColumn();
            if ($g !== false) return (int)$g;
        }
        if ($fornitoreId) {
            $stmt = $this->pdo->prepare("SELECT giorni_pagamento FROM {$this->prefix}fornitori WHERE id = ?");
            $stmt->execute([$fornitoreId]);
            $g = $stmt->fetchColumn();
            if ($g !== false) return (int)$g;
        }
        return 30;
    }

    /** Contenuto XML da un file .xml o firmato .xml.p7m (inviato in base64). */
    private function contenutoFile(string $b64): string {
        require_once __DIR__ . '/../Shared/P7m.php';
        $raw = base64_decode($b64, true);
        return $raw === false ? '' : P7m::xmlDaFile($raw);
    }

    private function loadXml(string $content): SimpleXMLElement {
        if (trim($content) === '' && !empty($_POST['file_b64'])) {
            $content = $this->contenutoFile((string)$_POST['file_b64']);
            if ($content === '') Response::json(false, 'File firmato (p7m) non leggibile');
        }
        if (trim($content) === '') Response::json(false, 'Nessun contenuto XML fornito');
        // Una FatturaPA non ha DOCTYPE: se c'è, il file non è una fattura (e le entità non vanno espanse)
        if (stripos($content, '<!DOCTYPE') !== false) Response::json(false, 'File XML non valido: contiene una dichiarazione DOCTYPE');
        // Namespace rimossi come nell'import delle fatture emesse
        $content = preg_replace('/(<\/?)(?!xml)[a-zA-Z0-9_-]+:/i', '$1', $content);
        $content = preg_replace('/\sxmlns=[\'"].*?[\'"]/i', '', $content);
        $content = preg_replace('/\sxmlns:[a-zA-Z0-9_-]+=[\'"].*?[\'"]/i', '', $content);
        libxml_use_internal_errors(true);
        $xml = simplexml_load_string($content, 'SimpleXMLElement', LIBXML_NONET);
        if ($xml === false || !$xml->FatturaElettronicaHeader || !$xml->FatturaElettronicaBody) {
            Response::json(false, 'XML non valido: non è una fattura elettronica');
        }
        return $xml;
    }

    private function data($v): ?string {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y) ? $v : null;
    }
}
