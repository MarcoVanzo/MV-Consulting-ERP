<?php
/**
 * Admin Controller — Gestione Utenti, Backup, Logs per MV Consulting ERP
 */

class AdminController {
    private $pdo;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    /**
     * Inizializza le tabelle necessarie. Chiamare solo durante il setup iniziale,
     * NON ad ogni richiesta (evita DDL overhead).
     */
    public static function ensureTables() {
        $pdo = Database::getConnection();
        $prefix = getenv('DB_PREFIX') ?: 'mv_';

        $sqlLogs = "CREATE TABLE IF NOT EXISTS `{$prefix}audit_logs` (
            `id` BIGINT AUTO_INCREMENT PRIMARY KEY,
            `user_id` INT NULL,
            `user_name` VARCHAR(100) NULL,
            `action` VARCHAR(50) NOT NULL,
            `table_name` VARCHAR(100) NULL,
            `record_id` VARCHAR(100) NULL,
            `details` TEXT NULL,
            `ip_address` VARCHAR(45) NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $pdo->exec($sqlLogs);

        $sqlBackups = "CREATE TABLE IF NOT EXISTS `{$prefix}db_backups` (
            `id` VARCHAR(50) PRIMARY KEY,
            `filename` VARCHAR(255) NOT NULL,
            `filesize` BIGINT NOT NULL DEFAULT 0,
            `row_count` INT NULL DEFAULT 0,
            `status` VARCHAR(20) DEFAULT 'ok',
            `created_by` INT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $pdo->exec($sqlBackups);
    }





    // ─── UTENTI ─────────────────────────────────────────────────────────────

    public function listUsers() {
        $sql = "SELECT id, email, full_name as name, role, last_login_at FROM {$this->prefix}users ORDER BY full_name ASC";
        $stmt = $this->pdo->query($sql);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::json(true, '', $users);
    }

    public function createUser($data = null) {
        if (!$data) $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        if (empty($data['email']) || empty($data['full_name'])) {
            Response::json(false, 'Email e Nome completi sono obbligatori');
        }

        $email = trim($data['email']);
        $name = trim($data['full_name']);
        $role = $data['role'] ?? 'operatore';
        
        $tempPassword = bin2hex(random_bytes(4));
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);

        // Check if exists
        $stmt = $this->pdo->prepare("SELECT id FROM {$this->prefix}users WHERE email = ?");
        $stmt->execute([$email]);
        if ($stmt->fetch()) {
            Response::json(false, 'Email già in uso');
        }

        $sql = "INSERT INTO {$this->prefix}users (email, username, password, full_name, role) VALUES (?, ?, ?, ?, ?)";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([$email, $email, $hash, $name, $role]);
        $id = $this->pdo->lastInsertId();

