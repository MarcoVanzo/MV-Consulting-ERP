<?php
/**
 * Prova da riga di comando delle vendite: numerazione offerte, commessa sempre da offerta
 * (offerta rapida), scheda cliente con storico, referenti. SQLite in memoria, dati inventati.
 *
 *   php tests/vendite_cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/Database.php';
require_once __DIR__ . '/../api/Shared/Response.php';
require_once __DIR__ . '/../api/Shared/CommessaService.php';
require_once __DIR__ . '/../api/Controllers/SchedaClienteController.php';

$ok = 0;
$ko = 0;
function check(string $nome, bool $cond, $dettaglio = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok   $nome\n"; return; }
    $ko++;
    echo "  FAIL $nome" . ($dettaglio !== null ? ' → ' . json_encode($dettaglio, JSON_UNESCAPED_UNICODE) : '') . "\n";
}
/** Esegue una chiamata che risponde con Response::json e restituisce la risposta. */
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
foreach ([
    "CREATE TABLE {$p}clienti (id INTEGER PRIMARY KEY, ragione_sociale TEXT)",
    "CREATE TABLE {$p}sottoclienti (id INTEGER PRIMARY KEY, cliente_id INT, nome TEXT)",
    "CREATE TABLE {$p}referenti (id INTEGER PRIMARY KEY, cliente_id INT, nome TEXT, ruolo TEXT, email TEXT, telefono TEXT,
        principale INT DEFAULT 0, note TEXT, deleted_at TEXT)",
    "CREATE TABLE {$p}attivita (id INTEGER PRIMARY KEY, cliente_id INT, offerta_id INT, tipo TEXT, data TEXT, testo TEXT, user_id INT,
        created_at TEXT DEFAULT CURRENT_TIMESTAMP)",
    "CREATE TABLE {$p}offerte (id INTEGER PRIMARY KEY, numero TEXT, versione INT, cliente_id INT, sottocliente_id INT, data_offerta TEXT,
        oggetto TEXT, tipo_commessa TEXT, num_giornate REAL, imponibile REAL, giorni_pagamento INT, condizioni_pagamento TEXT,
        stato TEXT, data_invio TEXT, data_esito TEXT, data_followup TEXT, prossima_azione TEXT, motivo_esito TEXT, probabilita INT,
        incarico_id INT, origine TEXT, note TEXT, deleted_at TEXT)",
    "CREATE TABLE {$p}incarichi (id INTEGER PRIMARY KEY, cliente_id INT, offerta_id INT, data_incarico TEXT, descrizione TEXT,
        tipo_commessa TEXT, importo_totale REAL, stato TEXT DEFAULT 'attivo')",
    "CREATE TABLE {$p}fatture (id INTEGER PRIMARY KEY, cliente_id INT, incarico_id INT, numero_fattura TEXT, data_emissione TEXT,
        data_scadenza TEXT, data_pagamento TEXT, imponibile REAL, importo_totale REAL, stato TEXT)",
] as $sql) $pdo->exec($sql);

echo "Protocollo delle lettere d'incarico\n";
$k = CommessaService::chiaviProtocollo('Prot. n. 820/2026 (SZ.DPS.F011.26)');
check('due codici del protocollo', $k === ['820/2026', 'SZ.DPS.F011.26'], $k);
check('stesso protocollo scritto a metà', (bool)array_intersect($k, CommessaService::chiaviProtocollo('0820 / 2026')));
check('anno diverso non combacia', !array_intersect($k, CommessaService::chiaviProtocollo('Prot. n. 820/2025 (SZ.DPS.F011.25)')));
check('protocollo vuoto', CommessaService::chiaviProtocollo(null) === []);

