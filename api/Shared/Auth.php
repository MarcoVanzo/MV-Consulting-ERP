<?php

require_once __DIR__ . '/Security.php';

class Auth {
    /** Ruoli ammessi; un ruolo mancante vale il minimo privilegio */
    public const ROLES = ['admin', 'operatore'];
    public const DEFAULT_ROLE = 'operatore';

    public static function normalizeRole($role): string {
        return in_array($role, self::ROLES, true) ? $role : self::DEFAULT_ROLE;
    }

    /** Ferma la richiesta con 403 se l'utente corrente (middleware di router.php) non è admin */
    public static function richiediAdmin(): void {
        $ctx = $GLOBALS['userContext'] ?? [];
        if (($ctx['role'] ?? '') !== 'admin') {
            Response::json(false, 'Accesso negato. Operazione riservata agli amministratori.', null, 403);
        }
    }

    /** Hash fittizio: con un'email inesistente il login impiega lo stesso tempo (niente enumerazione) */
    private const DUMMY_HASH = '$2y$10$FceZ5vwVRPTBneQswTlFwOP.MuRl/tzHNRsLcsd3.pRdP2RvV8eZm';

    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function login($email, $password) {
        $prefix = getenv('DB_PREFIX') ?: 'mv_';
        
        try {
            $stmt = $this->db->prepare("SELECT * FROM {$prefix}users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            if ($user) {
                if (!empty($user['blocked'])) {
                    throw new Exception("Account bloccato. Contattare l'amministratore.");
                }
                if (($user['status'] ?? '') === 'Disattivato' || (isset($user['is_active']) && (int)$user['is_active'] === 0)) {
                    throw new Exception("Account disattivato.");
                }
                // Blocco temporaneo dopo troppi tentativi falliti
                if (Security::accountLockRemaining($user['id']) > 0) {
                    throw new Exception("Account temporaneamente bloccato per troppi tentativi.");
                }

                $dbPassword = !empty($user['password']) ? $user['password'] : (!empty($user['pwd_hash']) ? $user['pwd_hash'] : null);
                
                $isValid = false;
                if ($dbPassword) {
                    if (password_verify($password, $dbPassword)) {
                        $isValid = true;
                    } else {
                        // Log if password looks like a legacy hash (MD5/SHA1/plaintext) 
                        // to help identify users who haven't been migrated
                        $hashLen = strlen($dbPassword);
                        if ($hashLen === 32 || $hashLen === 40 || $hashLen < 30) {
                            error_log('[SECURITY] Legacy password hash detected for user ' . $user['id'] . ' — force password reset required');
                        }
                    }
                }

                if ($isValid) {
                    
                    // Reset failed attempts (fallback for older DB schemas)
                    try {
                        $this->db->prepare("UPDATE {$prefix}users SET failed_attempts = 0 WHERE id = ?")->execute([$user['id']]);
                    } catch (\Exception $e) { }

                    if (isset($user['must_change_password']) && $user['must_change_password']) {
                        return ['must_change' => true, 'reason' => 'mandatory', 'user_id' => $user['id']];
                    }

                    if (!empty($user['last_password_change'])) {
                        $lastChange = strtotime($user['last_password_change']);
                        $expiryDate = strtotime("+90 days", $lastChange);
                        if (time() > $expiryDate) {
                            try {
                                $this->db->prepare("UPDATE {$prefix}users SET must_change_password = 1 WHERE id = ?")->execute([$user['id']]);
                            } catch (\Exception $e) { }
                            return ['must_change' => true, 'reason' => 'expired', 'user_id' => $user['id']];
                        }
                    }

                    $secret = getenv('JWT_SECRET');
                    if (!$secret) {
                        error_log('CRITICAL: JWT_SECRET non configurato nel .env');
                        return false;
                    }
                    
                    $jwtExpiration = (int)(getenv('JWT_EXPIRATION') ?: 86400 * 30);
                    // iat: i token emessi prima di un cambio password vengono rifiutati (router.php)
                    $payload = [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'role' => self::normalizeRole($user['role'] ?? null),
                        'iat' => time(),
                        'exp' => time() + $jwtExpiration
                    ];
                    $token = JWT::encode($payload, $secret);
                    
                    // Secure anche quando l'HTTPS termina sul proxy di Aruba
                    $isSecure = Security::isHttps();
                    setcookie('auth_token', $token, [
                        'expires' => time() + $jwtExpiration,
                        'path' => '/',
                        'httponly' => true,
                        'secure' => $isSecure,
                        'samesite' => 'Lax'
                    ]);
                    
                    // CSRF Double Submit Cookie — leggibile da JS, non da cross-origin
                    $csrfToken = bin2hex(random_bytes(32));
                    setcookie('csrf_token', $csrfToken, [
                        'expires' => time() + $jwtExpiration,
                        'path' => '/',
                        'httponly' => false,  // JS deve poterlo leggere
                        'secure' => $isSecure,
                        'samesite' => 'Strict'  // MAI inviato in richieste cross-origin
                    ]);
                    
                    // Audit fallback since we don't have the Audit class imported properly
                    if (class_exists('Audit')) {
                        Audit::log('LOGIN', 'users', (string)$user['id'], null, null, ['email' => $user['email']], 'login', null, [
                            'id' => $user['id'],
                            'username' => $user['full_name'] ?? $user['name'] ?? $user['email'],
                            'role' => self::normalizeRole($user['role'] ?? null),
                        ]);
                    }
                    
                    return [
                        'id' => $user['id'],
                        'name' => $user['name'] ?? $user['full_name'] ?? 'User',
                        'email' => $user['email'],
                        'role' => self::normalizeRole($user['role'] ?? null)
                    ];
                } else {
                    // Blocco temporaneo (non più blocked = 1 permanente)
                    Security::registerFailedAttempt($this->db, $prefix, $user['id']);
                }
            } else {
                password_verify((string)$password, self::DUMMY_HASH);
            }
        } catch (PDOException $e) {
            error_log("Login DB Error: " . $e->getMessage());
        } catch (Throwable $e) {
            error_log("Login Error: " . $e->getMessage());
        }

        return false;
    }

