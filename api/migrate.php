<?php
/**
 * MV Consulting ERP — Database Migration (Versioned)
 * 
 * Ogni migrazione viene tracciata nella tabella {prefix}migrations.
 * Solo le migrazioni non ancora eseguite vengono applicate (idempotente).
 */

require_once __DIR__ . '/Shared/Database.php';
require_once __DIR__ . '/Shared/Env.php';

Env::load(__DIR__ . '/../.env');

$pdo = Database::getConnection();
$prefix = getenv('DB_PREFIX') ?: 'mv_';

// ── Crea tabella migrations se non esiste ──
$pdo->exec("CREATE TABLE IF NOT EXISTS `{$prefix}migrations` (
    id INT AUTO_INCREMENT PRIMARY KEY,
    version VARCHAR(100) NOT NULL UNIQUE,
    applied_at DATETIME DEFAULT CURRENT_TIMESTAMP
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");

// Recupera migrazioni già applicate
$applied = [];
$stmt = $pdo->query("SELECT version FROM `{$prefix}migrations`");
while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
    $applied[$row['version']] = true;
}

// Elenco in api/Shared/migrazioni.php (condiviso con health.php, che conta quelle mancanti)
$queries = require __DIR__ . '/Shared/migrazioni.php';

// Versioni che su DB legacy possono dare 1054 (colonna sconosciuta) senza che sia un errore:
// v025 ADD username AFTER name (colonna 'name' assente) → si riprova senza AFTER;
// v034/v043 CHANGE user_name/timestamp su audit_logs (colonne legacy già rinominate o mai esistite).
$legacy1054 = ['v025', 'v034', 'v043'];

$results = [];
$newlyApplied = 0;

foreach ($queries as $idx => $sql) {
    $version = 'v' . str_pad((string)($idx + 1), 3, '0', STR_PAD_LEFT);
    
    // Skip already applied
    if (isset($applied[$version])) {
        continue;
    }

    try {
        $pdo->exec($sql);
        // Track as applied
        $pdo->prepare("INSERT INTO `{$prefix}migrations` (version) VALUES (?)")->execute([$version]);
        $newlyApplied++;
        
        preg_match('/(?:CREATE TABLE IF NOT EXISTS|ALTER TABLE)\s+`?(\S+?)`?\s/i', $sql, $m);
        $tableName = $m[1] ?? "migration_$version";
        $results[] = ['version' => $version, 'table' => $tableName, 'status' => 'OK'];
    } catch (PDOException $e) {
        $code = (int)($e->errorInfo[1] ?? 0);

        // v025: se manca la colonna di riferimento dell'AFTER, riprova aggiungendo in coda
        if ($code === 1054 && in_array($version, $legacy1054, true) && preg_match('/\bADD COLUMN\b.*\bAFTER\s+\w+\s*$/is', $sql)) {
            try {
                $pdo->exec(preg_replace('/\s+AFTER\s+\w+\s*$/i', '', $sql));
                $pdo->prepare("INSERT INTO `{$prefix}migrations` (version) VALUES (?)")->execute([$version]);
                $newlyApplied++;
                $results[] = ['version' => $version, 'status' => 'OK', 'message' => 'Applicata senza AFTER (colonna di riferimento assente)'];
                continue;
            } catch (PDOException $e2) {
                $e = $e2;
                $code = (int)($e2->errorInfo[1] ?? 0);
            }
        }

        // Duplicate column / table exists errors are safe to skip
        $safeErrors = [1060, 1061, 1050]; // dup column, dup key, table exists
        $isLegacyChange = $code === 1054 && in_array($version, $legacy1054, true) && preg_match('/\bCHANGE\b/i', $sql);
        if (in_array($code, $safeErrors, true) || $isLegacyChange) {
            // Mark as applied even if it was a safe skip
            try { $pdo->prepare("INSERT INTO `{$prefix}migrations` (version) VALUES (?)")->execute([$version]); } catch(\Throwable $ignore) {}
            $results[] = ['version' => $version, 'status' => 'SKIPPED', 'message' => $e->getMessage()];
        } else {
            $results[] = ['version' => $version, 'status' => 'ERROR', 'message' => $e->getMessage()];
        }
    }
}

$hasErrors = count(array_filter($results, fn($r) => $r['status'] === 'ERROR')) > 0;

header('Content-Type: application/json');
echo json_encode([
    'success' => !$hasErrors,
    'newly_applied' => $newlyApplied,
    'total_tracked' => count($applied) + $newlyApplied,
    'migrations' => $results
], JSON_PRETTY_PRINT);

