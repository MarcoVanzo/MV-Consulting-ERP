<?php
declare(strict_types=1);

require_once __DIR__ . '/Response.php';
require_once __DIR__ . '/Auth.php';
require_once __DIR__ . '/Security.php';
require_once __DIR__ . '/../Controllers/ClientiController.php';
require_once __DIR__ . '/../Controllers/SottoclientiController.php';
require_once __DIR__ . '/../Controllers/TrasferteController.php';
require_once __DIR__ . '/../Controllers/MezziController.php';
require_once __DIR__ . '/../Controllers/ContabilitaController.php';
require_once __DIR__ . '/../Controllers/IncarchiController.php';
require_once __DIR__ . '/../Controllers/AdminController.php';
require_once __DIR__ . '/../Controllers/GoogleAuthController.php';
require_once __DIR__ . '/../Controllers/OfferteController.php';
require_once __DIR__ . '/../Controllers/FornitoriController.php';
require_once __DIR__ . '/../Controllers/FatturePassiveController.php';
require_once __DIR__ . '/../Controllers/CommesseController.php';

class ApiRouter {
    // Rate limit per IP condiviso da login e reset_password: max 10 tentativi in 15 minuti
    private const LOGIN_MAX_ATTEMPTS = 10;
    private const LOGIN_WINDOW = 900;
    // request_reset: max 5 richieste/ora per IP, max 3/ora per email
    private const RESET_MAX_PER_IP = 5;
    private const RESET_MAX_PER_EMAIL = 3;
    private const RESET_WINDOW = 3600;

    /**
     * Azioni di sola lettura: le uniche ammesse in GET (e senza effetti collaterali).
     * Tutto il resto richiede POST + token CSRF (verificato in router.php).
     */
    public const READ_ONLY_ACTIONS = [
        'auth'         => ['verify'],
        'clienti'      => ['list', 'get', 'lookup-vat'],
        'sottoclienti' => ['list'],
        'trasferte'    => ['list', 'rendiconto'],
        'mezzi'        => ['getAllVehicles', 'getVehicleById'],
        'incarichi'    => ['list', 'overview', 'get_by_cliente', 'documento'],
        'contabilita'  => ['list', 'overview'],
        'offerte'      => ['list', 'get', 'prossimo_numero', 'documento'],
        'fornitori'    => ['list', 'costi_fornitore', 'documento_costo'],
        'passive'      => ['list'],
        'commesse'     => ['get', 'margini', 'scadenzario', 'fatture_libere'],
        'admin'        => ['listUsers', 'listBackups', 'downloadBackup', 'listLogs'],
    ];

    public static function isReadOnly(string $module, string $action): bool {
        return in_array($action, self::READ_ONLY_ACTIONS[$module] ?? [], true);
    }

    /** Blocca con 429 se l'IP ha superato i tentativi di login/reset password */
    private static function enforceLoginRateLimit(string $email = ''): void {
        $ip = Security::clientIp();
        if (Security::isRateLimited('login', $ip, self::LOGIN_MAX_ATTEMPTS, self::LOGIN_WINDOW)) {
            if (class_exists('Audit')) {
                Audit::log('RATE_LIMIT', 'auth', null, null, null, ['ip' => $ip, 'email' => $email], 'security');
            }
            Response::json(false, 'Troppi tentativi di accesso. Riprovare tra qualche minuto.', null, 429);
        }
    }

    /**
     * Dispatch della richiesta al controller appropriato.
     */
    public static function dispatch(string $module, string $action, array $data, bool $isDeployKeyAuth = false): void {
        switch ($module) {
            case 'auth':
                self::handleAuth($action, $data);
                break;
            case 'clienti':
                self::handleClienti($action, $data);
                break;
            case 'sottoclienti':
                self::handleSottoclienti($action, $data);
                break;
            case 'trasferte':
                self::handleTrasferte($action, $data);
                break;
            case 'mezzi':
                self::handleMezzi($action, $data);
                break;
            case 'google':
                self::handleGoogle($action, $data);
                break;
            case 'incarichi':
                self::handleIncarichi($action, $data);
                break;
            case 'contabilita':
                self::handleContabilita($action, $data);
                break;
            case 'offerte':
                self::handleOfferte($action, $data);
                break;
            case 'fornitori':
                self::handleFornitori($action, $data);
                break;
            case 'passive':
                self::handlePassive($action, $data);
                break;
            case 'commesse':
                self::handleCommesse($action, $data);
                break;
            case 'admin':
                self::handleAdmin($action, $data, $isDeployKeyAuth);
                break;
            default:
                Response::json(false, 'Modulo non supportato: ' . htmlspecialchars($module . '/' . $action));
        }
    }

