<?php
/**
 * Debug: mostra i numeri fattura presenti nel DB
 * DA RIMUOVERE DOPO IL DEBUG
 */
require_once __DIR__ . '/api/Shared/Database.php';

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
    }
}

$pdo = Database::getConnection();
$prefix = getenv('DB_PREFIX') ?: 'mv_';

header('Content-Type: application/json');

$stmt = $pdo->query("SELECT id, numero_fattura, importo_totale, stato, sottocliente_id, data_emissione 
    FROM {$prefix}fatture ORDER BY numero_fattura ASC, id ASC");
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode([
    'total' => count($rows),
    'fatture' => $rows
], JSON_PRETTY_PRINT);
