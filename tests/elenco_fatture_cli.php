<?php
/**
 * Prova da riga di comando dell'elenco di fatture ricevute: fornitori creati dal nome, P.IVA cercata
 * (risposta del web simulata), doppioni uniti, fatture tolte dalle emesse, XML che completa la riga.
 * SQLite in memoria, dati inventati.
 *
 *   php tests/elenco_fatture_cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/Database.php';
require_once __DIR__ . '/../api/Shared/Response.php';
require_once __DIR__ . '/../api/Shared/Audit.php';
require_once __DIR__ . '/../api/Shared/AnagraficaAuto.php';
require_once __DIR__ . '/../api/Controllers/FatturePassiveController.php';

$ok = 0;
$ko = 0;
function check(string $nome, bool $cond, $dettaglio = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok   $nome\n"; return; }
    $ko++;
    echo "  FAIL $nome" . ($dettaglio !== null ? ' → ' . json_encode($dettaglio, JSON_UNESCAPED_UNICODE) : '') . "\n";
}

$p = 'mv_';
putenv('DB_PREFIX=' . $p);
putenv('AZIENDA_PARTITA_IVA=01234567897');
$pdo = new MvPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
(new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $pdo);
foreach ([
    "CREATE TABLE {$p}clienti (id INTEGER PRIMARY KEY, ragione_sociale TEXT, partita_iva TEXT, codice_fiscale TEXT, note TEXT, piva_ricerca TEXT)",
    "CREATE TABLE {$p}fornitori (id INTEGER PRIMARY KEY, ragione_sociale TEXT, tipo TEXT DEFAULT 'partner', partita_iva TEXT, codice_fiscale TEXT,
        giorni_pagamento INT DEFAULT 30, note TEXT, deleted_at TEXT, piva_ricerca TEXT)",
    "CREATE TABLE {$p}fatture_passive (id INTEGER PRIMARY KEY, fornitore_id INT, incarico_id INT, costo_id INT, numero TEXT, data_emissione TEXT,
        descrizione TEXT, imponibile REAL, importo_iva REAL, ritenuta REAL, importo_totale REAL, data_scadenza TEXT, data_pagamento TEXT,
        stato TEXT DEFAULT 'da_pagare', note TEXT, UNIQUE (fornitore_id, numero, data_emissione))",
    "CREATE TABLE {$p}fatture (id INTEGER PRIMARY KEY, numero_fattura TEXT, data_emissione TEXT, cliente_id INT, importo_totale REAL, descrizione TEXT)",
    "CREATE TABLE {$p}commessa_costi (id INTEGER PRIMARY KEY, fornitore_id INT, incarico_id INT, importo_previsto REAL, giorni_pagamento INT)",
] as $sql) $pdo->exec($sql);

/** Esegue il controller e restituisce la risposta invece di uscire. */
function risposta(callable $f): array
{
    Response::$cattura = true;
    try { $f(); return ['success' => false, 'message' => 'nessuna risposta']; }
    catch (RispostaCatturata $r) { return $r->risposta ?? (array)$r->getMessage(); }
    finally { Response::fineCattura(); }
}

echo "Partita IVA\n";
check('italiana valida', AnagraficaAuto::partitaIvaValida('IT 01234567897') === '01234567897');
check('cifra di controllo sbagliata rifiutata', AnagraficaAuto::partitaIvaValida('01234567890') === null);
check('estera col prefisso paese', AnagraficaAuto::partitaIvaValida('LU26375245') === 'LU26375245');
check('testo a caso rifiutato', AnagraficaAuto::partitaIvaValida('non trovata') === null);
check('JSON dentro il testo', AnagraficaAuto::leggiRisposta("Ecco: {\"trovata\": true, \"partita_iva\": \"x\"} fine")['partita_iva'] === 'x');
check('trovata false → niente', AnagraficaAuto::leggiRisposta('{"trovata": false}') === null);

