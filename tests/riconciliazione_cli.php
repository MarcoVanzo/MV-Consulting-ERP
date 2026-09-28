<?php
/**
 * Prova da riga di comando della riconciliazione pagamenti (non va online: tests/ è escluso dal deploy).
 *
 *   php tests/riconciliazione_cli.php
 *   CBI_FILE=/percorso/export.xml php tests/riconciliazione_cli.php   (conta i movimenti di un estratto vero, non stampa dati)
 *
 * Usa SQLite in memoria con lo schema minimo delle tabelle coinvolte e dati inventati.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/EstrattoContoParser.php';
require_once __DIR__ . '/../api/Shared/RiconciliazioneMatch.php';
require_once __DIR__ . '/../api/Shared/RiconciliazioneDocumenti.php';
require_once __DIR__ . '/../api/Shared/Riconciliatore.php';
require_once __DIR__ . '/../api/Shared/CommessaService.php';
require_once __DIR__ . '/../api/Shared/Classificatore.php';
require_once __DIR__ . '/../api/Shared/CategorieMovimenti.php';

$ok = 0;
$ko = 0;
function check(string $nome, bool $cond, $dettaglio = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok   $nome\n"; return; }
    $ko++;
    echo "  FAIL $nome" . ($dettaglio !== null ? ' → ' . json_encode($dettaglio, JSON_UNESCAPED_UNICODE) : '') . "\n";
}

// ═══ 1. Funzioni pure ═══════════════════════════════════════
echo "Riferimenti in causale\n";
$casi = [
    'Bonifico a vs favore *UNINDUSTRIA SERVIZI E FORMAZIONE TREVISO 2728-42 -FATT. 46/001 DEL 28/06/2026-FATT. 47/001 DEL 28/06/2026-FATT...'
        => [[46, '001', 2026], [47, '001', 2026]],
    'BONIFICO EUROINTERIM SERVIZI S.R.L. SALDO FATTURA NR. 67 DEL 12.12.2025' => [[67, null, 2025]],
    'PALLAVOLO SCANDICCI SAVINO DEL BENE SOCI FATT. N. 62-2025 DEL 251125' => [[62, null, 2025]],
    'ACME SRL FATT. N. 7-001 30012026' => [[7, '001', 2026]],
    'ROSSI SPA FATT. 64/2025 DEL 30/11/2025' => [[64, null, 2025]],
    'ZANUTTA S.P.A. 0009710825.FOR11469 19-050326' => [[19, null, 2026]],
    'SALDO FATTURE 12, 13 E 14 DEL 01/02/2026' => [[12, null, 2026], [13, null, 2026], [14, null, 2026]],
    'Wise 1944617001 Italy Girls Soccer 7/06' => [],
    'Erogazione mutuo chirografario 2728-42' => [],
];
foreach ($casi as $testo => $attesi) {
    $refs = array_map(fn($r) => [$r['numero'], $r['registro'], $r['anno']], RiconciliazioneMatch::estraiRiferimenti($testo));
    check(substr($testo, 0, 60), $refs == $attesi, $refs);
}

echo "Numeri ERP e corrispondenze\n";
check('46/001 → 46, sezionale 001', RiconciliazioneMatch::scomponiNumero('46/001') === [46, '001', null]);
check('15AV escluso', RiconciliazioneMatch::escluso('15AV') && RiconciliazioneMatch::escluso('3/002') && !RiconciliazioneMatch::escluso('46/001'));
$ref = ['numero' => 46, 'registro' => '001', 'anno' => 2026, 'data' => null];
check('ref 46/001/2026 ↔ 46/001 del 2026', RiconciliazioneMatch::corrisponde($ref, '46/001', '2026-06-28'));
check('ref 46/001/2026 ≠ 46/001 del 2025', !RiconciliazioneMatch::corrisponde($ref, '46/001', '2025-06-28'));
check('date in causale', RiconciliazioneMatch::dataCausale('251125') === '2025-11-25' && RiconciliazioneMatch::dataCausale('30012026') === '2026-01-30'
    && RiconciliazioneMatch::dataCausale('12.12.2025') === '2025-12-12' && RiconciliazioneMatch::dataCausale('31/02/2026') === null);

echo "Subset-sum\n";
$voci = [['id' => 'a', 'opzioni' => [10000]], ['id' => 'b', 'opzioni' => [25000]], ['id' => 'c', 'opzioni' => [5000]],
    ['id' => 'd', 'opzioni' => [30000]], ['id' => 'e', 'opzioni' => [4950]]];
$s = RiconciliazioneMatch::subsetSum($voci, 35000, 5);
check('due combinazioni per 350,00 (a+b, c+d)', count($s['soluzioni']) === 2, $s['soluzioni']);
$s = RiconciliazioneMatch::subsetSum($voci, 15000, 5);
check('una sola per 150,00 (a+c)', count($s['soluzioni']) === 1 && isset($s['soluzioni'][0]['a'], $s['soluzioni'][0]['c']) && count($s['soluzioni'][0]) === 2, $s['soluzioni']);
check('tolleranza 1 centesimo', count(RiconciliazioneMatch::subsetSum($voci, 10001)['soluzioni']) === 1);
check('opzione ritenuta', count(RiconciliazioneMatch::subsetSum([['id' => 'x', 'opzioni' => [80000, 100000]]], 100000)['soluzioni']) === 1);
$tante = [];
for ($i = 1; $i <= 20; $i++) $tante[] = ['id' => $i, 'opzioni' => [$i * 1000 + 7]];
$t0 = microtime(true);
$s = RiconciliazioneMatch::subsetSum($tante, 99999999, 2, 50000);
check('20 voci, bersaglio irraggiungibile: limite nodi rispettato (' . round((microtime(true) - $t0) * 1000) . ' ms)', !$s['soluzioni'] && (microtime(true) - $t0) < 2);
$s = RiconciliazioneMatch::subsetSum($tante, 1007 + 2007 + 20007, 3);
check('20 voci: trova 1+2+20', count($s['soluzioni']) >= 1);

echo "Punteggio\n";
$doc = ['residuo' => 500.0, 'ritenuta' => 0, 'anagrafica_id' => 3, 'data_scadenza' => '2026-03-10'];
$p = RiconciliazioneMatch::punteggio($doc, 50000, true, 3, '2026-03-12');
check('importo+cliente+numero+scadenza = 50+25+30+15', $p['punteggio'] === 120, $p);
check('solo vicinanza scadenza = 0', RiconciliazioneMatch::punteggio($doc, 12345, false, null, '2026-03-10')['punteggio'] === 0);

echo "Parser PDF a regole\n";
$pdf = "ESTRATTO CONTO AL 31/03/2026\nDATA DATA VALUTA DESCRIZIONE DARE AVERE\n"
    . "02/03/26 02/03/26 BONIFICO A VOSTRO FAVORE 1.220,00\nORD: ALFA SRL CAUSALE FATT. 12/001 DEL 10/02/2026\n"
    . "05/03/2026 04/03/2026 COMMISSIONI BONIFICO 1,50\n"
    . "06/03/26 06/03/26 PAGAMENTO 10,00-\nSALDO FINALE 1.208,50";
$r = EstrattoContoParser::parseRegole($pdf);
check('3 movimenti', count($r['movimenti']) === 3, $r);
check('accredito +1220 con causale sulla riga dopo', ($r['movimenti'][0]['importo'] ?? null) === 1220.0 && strpos($r['movimenti'][0]['descrizione'], 'FATT. 12/001') !== false);
check('controparte ALFA SRL', ($r['movimenti'][0]['controparte'] ?? '') === 'ALFA SRL', $r['movimenti'][0]['controparte'] ?? null);
check('commissione −1,50 (segno certo)', ($r['movimenti'][1]['importo'] ?? null) === -1.5 && !$r['movimenti'][1]['segno_incerto']);
check('segno esplicito −10', ($r['movimenti'][2]['importo'] ?? null) === -10.0);
check('importo italiano', EstrattoContoParser::importoIt('1.234,56-') === -1234.56 && EstrattoContoParser::importoIt('abc') === null);

// ═══ 2. Estratto CBI sintetico ══════════════════════════════
function ntry(string $ref, float $amt, string $ind, string $data, string $causale, string $cd = '48', string $nome = ''): string
{
    $parte = $nome !== '' ? ($ind === 'CRDT' ? "<ns5:Dbtr><ns5:Nm>$nome</ns5:Nm></ns5:Dbtr>" : "<ns5:Cdtr><ns5:Nm>$nome</ns5:Nm></ns5:Cdtr>") : '';
    return "<ns5:Ntry><ns5:NtryRef>$ref</ns5:NtryRef><ns5:Amt Ccy=\"EUR\">" . number_format($amt, 2, '.', '') . "</ns5:Amt>"
        . "<ns5:CdtDbtInd>$ind</ns5:CdtDbtInd><ns5:Sts>BOOK</ns5:Sts><ns5:BookgDt><ns5:Dt>{$data}+01:00</ns5:Dt></ns5:BookgDt>"
        . "<ns5:ValDt><ns5:Dt>{$data}+01:00</ns5:Dt></ns5:ValDt><ns5:AcctSvcrRef>S$ref</ns5:AcctSvcrRef>"
        . "<ns5:BkTxCd><ns5:Prtry><ns5:Cd>$cd//00</ns5:Cd></ns5:Prtry></ns5:BkTxCd>"
        . "<ns5:NtryDtls><ns5:TxDtls><ns5:RltdPties>$parte</ns5:RltdPties><ns5:AddtlTxInf>$causale</ns5:AddtlTxInf></ns5:TxDtls></ns5:NtryDtls></ns5:Ntry>";
}
function cbi(array $ntry, float $apertura, float $chiusura): string
{
    return '<?xml version="1.0" encoding="UTF-8"?><ns4:CBIBdyBkToCstmrStmtReq xmlns:ns4="urn:CBI:xsd:CBIBdyBkToCstmrStmtReq.00.01.02" xmlns:ns5="urn:CBI:xsd:CBIDlyStmtReqLogMsg.00.01.02">'
        . '<ns4:CBIEnvelDlyStmtReqLogMsg><ns4:CBIDlyStmtReqLogMsg><ns5:Stmt><ns5:Id>1</ns5:Id>'
        . '<ns5:Acct><ns5:Id><ns5:IBAN>IT00X0000000000000000000000</ns5:IBAN></ns5:Id></ns5:Acct>'
        . '<ns5:Bal><ns5:Tp><ns5:CdOrPrtry><ns5:Cd>OPBD</ns5:Cd></ns5:CdOrPrtry></ns5:Tp><ns5:Amt Ccy="EUR">' . number_format($apertura, 2, '.', '') . '</ns5:Amt><ns5:CdtDbtInd>CRDT</ns5:CdtDbtInd></ns5:Bal>'
        . '<ns5:Bal><ns5:Tp><ns5:CdOrPrtry><ns5:Cd>CLBD</ns5:Cd></ns5:CdOrPrtry></ns5:Tp><ns5:Amt Ccy="EUR">' . number_format($chiusura, 2, '.', '') . '</ns5:Amt><ns5:CdtDbtInd>CRDT</ns5:CdtDbtInd></ns5:Bal>'
        . implode('', $ntry) . '</ns5:Stmt></ns4:CBIDlyStmtReqLogMsg></ns4:CBIEnvelDlyStmtReqLogMsg></ns4:CBIBdyBkToCstmrStmtReq>';
}

$ntry = [
    // Causale troncata: cita 46 e 47, la 48 (stessa data, due righe/sottoclienti) si ricava dal subset-sum
    ntry('R1', 1220.00 + 610.00 + 732.00 + 488.00, 'CRDT', '2026-07-20', 'Bonifico a vs favore *UNINDUSTRIA SERVIZI E FORMAZIONE TREVISO 2728-42 -FATT. 46/001 DEL 28/06/2026-FATT. 47/001 DEL 28/06/2026-FATT...'),
    ntry('R2', 915.00, 'CRDT', '2026-01-15', 'Bonifico a vs favore EUROINTERIM SERVIZI S.R.L. SALDO FATTURA NR. 67 DEL 12.12.2025'),
    ntry('R3', 300.00, 'CRDT', '2026-01-10', 'Bonifico a vs favore PALLAVOLO SCANDICCI SAVINO DEL BENE SOCI FATT. N. 62-2025 DEL 251125'),
    ntry('R4', 2440.00, 'CRDT', '2026-04-02', 'ZANUTTA S.P.A. 0009710825.FOR11469 19-050326'),
    ntry('R5', 1067.80, 'CRDT', '2026-06-10', 'Wise 1944617001 Italy Girls Soccer 7/06'),
    ntry('R6', 1.50, 'DBIT', '2026-06-10', 'Commissioni bonifico', '26'),
    ntry('R7', 50000.00, 'CRDT', '2026-02-01', 'Erogazione mutuo', '47'),
    ntry('R8', 350.00, 'DBIT', '2026-05-05', 'Bonifico a favore di STUDIO PAGHE ALFA SAS stipendi e paghe maggio', '48', 'STUDIO PAGHE ALFA SAS'),
    ntry('R9', 500.00, 'CRDT', '2026-05-20', 'Bonifico a vs favore FUSION TEAM VOLLEY A.S.D. quota', '48', 'FUSION TEAM VOLLEY A.S.D.'),
];
$movTot = 1220 + 610 + 732 + 488 + 915 + 300 + 2440 + 1067.80 - 1.50 + 50000 - 350 + 500;
$xml = cbi($ntry, 1000.00, 1000.00 + $movTot);

echo "Parser XML CBI\n";
$letto = EstrattoContoParser::parseXmlCbi($xml);
check('9 movimenti letti', count($letto['movimenti']) === 9, count($letto['movimenti']));
check('IBAN del conto', $letto['iban'] === 'IT00X0000000000000000000000');
check('saldi quadrati', !$letto['avvisi'], $letto['avvisi']);
$m0 = $letto['movimenti'][0];
check('data valuta senza fuso orario', $m0['data_valuta'] === '2026-07-20');
check('addebito negativo con controparte da Cdtr', $letto['movimenti'][7]['importo'] === -350.0 && $letto['movimenti'][7]['controparte'] === 'STUDIO PAGHE ALFA SAS');
check('codice operazione', $letto['movimenti'][6]['codice_operazione'] === '47//00');
check('hash dal riferimento banca con data e importo', EstrattoContoParser::conHash([$m0])[0]['hash_riga'] === hash('sha256', 'ref|IT00X0000000000000000000000|R1|2026-07-20|3050.00'));
$sbagliato = EstrattoContoParser::parseXmlCbi(cbi([ntry('Z', 10, 'CRDT', '2026-01-01', 'x')], 0, 99));
check('quadratura sbagliata segnalata', count($sbagliato['avvisi']) === 1);

// ═══ 3. Motore su SQLite ════════════════════════════════════
echo "Riconciliatore (SQLite in memoria)\n";
/** PDO che conta le query (per verificare che le liste non facciano una query per riga) */
class ContaPdo extends PDO
{
    public int $n = 0;
    public function prepare(string $query, array $options = []): PDOStatement|false { $this->n++; return parent::prepare($query, $options); }
    public function query(string $query, ?int $fetchMode = null, mixed ...$args): PDOStatement|false
    {
        $this->n++;
        return $fetchMode === null ? parent::query($query) : parent::query($query, $fetchMode, ...$args);
    }
}
$pdo = new ContaPdo('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$p = 'mv_';
foreach ([
    "CREATE TABLE {$p}clienti (id INTEGER PRIMARY KEY, ragione_sociale TEXT, partita_iva TEXT, codice_fiscale TEXT)",
    "CREATE TABLE {$p}sottoclienti (id INTEGER PRIMARY KEY, cliente_id INT, nome TEXT)",
    "CREATE TABLE {$p}fornitori (id INTEGER PRIMARY KEY, ragione_sociale TEXT, partita_iva TEXT, codice_fiscale TEXT, deleted_at TEXT, categoria_default_id INT)",
    "CREATE TABLE {$p}categorie_movimento (id INTEGER PRIMARY KEY, codice TEXT UNIQUE, nome TEXT NOT NULL, tipo TEXT NOT NULL,
        colore TEXT NOT NULL DEFAULT '#64748B', ordine INT NOT NULL DEFAULT 0, attiva INT NOT NULL DEFAULT 1)",
    "CREATE TABLE {$p}regole_categoria (id INTEGER PRIMARY KEY, chiave TEXT NOT NULL DEFAULT '', codice_operazione TEXT NOT NULL DEFAULT '',
        segno INT NOT NULL, categoria_id INT NOT NULL, utilizzi INT NOT NULL DEFAULT 0, created_by INT, UNIQUE (chiave, codice_operazione, segno))",
    "CREATE TABLE {$p}incarichi (id INTEGER PRIMARY KEY, cliente_id INT, importo_totale REAL, importo_fatturato REAL DEFAULT 0, importo_pagato REAL DEFAULT 0, stato TEXT DEFAULT 'attivo')",
    "CREATE TABLE {$p}incarichi_rate (id INTEGER PRIMARY KEY, incarico_id INT, ordine INT, descrizione TEXT, importo REAL, giorni_pagamento INT DEFAULT 30, fattura_id INT UNIQUE)",
    "CREATE TABLE {$p}fatture (id INTEGER PRIMARY KEY, numero_fattura TEXT, data_emissione TEXT, cliente_id INT, sottocliente_id INT, incarico_id INT,
        imponibile REAL, importo_totale REAL, stato TEXT DEFAULT 'emessa', data_scadenza TEXT, data_pagamento TEXT, metodo_pagamento TEXT)",
    "CREATE TABLE {$p}fatture_passive (id INTEGER PRIMARY KEY, fornitore_id INT, incarico_id INT, numero TEXT, data_emissione TEXT,
        imponibile REAL, ritenuta REAL DEFAULT 0, importo_totale REAL, stato TEXT DEFAULT 'da_pagare', data_scadenza TEXT, data_pagamento TEXT)",
    "CREATE TABLE {$p}movimenti_banca (id INTEGER PRIMARY KEY, banca TEXT DEFAULT '', iban TEXT, riferimento_banca TEXT, codice_operazione TEXT,
        data_operazione TEXT NOT NULL, data_valuta TEXT, importo REAL NOT NULL, descrizione TEXT, controparte TEXT, hash_riga TEXT NOT NULL UNIQUE,
        stato TEXT NOT NULL DEFAULT 'da_riconciliare', origine TEXT NOT NULL DEFAULT 'estratto_conto', avviso_id INT, file_nome TEXT,
        categoria_id INT, categoria_fonte TEXT, classificazione TEXT NOT NULL DEFAULT 'da_classificare', regola_id INT,
        categoria_proposta_id INT, proposta_motivo TEXT, abbinabile INT NOT NULL DEFAULT 1,
        segno_incerto INT NOT NULL DEFAULT 0, abbinamento_annullato INT NOT NULL DEFAULT 0,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP, updated_at TEXT DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE {$p}riconciliazioni (id INTEGER PRIMARY KEY, movimento_id INT NOT NULL REFERENCES {$p}movimenti_banca(id) ON DELETE CASCADE,
        tipo TEXT NOT NULL, documento_id INT NOT NULL, importo REAL NOT NULL, metodo TEXT NOT NULL, created_by INT, stato_precedente TEXT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
] as $sql) $pdo->exec($sql);
// Categorie iniziali: la stessa INSERT della migrazione v060 (INSERT IGNORE → sintassi SQLite)
$src = (string)file_get_contents(__DIR__ . '/../api/Shared/migrazioni.php');
preg_match('/"(INSERT IGNORE INTO \{\$prefix\}categorie_movimento.*?)"/s', $src, $mSeed);
$pdo->exec(str_replace(['INSERT IGNORE', '{$prefix}'], ['INSERT OR IGNORE', $p], $mSeed[1]));

