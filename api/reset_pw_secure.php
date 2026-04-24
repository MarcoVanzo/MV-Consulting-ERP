<?php
$token = $_GET['token'] ?? '';
if ($token !== 'mv-secret-1234') {
    die('Unauthorized');
}

require_once __DIR__ . '/Shared/Database.php';

$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value, " \t\n\r\0\x0B\""));
    }
}

try {
    $db = Database::getConnection();
    $prefix = getenv('DB_PREFIX') ?: 'mv_';
    
    $newPassword = 'Password123!';
    $newHash = password_hash($newPassword, PASSWORD_DEFAULT);
    
    $stmt = $db->query("SELECT id, email FROM {$prefix}users");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($users as $u) {
        $db->prepare("UPDATE {$prefix}users SET password = ?, pwd_hash = ?, failed_attempts = 0, blocked = 0, must_change_password = 1 WHERE id = ?")
           ->execute([$newHash, $newHash, $u['id']]);
        echo "Reset password for {$u['email']}<br>\n";
    }
    
    echo "<b>All passwords have been reset to: $newPassword</b>";
} catch (Exception $e) {
    echo "Error: " . $e->getMessage();
}