    /** Durata del link di reset */
    private const RESET_TOKEN_TTL_MINUTES = 60;

    /**
     * Prepara un link di reset valido un'ora e restituisce l'email da inviare (null se l'utente
     * non c'è o non è attivo). L'invio lo fa il router DOPO aver risposto, così i tempi di
     * risposta non rivelano se l'indirizzo esiste. La password NON cambia finché il link non
     * viene usato: chi conosce solo l'email non può buttare fuori un utente.
     */
    public function preparePasswordReset($email): ?array {
        $prefix = getenv('DB_PREFIX') ?: 'mv_';
        $stmt = $this->db->prepare("SELECT * FROM {$prefix}users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if ($user && empty($user['blocked']) && ($user['status'] ?? '') !== 'Disattivato') {
            $token = bin2hex(random_bytes(32));
            // In DB solo l'hash: una lettura del DB non basta per usare il link
            $this->db->prepare("UPDATE {$prefix}users SET verification_token = ?, token_expires_at = DATE_ADD(NOW(), INTERVAL " . self::RESET_TOKEN_TTL_MINUTES . " MINUTE) WHERE id = ?")
                ->execute([hash('sha256', $token), $user['id']]);

            // Indirizzo fisso (override con ERP_PUBLIC_URL): mai l'Host della richiesta, che è manipolabile
            $base = rtrim(getenv('ERP_PUBLIC_URL') ?: 'https://www.mv-consulting.it/ERP', '/');
            $link = $base . '/#reset-token=' . $token;
            $subject = "Reimpostazione password - MV Consulting ERP";
            $message = "Hai chiesto di reimpostare la password dell'ERP.\n\n"
                . "Apri questo link entro " . self::RESET_TOKEN_TTL_MINUTES . " minuti e scegli la nuova password:\n"
                . $link . "\n\n"
                . "Se non sei stato tu, ignora questa email: la password attuale resta valida.";
            return ['to' => $user['email'], 'name' => $user['full_name'] ?? $user['name'] ?? null, 'subject' => $subject, 'message' => $message];
        }
        return null;
    }

