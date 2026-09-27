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
        // ── 1. Cartella dei backup: solo storage/backups (protetta da .htaccess, letta da download/elimina)
        $storagePath = self::storageDir();
        if (!is_dir($storagePath)) {
            @mkdir($storagePath, 0750, true);
        }
        if (!is_dir($storagePath) || !is_writable($storagePath)) {
            return ['success' => false, 'error' => 'Cartella di backup non scrivibile: storage/backups'];
        }

        // ── 2. Snapshot coerente: tutte le SELECT vedono il DB allo stesso istante (InnoDB)
        try {
            $this->pdo->exec('SET TRANSACTION ISOLATION LEVEL REPEATABLE READ');
            $this->pdo->exec('START TRANSACTION WITH CONSISTENT SNAPSHOT');
        } catch (\Throwable $e) {
            return ['success' => false, 'error' => 'Impossibile aprire lo snapshot: ' . $e->getMessage()];
        }
        try {
            $scrittura = $this->scriviDump($storagePath, $authorName);
        } finally {
            try { $this->pdo->exec('COMMIT'); } catch (\Throwable $e) {}
        }
        if (!$scrittura['success']) return $scrittura;
        ['backupId' => $backupId, 'sqlFile' => $sqlFile, 'zipFile' => $zipFile, 'sqlPath' => $sqlPath,
         'zipPath' => $zipPath, 'tableNames' => $tableNames, 'totalRows' => $totalRows] = $scrittura;

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

    /** Scrive il dump SQL (dentro lo snapshot aperto da dump()) */
    private function scriviDump(string $storagePath, string $authorName): array
    {
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
            return ['success' => false, 'error' => 'Impossibile scrivere il file di backup ' . $sqlFile];
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

        return ['success' => true, 'backupId' => $backupId, 'sqlFile' => $sqlFile, 'zipFile' => $zipFile, 'sqlPath' => $sqlPath,
                'zipPath' => $zipPath, 'tableNames' => $tableNames, 'totalRows' => $totalRows];
    }

    /** Unica cartella dei backup */
    public static function storageDir(): string
    {
        return dirname(__DIR__, 2) . '/storage/backups/';
    }

    /**
     * Backup notturno: dump, upload su Google Drive (se configurato) e pulizia dei vecchi.
     * Lo usano cron/backup_nightly.php (CLI) e router.php (module=cron&action=backup).
     * Esito senza percorsi né dati: finisce nella risposta HTTP del cron.
     *
     * @return array{success: bool, drive: string, filename?: string, filesize?: int, total_rows?: int, eliminati?: int, error?: string}
     */
    public function eseguiNotturno(string $authorName = 'Cron Automatico'): array
    {
        $result = $this->dump(null, $authorName);
        if (!$result['success']) {
            return ['success' => false, 'drive' => 'saltato', 'error' => $result['error']];
        }
        $esito = [
            'success' => true,
            'filename' => $result['filename'],
            'filesize' => (int)$result['filesize'],
            'total_rows' => (int)$result['total_rows'],
            'drive' => 'non_configurato',
        ];

        if (!empty(getenv('GDRIVE_CLIENT_ID')) && !empty(getenv('GDRIVE_REFRESH_TOKEN'))) {
            require_once __DIR__ . '/GoogleDrive.php';
            try {
                GoogleDrive::uploadFile($result['filepath'], $result['filename']);
                try {
                    $this->pdo->prepare("UPDATE {$this->prefix}db_backups SET status = 'synced' WHERE id = ?")->execute([$result['id']]);
                } catch (\Throwable $e) {}
                $esito['drive'] = 'ok';
            } catch (\Throwable $e) {
                error_log('[BACKUP] Upload Drive non riuscito: ' . $e->getMessage());
                $esito['success'] = false;
                $esito['drive'] = 'errore';
                $esito['error'] = 'Backup locale creato, upload su Google Drive non riuscito';
            }
        }

        $keep = (int)(getenv('BACKUP_KEEP') ?: 14);
        $esito['eliminati'] = $this->pulisciVecchi($keep);
        return $esito;
    }

    /**
     * Tiene in storage/backups solo gli ultimi $keep backup (minimo 1) e toglie dal registro
     * le righe dei file eliminati. Restituisce quanti file ha cancellato.
     */
    public function pulisciVecchi(int $keep = 14): int
    {
        $keep = max(1, $keep);
        $files = array_filter(glob(self::storageDir() . 'backup_*') ?: [], fn($f) => preg_match('/\.(zip|sql)$/', $f));
        // Il nome inizia con backup_AAAAMMGG_HHMMSS: l'ordine alfabetico è quello cronologico
        rsort($files, SORT_STRING);
        $eliminati = 0;
        foreach (array_slice($files, $keep) as $file) {
            if (!@unlink($file)) continue;
            $eliminati++;
            try {
                $this->pdo->prepare("DELETE FROM {$this->prefix}db_backups WHERE filename = ?")->execute([basename($file)]);
            } catch (\Throwable $e) {}
        }
        return $eliminati;
    }
}
