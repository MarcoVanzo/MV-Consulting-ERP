<?php
/**
 * API Router for MV Consulting ERP
 * v2.0 — Moduli: Auth, Clienti, Sottoclienti, Trasferte, Contabilità
 */

// Carica variabili d'ambiente (.env) — prima di qualsiasi getenv()
require_once __DIR__ . '/Shared/Env.php';
Env::load(__DIR__ . '/../.env');

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

// CORS — solo dominio di produzione; fuori produzione anche localhost/127.0.0.1 (qualsiasi porta)
$allowedOrigins = ['https://www.mv-consulting.it', 'https://mv-consulting.it'];
$origin = $_SERVER['HTTP_ORIGIN'] ?? '';
$originAllowed = in_array($origin, $allowedOrigins, true)
    || (getenv('APP_ENV') !== 'production' && preg_match('#^https?://(localhost|127\.0\.0\.1)(:\d{1,5})?$#', $origin));
if ($originAllowed) {
    header('Access-Control-Allow-Origin: ' . $origin);
    header('Access-Control-Allow-Credentials: true');
}
header('Vary: Origin');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, X-CSRF-Token');

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
// Pubblici: login/reset password e il callback OAuth (protetto dallo state).
// google/auth richiede un admin autenticato.
$public_actions = [
    'auth' => ['login', 'request_reset', 'reset_password'],
    'google' => ['callback']
];
$isPublic = isset($public_actions[$module]) && in_array($action, $public_actions[$module], true);

// Deploy key auth: admin/migrate può essere autenticato via X-Deploy-Key
$isDeployKeyAuth = false;
if ($module === 'admin' && $action === 'migrate') {
    $deployKey = $_SERVER['HTTP_X_DEPLOY_KEY'] ?? '';
    $serverKey = getenv('DEPLOY_KEY') ?: '';
    if ($deployKey && $serverKey && hash_equals($serverKey, $deployKey)) {
        $isDeployKeyAuth = true;
    }
}

/**
 * Valida il JWT (cookie HttpOnly o Authorization Bearer) e rilegge l'utente dal DB:
 * ruolo, blocco e disattivazione valgono subito, non alla scadenza del token.
 * Ritorna il contesto utente oppure [null, messaggio, codice HTTP].
 */
function mvResolveUser(string $authHeader): array {
    $jwt = $_COOKIE['auth_token'] ?? '';
    if (!$jwt && preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $jwt = $matches[1];
    }
    if (!$jwt) {
        return [null, 'Autorizzazione negata. Token mancante nel payload', 401];
    }
    $secret = getenv('JWT_SECRET');
    if (!$secret) {
        error_log('CRITICAL: JWT_SECRET non configurato nel .env');
        return [null, 'Errore di configurazione server', 500];
    }
    $decoded = JWT::decode($jwt, $secret);
    if (!$decoded || empty($decoded['id'])) {
        return [null, 'Token non valido o scaduto', 401];
    }

    $prefix = getenv('DB_PREFIX') ?: 'mv_';
    // SELECT * per tollerare schemi senza is_active/blocked/status
    try {
        $stmt = Database::getConnection()->prepare("SELECT * FROM {$prefix}users WHERE id = ? LIMIT 1");
        $stmt->execute([$decoded['id']]);
        $user = $stmt->fetch(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('Auth middleware DB error: ' . $e->getMessage());
        return [null, 'Errore interno del server. Contattare l\'amministratore.', 500];
    }
    if (!$user) {
        return [null, 'Token non valido o scaduto', 401];
    }
    if (!empty($user['blocked'])
        || (isset($user['is_active']) && (int)$user['is_active'] === 0)
        || (($user['status'] ?? '') === 'Disattivato')) {
        return [null, 'Token non valido o scaduto', 401];
    }

    return [[
        'id'    => $user['id'],
        'email' => $user['email'],
        'role'  => Auth::normalizeRole($user['role'] ?? null), // ruolo dal DB, non dal token
        'name'  => $user['full_name'] ?? $user['name'] ?? $user['email'],
        'exp'   => $decoded['exp'] ?? null,
    ], '', 200];
}

if (!$isPublic && !$isDeployKeyAuth) {
    [$ctx, $authError, $authCode] = mvResolveUser($authHeader);
    if (!$ctx) {
        Response::json(false, $authError, null, $authCode);
    }
    $GLOBALS['userContext'] = $ctx;
} elseif ($module === 'google' && $action === 'callback') {
    // Callback pubblico: il cookie auth_token (SameSite=Lax) arriva col redirect top-level;
    // il controller verifica che sia un admin
    [$ctx] = mvResolveUser('');
    if ($ctx) $GLOBALS['userContext'] = $ctx;
}

// ═════════════════════════════════════════════
// METODO + CSRF (Double Submit Cookie)
// Solo le azioni in ApiRouter::READ_ONLY_ACTIONS sono ammesse in GET;
// ogni altra azione richiede POST + token CSRF valido.
// ═════════════════════════════════════════════
require_once __DIR__ . '/Shared/Router.php';
if (!$isPublic && !$isDeployKeyAuth) {
    $method = $_SERVER['REQUEST_METHOD'] ?? 'GET';
    if ($method !== 'POST' && !ApiRouter::isReadOnly($module, $action)) {
        header('Allow: POST');
        Response::json(false, 'Metodo non consentito per questa azione.', null, 405);
    }
    if ($method === 'POST') {
        $csrfCookie = $_COOKIE['csrf_token'] ?? '';
        $csrfHeader = $_SERVER['HTTP_X_CSRF_TOKEN'] ?? '';
        if (empty($csrfCookie) || empty($csrfHeader) || !hash_equals($csrfCookie, $csrfHeader)) {
            Response::json(false, 'Richiesta non valida (CSRF).', null, 403);
        }
    }
}

try {
    ApiRouter::dispatch($module, $action, $data, $isDeployKeyAuth);
} catch (Throwable $e) {
    error_log("MV Consulting ERP API Error: " . $e->getMessage());
    // Non esporre i dettagli dell'errore al client in produzione
    $msg = (getenv('APP_DEBUG') === 'true') 
        ? 'Errore server: ' . $e->getMessage() 
        : 'Errore interno del server. Contattare l\'amministratore.';
    Response::json(false, $msg, null, 500);
}
