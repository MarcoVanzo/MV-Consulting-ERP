<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/api/Shared/Database.php';

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
    }
}

try {
    $pdo = Database::getConnection();
    $prefix = getenv('DB_PREFIX') ?: 'mv_';

    echo "CLIENTI:\n";
    $stmt = $pdo->query("SELECT id, ragione_sociale FROM {$prefix}clienti ORDER BY ragione_sociale ASC");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

    echo "\nSOTTOCLIENTI (primi 20):\n";
    $stmt2 = $pdo->query("SELECT id, cliente_id, nome FROM {$prefix}sottoclienti ORDER BY id DESC LIMIT 20");
    print_r($stmt2->fetchAll(PDO::FETCH_ASSOC));

    echo "\nTRASFERTE (ultime 10):\n";
    $stmt3 = $pdo->query("SELECT id, data_trasferta, cliente_id, sottocliente_id, descrizione FROM {$prefix}trasferte ORDER BY data_trasferta DESC LIMIT 10");
    print_r($stmt3->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