$ins = fn(string $sql, array $v) => $pdo->prepare($sql)->execute($v);
$ins("INSERT INTO {$p}clienti (id, ragione_sociale) VALUES (?, ?)", [1, 'Unindustria Servizi & Formazione Treviso Pordenone S.c.a r.l.']);
$ins("INSERT INTO {$p}clienti (id, ragione_sociale) VALUES (?, ?)", [2, 'Eurointerim S.p.A.']);
$ins("INSERT INTO {$p}clienti (id, ragione_sociale) VALUES (?, ?)", [3, 'Pallavolo Scandicci Savino Del Bene']);
$ins("INSERT INTO {$p}clienti (id, ragione_sociale) VALUES (?, ?)", [4, 'Zanutta S.p.A.']);
$ins("INSERT INTO {$p}clienti (id, ragione_sociale) VALUES (?, ?)", [5, 'Fusion Team Volley A.S.D.']);
$ins("INSERT INTO {$p}fornitori (id, ragione_sociale) VALUES (?, ?)", [1, 'Studio Paghe Alfa S.a.s.']);
$ins("INSERT INTO {$p}sottoclienti (id, cliente_id, nome) VALUES (?, ?, ?)", [1, 1, 'Azienda Uno']);
$ins("INSERT INTO {$p}sottoclienti (id, cliente_id, nome) VALUES (?, ?, ?)", [2, 1, 'Azienda Due']);
// Incarichi con due rate ciascuno (acconto già pagato in precedenza, saldo = fattura 48)
foreach ([[1, 1, 1100.0], [2, 1, 800.0]] as [$id, $cl, $imp]) {
    $ins("INSERT INTO {$p}incarichi (id, cliente_id, importo_totale) VALUES (?, ?, ?)", [$id, $cl, $imp]);
}
$fatt = [
    // id, numero, data, cliente, sottocliente, incarico, imponibile, totale, stato
    [1, '46/001', '2026-06-28', 1, null, null, 1000, 1220, 'emessa'],
    [2, '47/001', '2026-06-28', 1, null, null, 500, 610, 'emessa'],
    [3, '48/001', '2026-06-28', 1, 1, 1, 600, 732, 'emessa'],   // fattura 48 spezzata su due sottoclienti/incarichi
    [4, '48/001', '2026-06-28', 1, 2, 2, 400, 488, 'emessa'],
    [5, '49/001', '2026-07-01', 1, null, null, 100, 122, 'emessa'],   // altra data: non entra nel completamento
    [6, '67/001', '2025-12-12', 2, null, null, 750, 915, 'emessa'],
    [7, '62/001', '2025-11-25', 3, null, null, 1000, 1220, 'scaduta'],
    [8, '19/001', '2026-03-05', 4, null, null, 2000, 2440, 'emessa'],
    [9, '15AV', '2026-06-01', 5, null, null, 1067.80, 1067.80, 'emessa'],   // registro agenzia viaggi: escluso
    [10, '80/001', '2026-05-01', 5, null, null, 409.84, 500, 'emessa'],
    [11, '81/001', '2026-05-02', 5, null, null, 409.84, 500, 'emessa'],   // due fatture uguali: ambiguo
    [12, '10/001', '2026-01-10', 1, 1, 1, 500, 610, 'pagata'],    // acconto incarico 1 già pagato
    [13, '11/001', '2026-01-10', 1, 2, 2, 400, 488, 'pagata'],    // acconto incarico 2 già pagato
];
foreach ($fatt as $f) {
    $ins("INSERT INTO {$p}fatture (id, numero_fattura, data_emissione, cliente_id, sottocliente_id, incarico_id, imponibile, importo_totale, stato) VALUES (?,?,?,?,?,?,?,?,?)", $f);
}
foreach ([[1, 1, 1, 'Acconto', 500, 12], [2, 1, 2, 'Saldo', 600, 3], [3, 2, 1, 'Acconto', 400, 13], [4, 2, 2, 'Saldo', 400, 4]] as $r) {
    $ins("INSERT INTO {$p}incarichi_rate (id, incarico_id, ordine, descrizione, importo, fattura_id) VALUES (?,?,?,?,?,?)", $r);
}
$ins("INSERT INTO {$p}fatture_passive (id, fornitore_id, numero, data_emissione, imponibile, importo_totale, data_scadenza) VALUES (?,?,?,?,?,?,?)",
    [1, 1, 'P-12', '2026-04-30', 350, 350, '2026-05-31']);

