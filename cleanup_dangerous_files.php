<?php
/**
 * TEMPORARY cleanup script — deletes dangerous debug/test files from production
 * This file self-destructs after execution.
 */

// Auth: require deploy key
$deployKey = '';
$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    foreach (file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES) as $line) {
        if (strpos(trim($line), '#') === 0 || strpos($line, '=') === false) continue;
        list($k, $v) = explode('=', $line, 2);
        if (trim($k) === 'DEPLOY_KEY') $deployKey = trim($v, " \t\n\r\0\x0B\"");
    }
}

$token = $_SERVER['HTTP_X_DEPLOY_KEY'] ?? $_GET['key'] ?? '';
if (empty($deployKey) || $token !== $deployKey) {
    http_response_code(403);
    die(json_encode(['error' => 'Unauthorized']));
}

header('Content-Type: application/json');

$rootDir = __DIR__;
$apiDir = $rootDir . '/api';

// Files to delete
$dangerousFiles = [
    $apiDir . '/reset2.php',
    $apiDir . '/reset_pw_secure.php',
    $rootDir . '/test_login.php',
    $rootDir . '/test_db.php',
    $rootDir . '/test_audit.php',
    $rootDir . '/test_sync.php',
    $rootDir . '/fix_db.php',
];

$results = [];
foreach ($dangerousFiles as $file) {
    $basename = basename($file);
    if (file_exists($file)) {
        if (unlink($file)) {
            $results[] = ['file' => $basename, 'status' => 'DELETED'];
        } else {
            $results[] = ['file' => $basename, 'status' => 'FAILED'];
        }
    } else {
        $results[] = ['file' => $basename, 'status' => 'NOT_FOUND'];
    }
}

// Self-destruct
$selfDelete = unlink(__FILE__);

echo json_encode([
    'success' => true,
    'results' => $results,
    'self_deleted' => $selfDelete
], JSON_PRETTY_PRINT);
