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