// Ricalcolo incarico come IncarchiController::recalculate (che nel test non si può istanziare: vuole MySQL)
$dopo = function (int $fatturaId) use ($pdo, $p) {
    $inc = (int)$pdo->query("SELECT incarico_id FROM {$p}fatture WHERE id = $fatturaId")->fetchColumn();
    if (!$inc) return;
    (new CommessaService($pdo, $p))->collegaFatturaARata($fatturaId);
    $pag = (float)$pdo->query("SELECT COALESCE(SUM(imponibile),0) FROM {$p}fatture WHERE incarico_id = $inc AND stato = 'pagata'")->fetchColumn();
    $tot = (float)$pdo->query("SELECT importo_totale FROM {$p}incarichi WHERE id = $inc")->fetchColumn();
    $pdo->prepare("UPDATE {$p}incarichi SET importo_pagato = ?, stato = ? WHERE id = ?")->execute([$pag, $pag >= $tot - 0.01 ? 'pagato' : 'parziale', $inc]);
};
$ric = new Riconciliatore($pdo, $p, $dopo);
$stato = fn(int $id) => $pdo->query("SELECT stato FROM {$p}fatture WHERE id = $id")->fetchColumn();

check('documenti aperti: 48/001 è un documento con 2 righe, 15AV escluso', (function () use ($ric) {
    $docs = $ric->documenti()->aperti('fattura');
    $n48 = array_values(array_filter($docs, fn($d) => $d['numero'] === '48/001'));
    return count($n48) === 1 && count($n48[0]['righe']) === 2 && abs($n48[0]['residuo'] - 1220) < 0.01
        && !array_filter($docs, fn($d) => $d['numero'] === '15AV');
})());

$pdo->beginTransaction();
$esito = $ric->importaMovimenti($letto['movimenti'], ['banca' => 'Banca di prova', 'iban' => $letto['iban'], 'file_nome' => 'prova.xml'], 7);
$pdo->commit();
$perRef = [];
foreach ($esito['movimenti'] as $m) $perRef[$m['descrizione']] = $m;
echo '  riepilogo: ' . json_encode(array_diff_key($esito, ['movimenti' => 1])) . "\n";
check('tutti i 9 movimenti salvati', $esito['nuovi'] === 9, $esito);
check('3 senza aggancio a fatture (Wise, commissioni, mutuo): abbinabile = 0', $esito['senza_aggancio'] === 3
    && (int)$pdo->query("SELECT COUNT(*) FROM {$p}movimenti_banca WHERE abbinabile = 0")->fetchColumn() === 3);
check('4 abbinati in automatico', $esito['abbinati'] === 4, array_map(fn($m) => [$m['descrizione'], $m['esito'], $m['dettaglio']], $esito['movimenti']));
check('causale troncata: 46, 47 e tutte le righe della 48 pagate', $stato(1) === 'pagata' && $stato(2) === 'pagata' && $stato(3) === 'pagata' && $stato(4) === 'pagata' && $stato(5) !== 'pagata');
check('data pagamento = data valuta', $pdo->query("SELECT data_pagamento FROM {$p}fatture WHERE id = 3")->fetchColumn() === '2026-07-20');
$r1 = $ric->documenti()->rateIncarico(1);
$r2 = $ric->documenti()->rateIncarico(2);
check('incarichi: rate incassate 2 su 2', $r1['rate_incassate'] === 2 && $r1['rate_totali'] === 2 && $r2['rate_incassate'] === 2, [$r1, $r2]);
check('incarico 1 pagato', $pdo->query("SELECT stato FROM {$p}incarichi WHERE id = 1")->fetchColumn() === 'pagato');
check('NR. 67 del 2025 pagata', $stato(6) === 'pagata');
check('Zanutta 19-050326 pagata', $stato(8) === 'pagata');
check('fornitore generico (studio paghe) pagato', $pdo->query("SELECT stato FROM {$p}fatture_passive WHERE id = 1")->fetchColumn() === 'pagata');
check('acconto Scandicci (300 su 1220): da verificare con proposta sulla 62', (function () use ($esito) {
    foreach ($esito['movimenti'] as $m) {
        if (strpos($m['descrizione'], '62-2025') !== false) {
            return $m['esito'] === 'da_verificare' && ($m['proposte'][0]['documenti'][0]['numero'] ?? '') === '62/001'
                && abs($m['proposte'][0]['documenti'][0]['importo'] - 300) < 0.01;
        }
    }
    return false;
})());
check('Fusion: due fatture da 500, nessun abbinamento automatico', $stato(10) !== 'pagata' && $stato(11) !== 'pagata');

