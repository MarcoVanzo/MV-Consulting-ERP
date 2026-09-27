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

    require_once __DIR__ . '/Shared/Env.php';
    Env::load(__DIR__ . '/../.env');

    $pdo = Database::getConnection();
    $dbStart = microtime(true);
    $pdo->query('SELECT 1');
    $dbLatency = round((microtime(true) - $dbStart) * 1000);
    $checks['database'] = 'ok';
    $checks['db_latency_ms'] = $dbLatency;
} catch (Throwable $e) {
    $checks['database'] = 'error';
    error_log('health: ' . $e->getMessage());
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
