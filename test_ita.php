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

    echo "TUTTI I CLIENTI:\n";
    $stmt = $pdo->query("SELECT id, ragione_sociale FROM {$prefix}clienti ORDER BY id DESC LIMIT 10");
    $clienti = $stmt->fetchAll(PDO::FETCH_ASSOC);
    print_r($clienti);
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