echo "Import dell'elenco\n";
$pdo->exec("INSERT INTO {$p}fornitori (id, ragione_sociale, tipo, partita_iva) VALUES (1, 'Alfa Consulenze S.r.l.', 'partner', '12345678903')");
// Una ricevuta finita per errore tra le emesse col vecchio import
$pdo->exec("INSERT INTO {$p}fatture (numero_fattura, data_emissione, cliente_id, importo_totale, descrizione)
    VALUES ('5/FE', '2026-09-25', NULL, 9202, 'Importata dalla lista fatture di Sistemi')");
$letto = ['verso' => 'passiva', 'avvisi' => [], 'fatture' => [
    ['nota_credito' => false, 'registro' => '', 'numero' => '5/FE', 'data' => '2026-09-25', 'cliente' => 'Beta Arredi S.n.c.', 'imponibile' => 9202.0, 'iva' => 0.0, 'totale' => 9202.0],
    ['nota_credito' => false, 'registro' => '', 'numero' => '7', 'data' => '2026-09-20', 'cliente' => 'Beta Arredi S.n.c.', 'imponibile' => 100.0, 'iva' => 0.0, 'totale' => 100.0],
    ['nota_credito' => true, 'registro' => '', 'numero' => 'NC1', 'data' => '2026-09-10', 'cliente' => 'ALFA CONSULENZE SRL', 'imponibile' => -50.0, 'iva' => 0.0, 'totale' => -50.0],
    ['nota_credito' => false, 'registro' => '', 'numero' => 'A-9', 'data' => '2026-08-01', 'cliente' => 'Gamma Lab', 'imponibile' => 30.0, 'iva' => 0.0, 'totale' => 30.0],
]];
$r = risposta(fn() => (new FatturePassiveController())->importLista($letto, 'elenco.xlsx'));
$d = $r['data'] ?? [];
check('import riuscito', ($r['success'] ?? false) === true, $r);
check('quattro fatture nuove', ($d['num_imported'] ?? 0) === 4, $d);
check('fornitore esistente riconosciuto per nome', (int)$pdo->query("SELECT fornitore_id FROM {$p}fatture_passive WHERE numero = 'NC1'")->fetchColumn() === 1);
check('due fornitori creati', ($d['anagrafiche_create'] ?? []) === ['Beta Arredi S.n.c.', 'Gamma Lab'], $d['anagrafiche_create'] ?? null);
check('e sono da cercare', count($d['da_cercare'] ?? []) === 2 && $d['da_cercare'][0]['tipo'] === 'fornitore');
check('ricevuta tolta dalle emesse', ($d['num_tolte_emesse'] ?? 0) === 1 && (int)$pdo->query("SELECT COUNT(*) FROM {$p}fatture")->fetchColumn() === 0);
check('nota di credito negativa', (float)$pdo->query("SELECT importo_totale FROM {$p}fatture_passive WHERE numero = 'NC1'")->fetchColumn() === -50.0);
check('scadenza a 30 giorni', $pdo->query("SELECT data_scadenza FROM {$p}fatture_passive WHERE numero = '7'")->fetchColumn() === '2026-10-20');
$r = risposta(fn() => (new FatturePassiveController())->importLista($letto, 'elenco.xlsx'));
check('secondo import: tutte già presenti', ($r['data']['num_imported'] ?? -1) === 0 && ($r['data']['num_existing'] ?? 0) === 4, $r['data'] ?? $r);

echo "Ricerca della partita IVA\n";
$beta = (int)$pdo->query("SELECT id FROM {$p}fornitori WHERE ragione_sociale = 'Beta Arredi S.n.c.'")->fetchColumn();
$gamma = (int)$pdo->query("SELECT id FROM {$p}fornitori WHERE ragione_sociale = 'Gamma Lab'")->fetchColumn();
$web = fn(string $json) => fn() => $json;
$e = AnagraficaAuto::cerca($pdo, $p, 'fornitore', $beta, $web('{"trovata": true, "partita_iva": "IT 98765432103", "codice_fiscale": ""}'));
check('trovata e salvata', $e['esito'] === 'trovata' && $pdo->query("SELECT partita_iva FROM {$p}fornitori WHERE id = $beta")->fetchColumn() === '98765432103', $e);
$e = AnagraficaAuto::cerca($pdo, $p, 'fornitore', $beta, fn() => throw new RuntimeException('non deve richiamare il web'));
check('già cercata: niente seconda ricerca', $e['messaggio'] === 'Beta Arredi S.n.c.: già cercata.');
// Gamma Lab è in realtà Alfa Consulenze (stessa P.IVA): si unisce
$e = AnagraficaAuto::cerca($pdo, $p, 'fornitore', $gamma, $web('{"trovata": true, "partita_iva": "12345678903"}'));
check('doppione unito al fornitore esistente', $e['esito'] === 'unita' && $e['unito_a'] === 1, $e);
check('fatture spostate', (int)$pdo->query("SELECT fornitore_id FROM {$p}fatture_passive WHERE numero = 'A-9'")->fetchColumn() === 1);
check('doppione tolto', $pdo->query("SELECT deleted_at FROM {$p}fornitori WHERE id = $gamma")->fetchColumn() !== null);
$pdo->exec("INSERT INTO {$p}fornitori (id, ragione_sociale, piva_ricerca) VALUES (50, 'Delta', 'da_cercare'), (51, 'Noi stessi', 'da_cercare')");
$e = AnagraficaAuto::cerca($pdo, $p, 'fornitore', 50, $web('Non ho trovato nulla di certo. {"trovata": false}'));
check('non trovata: segnata, da inserire a mano', $e['esito'] === 'non_trovata'
    && $pdo->query("SELECT piva_ricerca FROM {$p}fornitori WHERE id = 50")->fetchColumn() === 'non_trovata');
$e = AnagraficaAuto::cerca($pdo, $p, 'fornitore', 51, $web('{"trovata": true, "partita_iva": "01234567897"}'));
check('la nostra P.IVA non si assegna a un fornitore', $e['esito'] === 'non_trovata');
check('nessuna anagrafica ancora da cercare', AnagraficaAuto::daCercare($pdo, $p) === []);

echo "L'XML completa la fattura dell'elenco\n";
$xml = '<?xml version="1.0"?><FatturaElettronica><FatturaElettronicaHeader><CedentePrestatore><DatiAnagrafici>
    <IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>98765432103</IdCodice></IdFiscaleIVA><Anagrafica><Denominazione>Beta Arredi S.n.c.</Denominazione></Anagrafica>
    </DatiAnagrafici></CedentePrestatore></FatturaElettronicaHeader><FatturaElettronicaBody><DatiGenerali><DatiGeneraliDocumento>
    <TipoDocumento>TD01</TipoDocumento><Data>2026-09-20</Data><Numero>7</Numero></DatiGeneraliDocumento></DatiGenerali>
    <DatiBeniServizi><DettaglioLinee><Descrizione>Scrivania</Descrizione></DettaglioLinee><DatiRiepilogo><ImponibileImporto>81.97</ImponibileImporto><Imposta>18.03</Imposta></DatiRiepilogo></DatiBeniServizi>
    </FatturaElettronicaBody></FatturaElettronica>';
$r = risposta(fn() => (new FatturePassiveController())->importXml(['xml' => $xml]));
$riga = $pdo->query("SELECT * FROM {$p}fatture_passive WHERE numero = '7'")->fetch();
check('riga completata, non duplicata', ($r['success'] ?? false) && (int)$pdo->query("SELECT COUNT(*) FROM {$p}fatture_passive WHERE numero = '7'")->fetchColumn() === 1, $r);
check('imponibile e IVA dall\'XML', (float)$riga['imponibile'] === 81.97 && (float)$riga['importo_iva'] === 18.03 && $riga['descrizione'] === 'Scrivania', $riga);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
