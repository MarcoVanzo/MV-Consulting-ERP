<?php
/**
 * ImportaController — porta d'ingresso unica delle fatture elettroniche (XML o .p7m) e delle fatture in PDF
 * (lette da FatturaPdf e trasformate in una FatturaPA minima, poi trattate come un XML).
 *
 * Decide da solo se la fattura è emessa (attiva) o ricevuta (passiva) e la passa all'import
 * giusto: emessa se il cedente è MV Consulting (AZIENDA_PARTITA_IVA), altrimenti ricevuta.
 * Senza AZIENDA_PARTITA_IVA prova con le anagrafiche; se resta il dubbio chiede all'utente
 * (campo verso = attiva|passiva).
 */
declare(strict_types=1);

require_once __DIR__ . '/../Shared/Database.php';
require_once __DIR__ . '/../Shared/Response.php';
require_once __DIR__ . '/../Shared/P7m.php';
require_once __DIR__ . '/../Shared/AnagraficaMatcher.php';
require_once __DIR__ . '/../Shared/FatturaPdf.php';
require_once __DIR__ . '/../Shared/Avvisi.php';

class ImportaController
{
    private $pdo;
    private $prefix;

    public function __construct()
    {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    public function fattura(array $data): void
    {
        $xml = (string)($data['xml'] ?? '');
        if (trim($xml) === '' && !empty($data['file_b64'])) {
            $raw = base64_decode((string)$data['file_b64'], true);
            $xml = $raw === false ? '' : P7m::xmlDaFile($raw);
            if ($xml === '') Response::json(false, 'File firmato (p7m) non leggibile');
        }
        if (trim($xml) === '') Response::json(false, 'Nessun contenuto nel file');

        $verso = in_array($data['verso'] ?? '', ['attiva', 'passiva'], true)
            ? $data['verso'] : self::verso($xml, $this->pdo, $this->prefix, (string)getenv('AZIENDA_PARTITA_IVA'));
        if ($verso === null) {
            Response::json(false, 'Non riesco a capire se la fattura è emessa o ricevuta: scegli tu il tipo (e imposta AZIENDA_PARTITA_IVA nel .env)',
                ['serve_verso' => true]);
        }

        $this->inoltra($xml, $verso, (string)($data['file_nome'] ?? ''));
    }

    /**
     * Fattura in PDF, emessa o ricevuta (campo pages: testo delle pagine con gli a capo). Letta a regole:
     * quello che si è capito finisce negli avvisi dell'anteprima, da controllare prima di importare.
     */
    public function fatturaPdf(array $data): void
    {
        $pagine = $data['pages'] ?? [];
        if (!is_array($pagine) || !array_filter($pagine, fn($p) => trim((string)$p) !== '')) {
            Response::json(false, 'Nessun testo nel PDF (è una scansione? serve l\'XML della fattura)');
        }
        $f = FatturaPdf::leggi(implode("\n", array_map('strval', $pagine)), (string)getenv('AZIENDA_PARTITA_IVA'));
        if (!$f['numero'] || !$f['data'] || !$f['imponibile']) {
            Response::json(false, 'Dal PDF non riesco a leggere numero, data e importi: importa l\'XML della fattura');
        }
        $c = $f['controparte'];
        $verso = in_array($data['verso'] ?? '', ['attiva', 'passiva'], true) ? $data['verso'] : $f['verso'];
        if ($verso === null && $c['piva'] !== '') {
            // Chi conosciamo già in anagrafica?
            $tipi = array_unique(array_map(fn($a) => $a['tipo'] === 'cliente' ? 'attiva' : 'passiva',
                AnagraficaMatcher::conPartitaIva($this->pdo, $this->prefix, $c['piva'])));
            if (count($tipi) === 1) $verso = reset($tipi);
        }
        if ($verso === null) {
            Response::json(false, 'Non riesco a capire se la fattura è emessa o ricevuta: scegli tu', ['serve_verso' => true]);
        }

        $euro = fn(float $v) => number_format($v, 2, ',', '.');
        Avvisi::aggiungi(sprintf('Letto dal PDF: fattura %s %s n. %s del %s, imponibile %s €, IVA %s €%s%s.',
            $verso === 'passiva' ? 'ricevuta da' : 'emessa a', $c['nome'] ?: ($c['piva'] ?: '?'), $f['numero'],
            date('d/m/Y', strtotime($f['data'])), $euro($f['imponibile']), $euro($f['iva']),
            $f['ritenuta'] > 0 ? ', ritenuta ' . $euro($f['ritenuta']) . ' €' : '',
            $f['scadenza'] ? ', scadenza ' . date('d/m/Y', strtotime($f['scadenza'])) : ', scadenza non indicata'));
        foreach ($f['avvisi'] as $a) Avvisi::aggiungi("PDF: $a");

        $this->inoltra(FatturaPdf::xml($f, $verso), $verso, (string)($data['file_nome'] ?? ''));
    }

    private function inoltra(string $xml, string $verso, string $nomeFile): void
    {
        $inoltro = ['xml' => $xml, 'file_nome' => $nomeFile];
        if ($verso === 'attiva') {
            require_once __DIR__ . '/ContabilitaController.php';
            (new ContabilitaController())->importXmlData($inoltro);
        } else {
            require_once __DIR__ . '/FatturePassiveController.php';
            (new FatturePassiveController())->importXml($inoltro);
        }
    }

    /**
     * Elenco di fatture in Excel (campo file): Lista Fatture di Sistemi o elenco del portale, emesse o ricevute.
     * Il verso lo dice l'intestazione (colonna Fornitore o Cliente); $data['verso'] vale solo se l'intestazione tace.
     */
    public function lista(array $data): void
    {
        require_once __DIR__ . '/../Shared/ListaFattureParser.php';
        $f = $_FILES['file'] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) Response::json(false, 'Caricamento del file non riuscito');
        if (strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION)) !== 'xlsx') {
            Response::json(false, 'Serve il file Excel .xlsx (il vecchio .xls va risalvato come .xlsx)');
        }
        try {
            $letto = ListaFattureParser::fatture(ListaFattureParser::leggiXlsx($f['tmp_name']));
        } catch (RuntimeException $e) {
            Response::json(false, $e->getMessage());
        }
        if (!$letto['fatture']) Response::json(false, 'Nessuna fattura nel file', ['errors' => $letto['avvisi']]);

        $scelto = in_array($data['verso'] ?? '', ['attiva', 'passiva'], true) ? $data['verso'] : null;
        $verso = $letto['verso'] ?? $scelto;
        if ($verso === null) Response::json(false, 'Non capisco se sono fatture emesse o ricevute: scegli tu il tipo', ['serve_verso' => true]);
        if ($scelto && $scelto !== $verso) {
            $letto['avvisi'][] = 'Il file ha la colonna ' . ($verso === 'passiva' ? 'Fornitore' : 'Cliente')
                . ': importato come elenco di fatture ' . ($verso === 'passiva' ? 'ricevute' : 'emesse') . '.';
        }
        $nome = (string)($data['file_nome'] ?? $f['name']);
        if ($verso === 'attiva') {
            require_once __DIR__ . '/ContabilitaController.php';
            (new ContabilitaController())->importLista($letto, $nome);
        } else {
            require_once __DIR__ . '/FatturePassiveController.php';
            (new FatturePassiveController())->importLista($letto, $nome);
        }
    }

    /** Una anagrafica creata da un elenco: cerca sul web la sua partita IVA (una per richiesta, dura decine di secondi). */
    public function cercaPiva(array $data): void
    {
        require_once __DIR__ . '/../Shared/AnagraficaAuto.php';
        require_once __DIR__ . '/../Shared/ClaudeClient.php';
        if (!ClaudeClient::isConfigured()) Response::json(false, 'Ricerca della partita IVA non disponibile: manca ANTHROPIC_API_KEY');
        try {
            $r = AnagraficaAuto::cerca($this->pdo, $this->prefix, (string)($data['tipo'] ?? ''), (int)($data['id'] ?? 0));
        } catch (Throwable $e) {
            error_log('[Importa::cercaPiva] ' . $e->getMessage());
            Response::json(false, $e->getMessage());
        }
        Response::json(true, $r['messaggio'], $r);
    }

    /**
     * attiva | passiva | null (non si sa).
     * Legge le partite IVA di cedente e cessionario senza dipendere dai namespace.
     */
    public static function verso(string $xml, PDO $pdo, string $prefix, string $nostraPiva): ?string
    {
        $cedente = self::pivaDi($xml, 'CedentePrestatore');
        $cessionario = self::pivaDi($xml, 'CessionarioCommittente');
        if ($cedente === '' && $cessionario === '') return null;
        $nostra = AnagraficaMatcher::normalizzaCodice($nostraPiva);
        if ($nostra !== '') {
            if ($cedente === $nostra) return 'attiva';
            if ($cessionario === $nostra) return 'passiva';
        }
        // Senza la nostra P.IVA: chi conosciamo già in anagrafica?
        $clienti = $cessionario !== '' ? AnagraficaMatcher::conPartitaIva($pdo, $prefix, $cessionario) : [];
        $fornitori = $cedente !== '' ? AnagraficaMatcher::conPartitaIva($pdo, $prefix, $cedente) : [];
        $eCliente = (bool)array_filter($clienti, fn($a) => $a['tipo'] === 'cliente');
        $eFornitore = (bool)array_filter($fornitori, fn($a) => $a['tipo'] !== 'cliente');
        if ($eCliente && !$eFornitore) return 'attiva';
        if ($eFornitore && !$eCliente) return 'passiva';
        return null;
    }

    private static function pivaDi(string $xml, string $blocco): string
    {
        if (!preg_match('/<(?:\w+:)?' . $blocco . '\b.*?<\/(?:\w+:)?' . $blocco . '>/s', $xml, $m)) return '';
        if (!preg_match('/<(?:\w+:)?IdCodice>\s*([^<]+?)\s*</', $m[0], $c)) return '';
        return AnagraficaMatcher::normalizzaCodice($c[1]);
    }
}
