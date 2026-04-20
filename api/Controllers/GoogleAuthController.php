<?php
/**
 * Google Auth Controller - Handles OAuth2 and Calendar Sync via cURL
 */

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

    /**
     * Reindirizza l'utente a Google per l'autenticazione
     */
    public function auth() {
        if (!$this->clientId) {
            Response::json(false, "GOOGLE_CLIENT_ID non configurato nel file .env");
        }

        $params = [
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

        // Troviamo un range temporale molto più ampio (-1 anno fino a fine anno prossimo)
        $timeMin = date('Y-01-01T00:00:00\Z', strtotime('-1 year'));
        $timeMax = date('Y-12-31T23:59:59\Z', strtotime('+1 year')); 

        // Recuperiamo tutti i clienti e sottoclienti per l'auto-matching
        $stmtClienti = $this->pdo->query("SELECT id, ragione_sociale FROM {$this->prefix}clienti WHERE ragione_sociale != ''");
        $clienti = $stmtClienti->fetchAll();

        $stmtSottoclienti = $this->pdo->query("SELECT id, nome, cliente_id FROM {$this->prefix}sottoclienti WHERE nome != ''");
        $sottoclienti = $stmtSottoclienti->fetchAll();

        $countImported = 0;
        $affectedDates = [];

        foreach ($this->calendarIds as $calId) {
            $pageToken = null;
            
            do {
                $params = [
                    'timeMin' => $timeMin,
                    'timeMax' => $timeMax,
                    'singleEvents' => 'true',
                    'orderBy' => 'startTime',
                    'maxResults' => 250
                ];
                if ($pageToken) {
                    $params['pageToken'] = $pageToken;
                }

                $url = "https://www.googleapis.com/calendar/v3/calendars/" . urlencode($calId) . "/events?" . http_build_query($params);

                $ch = curl_init($url);
                curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
                curl_setopt($ch, CURLOPT_HTTPHEADER, [
                    "Authorization: Bearer $accessToken"
                ]);
                $response = curl_exec($ch);
                curl_close($ch);

                $data = json_decode($response, true);
                if (isset($data['error'])) {
                    error_log("Google Sync Error: " . json_encode($data['error']));
                    break; // Passa al prossimo calendario
                }

                if (isset($data['items'])) {
                    foreach ($data['items'] as $event) {
                        $eventId = $event['id'];
                        
                        $startStr = $event['start']['date'] ?? $event['start']['dateTime'] ?? null;
                        $endStr = $event['end']['date'] ?? $event['end']['dateTime'] ?? null;

                        if (!$startStr) continue;

                        $startTs = strtotime(substr($startStr, 0, 10));
                        $endTs = $endStr ? strtotime(substr($endStr, 0, 10)) : $startTs;

                        // Se l'evento è un "All Day", Google imposta end data come **Esclusiva** (es. fine il giorno dopo)
                        // Pertanto arretriamo la fine di 1 giorno per considerarla Inclusiva per il nostro ciclo.
                        if (isset($event['end']['date'])) {
                            $endTs = strtotime('-1 day', $endTs);
                        }

                        // Protezione estrema: l'inizio non può essere dopo la fine
                        if ($endTs < $startTs) $endTs = $startTs;

                        $summary = $event['summary'] ?? 'Evento senza titolo';
                        $location = $event['location'] ?? '';
                        $description = $event['description'] ?? '';

                        $descDb = "Titolo Originario: $summary\n";
                        if ($description) $descDb .= "Descrizione: $description";

                        // Determine fascia_oraria based on start/end time
                        $isAllDay = isset($event['start']['date']);
                        $fasciaOraria = 'intera';
                        
                        if (!$isAllDay && isset($event['start']['dateTime']) && isset($event['end']['dateTime'])) {
                            $startHour = (int)date('H', strtotime($event['start']['dateTime']));
                            $endHour = (int)date('H', strtotime($event['end']['dateTime']));
                            
                            if ($startHour < 13 && $endHour <= 14) {
                                $fasciaOraria = 'mattino';
                            } elseif ($startHour >= 13) {
                                $fasciaOraria = 'pomeriggio';
                            }
                        }

                        // Logica di auto-matching del cliente e sottocliente
                        $matchedClienteId = null;
                        $matchedSottoclienteId = null;
                        
                        // Prep search strings da event
                        $searchStrings = [];
                        if (!empty($summary)) {
                            // Filter out common tiny words and split loosely
                            $parts = preg_split('/[\s\-]+/', $summary);
                            foreach ($parts as $p) {
                                $p = trim($p);
                                if (mb_strlen($p, 'UTF-8') >= 3) $searchStrings[] = mb_strtolower($p, 'UTF-8');
                            }
                            $searchStrings[] = mb_strtolower(trim($summary), 'UTF-8');
                        }
                        if (!empty($location)) {
                            $locParts = preg_split('/[\s\-]+/', $location);
                            foreach ($locParts as $p) {
                                $p = trim($p);
                                if (mb_strlen($p, 'UTF-8') >= 3) $searchStrings[] = mb_strtolower($p, 'UTF-8');
                            }
                            $searchStrings[] = mb_strtolower(trim($location), 'UTF-8');
                        }

                        // Funzione di match sicura
                        $isMatch = function($dbName, $str, $fullDescription = '') {
                            $dbName = mb_strtolower(trim($dbName), 'UTF-8');
                            if ($dbName === $str && mb_strlen($dbName, 'UTF-8') > 0) return true;
                            
                            // Prevent short generic matches
                            $forbidden = ['spa', 'srl', 'snc', 'sas', 'per', 'con', 'del', 'dal', 'all', 'una', 'ita', 'titolo'];
                            if (in_array($dbName, $forbidden) || in_array($str, $forbidden)) return false;

                            // Verifica nelle short string
                            $escapedDb = preg_quote($dbName, '/');
                            if (mb_strlen($dbName, 'UTF-8') >= 3 && preg_match('/\b' . $escapedDb . '\b/iu', $str)) return true;
                            
                            $escapedStr = preg_quote($str, '/');
                            if (mb_strlen($str, 'UTF-8') >= 3 && preg_match('/\b' . $escapedStr . '\b/iu', $dbName)) return true;

                            // Verifica nella descrizione completa del calendario (se fornita)
                            if (mb_strlen($dbName, 'UTF-8') > 4 && !empty($fullDescription)) {
                                if (preg_match('/\b' . $escapedDb . '\b/iu', $fullDescription)) return true;
                            }
                            
                            // Extra fallback: se il dbName inizia con la str o viceversa (matches parziali senza word boundary se sono lunghe)
                            if (mb_strlen($str, 'UTF-8') >= 5 && mb_strpos($dbName, $str) !== false) return true;
                            if (mb_strlen($dbName, 'UTF-8') >= 5 && mb_strpos($str, $dbName) !== false) return true;

                            return false;
                        };

                        // Scorriamo le search string e testiamo contro sottoclienti prima (più specifici)
                        foreach ($sottoclienti as $sc) {
                            $nomeSc = $sc['nome'];
                            if ($isMatch($nomeSc, '', $description)) {
                                $matchedSottoclienteId = $sc['id'];
                                $matchedClienteId = $sc['cliente_id'];
                                break;
                            }
                            foreach ($searchStrings as $s) {
                                if ($isMatch($nomeSc, $s)) {
                                    $matchedSottoclienteId = $sc['id'];
                                    $matchedClienteId = $sc['cliente_id'];
                                    break 2;
                                }
                            }
                        }

                        // Altrimenti cerchiamo nei clienti
                        if (!$matchedClienteId) {
                            foreach ($clienti as $c) {
                                $ragSoc = $c['ragione_sociale'];
                                if ($isMatch($ragSoc, '', $description)) {
                                    $matchedClienteId = $c['id'];
                                    break;
                                }
                                foreach ($searchStrings as $s) {
                                    if ($isMatch($ragSoc, $s)) {
                                        $matchedClienteId = $c['id'];
                                        break 2;
                                    }
                                }
                            }
                        }

                        // Per ogni giorno attraversato da questo evento (da data Inizo a data Fine)
                        for ($time = $startTs; $time <= $endTs; $time += 86400) {
                            $currentDate = date('Y-m-d', $time);
                            
                            $uniqueId = $eventId . '_' . $currentDate;

                            $stmt = $this->pdo->prepare("SELECT id, cliente_id, sottocliente_id, descrizione, fascia_oraria FROM {$this->prefix}trasferte WHERE google_event_id = ? OR (google_event_id = ? AND data_trasferta = ?)");
                            $stmt->execute([$uniqueId, $eventId, $currentDate]);
                            $existingRow = $stmt->fetch();
                            if ($existingRow) {
                                $needsUpdate = false;
                                $updId = $existingRow['cliente_id'];
                                $updSotto = $existingRow['sottocliente_id'];
                                $updFascia = $existingRow['fascia_oraria'];
                                
                                if ($matchedClienteId && empty($existingRow['cliente_id'])) {
                                    $needsUpdate = true;
                                    $updId = $matchedClienteId;
                                    $updSotto = $matchedSottoclienteId;
                                } elseif ($matchedClienteId && $existingRow['cliente_id'] != $matchedClienteId && $matchedSottoclienteId && empty($existingRow['sottocliente_id'])) {
                                    $needsUpdate = true;
                                    $updId = $matchedClienteId;
                                    $updSotto = $matchedSottoclienteId;
                                }

                                if (trim((string)$existingRow['descrizione']) !== trim($descDb)) {
                                    $sqlDesc = "UPDATE {$this->prefix}trasferte SET descrizione = ? WHERE id = ?";
                                    $this->pdo->prepare($sqlDesc)->execute([trim($descDb), $existingRow['id']]);
                                }

                                if (empty($existingRow['fascia_oraria']) || $existingRow['fascia_oraria'] !== $fasciaOraria) {
                                    $needsUpdate = true;
                                    $updFascia = $fasciaOraria;
                                }

                                if ($needsUpdate) {
                                    $sqlUpdate = "UPDATE {$this->prefix}trasferte SET cliente_id = ?, sottocliente_id = ?, fascia_oraria = ? WHERE id = ?";
                                    $this->pdo->prepare($sqlUpdate)->execute([$updId, $updSotto, $updFascia, $existingRow['id']]);
                                    $affectedDates[] = $currentDate;
                                    $countImported++;
                                }
                                continue;
                            }

                            // Inserimento a DB
                            $sql = "INSERT INTO {$this->prefix}trasferte (
                                data_trasferta, luogo_arrivo, descrizione, google_event_id, google_calendar_id, cliente_id, sottocliente_id, fascia_oraria
                            ) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
                            
                            $this->pdo->prepare($sql)->execute([
                                $currentDate,
                                $location,
                                trim($descDb),
                                $uniqueId,
                                $calId,
                                $matchedClienteId,
                                $matchedSottoclienteId,
                                $fasciaOraria
                            ]);
                            
                            $countImported++;
                            $affectedDates[] = $currentDate;
                        }
                    }
                }
                
                // Paginazione Google
                $pageToken = $data['nextPageToken'] ?? null;
            } while ($pageToken != null);
        }

        // Auto-calcula rotta per tutti i giorni modificati
        if (!empty($affectedDates)) {
            require_once __DIR__ . '/TrasferteController.php';
            $tc = new TrasferteController();
            foreach (array_unique($affectedDates) as $d) {
                try {
                    $tc->calcolaKmPerData($d);
                } catch (\Exception $e) {}
            }
        }

        Response::json(true, "Sincronizzazione completata", ['imported' => $countImported]);
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
