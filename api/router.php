<?php
/**
 * API Router for MV Consulting ERP
 * v2.0 — Moduli: Auth, Clienti, Sottoclienti, Trasferte, Contabilità
 */

require_once __DIR__ . '/Shared/Database.php';
require_once __DIR__ . '/Shared/Audit.php';
require_once __DIR__ . '/Shared/Response.php';
require_once __DIR__ . '/Shared/JWT.php';
require_once __DIR__ . '/Shared/Auth.php';
require_once __DIR__ . '/Controllers/ClientiController.php';
require_once __DIR__ . '/Controllers/SottoclientiController.php';
require_once __DIR__ . '/Controllers/TrasferteController.php';
require_once __DIR__ . '/Controllers/ContabilitaController.php';
require_once __DIR__ . '/Controllers/IncarchiController.php';
require_once __DIR__ . '/Controllers/AdminController.php';
require_once __DIR__ . '/Controllers/GoogleAuthController.php';

header('Content-Type: application/json; charset=utf-8');

// CORS — Restrittivo: solo dal dominio di produzione
$allowedOrigins = ['https://www.mv-consulting.it', 'https://mv-consulting.it'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
if (in_array($origin, $allowedOrigins)) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
} elseif (getenv('APP_ENV') !== 'production') {
    // In locale, permetti tutto per sviluppo
    header('Access-Control-Allow-Origin: ' . ($origin ?: '*'));
    if ($origin) header('Access-Control-Allow-Credentials: true');
}
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

// Security Headers
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('X-XSS-Protection: 1; mode=block');
header('Referrer-Policy: strict-origin-when-cross-origin');
header("Content-Security-Policy: default-src 'self'; script-src 'self' 'unsafe-inline'; style-src 'self' 'unsafe-inline' https://fonts.googleapis.com; font-src 'self' https://fonts.gstatic.com; img-src 'self' data:; connect-src 'self' https://www.mv-consulting.it");
if (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on') {
    header('Strict-Transport-Security: max-age=31536000; includeSubDomains');
}

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// Carica variabili d'ambiente (.env) — centralizzato
require_once __DIR__ . '/Shared/Env.php';
Env::load(__DIR__ . '/../.env');

// Validazione DB_PREFIX: solo caratteri sicuri (alfanumerici + underscore)
$dbPrefix = getenv('DB_PREFIX') ?: 'mv_';
if (!preg_match('/^[a-zA-Z0-9_]+$/', $dbPrefix)) {
    die(json_encode(['success' => false, 'message' => 'Configurazione DB_PREFIX non valida']));
}

$module = $_POST['module'] ?? $_GET['module'] ?? '';
$action = $_POST['action'] ?? $_GET['action'] ?? '';

// Parse JSON body for non-form requests
$input = [];
$rawInput = file_get_contents('php://input');
if ($rawInput) {
    $jsonInput = json_decode($rawInput, true);
    if ($jsonInput) {
        $input = $jsonInput;
        if (empty($module)) $module = $input['module'] ?? '';
        if (empty($action)) $action = $input['action'] ?? '';
    }
}

// Merge POST + JSON input
$data = array_merge($_POST, $input);

// ═════════════════════════════════════════════
// GLOBAL AUTHENTICATION MIDDLEWARE
// ═════════════════════════════════════════════
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
// Solo auth e callback OAuth sono pubblici.
$public_actions = [
    'auth' => ['login', 'request_reset', 'reset_password'],
    'google' => ['auth', 'callback']
];
$isPublic = isset($public_actions[$module]) && in_array($action, $public_actions[$module]);

// Deploy key auth: admin/migrate può essere autenticato via X-Deploy-Key
$isDeployKeyAuth = false;
if ($module === 'admin' && $action === 'migrate') {
    $deployKey = $_SERVER['HTTP_X_DEPLOY_KEY'] ?? '';
    $serverKey = getenv('DEPLOY_KEY') ?: '';
    if ($deployKey && $serverKey && hash_equals($serverKey, $deployKey)) {
        $isDeployKeyAuth = true;
    }
}

if (!$isPublic && !$isDeployKeyAuth) {
    $jwt = $_COOKIE['auth_token'] ?? '';
    if (!$jwt && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $jwt = $matches[1];
    }
    
    // SECURITY: JWT accettato tramite Cookie HttpOnly o Authorization Header

    if ($jwt) {
        $secret = getenv('JWT_SECRET');
        if (!$secret) {
            error_log('CRITICAL: JWT_SECRET non configurato nel .env');
            Response::json(false, 'Errore di configurazione server', null, 500);
            exit;
        }
        $decoded = JWT::decode($jwt, $secret);
        if (!$decoded) {
            Response::json(false, 'Token non valido o scaduto', null, 401);
            exit;
        }
        $GLOBALS['userContext'] = $decoded;
    } else {
        Response::json(false, 'Autorizzazione negata. Token mancante nel payload', null, 401);
        exit;
    }
}

// ═════════════════════════════════════════════
// CSRF PROTECTION (Double Submit Cookie)
// ═════════════════════════════════════════════
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !$isPublic && !$isDeployKeyAuth) {
    $csrfCookie = $_COOKIE['csrf_token'] ?? '';
    $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
    
    if (empty($csrfCookie) || empty($csrfHeader) || !hash_equals($csrfCookie, $csrfHeader)) {
        Response::json(false, 'Richiesta non valida (CSRF).', null, 403);
        exit;
    }
}

try {
    require_once __DIR__ . '/Shared/Router.php';
    ApiRouter::dispatch($module, $action, $data, $isDeployKeyAuth);
} catch (Throwable $e) {
    error_log("MV Consulting ERP API Error: " . $e->getMessage());
    // Non esporre i dettagli dell'errore al client in produzione
    $msg = (getenv('APP_DEBUG') === 'true') 
        ? 'Errore server: ' . $e->getMessage() 
        : 'Errore interno del server. Contattare l\'amministratore.';
    Response::json(false, $msg, null, 500);
}
