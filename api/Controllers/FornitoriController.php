<?php
/**
 * Fornitori Controller — partner e fornitori, e i costi che generano sulle commesse
 * (con l'offerta ricevuta dal partner come allegato).
 */

require_once __DIR__ . '/../Shared/Documenti.php';

class FornitoriController {
    private $pdo;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    public function list() {
        $p = $this->prefix;
        $rows = $this->pdo->query("SELECT f.*,
                (SELECT COUNT(DISTINCT c.incarico_id) FROM {$p}commessa_costi c WHERE c.fornitore_id = f.id AND c.incarico_id IS NOT NULL) AS num_commesse,
                (SELECT COALESCE(SUM(fp.importo_totale), 0) FROM {$p}fatture_passive fp WHERE fp.fornitore_id = f.id AND fp.stato = 'da_pagare') AS da_pagare
            FROM {$p}fornitori f WHERE f.deleted_at IS NULL ORDER BY f.ragione_sociale")->fetchAll();
        Response::json(true, '', $rows);
    }

    public function save($data) {
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $fields = [
            'ragione_sociale'  => trim((string)($data['ragione_sociale'] ?? '')),
            'tipo'             => ($data['tipo'] ?? '') === 'fornitore' ? 'fornitore' : 'partner',
            'partita_iva'      => trim((string)($data['partita_iva'] ?? '')) ?: null,
            'codice_fiscale'   => trim((string)($data['codice_fiscale'] ?? '')) ?: null,
            'email'            => trim((string)($data['email'] ?? '')) ?: null,
            'pec'              => trim((string)($data['pec'] ?? '')) ?: null,
            'telefono'         => trim((string)($data['telefono'] ?? '')) ?: null,
            'iban'             => strtoupper(preg_replace('/\s+/', '', (string)($data['iban'] ?? ''))) ?: null,
            'giorni_pagamento' => max(0, (int)($data['giorni_pagamento'] ?? 30)),
            'note'             => trim((string)($data['note'] ?? '')) ?: null,
        ];
        if ($fields['ragione_sociale'] === '') Response::json(false, 'Ragione sociale obbligatoria');

        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $this->pdo->prepare("UPDATE {$this->prefix}fornitori SET $sets WHERE id = ? AND deleted_at IS NULL")
                ->execute(array_merge(array_values($fields), [$id]));
        } else {
            $cols = implode(', ', array_keys($fields));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $this->pdo->prepare("INSERT INTO {$this->prefix}fornitori ($cols) VALUES ($ph)")->execute(array_values($fields));
            $id = (int)$this->pdo->lastInsertId();
        }
        Audit::log(!empty($data['id']) ? 'UPDATE' : 'INSERT', 'fornitori', (string)$id, null, ['ragione_sociale' => $fields['ragione_sociale']]);
        Response::json(true, 'Fornitore salvato', ['id' => $id]);
    }

    public function delete($id) {
        $this->pdo->prepare("UPDATE {$this->prefix}fornitori SET deleted_at = NOW() WHERE id = ?")->execute([(int)$id]);
        Audit::log('DELETE', 'fornitori', (string)$id, null, null);
        Response::json(true, 'Fornitore eliminato');
    }

    // ── Costi di commessa ───────────────────────────────

