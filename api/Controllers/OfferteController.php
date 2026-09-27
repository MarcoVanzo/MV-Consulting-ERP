<?php
/**
 * Offerte Controller — preventivi ai clienti.
 * Ciclo: bozza → inviata (con data di ricontatto) → accettata (diventa incarico con piano rate) | rifiutata | scaduta.
 * Una revisione crea una nuova versione e marca la precedente come "sostituita".
 */

require_once __DIR__ . '/../Shared/CommessaService.php';
require_once __DIR__ . '/../Shared/Documenti.php';
require_once __DIR__ . '/../Shared/DocumentAi.php';
require_once __DIR__ . '/../Shared/AnagraficaMatcher.php';

class OfferteController {
    private $pdo;
    private $prefix;

    private const STATI = ['bozza', 'inviata', 'accettata', 'rifiutata', 'scaduta', 'sostituita'];
    private const TIPI = ['assistenza', 'dpo', 'formazione', 'nis2', 'ict', 'digital', 'sviluppo_software'];

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    public function list() {
        $p = $this->prefix;
        $year = $_POST['year'] ?? $_GET['year'] ?? date('Y');
        $stato = $_POST['stato'] ?? $_GET['stato'] ?? '';

        $sql = "SELECT o.*, COALESCE(c.ragione_sociale, o.cliente_nome) AS cliente_nome_vis, sc.nome AS sottocliente_nome,
                COALESCE(cp.costi, 0) AS costi_previsti,
                (o.stato = 'inviata' AND o.data_followup IS NOT NULL AND o.data_followup <= CURDATE()) AS da_ricontattare
            FROM {$p}offerte o
            LEFT JOIN {$p}clienti c ON c.id = o.cliente_id
            LEFT JOIN {$p}sottoclienti sc ON sc.id = o.sottocliente_id
            LEFT JOIN (SELECT offerta_id, SUM(importo_previsto) AS costi FROM {$p}commessa_costi GROUP BY offerta_id) cp ON cp.offerta_id = o.id
            WHERE o.deleted_at IS NULL AND YEAR(o.data_offerta) = ?";
        $params = [(int)$year];
        if ($stato !== '' && in_array($stato, self::STATI, true)) {
            $sql .= " AND o.stato = ?";
            $params[] = $stato;
        } elseif ($stato === '') {
            // Le versioni superate restano consultabili dal filtro dedicato
            $sql .= " AND o.stato <> 'sostituita'";
        }
        $sql .= " ORDER BY o.data_offerta DESC, o.numero DESC, o.versione DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        // KPI dell'anno: pipeline aperta e tasso di conversione
        $stmt = $this->pdo->prepare("SELECT
                COUNT(CASE WHEN stato <> 'sostituita' THEN 1 END) AS num_offerte,
                COALESCE(SUM(CASE WHEN stato IN ('bozza','inviata') THEN imponibile END), 0) AS pipeline,
                COUNT(CASE WHEN stato = 'inviata' THEN 1 END) AS num_inviate,
                COALESCE(SUM(CASE WHEN stato = 'accettata' THEN imponibile END), 0) AS accettato,
                COUNT(CASE WHEN stato = 'accettata' THEN 1 END) AS num_accettate,
                COUNT(CASE WHEN stato IN ('rifiutata','scaduta') THEN 1 END) AS num_perse
            FROM {$p}offerte WHERE deleted_at IS NULL AND YEAR(data_offerta) = ?");
        $stmt->execute([(int)$year]);
        $kpis = $stmt->fetch();
        $chiuse = (int)$kpis['num_accettate'] + (int)$kpis['num_perse'];
        $kpis['tasso_conversione'] = $chiuse > 0 ? round((int)$kpis['num_accettate'] / $chiuse * 100) : null;

        Response::json(true, '', ['offerte' => $rows, 'kpis' => $kpis]);
    }

    public function get($id) {
        $o = $this->load((int)$id);
        if (!$o) Response::json(false, 'Offerta non trovata', null, 404);
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->prefix}offerte_righe WHERE offerta_id = ? ORDER BY ordine, id");
        $stmt->execute([(int)$id]);
        $o['righe'] = $stmt->fetchAll();
        $o['piano_rate'] = json_decode((string)$o['piano_rate'], true) ?: [];
        $svc = new CommessaService($this->pdo, $this->prefix);
        $o['costi'] = $o['incarico_id'] ? $svc->costi((int)$o['incarico_id']) : $svc->costi(null, (int)$id);
        $o['ha_documento'] = Documenti::percorso($o['file_path']) !== null;
        unset($o['file_path']);
        Response::json(true, '', $o);
    }

