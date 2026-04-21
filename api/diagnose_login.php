<?php
/**
 * Diagnosi login — Verifica stato utenti nella tabella mv_users
 * TEMPORANEO: eliminare dopo la diagnosi!
 */

require_once __DIR__ . '/Shared/Database.php';

// Load .env
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
        $_ENV[trim($name)] = trim($value);
    }
}

header('Content-Type: application/json; charset=utf-8');

try {
    $pdo = Database::getConnection();
    $prefix = getenv('DB_PREFIX') ?: 'mv_';
    
    // 1. Verifica che la tabella mv_users esista
    $stmt = $pdo->query("SHOW TABLES LIKE '{$prefix}users'");
    $tableExists = $stmt->fetch();
    
    if (!$tableExists) {
        $stmt2 = $pdo->query("SHOW TABLES");
        $allTables = $stmt2->fetchAll(PDO::FETCH_COLUMN);
        echo json_encode(['error' => "Tabella {$prefix}users NON ESISTE!", 'available_tables' => $allTables]);
        exit;
    }
    
    // 2. Struttura della tabella
    $stmt = $pdo->query("DESCRIBE {$prefix}users");
    $columns = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 3. Lista utenti (senza esporre password complete)
    $stmt = $pdo->query("SELECT id, email, 
        CASE WHEN password IS NOT NULL AND password != '' THEN 'SET' ELSE 'EMPTY' END as pwd_status,
        LEFT(password, 7) as pwd_prefix,
        LENGTH(password) as pwd_length,
        role,
        created_at
        FROM {$prefix}users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // 4. Colonne disponibili
    $columnNames = array_column($columns, 'Field');
    
    echo json_encode([
        'success' => true,
        'table' => "{$prefix}users",
        'column_names' => $columnNames,
        'has_name' => in_array('name', $columnNames),
        'has_full_name' => in_array('full_name', $columnNames),
        'has_username' => in_array('username', $columnNames),
        'has_pwd_hash' => in_array('pwd_hash', $columnNames),
        'user_count' => count($users),
        'users' => $users,
        'jwt_secret_set' => !empty(getenv('JWT_SECRET')),
    ], JSON_PRETTY_PRINT);
    
} catch (Exception $e) {
    echo json_encode(['error' => $e->getMessage()]);
}
