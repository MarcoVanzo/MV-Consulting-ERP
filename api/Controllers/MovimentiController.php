<?php
/**
 * Movimenti Controller — categorie dei movimenti bancari, coda "Da classificare", regole apprese
 * e dati per i grafici "Andamento". Logica in api/Shared/Classificatore.php e CategorieMovimenti.php.
 */

require_once __DIR__ . '/../Shared/ClaudeClient.php';
require_once __DIR__ . '/../Shared/DocumentAi.php';
require_once __DIR__ . '/../Shared/Classificatore.php';
require_once __DIR__ . '/../Shared/CategorieMovimenti.php';
require_once __DIR__ . '/RiconciliazioneController.php';

class MovimentiController {
    private $pdo;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
        if (!Classificatore::tabellePresenti($this->pdo, $this->prefix)) {
            Response::json(false, 'Categorie non ancora attive: lancia la migrazione del database.');
        }
    }

    private function cls(): Classificatore {
        return new Classificatore($this->pdo, $this->prefix);
    }

    private function param(string $k, $default = null) {
        return $_GET[$k] ?? $_POST[$k] ?? $default;
    }

    private function soloAdmin(): void {
        if (($GLOBALS['userContext']['role'] ?? '') !== 'admin') {
            Response::json(false, 'Solo un amministratore può modificare categorie e regole.', null, 403);
        }
    }

    private function userId(): ?int {
        $id = $GLOBALS['userContext']['id'] ?? null;
        return $id ? (int)$id : null;
    }

    public function categorie() {
        Response::json(true, '', [
            'categorie' => $this->cls()->categorie(),
            'da_classificare' => $this->cls()->contaDaClassificare(),
            'ai' => ClaudeClient::isConfigured(),
        ]);
    }

    public function conteggio() {
        Response::json(true, '', ['da_classificare' => $this->cls()->contaDaClassificare()]);
    }

    /** Movimenti con filtri: dal, al, tipo, categoria_id ('nessuna'), classificazione, stato. */
    public function elenco() {
        $class = $this->param('classificazione', '');
        $f = [
            'origine' => 'estratto_conto',
            'dal' => $this->data($this->param('dal')),
            'al' => $this->data($this->param('al')),
            'tipo' => in_array($this->param('tipo'), ['entrata', 'uscita'], true) ? $this->param('tipo') : '',
            'categoria_id' => $this->param('categoria_id', ''),
            'classificazione' => in_array($class, ['da_classificare', 'classificato'], true) ? $class : '',
        ];
        Response::json(true, '', RiconciliazioneController::motore($this->pdo, $this->prefix)->lista($f));
    }

    public function statistiche() {
        $anno = (int)$this->param('anno', date('Y'));
        $dal = $this->data($this->param('dal')) ?? sprintf('%04d-01-01', $anno);
        $al = $this->data($this->param('al')) ?? sprintf('%04d-12-31', $anno);
        if ($al < $dal) Response::json(false, 'Periodo non valido');
        $stat = (new CategorieMovimenti($this->pdo, $this->prefix))->statistiche($dal, $al);
        $stat['dal'] = $dal;
        $stat['al'] = $al;
        $stat['da_classificare'] = $this->cls()->contaDaClassificare();
        Response::json(true, '', $stat);
    }

    public function regole() {
        Response::json(true, '', $this->cls()->regole());
    }

    /** Scelta della categoria dall'utente, con eventuale regola per i movimenti simili. */
    public function classifica($data) {
        $movId = (int)($data['movimento_id'] ?? 0);
        $catId = (int)($data['categoria_id'] ?? 0);
        $si = fn($k) => ($data[$k] ?? '') === '1';
        try {
            $esito = $this->cls()->classificaUtente($movId, $catId, [
                'applica_simili' => $si('applica_simili'),
                'chiave' => (string)($data['chiave'] ?? ''),
                'usa_codice' => $si('usa_codice'),
                'aggiorna_regola' => $si('aggiorna_regola'),
            ], $this->userId(), ($GLOBALS['userContext']['role'] ?? '') === 'admin');
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
        Audit::log('CLASSIFICA', 'movimenti_banca', (string)$movId, null, null, ['categoria_id' => $catId] + $esito);
        $esito['da_classificare'] = $this->cls()->contaDaClassificare();
        Response::json(true, $esito['aggiornati'] ? "Classificato, e altri {$esito['aggiornati']} movimenti simili" : 'Movimento classificato', $esito);
    }

    /** Riesegue la classificazione automatica (fatture, regole, euristiche) sui movimenti da classificare. */
    public function riclassifica() {
        $esito = $this->cls()->classifica(null);
        Response::json(true, 'Classificazione aggiornata', $esito + ['da_classificare' => $this->cls()->contaDaClassificare()]);
    }

    /** Proposte dell'AI per i movimenti da classificare (l'utente conferma). */
    public function proponiAi() {
        if (!ClaudeClient::isConfigured()) Response::json(false, 'Servizio AI non configurato');
        try {
            $n = $this->cls()->proponiConAi(60);
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
        Response::json(true, $n ? "$n proposte dall'AI" : 'Nessuna nuova proposta', ['proposte' => $n]);
    }

    public function salvaCategoria($data) {
        $this->soloAdmin();
        try {
            $id = (new CategorieMovimenti($this->pdo, $this->prefix))->salva($data);
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
        Audit::log(!empty($data['id']) ? 'UPDATE' : 'INSERT', 'categorie_movimento', (string)$id, null, ['nome' => $data['nome'] ?? '']);
        Response::json(true, 'Categoria salvata', ['id' => $id]);
    }

    public function eliminaRegola($data) {
        $this->soloAdmin();
        $id = (int)($data['id'] ?? 0);
        $this->cls()->eliminaRegola($id);
        Audit::log('DELETE', 'regole_categoria', (string)$id, null, null);
        Response::json(true, 'Regola eliminata');
    }

    private function data($v): ?string {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y) ? $v : null;
    }
}