$pdo->beginTransaction();
$bis = $ric->importaMovimenti($letto['movimenti'], ['banca' => 'Banca di prova', 'iban' => $letto['iban'], 'file_nome' => 'prova.xml'], 7);
$pdo->commit();
check('reimport dello stesso estratto: nessun nuovo movimento', $bis['nuovi'] === 0 && $bis['gia_presenti'] === 9, $bis);

echo "Conferma manuale, annulla, ignora\n";
$idScandicci = (int)$pdo->query("SELECT id FROM {$p}movimenti_banca WHERE descrizione LIKE '%62-2025%'")->fetchColumn();
$saldati = $ric->registra($idScandicci, [['tipo' => 'fattura', 'id' => 7, 'importo' => 300]], 'manuale', 7);
check('acconto: fattura resta aperta, movimento riconciliato', !$saldati && $stato(7) === 'scaduta'
    && $ric->movimento($idScandicci)['stato'] === 'riconciliato');
check('residuo 62/001 = 920', abs($ric->documenti()->documento('fattura', 7)['residuo'] - 920) < 0.01);
try { $ric->registra($idScandicci, [['tipo' => 'fattura', 'id' => 10, 'importo' => 500]], 'manuale', 7); $e = null; } catch (RuntimeException $e) {}
check('oltre l\'importo del movimento: rifiutato', $e instanceof RuntimeException);
$idFusion = (int)$pdo->query("SELECT id FROM {$p}movimenti_banca WHERE descrizione LIKE '%FUSION%'")->fetchColumn();
$prop = $ric->proposte($idFusion);
check('Fusion: proposte con le due fatture da 500', count(array_filter($prop, fn($x) => in_array($x['documenti'][0]['numero'] ?? '', ['80/001', '81/001'], true))) === 2, $prop);
try { $ric->registra($idFusion, [['tipo' => 'fattura_passiva', 'id' => 1, 'importo' => 500]], 'manuale', 7); $e = null; } catch (RuntimeException $e) {}
check('accredito su fattura passiva: rifiutato', $e instanceof RuntimeException);
$ric->ignora($idFusion);
check('ignora', $ric->movimento($idFusion)['stato'] === 'ignorato' && $ric->proposte($idFusion) === []);
$ric->ignora($idFusion, true);
check('ripristina', $ric->movimento($idFusion)['stato'] === 'da_riconciliare');

$idUni = (int)$pdo->query("SELECT id FROM {$p}movimenti_banca WHERE descrizione LIKE '%UNINDUSTRIA%'")->fetchColumn();
$riaperte = $ric->annulla($idUni);
check('annulla: 4 righe riaperte (46, 47, 48×2)', count($riaperte) === 4 && $stato(3) === 'emessa' && $stato(1) === 'emessa', $riaperte);
check('annulla: rata saldo non più incassata, incarico parziale', $ric->documenti()->rateIncarico(1)['rate_incassate'] === 1
    && $pdo->query("SELECT stato FROM {$p}incarichi WHERE id = 1")->fetchColumn() === 'parziale');
check('annulla: movimento da riconciliare', $ric->movimento($idUni)['stato'] === 'da_riconciliare');
$lista = $ric->lista(['stato' => 'riconciliato']);
check('lista filtrata per stato', count($lista) === 4 && isset($lista[0]['riconciliazioni']), count($lista));

echo "Avviso di pagamento ↔ accredito\n";
// Avviso registrato prima: poi l'accredito in banca (valuta +3 giorni) si collega senza doppio pagamento
$idAvv = $ric->registraAvviso(['data' => '2026-07-20', 'importo' => 3050.0, 'descrizione' => 'Avviso di pagamento — fatture 46, 47, 48'],
    [['tipo' => 'fattura', 'id' => 1, 'importo' => 1220], ['tipo' => 'fattura', 'id' => 2, 'importo' => 610], ['tipo' => 'fattura', 'id' => 3, 'importo' => null]], 7, 2.0);
check('avviso: fatture pagate', $stato(1) === 'pagata' && $stato(4) === 'pagata');
check('avviso: collegato all\'accredito già presente (stesso importo, ±5 gg)', (int)$ric->movimento($idUni)['avviso_id'] === $idAvv
    && $ric->movimento($idUni)['stato'] === 'riconciliato');
$pdo->beginTransaction();
$nuovo = $ric->importaMovimenti([['data_operazione' => '2026-08-03', 'data_valuta' => '2026-08-03', 'importo' => 1220.0,
    'descrizione' => 'Bonifico a vs favore EUROINTERIM saldo', 'controparte' => null, 'riferimento' => 'R99', 'iban' => 'X']], ['banca' => ''], 7);
$pdo->commit();
$idAvv2 = $ric->registraAvviso(['data' => '2026-08-20', 'importo' => 1220.0, 'descrizione' => 'Avviso 62'], [['tipo' => 'fattura', 'id' => 7, 'importo' => 920]], 7, 2.0);
check('avviso lontano più di 5 giorni: non collega', (int)$pdo->query("SELECT COUNT(*) FROM {$p}movimenti_banca WHERE avviso_id = $idAvv2")->fetchColumn() === 0);
$righeRic = (int)$pdo->query("SELECT COUNT(*) FROM {$p}riconciliazioni")->fetchColumn();
$ric->annulla($idAvv);
check('annulla avviso: fatture riaperte e accredito scollegato', $stato(1) === 'emessa' && $ric->movimento($idUni)['avviso_id'] === null
    && (int)$pdo->query("SELECT COUNT(*) FROM {$p}riconciliazioni")->fetchColumn() < $righeRic);

// ═══ 3b. Categorie dei movimenti ═══════════════════════════
echo "Chiavi normalizzate ed euristiche\n";
check('testo normalizzato: via date, numeri, ID e parole generiche',
    Classificatore::testoNormalizzato('Bonifico a vs favore *UNINDUSTRIA SERVIZI E FORMAZIONE 2728-42 -FATT. 46/001 DEL 28/06/2026') === 'UNINDUSTRIA SERVIZI FORMAZIONE',
    Classificatore::testoNormalizzato('Bonifico a vs favore *UNINDUSTRIA SERVIZI E FORMAZIONE 2728-42 -FATT. 46/001 DEL 28/06/2026'));
check('chiave Wise senza ID', Classificatore::chiaveSuggerita('Wise 1944617001 Italy Girls Soccer 7/06', null) === 'WISE ITALY GIRLS');
check('chiave dalla controparte (senza forma giuridica)', Classificatore::chiaveSuggerita('Bonifico stipendi', 'STUDIO PAGHE ALFA SAS') === 'STUDIO PAGHE ALFA');
check('chiave troppo generica rifiutata', !Classificatore::chiaveValida(Classificatore::testoNormalizzato('Bonifico SRL 12/2026')) && Classificatore::chiaveValida('WISE'));
$regWise = ['id' => 1, 'chiave' => 'WISE', 'codice_operazione' => '', 'segno' => 1, 'categoria_id' => 5];
check('regola: parola intera, segno giusto', Classificatore::regolaCorrisponde($regWise, ['importo' => 10, 'descrizione' => 'Wise 123 Italy', 'controparte' => null])
    && !Classificatore::regolaCorrisponde($regWise, ['importo' => -10, 'descrizione' => 'Wise 123', 'controparte' => null])
    && !Classificatore::regolaCorrisponde($regWise, ['importo' => 10, 'descrizione' => 'WISEMAN SRL', 'controparte' => null]));
$regCod = ['id' => 2, 'chiave' => 'MUTUO', 'codice_operazione' => '47//20', 'segno' => -1, 'categoria_id' => 9];
check('regola con codice operazione come restrizione', Classificatore::regolaCorrisponde($regCod, ['importo' => -500, 'descrizione' => 'rata mutuo', 'codice_operazione' => '47//20'])
    && !Classificatore::regolaCorrisponde($regCod, ['importo' => -500, 'descrizione' => 'rata mutuo', 'codice_operazione' => '48//00']));
$m = ['importo' => 10, 'descrizione' => 'Wise Italy Girls', 'controparte' => null];
check('vince la regola più specifica', Classificatore::sceltaRegola([$regWise, ['id' => 3, 'chiave' => 'WISE ITALY', 'codice_operazione' => '', 'segno' => 1, 'categoria_id' => 7]], $m)['id'] === 3);
check('due regole ugualmente specifiche e discordi: nessuna', Classificatore::sceltaRegola([$regWise, ['id' => 4, 'chiave' => 'ROMA', 'codice_operazione' => '', 'segno' => 1, 'categoria_id' => 7]],
    ['importo' => 10, 'descrizione' => 'WISE ROMA', 'controparte' => null]) === null);
