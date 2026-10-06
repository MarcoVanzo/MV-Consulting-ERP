<?php
/**
 * Prova da riga di comando del cestino delle commesse: eliminare salva la fotografia, ripristinare rimette
 * commessa, rate, costi, offerte e fatture come erano. SQLite in memoria, dati inventati.
 *
 *   php tests/cestino_cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/Database.php';
require_once __DIR__ . '/../api/Shared/Cestino.php';

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
    "CREATE TABLE {$p}incarichi (id INTEGER PRIMARY KEY, cliente_id INT, descrizione TEXT, importo_totale REAL, pdf_path TEXT)",
    "CREATE TABLE {$p}incarichi_rate (id INTEGER PRIMARY KEY, incarico_id INT, ordine INT, descrizione TEXT, importo REAL, fattura_id INT)",
    "CREATE TABLE {$p}commessa_costi (id INTEGER PRIMARY KEY, incarico_id INT, offerta_id INT, descrizione TEXT, offerta_fornitore_file TEXT)",
    "CREATE TABLE {$p}offerte (id INTEGER PRIMARY KEY, incarico_id INT, stato TEXT, origine TEXT, data_invio TEXT, data_esito TEXT, deleted_at TEXT)",
    "CREATE TABLE {$p}fatture (id INTEGER PRIMARY KEY, incarico_id INT)",
    "CREATE TABLE {$p}fatture_passive (id INTEGER PRIMARY KEY, incarico_id INT)",
    "CREATE TABLE {$p}cestino (id INTEGER PRIMARY KEY AUTOINCREMENT, tabella TEXT, record_id INT, descrizione TEXT, dati TEXT,
        file_refs TEXT, user_id INT, created_at TEXT DEFAULT CURRENT_TIMESTAMP, ripristinato_at TEXT)",
    "INSERT INTO {$p}incarichi VALUES (7, 1, 'Commessa di prova', 1000, 'documenti/abc.pdf'), (8, 1, 'Altra', 500, NULL)",
    "INSERT INTO {$p}incarichi_rate VALUES (1, 7, 1, 'Acconto', 500, 11), (2, 7, 2, 'Saldo', 500, NULL), (3, 8, 1, 'Saldo', 500, NULL)",
    "INSERT INTO {$p}commessa_costi VALUES (1, 7, NULL, 'Partner', 'documenti/costo.pdf'), (2, 7, 5, 'Dall''offerta', NULL)",
    "INSERT INTO {$p}offerte VALUES (5, 7, 'accettata', 'normale', '2026-01-10', '2026-01-20', NULL), (6, 7, 'accettata', 'rapida', NULL, '2026-01-20', NULL)",
    "INSERT INTO {$p}fatture VALUES (11, 7), (12, 8)",
    "INSERT INTO {$p}fatture_passive VALUES (21, 7)",
] as $sql) $pdo->exec($sql);
$foto = fn() => [
    $pdo->query("SELECT * FROM {$p}incarichi WHERE id = 7")->fetchAll(),
    $pdo->query("SELECT * FROM {$p}incarichi_rate WHERE incarico_id = 7 ORDER BY id")->fetchAll(),
    $pdo->query("SELECT * FROM {$p}commessa_costi ORDER BY id")->fetchAll(),
    $pdo->query("SELECT * FROM {$p}offerte ORDER BY id")->fetchAll(),
    $pdo->query("SELECT * FROM {$p}fatture ORDER BY id")->fetchAll(),
    $pdo->query("SELECT * FROM {$p}fatture_passive ORDER BY id")->fetchAll(),
];
$prima = $foto();

echo "Elimina\n";
$cid = Cestino::eliminaCommessa($pdo, $p, 7, 3);
check('commessa tolta', !$pdo->query("SELECT 1 FROM {$p}incarichi WHERE id = 7")->fetch());
check('rate tolte, quelle di altre commesse no', (int)$pdo->query("SELECT COUNT(*) c FROM {$p}incarichi_rate")->fetch()['c'] === 1);
check('fatture slegate', $pdo->query("SELECT incarico_id FROM {$p}fatture WHERE id = 11")->fetch()['incarico_id'] === null);
check('costo dell\'offerta resta all\'offerta', (int)$pdo->query("SELECT COUNT(*) c FROM {$p}commessa_costi")->fetch()['c'] === 1);
check('offerta vera di nuovo inviata, rapida eliminata', $pdo->query("SELECT stato FROM {$p}offerte WHERE id = 5")->fetch()['stato'] === 'inviata'
    && $pdo->query("SELECT deleted_at FROM {$p}offerte WHERE id = 6")->fetch()['deleted_at'] !== null);
check('nel cestino', count(Cestino::lista($pdo, $p)) === 1 && Cestino::lista($pdo, $p)[0]['record_id'] == 7);
check('file trattenuti', Cestino::fileTrattenuti($pdo, $p) === ['documenti/abc.pdf', 'documenti/costo.pdf'], Cestino::fileTrattenuti($pdo, $p));
try { Cestino::eliminaCommessa($pdo, $p, 99); check('commessa inesistente rifiutata', false); }
catch (RuntimeException $e) { check('commessa inesistente rifiutata', true); }

echo "Ripristina\n";
check('stesso id', Cestino::ripristinaCommessa($pdo, $p, $cid) === 7);
check('tutto come prima', $foto() == $prima, ['prima' => $prima, 'dopo' => $foto()]);
check('cestino vuoto e niente file trattenuti', Cestino::lista($pdo, $p) === [] && Cestino::fileTrattenuti($pdo, $p) === []);
try { Cestino::ripristinaCommessa($pdo, $p, $cid); check('secondo ripristino rifiutato', false); }
catch (RuntimeException $e) { check('secondo ripristino rifiutato', true); }

echo "Fattura passata a un'altra commessa nel frattempo\n";
$cid = Cestino::eliminaCommessa($pdo, $p, 7);
$pdo->exec("UPDATE {$p}fatture SET incarico_id = 8 WHERE id = 11");
Cestino::ripristinaCommessa($pdo, $p, $cid);
check('non viene ripresa', (int)$pdo->query("SELECT incarico_id FROM {$p}fatture WHERE id = 11")->fetch()['incarico_id'] === 8);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
