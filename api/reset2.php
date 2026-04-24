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

try {
    $pdo = Database::getConnection();
    $prefix = getenv('DB_PREFIX') ?: 'mv_';
    
    $newHash = password_hash('Admin123!', PASSWORD_DEFAULT);
    
    // Fallback: If table exists, update password.
    $stmt = $pdo->prepare("UPDATE {$prefix}users SET password = ?");
    $stmt->execute([$newHash]);
    
    // Check if we can add columns
    $cols = ['blocked' => 'TINYINT(1) DEFAULT 0', 'failed_attempts' => 'INT DEFAULT 0', 'must_change_password' => 'TINYINT(1) DEFAULT 0', 'last_password_change' => 'DATETIME DEFAULT NULL'];
    $added = [];
    foreach ($cols as $col => $def) {
        try {
            $pdo->exec("ALTER TABLE {$prefix}users ADD COLUMN $col $def");
            $added[] = $col;
        } catch (Exception $e) {}
    }
    
    die(json_encode(['success' => true, 'added_columns' => $added, 'message' => 'Tutte le password sono state resettate a Admin123!']));
} catch (Exception $e) {
    die(json_encode(['success' => false, 'error' => $e->getMessage()]));
}
