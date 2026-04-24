<?php

class Auth {
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
                if ($user['status'] === 'Disattivato') {
                    throw new Exception("Account disattivato.");
                }

                $dbPassword = !empty($user['password']) ? $user['password'] : (!empty($user['pwd_hash']) ? $user['pwd_hash'] : null);
                if ($dbPassword && password_verify($password, $dbPassword)) {
                    
                    // Reset failed attempts
                    $this->db->prepare("UPDATE {$prefix}users SET failed_attempts = 0 WHERE id = ?")->execute([$user['id']]);

                    if ($user['must_change_password']) {
                        return ['must_change' => true, 'reason' => 'mandatory', 'user_id' => $user['id']];
                    }

                    if (!empty($user['last_password_change'])) {
                        $lastChange = strtotime($user['last_password_change']);
                        $expiryDate = strtotime("+90 days", $lastChange);
                        if (time() > $expiryDate) {
                            $this->db->prepare("UPDATE {$prefix}users SET must_change_password = 1 WHERE id = ?")->execute([$user['id']]);
                            return ['must_change' => true, 'reason' => 'expired', 'user_id' => $user['id']];
                        }
                    }

                    $secret = getenv('JWT_SECRET');
                    if (!$secret) {
                        error_log('CRITICAL: JWT_SECRET non configurato nel .env');
                        return false;
                    }
                    
                    $jwtExpiration = (int)(getenv('JWT_EXPIRATION') ?: 86400 * 30);
                    $payload = [
                        'id' => $user['id'],
                        'email' => $user['email'],
                        'role' => $user['role'] ?? 'admin',
                        'exp' => time() + $jwtExpiration
                    ];
                    $token = JWT::encode($payload, $secret);
                    
                    $isSecure = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on');
                    setcookie('auth_token', $token, [
                        'expires' => time() + $jwtExpiration,
                        'path' => '/',
                        'httponly' => true,
                        'secure' => $isSecure,
                        'samesite' => 'Lax'
                    ]);
                    
                    // Audit fallback since we don't have the Audit class imported properly
                    if (class_exists('Audit')) {
                        Audit::log('LOGIN', 'users', $user['id'], null, null, ['email' => $user['email']]);
                    }
                    
                    return [
                        'id' => $user['id'],
                        'name' => $user['name'] ?? $user['full_name'] ?? 'User',
                        'email' => $user['email'],
                        'role' => $user['role'] ?? 'admin'
                    ];
                } else {
                    $this->db->prepare("UPDATE {$prefix}users SET failed_attempts = failed_attempts + 1 WHERE id = ?")->execute([$user['id']]);
                    $stmtCheck = $this->db->prepare("SELECT failed_attempts FROM {$prefix}users WHERE id = ?");
                    $stmtCheck->execute([$user['id']]);
                    if ($stmtCheck->fetchColumn() >= 10) {
                        $this->db->prepare("UPDATE {$prefix}users SET blocked = 1 WHERE id = ?")->execute([$user['id']]);
                    }
                }
            }
        } catch (PDOException $e) {
            error_log("Login DB Error: " . $e->getMessage());
        } catch (Exception $e) {
            error_log("Login Error: " . $e->getMessage());
        }

        return false;
    }

    public function requestPasswordReset($email) {
        $prefix = getenv('DB_PREFIX') ?: 'mv_';
        $stmt = $this->db->prepare("SELECT * FROM {$prefix}users WHERE email = :email LIMIT 1");
        $stmt->execute(['email' => $email]);
        $user = $stmt->fetch();
        if ($user) {
            require_once __DIR__ . '/Security.php';
            $tempPwd = Security::generateTempPassword();
            $hash = password_hash($tempPwd, PASSWORD_DEFAULT);
            $this->db->prepare("UPDATE {$prefix}users SET password = ?, last_password_change = NOW(), must_change_password = 1 WHERE id = ?")->execute([$hash, $user['id']]);
            
            // Assume Mailer class exists or uses mail()
            $subject = "Password Temporanea - MV Consulting ERP";
            $message = "La tua password temporanea è: $tempPwd \nAccedi al sistema e cambiala immediatamente.";
            if (class_exists('Mailer')) {
                Mailer::send($user['email'], $user['name'], $subject, $message);
            } else {
                mail($user['email'], $subject, $message);
            }
        }
        return true;
    }

    public function resetPassword($userId, $currentPwd, $newPwd) {
        $prefix = getenv('DB_PREFIX') ?: 'mv_';
        $stmt = $this->db->prepare("SELECT * FROM {$prefix}users WHERE id = :id LIMIT 1");
        $stmt->execute(['id' => $userId]);
        $user = $stmt->fetch();

        $dbPassword = !empty($user['password']) ? $user['password'] : (!empty($user['pwd_hash']) ? $user['pwd_hash'] : null);
        if (!$user || !password_verify($currentPwd, $dbPassword)) {
            throw new Exception("Password attuale errata.");
        }

        require_once __DIR__ . '/Security.php';
        if (!Security::validatePasswordComplexity($newPwd)) {
            throw new Exception("La password deve essere di almeno 12 caratteri e contenere maiuscole, minuscole, numeri e caratteri speciali.");
        }

        // History check
        $stmtHist = $this->db->prepare("SELECT pwd_hash FROM {$prefix}password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 5");
        $stmtHist->execute([$userId]);
        $history = $stmtHist->fetchAll(PDO::FETCH_COLUMN);
        foreach ($history as $oldHash) {
            if (password_verify($newPwd, $oldHash)) {
                throw new Exception("Non puoi riutilizzare una delle ultime 5 password.");
            }
        }

        $hash = password_hash($newPwd, PASSWORD_DEFAULT);
        $this->db->prepare("UPDATE {$prefix}users SET password = ?, last_password_change = NOW(), must_change_password = 0 WHERE id = ?")->execute([$hash, $userId]);
        
        $this->db->prepare("INSERT INTO {$prefix}password_history (user_id, pwd_hash) VALUES (?, ?)")->execute([$userId, $hash]);
        $this->db->prepare("DELETE FROM {$prefix}password_history WHERE user_id = ? AND id NOT IN (SELECT id FROM (SELECT id FROM {$prefix}password_history WHERE user_id = ? ORDER BY created_at DESC LIMIT 5) AS recent)")->execute([$userId, $userId]);

        return true;
    }
}