    private static function handleAuth(string $action, array $data): void {
        if ($action === 'login') {
            $email = $data['email'] ?? '';
            $password = $data['password'] ?? '';
            if (empty($email) || empty($password)) {
                Response::json(false, 'Credenziali non valide');
            }

            // Rate Limiting: max 10 tentativi per IP in 15 minuti
            self::enforceLoginRateLimit($email);
            $ip = Security::clientIp();

            $auth = new Auth();
            $user = $auth->login($email, $password);
            if ($user) {
                Security::rateLimitReset('login', $ip);
                Response::json(true, 'Login effettuato', $user);
            } else {
                Security::rateLimitHit('login', $ip, self::LOGIN_WINDOW);
                Response::json(false, 'Email o password errati');
            }
        } elseif ($action === 'request_reset') {
            $email = $data['email'] ?? '';
            if (empty($email)) {
                Response::json(false, 'Email mancante');
            }
            $okMsg = 'Se l\'email è registrata, riceverai una password temporanea a breve.';
            $ip = Security::clientIp();
            $emailKey = mb_strtolower(trim((string)$email), 'UTF-8');
            if (Security::isRateLimited('reset_ip', $ip, self::RESET_MAX_PER_IP, self::RESET_WINDOW)) {
                if (class_exists('Audit')) {
                    Audit::log('RATE_LIMIT', 'auth', null, null, null, ['ip' => $ip, 'email' => $emailKey, 'action' => 'request_reset'], 'security');
                }
                Response::json(false, 'Troppe richieste. Riprovare più tardi.', null, 429);
            }
            Security::rateLimitHit('reset_ip', $ip, self::RESET_WINDOW);
            // Limite per email: risposta identica per non rivelare se l'indirizzo esiste
            if (Security::isRateLimited('reset_email', $emailKey, self::RESET_MAX_PER_EMAIL, self::RESET_WINDOW)) {
                Response::json(true, $okMsg);
            }
            Security::rateLimitHit('reset_email', $emailKey, self::RESET_WINDOW);
            $auth = new Auth();
            $auth->requestPasswordReset($email);
            Response::json(true, $okMsg);
        } elseif ($action === 'reset_password') {
            $userId = $data['user_id'] ?? '';
            $currentPwd = $data['current_password'] ?? '';
            $newPwd = $data['new_password'] ?? '';
            if (empty($userId) || empty($currentPwd) || empty($newPwd)) {
                Response::json(false, 'Dati mancanti');
            }
            // Stesso rate limit per IP del login (bucket condiviso)
            self::enforceLoginRateLimit();
            $ip = Security::clientIp();
            $auth = new Auth();
            try {
                $auth->resetPassword($userId, $currentPwd, $newPwd);
                Security::rateLimitReset('login', $ip);
                Response::json(true, 'Password aggiornata con successo. Effettua il login.');
            } catch (Exception $e) {
                Security::rateLimitHit('login', $ip, self::LOGIN_WINDOW);
                Response::json(false, $e->getMessage());
            }
        } elseif ($action === 'verify') {
            $ctx = $GLOBALS['userContext'] ?? [];
            Response::json(true, 'Sessione valida', [
                'id'    => $ctx['id'] ?? null,
                'email' => $ctx['email'] ?? null,
                'role'  => $ctx['role'] ?? null,
                'name'  => $ctx['name'] ?? $ctx['email'] ?? 'User'
            ]);
        } elseif ($action === 'logout') {
            $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
            setcookie('auth_token', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'secure'   => $isSecure,
                'samesite' => 'Lax'
            ]);
            Response::json(true, 'Logout effettuato');
        } else {
            Response::json(false, "Azione auth non supportata: $action");
        }
    }

    private static function handleClienti(string $action, array $data): void {
        $ctrl = new ClientiController();
        switch ($action) {
            case 'list':       $ctrl->list(); break;
            case 'get':        $ctrl->get($data['id'] ?? $_GET['id'] ?? 0); break;
            case 'save':       $ctrl->save($data); break;
            case 'delete':     $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
            case 'lookup-vat': $ctrl->lookupVat($data['vat'] ?? $_GET['vat'] ?? ''); break;
            default:           Response::json(false, "Azione clienti non supportata: $action");
        }
    }

    private static function handleSottoclienti(string $action, array $data): void {
        $ctrl = new SottoclientiController();
        switch ($action) {
            case 'list':   $ctrl->listByCliente($data['cliente_id'] ?? $_GET['cliente_id'] ?? 0); break;
            case 'save':   $ctrl->save($data); break;
            case 'delete': $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
            default:       Response::json(false, "Azione sottoclienti non supportata: $action");
        }
    }

    private static function handleTrasferte(string $action, array $data): void {
        $ctrl = new TrasferteController();
        switch ($action) {
            case 'list':                $ctrl->list(); break;
            case 'save':                $ctrl->save($data); break;
            case 'delete':              $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
            case 'rendiconto':          $ctrl->rendiconto(); break;
            case 'calcolaKmGiorno':     $ctrl->calcolaKmGiorno(); break;
            case 'calcolaTuttiKm':      $ctrl->calcolaTuttiKm(); break;
            case 'togglePernottamento': $ctrl->togglePernottamento(); break;
            default:                    Response::json(false, "Azione trasferte non supportata: $action");
        }
    }

    private static function handleMezzi(string $action, array $data): void {
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
            default:                    Response::json(false, "Azione mezzi non supportata: $action");
        }
    }

    private static function handleGoogle(string $action, array $data): void {
        $ctrl = new GoogleAuthController();
        switch ($action) {
            case 'auth':     $ctrl->auth(); break;
            case 'callback': $ctrl->callback(); break;
            case 'sync':     $ctrl->sync(); break;
            default:         Response::json(false, "Azione google non supportata: $action");
        }
    }

    private static function handleIncarichi(string $action, array $data): void {
        $ctrl = new IncarchiController();
        switch ($action) {
            case 'list':            $ctrl->list(); break;
            case 'save':            $ctrl->save($data); break;
            case 'delete':          $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
            case 'overview':        $ctrl->overview(); break;
            case 'import_pdf':      $ctrl->importPdf($data); break;
            case 'get_by_cliente':  $ctrl->getByCliente(); break;
            case 'recalculate_all': $ctrl->recalculateAll(); break;
            case 'documento':       $ctrl->documento($data['id'] ?? $_GET['id'] ?? 0); break;
            default:                Response::json(false, "Azione incarichi non supportata: $action");
        }
    }

    private static function handleContabilita(string $action, array $data): void {
        $ctrl = new ContabilitaController();
        switch ($action) {
            case 'list':               $ctrl->list(); break;
            case 'save':               $ctrl->save($data); break;
            case 'delete':             $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
            case 'overview':           $ctrl->overview(); break;
            case 'import_pdf':         $ctrl->importPdfData($data); break;
            case 'import_xml':         $ctrl->importXmlData($data); break;
            case 'import_payment_pdf': $ctrl->importPaymentPdf($data); break;
            default:                   Response::json(false, "Azione contabilità non supportata: $action");
        }
    }

    private static function handleOfferte(string $action, array $data): void {
        $ctrl = new OfferteController();
        switch ($action) {
            case 'list':            $ctrl->list(); break;
            case 'get':             $ctrl->get($data['id'] ?? $_GET['id'] ?? 0); break;
            case 'prossimo_numero': $ctrl->prossimoNumero(); break;
            case 'save':            $ctrl->save($data); break;
            case 'delete':          $ctrl->delete($data['id'] ?? 0); break;
            case 'set_stato':       $ctrl->setStato($data); break;
            case 'accetta':         $ctrl->accetta($data); break;
            case 'nuova_versione':  $ctrl->nuovaVersione($data); break;
            case 'importa':         $ctrl->importa(); break;
            case 'documento':       $ctrl->documento($data['id'] ?? $_GET['id'] ?? 0); break;
            default:                Response::json(false, "Azione offerte non supportata: $action");
        }
    }

    private static function handleFornitori(string $action, array $data): void {
        $ctrl = new FornitoriController();
        switch ($action) {
            case 'list':            $ctrl->list(); break;
            case 'save':            $ctrl->save($data); break;
            case 'delete':          $ctrl->delete($data['id'] ?? 0); break;
            case 'save_costo':      $ctrl->saveCosto($data); break;
            case 'delete_costo':    $ctrl->deleteCosto($data['id'] ?? 0); break;
            case 'costi_fornitore': $ctrl->costiFornitore(); break;
            case 'documento_costo': $ctrl->documentoCosto($data['id'] ?? $_GET['id'] ?? 0); break;
            default:                Response::json(false, "Azione fornitori non supportata: $action");
        }
    }

    private static function handlePassive(string $action, array $data): void {
        $ctrl = new FatturePassiveController();
        switch ($action) {
            case 'list':        $ctrl->list(); break;
            case 'save':        $ctrl->save($data); break;
            case 'delete':      $ctrl->delete($data['id'] ?? 0); break;
            case 'set_pagata':  $ctrl->setPagata($data); break;
            case 'import_xml':  $ctrl->importXml($data); break;
            default:            Response::json(false, "Azione fatture fornitori non supportata: $action");
        }
    }

    private static function handleCommesse(string $action, array $data): void {
        $ctrl = new CommesseController();
        switch ($action) {
            case 'get':             $ctrl->get($data['id'] ?? $_GET['id'] ?? 0); break;
            case 'save_rate':       $ctrl->saveRate($data); break;
            case 'collega_fattura': $ctrl->collegaFattura($data); break;
            case 'fatture_libere':  $ctrl->fattureLibere(); break;
            case 'margini':         $ctrl->margini(); break;
            case 'segna_incassata': $ctrl->segnaIncassata($data); break;
            case 'scadenzario':     $ctrl->scadenzario(); break;
            default:                Response::json(false, "Azione commesse non supportata: $action");
        }
    }

    private static function handleAdmin(string $action, array $data, bool $isDeployKeyAuth): void {
        // RBAC: solo admin può accedere a questo modulo
        if (!$isDeployKeyAuth) {
            $userCtx = $GLOBALS['userContext'] ?? [];
            if (($userCtx['role'] ?? '') !== 'admin') {
                Response::json(false, 'Accesso negato. Permessi insufficienti.', null, 403);
            }
        }
        $ctrl = new AdminController();
        switch ($action) {
            // Utenti
            case 'listUsers':     $ctrl->listUsers(); break;
            case 'createUser':    $ctrl->createUser(); break;
            case 'deleteUser':    $ctrl->deleteUser(); break;
            case 'resetPassword': $ctrl->resetPassword(); break;
            case 'unlockUser':    $ctrl->unlockUser(); break;
            
            // Backup
            case 'listBackups':   $ctrl->listBackups(); break;
            case 'createBackup':  $ctrl->createBackup(); break;
            case 'downloadBackup':$ctrl->downloadBackup(); break;
            case 'deleteBackup':  $ctrl->deleteBackup(); break;
            
            // Logs
            case 'listLogs':      $ctrl->listLogs(); break;
            
            // Migrazione DB (triggerable via router)
            case 'migrate':
                ob_start();
                require_once __DIR__ . '/../migrate.php';
                $output = ob_get_clean();
                Response::json(true, 'Migrazione eseguita', ['output' => $output]);
                break;
            
            default:
                Response::json(false, 'Azione admin non valida');
        }
    }
}