    /** Costo previsto di un partner su un'offerta (prima dell'accettazione) o su un incarico. */
    public function saveCosto($data) {
        $p = $this->prefix;
        $id = !empty($data['id']) ? (int)$data['id'] : null;
        $current = null;
        if ($id) {
            $stmt = $this->pdo->prepare("SELECT * FROM {$p}commessa_costi WHERE id = ?");
            $stmt->execute([$id]);
            $current = $stmt->fetch();
            if (!$current) Response::json(false, 'Costo non trovato', null, 404);
        }
        $fields = [
            'incarico_id'              => !empty($data['incarico_id']) ? (int)$data['incarico_id'] : null,
            'offerta_id'               => !empty($data['offerta_id']) ? (int)$data['offerta_id'] : null,
            'fornitore_id'             => !empty($data['fornitore_id']) ? (int)$data['fornitore_id'] : null,
            'descrizione'              => trim((string)($data['descrizione'] ?? '')),
            'importo_previsto'         => round((float)($data['importo_previsto'] ?? 0), 2),
            'offerta_fornitore_numero' => trim((string)($data['offerta_fornitore_numero'] ?? '')) ?: null,
            'offerta_fornitore_data'   => preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)($data['offerta_fornitore_data'] ?? '')) ? $data['offerta_fornitore_data'] : null,
            'condizione_pagamento'     => ($data['condizione_pagamento'] ?? '') === 'back_to_back' ? 'back_to_back' : 'scadenza',
            'giorni_pagamento'         => max(0, (int)($data['giorni_pagamento'] ?? 30)),
            'note'                     => trim((string)($data['note'] ?? '')) ?: null,
        ];
        if (!$fields['incarico_id'] && !$fields['offerta_id']) Response::json(false, 'Il costo va collegato a un\'offerta o a un incarico');
        if ($fields['descrizione'] === '') Response::json(false, 'Descrizione obbligatoria');
        if ($fields['offerta_id'] && !$fields['incarico_id']) {
            // Costo inserito dall'offerta già accettata: va direttamente sulla commessa
            $stmt = $this->pdo->prepare("SELECT incarico_id FROM {$p}offerte WHERE id = ?");
            $stmt->execute([$fields['offerta_id']]);
            $fields['incarico_id'] = ((int)$stmt->fetchColumn()) ?: null;
        }

        try {
            $file = Documenti::salvaUpload('file');
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
        if ($file) $fields['offerta_fornitore_file'] = $file;

        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($fields)));
            $this->pdo->prepare("UPDATE {$p}commessa_costi SET $sets WHERE id = ?")->execute(array_merge(array_values($fields), [$id]));
        } else {
            $cols = implode(', ', array_keys($fields));
            $ph = implode(', ', array_fill(0, count($fields), '?'));
            $this->pdo->prepare("INSERT INTO {$p}commessa_costi ($cols) VALUES ($ph)")->execute(array_values($fields));
            $id = (int)$this->pdo->lastInsertId();
        }
        if ($file && $current) $this->eliminaFileSeLibero($current['offerta_fornitore_file']);
        // Le fatture del partner già collegate seguono la commessa del costo
        if ($fields['incarico_id']) {
            $this->pdo->prepare("UPDATE {$p}fatture_passive SET incarico_id = ? WHERE costo_id = ?")->execute([$fields['incarico_id'], $id]);
        }
        Audit::log($current ? 'UPDATE' : 'INSERT', 'commessa_costi', (string)$id, $current ?: null, ['importo_previsto' => $fields['importo_previsto']]);
        Response::json(true, 'Costo salvato', ['id' => $id]);
    }

    public function deleteCosto($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->prefix}commessa_costi WHERE id = ?");
        $stmt->execute([(int)$id]);
        $c = $stmt->fetch();
        if (!$c) Response::json(false, 'Costo non trovato', null, 404);
        $this->pdo->prepare("DELETE FROM {$this->prefix}commessa_costi WHERE id = ?")->execute([(int)$id]);
        $this->eliminaFileSeLibero($c['offerta_fornitore_file']);
        Audit::log('DELETE', 'commessa_costi', (string)$id, $c, null);
        Response::json(true, 'Costo eliminato');
    }

    /** Costi aperti di un fornitore, per collegarci una fattura passiva. */
    public function costiFornitore() {
        $fid = (int)($_POST['fornitore_id'] ?? $_GET['fornitore_id'] ?? 0);
        $stmt = $this->pdo->prepare("SELECT c.id, c.descrizione, c.importo_previsto, c.incarico_id,
                i.descrizione AS incarico_descrizione, cl.ragione_sociale AS cliente_nome
            FROM {$this->prefix}commessa_costi c
            LEFT JOIN {$this->prefix}incarichi i ON i.id = c.incarico_id
            LEFT JOIN {$this->prefix}clienti cl ON cl.id = i.cliente_id
            WHERE c.fornitore_id = ? AND c.incarico_id IS NOT NULL ORDER BY c.id DESC");
        $stmt->execute([$fid]);
        Response::json(true, '', $stmt->fetchAll());
    }

    /** Le revisioni di un'offerta condividono l'allegato del partner: si elimina solo se nessuno lo usa più. */
    private function eliminaFileSeLibero(?string $ref): void {
        if (!$ref) return;
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->prefix}commessa_costi WHERE offerta_fornitore_file = ?");
        $stmt->execute([$ref]);
        if ((int)$stmt->fetchColumn() === 0) Documenti::elimina($ref);
    }

    public function documentoCosto($id) {
        $stmt = $this->pdo->prepare("SELECT offerta_fornitore_file, offerta_fornitore_numero FROM {$this->prefix}commessa_costi WHERE id = ?");
        $stmt->execute([(int)$id]);
        $c = $stmt->fetch();
        if (!$c) Response::json(false, 'Costo non trovato', null, 404);
        Documenti::invia($c['offerta_fornitore_file'], 'offerta-partner-' . ($c['offerta_fornitore_numero'] ?: $id));
    }
}
