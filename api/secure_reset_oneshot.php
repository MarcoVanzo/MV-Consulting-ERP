<?php
// ONE-SHOT secure password reset — auto-deletes after use
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        putenv(trim($k) . '=' . trim($v, " \t\n\r\0\x0B\""));
    }
}

$key = $_SERVER['HTTP_X_DEPLOY_KEY'] ?? '';
$expected = getenv('DEPLOY_KEY') ?: '';
if (empty($expected) || $key !== $expected) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

header('Content-Type: application/json');

require_once __DIR__ . '/Shared/Database.php';
$pdo = Database::getConnection();
$prefix = getenv('DB_PREFIX') ?: 'mv_';

function genPass() {
    $chars = 'abcdefghijkmnopqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';
    $pass = '';
    for ($i = 0; $i < 16; $i++) $pass .= $chars[random_int(0, strlen($chars) - 1)];
    return $pass;
}

$stmt = $pdo->query("SELECT id, email FROM {$prefix}users");
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

$results = [];
foreach ($users as $u) {
    $newPass = genPass();
    $hash = password_hash($newPass, PASSWORD_DEFAULT);
    $pdo->prepare("UPDATE {$prefix}users SET password = ?, must_change_password = 1 WHERE id = ?")
         ->execute([$hash, $u['id']]);
    $results[] = ['id' => $u['id'], 'email' => $u['email'], 'temp_password' => $newPass];
}

// Self destruct
unlink(__FILE__);

echo json_encode(['success' => true, 'users' => $results], JSON_PRETTY_PRINT);