check('euristica univoca: commissioni', Classificatore::euristica(['importo' => -1.5, 'descrizione' => 'Commissioni bonifico', 'controparte' => null]) === 'commissioni_banca');
check('euristica: F24 + commissioni = ambigua', Classificatore::euristica(['importo' => -300, 'descrizione' => 'Pagamento delega F24 commissioni', 'controparte' => null]) === null);
check('euristica: segno sbagliato non vale', Classificatore::euristica(['importo' => 1.5, 'descrizione' => 'Commissioni', 'controparte' => null]) === null);
check('euristica: polizza → assicurazioni', Classificatore::euristica(['importo' => -500, 'descrizione' => 'Bonifico a agenzia Rossi snc polizza 123456', 'controparte' => null]) === 'assicurazioni');
check('euristica: erogazione mutuo in entrata', Classificatore::euristica(['importo' => 50000, 'descrizione' => 'Erogazione mutuo', 'controparte' => null]) === 'finanziamenti');

echo "Classificazione automatica e coda Da classificare\n";
check('16 categorie iniziali', (int)$pdo->query("SELECT COUNT(*) FROM {$p}categorie_movimento")->fetchColumn() === 16);
$catId = fn(string $codice) => (int)$pdo->query("SELECT id FROM {$p}categorie_movimento WHERE codice = '$codice'")->fetchColumn();
$pdo->exec("UPDATE {$p}fornitori SET categoria_default_id = " . $catId('commercialista_paghe') . " WHERE id = 1");
$cls = new Classificatore($pdo, $p);
$cls->classifica(null);
$movPer = fn(string $like) => $pdo->query("SELECT * FROM {$p}movimenti_banca WHERE origine = 'estratto_conto' AND descrizione LIKE '%$like%' ORDER BY id LIMIT 1")->fetch();
check('incasso riconciliato → Incassi clienti (fonte fattura)', ($r = $movPer('NR. 67'))['categoria_id'] == $catId('incassi_clienti') && $r['categoria_fonte'] === 'fattura');
check('fornitore con categoria predefinita → Commercialista e paghe', $movPer('STUDIO PAGHE')['categoria_id'] == $catId('commercialista_paghe'));
check('commissioni → euristica (codice_banca)', ($r = $movPer('Commissioni'))['categoria_id'] == $catId('commissioni_banca') && $r['categoria_fonte'] === 'codice_banca');
check('erogazione mutuo → Finanziamenti', $movPer('Erogazione mutuo')['categoria_id'] == $catId('finanziamenti'));
check('Wise e Unindustria non abbinato: da classificare', $movPer('Wise')['classificazione'] === 'da_classificare' && $movPer('UNINDUSTRIA')['classificazione'] === 'da_classificare');
check('avvisi di pagamento fuori dalla coda', (int)$pdo->query("SELECT COUNT(*) FROM {$p}movimenti_banca WHERE origine = 'avviso_pagamento' AND categoria_id IS NOT NULL")->fetchColumn() === 0);

$nuovi = [
    ['data_operazione' => '2026-06-12', 'data_valuta' => '2026-06-12', 'importo' => 800.0, 'descrizione' => 'Wise 2000000002 Italy Boys Soccer 8/06', 'controparte' => null, 'riferimento' => 'W2', 'iban' => 'X'],
    ['data_operazione' => '2026-06-14', 'data_valuta' => '2026-06-14', 'importo' => 500.0, 'descrizione' => 'Wise 2000000003 Italy Girls Soccer 9/06', 'controparte' => null, 'riferimento' => 'W3', 'iban' => 'X'],
    ['data_operazione' => '2026-06-16', 'data_valuta' => '2026-06-16', 'importo' => -300.0, 'descrizione' => 'Pagamento delega F24 commissioni 0001', 'controparte' => null, 'riferimento' => 'F1', 'iban' => 'X'],
];
$pdo->beginTransaction();
$e2 = $ric->importaMovimenti($nuovi, ['banca' => ''], 7);
$cls->classifica($e2['ids']);
$pdo->commit();
check('F24 + commissioni resta da classificare', $movPer('delega F24')['classificazione'] === 'da_classificare');
$daClass = $cls->contaDaClassificare();

echo "Regole apprese\n";
$wise = $movPer('Wise 1944617001');
try { $cls->classificaUtente((int)$wise['id'], $catId('altre_uscite'), [], 7); $e = null; } catch (RuntimeException $e) {}
check('categoria di uscita su un accredito: rifiutata', $e instanceof RuntimeException);
try { $cls->classificaUtente((int)$wise['id'], $catId('altre_entrate'), ['applica_simili' => true, 'chiave' => 'SRL 2026'], 7); $e = null; } catch (RuntimeException $e) {}
check('chiave generica: rifiutata', $e instanceof RuntimeException && strpos($e->getMessage(), 'generica') !== false);
try { $cls->classificaUtente((int)$wise['id'], $catId('altre_entrate'), ['applica_simili' => true, 'chiave' => 'PAYPAL'], 7); $e = null; } catch (RuntimeException $e) {}
check('chiave che non compare nel movimento: rifiutata', $e instanceof RuntimeException);
$r = $cls->classificaUtente((int)$wise['id'], $catId('altre_entrate'), ['applica_simili' => true, 'chiave' => 'wise'], 7);
check('regola "WISE" applicata subito agli altri 2 movimenti Wise', $r['aggiornati'] === 2 && $r['regola_id'] > 0, $r);
check('movimento scelto: fonte utente; simili: fonte regola',
    $movPer('Wise 1944617001')['categoria_fonte'] === 'utente' && $movPer('Wise 2000000002')['categoria_fonte'] === 'regola');
check('coda Da classificare scesa di 3', $cls->contaDaClassificare() === $daClass - 3, [$daClass, $cls->contaDaClassificare()]);
$pdo->beginTransaction();
$e3 = $ric->importaMovimenti([['data_operazione' => '2026-07-01', 'data_valuta' => '2026-07-01', 'importo' => 90.0,
    'descrizione' => 'Wise 2000000009 Refund', 'controparte' => null, 'riferimento' => 'W9', 'iban' => 'X']], ['banca' => ''], 7);
$cls->classifica($e3['ids']);
$pdo->commit();
check('nuovo import: Wise classificato dalla regola', ($r9 = $movPer('Wise 2000000009'))['categoria_fonte'] === 'regola' && $r9['categoria_id'] == $catId('altre_entrate'));
$r = $cls->classificaUtente((int)$r9['id'], $catId('rimborsi'), ['aggiorna_regola' => true], 7);
check('cambio categoria con aggiornamento della regola: anche gli altri movimenti della regola', $r['aggiornati'] === 2
    && $movPer('Wise 2000000002')['categoria_id'] == $catId('rimborsi')
    && (int)$pdo->query("SELECT categoria_id FROM {$p}regole_categoria WHERE chiave = 'WISE'")->fetchColumn() === $catId('rimborsi'));
check('la scelta esplicita dell\'utente non cambia', $movPer('Wise 1944617001')['categoria_id'] == $catId('altre_entrate'));

echo "Proposte da movimenti simili e annullamento\n";
$f24 = $movPer('delega F24');
$cls->classificaUtente((int)$f24['id'], $catId('imposte_tasse'), [], 7);
$pdo->beginTransaction();
$e4 = $ric->importaMovimenti([['data_operazione' => '2026-07-16', 'data_valuta' => '2026-07-16', 'importo' => -410.0,
    'descrizione' => 'Pagamento delega F24 commissioni 0002', 'controparte' => null, 'riferimento' => 'F2', 'iban' => 'X']], ['banca' => ''], 7);
$cls->classifica($e4['ids']);
$pdo->commit();
$f24b = $movPer('commissioni 0002');
check('movimento simile: resta da classificare ma con proposta "Imposte"', $f24b['classificazione'] === 'da_classificare'
    && (int)$f24b['categoria_proposta_id'] === $catId('imposte_tasse') && strpos((string)$f24b['proposta_motivo'], 'simile') !== false, $f24b);
$idEuro = (int)$movPer('NR. 67')['id'];
$ric->annulla($idEuro);
$cls->classifica([$idEuro]);
check('riconciliazione annullata: la categoria da fattura si toglie', $ric->movimento($idEuro)['classificazione'] === 'da_classificare');
$lista = $ric->lista(['origine' => 'estratto_conto', 'classificazione' => 'da_classificare']);
check('lista coda con chiave suggerita', $lista && isset($lista[0]['chiave_suggerita']));
check('filtro abbinabili esclude Wise/commissioni/mutuo', !array_filter($ric->lista(['abbinabili' => true]), fn($x) => stripos($x['descrizione'], 'Wise 1944') !== false));

echo "Dati per i grafici\n";
$cats = [['id' => 1, 'nome' => 'Incassi', 'tipo' => 'entrata', 'colore' => '#10B981'], ['id' => 2, 'nome' => 'Banca', 'tipo' => 'uscita', 'colore' => '#94A3B8']];
$agg = CategorieMovimenti::aggrega([
    ['data' => '2026-01-10', 'importo' => 1000, 'categoria_id' => 1], ['data' => '2026-01-20', 'importo' => -1.5, 'categoria_id' => 2],
    ['data' => '2026-03-05', 'importo' => 200, 'categoria_id' => null], ['data' => '2026-03-06', 'importo' => -50, 'categoria_id' => null],
], $cats, '2026-01-01', '2026-03-31');
check('tre mesi anche se febbraio è vuoto', count($agg['mesi']) === 3 && $agg['mesi'][1]['entrate'] === 0.0);
check('gennaio: entrate 1000, uscite 1,50, netto 998,50', $agg['mesi'][0]['entrate'] === 1000.0 && $agg['mesi'][0]['uscite'] === 1.5 && $agg['mesi'][0]['netto'] === 998.5);
check('entrate per categoria ordinate, con "Non classificato"', $agg['entrate']['categorie'][0]['nome'] === 'Incassi' && $agg['entrate']['categorie'][1]['id'] === null
    && $agg['entrate']['categorie'][1]['nome'] === 'Non classificato' && $agg['entrate']['totale'] === 1200.0);
