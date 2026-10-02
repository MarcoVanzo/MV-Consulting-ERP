<?php
/**
 * Commesse Controller — scheda della commessa (incarico): piano di fatturazione,
 * abbinamento rate ↔ fatture, costi dei partner, margini.
 */

require_once __DIR__ . '/../Shared/CommessaService.php';
require_once __DIR__ . '/../Shared/Scadenzario.php';

class CommesseController {
    private $pdo;
    private $prefix;
    private $svc;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
        $this->svc = new CommessaService($this->pdo, $this->prefix);
    }

    public function get($id) {
        $r = $this->svc->riepilogo((int)$id);
        if (!$r) Response::json(false, 'Incarico non trovato', null, 404);
        Response::json(true, '', $r);
    }

    /**
     * Salva il piano rate completo. Le rate già fatturate non si eliminano;
     * la somma deve tornare al valore dell'incarico.
     */
    public function saveRate($data) {
        $p = $this->prefix;
        $incaricoId = (int)($data['incarico_id'] ?? 0);
        $stmt = $this->pdo->prepare("SELECT importo_totale, giorni_pagamento FROM {$p}incarichi WHERE id = ?");
        $stmt->execute([$incaricoId]);
        $inc = $stmt->fetch();
        if (!$inc) Response::json(false, 'Incarico non trovato', null, 404);

        $rate = json_decode((string)($data['rate'] ?? '[]'), true);
        if (!is_array($rate)) Response::json(false, 'Piano rate non valido');
        $totale = 0.0;
        foreach ($rate as $r) $totale += round((float)($r['importo'] ?? 0), 2);
        if (abs($totale - (float)$inc['importo_totale']) > 0.01) {
            Response::json(false, 'Le rate sommano ' . number_format($totale, 2, ',', '.') . ' € ma l\'incarico vale '
                . number_format((float)$inc['importo_totale'], 2, ',', '.') . ' €');
        }

        $stmt = $this->pdo->prepare("SELECT id, fattura_id FROM {$p}incarichi_rate WHERE incarico_id = ?");
        $stmt->execute([$incaricoId]);
        $esistenti = [];
        foreach ($stmt->fetchAll() as $e) $esistenti[(int)$e['id']] = $e['fattura_id'];

        $this->pdo->beginTransaction();
        $tenute = [];
        $upd = $this->pdo->prepare("UPDATE {$p}incarichi_rate SET ordine = ?, descrizione = ?, percentuale = ?, importo = ?, data_prevista = ?, giorni_pagamento = ? WHERE id = ? AND incarico_id = ?");
        $ins = $this->pdo->prepare("INSERT INTO {$p}incarichi_rate (incarico_id, ordine, descrizione, percentuale, importo, data_prevista, giorni_pagamento) VALUES (?, ?, ?, ?, ?, ?, ?)");
        foreach (array_values($rate) as $i => $r) {
            $importo = round((float)($r['importo'] ?? 0), 2);
            $perc = (float)$inc['importo_totale'] > 0 ? round($importo / (float)$inc['importo_totale'] * 100, 2) : null;
            $dataPrev = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($r['data_prevista'] ?? '')) ? $r['data_prevista'] : null;
            $giorni = isset($r['giorni_pagamento']) && $r['giorni_pagamento'] !== '' ? max(0, (int)$r['giorni_pagamento']) : (int)$inc['giorni_pagamento'];
            $desc = trim((string)($r['descrizione'] ?? '')) ?: 'Rata ' . ($i + 1);
            $rid = (int)($r['id'] ?? 0);
            if ($rid && array_key_exists($rid, $esistenti)) {
                $upd->execute([$i + 1, $desc, $perc, $importo, $dataPrev, $giorni, $rid, $incaricoId]);
                $tenute[] = $rid;
            } else {
                $ins->execute([$incaricoId, $i + 1, $desc, $perc, $importo, $dataPrev, $giorni]);
            }
        }
        foreach ($esistenti as $rid => $fatturaId) {
            if (in_array($rid, $tenute, true)) continue;
            if ($fatturaId) {
                $this->pdo->rollBack();
                Response::json(false, 'Non puoi eliminare una rata già fatturata: scollega prima la fattura');
            }
            $this->pdo->prepare("DELETE FROM {$p}incarichi_rate WHERE id = ?")->execute([$rid]);
        }
        $this->pdo->commit();
        Audit::log('UPDATE', 'incarichi_rate', (string)$incaricoId, null, ['rate' => count($rate)]);
        Response::json(true, 'Piano di fatturazione salvato');
    }

    /** Collega a mano una fattura emessa a una rata (fattura_id vuoto = scollega). */
    public function collegaFattura($data) {
        $p = $this->prefix;
        $rataId = (int)($data['rata_id'] ?? 0);
        $fatturaId = !empty($data['fattura_id']) ? (int)$data['fattura_id'] : null;
        $stmt = $this->pdo->prepare("SELECT incarico_id FROM {$p}incarichi_rate WHERE id = ?");
        $stmt->execute([$rataId]);
        $incaricoId = (int)$stmt->fetchColumn();
        if (!$incaricoId) Response::json(false, 'Rata non trovata', null, 404);

        if ($fatturaId) {
            $stmt = $this->pdo->prepare("SELECT incarico_id FROM {$p}fatture WHERE id = ?");
            $stmt->execute([$fatturaId]);
            $fattInc = $stmt->fetchColumn();
            if ($fattInc === false) Response::json(false, 'Fattura non trovata', null, 404);
            $this->pdo->beginTransaction();
            // Una fattura copre una sola rata; se era su un'altra, si sposta
            $this->pdo->prepare("UPDATE {$p}incarichi_rate SET fattura_id = NULL WHERE fattura_id = ?")->execute([$fatturaId]);
            $this->pdo->prepare("UPDATE {$p}incarichi_rate SET fattura_id = ? WHERE id = ?")->execute([$fatturaId, $rataId]);
            if ((int)$fattInc !== $incaricoId) {
                $this->pdo->prepare("UPDATE {$p}fatture SET incarico_id = ? WHERE id = ?")->execute([$incaricoId, $fatturaId]);
            }
            $this->pdo->commit();
            require_once __DIR__ . '/IncarchiController.php';
            $ic = new IncarchiController();
            $ic->recalculate($incaricoId);
            if ($fattInc && (int)$fattInc !== $incaricoId) $ic->recalculate((int)$fattInc);
        } else {
            $this->pdo->prepare("UPDATE {$p}incarichi_rate SET fattura_id = NULL WHERE id = ?")->execute([$rataId]);
        }
        Audit::log('UPDATE', 'incarichi_rate', (string)$rataId, null, ['fattura_id' => $fatturaId]);
        Response::json(true, $fatturaId ? 'Fattura collegata alla rata' : 'Fattura scollegata');
    }

    /** Fatture del cliente dell'incarico non ancora abbinate a una rata. */
    public function fattureLibere() {
        $p = $this->prefix;
        $incaricoId = (int)($_POST['incarico_id'] ?? $_GET['incarico_id'] ?? 0);
        $stmt = $this->pdo->prepare("SELECT f.id, f.numero_fattura, f.data_emissione, f.imponibile, f.stato, f.incarico_id
            FROM {$p}fatture f
            JOIN {$p}incarichi i ON i.id = ? AND i.cliente_id = f.cliente_id
            WHERE f.imponibile > 0 AND NOT EXISTS (SELECT 1 FROM {$p}incarichi_rate r WHERE r.fattura_id = f.id)
            ORDER BY (f.incarico_id = i.id) DESC, f.data_emissione DESC LIMIT 50");
        $stmt->execute([$incaricoId]);
        Response::json(true, '', $stmt->fetchAll());
    }

    /** Fatture dell'anno senza commessa, con la commessa proposta (CommessaService::proposteCollegamento). */
    public function daCollegare() {
        $year = (int)($_POST['year'] ?? $_GET['year'] ?? date('Y'));
        Response::json(true, '', $this->svc->proposteCollegamento($year));
    }

    /** Collega fatture a commesse: collegamenti = JSON [{fattura_id, incarico_id}]. */
    public function collegaACommessa($data) {
        $coppie = json_decode((string)($data['collegamenti'] ?? '[]'), true);
        if (!is_array($coppie) || !$coppie) Response::json(false, 'Nessun collegamento da salvare');
        $p = $this->prefix;
        $esisteF = $this->pdo->prepare("SELECT incarico_id FROM {$p}fatture WHERE id = ?");
        $esisteI = $this->pdo->prepare("SELECT 1 FROM {$p}incarichi WHERE id = ?");
        $toccate = [];
        $this->pdo->beginTransaction();
        foreach ($coppie as $c) {
            $fid = (int)($c['fattura_id'] ?? 0);
            $iid = (int)($c['incarico_id'] ?? 0);
            $esisteF->execute([$fid]);
            $prima = $esisteF->fetchColumn();
            $esisteI->execute([$iid]);
            if ($prima === false || !$esisteI->fetchColumn()) {
                $this->pdo->rollBack();
                Response::json(false, 'Fattura o commessa non trovata', null, 404);
            }
            $this->svc->collegaFatturaACommessa($fid, $iid);
            $toccate[$iid] = true;
            if ($prima) $toccate[(int)$prima] = true;
        }
        $this->pdo->commit();
        require_once __DIR__ . '/IncarchiController.php';
        $ic = new IncarchiController();
        foreach (array_keys($toccate) as $iid) $ic->recalculate($iid);
        Audit::log('UPDATE', 'fatture', 'collega_commessa', null, ['collegamenti' => count($coppie)]);
        Response::json(true, count($coppie) === 1 ? 'Fattura collegata alla commessa' : count($coppie) . ' fatture collegate alle commesse');
    }

    /** Nuova commessa dalle fatture scelte (singola o ricorrente a canone): vedi CommessaService::creaDaFatture. */
    public function creaDaFatture($data) {
        $ids = json_decode((string)($data['fatture'] ?? '[]'), true);
        $this->pdo->beginTransaction();
        try {
            $id = $this->svc->creaDaFatture(is_array($ids) ? $ids : [], [
                'tipo_commessa' => $data['tipo_commessa'] ?? 'altro',
                'descrizione' => $data['descrizione'] ?? '',
                'ricorrente' => !empty($data['ricorrente']) && $data['ricorrente'] !== '0',
                'mesi' => $data['mesi'] ?? 12,
            ]);
            $this->pdo->commit();
        } catch (InvalidArgumentException $e) {
            $this->pdo->rollBack();
            Response::json(false, $e->getMessage());
        }
        require_once __DIR__ . '/IncarchiController.php';
        (new IncarchiController())->recalculate($id);
        Audit::log('INSERT', 'incarichi', (string)$id, null, null, ['da_fatture' => count($ids)]);
        Response::json(true, 'Commessa creata dalle fatture', ['id' => $id]);
    }

    /** Incasso di una fattura emessa, dallo scadenzario (data vuota = oggi). */
    public function segnaIncassata($data) {
        $id = (int)($data['fattura_id'] ?? 0);
        $dataPag = preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($data['data_pagamento'] ?? '')) ? $data['data_pagamento'] : date('Y-m-d');
        $stmt = $this->pdo->prepare("SELECT incarico_id FROM {$this->prefix}fatture WHERE id = ?");
        $stmt->execute([$id]);
        $incaricoId = $stmt->fetchColumn();
        if ($incaricoId === false) Response::json(false, 'Fattura non trovata', null, 404);
        $this->pdo->prepare("UPDATE {$this->prefix}fatture SET stato = 'pagata', data_pagamento = ? WHERE id = ?")->execute([$dataPag, $id]);
        if ($incaricoId) {
            require_once __DIR__ . '/IncarchiController.php';
            (new IncarchiController())->recalculate((int)$incaricoId);
        }
        Audit::log('UPDATE', 'fatture', (string)$id, null, ['stato' => 'pagata', 'data_pagamento' => $dataPag]);
        Response::json(true, 'Incasso registrato');
    }

    public function scadenzario() {
        $giorni = max(1, min(60, (int)($_POST['giorni'] ?? $_GET['giorni'] ?? 7)));
        Response::json(true, '', (new Scadenzario($this->pdo, $this->prefix))->calcola($giorni));
    }
}
