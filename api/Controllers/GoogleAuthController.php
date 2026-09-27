<?php
/**
 * Google Auth Controller - Handles OAuth2 and Calendar Sync via cURL
 */

require_once __DIR__ . '/../Shared/TrasferteSync.php';
require_once __DIR__ . '/TrasferteController.php';

class GoogleAuthController {
    private $pdo;
    private $clientId;
    private $clientSecret;
    private $redirectUri;
    private $calendarIds;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
        $this->clientId = getenv('GOOGLE_CLIENT_ID');
        $this->clientSecret = getenv('GOOGLE_CLIENT_SECRET');
        $this->redirectUri = getenv('GOOGLE_REDIRECT_URI') ?: 'http://localhost/api/router.php?module=google&action=callback';
        
        $calIds = getenv('GOOGLE_CALENDAR_IDS');
        $this->calendarIds = $calIds ? array_map('trim', explode(',', $calIds)) : [];
    }

    private const STATE_COOKIE = 'google_oauth_state';

    /** Solo un admin autenticato (contesto impostato dal middleware di router.php) */
    private function isAdmin(): bool {
        return (($GLOBALS['userContext']['role'] ?? '') === 'admin');
    }

    private function setStateCookie(string $value, int $expires): void {
        setcookie(self::STATE_COOKIE, $value, [
            'expires'  => $expires,
            'path'     => '/',
            'httponly' => true,
            'secure'   => (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on'),
            'samesite' => 'Lax' // inviato nel redirect top-level di ritorno da Google
        ]);
    }

    /**
     * Reindirizza l'utente a Google per l'autenticazione
     */
    public function auth() {
        if (!$this->isAdmin()) {
            Response::json(false, 'Accesso negato. Solo un amministratore può collegare Google Calendar.', null, 403);
        }
        if (!$this->clientId) {
            Response::json(false, "GOOGLE_CLIENT_ID non configurato nel file .env");
        }

        // State anti-CSRF, verificato nel callback
        $state = bin2hex(random_bytes(32));
        $this->setStateCookie($state, time() + 600);

        $params = [
            'state' => $state,
            'client_id' => $this->clientId,
            'redirect_uri' => $this->redirectUri,
            'response_type' => 'code',
            'scope' => 'https://www.googleapis.com/auth/calendar.readonly',
            'access_type' => 'offline',
            'prompt' => 'consent' // Forza a rilasciare il refresh token fise
        ];

        $authUrl = "https://accounts.google.com/o/oauth2/v2/auth?" . http_build_query($params);
        Response::json(true, 'Redirecting...', ['url' => $authUrl]);
    }

    /**
     * Callback OAuth2 da Google: salva il token nel database
     */
    public function callback() {
        // Verifica state: deve coincidere con il cookie emesso da auth()
        $state = (string)($_GET['state'] ?? '');
        $cookieState = (string)($_COOKIE[self::STATE_COOKIE] ?? '');
        $this->setStateCookie('', time() - 3600); // monouso
        if ($state === '' || $cookieState === '' || !hash_equals($cookieState, $state)) {
            http_response_code(400);
            echo "Richiesta OAuth non valida (state).";
            exit;
        }
        // Il cookie auth_token (SameSite=Lax) arriva nel redirect: deve essere un admin
        if (!$this->isAdmin()) {
            http_response_code(403);
            echo "Accesso negato. Effettua il login come amministratore e riprova.";
            exit;
        }

        $code = $_GET['code'] ?? null;
        if (!$code) {
            echo "Nessun codice ricevuto da Google.";
            exit;
        }

        // Richiedi token
        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'code' => $code,
            'grant_type' => 'authorization_code',
            'redirect_uri' => $this->redirectUri
        ]));
        $response = curl_exec($ch);
        curl_close($ch);

        $tokenData = json_decode($response, true);
        
        if (isset($tokenData['access_token'])) {
            $accessToken = $tokenData['access_token'];
            $refreshToken = $tokenData['refresh_token'] ?? '';
            $expiresIn = $tokenData['expires_in'] ?? 3600;
            $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);
            
            // Salviamo il token nel DB. Per un'app multiutente, dovremmo usare l'id utente loggato.
            // In questo caso, essendo un ERP monocontesto/amministrativo, possiamo tenere un token globale.
            $stmt = $this->pdo->query("SELECT id FROM {$this->prefix}google_tokens LIMIT 1");
            $row = $stmt->fetch();
            
            if ($row) {
                // Update
                if ($refreshToken) {
                    $sql = "UPDATE {$this->prefix}google_tokens SET access_token = ?, refresh_token = ?, expires_at = ? WHERE id = ?";
                    $this->pdo->prepare($sql)->execute([$accessToken, $refreshToken, $expiresAt, $row['id']]);
                } else {
                    $sql = "UPDATE {$this->prefix}google_tokens SET access_token = ?, expires_at = ? WHERE id = ?";
                    $this->pdo->prepare($sql)->execute([$accessToken, $expiresAt, $row['id']]);
                }
            } else {
                // Insert
                $sql = "INSERT INTO {$this->prefix}google_tokens (access_token, refresh_token, expires_at) VALUES (?, ?, ?)";
                $this->pdo->prepare($sql)->execute([$accessToken, $refreshToken, $expiresAt]);
            }

            // Tutto ok, torna all'app (view trasferte). Usa header per bypassare application/json
            header("Location: ../index.html#view-trasferte?google_sync=success");
            exit;
        } else {
            echo "Errore callback: " . json_encode($tokenData);
            exit;
        }
    }

    /**
     * Sincronizza tutti i calendari verso la tabella Trasferte
     */
    public function sync() {
        $tokenRow = $this->pdo->query("SELECT * FROM {$this->prefix}google_tokens LIMIT 1")->fetch();
        if (!$tokenRow) {
            Response::json(true, "Richiesta autorizzazione", ['auth_required' => true]);
        }

        // Controlla scadenza
        $accessToken = $tokenRow['access_token'];
        if (strtotime($tokenRow['expires_at']) < time() + 60) {
            $accessToken = $this->refreshToken($tokenRow);
            if (!$accessToken) {
                Response::json(true, "Richiesta autorizzazione", ['auth_required' => true]);
            }
        }

        if (empty($this->calendarIds)) {
            Response::json(false, "Nessun Calendar ID configurato in GOOGLE_CALENDAR_IDS nel file .env.");
        }

        // Import e ricalcolo km di molti giorni: con la cache delle coordinate bastano pochi secondi,
        // la prima volta (indirizzi da geocodificare a 1 al secondo) anche qualche minuto.
        set_time_limit(600);
        ignore_user_abort(true);

        // Periodo: anno corrente; a gennaio anche il precedente, per le trasferte di fine dicembre
        $tz = new DateTimeZone(TrasferteRegole::FUSO);
        $oggi = new DateTimeImmutable('now', $tz);
        $annoDa = (int)$oggi->format('n') === 1 ? (int)$oggi->format('Y') - 1 : (int)$oggi->format('Y');
        $da = "$annoDa-01-01";
        $a = $oggi->format('Y') . '-12-31';
        $timeMin = (new DateTimeImmutable("$da 00:00:00", $tz))->format(DATE_RFC3339);
        $timeMax = (new DateTimeImmutable("$a 23:59:59", $tz))->format(DATE_RFC3339);

        $trasferte = new TrasferteController();
        $sync = new TrasferteSync($this->pdo, $this->prefix, $trasferte->haColonnaManuale());
        $falliti = [];

        foreach ($this->calendarIds as $calId) {
            $pageToken = null;
            $letto = true;
            do {
                $params = [
                    'timeMin' => $timeMin,
                    'timeMax' => $timeMax,
                    'singleEvents' => 'true',
                    'orderBy' => 'startTime',
                    'maxResults' => 250
                ];
                if ($pageToken) $params['pageToken'] = $pageToken;
                $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calId) . "/events?" . http_build_query($params);

                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_HTTPHEADER => ["Authorization: Bearer $accessToken"],
                    CURLOPT_TIMEOUT => 20,
                    CURLOPT_CONNECTTIMEOUT => 5,
                ]);
                $response = curl_exec($ch);
                $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
                $curlErr = curl_error($ch);
                curl_close($ch);

                $data = json_decode((string)$response, true);
                if ($curlErr || $httpCode !== 200 || !is_array($data) || isset($data['error'])) {
                    error_log("Google Sync Error [$calId] HTTP $httpCode $curlErr " . json_encode($data['error'] ?? null));
                    $letto = false;
                    break; // Passa al prossimo calendario
                }

                foreach ($data['items'] ?? [] as $event) {
                    $sync->importaEvento($calId, $event);
                }
                $pageToken = $data['nextPageToken'] ?? null;
            } while ($pageToken != null);

            // Solo con il calendario letto per intero si possono togliere le trasferte sparite
            if ($letto) $sync->rimuoviAssenti($calId, $da, $a);
            else $falliti[] = $calId;
        }

        // Ricalcolo km per i giorni toccati (e i vicini: pernottamenti)
        if ($sync->dateToccate) {
            $trasferte->ricalcolaIntorno(array_keys($sync->dateToccate));
        }

        $msg = $sync->riepilogo($falliti);
        Response::json(true, $msg, [
            'imported' => $sync->importate + $sync->aggiornate,
            'rimosse' => $sync->rimosse,
            'calendari_falliti' => $falliti,
            'message' => $msg
        ]);
    }

    private function refreshToken($tokenRow) {
        if (empty($tokenRow['refresh_token'])) return null;

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_POST, true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query([
            'client_id' => $this->clientId,
            'client_secret' => $this->clientSecret,
            'refresh_token' => $tokenRow['refresh_token'],
            'grant_type' => 'refresh_token'
        ]));
        $response = curl_exec($ch);
        curl_close($ch);

        $tokenData = json_decode($response, true);
        
        if (isset($tokenData['access_token'])) {
            $accessToken = $tokenData['access_token'];
            $expiresIn = $tokenData['expires_in'] ?? 3600;
            $expiresAt = date('Y-m-d H:i:s', time() + $expiresIn);

            $sql = "UPDATE {$this->prefix}google_tokens SET access_token = ?, expires_at = ? WHERE id = ?";
            $this->pdo->prepare($sql)->execute([$accessToken, $expiresAt, $tokenRow['id']]);

            return $accessToken;
        }
        
        return null;
    }
}
