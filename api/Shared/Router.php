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
require_once __DIR__ . '/../Controllers/RiconciliazioneController.php';
require_once __DIR__ . '/../Controllers/MovimentiController.php';

class ApiRouter {
    // Rate limit per IP condiviso da login e reset password: max 5 tentativi in 15 minuti.
    // Sotto la soglia di blocco account (Security::ACCOUNT_MAX_FAILED = 10): da un solo IP
    // non si riesce a bloccare l'account di un altro.
    private const LOGIN_MAX_ATTEMPTS = 5;
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
        'riconciliazione' => ['movimenti', 'proposte', 'documenti_aperti'],
        'movimenti'    => ['categorie', 'conteggio', 'elenco', 'statistiche', 'regole'],
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
     * Risponde subito al client e poi esegue $dopo (es. invio email): la durata dell'invio
     * non si vede nei tempi di risposta. Senza PHP-FPM ripiega su flush().
     */
    private static function rispondiEPoi(bool $success, string $message, callable $dopo): void {
        ignore_user_abort(true);
        http_response_code(200);
        $body = json_encode(['success' => $success, 'message' => $message]);
        header('Content-Length: ' . strlen($body));
        header('Connection: close');
        echo $body;
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        } else {
            while (ob_get_level() > 0) @ob_end_flush();
            @flush();
        }
        try {
            $dopo();
        } catch (Throwable $e) {
            error_log('[rispondiEPoi] ' . $e->getMessage());
        }
        exit;
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
            case 'riconciliazione':
                self::handleRiconciliazione($action, $data);
                break;
            case 'movimenti':
                self::handleMovimenti($action, $data);
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

            // Rate Limiting: max 5 tentativi falliti per IP in 15 minuti
            self::enforceLoginRateLimit($email);
            $ip = Security::clientIp();

