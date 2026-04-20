<?php
/**
 * API Router for MV Consulting ERP
 */

require_once __DIR__ . '/Shared/Database.php';
require_once __DIR__ . '/Shared/Response.php';
require_once __DIR__ . '/Shared/Auth.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');

// Carica variabili d'ambiente (.env)
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

$module = $_POST['module'] ?? $_GET['module'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

try {
    if ($module === 'auth' && $action === 'login') {
        $email = $_POST['email'] ?? '';
        $password = $_POST['password'] ?? '';
        
        if (empty($email) || empty($password)) {
            Response::json(false, 'Credenziali non valide');
            exit;
        }

        $auth = new Auth();
        $user = $auth->login($email, $password);

        if ($user) {
            Response::json(true, 'Login effettuato', $user);
        } else {
            Response::json(false, 'Email o password errati');
        }
    } else {
        Response::json(false, 'Azione o modulo non supportato: ' . htmlspecialchars($module . '/' . $action));
    }
} catch (Exception $e) {
    Response::json(false, 'Errore Server: ' . $e->getMessage());
}
