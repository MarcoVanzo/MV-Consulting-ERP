<?php
/**
 * API Router for MV Consulting ERP
 * v2.0 — Moduli: Auth, Clienti, Sottoclienti, Trasferte, Contabilità
 */

require_once __DIR__ . '/Shared/Database.php';
require_once __DIR__ . '/Shared/Logger.php';
require_once __DIR__ . '/Shared/Response.php';
require_once __DIR__ . '/Shared/JWT.php';
require_once __DIR__ . '/Shared/Auth.php';
require_once __DIR__ . '/Controllers/ClientiController.php';
require_once __DIR__ . '/Controllers/SottoclientiController.php';
require_once __DIR__ . '/Controllers/TrasferteController.php';
require_once __DIR__ . '/Controllers/ContabilitaController.php';
require_once __DIR__ . '/Controllers/AdminController.php'; // Nuovo: Admin Controller
require_once __DIR__ . '/Controllers/GoogleAuthController.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization');

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

// ═══════════════════════════════════════════
// GLOBAL AUTHENTICATION MIDDLEWARE
// ═══════════════════════════════════════════
$authHeader = $_SERVER['HTTP_AUTHORIZATION'] ?? '';
$public_modules = ['auth', 'google']; // Whitelist public entry points
if (!in_array($module, $public_modules)) {
    $jwt = '';
    if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
        $jwt = $matches[1];
    } elseif (isset($_GET['token'])) {
        $jwt = $_GET['token'];
    }

    if ($jwt) {
        $secret = getenv('JWT_SECRET') ?: 'mv_fallback_secret_secure_2026';
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

        // ═══════════════════════════════════════════
        // AUTH
        // ═══════════════════════════════════════════
        case 'auth':
            if ($action === 'login') {
                $email = $data['email'] ?? '';
                $password = $data['password'] ?? '';
                if (empty($email) || empty($password)) {
                    Response::json(false, 'Credenziali non valide');
                }
                $auth = new Auth();
                $user = $auth->login($email, $password);
                if ($user) {
                    Response::json(true, 'Login effettuato', $user);
                } else {
                    Response::json(false, 'Email o password errati');
                }
            }
            break;

        // ═══════════════════════════════════════════
        // CLIENTI
        // ═══════════════════════════════════════════
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

        // ═══════════════════════════════════════════
        // SOTTOCLIENTI
        // ═══════════════════════════════════════════
        case 'sottoclienti':
            $ctrl = new SottoclientiController();
            switch ($action) {
                case 'list':   $ctrl->listByCliente($data['cliente_id'] ?? $_GET['cliente_id'] ?? 0); break;
                case 'save':   $ctrl->save($data); break;
                case 'delete': $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
                default:       Response::json(false, "Azione sottoclienti non supportata: $action");
            }
            break;

        // ═══════════════════════════════════════════
        // TRASFERTE
        // ═══════════════════════════════════════════
        case 'trasferte':
            $ctrl = new TrasferteController();
            switch ($action) {
                case 'list':             $ctrl->list(); break;
                case 'save':             $ctrl->save($data); break;
                case 'delete':           $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
                case 'rendiconto':       $ctrl->rendiconto(); break;
                case 'calcolaKmGiorno':  $ctrl->calcolaKmGiorno(); break;
                case 'calcolaTuttiKm':   $ctrl->calcolaTuttiKm(); break;
                default:                 Response::json(false, "Azione trasferte non supportata: $action");
            }
            break;

        // ═══════════════════════════════════════════
        // GOOGLE CALENDAR
        // ═══════════════════════════════════════════
        case 'google':
            $ctrl = new GoogleAuthController();
            switch ($action) {
                case 'auth':     $ctrl->auth(); break;
                case 'callback': $ctrl->callback(); break;
                case 'sync':     $ctrl->sync(); break;
                default:         Response::json(false, "Azione google non supportata: $action");
            }
            break;

        // ═══════════════════════════════════════════
        // CONTABILITÀ
        // ═══════════════════════════════════════════
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
                default:           Response::json(false, "Azione contabilità non supportata: $action");
            }
            break;

        // ═══════════════════════════════════════════
        // ADMIN (Utenti, Backup, Logs)
        // ═══════════════════════════════════════════
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
} catch (Exception $e) {
    error_log("MV Consulting ERP API Error: " . $e->getMessage());
    Response::json(false, 'Errore server: ' . $e->getMessage());
}
