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

    /**
     * Collega fatture a commesse: collegamenti = JSON [{fattura_id, incarico_id}]. Fattura e commessa devono essere
     * dello stesso cliente; tutto o niente, totali delle commesse toccate ricalcolati nella stessa transazione.
     */
    public function collegaACommessa($data) {
        $coppie = json_decode((string)($data['collegamenti'] ?? '[]'), true);
        if (!is_array($coppie) || !$coppie) Response::json(false, 'Nessun collegamento da salvare');
        $p = $this->prefix;
        $leggiF = $this->pdo->prepare("SELECT incarico_id, cliente_id, numero_fattura FROM {$p}fatture WHERE id = ?");
        $leggiI = $this->pdo->prepare("SELECT cliente_id FROM {$p}incarichi WHERE id = ?");
        $this->pdo->beginTransaction();
        try {
            $toccate = [];
            foreach ($coppie as $c) {
                $fid = (int)($c['fattura_id'] ?? 0);
                $iid = (int)($c['incarico_id'] ?? 0);
                $leggiF->execute([$fid]);
                $f = $leggiF->fetch(PDO::FETCH_ASSOC);
                $leggiI->execute([$iid]);
                $clienteCommessa = $leggiI->fetchColumn();
                if (!$f || $clienteCommessa === false) throw new InvalidArgumentException('Fattura o commessa non trovata');
                if ((int)$f['cliente_id'] !== (int)$clienteCommessa) {
                    throw new InvalidArgumentException("La fattura {$f['numero_fattura']} è di un altro cliente: non può andare su questa commessa");
                }
                $this->svc->collegaFatturaACommessa($fid, $iid);
                $toccate[$iid] = true;
                if ($f['incarico_id']) $toccate[(int)$f['incarico_id']] = true;
            }
            require_once __DIR__ . '/IncarchiController.php';
            $ic = new IncarchiController();
            foreach (array_keys($toccate) as $iid) $ic->recalculate($iid);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->annulla($e, 'Collegamento non salvato');
        }
        Audit::log('UPDATE', 'fatture', 'collega_commessa', null, ['collegamenti' => count($coppie)]);
        Response::json(true, count($coppie) === 1 ? 'Fattura collegata alla commessa' : count($coppie) . ' fatture collegate alle commesse');
    }

    /** Nuova commessa dalle fatture scelte (singola o ricorrente a canone): vedi CommessaService::creaDaFatture. */
    public function creaDaFatture($data) {
        $ids = json_decode((string)($data['fatture'] ?? '[]'), true);
        if (!is_array($ids)) Response::json(false, 'Elenco delle fatture non valido');
        $this->pdo->beginTransaction();
        try {
            $id = $this->svc->creaDaFatture($ids, [
                'tipo_commessa' => $data['tipo_commessa'] ?? 'altro',
                'descrizione' => $data['descrizione'] ?? '',
                'ricorrente' => !empty($data['ricorrente']) && $data['ricorrente'] !== '0',
                'mesi' => $data['mesi'] ?? 12,
            ]);
            require_once __DIR__ . '/IncarchiController.php';
            (new IncarchiController())->recalculate($id);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->annulla($e, 'Commessa non creata');
        }
        Audit::log('INSERT', 'incarichi', (string)$id, null, null, ['da_fatture' => count($ids)]);
        Response::json(true, 'Commessa creata dalle fatture', ['id' => $id]);
    }

    /**
     * Commesse per le fatture emesse che non ne hanno (tutte, o solo quelle in fatture = JSON di id):
     * vedi CommessaService::creaCommesseMancanti. Lanciato anche dopo l'import, se l'utente lo ha scelto.
     * Un lock impedisce due esecuzioni insieme (due schede, due import): creerebbero le stesse commesse due volte.
     */
    public function creaMancanti($data) {
        $ids = null;
        if (isset($data['fatture'])) {
            $ids = json_decode((string)$data['fatture'], true);
            if (!is_array($ids)) Response::json(false, 'Elenco delle fatture non valido');
        }
        if (!$this->blocca('crea_mancanti')) Response::json(false, 'Le commesse si stanno già creando da un\'altra finestra: riprova tra qualche secondo');
        $this->pdo->beginTransaction();
        try {
            $esito = $this->svc->creaCommesseMancanti($ids);
            require_once __DIR__ . '/IncarchiController.php';
            $ic = new IncarchiController();
            foreach ($esito['commesse'] as $iid) $ic->recalculate($iid);
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->sblocca('crea_mancanti');
            $this->annulla($e, 'Commesse non create');
        }
        $this->sblocca('crea_mancanti');
        $parti = [];
        if ($esito['create']) $parti[] = $esito['create'] . ($esito['create'] === 1 ? ' commessa creata' : ' commesse create')
            . ($esito['ricorrenti'] ? " ({$esito['ricorrenti']} a canone)" : '');
        if ($esito['collegate']) $parti[] = $esito['collegate'] . ($esito['collegate'] === 1 ? ' fattura collegata' : ' fatture collegate') . ' a commesse esistenti';
        if ($esito['da_scegliere']) $parti[] = $esito['da_scegliere'] . ' da confermare a mano';
        if ($esito['create'] || $esito['collegate']) Audit::log('INSERT', 'incarichi', 'crea_mancanti', null, null, $esito);
        $messaggio = $parti ? ucfirst(implode(', ', $parti)) : 'Nessuna fattura da trasformare in commessa';
        Response::json(true, $messaggio, $esito + ['messaggio' => $messaggio]);
    }

    /**
     * Lock con nome (MySQL GET_LOCK, attesa massima 5 secondi). Su SQLite (test) c'è una sola connessione: sempre libero.
     * MySQL lo rilascia comunque alla chiusura della connessione, anche se la richiesta si interrompe.
     */
    private function blocca(string $nome): bool {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return true;
        $stmt = $this->pdo->prepare('SELECT GET_LOCK(?, 5)');
        $stmt->execute([$this->prefix . $nome]);
        return (int)$stmt->fetchColumn() === 1;
    }

    private function sblocca(string $nome): void {
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) !== 'mysql') return;
        $this->pdo->prepare('SELECT RELEASE_LOCK(?)')->execute([$this->prefix . $nome]);
    }

    /** Annulla la transazione e risponde: gli errori di validazione col loro messaggio, gli altri finiscono nel log. */
    private function annulla(Throwable $e, string $cosa): void {
        if ($this->pdo->inTransaction()) $this->pdo->rollBack();
        if ($e instanceof RispostaCatturata) throw $e;
        if ($e instanceof InvalidArgumentException) Response::json(false, $e->getMessage());
        error_log("[Commesse] $cosa: " . $e->getMessage());
        Response::json(false, "$cosa: errore del server, nessuna modifica salvata", null, 500);
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
