<?php
/**
 * Prova da riga di comando dell'import di fatture emesse (XML, anche dal pulsante «Da FattureWeb»):
 * ogni riga va alla sua commessa dai protocolli nel testo (anche solo il codice SZ.DPS o il secondo
 * numero «+ 452/2027»), due commesse dello stesso sottocliente restano separate, una fattura entrata
 * dall'elenco Excel si completa invece di raddoppiare, un secondo import non aggiunge nulla.
 * SQLite in memoria, dati inventati.
 *
 *   php tests/fattura_righe_cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/Database.php';
require_once __DIR__ . '/../api/Shared/Response.php';
require_once __DIR__ . '/../api/Shared/Audit.php';
require_once __DIR__ . '/../api/Controllers/ContabilitaController.php';

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
$pdo = new MvPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('YEAR', fn($d) => $d === null ? null : (int)substr((string)$d, 0, 4), 1);
(new ReflectionProperty(Database::class, 'pdo'))->setValue(null, $pdo);
foreach ([
    "CREATE TABLE {$p}clienti (id INTEGER PRIMARY KEY, ragione_sociale TEXT, partita_iva TEXT, codice_fiscale TEXT, indirizzo TEXT, citta TEXT, cap TEXT, provincia TEXT)",
    "CREATE TABLE {$p}sottoclienti (id INTEGER PRIMARY KEY, cliente_id INT, nome TEXT, partita_iva TEXT, codice_fiscale TEXT, riferimento TEXT,
        indirizzo TEXT, citta TEXT, cap TEXT, provincia TEXT, pec TEXT, sdi TEXT, email TEXT)",
    "CREATE TABLE {$p}incarichi (id INTEGER PRIMARY KEY, cliente_id INT, sottocliente_id INT, numero_protocollo TEXT, importo_totale REAL,
        importo_fatturato REAL DEFAULT 0, importo_pagato REAL DEFAULT 0, stato TEXT DEFAULT 'attivo')",
    "CREATE TABLE {$p}incarichi_rate (id INTEGER PRIMARY KEY, incarico_id INT, ordine INT, descrizione TEXT, percentuale REAL, importo REAL,
        data_prevista TEXT, giorni_pagamento INT, fattura_id INT)",
    "CREATE TABLE {$p}fatture (id INTEGER PRIMARY KEY, numero_fattura TEXT, data_emissione TEXT, cliente_id INT, sottocliente_id INT, incarico_id INT,
        descrizione TEXT, imponibile REAL, iva_percentuale REAL, importo_iva REAL, importo_totale REAL, stato TEXT DEFAULT 'emessa',
        data_scadenza TEXT, data_pagamento TEXT, tipo_documento TEXT)",
    "CREATE TABLE {$p}riconciliazioni (id INTEGER PRIMARY KEY, movimento_id INT, tipo TEXT, documento_id INT, importo REAL)",
    "CREATE TABLE {$p}offerte (id INTEGER PRIMARY KEY, numero TEXT, incarico_id INT, cliente_id INT, versione INT, deleted_at TEXT)",
] as $sql) $pdo->exec($sql);

function risposta(callable $f): array
{
    Response::$cattura = true;
    try { $f(); return ['success' => false, 'message' => 'nessuna risposta']; }
    catch (RispostaCatturata $r) { return $r->risposta ?? (array)$r->getMessage(); }
    finally { Response::fineCattura(); }
}

/** FatturaPA minima: righe [descrizione, importo], IVA 22%. */
function xml(string $numero, string $data, array $righe, string $tipo = 'TD01'): string
{
    $linee = '';
    $imp = 0.0;
    foreach ($righe as $i => [$d, $v]) {
        $imp += $v;
        $linee .= '<DettaglioLinee><NumeroLinea>' . ($i + 1) . '</NumeroLinea><Descrizione>' . htmlspecialchars($d, ENT_XML1)
            . "</Descrizione><PrezzoUnitario>$v</PrezzoUnitario><PrezzoTotale>$v</PrezzoTotale><AliquotaIVA>22.00</AliquotaIVA></DettaglioLinee>";
    }
    $iva = round($imp * 0.22, 2);
    return '<?xml version="1.0" encoding="UTF-8"?><p:FatturaElettronica xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2" versione="FPR12">'
        . '<FatturaElettronicaHeader><CessionarioCommittente><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>01234567897</IdCodice></IdFiscaleIVA>'
        . '<Anagrafica><Denominazione>Associazione Esempio Servizi</Denominazione></Anagrafica></DatiAnagrafici></CessionarioCommittente></FatturaElettronicaHeader>'
        . "<FatturaElettronicaBody><DatiGenerali><DatiGeneraliDocumento><TipoDocumento>$tipo</TipoDocumento><Data>$data</Data><Numero>$numero</Numero></DatiGeneraliDocumento></DatiGenerali>"
        . "<DatiBeniServizi>$linee<DatiRiepilogo><AliquotaIVA>22.00</AliquotaIVA><ImponibileImporto>$imp</ImponibileImporto><Imposta>$iva</Imposta></DatiRiepilogo></DatiBeniServizi>"
        . '</FatturaElettronicaBody></p:FatturaElettronica>';
}

