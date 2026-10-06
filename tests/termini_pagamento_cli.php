<?php
/**
 * Prova da riga di comando dei termini di pagamento (fine mese, giorno fisso, piano 6/12 mesi)
 * e del lettore degli avvisi di pagamento. SQLite in memoria, dati inventati.
 *
 *   php tests/termini_pagamento_cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/TerminiPagamento.php';
require_once __DIR__ . '/../api/Shared/AvvisoPagamentoParser.php';
require_once __DIR__ . '/../api/Shared/CommessaService.php';

$ok = 0;
$ko = 0;
function check(string $nome, bool $cond, $dettaglio = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok   $nome\n"; return; }
    $ko++;
    echo "  FAIL $nome" . ($dettaglio !== null ? ' → ' . json_encode($dettaglio, JSON_UNESCAPED_UNICODE) : '') . "\n";
}

echo "Scadenze\n";
$casi = [
    // data fattura, giorni, fine mese, giorno fisso → scadenza
    ['2026-06-28', 60, true, 10, '2026-09-10'],
    ['2026-05-29', 60, true, 10, '2026-08-10'],
    ['2026-04-30', 60, true, 10, '2026-07-10'],
    ['2026-02-27', 60, true, 10, '2026-05-10'],
    ['2026-12-15', 60, true, 10, '2027-03-10'],
    ['2026-12-31', 30, true, null, '2027-01-31'],
    ['2026-01-31', 30, true, null, '2026-02-28'],
    ['2026-03-10', 30, false, null, '2026-04-09'],
    ['2026-03-10', 30, false, 15, '2026-04-15'],
    ['2026-03-10', 45, true, null, '2026-05-15'],
];
foreach ($casi as [$d, $g, $fm, $gf, $atteso]) {
    $x = TerminiPagamento::scadenza($d, $g, $fm, $gf);
    check("$d " . TerminiPagamento::descrivi($g, $fm, $gf) . " → $atteso", $x === $atteso, $x);
}
check('descrizione', TerminiPagamento::descrivi(60, true, 10) === '60 gg d.f.f.m. al 10');
check('6 mesi dal 31/08 → fine febbraio', TerminiPagamento::piuMesi('2026-08-31', 6) === '2027-02-28');
check('12 mesi dal 15/03', TerminiPagamento::piuMesi('2026-03-15', 12) === '2027-03-15');

echo "Avviso di pagamento\n";
// Testo come lo dà pdf.js (righe unite da spazi); numeri e importi inventati
$testo = 'Spett. 000123 MV CONSULTING S.R.L.S. VIA ROMA 1 00100 CITTA XX Treviso,  9/07/26 Con la presente Vi informiamo '
    . 'di aver dato ordine alla nostra banca di bonificare sul Vostro conto corrente quanto segue: '
    . '! Num. Data doc. Riferim. ! Valuta ! Importo ! Ritenute ! '
    . '7  30/04/26  10/07/26  100,00 BANCA . IBAN IT 00 X 00000 00000 000000000000 ---- *** TOTALE PAGAMENTO *** EURO  100,00 '
    . '8  30/04/26  10/07/26  1.200,50 9/001  30/04/26  10/07/26 Fissa  2.000,00 BANCA . IBAN IT 00 X 00000 00000 000000000000 '
    . '---- *** TOTALE PAGAMENTO *** EURO  3.200,50 *** TOTALE GENERALE *** EURO  3.300,50 Distinti saluti.';
$a = AvvisoPagamentoParser::leggi([$testo]);
check('data della lettera', $a['data_avviso'] === '2026-07-09', $a['data_avviso']);
check('due bonifici', count($a['bonifici']) === 2, count($a['bonifici']));
check('totale generale', abs($a['totale'] - 3300.50) < 0.005, $a['totale']);
check('primo bonifico: una fattura', count($a['bonifici'][0]['righe'] ?? []) === 1 && abs($a['bonifici'][0]['totale'] - 100) < 0.005);
$r = $a['bonifici'][1]['righe'] ?? [];
check('secondo bonifico: due fatture', count($r) === 2, array_column($r, 'numero'));
check('numero con suffisso', ($r[1]['numero'] ?? '') === '9/001' && ($r[1]['numero_base'] ?? '') === '9', $r[1] ?? null);
check('riga con «Fissa» e migliaia', abs(($r[1]['importo'] ?? 0) - 2000) < 0.005 && abs(($r[0]['importo'] ?? 0) - 1200.50) < 0.005);
check('data documento e valuta', ($r[0]['data_documento'] ?? '') === '2026-04-30' && ($r[0]['valuta'] ?? '') === '2026-07-10');
$senza = AvvisoPagamentoParser::leggi(['Treviso, 4/08/26 ! Num. ! 3 29/05/26 4/08/26 50,00 4 29/05/26 4/08/26 25,00']);
check('senza riga di totale: somma delle righe', count($senza['bonifici']) === 1 && abs($senza['totale'] - 75) < 0.005, $senza);
check('valuta con giorno a una cifra', ($senza['bonifici'][0]['valuta'] ?? '') === '2026-08-04');

echo "Commessa\n";
$p = 'mv_';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach ([
    "CREATE TABLE {$p}clienti (id INTEGER PRIMARY KEY, ragione_sociale TEXT, giorni_pagamento INT, fine_mese INT DEFAULT 0,
        giorno_pagamento INT, piano_fatturazione TEXT)",
    "CREATE TABLE {$p}incarichi (id INTEGER PRIMARY KEY, cliente_id INT, giorni_pagamento INT DEFAULT 30, fine_mese INT DEFAULT 0, giorno_pagamento INT)",
    "CREATE TABLE {$p}incarichi_rate (id INTEGER PRIMARY KEY, incarico_id INT, ordine INT, descrizione TEXT, percentuale REAL,
        importo REAL, data_prevista TEXT, giorni_pagamento INT, fattura_id INT)",
    "CREATE TABLE {$p}fatture (id INTEGER PRIMARY KEY, numero_fattura TEXT, data_emissione TEXT, data_scadenza TEXT, cliente_id INT, incarico_id INT)",
    "INSERT INTO {$p}clienti VALUES (1, 'Ente committente', 60, 1, 10, '6_12'), (2, 'Altro cliente', NULL, 0, NULL, NULL)",
    "INSERT INTO {$p}incarichi VALUES (1, 1, 60, 1, 10)",
] as $sql) $pdo->exec($sql);
$svc = new CommessaService($pdo, $p);

$std = $svc->terminiCliente(1);
check('termini standard del cliente', $std && $std['giorni_pagamento'] === 60 && $std['fine_mese'] === 1 && $std['giorno_pagamento'] === 10, $std);
check('cliente senza standard', $svc->terminiCliente(2) === null);
$svc->creaRateDaPiano(1, 1000.01, $std['piano'], '2026-03-31', 60);
$rate = $pdo->query("SELECT descrizione, importo, data_prevista, giorni_pagamento FROM {$p}incarichi_rate ORDER BY ordine")->fetchAll();
check('piano 6/12: due rate', count($rate) === 2, $rate);
check('acconto a 6 mesi', ($rate[0]['data_prevista'] ?? '') === '2026-09-30' && abs($rate[0]['importo'] - 500.01) < 0.005, $rate[0] ?? null);
check('saldo a 12 mesi, somma al centesimo', ($rate[1]['data_prevista'] ?? '') === '2027-03-31' && abs($rate[0]['importo'] + $rate[1]['importo'] - 1000.01) < 0.005, $rate[1] ?? null);

$pdo->exec("INSERT INTO {$p}fatture VALUES (1, '5', '2026-06-28', '2026-07-28', 1, 1), (2, '6', '2026-06-28', '2026-07-28', 2, NULL),
    (3, '7', '2026-06-28', NULL, 2, NULL)");
check('scadenza attesa dai termini della commessa', $svc->scadenzaAttesa(1) === ['2026-09-10', '60 gg d.f.f.m. al 10'], $svc->scadenzaAttesa(1));
check('senza termini: la scadenza registrata', $svc->scadenzaAttesa(2) === ['2026-07-28', 'scadenza della fattura'], $svc->scadenzaAttesa(2));
check('senza termini né scadenza: nulla', $svc->scadenzaAttesa(3) === null);
$pdo->exec("UPDATE {$p}fatture SET incarico_id = NULL, cliente_id = 1 WHERE id = 2");
check('fattura senza commessa: termini del cliente', ($svc->scadenzaAttesa(2)[0] ?? '') === '2026-09-10', $svc->scadenzaAttesa(2));

echo "Giorno fisso\n";
$pdo->exec("ALTER TABLE {$p}fatture ADD COLUMN stato TEXT DEFAULT 'emessa'");
$pdo->exec("DELETE FROM {$p}fatture");
$pdo->exec("INSERT INTO {$p}fatture (id, numero_fattura, data_emissione, data_scadenza, cliente_id, incarico_id, stato) VALUES
    (1, '3', '2026-01-31', '2026-03-31', 1, NULL, 'scaduta'), (2, '4', '2026-01-31', '2026-04-05', 1, NULL, 'emessa'),
    (3, '5', '2026-01-31', '2026-04-10', 1, NULL, 'emessa'), (4, '6', '2026-01-31', '2026-03-31', 1, NULL, 'pagata'),
    (5, '7', '2026-01-31', '2026-03-31', 2, NULL, 'emessa'), (6, '8', '2026-06-28', '2026-08-31', 2, 1, 'emessa')");
check('allineate al giorno fisso', $svc->allineaScadenzeGiornoFisso() === 3);
$sc = $pdo->query("SELECT id, data_scadenza FROM {$p}fatture ORDER BY id")->fetchAll(PDO::FETCH_KEY_PAIR);
check('fine mese → il 10 del mese dopo', $sc[1] === '2026-04-10', $sc);
check('prima del 10 → il 10 dello stesso mese', $sc[2] === '2026-04-10', $sc);
check('già al 10, pagate e clienti senza giorno fisso: invariate', $sc[3] === '2026-04-10' && $sc[4] === '2026-03-31' && $sc[5] === '2026-03-31', $sc);
check('vince il giorno fisso della commessa', $sc[6] === '2026-09-10', $sc);
check('ripetibile', $svc->allineaScadenzeGiornoFisso() === 0);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
