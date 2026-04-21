<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/api/Shared/Database.php';

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
        $_ENV[trim($name)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

try {
    $pdo = Database::getConnection();
    $prefix = getenv('DB_PREFIX') ?: 'mv_';
    
    $unindustriaId = 1; // UNINDUSTRIA SERVIZI & FORMAZIONE

    $newSottoclienti = [
        'UNICOLOR S.R.L.',
        'ONFIELD S.R.L.'
    ];

    foreach ($newSottoclienti as $nome) {
        // Check if already exists
        $stmt = $pdo->prepare("SELECT id FROM {$prefix}sottoclienti WHERE cliente_id = ? AND LOWER(nome) LIKE ?");
        $searchName = '%' . strtolower(explode(' ', $nome)[0]) . '%';
        $stmt->execute([$unindustriaId, $searchName]);
        $existing = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($existing) {
            echo "⚠️ '$nome' già presente (ID: {$existing['id']}), skip.\n";
        } else {
            $stmtIns = $pdo->prepare("INSERT INTO {$prefix}sottoclienti (cliente_id, nome, partita_iva, codice_fiscale, riferimento, indirizzo, citta, cap, provincia, pec, sdi, email) VALUES (?, ?, '', '', '', '', '', '', '', '', '', '')");
            $stmtIns->execute([$unindustriaId, $nome]);
            $newId = $pdo->lastInsertId();
            echo "✅ '$nome' creato come sottocliente ID $newId di Unindustria.\n";
        }
    }

    echo "\nDone!\n";
} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
