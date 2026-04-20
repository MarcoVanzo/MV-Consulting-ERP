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
    
    $prefix = getenv('DB_PREFIX') ?: 'mv_';
    $table = "{$prefix}users";
    echo "📊 Verifico la tabella: {$table}\n\n";

    $stmt = $db->query("SELECT id, email, role FROM {$table} LIMIT 10");
    $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
    echo "👥 Utenti presenti (Top 10):\n";
    foreach($users as $u) {
        echo "   - Email: " . ($u['email'] ?? 'N/A') . " (Ruolo: " . ($u['role'] ?? 'N/A') . ")\n";
    }
    
    $newHash = password_hash('admin123', PASSWORD_BCRYPT);
    $email = 'admin@fusionerp.it';
    
    // update the pwd_hash or password column
    $stmt = $db->query("SHOW COLUMNS FROM {$table} LIKE 'password'");
    $hasPassword = $stmt->fetch();
    $column = $hasPassword ? 'password' : 'pwd_hash';
    
    $update = $db->prepare("UPDATE {$table} SET {$column} = :hash WHERE email = :email");
    $update->execute(['hash' => $newHash, 'email' => $email]);
    
    if ($update->rowCount() > 0) {
        echo "\n✅ Reset completato: '$email' -> 'admin123'. \n";
    } else {
        echo "\n⚠️ Nessun reset fatto per '$email' (forse l'utente non c'e'?).\n";
    }
    
} catch (Exception $e) {
    echo "\n❌ Errore irreversibile: " . $e->getMessage() . "\n";
}
