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
    
    $email = 'admin@mv-consulting.it';
    $newHash = password_hash('admin123', PASSWORD_BCRYPT);
    $id = uniqid('USR_');
    
    $stmt = $db->query("SHOW COLUMNS FROM mv_users LIKE 'password'");
    $hasPassword = $stmt->fetch();
    $column = $hasPassword ? 'password' : 'pwd_hash';
    $nameColumn = $hasPassword ? 'name' : 'full_name';
    
    $check = $db->prepare("SELECT id FROM mv_users WHERE email = ?");
    $check->execute([$email]);
    if (!$check->fetch()) {
        try {
            // FIXED QUERY
            $insert = $db->prepare("INSERT INTO mv_users (id, email, {$column}, role, {$nameColumn}, is_active) VALUES (?, ?, ?, 'admin', 'Admin MV Consulting', 1)");
            $insert->execute([$id, $email, $newHash]);
            echo "✅ UTENTE CREATO: $email (pw: admin123) in ambito MV (separato)!\n";
        } catch (Exception $e) {
             echo "❌ Errore crezione utente specifico mv: " . $e->getMessage() . "\n";
        }
    } else {
        echo "✅ Utente $email già presente in mv_users.\n";
    }
    
} catch (Exception $e) {
    echo "❌ ERROR: " . $e->getMessage() . "\n";
}

@unlink(__FILE__);
