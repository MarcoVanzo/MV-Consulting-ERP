<?php
/**
 * Audit Logger
 * MV Consulting ERP v1.0
 */

declare(strict_types=1);

require_once __DIR__ . '/Database.php';
require_once __DIR__ . '/Security.php';

class Audit
{
    /**
     * Log a write action to mv_audit_logs.
     *
     * @param string      $action     INSERT | UPDATE | DELETE | ecc
     * @param string      $tableName  Target table name
     * @param string|null $recordId   Primary key of affected record
     * @param array|null  $before     Snapshot before change
     * @param array|null  $after      Snapshot after change
     * @param array|null  $details    Additional details
     * @param string|null $eventType  Event category (login, crud, error, etc.)
     * @param int|null    $tenantId   Overrides tenant_id
     * @param array|null  $user       Overrides user object
     */
    public static function log(
        string $action,
        string $tableName,
        ?string $recordId = null,
        ?array $before = null,
        ?array $after = null,
        ?array $details = null,
        string $eventType = 'crud',
        ?int $tenantId = null,
        ?array $user = null
        ): void
    {
        try {
            $pdo = Database::getConnection();
            $prefix = getenv('DB_PREFIX') ?: 'mv_';

            if (!$user) {
                // Utente dal middleware di router.php (JWT da cookie o Bearer, riletto dal DB)
                $ctx = $GLOBALS['userContext'] ?? null;
                if (is_array($ctx) && !empty($ctx['id'])) {
                    $user = [
                        'id' => $ctx['id'],
                        'username' => $ctx['name'] ?? $ctx['email'] ?? 'User',
                        'role' => $ctx['role'] ?? null,
                    ];
                }
            }
            
            $userId = $user['id'] ?? null;
            $username = $user['username'] ?? 'System';
            $role = $user['role'] ?? null;
            $resolvedTenantId = $tenantId ?? 1;

            // Stesso IP del rate limit: X-Forwarded-For solo da proxy fidati
            $ip = Security::clientIp();
            $id = 'AUD_' . bin2hex(random_bytes(4));

            $userAgent = $_SERVER['HTTP_USER_AGENT'] ?? null;
            $httpStatus = http_response_code() ?: 200;

            $sensitiveKeys = ['password', 'token'];
            $sanitize = function ($data) use ($sensitiveKeys) {
                if ($data === null) return null;
                $arr = is_array($data) ? $data : json_decode((string)$data, true);
                if (!is_array($arr)) return is_string($data) ? $data : json_encode($data);
                foreach ($sensitiveKeys as $k) {
                    if (isset($arr[$k])) $arr[$k] = '***';
                }
                return json_encode($arr, JSON_UNESCAPED_UNICODE);
            };

            $stmt = $pdo->prepare(
                "INSERT INTO {$prefix}audit_logs
                    (id, tenant_id, user_id, username, role, action, event_type, table_name, record_id,
                     before_snapshot, after_snapshot, ip_address, user_agent, http_status, details)
                 VALUES
                    (:id, :tenant_id, :user_id, :username, :role, :action, :event_type, :table_name, :record_id,
                     :before, :after, :ip, :user_agent, :http_status, :details)"
            );

            $stmt->execute([
                ':id' => $id,
                ':tenant_id' => $resolvedTenantId,
                ':user_id' => $userId,
                ':username' => $username,
                ':role' => $role,
                ':action' => $action,
                ':event_type' => $eventType,
                ':table_name' => $tableName,
                ':record_id' => $recordId,
                ':before' => $sanitize($before),
                ':after' => $sanitize($after),
                ':ip' => $ip,
                ':user_agent' => $userAgent ? mb_substr($userAgent, 0, 512) : null,
                ':http_status' => $httpStatus,
                ':details' => $details ? json_encode($details, JSON_UNESCAPED_UNICODE) : null,
            ]);
        }
        catch (Exception $e) {
            error_log('[AUDIT] Failed to write audit log: ' . $e->getMessage());
        }
    }
}
