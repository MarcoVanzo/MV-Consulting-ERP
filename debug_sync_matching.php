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

    // 1. Trasferte senza cliente assegnato
    $stmt = $pdo->query("SELECT id, data_trasferta, descrizione, cliente_id, sottocliente_id, fascia_oraria 
        FROM {$prefix}trasferte 
        WHERE (cliente_id IS NULL OR cliente_id = 0) 
        ORDER BY data_trasferta DESC LIMIT 30");
    $unmatched = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    echo "=== TRASFERTE SENZA CLIENTE (ultime 30) ===\n";
    echo "Totale trovate: " . count($unmatched) . "\n\n";
    
    // 2. Load all sottoclienti
    $stmtSotto = $pdo->query("SELECT id, nome, cliente_id FROM {$prefix}sottoclienti WHERE nome != ''");
    $sottoclienti = $stmtSotto->fetchAll(PDO::FETCH_ASSOC);
    
    echo "=== SOTTOCLIENTI DISPONIBILI: " . count($sottoclienti) . " ===\n\n";
    
    foreach ($unmatched as $t) {
        $desc = $t['descrizione'];
        $summary = '';
        if (preg_match('/Titolo Originario:\s*(.*?)(?:\n|$)/i', $desc, $matches)) {
            $summary = trim($matches[1]);
        } else {
            $summary = trim(strip_tags(str_replace("\n", " ", $desc)));
        }
        
        echo "--- TRASFERTA ID {$t['id']} | Data: {$t['data_trasferta']} ---\n";
        echo "  Summary: \"$summary\"\n";
        
        // Generate search strings exactly like the sync does
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
        
        echo "  Search tokens: [" . implode(', ', $searchStrings) . "]\n";
        
        // Try matching against each sottocliente
        $found = false;
        foreach ($sottoclienti as $sc) {
            $dbName = mb_strtolower(trim($sc['nome']), 'UTF-8');
            $dbNameClean = preg_replace('/\b(s\.r\.l\.|s\.p\.a\.|srl|spa|snc|sas)\b/iu', '', $dbName);
            $dbNameClean = trim($dbNameClean);
            
            foreach ($searchStrings as $s) {
                // Direct match
                if ($dbNameClean === $s && mb_strlen($dbNameClean, 'UTF-8') > 0) {
                    echo "  ✅ MATCH (exact): sottocliente \"{$sc['nome']}\" (ID {$sc['id']}) matched token \"$s\"\n";
                    $found = true;
                    break 2;
                }
                
                // Word boundary match
                $escapedDb = preg_quote($dbNameClean, '/');
                if (mb_strlen($dbNameClean, 'UTF-8') >= 2 && preg_match('/\b' . $escapedDb . '\b/iu', $s)) {
                    echo "  ✅ MATCH (boundary db→str): sottocliente \"{$sc['nome']}\" (ID {$sc['id']}) matched token \"$s\"\n";
                    $found = true;
                    break 2;
                }
                
                $escapedStr = preg_quote($s, '/');
                if (mb_strlen($s, 'UTF-8') >= 2 && preg_match('/\b' . $escapedStr . '\b/iu', $dbNameClean)) {
                    echo "  ✅ MATCH (boundary str→db): sottocliente \"{$sc['nome']}\" (ID {$sc['id']}) matched token \"$s\"\n";
                    $found = true;
                    break 2;
                }
                
                // Acronym match
                $words = preg_split('/[\s\-]+/', $dbNameClean);
                $stopWords = ['di', 'de', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra', 'il', 'lo', 'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'ed', 'e', 'o', 'a', '&', 'and'];
                $acronymAll = '';
                $acronymNoStop = '';
                foreach ($words as $w) {
                    if (mb_strlen($w, 'UTF-8') > 0) {
                        $acronymAll .= mb_substr($w, 0, 1, 'UTF-8');
                        if (!in_array($w, $stopWords)) {
                            $acronymNoStop .= mb_substr($w, 0, 1, 'UTF-8');
                        }
                    }
                }
                
                if (mb_strlen($s, 'UTF-8') >= 2 && ($s === $acronymAll || $s === $acronymNoStop)) {
                    echo "  ✅ MATCH (acronym): sottocliente \"{$sc['nome']}\" (ID {$sc['id']}) | acronym=\"$acronymAll\" / noStop=\"$acronymNoStop\" matched token \"$s\"\n";
                    $found = true;
                    break 2;
                }
                
                // Partial match for long strings
                if (mb_strlen($s, 'UTF-8') >= 5 && mb_strpos($dbNameClean, $s) !== false) {
                    echo "  ✅ MATCH (partial s→db): sottocliente \"{$sc['nome']}\" (ID {$sc['id']}) | \"$s\" found in \"$dbNameClean\"\n";
                    $found = true;
                    break 2;
                }
                if (mb_strlen($dbNameClean, 'UTF-8') >= 5 && mb_strpos($s, $dbNameClean) !== false) {
                    echo "  ✅ MATCH (partial db→s): sottocliente \"{$sc['nome']}\" (ID {$sc['id']}) | \"$dbNameClean\" found in \"$s\"\n";
                    $found = true;
                    break 2;
                }
            }
        }
        
        if (!$found) {
            echo "  ❌ NESSUN MATCH\n";
        }
        echo "\n";
    }

    // 3. Totale trasferte senza cliente
    $stmtTotal = $pdo->query("SELECT COUNT(*) as cnt FROM {$prefix}trasferte WHERE (cliente_id IS NULL OR cliente_id = 0)");
    $total = $stmtTotal->fetch(PDO::FETCH_ASSOC);
    echo "\n=== TOTALE TRASFERTE SENZA CLIENTE: {$total['cnt']} ===\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