$pdo->exec("INSERT INTO {$p}clienti (id, ragione_sociale, partita_iva) VALUES (1, 'Associazione Esempio Servizi', '01234567897')");
$pdo->exec("INSERT INTO {$p}sottoclienti (id, cliente_id, nome) VALUES (10, 1, 'ALFA MECCANICA S.R.L.'), (11, 1, 'BETA TESSILE SPA')");
$pdo->exec("INSERT INTO {$p}incarichi (id, cliente_id, sottocliente_id, numero_protocollo, importo_totale) VALUES
    (100, 1, 10, 'Prot. n. 700/2026 (SZ.DPS.F010.26)', 1000),
    (101, 1, 10, '800/2026 + 50/2027', 2000),
    (102, 1, 11, 'SZ.DPS.F020.26', 500)");

echo "Commessa della riga\n";
$inc = $pdo->query("SELECT id, numero_protocollo, sottocliente_id FROM {$p}incarichi")->fetchAll();
$riga = fn(string $d) => CommessaService::incaricoDellaRiga($d, $inc)['id'] ?? null;
check('solo il codice SZ.DPS', $riga('Consulenze privacy presso ALFA MECCANICA S.R.L. (SZ.DPS.F010.26)') === 100);
check('solo il secondo numero di protocollo', $riga('Servizi di DPO presso ALFA Prot. n. 9/2026 + 50/2027') === 101);
check('una data non è un protocollo', $riga('storno parziale ft. 65 del 07/07/2026') === null);
check('testo senza codici', $riga('Supporto generale area privacy') === null);

echo "Fattura con tre commesse, due dello stesso sottocliente\n";
$x = xml('5/001', '2026-03-31', [
    ['Consulenze privacy presso ALFA MECCANICA S.R.L. Prot. n. 700/2026 (SZ.DPS.F010.26)', 500],
    ['Servizi di DPO presso ALFA MECCANICA S.R.L. Prot. n. 800/2026 + 50/2027', 1000],
    ['Consulenze privacy presso BETA TESSILE SPA (SZ.DPS.F020.26)', 250],
]);
$r = risposta(fn() => (new ContabilitaController())->importXmlData(['xml' => $x]));
check('import riuscito', ($r['success'] ?? false) === true, $r);
$righe = $pdo->query("SELECT incarico_id, sottocliente_id, imponibile, importo_iva FROM {$p}fatture WHERE numero_fattura = '5/001' ORDER BY incarico_id")->fetchAll();
check('un record per commessa', array_column($righe, 'incarico_id') === [100, 101, 102], $righe);
check('sottocliente dalla commessa', array_column($righe, 'sottocliente_id') === [10, 10, 11], $righe);
check('importi riga per riga', array_map('floatval', array_column($righe, 'imponibile')) === [500.0, 1000.0, 250.0], $righe);
check('IVA che torna col riepilogo', abs(array_sum(array_column($righe, 'importo_iva')) - 385.0) < 0.001, $righe);
check('commessa ricalcolata', (float)$pdo->query("SELECT importo_fatturato FROM {$p}incarichi WHERE id = 101")->fetchColumn() === 1000.0);
check('nessun sottocliente creato in più', (int)$pdo->query("SELECT COUNT(*) FROM {$p}sottoclienti")->fetchColumn() === 2);

$r = risposta(fn() => (new ContabilitaController())->importXmlData(['xml' => $x]));
check('secondo import: nulla di nuovo', ($r['data']['num_imported'] ?? -1) === 0, $r);
check('secondo import: sempre tre record', (int)$pdo->query("SELECT COUNT(*) FROM {$p}fatture WHERE numero_fattura = '5/001'")->fetchColumn() === 3);

echo "Fattura entrata prima dall'elenco Excel\n";
$pdo->prepare("INSERT INTO {$p}fatture (id, numero_fattura, data_emissione, cliente_id, imponibile, iva_percentuale, importo_iva, importo_totale, stato, data_pagamento, descrizione, tipo_documento)
    VALUES (50, '9/001', '2026-04-30', 7, 750, 22, 165, 915, 'pagata', '2026-06-10', ?, 'TD01')")->execute([ContabilitaController::DA_ELENCO]);
$pdo->exec("INSERT INTO {$p}riconciliazioni (movimento_id, tipo, documento_id, importo) VALUES (1, 'fattura', 50, 915)");
$r = risposta(fn() => (new ContabilitaController())->importXmlData(['xml' => xml('9/001', '2026-04-30', [
    ['Consulenze privacy presso ALFA MECCANICA S.R.L. (SZ.DPS.F010.26)', 500],
    ['Consulenze privacy presso BETA TESSILE SPA (SZ.DPS.F020.26)', 250],
])]));
check('import riuscito', ($r['success'] ?? false) === true, $r);
$righe = $pdo->query("SELECT id, cliente_id, incarico_id, imponibile, stato, data_pagamento FROM {$p}fatture WHERE numero_fattura = '9/001' ORDER BY id")->fetchAll();
check('due record, non tre', count($righe) === 2, $righe);
check('la riga dell\'elenco tiene il suo id (riconciliazione salva)', (int)$righe[0]['id'] === 50 && (int)$righe[0]['incarico_id'] === 100, $righe);
check('cliente corretto dalla P.IVA', (int)$righe[0]['cliente_id'] === 1, $righe);
check('la parte nuova resta pagata', $righe[1]['stato'] === 'pagata' && $righe[1]['data_pagamento'] === '2026-06-10', $righe);
check('totale invariato', abs(array_sum(array_map('floatval', array_column($righe, 'imponibile'))) - 750) < 0.001);

echo "Nota di credito con lo stesso numero di una fattura\n";
$r = risposta(fn() => (new ContabilitaController())->importXmlData(['xml' => xml('5/001', '2026-04-30', [
    ['Storno parziale SZ.DPS.F020.26', 100],
], 'TD04')]));
$nc = $pdo->query("SELECT incarico_id, imponibile FROM {$p}fatture WHERE numero_fattura = '5/001' AND tipo_documento = 'TD04'")->fetchAll();
check('nota di credito separata e negativa', count($nc) === 1 && (float)$nc[0]['imponibile'] === -100.0 && (int)$nc[0]['incarico_id'] === 102, $nc);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