        Audit::log('INSERT', 'users', $id, null, null, ['email' => $email]);
        Response::json(true, 'Utente creato', ['tempPassword' => $tempPassword, 'id' => $id]);
    }

    public function deleteUser($data = null) {
        if (!$data) $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = $data['id'] ?? null;
        if (!$id) Response::json(false, 'ID utente mancante');
        
        $this->pdo->prepare("DELETE FROM {$this->prefix}users WHERE id = ?")->execute([$id]);
        Audit::log('DELETE', 'users', $id, null, null, null);
        Response::json(true, 'Utente eliminato');
    }

    public function resetPassword($data = null) {
        if (!$data) $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = $data['id'] ?? null;
        if (!$id) Response::json(false, 'ID utente mancante');

        $tempPassword = bin2hex(random_bytes(4));
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
        
        $this->pdo->prepare("UPDATE {$this->prefix}users SET password = ? WHERE id = ?")->execute([$hash, $id]);
        Audit::log('UPDATE', 'users', $id, null, null, ['action' => 'reset_password']);
        
        Response::json(true, 'Password resettata', ['tempPassword' => $tempPassword]);
    }

    // ─── BACKUP ─────────────────────────────────────────────────────────────

    public function listBackups() {
        $stmt = $this->pdo->query("SELECT * FROM {$this->prefix}db_backups ORDER BY created_at DESC");
        $backups = $stmt->fetchAll(PDO::FETCH_ASSOC);

        // get DB stats
        $stmt = $this->pdo->query("SELECT COUNT(*) as t_count FROM information_schema.tables WHERE table_schema = DATABASE() AND table_name LIKE '{$this->prefix}%'");
        $tables = $stmt->fetchColumn();

        Response::json(true, '', ['backups' => $backups, 'db_stats' => ['table_count' => $tables]]);
    }

    public function createBackup() {
        try {
            require_once dirname(__DIR__) . '/Shared/BackupService.php';
            require_once dirname(__DIR__) . '/Shared/GoogleDrive.php';
            
            // L'utente corrente (puoi prenderlo dalla sessione se disponibile, altrimenti admin manuale)
            $userId = $_SESSION['user_id'] ?? null;
            
            $service = new BackupService($this->pdo, $this->prefix);
            $result = $service->dump((string)$userId, 'Manuale da Pannello');
            
            if (!$result['success']) {
                Response::json(false, "Errore backup: " . $result['error']);
                return;
            }

            $driveEnabled = !empty(getenv('GDRIVE_CLIENT_ID')) && !empty(getenv('GDRIVE_REFRESH_TOKEN'));
            $driveMsg = '';
            
            if ($driveEnabled) {
                try {
                    $driveFileId = GoogleDrive::uploadFile($result['filepath'], $result['filename']);
                    $this->pdo->prepare("UPDATE {$this->prefix}db_backups SET status = 'synced' WHERE id = ?")->execute([$result['id']]);
                    $driveMsg = ' (Sincronizzato su Google Drive)';
                } catch (\Throwable $e) {
                    $driveMsg = ' (Errore GDrive: ' . $e->getMessage() . ')';
                }
            }
            
            Audit::log('CREATE', 'db_backups', $result['id'], null, null, ['filename' => $result['filename']]);
            Response::json(true, 'Backup creato con successo' . $driveMsg, ['filename' => $result['filename']]);
        } catch (Throwable $e) {
            Response::json(false, "Errore interno server: " . $e->getMessage());
        }
    }

    public function downloadBackup() {
        $id = $_GET['id'] ?? null;
        if (!$id) {
            Response::json(false, 'ID backup non fornito.', null, 400);
        }

        $stmt = $this->pdo->prepare("SELECT filename FROM {$this->prefix}db_backups WHERE id = ?");
        $stmt->execute([$id]);
        $filename = $stmt->fetchColumn();
        
        if (!$filename) {
            Response::json(false, 'Backup non trovato nel DB.', null, 404);
        }
        
        $filepath = dirname(__DIR__, 2) . '/storage/backups/' . basename($filename);
        if (file_exists($filepath)) {
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        } else {
            Response::json(false, 'File fisico non trovato sul disco.', null, 404);
        }
    }

    public function deleteBackup($data = null) {
        if (!$data) $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = $data['id'] ?? null;
        
        $stmt = $this->pdo->prepare("SELECT filename FROM {$this->prefix}db_backups WHERE id = ?");
        $stmt->execute([$id]);
        $filename = $stmt->fetchColumn();

        if ($filename) {
            $filepath = dirname(__DIR__, 2) . '/storage/backups/' . basename($filename);
            if (file_exists($filepath)) unlink($filepath);
            
            $this->pdo->prepare("DELETE FROM {$this->prefix}db_backups WHERE id = ?")->execute([$id]);
            Audit::log('DELETE', 'db_backups', $id, null, null, ['filename' => $filename]);
            Response::json(true, 'Backup eliminato');
        } else {
            Response::json(false, 'Backup non trovato');
        }
    }

    // ─── LOGS ─────────────────────────────────────────────────────────────

    public function listLogs($data = null) {
        if (!$data) $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $limit = max(1, min(500, (int)($data['limit'] ?? $_GET['limit'] ?? 100)));
        $offset = max(0, (int)($data['offset'] ?? $_GET['offset'] ?? 0));
        
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->prefix}audit_logs ORDER BY created_at DESC LIMIT :limit OFFSET :offset");
        $stmt->bindValue(':limit', $limit, PDO::PARAM_INT);
        $stmt->bindValue(':offset', $offset, PDO::PARAM_INT);
        $stmt->execute();
        $logs = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        Response::json(true, '', ['logs' => $logs]);
    }
}
