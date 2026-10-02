<?php
/**
 * Prova da riga di comando degli indicatori (docs/indicatori.md). SQLite in memoria, dati inventati.
 *
 *   php tests/indicatori_cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/Indicatori.php';

$ok = 0;
$ko = 0;
function check(string $nome, bool $cond, $dettaglio = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok   $nome\n"; return; }
    $ko++;
    echo "  FAIL $nome" . ($dettaglio !== null ? ' → ' . json_encode($dettaglio, JSON_UNESCAPED_UNICODE) : '') . "\n";
}
function uguale(float $a, float $b): bool { return abs($a - $b) < 0.005; }

$p = 'mv_';
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
foreach ([
    "CREATE TABLE {$p}settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)",
    "CREATE TABLE {$p}incarichi (id INTEGER PRIMARY KEY, cliente_id INT, data_incarico TEXT, importo_totale REAL, stato TEXT DEFAULT 'attivo',
        fine_mese INT DEFAULT 0, giorno_pagamento INT)",
    "CREATE TABLE {$p}incarichi_rate (id INTEGER PRIMARY KEY, incarico_id INT, importo REAL, data_prevista TEXT, fattura_id INT)",
    "CREATE TABLE {$p}fatture (id INTEGER PRIMARY KEY, numero_fattura TEXT, data_emissione TEXT, cliente_id INT, sottocliente_id INT,
        incarico_id INT, imponibile REAL, importo_totale REAL, stato TEXT DEFAULT 'emessa', data_scadenza TEXT)",
    "CREATE TABLE {$p}fatture_passive (id INTEGER PRIMARY KEY, importo_totale REAL, ritenuta REAL DEFAULT 0, stato TEXT, data_scadenza TEXT)",
    "CREATE TABLE {$p}offerte (id INTEGER PRIMARY KEY, data_offerta TEXT, stato TEXT, imponibile REAL, deleted_at TEXT, probabilita INT,
        origine TEXT DEFAULT 'manuale', data_invio TEXT)",
] as $sql) $pdo->exec($sql);

$oggi = '2026-09-28';

// Commesse 2026: 10.000 (fatturata 6.000 in due sottoclienti, pagata 1.000) e 2.000 (non fatturata)
$pdo->exec("INSERT INTO {$p}incarichi (id, cliente_id, data_incarico, importo_totale) VALUES
    (1, 1, '2026-02-01', 10000), (2, 2, '2026-05-10', 2000), (3, 1, '2025-11-01', 500)");
// Fattura 10/2026 del cliente 1 spezzata su due sottoclienti (due record, un documento)
$pdo->exec("INSERT INTO {$p}fatture (numero_fattura, data_emissione, cliente_id, sottocliente_id, incarico_id, imponibile, importo_totale, stato, data_scadenza) VALUES
    ('10', '2026-03-01', 1, 11, 1, 1000, 1220, 'pagata', '2026-03-31'),
    ('10', '2026-03-01', 1, 12, 1, 2000, 2440, 'emessa', '2026-03-31'),
    ('11', '2026-09-01', 1, NULL, 1, 3000, 3660, 'emessa', '2026-10-31'),
    ('11', '2025-06-01', 2, NULL, NULL, 100, 122, 'scaduta', '2025-07-01'),
    ('12', '2026-04-01', 3, NULL, NULL, -200, -244, 'emessa', NULL)");
$pdo->exec("INSERT INTO {$p}incarichi_rate (incarico_id, importo, data_prevista, fattura_id) VALUES
    (1, 4000, '2026-10-15', NULL), (2, 2000, '2026-12-31', NULL), (2, 0, NULL, NULL), (1, 3000, '2026-03-01', 1)");
$pdo->exec("INSERT INTO {$p}fatture_passive (importo_totale, ritenuta, stato, data_scadenza) VALUES
    (800, 200, 'da_pagare', '2026-09-01'), (500, 0, 'da_pagare', '2026-12-01'), (999, 0, 'pagata', '2026-01-01')");
$pdo->exec("INSERT INTO {$p}offerte (data_offerta, stato, imponibile, deleted_at) VALUES
    ('2026-01-10', 'inviata', 5000, NULL), ('2026-02-10', 'bozza', 1500, NULL), ('2026-03-10', 'accettata', 10000, NULL),
    ('2026-04-10', 'rifiutata', 3000, NULL), ('2026-05-10', 'inviata', 9999, '2026-05-11'), ('2026-06-10', 'sostituita', 4000, NULL),
    ('2026-07-10', 'lead', 20000, NULL)");
$pdo->exec("UPDATE {$p}offerte SET probabilita = 80 WHERE stato = 'inviata' AND deleted_at IS NULL");
$pdo->exec("UPDATE {$p}offerte SET data_invio = '2026-04-01' WHERE stato = 'rifiutata'");

$ind = new Indicatori($pdo, $p, $oggi);

echo "Fatture 2026\n";
$f = $ind->fatture(2026, []);
check('fatturato = imponibile (nota di credito sottratta)', uguale($f['fatturato'], 5800), $f);
check('incassato IVA inclusa', uguale($f['incassato'], 1220), $f);
check('da incassare IVA inclusa', uguale($f['da_incassare'], 2440 + 3660 - 244), $f);
check('scaduto: solo scadenza passata e non pagata', uguale($f['scaduto'], 2440), $f);
check('documenti contati una volta anche se spezzati', $f['num_documenti'] === 3, $f);
check('fatture scadute contate per documento', $f['num_scaduti'] === 1, $f);
check('stessa numerazione in anni diversi = documenti diversi', $ind->fatture(null, [])['num_documenti'] === 4);

echo "Clienti esclusi\n";
$pdo->prepare("INSERT INTO {$p}settings VALUES (?, ?)")->execute([Indicatori::chiaveClientiEsclusi(), json_encode([3])]);
check('esclusione letta dalle impostazioni', $ind->clientiEsclusi() === [3]);
check('cliente escluso fuori dal fatturato', uguale($ind->fatture(2026)['fatturato'], 6000));
check('senza commessa: solo le fatture non collegate', uguale($ind->fatture(2026, [], null, true)['fatturato'], -200));
check('senza commessa: clienti esclusi tolti', uguale($ind->fatture(2026, null, null, true)['fatturato'], 0));
check('situazione di oggi: nessuna esclusione', uguale($ind->riepilogo()['fatture']['da_incassare'], 2440 + 3660 + 122 - 244));

echo "Commesse\n";
$c = $ind->commesse(2026);
check('valore commesse dell\'anno', uguale($c['valore'], 12000), $c);
check('fatturato commesse netto IVA', uguale($c['fatturato'], 6000), $c);
check('incassato commesse netto IVA', uguale($c['incassato_netto'], 1000), $c);
check('da fatturare mai negativo', uguale($c['da_fatturare'], 4000 + 2000), $c);
check('commesse con qualcosa da fatturare', $c['num_da_fatturare'] === 2, $c);
check('anno precedente separato', uguale($ind->commesse(2025)['valore'], 500));
$an = $ind->anzianitaCrediti(2026, []);
check('anzianità crediti: la somma è il da incassare', uguale(array_sum($an), $ind->fatture(2026, [])['da_incassare']), [$an, $ind->fatture(2026, [])]);
$pt = $ind->ponteFatturato(2026);
check('ponte Vendite → Fatture: i termini tornano', uguale($pt['su_commesse'] - $pt['fuori_anno'] + $pt['commesse_altri_anni'] + $pt['senza_commessa'], $pt['fatturato']), $pt);
check('ponte: fatture dell\'anno di tutti i clienti', uguale($pt['fatturato'], (float)$pdo->query("SELECT SUM(imponibile) FROM {$p}fatture WHERE data_emissione LIKE '2026%'")->fetchColumn()), $pt);

echo "Rate, offerte, partner\n";
$r = $ind->rateDaFatturare(30);
check('rate entro 30 giorni + rate senza data', uguale($r['importo'], 4000) && $r['num_rate'] === 2, $r);
check('orizzonte più lungo include le rate successive', uguale($ind->rateDaFatturare(120)['importo'], 6000));
$o = $ind->offerte(2026);
check('pipeline = solo offerte inviate non eliminate', uguale($o['pipeline'], 5000) && $o['num_inviate'] === 1, $o);
check('bozze a parte', uguale($o['bozze'], 1500), $o);
check('conversione = accettate / chiuse', $o['tasso_conversione'] === 50, $o);
// Offerta registrata con una commessa (rapida) e lead perso senza offerta: fuori dalla conversione
$pdo->exec("INSERT INTO {$p}offerte (data_offerta, stato, imponibile, origine) VALUES ('2026-08-01', 'accettata', 7000, 'rapida'), ('2026-08-02', 'rifiutata', 900, 'manuale')");
$o2 = $ind->offerte(2026);
check('conversione senza offerte rapide né lead persi', $o2['tasso_conversione'] === 50 && $o2['num_accettate'] === 2, $o2);
$pdo->exec("DELETE FROM {$p}offerte WHERE data_offerta IN ('2026-08-01', '2026-08-02')");
check('versioni sostituite non contate', $o['num_offerte'] === 5, $o);
check('lead contati a parte', $o['num_lead'] === 1 && abs($o['valore_lead'] - 20000) < 0.01, $o);
// 20000 × 10% (lead, predefinita) + 1500 × 30% (bozza, predefinita) + 5000 × 80% (inviata, sua)
check('pipeline pesata con probabilità propria o dello stato', abs($o['pipeline_pesata'] - (2000 + 450 + 4000)) < 0.01, $o);
$pp = $ind->partnerDaPagare();
check('partner da pagare = netto a pagare', uguale($pp['da_pagare'], 1300) && $pp['num_da_pagare'] === 2, $pp);
check('partner scaduti', uguale($pp['scaduto'], 800) && $pp['num_scaduti'] === 1, $pp);

echo "Dashboard Oggi\n";
foreach ([
    "ALTER TABLE {$p}incarichi ADD COLUMN offerta_id INT",
    "ALTER TABLE {$p}incarichi_rate ADD COLUMN giorni_pagamento INT DEFAULT 30",
    "ALTER TABLE {$p}offerte ADD COLUMN data_followup TEXT",
    "ALTER TABLE {$p}offerte ADD COLUMN iva_percentuale REAL",
    "CREATE TABLE {$p}movimenti_banca (id INTEGER PRIMARY KEY, stato TEXT, abbinabile INT DEFAULT 1, origine TEXT, importo REAL, classificazione TEXT DEFAULT 'classificato')",
    "CREATE TABLE {$p}trasferte (id INTEGER PRIMARY KEY, data_trasferta TEXT, cliente_id INT, sottocliente_id INT, mezzo_id INT,
        km_andata REAL DEFAULT 0, km_ritorno REAL DEFAULT 0, vitto REAL DEFAULT 0, alloggio REAL DEFAULT 0)",
    "CREATE TABLE {$p}mezzi (id INTEGER PRIMARY KEY, costo_km REAL)",
    "CREATE TABLE IF NOT EXISTS {$p}clienti (id INTEGER PRIMARY KEY, citta TEXT)",
    "CREATE TABLE IF NOT EXISTS {$p}sottoclienti (id INTEGER PRIMARY KEY, citta TEXT)",
    "CREATE TABLE {$p}spese (id INTEGER PRIMARY KEY, data TEXT, categoria TEXT, importo REAL, deleted_at TEXT, metodo TEXT DEFAULT 'contanti')",
] as $sql) $pdo->exec($sql);
$pdo->exec("UPDATE {$p}offerte SET data_followup = '2026-09-20' WHERE stato = 'inviata'");
$pdo->exec("INSERT INTO {$p}movimenti_banca (stato, abbinabile, origine, importo) VALUES
    ('da_riconciliare', 1, 'estratto_conto', 500), ('da_riconciliare', 0, 'estratto_conto', -3), ('da_riconciliare', 1, 'estratto_carta', -20), ('riconciliato', 1, 'estratto_conto', 90)");
$pdo->exec("UPDATE {$p}movimenti_banca SET classificazione = 'da_classificare' WHERE importo IN (500, -3, -20)");
$df = $ind->daFare(7);
check('scaduti per documento (fattura divisa contata una volta)', $df['incassi_scaduti']['num'] === 2 && abs($df['incassi_scaduti']['importo'] - (2440 + 122)) < 0.01, $df['incassi_scaduti']);
check('offerte da ricontattare (non eliminate)', $df['offerte_da_ricontattare']['num'] === 1, $df['offerte_da_ricontattare']);
check('movimenti da abbinare: solo conto e abbinabili', $df['movimenti_da_abbinare']['num'] === 1, $df['movimenti_da_abbinare']);
check('movimenti da sistemare contati una volta (abbinare o classificare)', $df['movimenti_da_sistemare']['num'] === 2, $df['movimenti_da_sistemare']);
check('partner da pagare entro 7 giorni (e scaduti)', $df['partner_da_pagare']['num'] === 1, $df['partner_da_pagare']);
$ia = $ind->incassiAttesi(12);
check('dodici settimane da lunedì', count($ia['settimane']) === 12 && $ia['settimane'][0]['dal'] === '2026-09-28', $ia['settimane'][0]);
check('scaduto separato', abs($ia['scaduto']['fatturate'] - (2440 + 122)) < 0.01, $ia['scaduto']);
$tot = array_sum(array_column($ia['settimane'], 'fatturate'));
check('fattura in scadenza il 31/10 nella sua settimana', abs($tot - 3660) < 0.01 && abs($ia['settimane'][4]['fatturate'] - 3660) < 0.01, $ia['settimane']);
// rata 4000 prevista 15/10 + 30 gg = 14/11, IVA 22% → settimana del 9/11 (indice 6)
check('rata da fatturare alla data di incasso con IVA', abs($ia['settimane'][6]['da_fatturare'] - 4880) < 0.01, $ia['settimane'][6]);
$pdo->exec("INSERT INTO {$p}trasferte (data_trasferta, cliente_id, km_andata, km_ritorno) VALUES
    ('2026-09-02', 1, 50, 50), ('2026-09-03', 2, 30, 30), ('2026-09-04', NULL, 10, 10), ('2026-08-30', 1, 99, 99)");
// Le spese stanno nella loro tabella: vitto il 3 (riduce l'indennità), un pedaggio il 4, una spesa eliminata
$pdo->exec("INSERT INTO {$p}spese (data, categoria, importo, deleted_at) VALUES
    ('2026-09-03', 'vitto', 15, NULL), ('2026-09-04', 'pedaggio', 7.5, NULL), ('2026-09-03', 'alloggio', 90, '2026-09-05')");
$tr = $ind->trasferte('2026-09-01', '2026-09-30', 0.5);
check('km e rimborso al costo/km', abs($tr['km'] - 180) < 0.01 && abs($tr['rimborso_km'] - 90) < 0.01, $tr);
check('indennità dalle regole (piena + ridotta)', abs($tr['indennita'] - (46.48 + 30.99)) < 0.01, $tr);
check('giornate senza cliente', $tr['num_giornate'] === 3 && $tr['num_senza_cliente'] === 1, $tr);
check('spese dalla tabella spese, eliminate escluse', abs($tr['spese'] - 22.5) < 0.01, $tr);
check('totale da rimborsare', abs($tr['da_rimborsare'] - (90 + 46.48 + 30.99 + 22.5)) < 0.01, $tr);
$pdo->exec("UPDATE {$p}spese SET metodo = 'carta' WHERE categoria = 'pedaggio'");
$trc = $ind->trasferte('2026-09-01', '2026-09-30', 0.5);
check('spese con carta aziendale: rendicontate ma non rimborsate', abs($trc['spese'] - 22.5) < 0.01 && abs($trc['spese_aziendali'] - 7.5) < 0.01
    && abs($trc['da_rimborsare'] - (90 + 46.48 + 30.99 + 15)) < 0.01, $trc);
$pdo->exec("INSERT INTO {$p}mezzi (id, costo_km) VALUES (1, 0.8)");
$pdo->exec("UPDATE {$p}trasferte SET mezzo_id = 1 WHERE data_trasferta = '2026-09-02'");
check('costo ACI del mezzo al posto di quello generale', abs($ind->trasferte('2026-09-01', '2026-09-30', 0.5)['rimborso_km'] - (100 * 0.8 + 80 * 0.5)) < 0.01);
$t0 = $ind->trasferte('2026-09-01', '2026-09-30', null);
check('senza costo generale: solo i km del mezzo con costo, gli altri segnalati', abs($t0['rimborso_km'] - 80) < 0.01 && $t0['km_senza_costo'] === true, $t0);
$pdo->exec("UPDATE {$p}trasferte SET mezzo_id = NULL");
check('nessun costo noto: rimborso km non calcolato', $ind->trasferte('2026-09-01', '2026-09-30', null)['rimborso_km'] === null);

echo "Casi limite\n";
// Nota di credito aperta che storna una fattura scaduta dello stesso cliente (stesso numero)
$pdo->exec("INSERT INTO {$p}fatture (numero_fattura, data_emissione, cliente_id, imponibile, importo_totale, stato, data_scadenza) VALUES
    ('50', '2026-05-01', 7, 1000, 1220, 'emessa', '2026-06-01'), ('50', '2026-05-20', 7, -1000, -1220, 'emessa', NULL)");
$fn = $ind->fatture(2026, [], 7);
check('nota di credito: documento a parte e scaduto azzerato', $fn['num_documenti'] === 2 && abs($fn['scaduto']) < 0.01 && abs($fn['da_incassare']) < 0.01, $fn);
$pdo->exec("DELETE FROM {$p}fatture WHERE cliente_id = 7");
// Commessa 2 da 2000 fatturata per intero senza aggancio alla rata: la rata non è più da fatturare
$pdo->exec("INSERT INTO {$p}fatture (numero_fattura, data_emissione, cliente_id, incarico_id, imponibile, importo_totale, stato, data_scadenza) VALUES ('60', '2026-09-20', 2, 2, 2002, 2442.44, 'emessa', '2026-10-20')");
check('rata di una commessa già fatturata esclusa', abs($ind->rateDaFatturare(120)['importo'] - 4000) < 0.01, $ind->rateDaFatturare(120));
$pdo->exec("DELETE FROM {$p}fatture WHERE numero_fattura = '60'");
// Ora legale: lunedì 29/03/2027 deve cadere nella sua settimana
$pdo->exec("INSERT INTO {$p}fatture (numero_fattura, data_emissione, cliente_id, imponibile, importo_totale, stato, data_scadenza) VALUES ('70', '2027-03-01', 8, 100, 122, 'emessa', '2027-03-29')");
date_default_timezone_set('Europe/Rome');
$iaDst = (new Indicatori($pdo, $p, '2027-03-10'))->incassiAttesi(4);
check('settimana giusta dopo il cambio dell\'ora', abs($iaDst['settimane'][3]['fatturate'] - 122) < 0.01 && $iaDst['settimane'][3]['dal'] === '2027-03-29', $iaDst['settimane']);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