check('totali', $agg['totali'] === ['entrate' => 1200.0, 'uscite' => 51.5, 'netto' => 1148.5], $agg['totali']);
$stat = (new CategorieMovimenti($pdo, $p))->statistiche('2026-01-01', '2026-12-31');
$atteso = (float)$pdo->query("SELECT SUM(importo) FROM {$p}movimenti_banca WHERE origine = 'estratto_conto' AND data_valuta BETWEEN '2026-01-01' AND '2026-12-31'")->fetchColumn();
check('statistiche dal DB: netto = somma dei movimenti bancari (avvisi esclusi)', abs($stat['totali']['netto'] - $atteso) < 0.01 && count($stat['mesi']) === 12, [$stat['totali'], $atteso]);
$nc = array_values(array_filter($stat['entrate']['categorie'], fn($c) => $c['id'] === null));
check('quota "Non classificato" nelle entrate', $nc && $nc[0]['numero'] >= 1);

// ═══ 3c. Correzioni della revisione (un caso per punto) ═══════
echo "Correzioni della revisione\n";
$mv = fn(string $ref, float $imp, string $data, string $descr, ?string $cp = null, string $iban = 'IBX', string $cod = '48//00') =>
    ['data_operazione' => $data, 'data_valuta' => $data, 'importo' => $imp, 'descrizione' => $descr, 'controparte' => $cp,
     'riferimento' => $ref, 'iban' => $iban, 'codice_operazione' => $cod];
$importa = function (array $movs, array $conto = ['iban' => 'IBX']) use ($pdo, &$ric, $cls) {
    $pdo->beginTransaction();
    $e = $ric->importaMovimenti($movs, $conto, 7);
    $cls->classifica($e['ids']);
    $pdo->commit();
    return $e;
};
$idPer = fn(string $rif) => (int)$pdo->query("SELECT id FROM {$p}movimenti_banca WHERE riferimento_banca = '$rif'")->fetchColumn();
$fatt = function (int $id, string $num, string $data, int $cli, float $tot, string $stato = 'emessa', ?string $scad = null) use ($ins, $p) {
    $ins("INSERT INTO {$p}fatture (id, numero_fattura, data_emissione, cliente_id, imponibile, importo_totale, stato, data_scadenza) VALUES (?,?,?,?,?,?,?,?)",
        [$id, $num, $data, $cli, round($tot / 1.22, 2), $tot, $stato, $scad]);
};
foreach ([[20, 'Gamma Impianti Srl'], [21, 'Delta Costruzioni Spa'], [22, 'Omega Logistica Srl'], [23, 'Sigma Studio Associato'], [24, 'Zeta Consulenze Srl']] as $c) {
    $ins("INSERT INTO {$p}clienti (id, ragione_sociale) VALUES (?, ?)", $c);
}
// Istanza nuova: il Riconciliatore tiene in memoria le anagrafiche per tutta la richiesta
$ric = new Riconciliatore($pdo, $p, $dopo);

// #3 hash con data e importo; hash della prima versione riconosciuto
$h = fn($d) => EstrattoContoParser::conHash([$mv('FISSO', 10.0, $d, 'x')])[0]['hash_riga'];
check('#3 NtryRef fisso: date diverse, hash diversi', $h('2026-01-05') !== $h('2026-01-06'));
$ins("INSERT INTO {$p}movimenti_banca (data_operazione, data_valuta, importo, descrizione, hash_riga, abbinabile) VALUES (?,?,?,?,?,0)",
    ['2025-01-01', '2025-01-01', -1.0, 'vecchio', hash('sha256', 'ref|IBX|VECCHIO')]);
$e = $importa([$mv('VECCHIO', -1.0, '2025-01-01', 'vecchio')]);
check('#3 movimento importato con l\'hash della prima versione: già presente', $e['gia_presenti'] === 1 && $e['nuovi'] === 0);

// #15 DOCTYPE, #16 codice dal dominio ISO
try { EstrattoContoParser::parseXmlCbi('<?xml version="1.0"?><!DOCTYPE x [<!ENTITY a SYSTEM "file:///etc/hosts">]><x>&a;</x>'); $e = null; } catch (RuntimeException $e) {}
check('#15 XML con DOCTYPE rifiutato', $e instanceof RuntimeException && strpos($e->getMessage(), 'DOCTYPE') !== false);
$domn = str_replace('<ns5:Prtry><ns5:Cd>48//00</ns5:Cd></ns5:Prtry>',
    '<ns5:Domn><ns5:Cd>PMNT</ns5:Cd><ns5:Fmly><ns5:Cd>RCDT</ns5:Cd><ns5:SubFmlyCd>ESCT</ns5:SubFmlyCd></ns5:Fmly></ns5:Domn>',
    ntry('D1', 10, 'CRDT', '2026-01-01', 'x'));
check('#16 codice operazione dal dominio ISO se manca il proprietario', EstrattoContoParser::parseXmlCbi(cbi([$domn], 0, 10))['movimenti'][0]['codice_operazione'] === 'PMNT-RCDT-ESCT'
    && EstrattoContoParser::parseXmlCbi(cbi([ntry('D2', 10, 'CRDT', '2026-01-01', 'x')], 0, 10))['movimenti'][0]['codice_operazione'] === '48//00');

// #7 subset-sum fermo a maxSoluzioni, #11 note di credito, #8 limite di elementi
$s = RiconciliazioneMatch::subsetSum([['id' => 'a', 'opzioni' => [100]], ['id' => 'b', 'opzioni' => [100]], ['id' => 'c', 'opzioni' => [100]]], 100, 2);
check('#7 ricerca fermata a maxSoluzioni = troncata (niente automatico)', count($s['soluzioni']) === 2 && $s['troncato']);
check('#7 ricerca completa con una soluzione: non troncata', !RiconciliazioneMatch::subsetSum([['id' => 'a', 'opzioni' => [100]], ['id' => 'b', 'opzioni' => [250]]], 100, 2)['troncato']);
$s = RiconciliazioneMatch::subsetSum([['id' => 'f', 'opzioni' => [100000]], ['id' => 'nc', 'opzioni' => [-20000]]], 80000);
check('#11 fattura − nota di credito = importo', count($s['soluzioni']) === 1 && ($s['soluzioni'][0]['nc'] ?? 0) === -20000 && !$s['troncato']);
check('#11 nota di credito da sola: mai', !RiconciliazioneMatch::subsetSum([['id' => 'nc', 'opzioni' => [-20000]]], 20000)['soluzioni']);
$cinque = array_map(fn($i) => ['id' => $i, 'opzioni' => [100]], range(1, 5));
check('#8 al massimo 4 documenti per combinazione', !RiconciliazioneMatch::subsetSum($cinque, 500, 3, 200000, 4)['soluzioni']);

// #9 anagrafica: parola intera, ambiguità
$r9 = fn(array $nomi) => array_map(fn($i, $n) => ['id' => $i + 1, 'partita_iva' => null, 'codice_fiscale' => null, 'ragione_sociale' => $n], array_keys($nomi), $nomi);
check('#9 parola intera: "Rossini" non è "Rossi"', AnagraficaMatcher::trovaTra($r9(['Rossi Srl', 'Rossini Spa']), null, null, 'BONIFICO DA ROSSINI SPA') === 2
    && AnagraficaMatcher::trovaTra($r9(['Rossi']), null, null, 'BONIFICO DA ROSSINI') === null);
check('#9 due clienti diversi nel testo: ambiguo (null)', AnagraficaMatcher::trovaTra($r9(['Alfa Servizi', 'Beta Consulting']), null, null, 'ALFA SERVIZI PER BETA CONSULTING') === null);
check('#9 nome più lungo che contiene l\'altro: vale il più lungo', AnagraficaMatcher::trovaTra($r9(['Alfa', 'Alfa Servizi']), null, null, 'BONIFICO ALFA SERVIZI') === 2);

// #13 stato precedente, #14 riconciliazioni del record, #10 categoria dopo riconciliazione manuale
$fatt(100, '90/001', '2026-02-01', 20, 500, 'inviata', '2020-01-01');
$e = $importa([$mv('C1', 500.0, '2026-03-01', 'Bonifico a vs favore GAMMA IMPIANTI FATT. 90/001 DEL 01/02/2026')]);
check('#13 pagata dal bonifico', $stato(100) === 'pagata' && Riconciliatore::riconciliazioniDi($pdo, $p, 'fattura', 100) === 1);
$ric->annulla($idPer('C1'));
check('#13 annullando torna "inviata" (non "scaduta" dedotta dalla scadenza)', $stato(100) === 'inviata');
check('#14 nessuna riconciliazione dopo l\'annullamento', Riconciliatore::riconciliazioniDi($pdo, $p, 'fattura', 100) === 0);
$cls->salvaRegola('GAMMA IMPIANTI', '', 1, $catId('altre_entrate'), 7);
$cls->classifica([$idPer('C1')]);
check('#10 prima: classificato dalla regola', $ric->movimento($idPer('C1'))['categoria_fonte'] === 'regola');
$ric->registra($idPer('C1'), [['tipo' => 'fattura', 'id' => 100, 'importo' => null]], 'manuale', 7);
$cls->classifica([$idPer('C1')], true);
check('#10 dopo la riconciliazione manuale: Incassi clienti dalla fattura', ($x = $ric->movimento($idPer('C1')))['categoria_fonte'] === 'fattura' && (int)$x['categoria_id'] === $catId('incassi_clienti'));

