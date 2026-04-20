<?php

class Auth {
    private $db;

    public function __construct() {
        $this->db = Database::getConnection();
    }

    public function login($email, $password) {
        // Here we assume a 'users' or 'ts_users' table structure
        // The prefix might be needed if he's using the same DB as Savino.
        $prefix = getenv('DB_PREFIX') ?: 'mv_';
        
        try {
            // Adjust table name if needed based on Savino DB schema
            $stmt = $this->db->prepare("SELECT * FROM {$prefix}users WHERE email = :email LIMIT 1");
            $stmt->execute(['email' => $email]);
            $user = $stmt->fetch();

            $dbPassword = !empty($user['password']) ? $user['password'] : (!empty($user['pwd_hash']) ? $user['pwd_hash'] : null);

            if ($user && $dbPassword && password_verify($password, $dbPassword)) {
                // Generate a simple token (in production use real JWT)
                $token = bin2hex(random_bytes(32));
                
                // Save it to user record if necessary or return it
                return [
                    'id' => $user['id'],
                    'name' => $user['name'] ?? $user['full_name'] ?? 'User',
                    'email' => $user['email'],
                    'role' => $user['role'] ?? 'admin',
                    'token' => $token
                ];
            }
        } catch (PDOException $e) {
            error_log("Login DB Error: " . $e->getMessage());
        }

        return false;
    }
}
