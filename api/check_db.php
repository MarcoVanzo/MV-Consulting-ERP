<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/Shared/Database.php';

$envPath = __DIR__ . '/../.env';
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
    $db = Database::getConnection();
    $table = "ts_users";
    
    $newHash = password_hash('admin123', PASSWORD_BCRYPT);
    $email = 'marco@marcovanzo.com';
    
    // Check if column is password or pwd_hash
    $stmt = $db->query("SHOW COLUMNS FROM {$table} LIKE 'password'");
    $hasPassword = $stmt->fetch();
    $column = $hasPassword ? 'password' : 'pwd_hash';
    
    $update = $db->prepare("UPDATE {$table} SET {$column} = :hash WHERE email = :email");
    $update->execute(['hash' => $newHash, 'email' => $email]);
    
    echo "SUCCESS\n";
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}