    public function prossimoNumero() {
        Response::json(true, '', ['numero' => $this->nuovoNumero((int)date('Y'))]);
    }

    public function save($data) {
        $p = $this->prefix;
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $current = $id ? $this->load($id) : null;
        if ($id && !$current) Response::json(false, 'Offerta non trovata', null, 404);
        if ($current && in_array($current['stato'], ['accettata', 'sostituita'], true)) {
            Response::json(false, 'Offerta ' . $current['stato'] . ': crea una nuova versione per modificarla');
        }

        $righe = $this->parseRighe($data['righe'] ?? '[]');
        $piano = $this->parsePiano($data['piano_rate'] ?? '[]');
        $imponibile = $righe ? round(array_sum(array_column($righe, 'importo')), 2) : round((float)($data['imponibile'] ?? 0), 2);

        $dataOfferta = $this->data($data['data_offerta'] ?? null) ?? date('Y-m-d');
        $fields = [
            'cliente_id'           => !empty($data['cliente_id']) ? (int)$data['cliente_id'] : null,
            'cliente_nome'         => trim((string)($data['cliente_nome'] ?? '')) ?: null,
            'sottocliente_id'      => !empty($data['sottocliente_id']) ? (int)$data['sottocliente_id'] : null,
            'data_offerta'         => $dataOfferta,
            'data_scadenza'        => $this->data($data['data_scadenza'] ?? null),
            'oggetto'              => trim((string)($data['oggetto'] ?? '')),
            'descrizione'          => trim((string)($data['descrizione'] ?? '')) ?: null,
            'tipo_commessa'        => in_array($data['tipo_commessa'] ?? '', self::TIPI, true) ? $data['tipo_commessa'] : 'assistenza',
            'num_giornate'         => round((float)($data['num_giornate'] ?? 0), 1),
            'imponibile'           => $imponibile,
            'iva_percentuale'      => isset($data['iva_percentuale']) && $data['iva_percentuale'] !== '' ? round((float)$data['iva_percentuale'], 2) : 22.0,
            'condizioni_pagamento' => trim((string)($data['condizioni_pagamento'] ?? '')) ?: null,
            'giorni_pagamento'     => max(0, (int)($data['giorni_pagamento'] ?? 30)),
            'piano_rate'           => json_encode($piano, JSON_UNESCAPED_UNICODE),
            'data_followup'        => $this->data($data['data_followup'] ?? null),
            'note'                 => trim((string)($data['note'] ?? '')) ?: null,
        ];
        if ($fields['oggetto'] === '') Response::json(false, 'Oggetto obbligatorio');
        if (!$fields['cliente_id'] && !$fields['cliente_nome']) Response::json(false, 'Indica il cliente');
        if ($fields['cliente_id']) $fields['cliente_nome'] = null;
        $sommaPerc = array_sum(array_column($piano, 'percentuale'));
        if ($piano && abs($sommaPerc - 100) > 0.01) Response::json(false, 'Le rate devono sommare al 100% (ora ' . round($sommaPerc, 2) . '%)');

        try {
            $file = Documenti::salvaUpload('file');
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }

        $this->pdo->beginTransaction();
        if (!$fields['cliente_id']) {
            $fields['cliente_id'] = $this->clienteDaProspect($fields['cliente_nome']);
            $fields['cliente_nome'] = null;
        }
        if ($id) {
            if ($file) $fields['file_path'] = $file;
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $this->pdo->prepare("UPDATE {$p}offerte SET $sets WHERE id = ?")->execute(array_merge(array_values($fields), [$id]));
        } else {
            $fields['numero'] = $this->nuovoNumero((int)substr($dataOfferta, 0, 4));
            $fields['versione'] = 1;
            $fields['stato'] = 'bozza';
            $fields['file_path'] = $file;
            $fields['origine'] = ($data['origine'] ?? '') === 'cowork' ? 'cowork' : 'manuale';
            $cols = implode(', ', array_keys($fields));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $this->pdo->prepare("INSERT INTO {$p}offerte ($cols) VALUES ($ph)")->execute(array_values($fields));
            $id = (int)$this->pdo->lastInsertId();
        }
        $this->salvaRighe($id, $righe);
        $this->pdo->commit();
        if ($file && $current) Documenti::elimina($current['file_path']);

        Audit::log($current ? 'UPDATE' : 'INSERT', 'offerte', (string)$id, $current, ['oggetto' => $fields['oggetto'], 'imponibile' => $imponibile]);
        Response::json(true, $current ? 'Offerta aggiornata' : 'Offerta creata', ['id' => $id]);
    }

