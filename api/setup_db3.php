<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/Shared/Database.php';

// disable cache explicitly
header("Cache-Control: no-store, no-cache, must-revalidate, max-age=0");
header("Cache-Control: post-check=0, pre-check=0", false);
header("Pragma: no-cache");

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
    
    // 1. CLONIAMO LA STRUTTURA SENZA TOCCARE I DATI ORIGINALI
    $db->exec("CREATE TABLE IF NOT EXISTS mv_users LIKE ts_users");
    
    echo "✅ Separazione DB completata: Tabella 'mv_users' originata correttamente.\n";

    // 2. CREIAMO UN UTENTE DEDICATO PER MV
    $email = 'admin@mv-consulting.it';
    $newHash = password_hash('admin123', PASSWORD_BCRYPT);
    $id = uniqid('USR_');
    
    $check = $db->prepare("SELECT id FROM mv_users WHERE email = ?");
    $check->execute([$email]);
    if (!$check->fetch()) {
        try {
            // EXPLICT INSERT omitting is_active, just in case
            $insert = $db->prepare("INSERT INTO mv_users (id, email, pwd_hash, role) VALUES (?, ?, ?, 'admin')");
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