echo "Numerazione e commessa sempre da offerta\n";
$svc = new CommessaService($pdo, $p);
check('prima offerta dell\'anno', $svc->nuovoNumeroOfferta(2026) === 'OFF-2026-001');
$pdo->exec("INSERT INTO {$p}clienti (id, ragione_sociale) VALUES (1, 'Studio Esempio'), (2, 'Prospect Srl')");
$pdo->exec("INSERT INTO {$p}offerte (numero, versione, cliente_id, data_offerta, oggetto, stato, imponibile) VALUES
    ('OFF-2026-009', 1, 1, '2026-01-10', 'Vecchia', 'rifiutata', 1000), ('OFF-2025-044', 1, 1, '2025-11-10', 'Anno prima', 'accettata', 500)");
check('numero successivo al più alto dell\'anno', $svc->nuovoNumeroOfferta(2026) === 'OFF-2026-010');
$pdo->exec("INSERT INTO {$p}incarichi (id, cliente_id, data_incarico, descrizione, tipo_commessa, importo_totale) VALUES (5, 1, '2026-03-01', 'Assistenza DPO', 'dpo', 6000)");
$oid = $svc->offertaRapida(5, ['cliente_id' => 1, 'data_incarico' => '2026-03-01', 'descrizione' => 'Assistenza DPO', 'tipo_commessa' => 'dpo', 'importo_totale' => 6000]);
$o = $pdo->query("SELECT * FROM {$p}offerte WHERE id = $oid")->fetch();
check('offerta rapida già accettata e collegata', $o['stato'] === 'accettata' && (int)$o['incarico_id'] === 5 && (float)$o['imponibile'] === 6000.0 && $o['numero'] === 'OFF-2026-010', $o);
check('la commessa punta all\'offerta', (int)$pdo->query("SELECT offerta_id FROM {$p}incarichi WHERE id = 5")->fetchColumn() === $oid);

echo "Scheda cliente\n";
$pdo->exec("INSERT INTO {$p}fatture (cliente_id, incarico_id, numero_fattura, data_emissione, data_scadenza, data_pagamento, imponibile, importo_totale, stato) VALUES
    (1, 5, '7', '2026-04-01', '2026-05-01', '2026-05-03', 3000, 3660, 'pagata'), (1, 5, '9', '2026-06-01', '2026-07-01', NULL, 3000, 3660, 'emessa')");
$sc = new SchedaClienteController($pdo, $p);
$r = risposta(fn() => $sc->salvaNota(['cliente_id' => 1, 'tipo' => 'chiamata', 'data' => '2026-06-15', 'testo' => 'Sollecitato il pagamento della 9']));
check('nota salvata', $r['success'] === true, $r);
check('nota vuota rifiutata', risposta(fn() => $sc->salvaNota(['cliente_id' => 1, 'testo' => ' ']))['success'] === false);
$d = $sc->dati(1);
check('cliente con commesse e fatture', $d['cliente']['tipo'] === 'cliente');
check('situazione fatture', abs($d['situazione']['fatturato_totale'] - 6000) < 0.01 && abs($d['situazione']['scaduto'] - 3660) < 0.01, $d['situazione']);
$date = array_column($d['storico'], 'data');
$ordinate = $date; rsort($ordinate);
check('storico dal più recente', $date === $ordinate, $date);
check('storico con nota, fatture, incasso, commessa e offerte', count(array_intersect(['chiamata', 'fattura', 'incasso', 'commessa', 'offerta'], array_column($d['storico'], 'tipo'))) === 5, $d['storico']);
check('versioni sostituite fuori dalle offerte', count($d['offerte']) === 3);
check('prospect senza commesse né fatture', $sc->dati(2)['cliente']['tipo'] === 'prospect');
check('cliente inesistente', $sc->dati(99) === null);

echo "Referenti\n";
risposta(fn() => $sc->salvaReferente(['cliente_id' => 1, 'nome' => 'Anna Rossi', 'ruolo' => 'Amministrazione', 'principale' => '1']));
risposta(fn() => $sc->salvaReferente(['cliente_id' => 1, 'nome' => 'Luca Bianchi', 'email' => 'luca@example.test', 'principale' => '1']));
$ref = $sc->dati(1)['referenti'];
check('un solo referente principale', count($ref) === 2 && array_sum(array_column($ref, 'principale')) === 1 && $ref[0]['nome'] === 'Luca Bianchi', $ref);
check('email non valida rifiutata', risposta(fn() => $sc->salvaReferente(['cliente_id' => 1, 'nome' => 'X', 'email' => 'non-email']))['success'] === false);
check('referente senza cliente rifiutato', risposta(fn() => $sc->salvaReferente(['cliente_id' => 99, 'nome' => 'X']))['success'] === false);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
