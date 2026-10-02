<?php
/**
 * Prova da riga di comando delle note spese: spese per giorno sulle trasferte, abbinamento alla carta,
 * spesa da movimento, tracciabilità, nota spese presentata che blocca le modifiche.
 * SQLite in memoria, dati inventati.
 *
 *   php tests/spese_cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/Database.php';
require_once __DIR__ . '/../api/Shared/Response.php';
require_once __DIR__ . '/../api/Controllers/SpeseController.php';
require_once __DIR__ . '/../api/Controllers/TrasferteController.php';

$ok = 0;
$ko = 0;
function check(string $nome, bool $cond, $dettaglio = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok   $nome\n"; return; }
    $ko++;
    echo "  FAIL $nome" . ($dettaglio !== null ? ' → ' . json_encode($dettaglio, JSON_UNESCAPED_UNICODE) : '') . "\n";
}
function risposta(callable $f): array
{
    Response::$cattura = true;
    try { $f(); return ['success' => false, 'message' => 'nessuna risposta']; }
    catch (RispostaCatturata $r) { return $r->risposta; }
    finally { Response::fineCattura(); }
}

$p = 'mv_';
$pdo = new MvPdo('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->sqliteCreateFunction('NOW', fn() => date('Y-m-d H:i:s'), 0);
foreach ([
    "CREATE TABLE {$p}clienti (id INTEGER PRIMARY KEY, ragione_sociale TEXT, citta TEXT)",
    "CREATE TABLE {$p}sottoclienti (id INTEGER PRIMARY KEY, citta TEXT)",
    "CREATE TABLE {$p}settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)",
    "CREATE TABLE {$p}mezzi (id INTEGER PRIMARY KEY, costo_km REAL)",
    "CREATE TABLE {$p}trasferte (id INTEGER PRIMARY KEY, data_trasferta TEXT, cliente_id INT, sottocliente_id INT, mezzo_id INT,
        km_andata REAL DEFAULT 0, km_ritorno REAL DEFAULT 0, vitto REAL DEFAULT 0, alloggio REAL DEFAULT 0)",
    "CREATE TABLE {$p}movimenti_banca (id INTEGER PRIMARY KEY, origine TEXT, importo REAL, data_operazione TEXT, descrizione TEXT, controparte TEXT)",
    "CREATE TABLE {$p}spese (id INTEGER PRIMARY KEY, data TEXT, categoria TEXT, descrizione TEXT, esercente TEXT, importo REAL,
        metodo TEXT DEFAULT 'carta', cliente_id INT, movimento_id INT, documento TEXT, origine TEXT DEFAULT 'manuale', deleted_at TEXT)",
    "CREATE TABLE {$p}rimborsi (id INTEGER PRIMARY KEY, mese TEXT UNIQUE, stato TEXT, km REAL, importo_km REAL, indennita REAL, spese REAL,
        totale REAL, data_presentazione TEXT, data_rimborso TEXT, note TEXT)",
] as $sql) $pdo->exec($sql);

$sp = new Spese($pdo, $p);

echo "Spese per giorno\n";
$pdo->exec("INSERT INTO {$p}spese (data, categoria, importo, metodo) VALUES
    ('2026-09-03', 'vitto', 18.5, 'carta'), ('2026-09-03', 'pedaggio', 7.2, 'carta'), ('2026-09-03', 'vitto', 4, 'contanti'),
    ('2026-09-04', 'alloggio', 95, 'carta'), ('2026-09-05', 'taxi', 22, 'contanti')");
$g = $sp->perGiorno('2026-09-01', '2026-09-30');
check('vitto sommato, pedaggio tra le altre', abs($g['2026-09-03']['vitto'] - 22.5) < 0.01 && abs($g['2026-09-03']['altre'] - 7.2) < 0.01, $g);
$righe = Spese::applicaAlleTrasferte([['data_trasferta' => '2026-09-03'], ['data_trasferta' => '2026-09-03'], ['data_trasferta' => '2026-09-06']], $g);
check('spese sulla prima riga del giorno, zero sulle altre', $righe[0]['vitto'] == 22.5 && $righe[1]['vitto'] == 0 && $righe[2]['altre_spese'] == 0, $righe);
$el = $sp->elenco('2026-09-01', '2026-09-30');
$taxi = array_values(array_filter($el, fn($s) => $s['categoria'] === 'taxi'))[0];
$ped = array_values(array_filter($el, fn($s) => $s['categoria'] === 'pedaggio'))[0];
check('taxi in contanti da segnalare (non tracciabile)', $taxi['da_segnalare'] === true && $taxi['tracciabile'] === false);
check('pedaggio con carta: tracciabile', $ped['da_segnalare'] === false && $ped['tracciabile'] === true);
// Carburante di tasca propria nel giorno del rimborso km: già compreso nel costo ACI
$pdo->exec("INSERT INTO {$p}clienti (id, ragione_sociale, citta) VALUES (1, 'Cliente di prova', 'Treviso')");
$pdo->exec("INSERT INTO {$p}settings VALUES ('trasferte_costo_km', '0.5')");
$sp = new Spese($pdo, $p); // il costo al km si legge una volta per istanza (una per richiesta)
$pdo->exec("INSERT INTO {$p}trasferte (data_trasferta, cliente_id, km_andata) VALUES ('2026-09-07', 1, 40)");
$pdo->exec("INSERT INTO {$p}spese (data, categoria, importo, metodo) VALUES ('2026-09-07', 'carburante', 60, 'carta_personale'),
    ('2026-09-07', 'carburante', 50, 'carta'), ('2026-09-08', 'carburante', 30, 'contanti')");
$carb = array_values(array_filter($sp->elenco('2026-09-01', '2026-09-30'), fn($s) => $s['categoria'] === 'carburante'));
$doppio = array_column($carb, 'carburante_doppio', 'metodo');
check('carburante di tasca propria nel giorno con km: segnalato', $doppio['carta_personale'] === true, $doppio);
check('carburante con carta aziendale: non segnalato', $doppio['carta'] === false, $doppio);
check('carburante in un giorno senza km: non segnalato', $doppio['contanti'] === false, $doppio);
$cp = array_values(array_filter($carb, fn($s) => $s['metodo'] === 'carta_personale'))[0];
check('carburante doppio: non rimborsabile', $cp['rimborsabile'] === false, $cp);
$gc = $sp->perGiorno('2026-09-07', '2026-09-08');
check('carburante doppio escluso per giorno (non in da rimborsare)', abs($gc['2026-09-07']['escluse'] - 60) < 0.01 && abs($gc['2026-09-08']['escluse']) < 0.01, $gc);
$pm = $sp->perMetodo('2026-09-07', '2026-09-08');
check('carburante doppio escluso dal totale da rimborsare', abs($pm['da_rimborsare'] - 30) < 0.01 && abs($pm['carburante_doppio'] - 60) < 0.01 && abs($pm['aziendali'] - 50) < 0.01, $pm);
$rc = Spese::applicaAlleTrasferte([['data_trasferta' => '2026-09-07']], $gc);
check('carburante doppio sulla riga della trasferta', abs($rc[0]['spese_escluse'] - 60) < 0.01, $rc);
// Senza un costo al km (né generale né del mezzo) non c'è rimborso km: il pieno si rimborsa
$pdo->exec("DELETE FROM {$p}settings WHERE setting_key = 'trasferte_costo_km'");
check('senza costo al km: carburante non doppio', abs((new Spese($pdo, $p))->perMetodo('2026-09-07', '2026-09-08')['carburante_doppio']) < 0.01);
$pdo->exec("INSERT INTO {$p}mezzi (id, costo_km) VALUES (1, 0.42)");
$pdo->exec("UPDATE {$p}trasferte SET mezzo_id = 1");
check('costo ACI del mezzo: carburante doppio', abs((new Spese($pdo, $p))->perMetodo('2026-09-07', '2026-09-08')['carburante_doppio'] - 60) < 0.01);
$pdo->exec("DELETE FROM {$p}mezzi");
$pdo->exec("DELETE FROM {$p}spese WHERE categoria = 'carburante'");
$pdo->exec("DELETE FROM {$p}trasferte");

echo "Carta\n";
$pdo->exec("INSERT INTO {$p}movimenti_banca (id, origine, importo, data_operazione, descrizione, controparte) VALUES
    (1, 'estratto_carta', -18.5, '2026-09-05', 'OSTERIA DA MARIO', 'Osteria da Mario'),
    (2, 'estratto_carta', -7.2, '2026-09-03', 'AUTOSTRADE', NULL), (3, 'estratto_carta', -7.2, '2026-09-04', 'AUTOSTRADE', NULL),
    (4, 'estratto_carta', -95, '2026-09-20', 'HOTEL', NULL), (5, 'estratto_conto', -18.5, '2026-09-03', 'NON CARTA', NULL),
    (6, 'estratto_carta', -42, '2026-09-10', 'RISTORANTE PESCE', 'Ristorante Pesce')");
$n = $sp->abbinaCarta();
$mov = fn($imp) => $pdo->query("SELECT movimento_id FROM {$p}spese WHERE importo = $imp")->fetchColumn();
check('vitto abbinato entro 3 giorni, solo movimenti della carta', (int)$mov(18.5) === 1, $n);
check('due movimenti uguali: nessuna scelta a caso', $mov(7.2) === null);
check('troppo lontano nel tempo: non abbinato', $mov(95) === null);
check('contanti mai abbinati', $pdo->query("SELECT COUNT(*) FROM {$p}spese WHERE metodo = 'contanti' AND movimento_id IS NOT NULL")->fetchColumn() == 0);
$liberi = array_column($sp->movimentiCartaLiberi('2026-09-01', '2026-09-30'), 'id');
check('uscite della carta non registrate', array_map('intval', $liberi) === [4, 6, 3, 2], $liberi);
$id = $sp->daMovimento(6, 'vitto', null);
$s6 = $pdo->query("SELECT * FROM {$p}spese WHERE id = $id")->fetch();
check('spesa creata dal movimento', (float)$s6['importo'] === 42.0 && $s6['metodo'] === 'carta' && (int)$s6['movimento_id'] === 6 && $s6['esercente'] === 'Ristorante Pesce', $s6);
try { $sp->daMovimento(6, 'vitto'); check('stesso movimento due volte: rifiutato', false); }
catch (RuntimeException $e) { check('stesso movimento due volte: rifiutato', true); }

// Ambiguità dal lato del movimento: due spese uguali vicine e una sola uscita della carta
$pdo->exec("INSERT INTO {$p}spese (id, data, categoria, importo, metodo) VALUES (90, '2026-09-10', 'parcheggio', 25, 'carta'), (91, '2026-09-12', 'parcheggio', 25, 'carta')");
$pdo->exec("INSERT INTO {$p}movimenti_banca (id, origine, importo, data_operazione, descrizione) VALUES (9, 'estratto_carta', -25, '2026-09-11', 'PARCHEGGIO')");
$sp->abbinaCarta();
check('due spese candidate per la stessa uscita: nessun abbinamento', $pdo->query("SELECT COUNT(*) FROM {$p}spese WHERE movimento_id = 9")->fetchColumn() == 0);
$pdo->exec("DELETE FROM {$p}spese WHERE id IN (90, 91)");
$pdo->exec("DELETE FROM {$p}movimenti_banca WHERE id = 9");

echo "Nota spese del mese\n";
$pdo->exec("INSERT INTO {$p}settings VALUES ('trasferte_costo_km', '0.5')");
$pdo->exec("INSERT INTO {$p}trasferte (data_trasferta, cliente_id, km_andata, km_ritorno) VALUES ('2026-09-03', 1, 40, 40)");
$c = new SpeseController($pdo, $p);
$r = risposta(fn() => $c->rimborso(['mese' => '2026-09', 'azione' => 'presenta']));
$_GET = ['year' => '2026', 'month' => '9'];
$rb = $pdo->query("SELECT * FROM {$p}rimborsi WHERE mese = '2026-09'")->fetch();
// km 80 × 0,5 = 40; indennità 30,99 (vitto pagato: riduce l'indennità anche se con carta aziendale);
// da rimborsare solo le spese pagate di tasca propria: 4 (vitto in contanti) + 22 (taxi) = 26
check('presentata con i totali congelati', $r['success'] && abs($rb['importo_km'] - 40) < 0.01 && abs($rb['indennita'] - 30.99) < 0.01
    && abs($rb['spese'] - 26) < 0.01 && abs($rb['totale'] - 96.99) < 0.01, $rb);
// Trasferte › Viaggi di un mese presentato: totali congelati e ricalcolo di oggi, per l'avviso
$ref = new ReflectionProperty(Database::class, 'pdo');
$ref->setValue(null, $pdo);
foreach (["ALTER TABLE {$p}sottoclienti ADD COLUMN nome TEXT", "ALTER TABLE {$p}mezzi ADD COLUMN nome TEXT", "ALTER TABLE {$p}mezzi ADD COLUMN targa TEXT"] as $sql) $pdo->exec($sql);
$tc = new TrasferteController(new Percorsi($pdo, $p));
$l = risposta(fn() => $tc->list());
$np = $l['data']['note_presentate']['2026-09'] ?? null;
check('lista: nota presentata con i totali congelati, ricalcolo uguale', $np && abs($np['congelato']['totale'] - 96.99) < 0.01 && $np['differisce'] === false, $l['data']['note_presentate'] ?? $l);
// Una regola cambiata dopo la presentazione (qui: il cliente risulta nel comune della sede) cambia il ricalcolo, non la nota
$pdo->exec("UPDATE {$p}clienti SET citta = 'Zero Branco' WHERE id = 1");
$np = risposta(fn() => $tc->list())['data']['note_presentate']['2026-09'];
check('lista: ricalcolo diverso segnalato, totali presentati intatti', $np['differisce'] === true && abs($np['congelato']['indennita'] - 30.99) < 0.01
    && abs($np['ricalcolo']['indennita']) < 0.01, $np);
$pdo->exec("UPDATE {$p}clienti SET citta = 'Treviso' WHERE id = 1");
check('ripresentare senza riaprire: rifiutato', risposta(fn() => $c->rimborso(['mese' => '2026-09', 'azione' => 'presenta']))['success'] === false);
check('spesa dalla carta in un mese presentato: bloccata', risposta(fn() => $c->daMovimento(['movimento_id' => 4, 'categoria' => 'alloggio']))['success'] === false);
$r = risposta(fn() => $c->save(['data' => '2026-09-12', 'categoria' => 'parcheggio', 'importo' => '3,50', 'metodo' => 'carta']));
check('spesa in un mese presentato: bloccata', $r['success'] === false && str_contains($r['message'], '09/2026 è già presentata'), $r);
check('rimborsata con data', risposta(fn() => $c->rimborso(['mese' => '2026-09', 'azione' => 'rimborsata', 'data' => '2026-10-05']))['success']
    && $pdo->query("SELECT data_rimborso FROM {$p}rimborsi WHERE mese = '2026-09'")->fetchColumn() === '2026-10-05');
risposta(fn() => $c->rimborso(['mese' => '2026-09', 'azione' => 'riapri']));
$r = risposta(fn() => $c->save(['data' => '2026-09-12', 'categoria' => 'parcheggio', 'importo' => '3,50', 'metodo' => 'carta']));
check('riaperta: la spesa entra (importo con la virgola)', $r['success'] === true && (float)$pdo->query("SELECT importo FROM {$p}spese WHERE categoria = 'parcheggio'")->fetchColumn() === 3.5, $r);
check('importo non valido rifiutato', risposta(fn() => $c->save(['data' => '2026-09-12', 'importo' => '0']))['success'] === false);
$r = risposta(fn() => $c->delete($id));
check('eliminata: il movimento della carta torna libero', $r['success'] && in_array(6, array_map('intval', array_column($sp->movimentiCartaLiberi('2026-09-01', '2026-09-30'), 'id')), true), $r);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
