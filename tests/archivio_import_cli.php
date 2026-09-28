<?php
/**
 * Prova da riga di comando dell'archivio dei file importati. SQLite in memoria, cartella temporanea.
 *
 *   php tests/archivio_import_cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/ArchivioImport.php';

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

$tmp = sys_get_temp_dir() . '/archivio_import_' . bin2hex(random_bytes(4)) . '/';
ArchivioImport::$cartella = $tmp;

echo "Archivio\n";
$xml = '<FatturaElettronica><Numero>1</Numero></FatturaElettronica>';
$r1 = ArchivioImport::daRichiesta($pdo, $p, 'contabilita', 'import_xml', ['xml' => $xml, 'file_nome' => 'IT01_ABC.xml']);
check('file XML archiviato', $r1 !== null && $r1['gia_importato'] === null, $r1);
check('salvato con nome neutro (.bin)', is_file($tmp . hash('sha256', $xml) . '.bin'));
$r2 = ArchivioImport::daRichiesta($pdo, $p, 'contabilita', 'import_xml', ['xml' => $xml, 'file_nome' => 'copia.xml']);
check('stesso file: riconosciuto come già importato', $r2 !== null && $r2['id'] === $r1['id'] && $r2['gia_importato'] !== null, $r2);
check('contatore delle importazioni', (int)$pdo->query("SELECT volte FROM {$p}import_file")->fetchColumn() === 2);
check('azione che non è un import: niente', ArchivioImport::daRichiesta($pdo, $p, 'contabilita', 'list', ['xml' => $xml]) === null);
check('import senza file: niente', ArchivioImport::daRichiesta($pdo, $p, 'contabilita', 'import_pdf', ['pages' => ['testo']]) === null);
$p7m = "\x30\x82\x01\x00firmato";
$r3 = ArchivioImport::daRichiesta($pdo, $p, 'passive', 'import_xml', ['file_b64' => base64_encode($p7m), 'file_nome' => 'IT02.xml.p7m']);
check('p7m in base64 decodificato e archiviato', $r3 !== null && is_file($tmp . hash('sha256', $p7m) . '.bin'), $r3);
$r4 = ArchivioImport::daRichiesta($pdo, $p, 'riconciliazione', 'import_estratto', ['xml' => '<Document/>', 'tipo' => 'carta', 'file_nome' => 'a.exe']);
$t = $pdo->query("SELECT tipo, estensione FROM {$p}import_file WHERE id = " . (int)$r4['id'])->fetch();
check('estratto carta con tipo proprio ed estensione non ammessa neutralizzata', $t['tipo'] === 'estratto_carta' && $t['estensione'] === 'bin', $t);
check('errore del DB non blocca: restituisce null', (function () use ($p) {
    $rotto = new PDO('sqlite::memory:');
    $rotto->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    return ArchivioImport::daRichiesta($rotto, $p, 'contabilita', 'import_xml', ['xml' => '<x/>']) === null;
})());

array_map('unlink', glob($tmp . '*') ?: []);
@rmdir($tmp);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
