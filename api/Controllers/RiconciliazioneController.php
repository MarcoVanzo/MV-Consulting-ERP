<?php
/**
 * Riconciliazione Controller — estratto conto ↔ fatture emesse e fatture dei fornitori.
 * La logica sta in api/Shared/Riconciliatore.php; qui solo input, transazioni, audit e risposta.
 */

require_once __DIR__ . '/../Shared/ClaudeClient.php';
require_once __DIR__ . '/../Shared/DocumentAi.php';
require_once __DIR__ . '/../Shared/EstrattoContoParser.php';
require_once __DIR__ . '/../Shared/EstrattoTabellare.php';
require_once __DIR__ . '/../Shared/Riconciliatore.php';
require_once __DIR__ . '/../Shared/PagamentiFornitore.php';
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
    public static function classifica(PDO $pdo, string $prefix, ?array $ids = null, bool $ancheAutomatiche = false): ?array {
        if (!Classificatore::tabellePresenti($pdo, $prefix)) return null;
        return (new Classificatore($pdo, $prefix))->classifica($ids, $ancheAutomatiche);
    }

    private function userId(): ?int {
        $id = $GLOBALS['userContext']['id'] ?? null;
        return $id ? (int)$id : null;
    }

    /**
     * Import estratto conto: XML CBI/camt (campo xml), tabella CSV (campo csv) o Excel (file originale,
     * formato=xlsx) oppure testo delle pagine del PDF (pages[]).
     * Tabelle: colonne riconosciute dall'intestazione o scelte dall'utente (mappa, JSON), salvate per banca.
     * Con tipo=carta le pagine sono l'estratto della carta di credito: movimenti con origine estratto_carta.
     */
    public function importEstratto($data) {
        $ric = $this->riconciliatore();
        $fileNome = trim((string)($data['file_nome'] ?? ''));
        $carta = ($data['tipo'] ?? '') === 'carta';
        $tabella = in_array($data['formato'] ?? '', ['csv', 'xlsx'], true);
        // Estratto carta caricato come estratto conto: si importa comunque come carta, altrimenti le spese si raddoppiano
        $cartaRiconosciuta = !$carta && !$tabella && trim((string)($data['xml'] ?? '')) === '' && is_array($data['pages'] ?? null)
            && EstrattoContoParser::eEstrattoCarta(implode("\n", array_map('strval', $data['pages'])));
        $carta = $carta || $cartaRiconosciuta;
        if ($carta && !$this->origineCartaPresente()) {
            Response::json(false, 'Import dell\'estratto carta non ancora attivo: lancia la migrazione del database.');
        }
        try {
            if ($tabella) {
                $letto = $this->leggiTabella($data);
            } elseif ($carta) {
                $pages = $data['pages'] ?? [];
                if (empty($pages) || !is_array($pages)) Response::json(false, 'Nessun dato letto dal file');
                $letto = EstrattoContoParser::parseEstrattoCarta($pages);
            } elseif (trim((string)($data['xml'] ?? '')) !== '') {
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
            $esito = $ric->importaMovimenti($letto['movimenti'], ['banca' => $letto['banca'], 'iban' => $letto['iban'], 'file_nome' => $fileNome],
                $this->userId(), $carta ? 'estratto_carta' : 'estratto_conto');
            // Le spese della carta non entrano nelle categorie: sul conto c'è già il loro addebito mensile
            $esito['classificazione'] = $carta ? null : self::classifica($this->pdo, $this->prefix, $esito['ids']);
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
        if ($esito['classificazione'] && ($esito['classificazione']['da_classificare'] ?? 0) > 0 && ClaudeClient::isConfigured()
            && !(class_exists('Anteprima') && Anteprima::attiva())) {
            try {
                $esito['proposte_ai'] = (new Classificatore($this->pdo, $this->prefix))->proponiConAi(40);
            } catch (Throwable $e) {
                $letto['avvisi'][] = 'Proposte AI non disponibili: ' . $e->getMessage();
            }
        }
        unset($esito['ids']);
        // Le spese di trasferta pagate con carta trovano il loro movimento
        if ($carta) {
            require_once __DIR__ . '/../Shared/Spese.php';
            try { $esito['spese_abbinate'] = (new Spese($this->pdo, $this->prefix))->abbinaCarta(); } catch (Throwable $e) { /* tabella spese non ancora migrata */ }
        }
        $esito['metodo'] = $letto['metodo'];
        $esito['banca'] = $letto['banca'];
        $esito['avvisi'] = array_merge($letto['avvisi'], $esito['avvisi'] ?? []);
        if ($cartaRiconosciuta) array_unshift($esito['avvisi'], 'Il file è un estratto della carta di credito: importato come carta, fuori da categorie e grafici.');
        // Colonne scelte dall'utente: valgono anche per i prossimi file con la stessa intestazione
        if ($tabella && !empty($data['mappa'])) {
            $this->pdo->prepare("REPLACE INTO {$this->prefix}settings (setting_key, setting_value) VALUES (?, ?)")
                ->execute(['estratto_mappa_' . $letto['firma'], json_encode($letto['mappa'])]);
        }
        Response::json(true, $carta ? 'Estratto carta importato' : 'Estratto conto importato', $esito);
    }

    /** Estratto CSV/Excel: mappa delle colonne dall'utente, poi quella salvata, poi il riconoscimento automatico. */
    private function leggiTabella(array $data): array {
        if (($data['formato'] ?? '') === 'xlsx') {
            $f = $_FILES['originale'] ?? null;
            if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) Response::json(false, 'File Excel mancante');
            $righe = EstrattoTabellare::daXlsx($f['tmp_name']);
        } else {
            $righe = EstrattoTabellare::daCsv((string)($data['csv'] ?? ''));
        }
        if (!$righe) Response::json(false, 'Il file è vuoto');
        $mappa = json_decode((string)($data['mappa'] ?? ''), true);
        if (!is_array($mappa)) {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM {$this->prefix}settings WHERE setting_key = ?");
            $stmt->execute(['estratto_mappa_' . EstrattoTabellare::firmaDi($righe)]);
            $mappa = json_decode((string)$stmt->fetchColumn(), true);
        }
        $banca = trim((string)($data['banca'] ?? '')) ?: EstrattoContoParser::riconosciBanca(
            ($data['file_nome'] ?? '') . "
" . implode("
", array_map(fn($r) => implode(' ', $r), array_slice($righe, 0, 15))));
        try {
            return EstrattoTabellare::parse($righe, is_array($mappa) ? $mappa : null, $banca);
        } catch (MappaturaRichiesta $e) {
            Response::json(false, $e->getMessage(), ['serve_mappatura' => true, 'intestazione' => $e->intestazione,
                'esempio' => $e->esempio, 'proposta' => $e->proposta]);
        }
    }

    /** La migrazione v070 aggiunge estratto_carta ai valori di movimenti_banca.origine. */
    private function origineCartaPresente(): bool {
        $col = $this->pdo->query("SHOW COLUMNS FROM {$this->prefix}movimenti_banca LIKE 'origine'")->fetch(PDO::FETCH_ASSOC);
        return $col && str_contains((string)$col['Type'], 'estratto_carta');
    }

    /** Riprova l'abbinamento dei movimenti da riconciliare con le fatture presenti ora (dopo un import di fatture). */
    public function riabbina() {
        $ric = $this->riconciliatore();
        @set_time_limit(120);
        $this->pdo->beginTransaction();
        try {
            $esito = $ric->riabbina($this->userId());
            // Poi per fornitore: bonifici riconosciuti per nome che fanno esattamente il totale delle sue fatture
            $gruppi = (new PagamentiFornitore($this->pdo, $this->prefix, $ric))->abbinaSicuri($this->userId());
            $esito['abbinati'] += $gruppi['fornitori'];
            if ($esito['ids']) self::classifica($this->pdo, $this->prefix, $esito['ids'], true);
            $this->pdo->commit();
        } catch (Throwable $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            error_log('[Riconciliazione::riabbina] ' . $e->getMessage());
            Response::json(false, 'Abbinamento non riuscito: ' . $e->getMessage());
        }
        if ($esito['abbinati'] || $esito['nuovi_agganci']) {
            Audit::log('RICONCILIA', 'movimenti_banca', null, null, null, ['riabbina' => true, 'abbinati' => $esito['abbinati'],
                'nuovi_agganci' => $esito['nuovi_agganci']]);
        }
        unset($esito['ids']);
        Response::json(true, $esito['abbinati'] ? "{$esito['abbinati']} movimenti abbinati" : 'Nessun nuovo abbinamento sicuro', $esito);
    }

    /** Fatture da pagare e bonifici aperti messi insieme per fornitore (PagamentiFornitore::riepilogo). */
    public function perFornitore() {
        $ric = $this->riconciliatore();
        Response::json(true, '', (new PagamentiFornitore($this->pdo, $this->prefix, $ric))->riepilogo());
    }

    /** Abbinamento per fornitore confermato: movimenti e fatture = JSON di id, chiudi_differenza = '1'. */
    public function abbinaFornitore($data) {
        $ric = $this->riconciliatore();
        $fornitoreId = (int)($data['fornitore_id'] ?? 0);
        $movimenti = json_decode((string)($data['movimenti'] ?? '[]'), true);
        $fatture = json_decode((string)($data['fatture'] ?? '[]'), true);
        if (!$fornitoreId || !is_array($movimenti) || !is_array($fatture) || !$fatture) Response::json(false, 'Scegli almeno una fattura');
        $this->pdo->beginTransaction();
        try {
            $esito = (new PagamentiFornitore($this->pdo, $this->prefix, $ric))
                ->abbina($fornitoreId, $movimenti, $fatture, ($data['chiudi_differenza'] ?? '') === '1', $this->userId());
            self::classifica($this->pdo, $this->prefix, array_map('intval', $movimenti), true);
            $this->pdo->commit();
        } catch (RuntimeException $e) {
            if ($this->pdo->inTransaction()) $this->pdo->rollBack();
            Response::json(false, $e->getMessage());
        }
        Audit::log('RICONCILIA', 'fatture_passive', null, null, null, ['fornitore_id' => $fornitoreId, 'movimenti' => $movimenti,
            'fatture' => $fatture, 'esito' => $esito]);
        Response::json(true, $esito['fatture_saldate'] . ' fatture saldate', $esito);
    }

    public function movimenti() {
        $stato = $_GET['stato'] ?? $_POST['stato'] ?? '';
        $origine = $_GET['origine'] ?? $_POST['origine'] ?? '';
        $f = [
            'stato' => in_array($stato, ['da_riconciliare', 'riconciliato', 'ignorato'], true) ? $stato : '',
            'origine' => in_array($origine, ['estratto_conto', 'avviso_pagamento', 'estratto_carta'], true) ? $origine : '',
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
                self::classifica($this->pdo, $this->prefix, [$movId], true);
                Audit::log('UPDATE', 'movimenti_banca', (string)$movId, null, null, ['avviso_id' => (int)$data['avviso_id']]);
                Response::json(true, 'Accredito collegato all\'avviso di pagamento');
            }
            $docs = json_decode((string)($data['documenti'] ?? '[]'), true);
            if (!is_array($docs) || !$docs) Response::json(false, 'Seleziona almeno una fattura');
            $saldati = $ric->registra($movId, $docs, 'manuale', $this->userId());
            self::classifica($this->pdo, $this->prefix, [$movId], true);
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
            self::classifica($this->pdo, $this->prefix, $mov['origine'] === 'avviso_pagamento' ? null : [$movId], true);
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
