<?php
require_once __DIR__ . '/Shared/Database.php';

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

$pdo = Database::getConnection();
$prefix = getenv('DB_PREFIX') ?: 'mv_';

$queries = [
    "ALTER TABLE {$prefix}sottoclienti ADD COLUMN partita_iva VARCHAR(16) DEFAULT NULL AFTER riferimento",
    "ALTER TABLE {$prefix}sottoclienti ADD COLUMN codice_fiscale VARCHAR(20) DEFAULT NULL AFTER partita_iva",
    "ALTER TABLE {$prefix}sottoclienti ADD COLUMN pec VARCHAR(150) DEFAULT NULL AFTER provincia",
    "ALTER TABLE {$prefix}sottoclienti ADD COLUMN sdi VARCHAR(10) DEFAULT NULL AFTER pec"
];

foreach ($queries as $sql) {
    try {
        $pdo->exec($sql);
        echo "OK: $sql\n";
    } catch (PDOException $e) {
        $msg = $e->getMessage();
        if (strpos($msg, 'Duplicate column name') !== false) {
            echo "SKIPPED (Duplicate): $sql\n";
        } else {
            echo "ERROR: $msg\nsql: $sql\n";
        }
    }
}
echo "Migration complete.\n";
