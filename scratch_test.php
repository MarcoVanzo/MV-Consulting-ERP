<?php
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
    echo "=== CLIENTI ===\n";
    $stmt = $pdo->query("SELECT id, ragione_sociale FROM {$prefix}clienti");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    
    echo "=== SOTTOCLIENTI ===\n";
    $stmt = $pdo->query("SELECT id, nome FROM {$prefix}sottoclienti");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));
    
    echo "=== UNASSIGNED TRASFERTE ===\n";
    $stmt = $pdo->query("SELECT id, descrizione, data_trasferta FROM {$prefix}trasferte WHERE cliente_id IS NULL OR cliente_id = 0 ORDER BY data_trasferta DESC LIMIT 5");
    print_r($stmt->fetchAll(PDO::FETCH_ASSOC));

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