// #11 nota di credito dello stesso cliente nel caso (d); nota di credito da sola rifiutata
$fatt(101, '91/001', '2026-02-10', 21, 1000);
$fatt(102, '5/001', '2026-02-20', 21, -200);
$e = $importa([$mv('C2', 800.0, '2026-03-05', 'Bonifico a vs favore DELTA COSTRUZIONI saldo')]);
check('#11 fattura 1000 − NC 200 = 800: abbinato in automatico', $stato(101) === 'pagata' && $stato(102) === 'pagata'
    && abs((float)$pdo->query("SELECT SUM(importo) FROM {$p}riconciliazioni WHERE movimento_id = " . $idPer('C2'))->fetchColumn() - 800) < 0.01);
$fatt(103, '6/001', '2026-02-21', 21, -50);
$importa([$mv('C3', 50.0, '2026-03-06', 'Bonifico generico', null)]);
try { $ric->registra($idPer('C3'), [['tipo' => 'fattura', 'id' => 103, 'importo' => -50]], 'manuale', 7); $e = null; } catch (RuntimeException $e) {}
check('#11 nota di credito da sola: rifiutata', $e instanceof RuntimeException);
check('#11 la NC è tra i documenti aperti (per la scelta manuale)', (bool)array_filter($ric->documenti()->aperti('fattura'), fn($d) => $d['numero'] === '6/001' && $d['residuo'] < 0));

// #8 più di 20 fatture aperte, sottoinsiemi oltre 4 documenti
for ($i = 0; $i < 21; $i++) $fatt(200 + $i, (300 + $i) . '/001', '2026-01-15', 22, 100);
$fatt(230, '330/001', '2026-01-15', 22, 333);
$importa([$mv('C4', 333.0, '2026-03-10', 'Bonifico a vs favore OMEGA LOGISTICA')]);
check('#8 cliente con più di 20 fatture aperte: niente automatico', $stato(230) !== 'pagata');
for ($i = 0; $i < 5; $i++) $fatt(240 + $i, (400 + $i) . '/001', '2026-01-20', 23, 100);
$importa([$mv('C5', 500.0, '2026-03-11', 'Bonifico a vs favore SIGMA STUDIO ASSOCIATO')]);
check('#8 servirebbero 5 fatture (> 4): niente automatico', $stato(240) !== 'pagata');

// #1 avviso annullato; #2 unicità e coerenza dell'aggancio
$fatt(110, '95/001', '2026-03-01', 20, 700);
$fatt(111, '96/001', '2026-03-01', 20, 650);
$a1 = $ric->registraAvviso(['data' => '2026-04-10', 'importo' => 700, 'descrizione' => 'Avviso 95', 'file_nome' => 'avv1.pdf'], [['tipo' => 'fattura', 'id' => 110, 'importo' => null]], 7);
$ric->annulla($a1);
check('#1 avviso annullato: stato ignorato', $ric->movimento($a1)['stato'] === 'ignorato' && $stato(110) === 'emessa');
$importa([$mv('A1', 700.0, '2026-04-11', 'Bonifico a vs favore GAMMA IMPIANTI')]);
check('#1 l\'avviso annullato non si aggancia a un accredito', (int)$ric->movimento($idPer('A1'))['avviso_id'] === 0);
$a2 = $ric->registraAvviso(['data' => '2026-05-10', 'importo' => 650, 'descrizione' => 'Avviso 96', 'file_nome' => 'avv2.pdf'], [['tipo' => 'fattura', 'id' => 111, 'importo' => null]], 7);
$importa([$mv('A2', 650.0, '2026-05-11', 'Bonifico a vs favore GAMMA IMPIANTI'), $mv('A3', 650.0, '2026-05-12', 'Bonifico a vs favore GAMMA IMPIANTI')]);
check('#2 due accrediti candidati per lo stesso avviso: nessun aggancio automatico',
    !(int)$ric->movimento($idPer('A2'))['avviso_id'] && !(int)$ric->movimento($idPer('A3'))['avviso_id']);
check('#2 ...ma l\'avviso è tra le proposte', ($ric->proposte($idPer('A2'))[0]['tipo'] ?? '') === 'avviso');
$fatt(112, '97/001', '2026-03-01', 20, 900);
$fatt(113, '98/001', '2026-03-01', 24, 900);
$ric->registraAvviso(['data' => '2026-06-10', 'importo' => 900, 'descrizione' => 'Avviso 97', 'file_nome' => 'avv3.pdf'], [['tipo' => 'fattura', 'id' => 112, 'importo' => null]], 7);
$importa([$mv('A4', 900.0, '2026-06-11', 'Bonifico a vs favore ZETA CONSULENZE FATT. 98/001 DEL 01/03/2026')]);
check('#2 la causale cita un\'altra fattura: nessun aggancio all\'avviso', !(int)$ric->movimento($idPer('A4'))['avviso_id']);
$importa([$mv('A5', 900.0, '2026-06-12', 'Bonifico a vs favore GAMMA IMPIANTI')]);
check('#2 unico, stesso cliente: agganciato', (int)$ric->movimento($idPer('A5'))['avviso_id'] > 0);

// #6 reimport parziale dello stesso avviso
$fatt(120, '120/001', '2026-03-01', 24, 500);
$fatt(121, '121/001', '2026-03-01', 24, 700);
$x1 = $ric->registraAvviso(['data' => '2026-07-01', 'importo' => 1200, 'descrizione' => 'Avviso 120', 'file_nome' => 'avv4.pdf'], [['tipo' => 'fattura', 'id' => 120, 'importo' => null]], 7);
$x2 = $ric->registraAvviso(['data' => '2026-07-01', 'importo' => 1200, 'descrizione' => 'Avviso 120, 121', 'file_nome' => 'avv4.pdf'], [['tipo' => 'fattura', 'id' => 121, 'importo' => null]], 7);
check('#6 stesso file/data/totale: stesso avviso, fatture aggiunte', $x1 === $x2 && $stato(121) === 'pagata'
    && abs((float)$pdo->query("SELECT SUM(importo) FROM {$p}riconciliazioni WHERE movimento_id = $x1")->fetchColumn() - 1200) < 0.01
    && abs((float)$ric->movimento($x1)['importo'] - 1200) < 0.01);

// #5 voci dell'avviso con righe già pagate
$fatt(130, '130/001', '2026-03-01', 24, 300, 'pagata');
$fatt(131, '130/001', '2026-03-01', 24, 200);
$v = $ric->vociAvviso([['id' => 130, 'numero_fattura' => '130/001', 'cliente_id' => 24, 'stato' => 'pagata'], ['id' => 131, 'numero_fattura' => '130/001', 'cliente_id' => 24, 'stato' => 'emessa']], 500);
check('#5 documento con righe già pagate: residuo (null), non l\'importo dell\'avviso', count($v) === 1 && $v[0]['id'] === 131 && $v[0]['importo'] === null);
$fatt(140, '140/001', '2026-03-01', 24, 400);
$v = $ric->vociAvviso([['id' => 140, 'numero_fattura' => '140/001', 'cliente_id' => 24, 'stato' => 'emessa']], 402);
check('#5 importo dell\'avviso limitato al residuo', $v[0]['importo'] === 400.0);

// #4 regole senza chiave vietate, peso; #17 chiave lunga
check('#4 regola solo per codice operazione: non vale', !Classificatore::regolaCorrisponde(['chiave' => '', 'codice_operazione' => '47//20', 'segno' => -1],
    ['importo' => -5, 'descrizione' => 'x', 'codice_operazione' => '47//20']));
check('#4 peso = lunghezza chiave (+1 col codice)', Classificatore::sceltaRegola([
    ['id' => 1, 'chiave' => 'MUTUO', 'codice_operazione' => '47//20', 'segno' => -1, 'categoria_id' => 1],
    ['id' => 2, 'chiave' => 'MUTUO CHIRO', 'codice_operazione' => '', 'segno' => -1, 'categoria_id' => 2]],
    ['importo' => -5, 'descrizione' => 'rata mutuo chiro', 'codice_operazione' => '47//20'])['id'] === 2);
try { $cls->salvaRegola('12/2026', '47//20', -1, $catId('altre_uscite'), 7); $e = null; } catch (RuntimeException $e) {}
check('#4 salvare una regola senza chiave valida: rifiutato', $e instanceof RuntimeException);
$k = Classificatore::preparaChiave(str_repeat('PAROLA ', 40));
check('#17 chiave oltre 150 caratteri: tagliata a parola intera', strlen($k) <= 150 && substr($k, -6) === 'PAROLA');

