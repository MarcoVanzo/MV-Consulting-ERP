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

// Carica variabili d'ambiente (.env)
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        $name = trim($name);
        $value = trim($value, " \t\n\r\0\x0B\"");
        putenv("$name=$value");
        $_ENV[$name] = $value;
    }
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
// Solo auth e callback OAuth sono pubblici.
$public_actions = [
    'auth' => ['login', 'request_reset', 'reset_password'],
    'google' => ['auth', 'callback']
];
$isPublic = isset($public_actions[$module]) && in_array($action, $public_actions[$module]);
if (!$isPublic) {
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

try {
    switch ($module) {

        // ═════════════════════════════════════════════
        // AUTH
        // ═════════════════════════════════════════════
        case 'auth':
            if ($action === 'login') {
                $email = $data['email'] ?? '';
                $password = $data['password'] ?? '';
                if (empty($email) || empty($password)) {
                    Response::json(false, 'Credenziali non valide');
                }

                // ── Rate Limiting: max 10 tentativi per IP in 15 minuti ──
                $rateLimitDir = __DIR__ . '/../storage/rate_limit';
                if (!is_dir($rateLimitDir)) @mkdir($rateLimitDir, 0755, true);
                $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
                $rateLimitFile = $rateLimitDir . '/' . md5($ip) . '.json';
                $maxAttempts = 10;
                $windowSeconds = 900; // 15 minuti

                $attempts = [];
                if (file_exists($rateLimitFile)) {
                    $attempts = json_decode(file_get_contents($rateLimitFile), true) ?: [];
                    // Rimuovi tentativi fuori dalla finestra temporale
                    $attempts = array_filter($attempts, fn($t) => $t > time() - $windowSeconds);
                }

                if (count($attempts) >= $maxAttempts) {
                    if (class_exists('Audit')) {
                        Audit::log('RATE_LIMIT', 'auth', null, null, null, ['ip' => $ip, 'email' => $email]);
                    }
                    Response::json(false, 'Troppi tentativi di accesso. Riprovare tra qualche minuto.', null, 429);
                }

                $auth = new Auth();
                $user = $auth->login($email, $password);
                if ($user) {
                    // Login riuscito: azzera i tentativi
                    if (file_exists($rateLimitFile)) @unlink($rateLimitFile);
                    Response::json(true, 'Login effettuato', $user);
                } else {
                    // Login fallito: registra il tentativo
                    $attempts[] = time();
                    file_put_contents($rateLimitFile, json_encode(array_values($attempts)));
                    Response::json(false, 'Email o password errati');
                }
            } elseif ($action === 'request_reset') {
                $email = $data['email'] ?? '';
                if (empty($email)) {
                    Response::json(false, 'Email mancante');
                }
                $auth = new Auth();
                $auth->requestPasswordReset($email);
                Response::json(true, 'Se l\'email è registrata, riceverai una password temporanea a breve.');
            } elseif ($action === 'reset_password') {
                $userId = $data['user_id'] ?? '';
                $currentPwd = $data['current_password'] ?? '';
                $newPwd = $data['new_password'] ?? '';
                if (empty($userId) || empty($currentPwd) || empty($newPwd)) {
                    Response::json(false, 'Dati mancanti');
                }
                $auth = new Auth();
                try {
                    $auth->resetPassword($userId, $currentPwd, $newPwd);
                    Response::json(true, 'Password aggiornata con successo. Effettua il login.');
                } catch (Exception $e) {
                    Response::json(false, $e->getMessage());
                }
            }
            break;

        // ═════════════════════════════════════════════
        // CLIENTI
        // ═════════════════════════════════════════════
        case 'clienti':
            $ctrl = new ClientiController();
            switch ($action) {
                case 'list':       $ctrl->list(); break;
                case 'get':        $ctrl->get($data['id'] ?? $_GET['id'] ?? 0); break;
                case 'save':       $ctrl->save($data); break;
                case 'delete':     $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
                case 'lookup-vat': $ctrl->lookupVat($data['vat'] ?? $_GET['vat'] ?? ''); break;
                default:           Response::json(false, "Azione clienti non supportata: $action");
            }
            break;

        // ═════════════════════════════════════════════
        // SOTTOCLIENTI
        // ═════════════════════════════════════════════
        case 'sottoclienti':
            $ctrl = new SottoclientiController();
            switch ($action) {
                case 'list':   $ctrl->listByCliente($data['cliente_id'] ?? $_GET['cliente_id'] ?? 0); break;
                case 'save':   $ctrl->save($data); break;
                case 'delete': $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
                default:       Response::json(false, "Azione sottoclienti non supportata: $action");
            }
            break;

        // ═════════════════════════════════════════════
        // TRASFERTE E MEZZI
        // ═════════════════════════════════════════════
        case 'trasferte':
            $ctrl = new TrasferteController();
            switch ($action) {
                case 'list':             $ctrl->list(); break;
                case 'save':             $ctrl->save($data); break;
                case 'delete':           $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
                case 'rendiconto':       $ctrl->rendiconto(); break;
                case 'calcolaKmGiorno':  $ctrl->calcolaKmGiorno(); break;
                case 'calcolaTuttiKm':   $ctrl->calcolaTuttiKm(); break;
                case 'togglePernottamento': $ctrl->togglePernottamento(); break;
                default:                 Response::json(false, "Azione trasferte non supportata: $action");
            }
            break;

        case 'mezzi':
            require_once __DIR__ . '/Controllers/MezziController.php';
            $ctrl = new MezziController();
            switch ($action) {
                case 'getAllVehicles':      $ctrl->getAllVehicles($data); break;
                case 'getVehicleById':      $ctrl->getVehicleById($data); break;
                case 'createVehicle':       $ctrl->createVehicle($data); break;
                case 'updateVehicle':       $ctrl->updateVehicle($data); break;
                case 'deleteVehicle':       $ctrl->deleteVehicle($data); break;
                case 'addMaintenance':      $ctrl->addMaintenance($data); break;
                case 'updateMaintenance':   $ctrl->updateMaintenance($data); break;
                case 'deleteMaintenance':   $ctrl->deleteMaintenance($data); break;
                case 'addAnomaly':          $ctrl->addAnomaly($data); break;
                case 'updateAnomaly':       $ctrl->updateAnomaly($data); break;
                case 'deleteAnomaly':       $ctrl->deleteAnomaly($data); break;
                case 'updateAnomalyStatus': $ctrl->updateAnomalyStatus($data); break;
                default:                 Response::json(false, "Azione mezzi non supportata: $action");
            }
            break;

        // ═════════════════════════════════════════════
        // GOOGLE CALENDAR
        // ═════════════════════════════════════════════
        case 'google':
            $ctrl = new GoogleAuthController();
            switch ($action) {
                case 'auth':     $ctrl->auth(); break;
                case 'callback': $ctrl->callback(); break;
                case 'sync':     $ctrl->sync(); break;
                default:         Response::json(false, "Azione google non supportata: $action");
            }
            break;

        // ═════════════════════════════════════════════
        // CONTABILITÀ
        // ═════════════════════════════════════════════
        case 'contabilita':
            $ctrl = new ContabilitaController();
            switch ($action) {
                case 'list':       $ctrl->list(); break;
                case 'save':       $ctrl->save($data); break;
                case 'delete':     $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
                case 'overview':   $ctrl->overview(); break;
                case 'import_pdf': $ctrl->importPdfData($data); break;
                case 'import_xml': $ctrl->importXmlData($data); break;
                case 'import_payment_pdf': $ctrl->importPaymentPdf($data); break;
                default:           Response::json(false, "Azione contabilit\u00e0 non supportata: $action");
            }
            break;

        // ═════════════════════════════════════════════
        // ADMIN (Utenti, Backup, Logs)
        // ═════════════════════════════════════════════
        case 'admin':
            $ctrl = new AdminController();
            switch ($action) {
                // Utenti
                case 'listUsers': $ctrl->listUsers(); break;
                case 'createUser': $ctrl->createUser(); break;
                case 'deleteUser': $ctrl->deleteUser(); break;
                case 'resetPassword': $ctrl->resetPassword(); break;
                
                // Backup
                case 'listBackups': $ctrl->listBackups(); break;
                case 'createBackup': $ctrl->createBackup(); break;
                case 'downloadBackup': $ctrl->downloadBackup(); break;
                case 'deleteBackup': $ctrl->deleteBackup(); break;
                
                // Logs
                case 'listLogs': $ctrl->listLogs(); break;
                
                default:
                    Response::json(false, 'Azione admin non valida');
            }
            break;

        default:
            Response::json(false, 'Modulo non supportato: ' . htmlspecialchars($module . '/' . $action));
    }
} catch (Throwable $e) {
    error_log("MV Consulting ERP API Error: " . $e->getMessage());
    // Non esporre i dettagli dell'errore al client in produzione
    $msg = (getenv('APP_DEBUG') === 'true') 
        ? 'Errore server: ' . $e->getMessage() 
        : 'Errore interno del server. Contattare l\'amministratore.';
    Response::json(false, $msg, null, 500);
}
