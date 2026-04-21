<?php

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Auth.php';

class Logger {
    public static function logAction($action, $tableName = null, $recordId = null, $details = null) {
        try {
            $pdo = Database::getConnection();
            $prefix = getenv('DB_PREFIX') ?: 'mv_';
            
            // Check if user is authenticated via JWT to get detailed info
            $userName = 'System';
            $userId = null;
            
            $headers = getallheaders();
            $authHeader = $headers['Authorization'] ?? $headers['authorization'] ?? '';
            
            if (preg_match('/Bearer\s(\S+)/', $authHeader, $matches)) {
                $token = $matches[1];
                $secret = getenv('JWT_SECRET');
                if ($secret) {
                    try {
                        $payload = JWT::decode($token, $secret);
                        if (is_array($payload)) {
                            $userName = $payload['email'] ?? 'Token User';
                            $userId = $payload['id'] ?? null;
                        }
                    } catch (Exception $e) {
                        $userName = 'Unknown User';
                    }
                }
            } else {
                // Try session or default
                $userName = 'Admin (Sistema)';
            }
            
            $ip = $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
            
            $sql = "INSERT INTO {$prefix}audit_logs (user_id, user_name, action, table_name, record_id, details, ip_address) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $pdo->prepare($sql)->execute([
                $userId,
                $userName, 
                $action, 
                $tableName, 
                $recordId, 
                $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null, 
                $ip
            ]);
        } catch (Exception $e) {
            // Silently fail logging so it doesn't break primary flow
            error_log("Logging error: " . $e->getMessage());
        }
    }
}
