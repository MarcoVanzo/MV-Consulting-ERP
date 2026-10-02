<?php
/**
 * Prova da riga di comando del collegamento fatture ↔ commesse: proposte (protocollo, rata di pari importo,
 * unica commessa aperta), commessa creata dalle fatture (singola con nota di credito, ricorrente a canone),
 * aggancio automatico delle fatture successive del canone. SQLite in memoria, dati inventati.
 *
 *   php tests/collega_commesse_cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/Database.php';
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

$p = 'mv_';
$pdo = new MvPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach ([
    "CREATE TABLE {$p}clienti (id INTEGER PRIMARY KEY, ragione_sociale TEXT, giorni_pagamento INT, fine_mese INT DEFAULT 0,
        giorno_pagamento INT, piano_fatturazione TEXT)",
    "CREATE TABLE {$p}sottoclienti (id INTEGER PRIMARY KEY, cliente_id INT, nome TEXT)",
    "CREATE TABLE {$p}offerte (id INTEGER PRIMARY KEY, numero TEXT, versione INT, cliente_id INT, sottocliente_id INT, data_offerta TEXT,
        oggetto TEXT, tipo_commessa TEXT, num_giornate REAL, imponibile REAL, giorni_pagamento INT, condizioni_pagamento TEXT,
        stato TEXT, data_esito TEXT, incarico_id INT, origine TEXT, note TEXT, deleted_at TEXT)",
    "CREATE TABLE {$p}incarichi (id INTEGER PRIMARY KEY, cliente_id INT, sottocliente_id INT, offerta_id INT, data_incarico TEXT,
        tipo_commessa TEXT, numero_protocollo TEXT, descrizione TEXT, num_giornate REAL, importo_totale REAL,
        importo_fatturato REAL DEFAULT 0, importo_pagato REAL DEFAULT 0, giorni_pagamento INT DEFAULT 30, fine_mese INT DEFAULT 0,
        giorno_pagamento INT, condizioni_pagamento TEXT, stato TEXT DEFAULT 'attivo', note TEXT)",
    "CREATE TABLE {$p}incarichi_rate (id INTEGER PRIMARY KEY, incarico_id INT, ordine INT, descrizione TEXT, percentuale REAL,
        importo REAL, data_prevista TEXT, giorni_pagamento INT, fattura_id INT)",
    "CREATE TABLE {$p}fatture (id INTEGER PRIMARY KEY, numero_fattura TEXT, data_emissione TEXT, cliente_id INT, sottocliente_id INT,
        incarico_id INT, descrizione TEXT, imponibile REAL, importo_totale REAL, stato TEXT DEFAULT 'emessa', data_scadenza TEXT)",
] as $sql) $pdo->exec($sql);

$pdo->exec("INSERT INTO {$p}clienti (id, ragione_sociale) VALUES (1, 'Viaggi Alfa'), (2, 'Società Beta'), (3, 'Ente Gamma')");
// Ente Gamma: una commessa con protocollo e una aperta senza
$pdo->exec("INSERT INTO {$p}incarichi (id, cliente_id, data_incarico, tipo_commessa, numero_protocollo, importo_totale)
    VALUES (10, 3, '2026-01-10', 'assistenza', '120/2026', 1000), (11, 3, '2026-02-01', 'sviluppo_software', NULL, 10000)");
$f = $pdo->prepare("INSERT INTO {$p}fatture (id, numero_fattura, data_emissione, cliente_id, descrizione, imponibile, importo_totale)
    VALUES (?, ?, ?, ?, ?, ?, ?)");
foreach ([
    [1, '1/001', '2026-03-01', 1, 'Viaggio Volley giugno', 5000, 6100],
    [2, '2/001', '2026-03-15', 1, 'Costi extra viaggio', 800, 976],
    [3, '3/001', '2026-04-01', 1, '[Nota di credito] storno parziale', -300, -366],
    [4, '4/001', '2026-01-31', 2, 'Noleggio pulmino gennaio', 200, 244],
    [5, '5/001', '2026-02-28', 2, 'Noleggio pulmino febbraio', 200, 244],
    [6, '6/001', '2026-03-31', 2, 'Noleggio pulmino marzo', 200, 244],
    [7, '7/001', '2026-03-05', 3, 'Supporto privacy Prot. n. 120/2026', 500, 610],
    [8, '8/001', '2026-03-06', 3, 'Acconto 30% progetto', 3000, 3660],
    [9, '9/001', '2026-01-05', 3, 'Consulenza preliminare', 200, 244],
    [10, '10/001', '2026-03-31', 3, 'Assistenza marzo', 150, 183],
    [11, '11/001', '2026-04-30', 3, 'Assistenza aprile', 150, 183],
    [12, '12/001', '2026-05-31', 3, 'Assistenza maggio', 150, 183],
] as $r) $f->execute($r);

$svc = new CommessaService($pdo, $p);

echo "Proposte di collegamento\n";
$pr = $svc->proposteCollegamento(2026);
$per = [];
foreach ($pr['fatture'] as $x) $per[(int)$x['id']] = $x;
check('tutte le fatture senza commessa', count($pr['fatture']) === 12, count($pr['fatture']));
check('protocollo citato → commessa 10', ($per[7]['proposta']['incarico_id'] ?? null) === 10, $per[7]['proposta']);
check('unica commessa con residuo → commessa 11', ($per[8]['proposta']['incarico_id'] ?? null) === 11, $per[8]['proposta']);
check('canone ripetuto: non va sulla commessa di un progetto', $per[10]['proposta'] === null, $per[10]['proposta']);
check('fattura precedente la commessa: nessuna proposta', $per[9]['proposta'] === null, $per[9]['proposta']);
check('cliente senza commesse: nessuna proposta', $per[1]['proposta'] === null);
check('nota di credito: nessuna proposta', $per[3]['proposta'] === null);
check('canone ripetuto 3 volte → ricorrente', $per[4]['ricorrente'] && !$per[1]['ricorrente']);

echo "Commessa singola dalle fatture (con nota di credito)\n";
$id = $svc->creaDaFatture([1, 2, 3], ['tipo_commessa' => 'viaggio', 'descrizione' => 'Viaggio Volley']);
$inc = $pdo->query("SELECT * FROM {$p}incarichi WHERE id = $id")->fetch();
check('valore = fatture meno nota di credito', abs((float)$inc['importo_totale'] - 5500) < 0.01, $inc['importo_totale']);
check('tipo viaggio', $inc['tipo_commessa'] === 'viaggio');
$rate = $pdo->query("SELECT importo, fattura_id FROM {$p}incarichi_rate WHERE incarico_id = $id ORDER BY ordine")->fetchAll();
check('una rata per fattura, la nota riduce l\'ultima', array_map(fn($r) => [(float)$r['importo'], (int)$r['fattura_id']], $rate) === [[5000.0, 1], [500.0, 2]], $rate);
check('anche la nota di credito è sulla commessa', (int)$pdo->query("SELECT COUNT(*) FROM {$p}fatture WHERE incarico_id = $id")->fetchColumn() === 3);
check('offerta già accettata registrata', (int)$pdo->query("SELECT COUNT(*) FROM {$p}offerte WHERE incarico_id = $id AND stato = 'accettata'")->fetchColumn() === 1);

$errore = null;
try { $svc->creaDaFatture([2], []); } catch (InvalidArgumentException $e) { $errore = $e->getMessage(); }
check('fattura già collegata: rifiutata', $errore !== null, $errore);
$errore = null;
try { $svc->creaDaFatture([4, 7], []); } catch (InvalidArgumentException $e) { $errore = $e->getMessage(); }
check('clienti diversi: rifiutata', $errore !== null, $errore);

echo "Commessa ricorrente a canone\n";
$id2 = $svc->creaDaFatture([6, 4, 5], ['tipo_commessa' => 'noleggio', 'ricorrente' => true, 'mesi' => 12]);
$inc = $pdo->query("SELECT * FROM {$p}incarichi WHERE id = $id2")->fetch();
check('valore = canone × 12', abs((float)$inc['importo_totale'] - 2400) < 0.01, $inc['importo_totale']);
check('parte dalla prima fattura', $inc['data_incarico'] === '2026-01-31', $inc['data_incarico']);
$rate = $pdo->query("SELECT data_prevista, fattura_id FROM {$p}incarichi_rate WHERE incarico_id = $id2 ORDER BY ordine")->fetchAll();
check('12 rate mensili', count($rate) === 12 && $rate[1]['data_prevista'] === '2026-02-28', array_slice($rate, 0, 3));
check('fatture sulle rate in ordine di data', array_map(fn($r) => (int)$r['fattura_id'], array_slice($rate, 0, 4)) === [4, 5, 6, 0], $rate);

echo "Aggancio delle fatture successive\n";
check('aprile: rata di pari importo vicina → commessa ricorrente', $svc->trovaIncaricoPerRata(2, 200, '2026-04-30') === $id2);
check('importo diverso: nessuna', $svc->trovaIncaricoPerRata(2, 250, '2026-04-30') === null);
check('troppo lontano dalle rate: nessuna', $svc->trovaIncaricoPerRata(2, 200, '2027-06-30') === null);

echo "Collegamento confermato a mano\n";
$svc->collegaFatturaACommessa(7, 10);
check('fattura collegata alla commessa', (int)$pdo->query("SELECT incarico_id FROM {$p}fatture WHERE id = 7")->fetchColumn() === 10);
$pr = $svc->proposteCollegamento(2026);
check('non è più tra le proposte', !in_array(7, array_map(fn($x) => (int)$x['id'], $pr['fatture']), true));

echo "Tutte le commesse mancanti\n";
$pdo->exec("INSERT INTO {$p}clienti (id, ragione_sociale) VALUES (4, 'Squadra Delta')");
$f->execute([20, '20/001', '2026-06-10', 4, 'Viaggio squadra a Roma', 1200, 1464]);
$f->execute([21, '21/001', '2026-06-12', 3, 'Corso di formazione privacy Prot. n. 120/2026', 100, 122]);
$f->execute([22, '22/001', '2026-06-15', 3, 'Attività extra', 50, 61]);
$f->execute([23, '23/001', '2026-06-20', null, 'Senza cliente', 80, 97.6]);
$f->execute([24, '24/001', '2026-06-21', 2, '[Nota di credito] storno', -50, -61]);
foreach ([[30, '2025-01-31'], [31, '2025-02-28'], [32, '2025-03-31'], [33, '2026-04-30']] as [$fid, $d]) {
    $f->execute([$fid, "$fid/001", $d, 1, 'Canone noleggio software', 90, 109.8]);
}
check('tipo dal testo', CommessaService::tipoDalTesto('Viaggio squadra') === 'viaggio' && CommessaService::tipoDalTesto('Varie') === 'altro');
$solo = $svc->creaCommesseMancanti([20]);
check('solo le fatture indicate', $solo['create'] === 1 && (int)$pdo->query("SELECT COUNT(*) FROM {$p}fatture WHERE incarico_id IS NULL AND id = 22")->fetchColumn() === 1, $solo);
$inc = $pdo->query("SELECT i.* FROM {$p}incarichi i JOIN {$p}fatture f ON f.incarico_id = i.id WHERE f.id = 20")->fetch();
check('commessa singola: valore, tipo e descrizione dalla fattura', abs((float)$inc['importo_totale'] - 1200) < 0.01
    && $inc['tipo_commessa'] === 'viaggio' && $inc['descrizione'] === 'Viaggio squadra a Roma', $inc);
$es = $svc->creaCommesseMancanti();
check('protocollo citato → collegata alla commessa esistente', (int)$pdo->query("SELECT incarico_id FROM {$p}fatture WHERE id = 21")->fetchColumn() === 10);
check('una sola commessa con residuo → resta da scegliere a mano', $es['da_scegliere'] >= 1
    && $pdo->query("SELECT incarico_id FROM {$p}fatture WHERE id = 8")->fetchColumn() === null, $es);
check('nota di credito e fattura senza cliente saltate', $pdo->query("SELECT COUNT(*) FROM {$p}fatture WHERE id IN (23, 24) AND incarico_id IS NULL")->fetchColumn() == 2);
$canone = $pdo->query("SELECT DISTINCT incarico_id FROM {$p}fatture WHERE id IN (30, 31, 32, 33)")->fetchAll(PDO::FETCH_COLUMN);
check('canone ripetuto → una commessa ricorrente', count($canone) === 1 && $canone[0] !== null, $canone);
$nRate = (int)$pdo->query("SELECT COUNT(*) FROM {$p}incarichi_rate WHERE incarico_id = " . (int)$canone[0])->fetchColumn();
check('rate per tutti i mesi fatturati (gen 2025 → apr 2026)', $nRate === 16, $nRate);
check('canone di assistenza 150 € (3 volte) → ricorrente', (int)$pdo->query("SELECT COUNT(DISTINCT incarico_id) FROM {$p}fatture WHERE id IN (10, 11, 12)")->fetchColumn() === 1);
$rimaste = (int)$pdo->query("SELECT COUNT(*) FROM {$p}fatture WHERE incarico_id IS NULL AND imponibile > 0 AND cliente_id IS NOT NULL")->fetchColumn();
check('restano solo quelle da scegliere', $rimaste === $es['da_scegliere'], [$rimaste, $es]);
$di = $svc->creaCommesseMancanti();
check("seconda passata: non crea niente", $di["create"] === 0 && $di["collegate"] === 0, [$di, $pdo->query("SELECT id, cliente_id, imponibile FROM {$p}fatture WHERE incarico_id IN (" . implode(",", $di["commesse"] ?: [0]) . ")")->fetchAll()]);

echo "Viaggi in acconto e saldo, note di credito\n";
$pdo->exec("INSERT INTO {$p}clienti (id, ragione_sociale) VALUES (5, 'Sport Viaggi')");
foreach ([
    [40, '1AV', '2026-03-17', 5, 'EXACT Trip - Italy, Volleyball which is scheduled from 30 June to 6 July 2026: 40% within March', 16800],
    [41, '12AV', '2026-06-12', 5, 'EXACT Trip - Italy, Volleyball which is scheduled from 30 June to 6 July 2026: balance', 25200],
    [42, '16AV', '2026-06-23', 5, 'the EXACT Trip - Italy, Volleyball which is scheduled from 30 June to 6 July 2026: balance', 22350],
    [43, '17AV', '2026-06-23', 5, "[Nota di credito]\r\nstorno fattura n.12AV del 12/06/26 per errata fatturazione", -25200],
    [44, '19AV', '2026-07-30', 5, 'Costi extra ciaggi', 51467.9],
    [45, '21AV', '2026-09-10', 5, "[Nota di credito]\r\nCosti extra viaggi", -51467.9],
    [46, '2AV', '2026-03-17', 5, 'EXACT Trip - Italy, Basket 1, which is scheduled from 21 July to 28 July 2026: 40%', 27500],
] as $r) $f->execute([$r[0], $r[1], $r[2], $r[3], $r[4], $r[5], $r[5]]);
$es = $svc->creaCommesseMancanti([40, 41, 42, 43, 44, 45, 46]);
$v = $pdo->query("SELECT DISTINCT incarico_id FROM {$p}fatture WHERE id IN (40, 41, 42, 43)")->fetchAll(PDO::FETCH_COLUMN);
check('acconto, saldo e nota dello stesso viaggio: una commessa', count($v) === 1 && $v[0] !== null, $v);
$inc = $pdo->query("SELECT * FROM {$p}incarichi WHERE id = " . (int)$v[0])->fetch();
check('valore al netto della nota', abs((float)$inc['importo_totale'] - 39150) < 0.01, $inc['importo_totale']);
check('tipo viaggio, descrizione = intestazione', $inc['tipo_commessa'] === 'viaggio'
    && $inc['descrizione'] === 'EXACT Trip - Italy, Volleyball which is scheduled from 30 June to 6 July 2026', $inc);
check('fattura stornata per intero: nessuna commessa', $pdo->query("SELECT COUNT(*) FROM {$p}fatture WHERE id IN (44, 45) AND incarico_id IS NULL")->fetchColumn() == 2, $es);
check('altro viaggio: commessa a parte', (int)$pdo->query("SELECT incarico_id FROM {$p}fatture WHERE id = 46")->fetchColumn() !== (int)$v[0]);
$f->execute([47, '22AV', '2026-09-20', 5, '[Nota di credito] storno parziale ft. 2AV', -500, -500]);
$es = $svc->creaCommesseMancanti([47]);
check('nota su fattura già in commessa: collegata', (int)$pdo->query("SELECT incarico_id FROM {$p}fatture WHERE id = 47")->fetchColumn()
    === (int)$pdo->query("SELECT incarico_id FROM {$p}fatture WHERE id = 46")->fetchColumn() && $es['collegate'] === 1, $es);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