    /** Imposta la nuova password a partire dal token ricevuto via email */
    public function confirmPasswordReset(string $token, string $newPwd) {
        $prefix = getenv('DB_PREFIX') ?: 'mv_';
        if (!preg_match('/^[a-f0-9]{64}$/', $token)) {
            throw new Exception("Link non valido o scaduto. Richiedi un nuovo reset.");
        }
        $hash = hash('sha256', $token);
        $stmt = $this->db->prepare("SELECT * FROM {$prefix}users WHERE verification_token = ? AND token_expires_at > NOW() LIMIT 1");
        $stmt->execute([$hash]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new Exception("Link non valido o scaduto. Richiedi un nuovo reset.");
        }
        if (!empty($user['blocked']) || ($user['status'] ?? '') === 'Disattivato' || (isset($user['is_active']) && (int)$user['is_active'] === 0)) {
            throw new Exception("Account non attivo. Contattare l'amministratore.");
        }

        // Link monouso in modo atomico: di due richieste concorrenti con lo stesso token ne passa una.
        // Nella transazione: se la nuova password è rifiutata (complessità, storico) il link resta valido.
        $this->db->beginTransaction();
        try {
            $upd = $this->db->prepare("UPDATE {$prefix}users SET verification_token = NULL, token_expires_at = NULL
                WHERE id = ? AND verification_token = ? AND token_expires_at > NOW()");
            $upd->execute([$user['id'], $hash]);
            if ($upd->rowCount() !== 1) {
                throw new Exception("Link non valido o scaduto. Richiedi un nuovo reset.");
            }
            $this->applyNewPassword($user['id'], $newPwd);
            $this->db->commit();
        } catch (Throwable $e) {
            if ($this->db->inTransaction()) $this->db->rollBack();
            throw $e;
        }
        // Il reset via email sblocca anche un eventuale blocco temporaneo
        Security::unlockAccount($user['id']);
        return true;
    }

    public function resetPassword($userId, $currentPwd, $newPwd) {
        $prefix = getenv('DB_PREFIX') ?: 'mv_';
        $stmt = $this->db->prepare("SELECT * FROM {$prefix}users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();
        if (!$user) {
            throw new Exception("Password attuale errata.");
        }
        if (!empty($user['blocked']) || ($user['status'] ?? '') === 'Disattivato' || (isset($user['is_active']) && (int)$user['is_active'] === 0)) {
            throw new Exception("Account non attivo. Contattare l'amministratore.");
        }
        if (Security::accountLockRemaining($user['id']) > 0) {
            throw new Exception("Account temporaneamente bloccato per troppi tentativi. Riprovare tra qualche minuto.");
        }

        $dbPassword = !empty($user['password']) ? $user['password'] : (!empty($user['pwd_hash']) ? $user['pwd_hash'] : null);
        if (!$dbPassword || !password_verify($currentPwd, $dbPassword)) {
            // Stesso conteggio del login: failed_attempts + blocco temporaneo
            Security::registerFailedAttempt($this->db, $prefix, $user['id']);
            throw new Exception("Password attuale errata.");
        }

        $this->applyNewPassword($userId, $newPwd);
        return true;
    }

    /** Complessità, storico delle ultime 5 e salvataggio: comune a cambio password e reset via link */
    private function applyNewPassword($userId, string $newPwd): void {
        $prefix = getenv('DB_PREFIX') ?: 'mv_';
        require_once __DIR__ . '/Security.php';
        if (!Security::validatePasswordComplexity($newPwd)) {
            throw new Exception("La password deve essere di almeno 12 caratteri e contenere maiuscole, minuscole, numeri e caratteri speciali.");
        }

        // History check: uno schema disallineato di password_history non deve impedire il cambio password
        try {
            $stmtHist = $this->db->prepare("SELECT pwd_hash FROM {$prefix}password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
            $stmtHist->execute([$userId]);
            $history = $stmtHist->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            error_log('password_history non leggibile: ' . $e->getMessage());
            $history = [];
        }
        foreach ($history as $oldHash) {
            if ($oldHash && password_verify($newPwd, $oldHash)) {
                throw new Exception("Non puoi riutilizzare una delle ultime 5 password.");
            }
        }

        $hash = password_hash($newPwd, PASSWORD_DEFAULT);
        $this->db->prepare("UPDATE {$prefix}users SET password = ?, last_password_change = NOW(), must_change_password = 0 WHERE id = ?")->execute([$hash, $userId]);
        try {
            $this->db->prepare("UPDATE {$prefix}users SET failed_attempts = 0 WHERE id = ?")->execute([$userId]);
        } catch (\Exception $e) { }

        try {
            $this->db->prepare("INSERT INTO {$prefix}password_history (user_id, pwd_hash) VALUES (?, ?)")->execute([$userId, $hash]);
            $this->db->prepare("DELETE FROM {$prefix}password_history WHERE user_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM {$prefix}password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 5) AS recent)")->execute([$userId, $userId]);
        } catch (PDOException $e) {
            error_log('password_history non aggiornabile: ' . $e->getMessage());
        }
    }
}
