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

        // Modificato come richiesto per importare tutti i dati dal primo gennaio 2026
        $timeMin = '2026-01-01T00:00:00Z';
        $timeMax = '2026-12-31T23:59:59Z'; // Tutti i 12 mesi del 2026

        // Recuperiamo tutti i clienti e sottoclienti per l'auto-matching
        $stmtClienti = $this->pdo->query("SELECT id, ragione_sociale FROM {$this->prefix}clienti WHERE ragione_sociale != ''");
        $clienti = $stmtClienti->fetchAll();

        $stmtSottoclienti = $this->pdo->query("SELECT id, nome, cliente_id FROM {$this->prefix}sottoclienti WHERE nome != ''");
        $sottoclienti = $stmtSottoclienti->fetchAll();

        // Alias espliciti: nomi abbreviati nel calendario → sottocliente_id
        // Questi gestiscono casi impossibili da matchare algoritmicamente (acronimi ambigui, umlaut, ecc.)
        $aliasMap = [];
        foreach ($sottoclienti as $sc) {
            $nLow = mb_strtolower(trim($sc['nome']), 'UTF-8');
            // CDM -> Centro di Medicina
            if (mb_strpos($nLow, 'centro di medicina') !== false) $aliasMap['cdm'] = $sc;
            // LUVE -> Gruppo Lu-Ve
            if (mb_strpos($nLow, 'lu-ve') !== false || mb_strpos($nLow, 'luve') !== false || mb_strpos($nLow, 'lu ve') !== false) $aliasMap['luve'] = $sc;
            // ITA -> Itagency
            if (mb_strpos($nLow, 'itagency') !== false) $aliasMap['ita'] = $sc;
        }

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

                        // Skip eventi inutili per il matching
                        $summaryLower = mb_strtolower(trim($summary), 'UTF-8');
                        $skipPatterns = ['non disponibile', 'evento senza titolo', 'annullato', 'cancelled'];
                        $skipEvent = false;
                        foreach ($skipPatterns as $sp) {
                            if ($summaryLower === $sp || mb_strpos($summaryLower, $sp) !== false) {
                                $skipEvent = true;
                                break;
                            }
                        }

                        if (!$skipEvent) {

                        // === ALIAS MAP: controlla prima le mappature esplicite ===
                        $summaryTokens = preg_split('/[\s\-]+/', $summaryLower);
                        foreach ($summaryTokens as $token) {
                            $token = trim(str_replace(['.', ','], '', $token));
                            if (isset($aliasMap[$token])) {
                                $matchedSottoclienteId = $aliasMap[$token]['id'];
                                $matchedClienteId = $aliasMap[$token]['cliente_id'];
                                break;
                            }
                        }
                        // Se tutta la stringa è un alias
                        if (!$matchedClienteId) {
                            $cleanSummary = trim(str_replace(['.', ',', '-'], '', $summaryLower));
                            $cleanSummary = preg_replace('/\s+/', '', $cleanSummary);
                            if (isset($aliasMap[$cleanSummary])) {
                                $matchedSottoclienteId = $aliasMap[$cleanSummary]['id'];
                                $matchedClienteId = $aliasMap[$cleanSummary]['cliente_id'];
                            }
                        }

                        // Se non trovato via alias, procediamo col matching algoritmico
                        if (!$matchedClienteId) {
                        
                        // Prep search strings da event
                        $searchStrings = [];
                        if (!empty($summary)) {
                            $parts = preg_split('/[\s\-]+/', $summary);
                            foreach ($parts as $p) {
                                $p = trim($p);
                                if (mb_strlen($p, 'UTF-8') >= 2) {
                                    $searchStrings[] = mb_strtolower($p, 'UTF-8');
                                    $pClean = str_replace(['.', ',', ';', ':'], '', $p);
                                    if ($p !== $pClean && mb_strlen($pClean, 'UTF-8') >= 2) {
                                        $searchStrings[] = mb_strtolower($pClean, 'UTF-8');
                                    }
                                }
                            }
                            $searchStrings[] = mb_strtolower(trim($summary), 'UTF-8');
                        }
                        if (!empty($location)) {
                            $locParts = preg_split('/[\s\-]+/', $location);
                            foreach ($locParts as $p) {
                                $p = trim($p);
                                if (mb_strlen($p, 'UTF-8') >= 2) {
                                    $searchStrings[] = mb_strtolower($p, 'UTF-8');
                                    $pClean = str_replace(['.', ',', ';', ':'], '', $p);
                                    if ($p !== $pClean && mb_strlen($pClean, 'UTF-8') >= 2) {
                                        $searchStrings[] = mb_strtolower($pClean, 'UTF-8');
                                    }
                                }
                            }
                            $searchStrings[] = mb_strtolower(trim($location), 'UTF-8');
                        }

                        // Pre-calcola acronimi e nomi puliti per tutti i sottoclienti e clienti
                        $forbidden = ['spa', 'srl', 'snc', 'sas', 'per', 'con', 'del', 'dal', 'all', 'una',
                                      'ita', 'titolo', 'non', 'disponibile', 'the', 'group', 'gruppo',
                                      'formazione', 'originario', 'descrizione', 'dpo', 'mkt', 'commerciale',
                                      'societa', 'società', 'responsabilita', 'limitata', 'consortile', 'il',
                                      'lo', 'la', 'di', 'de', 'da', 'in', 'su', 'tra', 'fra', 'ed', 'zaggia'];
                        $stopWords = ['di', 'de', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra', 'il', 'lo',
                                      'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'ed', 'e', 'o', 'a', '&', 'and'];

                        // Funzione di match migliorata
                        $isMatch = function($dbName, $str, $fullDescription = '') use ($forbidden, $stopWords) {
                            $dbName = mb_strtolower(trim($dbName), 'UTF-8');
                            
                            // Rimuovi suffissi tipo società
                            $dbName = preg_replace('/\b(s\.r\.l\.|s\.p\.a\.|srl|spa|snc|sas|s\.r\.l|s\.p\.a)\b/iu', '', $dbName);
                            // Rimuovi contenuti tra parentesi (es. numeri P.IVA)
                            $dbName = preg_replace('/\([^)]*\)/', '', $dbName);
                            $dbName = trim($dbName);

                            // Se il dbName è vuoto o troppo generico, skip
                            if (mb_strlen($dbName, 'UTF-8') < 2) return false;

                            // Exact match
                            if ($dbName === $str && mb_strlen($dbName, 'UTF-8') > 0) return true;
                            
                            // Prevent short/generic matches
                            if (in_array($dbName, $forbidden) || in_array($str, $forbidden)) return false;
                            // Skip se la stringa è troppo corta per word boundary (meno di 3 char)
                            if (mb_strlen($str, 'UTF-8') < 3 && mb_strlen($dbName, 'UTF-8') < 3) return false;

                            // === ACRONYM MATCHING ===
                            $words = preg_split('/[\s\-]+/', $dbName);
                            $words = array_filter($words, function($w) { return mb_strlen($w, 'UTF-8') > 0; });
                            $words = array_values($words);
                            
                            $acronymAll = '';
                            $acronymNoStop = '';
                            foreach ($words as $w) {
                                $acronymAll .= mb_substr($w, 0, 1, 'UTF-8');
                                if (!in_array($w, $stopWords)) {
                                    $acronymNoStop .= mb_substr($w, 0, 1, 'UTF-8');
                                }
                            }
                            
                            if (mb_strlen($str, 'UTF-8') >= 2 && mb_strlen($str, 'UTF-8') <= 5) {
                                if ($str === $acronymAll || $str === $acronymNoStop) return true;
                            }

                            // === WORD BOUNDARY MATCHING (soglia minima 3 caratteri) ===
                            if (mb_strlen($dbName, 'UTF-8') >= 3 && mb_strlen($str, 'UTF-8') >= 3) {
                                $escapedDb = preg_quote($dbName, '/');
                                if (preg_match('/\b' . $escapedDb . '\b/iu', $str)) return true;
                                
                                $escapedStr = preg_quote($str, '/');
                                if (preg_match('/\b' . $escapedStr . '\b/iu', $dbName)) return true;
                            }

                            // === FUZZY MATCHING (Levenshtein per gestire typo) ===
                            if (mb_strlen($str, 'UTF-8') >= 5) {
                                // Confronta la stringa di ricerca con ogni parola del nome DB
                                foreach ($words as $w) {
                                    if (mb_strlen($w, 'UTF-8') >= 4) {
                                        $distance = levenshtein($str, $w);
                                        $maxLen = max(mb_strlen($str, 'UTF-8'), mb_strlen($w, 'UTF-8'));
                                        // Tollera 1 errore per parole 5-8 char, 2 errori per parole 9+ char
                                        $threshold = ($maxLen >= 9) ? 2 : 1;
                                        if ($distance <= $threshold && $distance > 0) return true;
                                    }
                                }
                                // Confronta anche con il nome DB intero (senza tipo società)
                                $dbNameNoSpaces = str_replace(' ', '', $dbName);
                                if (mb_strlen($dbNameNoSpaces, 'UTF-8') >= 5) {
                                    $distance = levenshtein($str, $dbNameNoSpaces);
                                    $maxLen = max(mb_strlen($str, 'UTF-8'), mb_strlen($dbNameNoSpaces, 'UTF-8'));
                                    $threshold = ($maxLen >= 9) ? 2 : 1;
                                    if ($distance <= $threshold && $distance > 0) return true;
                                }
                            }

                            // === DESCRIZIONE COMPLETA DEL CALENDARIO ===
                            if (mb_strlen($dbName, 'UTF-8') > 4 && !empty($fullDescription)) {
                                $escapedDb = preg_quote($dbName, '/');
                                if (preg_match('/\b' . $escapedDb . '\b/iu', $fullDescription)) return true;
                            }
                            
                            // === PARTIAL MATCH per stringhe lunghe ===
                            if (mb_strlen($str, 'UTF-8') >= 5 && mb_strpos($dbName, $str) !== false) return true;
                            if (mb_strlen($dbName, 'UTF-8') >= 5 && mb_strpos($str, $dbName) !== false) return true;

                            return false;
                        };

                        // Scorriamo le search string e testiamo contro sottoclienti prima (più specifici)
                        foreach ($sottoclienti as $sc) {
                            $nomeSc = $sc['nome'];
                            // Skip sottoclienti con nomi troppo generici
                            $scClean = preg_replace('/\([^)]*\)/', '', $nomeSc);
                            $scClean = trim($scClean);
                            if (mb_strlen($scClean, 'UTF-8') < 3) continue;
                            if (mb_strtolower($scClean, 'UTF-8') === 'azienda non trovata') continue;

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

                        } // fine if (!$matchedClienteId) — matching algoritmico

                        } // fine if (!$skipEvent)

                        if (!isset($debugUnmatched)) $debugUnmatched = [];
                        if (!$matchedClienteId && count($debugUnmatched) < 8) {
                            $debugUnmatched[] = $summary . " [" . implode(',', $searchStrings) . "]";
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

        $debugMsg = !empty($debugUnmatched) ? "Debug: " . implode(" | ", $debugUnmatched) : "";
        Response::json(true, "Sincronizzazione completata $debugMsg", [
            'imported' => $countImported,
            'message' => "Sincronizzazione completata. Aggiornati $countImported. $debugMsg"
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
