<?php
/**
 * Cron — Nightly Database Backup + Google Drive Upload
 * MV Consulting ERP
 *
 * Run every night at midnight via crontab:
 *   0 0 * * * php /Users/marcovanzo/MV\ Consulting\ ERP/cron/backup_nightly.php >> /Users/marcovanzo/MV\ Consulting\ ERP/cron/db_backup.log 2>&1
 */

declare(strict_types=1);

$isCli = php_sapi_name() === 'cli';
$token = $_GET['token'] ?? '';
if (!$isCli && $token !== 'MVC_ERP_Backup_2026!') {
    http_response_code(403);
    die("Access denied");
}

$rootDir = dirname(__DIR__);
require_once $rootDir . '/api/config.php';
require_once $rootDir . '/api/Shared/BackupService.php';
require_once $rootDir . '/api/Shared/GoogleDrive.php';

// Inizializza PDO
$host = getenv('DB_HOST') ?: 'localhost';
$db   = getenv('DB_NAME') ?: 'mvc_erp';
$user = getenv('DB_USER') ?: 'root';
$pass = getenv('DB_PASS') ?: '';
$prefix = getenv('DB_PREFIX') ?: 'mv_';
$charset = 'utf8mb4';

$dsn = "mysql:host=$host;dbname=$db;charset=$charset";
$options = [
    PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
    PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
    PDO::ATTR_EMULATE_PREPARES   => false,
];

try {
    $pdo = new PDO($dsn, $user, $pass, $options);
} catch (\PDOException $e) {
    die("Database connection failed: " . $e->getMessage());
}

$now = date('Y-m-d H:i:s');
echo "[{$now}] ====== MV Consulting ERP — Nightly Backup ======\n";

echo "[{$now}] Avvio dump database...\n";

$service = new BackupService($pdo, $prefix);
$result = $service->dump(null, 'Cron Automatico');

if (!$result['success']) {
    echo "[{$now}] ❌ ERRORE dump: {$result['error']}\n";
    exit(1);
}

$now = date('Y-m-d H:i:s');
echo "[{$now}] ✅ Dump completato: {$result['filename']} (" . number_format($result['filesize'] / 1024, 1) . " KB, {$result['total_rows']} righe)\n";

$driveEnabled = !empty(getenv('GDRIVE_CLIENT_ID')) && !empty(getenv('GDRIVE_REFRESH_TOKEN'));

if (!$driveEnabled) {
    echo "[{$now}] ⚠️  Google Drive non configurato — salto upload (GDRIVE_CLIENT_ID o GDRIVE_REFRESH_TOKEN mancanti nel .env)\n";
    echo "[{$now}] ====== Fine Backup ======\n";
    exit(0);
}

echo "[{$now}] Caricamento su Google Drive...\n";

try {
    $driveFileId = GoogleDrive::uploadFile($result['filepath'], $result['filename']);

    // Aggiorna info in db_backups se possibile
    try {
        $stmt = $pdo->prepare("UPDATE {$prefix}db_backups SET status = 'synced' WHERE id = ?");
        $stmt->execute([$result['id']]);
    } catch (\Exception $e) {}

    $now = date('Y-m-d H:i:s');
    echo "[{$now}] ✅ Upload completato — Drive File ID: {$driveFileId}\n";
    echo "[{$now}] ====== Fine Backup — SUCCESSO ======\n";
    exit(0);
}
catch (\Throwable $e) {
    $now = date('Y-m-d H:i:s');
    echo "[{$now}] ❌ ERRORE upload Drive: " . $e->getMessage() . "\n";
    echo "[{$now}] ⚠️  Backup locale disponibile: {$result['filename']}\n";
    echo "[{$now}] ====== Fine Backup — PARZIALE (solo locale) ======\n";
    exit(2);
}
