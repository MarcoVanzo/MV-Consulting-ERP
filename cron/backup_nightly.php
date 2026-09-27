<?php
/**
 * Cron — Backup notturno del database + upload su Google Drive + pulizia dei vecchi
 * MV Consulting ERP
 *
 * Solo da riga di comando. Sull'hosting non c'è crontab: il backup notturno parte da
 * GitHub Actions (.github/workflows/backup.yml → router.php module=cron&action=backup);
 * questo script serve per lanciarlo a mano o da un server con crontab:
 *   15 2 * * * php /percorso/del/progetto/cron/backup_nightly.php >> /percorso/del/progetto/cron/db_backup.log 2>&1
 *
 * Exit code: 0 = ok, 1 = errore (DB/dump), 2 = dump ok ma upload Drive fallito.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "Access denied";
    exit(1);
}

$rootDir = dirname(__DIR__);

function cronErr(string $msg): void
{
    fwrite(STDERR, $msg);
}

require_once $rootDir . '/api/Shared/Env.php';
Env::load($rootDir . '/.env');

require_once $rootDir . '/api/Shared/Database.php';
require_once $rootDir . '/api/Shared/BackupService.php';

try {
    $pdo = Database::getConnection();
} catch (\Throwable $e) {
    cronErr("Connessione al database non riuscita: " . $e->getMessage() . "\n");
    exit(1);
}
$prefix = getenv('DB_PREFIX') ?: 'mv_';

$now = date('Y-m-d H:i:s');
echo "[{$now}] ====== MV Consulting ERP — Backup notturno ======\n";

$esito = (new BackupService($pdo, $prefix))->eseguiNotturno('Cron Automatico');
$now = date('Y-m-d H:i:s');

if (empty($esito['filename'])) {
    cronErr("[{$now}] ERRORE dump: " . ($esito['error'] ?? 'sconosciuto') . "\n");
    exit(1);
}

echo "[{$now}] Dump completato: {$esito['filename']} (" . number_format($esito['filesize'] / 1024, 1) . " KB, {$esito['total_rows']} righe)\n";
echo "[{$now}] Google Drive: {$esito['drive']} — backup vecchi eliminati: {$esito['eliminati']}\n";

if ($esito['drive'] === 'errore') {
    cronErr("[{$now}] ERRORE upload Drive: backup disponibile solo in locale\n");
    exit(2);
}
echo "[{$now}] ====== Fine backup — SUCCESSO ======\n";
exit(0);
