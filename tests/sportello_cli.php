<?php
/**
 * Prova da riga di comando del promemoria «Da Sportello»: la voce compare solo se il pulsante è già stato
 * usato e l'ultimo passaggio è di più di Promemoria::GIORNI_SPORTELLO giorni fa. SQLite in memoria.
 *
 *   php tests/sportello_cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/Promemoria.php';

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
$pdo = new PDO('sqlite::memory:');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec("CREATE TABLE {$p}import_file (id INTEGER PRIMARY KEY, sha256 TEXT NOT NULL UNIQUE, tipo TEXT NOT NULL,
    nome_file TEXT NOT NULL, estensione TEXT NOT NULL, dimensione INT NOT NULL DEFAULT 0, volte INT NOT NULL DEFAULT 1,
    user_id INT, prima_importazione TEXT NOT NULL, ultima_importazione TEXT NOT NULL)");
$file = function (string $nome, string $quando) use ($pdo, $p) {
    $pdo->prepare("INSERT INTO {$p}import_file (sha256, tipo, nome_file, estensione, prima_importazione, ultima_importazione)
        VALUES (?, 'fattura', ?, 'xml', ?, ?)")->execute([hash('sha256', $nome . $quando), $nome, $quando, $quando]);
};
$pr = new Promemoria($pdo, $p);

echo "Promemoria Sportello\n";
$file('IT01_trascinata.xml', '2026-10-01 09:00:00');
check('mai usato il pulsante: nessuna voce', $pr->sportelloFermo('2026-11-09') === []);

$file('sportello-IT01_A.xml', '2026-11-06 18:00:00');
check('ultimo passaggio 3 giorni fa: nessuna voce', $pr->sportelloFermo('2026-11-09') === []);
$v = $pr->sportelloFermo('2026-11-10');
check('4 giorni fa: una voce', count($v) === 1 && $v[0]['giorni'] === 4, $v);

$html = $pr->html(['oggi' => '2026-11-10', 'sportello' => $v, 'rate_da_fatturare' => [], 'incassi_scaduti' => [],
    'incassi_in_arrivo' => [], 'pagamenti_partner' => [], 'offerte_da_ricontattare' => [], 'offerte_in_scadenza' => []]);
check('voce nell\'email con la data', str_contains($html, 'Fatture di acquisto da prendere da Sportello (1)') && str_contains($html, '06/11/2026'));

$file('sportello-IT01_B.xml', '2026-11-10 08:00:00');
check('dopo un nuovo passaggio: nessuna voce', $pr->sportelloFermo('2026-11-10') === []);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
