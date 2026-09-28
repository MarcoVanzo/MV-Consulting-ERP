<?php
/**
 * Prova da riga di comando dell'importazione unica: anteprima (transazione annullata), transazioni
 * annidate, estratti CSV/Excel, camt.053, fattura attiva o passiva, aggancio alle rate.
 * SQLite in memoria, dati inventati.
 *
 *   php tests/importa_cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/Database.php';
require_once __DIR__ . '/../api/Shared/Response.php';
require_once __DIR__ . '/../api/Shared/Anteprima.php';
require_once __DIR__ . '/../api/Shared/EstrattoTabellare.php';
require_once __DIR__ . '/../api/Shared/CommessaService.php';
require_once __DIR__ . '/../api/Controllers/ImportaController.php';

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
$pdo = new MvPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach ([
    "CREATE TABLE t (v INT)",
    "CREATE TABLE {$p}clienti (id INTEGER PRIMARY KEY, ragione_sociale TEXT, partita_iva TEXT, codice_fiscale TEXT)",
    "CREATE TABLE {$p}fornitori (id INTEGER PRIMARY KEY, ragione_sociale TEXT, partita_iva TEXT, codice_fiscale TEXT, tipo TEXT DEFAULT 'partner', deleted_at TEXT)",
    "CREATE TABLE {$p}fatture (id INTEGER PRIMARY KEY, numero_fattura TEXT, incarico_id INT, imponibile REAL, data_emissione TEXT, data_scadenza TEXT)",
    "CREATE TABLE {$p}incarichi_rate (id INTEGER PRIMARY KEY, incarico_id INT, ordine INT, descrizione TEXT, percentuale REAL, importo REAL, giorni_pagamento INT DEFAULT 30, fattura_id INT)",
] as $sql) $pdo->exec($sql);

echo "Transazioni annidate\n";
$pdo->beginTransaction();
$pdo->exec("INSERT INTO t VALUES (1)");
$pdo->beginTransaction();
$pdo->exec("INSERT INTO t VALUES (2)");
$pdo->rollBack();
$pdo->beginTransaction();
$pdo->exec("INSERT INTO t VALUES (3)");
$pdo->commit();
$pdo->commit();
check('rollback interno annulla solo il savepoint', $pdo->query("SELECT GROUP_CONCAT(v) FROM t")->fetchColumn() === '1,3');
check('nessuna transazione rimasta aperta', !$pdo->inTransaction());

echo "Anteprima\n";
$esito = Anteprima::esegui($pdo, function () use ($pdo) {
    $pdo->beginTransaction();
    $pdo->exec("INSERT INTO t VALUES (9)");
    $pdo->commit();
    Response::json(true, 'Import fatto', ['num_imported' => 1]);
});
check('risposta dell\'import catturata', $esito['success'] === true && ($esito['data']['num_imported'] ?? 0) === 1, $esito);
check('dati dell\'anteprima annullati', (int)$pdo->query("SELECT COUNT(*) FROM t WHERE v = 9")->fetchColumn() === 0);
$esito = Anteprima::esegui($pdo, function () use ($pdo) {
    $pdo->beginTransaction();
    try {
        $pdo->exec("INSERT INTO t VALUES (8)");
        Response::json(false, 'Prima risposta');
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        Response::json(false, 'Risposta dal catch');
    }
});
check('vale la prima risposta anche se un catch la intercetta', $esito['message'] === 'Prima risposta', $esito);
check('anche con savepoint aperti tutto annullato', !$pdo->inTransaction() && (int)$pdo->query("SELECT COUNT(*) FROM t WHERE v = 8")->fetchColumn() === 0);
check('fuori dall\'anteprima si torna normali', !Anteprima::attiva());
Avvisi::aggiungi('avviso di prova');
$esito = Anteprima::esegui($pdo, fn() => Response::json(true, 'ok', ['x' => 1]));
check('gli avvisi raccolti arrivano nella risposta', ($esito['data']['avvisi_sistema'] ?? []) === ['avviso di prova'], $esito);
Avvisi::svuota();

echo "Estratti CSV\n";
$csv = "Estratto conto corrente n. 1234\nIntestatario;MV CONSULTING\n\nData contabile;Data valuta;Descrizione;Dare;Avere\n"
    . "01/09/2026;01/09/2026;BONIFICO DA ROSSI SRL FATT. 12/2026;;1.220,00\n"
    . "03/09/2026;02/09/2026;PAGAMENTO POS AUTOSTRADE;45,30;\n"
    . ";;Saldo finale;;9.999,00\n";
$l = EstrattoTabellare::parse(EstrattoTabellare::daCsv($csv));
check('intestazione trovata dopo le righe di testata', count($l['movimenti']) === 2, $l);
check('avere positivo, dare negativo', abs($l['movimenti'][0]['importo'] - 1220) < 0.001 && abs($l['movimenti'][1]['importo'] + 45.30) < 0.001, $l['movimenti']);
check('data valuta dalla sua colonna', $l['movimenti'][1]['data_valuta'] === '2026-09-02');
check('riga di saldo scartata e segnalata', count($l['avvisi']) === 1, $l['avvisi']);
$csvEn = "\xEF\xBB\xBFDate,Description,Amount\n2026-09-05,\"Card payment, Milano\",-12.50\n2026-09-06,Transfer in,\"1,500.00\"\n";
$l = EstrattoTabellare::parse(EstrattoTabellare::daCsv($csvEn), ['data_operazione' => 0, 'descrizione' => 1, 'importo' => 2, 'intestazione' => 0]);
check('csv con virgola, virgolette e numeri inglesi', count($l['movimenti']) === 2 && abs($l['movimenti'][1]['importo'] - 1500) < 0.001 && $l['movimenti'][0]['descrizione'] === 'Card payment, Milano', $l['movimenti']);
try {
    EstrattoTabellare::parse(EstrattoTabellare::daCsv("Quando;Cosa;Quanto\n01/09/2026;x;10,00\n"));
    check('colonne sconosciute: chiede la mappatura', false);
} catch (MappaturaRichiesta $e) {
    check('colonne sconosciute: chiede la mappatura', $e->intestazione === ['Quando', 'Cosa', 'Quanto'] && count($e->esempio) === 1);
}
try {
    EstrattoTabellare::parse(EstrattoTabellare::daCsv("Movimenti conto;;;\nGiorno;Testo;Euro;Note\n21/09/2026;BONIFICO;2.440,00;\n"));
} catch (MappaturaRichiesta $e) {
    check('titolo sopra la tabella: intestazione = riga prima dei movimenti', $e->intestazione[0] === 'Giorno' && $e->proposta['intestazione'] === 1, $e->intestazione);
}
$righe = EstrattoTabellare::daCsv("Quando;Cosa;Quanto\n01/09/2026;x;-10,00\n");
check('stessa intestazione, stessa firma', EstrattoTabellare::firmaDi($righe) === EstrattoTabellare::firmaDi(EstrattoTabellare::daCsv("quando ; cosa ; quanto\n")));
$num = ['1.234,56' => 1234.56, '-1234.56' => -1234.56, '1,234.56' => 1234.56, '€ 12,00' => 12.0, '(45,00)' => -45.0, '45,00-' => -45.0, '1.000' => 1000.0, '12.5' => 12.5, 'abc' => null];
$bad = array_filter($num, fn($v, $k) => EstrattoTabellare::numero($k) !== $v, ARRAY_FILTER_USE_BOTH);
check('numeri nei formati delle banche', !$bad, array_keys($bad));
check('data seriale di Excel', EstrattoTabellare::dataCella('46266') === '2026-09-01', EstrattoTabellare::dataCella('46266'));
check('data con ora', EstrattoTabellare::dataCella('2026-09-01 00:00:00') === '2026-09-01');

echo "camt.053\n";
$camt = '<?xml version="1.0"?><Document xmlns="urn:iso:std:iso:20022:tech:xsd:camt.053.001.08"><BkToCstmrStmt><Stmt>'
    . '<Acct><Id><IBAN>IT60X0542811101000000123456</IBAN></Id></Acct>'
    . '<Ntry><Amt Ccy="EUR">1220.00</Amt><CdtDbtInd>CRDT</CdtDbtInd><BookgDt><Dt>2026-09-01</Dt></BookgDt><ValDt><Dt>2026-09-01</Dt></ValDt>'
    . '<NtryDtls><TxDtls><RltdPties><Dbtr><Nm>ROSSI SRL</Nm></Dbtr></RltdPties><RmtInf><Ustrd>SALDO FATT. 12/2026</Ustrd></RmtInf></TxDtls></NtryDtls></Ntry>'
    . '</Stmt></BkToCstmrStmt></Document>';
$l = EstrattoContoParser::parseXmlCbi($camt);
$m = $l['movimenti'][0] ?? [];
check('camt.053 standard letto', count($l['movimenti']) === 1 && $l['iban'] === 'IT60X0542811101000000123456', $l);
check('causale da RmtInf/Ustrd e controparte', ($m['descrizione'] ?? '') === 'SALDO FATT. 12/2026' && ($m['controparte'] ?? '') === 'ROSSI SRL', $m);

echo "Fattura attiva o passiva\n";
$fatt = fn($ced, $ces) => "<p:FatturaElettronica xmlns:p=\"x\"><FatturaElettronicaHeader><CedentePrestatore><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>$ced</IdCodice></IdFiscaleIVA></DatiAnagrafici></CedentePrestatore>"
    . "<CessionarioCommittente><DatiAnagrafici><IdFiscaleIVA><IdPaese>IT</IdPaese><IdCodice>$ces</IdCodice></IdFiscaleIVA></DatiAnagrafici></CessionarioCommittente></FatturaElettronicaHeader></p:FatturaElettronica>";
check('emessa da noi: attiva', ImportaController::verso($fatt('01111111111', '02222222222'), $pdo, $p, 'IT01111111111') === 'attiva');
check('ricevuta da noi: passiva', ImportaController::verso($fatt('03333333333', '01111111111'), $pdo, $p, '01111111111') === 'passiva');
$pdo->exec("INSERT INTO {$p}clienti (ragione_sociale, partita_iva) VALUES ('Cliente', '02222222222')");
$pdo->exec("INSERT INTO {$p}fornitori (ragione_sociale, partita_iva) VALUES ('Partner', '04444444444')");
check('senza la nostra P.IVA: cessionario tra i clienti → attiva', ImportaController::verso($fatt('09999999999', '02222222222'), $pdo, $p, '') === 'attiva');
check('senza la nostra P.IVA: cedente tra i fornitori → passiva', ImportaController::verso($fatt('04444444444', '08888888888'), $pdo, $p, '') === 'passiva');
check('nessun indizio: chiede all\'utente', ImportaController::verso($fatt('05555555555', '06666666666'), $pdo, $p, '') === null);

echo "Aggancio alle rate\n";
$pdo->exec("INSERT INTO {$p}incarichi_rate (id, incarico_id, ordine, importo) VALUES (1, 7, 1, 3000), (2, 7, 2, 2000), (3, 7, 3, 5000)");
$pdo->exec("INSERT INTO {$p}fatture (id, numero_fattura, incarico_id, imponibile, data_emissione, data_scadenza) VALUES
    (1, '20', 7, 2000, '2026-09-01', '2026-10-01'), (2, '21', 7, 1234, '2026-09-02', '2026-10-01'), (3, '22', 7, 8000, '2026-09-03', '2026-10-01')");
$svc = new CommessaService($pdo, $p);
check('importo uguale a una rata: agganciata quella', $svc->collegaFatturaARata(1) === 2);
Avvisi::svuota();
check('importo che non combacia: nessuna rata scelta a caso', $svc->collegaFatturaARata(2) === null
    && (int)$pdo->query("SELECT COUNT(*) FROM {$p}incarichi_rate WHERE fattura_id = 2")->fetchColumn() === 0);
check('e l\'utente viene avvisato', count(Avvisi::tutti()) === 1 && str_contains(Avvisi::tutti()[0], 'Fattura 21'), Avvisi::tutti());
check('somma di rate consecutive riconosciuta', $svc->collegaFatturaARata(3) === 1
    && (int)$pdo->query("SELECT COUNT(*) FROM {$p}incarichi_rate WHERE fattura_id = 3")->fetchColumn() === 2);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
