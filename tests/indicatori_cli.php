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
    "CREATE TABLE {$p}incarichi (id INTEGER PRIMARY KEY, cliente_id INT, data_incarico TEXT, importo_totale REAL, stato TEXT DEFAULT 'attivo')",
    "CREATE TABLE {$p}incarichi_rate (id INTEGER PRIMARY KEY, incarico_id INT, importo REAL, data_prevista TEXT, fattura_id INT)",
    "CREATE TABLE {$p}fatture (id INTEGER PRIMARY KEY, numero_fattura TEXT, data_emissione TEXT, cliente_id INT, sottocliente_id INT,
        incarico_id INT, imponibile REAL, importo_totale REAL, stato TEXT DEFAULT 'emessa', data_scadenza TEXT)",
    "CREATE TABLE {$p}fatture_passive (id INTEGER PRIMARY KEY, importo_totale REAL, ritenuta REAL DEFAULT 0, stato TEXT, data_scadenza TEXT)",
    "CREATE TABLE {$p}offerte (id INTEGER PRIMARY KEY, data_offerta TEXT, stato TEXT, imponibile REAL, deleted_at TEXT)",
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
    ('2026-04-10', 'rifiutata', 3000, NULL), ('2026-05-10', 'inviata', 9999, '2026-05-11'), ('2026-06-10', 'sostituita', 4000, NULL)");

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
check('situazione di oggi: nessuna esclusione', uguale($ind->riepilogo()['fatture']['da_incassare'], 2440 + 3660 + 122 - 244));

echo "Commesse\n";
$c = $ind->commesse(2026);
check('valore commesse dell\'anno', uguale($c['valore'], 12000), $c);
check('fatturato commesse netto IVA', uguale($c['fatturato'], 6000), $c);
check('incassato commesse netto IVA', uguale($c['incassato_netto'], 1000), $c);
check('da fatturare mai negativo', uguale($c['da_fatturare'], 4000 + 2000), $c);
check('commesse con qualcosa da fatturare', $c['num_da_fatturare'] === 2, $c);
check('anno precedente separato', uguale($ind->commesse(2025)['valore'], 500));

echo "Rate, offerte, partner\n";
$r = $ind->rateDaFatturare(30);
check('rate entro 30 giorni + rate senza data', uguale($r['importo'], 4000) && $r['num_rate'] === 2, $r);
check('orizzonte più lungo include le rate successive', uguale($ind->rateDaFatturare(120)['importo'], 6000));
$o = $ind->offerte(2026);
check('pipeline = solo offerte inviate non eliminate', uguale($o['pipeline'], 5000) && $o['num_inviate'] === 1, $o);
check('bozze a parte', uguale($o['bozze'], 1500), $o);
check('conversione = accettate / chiuse', $o['tasso_conversione'] === 50, $o);
check('versioni sostituite non contate', $o['num_offerte'] === 4, $o);
$pp = $ind->partnerDaPagare();
check('partner da pagare = netto a pagare', uguale($pp['da_pagare'], 1300) && $pp['num_da_pagare'] === 2, $pp);
check('partner scaduti', uguale($pp['scaduto'], 800) && $pp['num_scaduti'] === 1, $pp);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