// #18 cambio di categoria di una regola: aggiorna i movimenti della regola, non le scelte dell'utente; permessi
$idWise = (int)$pdo->query("SELECT id FROM {$p}regole_categoria WHERE chiave = 'WISE'")->fetchColumn();
$cls->salvaRegola('WISE', '', 1, $catId('altre_entrate'), 7, false);
$cls->applicaRegola($idWise);
check('#18 movimenti della regola aggiornati, scelta dell\'utente intatta', $movPer('Wise 2000000002')['categoria_id'] == $catId('altre_entrate')
    && $movPer('Wise 1944617001')['categoria_fonte'] === 'utente');
$r = $cls->classificaUtente((int)$movPer('Wise 2000000002')['id'], $catId('rimborsi'), ['aggiorna_regola' => true], 8, false);
check('permessi: un operatore non modifica la regola di un altro, ne crea una più specifica',
    (int)$pdo->query("SELECT categoria_id FROM {$p}regole_categoria WHERE id = $idWise")->fetchColumn() === $catId('altre_entrate')
    && $r['regola_id'] !== $idWise && $pdo->query("SELECT chiave FROM {$p}regole_categoria WHERE id = " . (int)$r['regola_id'])->fetchColumn() === 'WISE ITALY');

// #20 stesso estratto in XML e poi in PDF
$importa([$mv('Z1', 123.45, '2026-08-01', 'Bonifico a vs favore ZETA CONSULENZE causale prova servizi')], ['iban' => 'IBZ']);
$pdf = $importa([['data_operazione' => '2026-08-01', 'data_valuta' => '2026-08-01', 'importo' => 123.45,
    'descrizione' => 'BONIFICO A VS FAVORE ZETA CONSULENZE CAUSALE PROVA', 'controparte' => null]], ['iban' => '']);
check('#20 stesso movimento dal PDF dopo l\'XML: non duplicato, con avviso', $pdf['altro_formato'] === 1 && $pdf['nuovi'] === 0 && $pdf['avvisi']);

// Estratto carta di credito (testo come lo ricostruisce pdf.js: a capo per riga, acquisto in valuta su più righe)
echo "Estratto carta\n";
$paginaCarta = "DATA ACQUISTO  DATA REGISTR.  DESCRIZIONE DELLE OPERAZIONI  IMPORTO IN EURO\n"
    . " 01/07/2026  02/07/2026  STUDIO PAGHE ALFA ROMA ITA  150,00\n"
    . "02/07/2026  03/07/2026  \nSOFTWARE BETA SAN FRANCISCO CA\n 20,00 USD  \n18,50\n"
    . "03/07/2026  03/07/2026  COMMISSIONE DI CONVERSIONE VALUTARIA  0,30\n"
    . "04/07/2026  06/07/2026  NEGOZIO GAMMA MILANO ITA  -40,00\n"
    . " TOTALE OPERAZIONI  128,80\n Carta Numero:  1234 **** **** 9876\nNumia S.p.A.";
$carta = EstrattoContoParser::parseEstrattoCarta([$paginaCarta]);
check('carta: 4 righe, totale che torna, nessun avviso', count($carta['movimenti']) === 4 && !$carta['avvisi'], $carta['avvisi']);
check('carta: spese negative, rimborso positivo', array_column($carta['movimenti'], 'importo') === [-150.0, -18.5, -0.3, 40.0]);
check('carta: valuta estera nella descrizione', $carta['movimenti'][1]['descrizione'] === 'SOFTWARE BETA SAN FRANCISCO CA (20,00 USD)');
check('carta: data acquisto e data registrazione', $carta['movimenti'][1]['data_operazione'] === '2026-07-02' && $carta['movimenti'][1]['data_valuta'] === '2026-07-03');
check('carta: banca dal numero mascherato', $carta['banca'] === 'CartaBCC 1234 **** 9876', $carta['banca']);
check('carta: riconosciuta anche se caricata come estratto conto', EstrattoContoParser::eEstrattoCarta($paginaCarta)
    && !EstrattoContoParser::eEstrattoCarta("DATA OPERAZIONE DATA VALUTA DESCRIZIONE\n01/07/2026 01/07/2026 ADDEBITO CARTA DI CREDITO NUMIA 128,80"));
$sbagliata = EstrattoContoParser::parseEstrattoCarta([str_replace('128,80', '999,00', $paginaCarta)]);
check('carta: totale diverso → avviso', count($sbagliata['avvisi']) === 1);
$pdo->beginTransaction();
$ec = $ric->importaMovimenti($carta['movimenti'], ['banca' => $carta['banca'], 'iban' => '', 'file_nome' => 'carta.pdf'], 7, 'estratto_carta');
$pdo->commit();
$origini = $pdo->query("SELECT DISTINCT origine FROM {$p}movimenti_banca WHERE file_nome = 'carta.pdf'")->fetchAll(PDO::FETCH_COLUMN);
check('carta: movimenti salvati con origine estratto_carta', $ec['nuovi'] === 4 && $origini === ['estratto_carta'], [$ec['nuovi'], $origini]);
$fornitoreCarta = (int)$pdo->query("SELECT abbinabile FROM {$p}movimenti_banca WHERE file_nome = 'carta.pdf' AND importo = -150")->fetchColumn();
check('carta: la spesa di un fornitore in anagrafica va in coda da riconciliare', $fornitoreCarta === 1);
$pdo->beginTransaction();
$ec2 = $ric->importaMovimenti($carta['movimenti'], ['banca' => $carta['banca'], 'iban' => '', 'file_nome' => 'carta.pdf'], 7, 'estratto_carta');
$pdo->commit();
check('carta: reimport senza doppioni', $ec2['nuovi'] === 0 && $ec2['gia_presenti'] === 4);
$daClassificare = (int)$pdo->query("SELECT COUNT(*) FROM {$p}movimenti_banca WHERE file_nome = 'carta.pdf' AND categoria_id IS NOT NULL")->fetchColumn();
$cls->classifica(null);
check('carta: fuori dalle categorie (sul conto c\'è già l\'addebito mensile)', $daClassificare === 0
    && (int)$pdo->query("SELECT COUNT(*) FROM {$p}movimenti_banca WHERE file_nome = 'carta.pdf' AND categoria_id IS NOT NULL")->fetchColumn() === 0);

// #12 lista senza una query per movimento
$n = (int)$pdo->query("SELECT COUNT(*) FROM {$p}movimenti_banca")->fetchColumn();
$pdo->n = 0;
$lista = $ric->lista([]);
check("#12 lista di $n movimenti con poche query ({$pdo->n})", count($lista) === $n && $pdo->n <= 6);

echo "Riprova abbinamento dopo l'import delle fatture\n";
$pdo->beginTransaction();
$er = $ric->importaMovimenti([['data_operazione' => '2026-08-20', 'data_valuta' => '2026-08-20', 'importo' => -915.0,
    'descrizione' => 'Bonifico a favore Tipografia Beta Srl saldo', 'controparte' => 'Tipografia Beta Srl', 'riferimento' => 'RB1', 'iban' => 'X']],
    ['banca' => ''], 7);
$pdo->commit();
$idBeta = $er['ids'][0];
check('fornitore non ancora in anagrafica: movimento senza aggancio', (int)$ric->movimento($idBeta)['abbinabile'] === 0);
$ins("INSERT INTO {$p}fornitori (id, ragione_sociale) VALUES (?, ?)", [9, 'Tipografia Beta S.r.l.']);
$ins("INSERT INTO {$p}fatture_passive (id, fornitore_id, numero, data_emissione, imponibile, importo_totale, data_scadenza) VALUES (?,?,?,?,?,?,?)",
    [9, 9, 'B-7', '2026-07-31', 750, 915, '2026-08-31']);
$pdo->beginTransaction();
$rb = $ric->riabbina(7);
$pdo->commit();
check('riabbina: acquista l\'aggancio e salda la fattura passiva', $rb['nuovi_agganci'] >= 1 && in_array($idBeta, $rb['ids'], true)
    && $ric->movimento($idBeta)['stato'] === 'riconciliato' && (int)$ric->movimento($idBeta)['abbinabile'] === 1
    && $pdo->query("SELECT stato FROM {$p}fatture_passive WHERE id = 9")->fetchColumn() === 'pagata', $rb);
$pdo->beginTransaction();
$rb2 = $ric->riabbina(7);
$pdo->commit();
check('riabbina non rifà gli abbinamenti annullati a mano', !in_array($idEuro, $rb['ids'], true)
    && (int)$ric->movimento($idEuro)['abbinamento_annullato'] === 1 && $ric->movimento($idEuro)['stato'] === 'da_riconciliare');
check('riabbina ripetuto: niente di nuovo', $rb2['abbinati'] === 0 && $rb2['nuovi_agganci'] === 0, $rb2);

// ═══ 4. Estratto conto vero (facoltativo) ═══════════════════
$file = getenv('CBI_FILE') ?: '';
if ($file !== '' && is_readable($file)) {
    echo "Estratto CBI reale ($file) — solo conteggi\n";
    $t0 = microtime(true);
    $vero = EstrattoContoParser::parseXmlCbi((string)file_get_contents($file));
    $cr = count(array_filter($vero['movimenti'], fn($m) => $m['importo'] > 0));
    $conRif = count(array_filter($vero['movimenti'], fn($m) => $m['importo'] > 0 && RiconciliazioneMatch::estraiRiferimenti($m['descrizione'])));
    echo '  movimenti: ' . count($vero['movimenti']) . ", accrediti: $cr, accrediti con numero fattura: $conRif, avvisi: " . count($vero['avvisi'])
        . ', ' . round((microtime(true) - $t0) * 1000) . " ms\n";
}

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