            $auth = new Auth();
            $user = $auth->login($email, $password);
            if ($user) {
                // Il bucket IP non si azzera: un login riuscito non regala altri tentativi
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
            $okMsg = 'Se l\'email è registrata, riceverai a breve un link per reimpostare la password.';
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
            $mail = $auth->preparePasswordReset($email);
            // Stessa risposta, e subito, che l'utente esista o no: l'email parte dopo
            self::rispondiEPoi(true, $okMsg, function () use ($mail) {
                if (!$mail) return;
                require_once __DIR__ . '/Mailer.php';
                Mailer::send($mail['to'], $mail['name'], $mail['subject'], $mail['message']);
            });
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
                Response::json(true, 'Password aggiornata con successo. Effettua il login.');
            } catch (Exception $e) {
                Security::rateLimitHit('login', $ip, self::LOGIN_WINDOW);
                Response::json(false, $e->getMessage());
            }
        } elseif ($action === 'confirm_reset') {
            $token = (string)($data['token'] ?? '');
            $newPwd = (string)($data['new_password'] ?? '');
            if ($token === '' || $newPwd === '') {
                Response::json(false, 'Dati mancanti');
            }
            // Stesso bucket del login: niente tentativi a raffica sui token
            self::enforceLoginRateLimit();
            $ip = Security::clientIp();
            $auth = new Auth();
            try {
                $auth->confirmPasswordReset($token, $newPwd);
                Response::json(true, 'Password aggiornata. Ora puoi accedere.');
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
            $isSecure = Security::isHttps();
            setcookie('auth_token', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => true,
                'secure'   => $isSecure,
                'samesite' => 'Lax'
            ]);
            // Anche il token CSRF: stessi attributi con cui è stato creato al login
            setcookie('csrf_token', '', [
                'expires'  => time() - 3600,
                'path'     => '/',
                'httponly' => false,
                'secure'   => $isSecure,
                'samesite' => 'Strict'
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
            case 'delete':     Auth::richiediAdmin(); $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
            case 'lookup-vat': $ctrl->lookupVat($data['vat'] ?? $_GET['vat'] ?? ''); break;
            default:           Response::json(false, "Azione clienti non supportata: $action");
        }
    }

    private static function handleSottoclienti(string $action, array $data): void {
        $ctrl = new SottoclientiController();
        switch ($action) {
            case 'list':   $ctrl->listByCliente($data['cliente_id'] ?? $_GET['cliente_id'] ?? 0); break;
            case 'save':   $ctrl->save($data); break;
            case 'delete': Auth::richiediAdmin(); $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
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
            case 'deleteVehicle':       Auth::richiediAdmin(); $ctrl->deleteVehicle($data); break;
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
            case 'delete':          Auth::richiediAdmin(); $ctrl->delete($data['id'] ?? $_GET['id'] ?? 0); break;
            case 'overview':        $ctrl->overview(); break;
            case 'import_pdf':      $ctrl->importPdf($data); break;
            case 'get_by_cliente':  $ctrl->getByCliente(); break;
            case 'recalculate_all': Auth::richiediAdmin(); $ctrl->recalculateAll(); break;
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
            case 'delete':          Auth::richiediAdmin(); $ctrl->delete($data['id'] ?? 0); break;
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
            case 'delete':          Auth::richiediAdmin(); $ctrl->delete($data['id'] ?? 0); break;
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
            case 'delete':      Auth::richiediAdmin(); $ctrl->delete($data['id'] ?? 0); break;
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

    private static function handleRiconciliazione(string $action, array $data): void {
        $ctrl = new RiconciliazioneController();
        switch ($action) {
            case 'import_estratto':  $ctrl->importEstratto($data); break;
            case 'movimenti':        $ctrl->movimenti(); break;
            case 'proposte':         $ctrl->proposte(); break;
            case 'documenti_aperti': $ctrl->documentiAperti(); break;
            case 'conferma':         $ctrl->conferma($data); break;
            case 'annulla':          $ctrl->annulla($data); break;
            case 'ignora':           $ctrl->ignora($data); break;
            default:                 Response::json(false, "Azione riconciliazione non supportata: $action");
        }
    }

    private static function handleMovimenti(string $action, array $data): void {
        $ctrl = new MovimentiController();
        switch ($action) {
            case 'categorie':       $ctrl->categorie(); break;
            case 'conteggio':       $ctrl->conteggio(); break;
            case 'elenco':          $ctrl->elenco(); break;
            case 'statistiche':     $ctrl->statistiche(); break;
            case 'regole':          $ctrl->regole(); break;
            case 'classifica':      $ctrl->classifica($data); break;
            case 'riclassifica':    $ctrl->riclassifica(); break;
            case 'proponi_ai':      $ctrl->proponiAi(); break;
            case 'salva_categoria': $ctrl->salvaCategoria($data); break;
            case 'elimina_regola':  $ctrl->eliminaRegola($data); break;
            default:                Response::json(false, "Azione movimenti non supportata: $action");
        }
    }

    private static function handleAdmin(string $action, array $data, bool $isDeployKeyAuth): void {
        // RBAC: solo admin può accedere a questo modulo
        if (!$isDeployKeyAuth) {
            Auth::richiediAdmin();
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
                header('Content-Type: application/json; charset=utf-8');
                // L'esito reale viene dall'output di migrate.php; 'output' resta per compatibilità
                $esito = json_decode((string)$output, true);
                if (!is_array($esito)) {
                    Response::json(false, 'Migrazione: output non interpretabile', ['output' => $output], 500);
                }
                $errori = array_values(array_filter($esito['migrations'] ?? [], fn($m) => ($m['status'] ?? '') === 'ERROR'));
                $ok = empty($errori) && ($esito['success'] ?? false) === true;
                Response::json($ok, $ok ? 'Migrazione eseguita' : 'Migrazione con errori (' . count($errori) . ')', [
                    'output' => $output,
                    'newly_applied' => $esito['newly_applied'] ?? 0,
                    'errors' => $errori,
                ], $ok ? 200 : 500);
                break;
            
            default:
                Response::json(false, 'Azione admin non valida');
        }
    }
}
