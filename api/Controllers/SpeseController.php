<?php
/**
 * SpeseController — note spese: spese di trasferta con giustificativo, lettura degli scontrini,
 * abbinamento alla carta, nota spese del mese (presentata / rimborsata, totali congelati).
 * La logica sta in api/Shared/Spese.php e Indicatori::trasferte (totali del mese).
 */
declare(strict_types=1);

require_once __DIR__ . '/../Shared/Database.php';
require_once __DIR__ . '/../Shared/Response.php';
require_once __DIR__ . '/../Shared/Audit.php';
require_once __DIR__ . '/../Shared/Documenti.php';
require_once __DIR__ . '/../Shared/Spese.php';
require_once __DIR__ . '/../Shared/Indicatori.php';
require_once __DIR__ . '/../Shared/ClaudeClient.php';
require_once __DIR__ . '/../Shared/DocumentAi.php';

class SpeseController
{
    private $pdo;
    private $p;

    public function __construct(?PDO $pdo = null, ?string $prefix = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->p = $prefix ?? (getenv('DB_PREFIX') ?: 'mv_');
    }

    /** Spese del mese, uscite della carta non registrate, totali e stato della nota spese. */
    public function list(array $data): void
    {
        $mese = $this->mese($data['mese'] ?? $_GET['mese'] ?? null);
        [$dal, $al] = [$mese . '-01', date('Y-m-t', strtotime($mese . '-01'))];
        $spese = new Spese($this->pdo, $this->p);
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->p}rimborsi WHERE mese = ?");
        $stmt->execute([$mese]);
        Response::json(true, '', [
            'mese' => $mese,
            'spese' => $spese->elenco($dal, $al),
            'carta_libera' => $spese->movimentiCartaLiberi($dal, $al),
            'totali' => (new Indicatori($this->pdo, $this->p))->trasferte($dal, $al, $this->costoKm()),
            'rimborso' => $stmt->fetch(PDO::FETCH_ASSOC) ?: null,
        ]);
    }

    public function save(array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        $modifica = $id > 0;
        $f = $this->campi($data);
        if (($e = $this->errore($f)) !== null) Response::json(false, $e);
        $this->bloccaSePresentata($f['data'], $id);
        try {
            $doc = Documenti::salvaUpload('file');
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
        if ($doc) $f['documento'] = $doc;
        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
            $this->pdo->prepare("UPDATE {$this->p}spese SET $sets WHERE id = ? AND deleted_at IS NULL")->execute(array_merge(array_values($f), [$id]));
        } else {
            $this->pdo->prepare("INSERT INTO {$this->p}spese (" . implode(', ', array_keys($f)) . ") VALUES (" . implode(', ', array_fill(0, count($f), '?')) . ")")
                ->execute(array_values($f));
            $id = (int)$this->pdo->lastInsertId();
        }
        $abbinate = (new Spese($this->pdo, $this->p))->abbinaCarta();
        Audit::log($modifica ? 'UPDATE' : 'INSERT', 'spese', (string)$id, null, ['importo' => $f['importo'], 'categoria' => $f['categoria']]);
        Response::json(true, 'Spesa salvata' . ($abbinate ? ': abbinata al movimento della carta' : ''), ['id' => $id]);
    }

    public function delete($id): void
    {
        $stmt = $this->pdo->prepare("SELECT data FROM {$this->p}spese WHERE id = ? AND deleted_at IS NULL");
        $stmt->execute([(int)$id]);
        $d = $stmt->fetchColumn();
        if (!$d) Response::json(false, 'Spesa non trovata', null, 404);
        $this->bloccaSePresentata((string)$d, (int)$id);
        // Il movimento della carta torna libero (indice unico su movimento_id)
        $this->pdo->prepare("UPDATE {$this->p}spese SET deleted_at = NOW(), movimento_id = NULL WHERE id = ?")->execute([(int)$id]);
        Audit::log('DELETE', 'spese', (string)(int)$id, null, null);
        Response::json(true, 'Spesa eliminata');
    }

    public function documento($id): void
    {
        $stmt = $this->pdo->prepare("SELECT documento, data FROM {$this->p}spese WHERE id = ?");
        $stmt->execute([(int)$id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        Documenti::invia($r['documento'] ?? null, 'giustificativo_' . ($r['data'] ?? ''));
    }

    /** Un'uscita della carta diventa spesa di trasferta. */
    public function daMovimento(array $data): void
    {
        try {
            $id = (new Spese($this->pdo, $this->p))->daMovimento((int)($data['movimento_id'] ?? 0), (string)($data['categoria'] ?? 'altro'),
                !empty($data['cliente_id']) ? (int)$data['cliente_id'] : null);
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
        Response::json(true, 'Spesa registrata dalla carta', ['id' => $id]);
    }

    /**
     * Scontrino o ricevuta (foto o PDF, campo file): Claude legge data, importo, esercente, categoria e
     * metodo; la spesa nasce con il file come giustificativo. Passa da «Importa file» (anteprima inclusa).
     */
    public function importaScontrino(array $data): void
    {
        if (!ClaudeClient::isConfigured()) {
            Response::json(false, 'Lettura automatica non attiva (manca ANTHROPIC_API_KEY): inserisci la spesa a mano con il giustificativo');
        }
        try {
            $ref = Documenti::salvaUpload('file');
            if (!$ref) Response::json(false, 'Nessun file caricato');
            $path = Documenti::percorso($ref);
            $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
            $bin = base64_encode((string)file_get_contents($path));
            $doc = $ext === 'pdf' ? ['pdf_base64' => $bin]
                : ['image_base64' => $bin, 'media_type' => ['jpg' => 'image/jpeg', 'jpeg' => 'image/jpeg', 'png' => 'image/png', 'webp' => 'image/webp'][$ext] ?? 'image/jpeg'];
            $x = DocumentAi::estraiScontrino($doc);
        } catch (RuntimeException $e) {
            if (!empty($ref)) Documenti::elimina($ref);
            Response::json(false, $e->getMessage());
        }
        $avvisi = array_values(array_filter([$x['note_estrazione'] ?? null]));
        if (empty($x['importo']) || empty($x['data'])) {
            Documenti::elimina($ref);
            Response::json(false, 'Importo o data non leggibili: inserisci la spesa a mano', ['avvisi' => $avvisi]);
        }
        $f = $this->campi([
            'data' => $x['data'], 'categoria' => $x['categoria'] ?? 'altro', 'importo' => $x['importo'],
            'metodo' => ($x['metodo'] ?? 'altro'), 'esercente' => $x['esercente'] ?? '', 'descrizione' => $x['descrizione'] ?? '',
            'cliente_id' => $data['cliente_id'] ?? null,
        ]);
        $f['documento'] = $ref;
        $f['origine'] = 'scontrino';
        $this->bloccaSePresentata($f['data'], 0);
        $this->pdo->prepare("INSERT INTO {$this->p}spese (" . implode(', ', array_keys($f)) . ") VALUES (" . implode(', ', array_fill(0, count($f), '?')) . ")")
            ->execute(array_values($f));
        $id = (int)$this->pdo->lastInsertId();
        $abbinata = (new Spese($this->pdo, $this->p))->abbinaCarta() > 0;
        if ($f['metodo'] === 'contanti' && in_array($f['categoria'], Spese::DA_TRACCIARE, true)) {
            $avvisi[] = 'Pagata in contanti: per vitto, alloggio e viaggio non è deducibile (serve un pagamento tracciabile).';
        }
        Response::json(true, 'Spesa registrata dallo scontrino', ['id' => $id, 'spesa' => $f + ['abbinata_carta' => $abbinata], 'avvisi' => $avvisi]);
    }

    /** Nota spese del mese: presenta (congela i totali), rimborsata (con data), riapri. */
    public function rimborso(array $data): void
    {
        $mese = $this->mese($data['mese'] ?? null);
        $azione = (string)($data['azione'] ?? '');
        $oggi = date('Y-m-d');
        if ($azione === 'presenta') {
            $t = (new Indicatori($this->pdo, $this->p))->trasferte($mese . '-01', date('Y-m-t', strtotime($mese . '-01')), $this->costoKm());
            $this->pdo->prepare("DELETE FROM {$this->p}rimborsi WHERE mese = ?")->execute([$mese]);
            $this->pdo->prepare("INSERT INTO {$this->p}rimborsi (mese, stato, km, importo_km, indennita, spese, totale, data_presentazione)
                VALUES (?, 'presentata', ?, ?, ?, ?, ?, ?)")
                ->execute([$mese, $t['km'], $t['rimborso_km'] ?? 0, $t['indennita'], $t['spese'], $t['da_rimborsare'], $oggi]);
        } elseif ($azione === 'rimborsata') {
            $d = (string)($data['data'] ?? '');
            $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) ? $d : $oggi;
            $stmt = $this->pdo->prepare("UPDATE {$this->p}rimborsi SET stato = 'rimborsata', data_rimborso = ? WHERE mese = ?");
            $stmt->execute([$d, $mese]);
            if (!$stmt->rowCount()) Response::json(false, 'Prima presenta la nota spese del mese');
        } elseif ($azione === 'riapri') {
            $this->pdo->prepare("DELETE FROM {$this->p}rimborsi WHERE mese = ?")->execute([$mese]);
        } else {
            Response::json(false, 'Azione non valida');
        }
        Audit::log('UPDATE', 'rimborsi', $mese, null, ['azione' => $azione]);
        Response::json(true, ['presenta' => 'Nota spese presentata', 'rimborsata' => 'Rimborso registrato', 'riapri' => 'Nota spese riaperta'][$azione]);
    }

    private function campi(array $d): array
    {
        return [
            'data' => (string)($d['data'] ?? ''),
            'categoria' => in_array($d['categoria'] ?? '', Spese::CATEGORIE, true) ? $d['categoria'] : 'altro',
            'descrizione' => mb_substr(trim((string)($d['descrizione'] ?? '')), 0, 255) ?: null,
            'esercente' => mb_substr(trim((string)($d['esercente'] ?? '')), 0, 150) ?: null,
            'importo' => round((float)str_replace(',', '.', (string)($d['importo'] ?? 0)), 2),
            'metodo' => in_array($d['metodo'] ?? '', Spese::METODI, true) ? $d['metodo'] : 'carta',
            'cliente_id' => !empty($d['cliente_id']) ? (int)$d['cliente_id'] : null,
        ];
    }

    private function errore(array $f): ?string
    {
        $d = $f['data'];
        if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) || !checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4))) return 'Data non valida';
        if ($f['importo'] <= 0 || $f['importo'] > 100000) return 'Importo non valido';
        return null;
    }

    /** Una nota spese già presentata non cambia sotto i piedi: prima si riapre. */
    private function bloccaSePresentata(string $data, int $id): void
    {
        $mesi = [substr($data, 0, 7)];
        if ($id) {
            $stmt = $this->pdo->prepare("SELECT data FROM {$this->p}spese WHERE id = ?");
            $stmt->execute([$id]);
            if ($vecchia = $stmt->fetchColumn()) $mesi[] = substr((string)$vecchia, 0, 7);
        }
        $stmt = $this->pdo->prepare("SELECT mese FROM {$this->p}rimborsi WHERE mese IN (" . implode(',', array_fill(0, count($mesi), '?')) . ")");
        $stmt->execute($mesi);
        if ($m = $stmt->fetchColumn()) Response::json(false, 'La nota spese di ' . substr((string)$m, 5, 2) . '/' . substr((string)$m, 0, 4) . ' è già presentata: riaprila per cambiare le spese');
    }

    private function mese($v): string
    {
        return is_string($v) && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $v) ? $v : date('Y-m');
    }

    private function costoKm(): ?float
    {
        $stmt = $this->pdo->prepare("SELECT setting_value FROM {$this->p}settings WHERE setting_key = 'trasferte_costo_km'");
        $stmt->execute();
        $v = $stmt->fetchColumn();
        return $v === false || $v === null || $v === '' ? null : (float)$v;
    }
}
