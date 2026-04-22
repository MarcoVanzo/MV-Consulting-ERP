<?php
/**
 * MV Consulting ERP — Health Check Endpoint
 * GET /api/health.php → {"status":"ok","latency_ms":12}
 */

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$start = microtime(true);
$checks = [];
$checks['php'] = 'ok';

try {
    require_once __DIR__ . '/Shared/Database.php';

    $envPath = __DIR__ . '/../.env';
    if (file_exists($envPath)) {
        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            list($name, $value) = explode('=', $line, 2);
            putenv(trim($name) . '=' . trim($value));
            $_ENV[trim($name)] = trim($value);
        }
    }

    $pdo = Database::getConnection();
    $dbStart = microtime(true);
    $pdo->query('SELECT 1');
    $dbLatency = round((microtime(true) - $dbStart) * 1000);
    $checks['database'] = 'ok';
    $checks['db_latency_ms'] = $dbLatency;
} catch (Throwable $e) {
    $checks['database'] = 'error';
    $checks['db_error'] = $e->getMessage();
}

$checks['disk'] = is_writable(__DIR__ . '/../storage') ? 'ok' : 'warning';

$allOk = ($checks['php'] === 'ok' && ($checks['database'] ?? '') === 'ok');
$latency = round((microtime(true) - $start) * 1000);

echo json_encode([
    'status'     => $allOk ? 'ok' : 'degraded',
    'latency_ms' => $latency,
    'checks'     => $checks,
    'timestamp'  => date('c'),
], JSON_PRETTY_PRINT);
