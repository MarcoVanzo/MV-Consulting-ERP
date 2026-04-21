<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/api/Shared/Database.php';

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        if (strpos($line, '=') === false) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
        $_ENV[trim($name)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

try {
    $pdo = Database::getConnection();
    $prefix = getenv('DB_PREFIX') ?: 'mv_';

    // Get tokens
    $stmt = $pdo->query("SELECT * FROM {$prefix}google_tokens LIMIT 1");
    $tokenRow = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$tokenRow) { echo "No Google tokens found!\n"; exit; }
    
    $accessToken = $tokenRow['access_token'];
    $refreshToken = $tokenRow['refresh_token'];
    $expiresAt = $tokenRow['expires_at'] ?? 0;
    
    // Refresh token if expired
    if (time() >= $expiresAt) {
        echo "Token scaduto, refreshing...\n";
        $clientId = getenv('GOOGLE_CLIENT_ID');
        $clientSecret = getenv('GOOGLE_CLIENT_SECRET');
        
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'grant_type' => 'refresh_token',
            'refresh_token' => $refreshToken,
            'client_id' => $clientId,
            'client_secret' => $clientSecret,
        ]));
        $resp = curl_exec($ch);
        curl_close($ch);
        $tokenData = json_decode($resp, true);
        
        if (isset($tokenData['access_token'])) {
            $accessToken = $tokenData['access_token'];
            $newExpires = time() + ($tokenData['expires_in'] ?? 3600);
            $pdo->prepare("UPDATE {$prefix}google_tokens SET access_token = ?, expires_at = ?")->execute([$accessToken, $newExpires]);
            echo "Token refreshed OK\n\n";
        } else {
            echo "Token refresh FAILED: " . json_encode($tokenData) . "\n";
            exit;
        }
    }

    // Get calendar IDs
    $calendarIds = array_filter(array_map('trim', explode(',', getenv('GOOGLE_CALENDAR_IDS') ?: 'primary')));
    echo "Calendari: " . implode(', ', $calendarIds) . "\n\n";

    // Fetch events for April 21 specifically
    foreach ($calendarIds as $calId) {
        echo "=== CALENDAR: $calId ===\n\n";
        
        $params = [
            'timeMin' => '2026-04-21T00:00:00Z',
            'timeMax' => '2026-04-22T00:00:00Z',
            'singleEvents' => 'true',
            'orderBy' => 'startTime',
        ];
        
        $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calId) . "/events?" . http_build_query($params);
        
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_HTTPHEADER, ["Authorization: Bearer $accessToken"]);
        $response = curl_exec($ch);
        curl_close($ch);
        
        $data = json_decode($response, true);
        
        if (isset($data['error'])) {
            echo "ERROR: " . json_encode($data['error']) . "\n";
            continue;
        }
        
        $items = $data['items'] ?? [];
        echo "Eventi trovati: " . count($items) . "\n\n";
        
        foreach ($items as $event) {
            echo "--- Event ID: {$event['id']} ---\n";
            echo "  Summary: " . ($event['summary'] ?? 'N/A') . "\n";
            echo "  Start RAW: " . json_encode($event['start']) . "\n";
            echo "  End RAW:   " . json_encode($event['end']) . "\n";
            
            // Simula la logica del controller
            $isAllDay = isset($event['start']['date']);
            $hasDateTime = isset($event['start']['dateTime']);
            echo "  isAllDay: " . ($isAllDay ? 'YES' : 'NO') . "\n";
            echo "  hasDateTime: " . ($hasDateTime ? 'YES' : 'NO') . "\n";
            
            if ($hasDateTime) {
                $startHour = (int)date('H', strtotime($event['start']['dateTime']));
                $endHour = (int)date('H', strtotime($event['end']['dateTime']));
                echo "  StartHour: $startHour | EndHour: $endHour\n";
                
                if ($startHour < 13 && $endHour <= 14) {
                    echo "  → FASCIA: mattino\n";
                } elseif ($startHour >= 13) {
                    echo "  → FASCIA: pomeriggio\n";
                } else {
                    echo "  → FASCIA: intera\n";
                }
            } else {
                echo "  → FASCIA: intera (all-day)\n";
            }
            echo "\n";
        }
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
