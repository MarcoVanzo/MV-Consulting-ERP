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

    // Load sottoclienti
    $stmtSotto = $pdo->query("SELECT id, nome, cliente_id FROM {$prefix}sottoclienti WHERE nome != ''");
    $sottoclienti = $stmtSotto->fetchAll(PDO::FETCH_ASSOC);
    
    // Load clienti
    $stmtClienti = $pdo->query("SELECT id, ragione_sociale FROM {$prefix}clienti WHERE ragione_sociale != ''");
    $clienti = $stmtClienti->fetchAll(PDO::FETCH_ASSOC);

    echo "=== SOTTOCLIENTI: " . count($sottoclienti) . " | CLIENTI: " . count($clienti) . " ===\n\n";

    // ===== STESSA LOGICA DEL CONTROLLER FIX =====
    $forbidden = ['spa', 'srl', 'snc', 'sas', 'per', 'con', 'del', 'dal', 'all', 'una',
                  'ita', 'titolo', 'non', 'disponibile', 'the', 'group', 'gruppo',
                  'formazione', 'originario', 'descrizione', 'dpo', 'mkt', 'commerciale',
                  'societa', 'società', 'responsabilita', 'limitata', 'consortile', 'il',
                  'lo', 'la', 'di', 'de', 'da', 'in', 'su', 'tra', 'fra', 'ed', 'zaggia'];
    $stopWords = ['di', 'de', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra', 'il', 'lo',
                  'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'ed', 'e', 'o', 'a', '&', 'and'];

    $isMatch = function($dbName, $str, $fullDescription = '') use ($forbidden, $stopWords) {
        $dbName = mb_strtolower(trim($dbName), 'UTF-8');
        $dbName = preg_replace('/\b(s\.r\.l\.|s\.p\.a\.|srl|spa|snc|sas|s\.r\.l|s\.p\.a)\b/iu', '', $dbName);
        $dbName = preg_replace('/\([^)]*\)/', '', $dbName);
        $dbName = trim($dbName);
        if (mb_strlen($dbName, 'UTF-8') < 2) return false;
        if ($dbName === $str && mb_strlen($dbName, 'UTF-8') > 0) return 'exact';
        if (in_array($dbName, $forbidden) || in_array($str, $forbidden)) return false;
        if (mb_strlen($str, 'UTF-8') < 3 && mb_strlen($dbName, 'UTF-8') < 3) return false;

        // Acronym
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
            if ($str === $acronymAll || $str === $acronymNoStop) return "acronym($acronymAll/$acronymNoStop)";
        }

        // Word boundary (min 3 chars)
        if (mb_strlen($dbName, 'UTF-8') >= 3 && mb_strlen($str, 'UTF-8') >= 3) {
            $escapedDb = preg_quote($dbName, '/');
            if (preg_match('/\b' . $escapedDb . '\b/iu', $str)) return 'boundary-db→str';
            $escapedStr = preg_quote($str, '/');
            if (preg_match('/\b' . $escapedStr . '\b/iu', $dbName)) return 'boundary-str→db';
        }

        // Fuzzy (Levenshtein)
        if (mb_strlen($str, 'UTF-8') >= 5) {
            foreach ($words as $w) {
                if (mb_strlen($w, 'UTF-8') >= 4) {
                    $distance = levenshtein($str, $w);
                    $maxLen = max(mb_strlen($str, 'UTF-8'), mb_strlen($w, 'UTF-8'));
                    $threshold = ($maxLen >= 9) ? 2 : 1;
                    if ($distance <= $threshold && $distance > 0) return "fuzzy(dist=$distance,$str↔$w)";
                }
            }
            $dbNameNoSpaces = str_replace(' ', '', $dbName);
            if (mb_strlen($dbNameNoSpaces, 'UTF-8') >= 5) {
                $distance = levenshtein($str, $dbNameNoSpaces);
                $maxLen = max(mb_strlen($str, 'UTF-8'), mb_strlen($dbNameNoSpaces, 'UTF-8'));
                $threshold = ($maxLen >= 9) ? 2 : 1;
                if ($distance <= $threshold && $distance > 0) return "fuzzy-full(dist=$distance,$str↔$dbNameNoSpaces)";
            }
        }

        // Description
        if (mb_strlen($dbName, 'UTF-8') > 4 && !empty($fullDescription)) {
            $escapedDb = preg_quote($dbName, '/');
            if (preg_match('/\b' . $escapedDb . '\b/iu', $fullDescription)) return 'description';
        }

        // Partial
        if (mb_strlen($str, 'UTF-8') >= 5 && mb_strpos($dbName, $str) !== false) return 'partial-s→db';
        if (mb_strlen($dbName, 'UTF-8') >= 5 && mb_strpos($str, $dbName) !== false) return 'partial-db→s';

        return false;
    };

    $skipPatterns = ['non disponibile', 'evento senza titolo', 'annullato', 'cancelled'];

    // Test ALL unmatched trasferte
    $stmt = $pdo->query("SELECT id, data_trasferta, descrizione, cliente_id, sottocliente_id 
        FROM {$prefix}trasferte 
        WHERE (cliente_id IS NULL OR cliente_id = 0) 
        ORDER BY data_trasferta DESC");
    $unmatched = $stmt->fetchAll(PDO::FETCH_ASSOC);

    echo "=== TRASFERTE SENZA CLIENTE: " . count($unmatched) . " ===\n\n";

    $wouldMatch = 0;
    $wouldSkip = 0;
    $stillUnmatched = 0;

    foreach ($unmatched as $t) {
        $desc = $t['descrizione'];
        $summary = '';
        if (preg_match('/Titolo Originario:\s*(.*?)(?:\n|$)/i', $desc, $matches)) {
            $summary = trim($matches[1]);
        } else {
            $summary = trim(strip_tags(str_replace("\n", " ", $desc)));
        }

        // Skip check
        $summaryLower = mb_strtolower(trim($summary), 'UTF-8');
        $skip = false;
        foreach ($skipPatterns as $sp) {
            if ($summaryLower === $sp || mb_strpos($summaryLower, $sp) !== false) {
                $skip = true;
                break;
            }
        }

        echo "ID {$t['id']} | {$t['data_trasferta']} | \"$summary\"";

        if ($skip) {
            echo " → ⏭️ SKIP\n";
            $wouldSkip++;
            continue;
        }

        // Generate search strings
        $searchStrings = [];
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

        // Test sottoclienti
        $found = false;
        foreach ($sottoclienti as $sc) {
            $scClean = preg_replace('/\([^)]*\)/', '', $sc['nome']);
            $scClean = trim($scClean);
            if (mb_strlen($scClean, 'UTF-8') < 3) continue;
            if (mb_strtolower($scClean, 'UTF-8') === 'azienda non trovata') continue;

            foreach ($searchStrings as $s) {
                $result = $isMatch($sc['nome'], $s);
                if ($result) {
                    echo " → ✅ SC \"{$sc['nome']}\" (ID {$sc['id']}) via $result [token: $s]\n";
                    $found = true;
                    $wouldMatch++;
                    break 2;
                }
            }
        }

        if (!$found) {
            // Test clienti
            foreach ($clienti as $c) {
                foreach ($searchStrings as $s) {
                    $result = $isMatch($c['ragione_sociale'], $s);
                    if ($result) {
                        echo " → ✅ CL \"{$c['ragione_sociale']}\" (ID {$c['id']}) via $result [token: $s]\n";
                        $found = true;
                        $wouldMatch++;
                        break 2;
                    }
                }
            }
        }

        if (!$found) {
            echo " → ❌ NO MATCH\n";
            $stillUnmatched++;
        }
    }

    echo "\n========================================\n";
    echo "RIEPILOGO:\n";
    echo "  Totale senza cliente: " . count($unmatched) . "\n";
    echo "  Verrebbero matchati:  $wouldMatch\n";
    echo "  Verrebbero skippati:  $wouldSkip\n";
    echo "  Ancora senza match:   $stillUnmatched\n";
    echo "========================================\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
