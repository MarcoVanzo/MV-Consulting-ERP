<?php
/**
 * Riconciliazione Controller — estratto conto ↔ fatture emesse e fatture dei fornitori.
 * La logica sta in api/Shared/Riconciliatore.php; qui solo input, transazioni, audit e risposta.
 */

require_once __DIR__ . '/../Shared/ClaudeClient.php';
require_once __DIR__ . '/../Shared/DocumentAi.php';
require_once __DIR__ . '/../Shared/EstrattoContoParser.php';
require_once __DIR__ . '/../Shared/Riconciliatore.php';
require_once __DIR__ . '/../Shared/Classificatore.php';
require_once __DIR__ . '/../Shared/CommessaService.php';
require_once __DIR__ . '/IncarchiController.php';

class RiconciliazioneController {
    private $pdo;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    /** Motore con il ricalcolo di rata e incarico del record fattura che cambia stato. */
    public static function motore(PDO $pdo, string $prefix): Riconciliatore {
        return new Riconciliatore($pdo, $prefix, function (int $fatturaId) use ($pdo, $prefix) {
            $stmt = $pdo->prepare("SELECT incarico_id FROM {$prefix}fatture WHERE id = ?");
            $stmt->execute([$fatturaId]);
            $incaricoId = (int)$stmt->fetchColumn();
            if (!$incaricoId) return;
            (new CommessaService($pdo, $prefix))->collegaFatturaARata($fatturaId);
            (new IncarchiController())->recalculate($incaricoId);
        });
    }

    private function riconciliatore(): Riconciliatore {
        if (!Riconciliatore::tabellePresenti($this->pdo, $this->prefix)) {
            Response::json(false, 'Riconciliazione non ancora attiva: lancia la migrazione del database.');
        }
        return self::motore($this->pdo, $this->prefix);
    }

    /** Aggiorna le categorie dopo un import o un cambio di riconciliazione (se la migrazione c'è). */
    public static function classifica(PDO $pdo, string $prefix, ?array $ids = null): ?array {
        if (!Classificatore::tabellePresenti($pdo, $prefix)) return null;
        return (new Classificatore($pdo, $prefix))->classifica($ids);
    }

    private function userId(): ?int {
        $id = $GLOBALS['userContext']['id'] ?? null;
        return $id ? (int)$id : null;
    }