    public function delete($id) {
        $o = $this->load((int)$id);
        if (!$o) Response::json(false, 'Offerta non trovata', null, 404);
        if ($o['stato'] === 'accettata') Response::json(false, "Offerta accettata: elimina prima l'incarico collegato");
        $this->pdo->beginTransaction();
        $this->pdo->prepare("UPDATE {$this->prefix}offerte SET deleted_at = NOW() WHERE id = ?")->execute([(int)$id]);
        if ($o['stato'] !== 'sostituita') {
            // Tolta una revisione, la versione precedente torna modificabile
            $this->pdo->prepare("UPDATE {$this->prefix}offerte
                SET stato = CASE WHEN data_invio IS NULL THEN 'bozza' ELSE 'inviata' END
                WHERE numero = ? AND stato = 'sostituita' AND deleted_at IS NULL
                ORDER BY versione DESC LIMIT 1")->execute([$o['numero']]);
        }
        $this->pdo->commit();
        Audit::log('DELETE', 'offerte', (string)$id, $o, null);
        Response::json(true, 'Offerta eliminata');
    }

    /** Cambio di stato: inviata, rifiutata, scaduta, bozza (riapertura). */
    public function setStato($data) {
        $id = (int)($data['id'] ?? 0);
        $stato = $data['stato'] ?? '';
        $o = $this->load($id);
        if (!$o) Response::json(false, 'Offerta non trovata', null, 404);
        if (!in_array($stato, ['bozza', 'inviata', 'rifiutata', 'scaduta'], true)) Response::json(false, 'Stato non valido');
        if (in_array($o['stato'], ['accettata', 'sostituita'], true)) Response::json(false, 'Offerta ' . $o['stato'] . ': stato non modificabile');

        $set = ['stato' => $stato];
        if ($stato === 'inviata') {
            $set['data_invio'] = $this->data($data['data_invio'] ?? null) ?? ($o['data_invio'] ?: date('Y-m-d'));
            // Senza una data di ricontatto esplicita: una settimana dopo l'invio
            $set['data_followup'] = $this->data($data['data_followup'] ?? null)
                ?? ($o['data_followup'] ?: date('Y-m-d', strtotime($set['data_invio'] . ' +7 days')));
            if (!$o['data_scadenza']) {
                $set['data_scadenza'] = date('Y-m-d', strtotime($set['data_invio'] . ' +30 days'));
            }
        }
        if (in_array($stato, ['rifiutata', 'scaduta'], true)) {
            $set['data_esito'] = date('Y-m-d');
            $set['motivo_esito'] = trim((string)($data['motivo_esito'] ?? '')) ?: null;
        }
        $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($set)));
        $this->pdo->prepare("UPDATE {$this->prefix}offerte SET $sets WHERE id = ?")->execute(array_merge(array_values($set), [$id]));
        Audit::log('UPDATE', 'offerte', (string)$id, ['stato' => $o['stato']], $set);
        Response::json(true, 'Offerta: ' . $stato);
    }

    /**
     * Accettazione: nasce l'incarico con il piano rate dell'offerta;
     * i costi dei partner stimati sull'offerta passano alla commessa.
     */
    public function accetta($data) {
        $p = $this->prefix;
        $id = (int)($data['id'] ?? 0);
        $o = $this->load($id);
        if (!$o) Response::json(false, 'Offerta non trovata', null, 404);
        if (!in_array($o['stato'], ['bozza', 'inviata'], true)) Response::json(false, 'Offerta già ' . $o['stato']);
        if (!$o['cliente_id']) Response::json(false, 'Prima di accettarla collega l\'offerta a un cliente in anagrafica');
        if ((float)$o['imponibile'] <= 0) Response::json(false, 'L\'offerta non ha importo');

        $dataAcc = $this->data($data['data_accettazione'] ?? null) ?? date('Y-m-d');
        $protocollo = trim((string)($data['numero_protocollo'] ?? '')) ?: null;
        $piano = $this->parsePiano($o['piano_rate'] ?? '[]');
        // Piano salvato prima del controllo in save(): le rate devono comunque coprire il 100%
        $sommaPerc = array_sum(array_column($piano, 'percentuale'));
        if ($piano && abs($sommaPerc - 100) > 0.01) {
            Response::json(false, 'Il piano rate somma al ' . round($sommaPerc, 2) . '%: correggilo (100%) prima di accettare');
        }

        $this->pdo->beginTransaction();
        // Transizione atomica: due accettazioni concorrenti non creano due incarichi
        $stmt = $this->pdo->prepare("UPDATE {$p}offerte SET stato = 'accettata', data_esito = ?
            WHERE id = ? AND stato IN ('bozza', 'inviata') AND deleted_at IS NULL");
        $stmt->execute([$dataAcc, $id]);
        if ($stmt->rowCount() !== 1) {
            $this->pdo->rollBack();
            Response::json(false, 'Offerta già accettata o modificata nel frattempo: ricarica la pagina', null, 409);
        }
        $this->pdo->prepare("INSERT INTO {$p}incarichi
                (cliente_id, sottocliente_id, offerta_id, data_incarico, tipo_commessa, numero_protocollo, descrizione,
                 num_giornate, importo_totale, giorni_pagamento, condizioni_pagamento, note)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$o['cliente_id'], $o['sottocliente_id'], $id, $dataAcc, $o['tipo_commessa'], $protocollo,
                $o['oggetto'], $o['num_giornate'], $o['imponibile'], $o['giorni_pagamento'], $o['condizioni_pagamento'],
                'Da offerta ' . $o['numero'] . ($o['versione'] > 1 ? ' v' . $o['versione'] : '')]);
        $incaricoId = (int)$this->pdo->lastInsertId();

        (new CommessaService($this->pdo, $p))->creaRateDaPiano($incaricoId, (float)$o['imponibile'], $piano, $dataAcc, (int)$o['giorni_pagamento']);
        $this->pdo->prepare("UPDATE {$p}commessa_costi SET incarico_id = ? WHERE offerta_id = ? AND incarico_id IS NULL")->execute([$incaricoId, $id]);
        $this->pdo->prepare("UPDATE {$p}offerte SET incarico_id = ? WHERE id = ?")->execute([$incaricoId, $id]);
        $this->pdo->commit();

        Audit::log('UPDATE', 'offerte', (string)$id, ['stato' => $o['stato']], ['stato' => 'accettata', 'incarico_id' => $incaricoId]);
        Audit::log('INSERT', 'incarichi', (string)$incaricoId, null, ['da_offerta' => $o['numero'], 'importo_totale' => $o['imponibile']]);
        Response::json(true, 'Offerta accettata: incarico creato', ['incarico_id' => $incaricoId]);
    }

    /** Revisione: copia l'offerta come nuova versione in bozza, la precedente diventa "sostituita". */
    public function nuovaVersione($data) {
        $p = $this->prefix;
        $id = (int)($data['id'] ?? 0);
        $o = $this->load($id);
        if (!$o) Response::json(false, 'Offerta non trovata', null, 404);
        if (!in_array($o['stato'], ['bozza', 'inviata', 'rifiutata', 'scaduta'], true)) Response::json(false, 'Offerta ' . $o['stato'] . ': non si può rivedere');

        $stmt = $this->pdo->prepare("SELECT MAX(versione) FROM {$p}offerte WHERE numero = ?");
        $stmt->execute([$o['numero']]);
        $versione = (int)$stmt->fetchColumn() + 1;

        $copia = $o;
        unset($copia['id'], $copia['created_at'], $copia['updated_at'], $copia['deleted_at']);
        $copia = array_merge($copia, [
            'versione' => $versione, 'stato' => 'bozza', 'data_offerta' => date('Y-m-d'), 'data_invio' => null,
            'data_followup' => null, 'data_esito' => null, 'motivo_esito' => null, 'incarico_id' => null, 'file_path' => null,
            'data_scadenza' => null,
        ]);
        $this->pdo->beginTransaction();
        $cols = implode(', ', array_keys($copia));
        $ph = implode(', ', array_fill(0, count($copia), '?'));
        $this->pdo->prepare("INSERT INTO {$p}offerte ($cols) VALUES ($ph)")->execute(array_values($copia));
        $newId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("INSERT INTO {$p}offerte_righe (offerta_id, ordine, descrizione, quantita, unita, prezzo_unitario, importo)
            SELECT ?, ordine, descrizione, quantita, unita, prezzo_unitario, importo FROM {$p}offerte_righe WHERE offerta_id = ?")->execute([$newId, $id]);
        $this->pdo->prepare("INSERT INTO {$p}commessa_costi (offerta_id, fornitore_id, descrizione, importo_previsto, offerta_fornitore_numero,
                offerta_fornitore_data, offerta_fornitore_file, condizione_pagamento, giorni_pagamento, note)
            SELECT ?, fornitore_id, descrizione, importo_previsto, offerta_fornitore_numero, offerta_fornitore_data,
                offerta_fornitore_file, condizione_pagamento, giorni_pagamento, note
            FROM {$p}commessa_costi WHERE offerta_id = ? AND incarico_id IS NULL")->execute([$newId, $id]);
        $this->pdo->prepare("UPDATE {$p}offerte SET stato = 'sostituita' WHERE id = ?")->execute([$id]);
        $this->pdo->commit();

        Audit::log('INSERT', 'offerte', (string)$newId, null, ['numero' => $o['numero'], 'versione' => $versione]);
        Response::json(true, 'Creata la versione ' . $versione, ['id' => $newId]);
    }

    /**
     * Import di un preventivo fatto con Cowork (PDF, DOCX, TXT/MD): Claude ne legge i dati
     * e l'offerta nasce in bozza, da verificare.
     */
    public function importa() {
        if (!ClaudeClient::isConfigured()) {
            Response::json(false, 'Lettura automatica non attiva: manca ANTHROPIC_API_KEY nel .env del server');
        }
        try {
            $ref = Documenti::salvaUpload('file');
            if (!$ref) Response::json(false, 'Nessun file caricato');
            $path = Documenti::percorso($ref);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            if ($ext === 'pdf') {
                $doc = ['pdf_base64' => base64_encode((string)file_get_contents($path))];
            } elseif ($ext === 'docx') {
                $doc = ['text' => DocumentAi::testoDaDocx($path)];
            } elseif (in_array($ext, ['txt', 'md'], true)) {
                $doc = ['text' => (string)file_get_contents($path)];
            } else {
                Documenti::elimina($ref);
                Response::json(false, 'Formato non supportato: carica PDF, Word (.docx) o testo');
            }
            $x = DocumentAi::estraiOfferta($doc);
        } catch (RuntimeException $e) {
            if (!empty($ref)) Documenti::elimina($ref);
            Response::json(false, $e->getMessage());
        }

        $p = $this->prefix;
        $nome = (string)($x['cliente']['nome'] ?? '');
        $clienteId = AnagraficaMatcher::trovaCliente($this->pdo, $p, $x['cliente']['partita_iva'] ?? null, $x['cliente']['codice_fiscale'] ?? null, $nome);
        $sottoId = null;
        if ($clienteId && !empty($x['sottocliente']['nome'])) {
            $sottoId = AnagraficaMatcher::trovaSottocliente($this->pdo, $p, $clienteId, $x['sottocliente']['nome']);
        }

        $dataOfferta = $this->data($x['data_offerta'] ?? null) ?? date('Y-m-d');
        $righe = [];
        foreach ($x['righe'] ?? [] as $r) {
            $q = (float)$r['quantita'] ?: 1;
            $pu = round((float)$r['prezzo_unitario'], 2);
            $righe[] = [
                'descrizione' => trim((string)$r['descrizione']),
                'quantita' => $q,
                'unita' => $r['unita'] ?? null,
                'prezzo_unitario' => $pu,
                'importo' => $pu != 0.0 ? round($q * $pu, 2) : round((float)$r['importo'], 2),
            ];
        }
        $imponibile = $righe ? round(array_sum(array_column($righe, 'importo')), 2) : round((float)$x['imponibile'], 2);
        $note = [];
        if ($righe && abs($imponibile - (float)$x['imponibile']) > 0.5) {
            $note[] = 'Totale del documento (' . number_format((float)$x['imponibile'], 2, ',', '.') . ' €) diverso dalla somma delle righe: verifica.';
        }
        if (!empty($x['note_estrazione'])) $note[] = $x['note_estrazione'];
        if (!$clienteId && $nome) $note[] = "Cliente \"$nome\" non era in anagrafica: aggiunto, completane i dati.";

        $piano = [];
        foreach ($x['piano_rate'] ?? [] as $r) {
            $piano[] = ['descrizione' => $r['descrizione'], 'percentuale' => round((float)$r['percentuale'], 2), 'giorni_da_accettazione' => $r['giorni_da_accettazione']];
        }
        $piano = $this->pianoSulTotale($piano, $x['piano_rate'] ?? [], $imponibile);
        if ($piano && abs(array_sum(array_column($piano, 'percentuale')) - 100) > 0.01) {
            $note[] = 'Le rate lette non sommano al 100%: sistemale prima di accettare.';
        }

        $fields = [
            'numero' => $this->nuovoNumero((int)substr($dataOfferta, 0, 4)),
            'versione' => 1,
            'cliente_id' => $clienteId,
            'cliente_nome' => $clienteId ? null : ($nome ?: null),
            'sottocliente_id' => $sottoId,
            'data_offerta' => $dataOfferta,
            'data_scadenza' => !empty($x['validita_giorni']) ? date('Y-m-d', strtotime($dataOfferta . ' +' . (int)$x['validita_giorni'] . ' days')) : null,
            'oggetto' => mb_substr(trim((string)$x['oggetto']) ?: 'Offerta', 0, 255),
            'descrizione' => $x['descrizione'] ?? null,
            'tipo_commessa' => in_array($x['tipo_commessa'] ?? '', self::TIPI, true) ? $x['tipo_commessa'] : 'assistenza',
            'num_giornate' => round((float)($x['num_giornate'] ?? 0), 1),
            'imponibile' => $imponibile,
            'iva_percentuale' => $x['iva_percentuale'] !== null ? (float)$x['iva_percentuale'] : 22.0,
            'condizioni_pagamento' => $x['condizioni_pagamento'] ?? null,
            'giorni_pagamento' => $x['giorni_pagamento'] !== null ? max(0, (int)$x['giorni_pagamento']) : 30,
            'piano_rate' => json_encode($piano, JSON_UNESCAPED_UNICODE),
            'stato' => 'bozza',
            'file_path' => $ref,
            'origine' => 'cowork',
            'note' => $note ? implode("\n", $note) : null,
        ];
        $this->pdo->beginTransaction();
        if (!$clienteId && $nome) {
            $fields['cliente_id'] = $this->clienteDaProspect($nome, $x['cliente']['partita_iva'] ?? null,
                $x['cliente']['codice_fiscale'] ?? null, $x['cliente']['email'] ?? null);
            $fields['cliente_nome'] = null;
        }
        $cols = implode(', ', array_keys($fields));
        $ph = implode(', ', array_fill(0, count($fields), '?'));
        $this->pdo->prepare("INSERT INTO {$p}offerte ($cols) VALUES ($ph)")->execute(array_values($fields));
        $id = (int)$this->pdo->lastInsertId();
        $this->salvaRighe($id, $righe);
        $this->pdo->commit();

        Audit::log('INSERT', 'offerte', (string)$id, null, ['origine' => 'cowork', 'imponibile' => $imponibile]);
        Response::json(true, 'Offerta importata in bozza', ['id' => $id, 'avvisi' => $note]);
    }

    public function documento($id) {
        $o = $this->load((int)$id);
        if (!$o) Response::json(false, 'Offerta non trovata', null, 404);
        Documenti::invia($o['file_path'], $o['numero'] . '-v' . $o['versione']);
    }

    // ── Helper ──────────────────────────────────────────

    private function load(int $id): ?array {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->prefix}offerte WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([$id]);
        return $stmt->fetch() ?: null;
    }

    /**
     * Un prospect che non è in anagrafica ci entra con il primo preventivo: si riusa il cliente
     * se il nome (o la P.IVA) lo riconosce, altrimenti si crea con i pochi dati che si hanno.
     */
    private function clienteDaProspect(string $nome, ?string $piva = null, ?string $cf = null, ?string $email = null): int {
        $p = $this->prefix;
        $esistente = AnagraficaMatcher::trovaCliente($this->pdo, $p, $piva, $cf, $nome);
        if ($esistente) return $esistente;
        $dati = ['ragione_sociale' => mb_substr($nome, 0, 255), 'partita_iva' => $piva ?: null,
            'codice_fiscale' => $cf ?: null, 'email' => $email ?: null, 'note' => 'Prospect: aggiunto da un preventivo'];
        $this->pdo->prepare("INSERT INTO {$p}clienti (ragione_sociale, partita_iva, codice_fiscale, email, note) VALUES (?, ?, ?, ?, ?)")
            ->execute(array_values($dati));
        $id = (int)$this->pdo->lastInsertId();
        Audit::log('INSERT', 'clienti', (string)$id, null, ['ragione_sociale' => $dati['ragione_sociale'], 'origine' => 'offerta']);
        return $id;
    }

    private function nuovoNumero(int $anno): string {
        $stmt = $this->pdo->prepare("SELECT MAX(CAST(SUBSTRING(numero, 10) AS UNSIGNED)) FROM {$this->prefix}offerte WHERE numero LIKE ?");
        $stmt->execute(["OFF-$anno-%"]);
        return sprintf('OFF-%d-%03d', $anno, (int)$stmt->fetchColumn() + 1);
    }

    private function data($v): ?string {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y) ? $v : null;
    }

    private function parseRighe($json): array {
        $rows = is_array($json) ? $json : (json_decode((string)$json, true) ?: []);
        $out = [];
        foreach ($rows as $r) {
            $desc = trim((string)($r['descrizione'] ?? ''));
            if ($desc === '') continue;
            $q = (float)($r['quantita'] ?? 1);
            $pu = round((float)($r['prezzo_unitario'] ?? 0), 2);
            $out[] = [
                'descrizione' => $desc,
                'quantita' => $q,
                'unita' => trim((string)($r['unita'] ?? '')) ?: null,
                'prezzo_unitario' => $pu,
                // L'importo segue sempre quantità × prezzo; solo le voci "a corpo" senza prezzo tengono l'importo dato
                'importo' => $pu != 0.0 ? round($q * $pu, 2) : round((float)($r['importo'] ?? 0), 2),
            ];
        }
        return $out;
    }

    private function parsePiano($json): array {
        $rows = is_array($json) ? $json : (json_decode((string)$json, true) ?: []);
        $out = [];
        foreach ($rows as $r) {
            $perc = round((float)($r['percentuale'] ?? 0), 2);
            if ($perc <= 0) continue;
            $g = $r['giorni_da_accettazione'] ?? null;
            $out[] = [
                'descrizione' => trim((string)($r['descrizione'] ?? '')) ?: 'Rata',
                'percentuale' => $perc,
                'giorni_da_accettazione' => ($g === null || $g === '') ? null : max(0, (int)$g),
            ];
        }
        return $out;
    }

    /**
     * Se le percentuali lette non sommano a 100 (tipico dei piani per fase: 40/40/20 + 40/30/30)
     * e ogni rata ha il suo importo, le ricalcola sul totale dell'offerta.
     */
    private function pianoSulTotale(array $piano, array $letti, float $imponibile): array {
        if (!$piano || $imponibile <= 0 || abs(array_sum(array_column($piano, 'percentuale')) - 100) <= 0.01) return $piano;
        $importi = array_map(fn($r) => (float)($r['importo'] ?? 0), $letti);
        if (count($importi) !== count($piano) || min($importi) <= 0) return $piano;
        foreach ($piano as $i => &$r) $r['percentuale'] = round($importi[$i] / $imponibile * 100, 2);
        unset($r);
        // Scarto di arrotondamento sull'ultima rata, solo se gli importi coprono davvero il totale
        $scarto = round(100 - array_sum(array_column($piano, 'percentuale')), 2);
        if ($scarto != 0.0 && abs($scarto) <= 0.05) $piano[count($piano) - 1]['percentuale'] += $scarto;
        return $piano;
    }

    private function salvaRighe(int $offertaId, array $righe): void {
        $this->pdo->prepare("DELETE FROM {$this->prefix}offerte_righe WHERE offerta_id = ?")->execute([$offertaId]);
        $ins = $this->pdo->prepare("INSERT INTO {$this->prefix}offerte_righe
            (offerta_id, ordine, descrizione, quantita, unita, prezzo_unitario, importo) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach ($righe as $i => $r) {
            $ins->execute([$offertaId, $i + 1, $r['descrizione'], $r['quantita'], $r['unita'], $r['prezzo_unitario'], $r['importo']]);
        }
    }
}
