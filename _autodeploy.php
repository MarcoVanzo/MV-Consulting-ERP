<?php
/**
 * Auto-deploy: scarica l'ultima versione dei file dal repository GitHub.
 * ATTENZIONE: Questo file si auto-elimina dopo l'uso.
 * Accesso protetto da token monouso.
 */

$deployToken = 'mv-deploy-2026-04-21-x9k2m';
if (($_GET['token'] ?? '') !== $deployToken) {
    http_response_code(403);
    die('Accesso negato.');
}

$files = [
    'api/Controllers/TrasferteController.php'
];

$repo = 'MarcoVanzo/MV-Consulting-ERP';
$branch = 'main';
$baseUrl = "https://raw.githubusercontent.com/$repo/$branch/";
$baseDir = __DIR__ . '/';

$results = [];

foreach ($files as $file) {
    $url = $baseUrl . $file;
    $targetPath = $baseDir . $file;

    $ch = curl_init($url);
    curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
    curl_setopt($ch, CURLOPT_FOLLOWLOCATION, true);
    curl_setopt($ch, CURLOPT_USERAGENT, 'MVC-ERP-Deploy/1.0');
    curl_setopt($ch, CURLOPT_TIMEOUT, 30);
    $content = curl_exec($ch);
    $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);

    if ($httpCode === 200 && $content) {
        // Backup del file esistente
        if (file_exists($targetPath)) {
            copy($targetPath, $targetPath . '.bak');
        }
        // Scrivi il nuovo contenuto
        $dir = dirname($targetPath);
        if (!is_dir($dir)) mkdir($dir, 0755, true);
        file_put_contents($targetPath, $content);
        $results[] = "✅ $file aggiornato (" . strlen($content) . " bytes)";
    } else {
        $results[] = "❌ $file fallito (HTTP $httpCode)";
    }
}

// Auto-elimina questo script per sicurezza
unlink(__FILE__);

header('Content-Type: text/plain; charset=utf-8');
echo "=== MV ERP Auto-Deploy ===\n";
echo date('Y-m-d H:i:s') . "\n\n";
echo implode("\n", $results) . "\n";
echo "\n🔒 Script auto-eliminato per sicurezza.\n";
