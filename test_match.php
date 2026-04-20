<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/api/Shared/Database.php';

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value, " \t\n\r\0\x0B\"'"));
        $_ENV[trim($name)] = trim($value, " \t\n\r\0\x0B\"'");
    }
}

try {
    $pdo = Database::getConnection();
    $prefix = getenv('DB_PREFIX') ?: 'mv_';

    $stmtSottoclienti = $pdo->query("SELECT id, nome, cliente_id FROM {$prefix}sottoclienti WHERE nome != ''");
    $sottoclienti = $stmtSottoclienti->fetchAll(PDO::FETCH_ASSOC);

    $stmtClienti = $pdo->query("SELECT id, ragione_sociale FROM {$prefix}clienti WHERE ragione_sociale != ''");
    $clienti = $stmtClienti->fetchAll(PDO::FETCH_ASSOC);

    // Get transfers lacking a client mapping
    $stmt = $pdo->query("SELECT id, data_trasferta, descrizione, cliente_id, sottocliente_id FROM {$prefix}trasferte WHERE cliente_id IS NULL OR cliente_id = 0");
    $trasferte = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $affected = 0;

    foreach($trasferte as $t) {
        $desc = $t['descrizione'];
        $summary = '';
        if (preg_match('/Titolo Originario:\s*(.*?)(?:\n|$)/i', $desc, $matches)) {
            $summary = trim($matches[1]);
        } else {
            // fallback generic words
            $summary = strip_tags(str_replace('\n', ' ', $desc));
        }

        $searchStrings = [];
        if (!empty($summary)) {
            $parts = preg_split('/[\s\-]+/', $summary);
            foreach ($parts as $p) {
                $p = trim($p);
                if (strlen($p) >= 3) $searchStrings[] = strtolower($p);
            }
            $searchStrings[] = strtolower(trim($summary));
        }

        if (empty($searchStrings)) continue;

        echo "TESTING TRASFERTA ID {$t['id']} (Data: {$t['data_trasferta']}) - Estratto: '$summary'\n";

        $matchedClienteId = null;
        $matchedSottoclienteId = null;

        // Funzione di match sicura
        $isMatch = function($dbName, $str) {
            $dbName = strtolower(trim($dbName));
            if ($dbName === $str && strlen($dbName) > 0) return true;
            $forbidden = ['spa', 'srl', 'snc', 'sas', 'per', 'con', 'del', 'dal', 'all', 'una', 'ita', 'titolo', 'originario'];
            if (in_array($dbName, $forbidden) || in_array($str, $forbidden)) return false;
            $escapedDb = preg_quote($dbName, '/');
            if (strlen($dbName) >= 3 && preg_match('/\b' . $escapedDb . '\b/i', $str)) return true;
            $escapedStr = preg_quote($str, '/');
            if (strlen($str) >= 3 && preg_match('/\b' . $escapedStr . '\b/i', $dbName)) return true;
            return false;
        };

        foreach ($sottoclienti as $sc) {
            foreach ($searchStrings as $s) {
                if ($isMatch($sc['nome'], $s)) {
                    $matchedSottoclienteId = $sc['id'];
                    $matchedClienteId = $sc['cliente_id'];
                    echo "  -> MATCH TROVATO in sottocliente: {$sc['nome']} (ID {$matchedSottoclienteId})\n";
                    break 2;
                }
            }
        }

        if (!$matchedClienteId) {
            foreach ($clienti as $c) {
                foreach ($searchStrings as $s) {
                    if ($isMatch($c['ragione_sociale'], $s)) {
                        $matchedClienteId = $c['id'];
                        echo "  -> MATCH TROVATO in cliente generico: {$c['ragione_sociale']} (ID {$matchedClienteId})\n";
                        break 2;
                    }
                }
            }
        }
        
        if ($matchedClienteId) {
            $sqlUpdate = "UPDATE {$prefix}trasferte SET cliente_id = ?, sottocliente_id = ? WHERE id = ?";
            $pdo->prepare($sqlUpdate)->execute([$matchedClienteId, $matchedSottoclienteId, $t['id']]);
            $affected++;
        } else {
            echo "  -> NESSUN MATCH TROVATO\n";
        }
    }

    echo "\nFATTO! Totale trasferte aggiornate: $affected\n";

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
