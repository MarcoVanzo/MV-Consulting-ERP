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
        $this->initTables();
    }

    /**
     * Assicura che le tabelle per i log e backup esistano prima di procedere
     */
    private function initTables() {
        // Tabella Logs
        $sqlLogs = "CREATE TABLE IF NOT EXISTS `{$this->prefix}audit_logs` (
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
        $this->pdo->exec($sqlLogs);

        // Tabella Backup
        $sqlBackups = "CREATE TABLE IF NOT EXISTS `{$this->prefix}db_backups` (
            `id` VARCHAR(50) PRIMARY KEY,
            `filename` VARCHAR(255) NOT NULL,
            `filesize` BIGINT NOT NULL DEFAULT 0,
            `row_count` INT NULL DEFAULT 0,
            `status` VARCHAR(20) DEFAULT 'ok',
            `created_by` INT NULL,
            `created_at` DATETIME DEFAULT CURRENT_TIMESTAMP
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4;";
        $this->pdo->exec($sqlBackups);
    }



    // ─── UTENTI ─────────────────────────────────────────────────────────────

    public function listUsers() {
        $sql = "SELECT id, email, full_name as name, role, last_login_at FROM {$this->prefix}users ORDER BY full_name ASC";
        $stmt = $this->pdo->query($sql);
        $users = $stmt->fetchAll(PDO::FETCH_ASSOC);
        Response::json(true, '', $users);
    }

    public function createUser() {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
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

        Logger::logAction('INSERT', 'users', $id, ['email' => $email]);
        Response::json(true, 'Utente creato', ['tempPassword' => $tempPassword, 'id' => $id]);
    }

    public function deleteUser() {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = $data['id'] ?? null;
        if (!$id) Response::json(false, 'ID utente mancante');
        
        $this->pdo->prepare("DELETE FROM {$this->prefix}users WHERE id = ?")->execute([$id]);
        Logger::logAction('DELETE', 'users', $id);
        Response::json(true, 'Utente eliminato');
    }

    public function resetPassword() {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = $data['id'] ?? null;
        if (!$id) Response::json(false, 'ID utente mancante');

        $tempPassword = bin2hex(random_bytes(4));
        $hash = password_hash($tempPassword, PASSWORD_DEFAULT);
        
        $this->pdo->prepare("UPDATE {$this->prefix}users SET password = ? WHERE id = ?")->execute([$hash, $id]);
        Logger::logAction('UPDATE', 'users', $id, ['action' => 'reset_password']);
        
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
        ob_start();
        try {
            $id = 'BKP_' . bin2hex(random_bytes(6));
            $filename = "backup_mv_{$id}_" . date('Ymd_His') . ".sql";
            $dir = dirname(__DIR__, 2) . '/storage/backups';
            if (!is_dir($dir)) {
                if (!@mkdir($dir, 0755, true)) {
                    throw new Exception("Impossibile creare la cartella di backup: $dir");
                }
            }
            $filepath = $dir . '/' . $filename;

            // Esegui dump usando mysqldump se disponibile, altrimenti finto backup
            $host = getenv('DB_HOST') ?: 'localhost';
            $user = getenv('DB_USER') ?: 'root';
            $pass = getenv('DB_PASS') ?: '';
            $name = getenv('DB_NAME') ?: 'mv_erp';
            
            $return_var = 1;
            if (function_exists('exec')) {
                $cmd = "mysqldump -h {$host} -u {$user} -p{$pass} {$name} > {$filepath} 2>/dev/null";
                @exec($cmd, $output, $return_var);
            }

            if ($return_var !== 0 || !file_exists($filepath) || filesize($filepath) === 0) {
                // Generiamo un dump via PHP PDO visto che mysqldump non va
                $fp = @fopen($filepath, 'w');
                if (!$fp) {
                    throw new Exception("Impossibile scrivere il file di backup in $filepath");
                }
                fwrite($fp, "-- Backup DB $name via PHP PDO\n");
                fwrite($fp, "-- Data: " . date('Y-m-d H:i:s') . "\n\n");
                
                try {
                    $tables = [];
                    $stmt = $this->pdo->query("SHOW TABLES");
                    while ($row = $stmt->fetch(PDO::FETCH_NUM)) {
                        $tables[] = $row[0];
                    }

                    foreach ($tables as $table) {
                        $stmt = $this->pdo->query("SHOW CREATE TABLE `$table`");
                        $row = $stmt->fetch(PDO::FETCH_NUM);
                        fwrite($fp, "DROP TABLE IF EXISTS `$table`;\n");
                        fwrite($fp, $row[1] . ";\n\n");

                        $stmt = $this->pdo->query("SELECT * FROM `$table`");
                        while ($rowData = $stmt->fetch(PDO::FETCH_ASSOC)) {
                            $escapedValues = array_map(function($v) {
                                return ($v === null) ? 'NULL' : $this->pdo->quote($v);
                            }, array_values($rowData));
                            $sql = "INSERT INTO `$table` (`" . implode("`, `", array_keys($rowData)) . "`) VALUES (" . implode(", ", $escapedValues) . ");\n";
                            fwrite($fp, $sql);
                        }
                        fwrite($fp, "\n");
                    }
                } catch(Exception $e) {
                    fclose($fp);
                    throw new Exception("Errore durante il dump PDO: " . $e->getMessage());
                }
                fclose($fp);
            }

            $filesize = @filesize($filepath) ?: 0;
            $sql = "INSERT INTO {$this->prefix}db_backups (id, filename, filesize) VALUES (?, ?, ?)";
            $this->pdo->prepare($sql)->execute([$id, $filename, $filesize]);

            Logger::logAction('CREATE', 'db_backups', $id, ['filename' => $filename]);
            $warns = ob_get_clean();
            Response::json(true, 'Backup creato' . ($warns ? " [Avvisi: $warns]" : ""), ['filename' => $filename]);
        } catch (Throwable $e) {
            $warns = ob_get_clean();
            Response::json(false, "Errore interno server: " . $e->getMessage() . " | " . $warns);
        }
    }

    public function downloadBackup() {
        $id = $_GET['id'] ?? null;
        if (!$id) die("ID backup non fornito.");

        $stmt = $this->pdo->prepare("SELECT filename FROM {$this->prefix}db_backups WHERE id = ?");
        $stmt->execute([$id]);
        $filename = $stmt->fetchColumn();
        
        if (!$filename) die("Backup non trovato nel DB.");
        
        $filepath = dirname(__DIR__, 2) . '/storage/backups/' . basename($filename);
        if (file_exists($filepath)) {
            header('Content-Type: application/sql');
            header('Content-Disposition: attachment; filename="' . basename($filepath) . '"');
            header('Content-Length: ' . filesize($filepath));
            readfile($filepath);
            exit;
        } else {
            die("File fisico non trovato sul disco.");
        }
    }

    public function deleteBackup() {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $id = $data['id'] ?? null;
        
        $stmt = $this->pdo->prepare("SELECT filename FROM {$this->prefix}db_backups WHERE id = ?");
        $stmt->execute([$id]);
        $filename = $stmt->fetchColumn();

        if ($filename) {
            $filepath = dirname(__DIR__, 2) . '/storage/backups/' . basename($filename);
            if (file_exists($filepath)) unlink($filepath);
            
            $this->pdo->prepare("DELETE FROM {$this->prefix}db_backups WHERE id = ?")->execute([$id]);
            Logger::logAction('DELETE', 'db_backups', $id, ['filename' => $filename]);
            Response::json(true, 'Backup eliminato');
        } else {
            Response::json(false, 'Backup non trovato');
        }
    }

    // ─── LOGS ─────────────────────────────────────────────────────────────

    public function listLogs() {
        $data = json_decode(file_get_contents('php://input'), true) ?? $_POST;
        $limit = max(1, min(500, (int)($data['limit'] ?? $_GET['limit'] ?? 100)));
        $offset = max(0, (int)($data['offset'] ?? $_GET['offset'] ?? 0));
        
        $sql = "SELECT * FROM {$this->prefix}audit_logs ORDER BY created_at DESC LIMIT $limit OFFSET $offset";
        $logs = $this->pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC);
        
        Response::json(true, '', ['logs' => $logs]);
    }
}
