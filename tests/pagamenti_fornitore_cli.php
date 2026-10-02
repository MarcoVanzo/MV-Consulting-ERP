<?php
/**
 * Prova da riga di comando dell'abbinamento per fornitore (PagamentiFornitore): bonifici prima della fattura,
 * più bonifici su più fatture, nomi in banca imparati, differenza chiusa, automatico solo se esatto.
 *
 *   php tests/pagamenti_fornitore_cli.php
 *
 * SQLite in memoria, dati inventati.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/Riconciliatore.php';
require_once __DIR__ . '/../api/Shared/PagamentiFornitore.php';

$ok = 0;
$ko = 0;
function check(string $nome, bool $cond, $dettaglio = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok   $nome\n"; return; }
    $ko++;
    echo "  FAIL $nome" . ($dettaglio !== null ? ' → ' . json_encode($dettaglio, JSON_UNESCAPED_UNICODE) : '') . "\n";
}

echo "Nome in banca\n";
check('bonifico: 24 caratteri dopo «*»', PagamentiFornitore::nomeInBanca('*HOTEL BETA MILANO       Saldo viaggio ID.BON:123', '') === 'HOTEL BETA MILANO');
check('bonifico tagliato a metà parola', PagamentiFornitore::nomeInBanca('*ROSSI COSTRUZIONI EDILIZSaldo FT 15/26', '') === 'ROSSI COSTRUZIONI EDILIZ');
check('istantaneo: nome dopo BEN', PagamentiFornitore::nomeInBanca('*INSTANT DEL 26/07/2026 ORE 21:23 ID. 0874IT BEN PULLMAN VERDI', '') === 'PULLMAN VERDI');
check('senza nome', PagamentiFornitore::nomeInBanca(null, 'Imposte e Tasse:Delega Unificata') === '');
check('parole distintive', PagamentiFornitore::paroleDistintive('Hotel Gamma Srl') === ['GAMMA']);
$alias = [['fornitore_id' => 1, 'alias' => 'HOTEL BETA MILANO'], ['fornitore_id' => 2, 'alias' => 'ROSSI COSTRUZIONI EDILI']];
check('alias all\'inizio di parola', PagamentiFornitore::daAlias($alias, 'Bonifico *ROSSI COSTRUZIONI EDILISaldo FT') === 2);
check('alias non dentro un\'altra parola', PagamentiFornitore::daAlias($alias, 'XHOTEL BETA MILANO') === null);

$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$p = 'mv_';
foreach ([
    "CREATE TABLE {$p}clienti (id INTEGER PRIMARY KEY, ragione_sociale TEXT, partita_iva TEXT, codice_fiscale TEXT)",
    "CREATE TABLE {$p}sottoclienti (id INTEGER PRIMARY KEY, cliente_id INT, nome TEXT)",
    "CREATE TABLE {$p}fornitori (id INTEGER PRIMARY KEY, ragione_sociale TEXT, partita_iva TEXT, codice_fiscale TEXT, deleted_at TEXT)",
    "CREATE TABLE {$p}fornitori_alias (id INTEGER PRIMARY KEY, fornitore_id INT NOT NULL, alias TEXT NOT NULL UNIQUE, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE {$p}fatture (id INTEGER PRIMARY KEY, numero_fattura TEXT, data_emissione TEXT, cliente_id INT, sottocliente_id INT, incarico_id INT,
        imponibile REAL, importo_totale REAL, stato TEXT DEFAULT 'emessa', data_scadenza TEXT, data_pagamento TEXT, metodo_pagamento TEXT)",
    "CREATE TABLE {$p}fatture_passive (id INTEGER PRIMARY KEY, fornitore_id INT, incarico_id INT, numero TEXT, data_emissione TEXT,
        imponibile REAL, ritenuta REAL DEFAULT 0, importo_totale REAL, stato TEXT DEFAULT 'da_pagare', data_scadenza TEXT, data_pagamento TEXT, note TEXT)",
    "CREATE TABLE {$p}movimenti_banca (id INTEGER PRIMARY KEY, banca TEXT DEFAULT '', iban TEXT, riferimento_banca TEXT, codice_operazione TEXT,
        data_operazione TEXT NOT NULL, data_valuta TEXT, importo REAL NOT NULL, descrizione TEXT, controparte TEXT, hash_riga TEXT NOT NULL UNIQUE,
        stato TEXT NOT NULL DEFAULT 'da_riconciliare', origine TEXT NOT NULL DEFAULT 'estratto_conto', avviso_id INT, file_nome TEXT,
        abbinabile INT NOT NULL DEFAULT 1, segno_incerto INT NOT NULL DEFAULT 0, abbinamento_annullato INT NOT NULL DEFAULT 0)",
    "CREATE TABLE {$p}riconciliazioni (id INTEGER PRIMARY KEY, movimento_id INT NOT NULL, tipo TEXT NOT NULL, documento_id INT NOT NULL,
        importo REAL NOT NULL, metodo TEXT NOT NULL, created_by INT, stato_precedente TEXT, created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
] as $sql) $pdo->exec($sql);

$ins = fn(string $sql, array $v) => $pdo->prepare($sql)->execute($v);
foreach ([[1, 'Alfa Hospitality S.p.A.'], [2, 'Gamma Pullman Srl'], [3, 'Delta Viaggi Srl'], [4, 'Omega Trasporti Srl']] as $f) {
    $ins("INSERT INTO {$p}fornitori (id, ragione_sociale) VALUES (?, ?)", $f);
}
$fattura = fn(int $id, int $forn, string $num, string $data, float $tot) =>
    $ins("INSERT INTO {$p}fatture_passive (id, fornitore_id, numero, data_emissione, imponibile, importo_totale, data_scadenza) VALUES (?,?,?,?,?,?,?)",
        [$id, $forn, $num, $data, $tot, $tot, date('Y-m-d', strtotime("$data +30 days"))]);
$mov = fn(int $id, string $data, float $imp, string $contro) =>
    $ins("INSERT INTO {$p}movimenti_banca (id, data_operazione, data_valuta, importo, descrizione, controparte, hash_riga) VALUES (?,?,?,?,?,?,?)",
        [$id, $data, $data, $imp, 'Bonifico tramite Internet Banking ' . $contro, $contro, "h$id"]);

// Alfa: l'hotel fattura dopo il soggiorno, i bonifici partono prima e col marchio dell'hotel
$fattura(1, 1, 'A-10', '2026-07-10', 1000);
$fattura(2, 1, 'A-11', '2026-07-20', 500);
$mov(1, '2026-06-01', -600, '*HOTEL BETA MILANO       Acconto viaggio luglio ID.BON:1');
$mov(2, '2026-07-15', -900, '*HOTEL BETA MILANO       Saldo viaggio luglio ID.BON:2');
// Gamma: si riconosce dalla parola «GAMMA», manca una piccola differenza (40 €)
$fattura(3, 2, 'G-1', '2026-07-01', 1000);
$fattura(4, 2, 'G-2', '2026-07-05', 1000);
$mov(3, '2026-07-02', -1960, '*GAMMA BUS               Saldo viaggi ID.BON:3');
// Delta: nome intero in banca, i bonifici fanno esattamente le fatture → automatico
$fattura(5, 3, 'D-1', '2026-08-01', 300);
$fattura(6, 3, 'D-2', '2026-08-02', 200);
$mov(4, '2026-07-20', -250, '*DELTA VIAGGI SRL        Acconto ID.BON:4');
$mov(5, '2026-08-10', -250, '*DELTA VIAGGI SRL        Saldo ID.BON:5');
// Omega: bonifico più grande delle fatture
$fattura(7, 4, 'O-1', '2026-09-01', 100);
$mov(6, '2026-09-05', -150, '*OMEGA TRASPORTI SRL     Saldo ID.BON:6');
// Un bonifico vecchio di un anno non entra nella finestra di Delta
$mov(7, '2025-06-01', -500, '*DELTA VIAGGI SRL        Vecchio ID.BON:7');

$ric = new Riconciliatore($pdo, $p);
$pf = new PagamentiFornitore($pdo, $p, $ric);
$stato = fn(int $id) => $pdo->query("SELECT stato FROM {$p}fatture_passive WHERE id = $id")->fetchColumn();
$movStato = fn(int $id) => $pdo->query("SELECT stato FROM {$p}movimenti_banca WHERE id = $id")->fetchColumn();
$gruppo = fn(array $r, int $f) => current(array_filter($r['fornitori'], fn($g) => $g['fornitore_id'] === $f));

echo "Riepilogo\n";
$r = $pf->riepilogo();
$alfa = $gruppo($r, 1);
check('Alfa: nessun bonifico riconosciuto (nome in banca diverso)', $alfa && !$alfa['movimenti'], $alfa);
check('i bonifici di Alfa sono tra i liberi', count(array_filter($r['liberi'], fn($m) => in_array($m['id'], [1, 2], true))) === 2);
$gamma = $gruppo($r, 2);
check('Gamma: bonifico trovato per parola, da verificare', count($gamma['movimenti']) === 1 && $gamma['movimenti'][0]['come'] === 'parola', $gamma['movimenti']);
check('Gamma: differenza 40 € chiudibile', abs($gamma['differenza'] - 40) < 0.001 && $gamma['chiudibile'], $gamma);
$delta = $gruppo($r, 3);
check('Delta: due bonifici per nome, il vecchio fuori finestra', array_column($delta['movimenti'], 'id') === [4, 5] && $delta['movimenti'][0]['come'] === 'nome', $delta['movimenti']);

echo "Automatico solo se esatto e per nome\n";
$pdo->beginTransaction();
$a = $pf->abbinaSicuri(null);
$pdo->commit();
check('solo Delta abbinata in automatico', $a['fornitori'] === 1 && $a['fatture_saldate'] === 2, $a);
check('Delta: fatture pagate, bonifici riconciliati', $stato(5) === 'pagata' && $stato(6) === 'pagata' && $movStato(4) === 'riconciliato' && $movStato(5) === 'riconciliato');
check('D-1 pagata dal secondo bonifico (FIFO: 250 + 50)', $pdo->query("SELECT data_pagamento FROM {$p}fatture_passive WHERE id = 5")->fetchColumn() === '2026-08-10');
check('Gamma (per parola) e Alfa non toccate', $stato(3) === 'da_pagare' && $stato(1) === 'da_pagare');

echo "Abbinamento confermato: bonifici prima della fattura, nome in banca imparato\n";
$pdo->beginTransaction();
$e = $pf->abbina(1, [2, 1], [1, 2], false, 7);
$pdo->commit();
check('Alfa: 2 fatture saldate con 2 bonifici', $e['fatture_saldate'] === 2 && $e['bonifici_usati'] === 2 && $e['scoperto'] == 0, $e);
check('A-10 = 600 (acconto di giugno) + 400, A-11 = 500', (function () use ($pdo, $p) {
    $r = $pdo->query("SELECT movimento_id, documento_id, importo FROM {$p}riconciliazioni WHERE documento_id IN (1, 2) ORDER BY id")->fetchAll();
    return array_map(fn($x) => [(int)$x['movimento_id'], (int)$x['documento_id'], (float)$x['importo']], $r) === [[1, 1, 600.0], [2, 1, 400.0], [2, 2, 500.0]];
})());
check('nome in banca imparato', $e['nomi_imparati'] === ['HOTEL BETA MILANO'], $e);
$mov(8, '2026-09-10', -300, '*HOTEL BETA MILANO       Acconto viaggio settembre ID.BON:8');
$ric2 = new Riconciliatore($pdo, $p);
check('un bonifico nuovo dello stesso hotel si riconosce subito', $ric2->contesto($ric2->movimento(8))['anagrafica_id'] === 1);

echo "Differenza\n";
$pdo->beginTransaction();
$e = $pf->abbina(2, [3], [3, 4], false, null);
$pdo->rollBack();
check('senza chiudere: G-1 pagata, G-2 resta con 40 €', $e['fatture_saldate'] === 1 && abs($e['scoperto'] - 40) < 0.001, $e);
$pdo->beginTransaction();
$e = $pf->abbina(2, [3], [3, 4], true, null);
$pdo->commit();
check('chiudendo: due fatture pagate, G-2 con la nota', $stato(3) === 'pagata' && $stato(4) === 'pagata' && $e['chiuse_con_differenza'] === ['G-2']
    && str_contains((string)$pdo->query("SELECT note FROM {$p}fatture_passive WHERE id = 4")->fetchColumn(), '40,00 €'), $e);
$fattura(9, 2, 'G-3', '2026-07-08', 1000);
$mov(9, '2026-07-09', -500, '*GAMMA BUS               Acconto ID.BON:9');
$pdo->beginTransaction();
try {
    $pf->abbina(2, [9], [9], true, null);
    check('differenza oltre il 5% rifiutata', false);
} catch (RuntimeException $ex) {
    check('differenza oltre il 5% rifiutata', str_contains($ex->getMessage(), 'oltre il 5%'), $ex->getMessage());
}
$pdo->rollBack();
check('… e niente registrato', $stato(9) === 'da_pagare' && $movStato(9) === 'da_riconciliare');

echo "Avanzo e annullamento\n";
$pdo->beginTransaction();
$e = $pf->abbina(4, [6], [7], false, null);
$pdo->commit();
check('Omega: fattura pagata, 50 € del bonifico restano da abbinare', $stato(7) === 'pagata' && abs($e['avanzo'] - 50) < 0.001 && $movStato(6) === 'da_riconciliare', $e);
check('il bonifico resta tra quelli aperti col solo residuo', (function () use ($pf) {
    foreach ($pf->riepilogo()['liberi'] as $m) if ($m['id'] === 6) return abs($m['importo'] - 50) < 0.001;
    return false;
})());
$ric->annulla(1);
check('annullato l\'acconto di giugno: A-10 torna da pagare, A-11 resta pagata', $stato(1) === 'da_pagare' && $stato(2) === 'pagata');
$ric->annulla(3);
check('annullato il bonifico di Gamma: anche la fattura chiusa con differenza torna da pagare', $stato(3) === 'da_pagare' && $stato(4) === 'da_pagare');

try {
    $pf->abbina(1, [2], [6], false, null);
    check('fattura di un altro fornitore rifiutata', false);
} catch (RuntimeException $ex) {
    check('fattura di un altro fornitore rifiutata', true);
}

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
