<?php
/**
 * ImportaController — porta d'ingresso unica delle fatture elettroniche (XML o .p7m).
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

        $inoltro = ['xml' => $xml, 'file_nome' => $data['file_nome'] ?? ''];
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
