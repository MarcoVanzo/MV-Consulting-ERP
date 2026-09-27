<?php
/**
 * BackupService — Standalone database dump + ZIP + metadata persistence
 * Adattato per MV ERP e MV Consulting ERP.
 */

declare(strict_types=1);

class BackupService
{
    private PDO $pdo;
    private string $prefix;

    public function __construct(PDO $pdo, string $prefix = '')
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    /**
     * Perform a full database dump, compress to ZIP, persist metadata and emit audit log.
     *
     * @param string|null $createdBy   User ID (null = cron / automated)
     * @param string      $authorName  Display name for the SQL header comment
     *
     * @return array
     */
    public function dump(?string $createdBy, string $authorName = 'System'): array
    {
        // ── 1. Resolve writable storage directory ─────────────────────────────
        $envPath = getenv('BACKUP_STORAGE_PATH') ?: '';
        $candidates = array_filter([
            $envPath ? rtrim($envPath, '/') . '/' : '',
            dirname(__DIR__, 2) . '/storage/backups/',
            dirname(__DIR__, 2) . '/backups/',
            dirname(__DIR__, 2) . '/uploads/backups/',
            sys_get_temp_dir() . '/mv_backups/',
        ]);

        $storagePath = null;
        foreach ($candidates as $candidate) {
            if (!is_dir($candidate)) {
                @mkdir($candidate, 0750, true);
            }
            if (is_dir($candidate) && is_writable($candidate)) {
                $storagePath = $candidate;
                break;
            }
        }

        if ($storagePath === null) {
            return ['success' => false, 'error' => 'Nessuna directory di backup scrivibile disponibile'];
        }

        // ── 2. List tables ────────────────────────────────────────────────────
        // '_' e '%' nel prefisso sono jolly di LIKE: vanno escapati
        $likePrefix = addcslashes($this->prefix, '\\_%') . '%';
        $stmt = $this->pdo->prepare("SELECT TABLE_NAME, TABLE_ROWS FROM INFORMATION_SCHEMA.TABLES WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME LIKE ?");
        $stmt->execute([$likePrefix]);
        $tables = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $tableNames = array_column($tables, 'TABLE_NAME');
        $totalRows = (int)array_sum(array_column($tables, 'TABLE_ROWS'));

        if (empty($tableNames)) {
            return ['success' => false, 'error' => 'Nessuna tabella trovata nel database'];
        }

        // ── 3. Open output file ───────────────────────────────────────────────
        $backupId = 'BKP_' . bin2hex(random_bytes(6));
        $date = date('Ymd_His');
        $sqlFile = "backup_{$date}_{$backupId}.sql";
        $zipFile = "backup_{$date}_{$backupId}.zip";
        $sqlPath = $storagePath . $sqlFile;
        $zipPath = $storagePath . $zipFile;

        $fh = fopen($sqlPath, 'w');
        if ($fh === false) {
            return ['success' => false, 'error' => 'Impossibile scrivere il file di backup in ' . $sqlPath];
        }

        // ── 4. Write SQL header ───────────────────────────────────────────────
        fwrite($fh, "-- Database Backup\n");
        fwrite($fh, "-- Generated: " . date('Y-m-d H:i:s') . "\n");
        fwrite($fh, "-- By: {$authorName}\n");
        fwrite($fh, "-- Tables: " . implode(', ', $tableNames) . "\n\n");
        fwrite($fh, "SET FOREIGN_KEY_CHECKS=0;\nSET SQL_MODE='NO_AUTO_VALUE_ON_ZERO';\nSET NAMES utf8mb4;\n\n");

        // ── 5. Dump each table ────────────────────────────────────────────────
        $readErrors = [];
        foreach ($tableNames as $table) {
            if (!preg_match('/^[a-zA-Z0-9_]+$/', $table)) {
                fwrite($fh, "-- SKIPPED unsafe table name: {$table}\n");
                continue;
            }

            try {
                $row = $this->pdo->query("SHOW CREATE TABLE `{$table}`")->fetch(PDO::FETCH_NUM);
                $createSql = $row[1] ?? '';
            } catch (\Throwable $e) {
                $createSql = "-- Could not retrieve CREATE for {$table}: " . $e->getMessage();
                $readErrors[] = "{$table}: " . $e->getMessage();
            }

            fwrite($fh, "-- ──────── TABLE: {$table} ────────\n");
            fwrite($fh, "DROP TABLE IF EXISTS `{$table}`;\n");
            fwrite($fh, $createSql . ";\n\n");

            // ORDER BY sulla chiave primaria: senza, LIMIT/OFFSET può saltare o duplicare righe
            $orderBy = '';
            try {
                $pkStmt = $this->pdo->prepare("SELECT COLUMN_NAME FROM INFORMATION_SCHEMA.KEY_COLUMN_USAGE WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ? AND CONSTRAINT_NAME = 'PRIMARY' ORDER BY ORDINAL_POSITION");
                $pkStmt->execute([$table]);
                $pkCols = $pkStmt->fetchAll(PDO::FETCH_COLUMN);
                if ($pkCols) {
                    $orderBy = ' ORDER BY ' . implode(', ', array_map(fn($c) => '`' . str_replace('`', '``', $c) . '`', $pkCols));
                }
            } catch (\Throwable $e) {
                $readErrors[] = "{$table}: " . $e->getMessage();
            }

            $offset = 0;
            $chunkSize = 500;
            do {
                try {
                    $stmt = $this->pdo->prepare("SELECT * FROM `{$table}`{$orderBy} LIMIT " . (string)$chunkSize . " OFFSET " . (string)$offset);
                    $stmt->execute();
                    $rows = $stmt->fetchAll(PDO::FETCH_NUM);
                } catch (\Throwable $e) {
                    fwrite($fh, "-- Error reading {$table}: " . $e->getMessage() . "\n");
                    $readErrors[] = "{$table}: " . $e->getMessage();
                    break;
                }
                if (empty($rows)) {
                    break;
                }

                fwrite($fh, "INSERT INTO `{$table}` VALUES\n");
                $rowStrings = [];
                foreach ($rows as $row) {
                    $vals = array_map(fn($v) => $v === null ? 'NULL' : $this->pdo->quote((string)$v), $row);
                    $rowStrings[] = '(' . implode(',', $vals) . ')';
                }
                fwrite($fh, implode(",\n", $rowStrings) . ";\n");
                $offset += $chunkSize;
            } while (count($rows) === $chunkSize);

            fwrite($fh, "\n");
        }

        fwrite($fh, "SET FOREIGN_KEY_CHECKS=1;\n");
        fclose($fh);

        // Un dump incompleto non è un backup: si scarta e si segnala l'errore
        if ($readErrors) {
            @unlink($sqlPath);
            return ['success' => false, 'error' => 'Backup incompleto, errore di lettura: ' . implode('; ', $readErrors)];
        }

        // ── 6. Compress to ZIP ────────────────────────────────────────────────
        $filesize = 0;
        $finalFile = $zipFile;
        $finalPath = $zipPath;

        if (class_exists('ZipArchive')) {
            $zip = new \ZipArchive();
            if ($zip->open($zipPath, \ZipArchive::CREATE) === true) {
                $zip->addFile($sqlPath, $sqlFile);
                if ($zip->close() && file_exists($zipPath)) {
                    unlink($sqlPath);
                    $filesize = filesize($zipPath);
                } else {
                    // ZIP non scritto: si tiene lo .sql non compresso
                    @unlink($zipPath);
                    $finalFile = $sqlFile;
                    $finalPath = $sqlPath;
                    $filesize = file_exists($sqlPath) ? filesize($sqlPath) : 0;
                }
            } else {
                $finalFile = $sqlFile;
                $finalPath = $sqlPath;
                $filesize = file_exists($sqlPath) ? filesize($sqlPath) : 0;
            }
        } else {
            $finalFile = $sqlFile;
            $finalPath = $sqlPath;
            $filesize = file_exists($sqlPath) ? filesize($sqlPath) : 0;
        }

        // ── 7. Persist metadata (if table exists) ───────────────────────────────
        try {
            // Check if db_backups table exists
            $stmt = $this->pdo->query("SHOW TABLES LIKE " . $this->pdo->quote(addcslashes($this->prefix . 'db_backups', '\\_%')));
            if ($stmt->fetch()) {
                $sql = "INSERT INTO {$this->prefix}db_backups (id, filename, filesize, row_count, created_by, status) VALUES (?, ?, ?, ?, ?, 'ok')";
                $this->pdo->prepare($sql)->execute([$backupId, $finalFile, $filesize, $totalRows, $createdBy]);
            }
        } catch (\Throwable $e) {
            error_log('[BACKUP] DB saveBackupRecord failed: ' . $e->getMessage());
        }

        return [
            'success' => true,
            'id' => $backupId,
            'filename' => $finalFile,
            'filepath' => $finalPath,
            'filesize' => $filesize,
            'table_names' => $tableNames,
            'total_rows' => $totalRows,
        ];
    }
}