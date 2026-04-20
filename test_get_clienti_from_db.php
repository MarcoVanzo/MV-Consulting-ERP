<?php
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value, '"\''));
    }
}

require 'api/Shared/Database.php';

try {
    $pdo = Database::getConnection();
    // Get all clients
    $stmt = $pdo->query("SELECT id, ragione_sociale, partita_iva FROM mv_clienti");
    $clienti = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "mv_clienti: " . count($clienti) . "\n";
    print_r($clienti);
    echo "\n";

    // Get all sottoclienti
    $stmt2 = $pdo->query("SELECT id, cliente_id, nome, partita_iva FROM mv_sottoclienti");
    $sottoclienti = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    echo "mv_sottoclienti: " . count($sottoclienti) . "\n";
    print_r($sottoclienti);
    echo "\n";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage() . "\n";
}