    /** Import estratto conto: XML CBI (campo xml) oppure testo delle pagine del PDF (pages[]). */
    public function importEstratto($data) {
        $ric = $this->riconciliatore();
        $fileNome = trim((string)($data['file_nome'] ?? ''));
        try {
            if (trim((string)($data['xml'] ?? '')) !== '') {
                $letto = EstrattoContoParser::parseXmlCbi((string)$data['xml'], trim((string)($data['banca'] ?? '')));
            } else {
                $pages = $data['pages'] ?? [];
                if (empty($pages) || !is_array($pages)) Response::json(false, 'Nessun dato letto dal file');
                $letto = EstrattoContoParser::parse($pages, trim((string)($data['banca'] ?? '')));
                $letto['iban'] = '';
            }
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
        if (!$letto['movimenti']) {
            Response::json(false, 'Nessun movimento riconosciuto nel file', ['avvisi' => $letto['avvisi']]);
        }

        @set_time_limit(120);
        $this->pdo->beginTransaction();
        try {
            $esito = $ric->importaMovimenti($letto['movimenti'], ['banca' => $letto['banca'], 'iban' => $letto['iban'], 'file_nome' => $fileNome], $this->userId());
            $esito['classificazione'] = self::classifica($this->pdo, $this->prefix, $esito['ids']);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[Riconciliazione::importEstratto] ' . $e->getMessage());
            Response::json(false, 'Import annullato: ' . $e->getMessage());
        }
        Audit::log('IMPORT', 'movimenti_banca', null, null, null, [
            'file' => $fileNome, 'metodo' => $letto['metodo'], 'letti' => $esito['letti'],
            'nuovi' => $esito['nuovi'], 'abbinati' => $esito['abbinati'],
        ]);
        // L'AI propone (non decide) una categoria per ciò che le regole non riconoscono
        if ($esito['classificazione'] && ($esito['classificazione']['da_classificare'] ?? 0) > 0 && ClaudeClient::isConfigured()) {
            try {
                $esito['proposte_ai'] = (new Classificatore($this->pdo, $this->prefix))->proponiConAi(40);
            } catch (Throwable $e) {
                $letto['avvisi'][] = 'Proposte AI non disponibili: ' . $e->getMessage();
            }
        }
        unset($esito['ids']);
        $esito['metodo'] = $letto['metodo'];
        $esito['banca'] = $letto['banca'];
        $esito['avvisi'] = $letto['avvisi'];
        Response::json(true, 'Estratto conto importato', $esito);
    }

    public function movimenti() {
        $stato = $_GET['stato'] ?? $_POST['stato'] ?? '';
        $origine = $_GET['origine'] ?? $_POST['origine'] ?? '';
        $f = [
            'stato' => in_array($stato, ['da_riconciliare', 'riconciliato', 'ignorato'], true) ? $stato : '',
            'origine' => in_array($origine, ['estratto_conto', 'avviso_pagamento'], true) ? $origine : '',
            'dal' => $this->data($_GET['dal'] ?? $_POST['dal'] ?? null),
            'al' => $this->data($_GET['al'] ?? $_POST['al'] ?? null),
            'abbinabili' => ($_GET['abbinabili'] ?? $_POST['abbinabili'] ?? '') === '1',
        ];
        Response::json(true, '', $this->riconciliatore()->lista($f));
    }

    public function proposte() {
        $id = (int)($_GET['id'] ?? $_POST['id'] ?? 0);
        try {
            Response::json(true, '', $this->riconciliatore()->proposte($id));
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
    }

    /** Documenti aperti per la scelta manuale: tipo = fattura | fattura_passiva. */
    public function documentiAperti() {
        $tipo = ($_GET['tipo'] ?? $_POST['tipo'] ?? '') === 'fattura_passiva' ? 'fattura_passiva' : 'fattura';
        $docs = $this->riconciliatore()->documenti()->aperti($tipo);
        Response::json(true, '', array_map(fn($d) => RiconciliazioneDocumenti::proposta($d, $d['residuo']), $docs));
    }

    /** Conferma manuale: documenti = JSON [{tipo, id, importo}] oppure avviso_id. */
    public function conferma($data) {
        $ric = $this->riconciliatore();
        $movId = (int)($data['movimento_id'] ?? 0);
        try {
            if (!empty($data['avviso_id'])) {
                $ric->collegaAvviso($movId, (int)$data['avviso_id']);
                self::classifica($this->pdo, $this->prefix, [$movId]);
                Audit::log('UPDATE', 'movimenti_banca', (string)$movId, null, null, ['avviso_id' => (int)$data['avviso_id']]);
                Response::json(true, 'Accredito collegato all\'avviso di pagamento');
            }
            $docs = json_decode((string)($data['documenti'] ?? '[]'), true);
            if (!is_array($docs) || !$docs) Response::json(false, 'Seleziona almeno una fattura');
            $saldati = $ric->registra($movId, $docs, 'manuale', $this->userId());
            self::classifica($this->pdo, $this->prefix, [$movId]);
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
        Audit::log('RICONCILIA', 'movimenti_banca', (string)$movId, null, null, ['documenti' => $docs, 'saldati' => $saldati]);
        Response::json(true, count($saldati) ? count($saldati) . ' fatture saldate' : 'Pagamento registrato', ['saldati' => $saldati]);
    }

    public function annulla($data) {
        $movId = (int)($data['movimento_id'] ?? 0);
        try {
            $mov = $this->riconciliatore()->movimento($movId);
            $riaperte = $this->riconciliatore()->annulla($movId);
            // Annullando un avviso si scollega anche il suo accredito: le categorie di entrambi si ricalcolano
            self::classifica($this->pdo, $this->prefix, $mov['origine'] === 'avviso_pagamento' ? null : [$movId]);
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
        Audit::log('ANNULLA', 'movimenti_banca', (string)$movId, null, null, ['riaperte' => $riaperte]);
        Response::json(true, 'Riconciliazione annullata', ['riaperte' => $riaperte]);
    }

    public function ignora($data) {
        $movId = (int)($data['movimento_id'] ?? 0);
        $ripristina = ($data['ripristina'] ?? '') === '1';
        try {
            $this->riconciliatore()->ignora($movId, $ripristina);
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
        Audit::log('UPDATE', 'movimenti_banca', (string)$movId, null, null, ['stato' => $ripristina ? 'da_riconciliare' : 'ignorato']);
        Response::json(true, $ripristina ? 'Movimento ripristinato' : 'Movimento ignorato');
    }

    private function data($v): ?string {
        if (!is_string($v) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $v)) return null;
        [$y, $m, $d] = array_map('intval', explode('-', $v));
        return checkdate($m, $d, $y) ? $v : null;
    }
}
