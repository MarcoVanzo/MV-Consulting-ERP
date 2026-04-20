<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/api/Shared/Database.php';

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
        $_ENV[trim($name)] = trim($value);
    }
}

try {
    $pdo = Database::getConnection();
    $prefix = getenv('DB_PREFIX') ?: 'mv_';

    echo "TRASFERTE ITA:\n";
    $stmt = $pdo->query("SELECT id, data_trasferta, descrizione, cliente_id, sottocliente_id FROM {$prefix}trasferte WHERE data_trasferta >= '2026-04-16' ORDER BY data_trasferta ASC LIMIT 50");
    $trasferte = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach($trasferte as $t) {
        if (stripos($t['descrizione'], 'ita') !== false) {
            print_r($t);
        }
    }
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
