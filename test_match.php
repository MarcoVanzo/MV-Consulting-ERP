<?php
header('Content-Type: text/plain; charset=utf-8');
require_once __DIR__ . '/api/Shared/Database.php';

$envPath = __DIR__ . '/.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
        $_ENV[trim($name)] = trim($value);
    }
}

try {
    $pdo = Database::getConnection();
    $prefix = getenv('DB_PREFIX') ?: 'mv_';

    $stmtSottoclienti = $pdo->query("SELECT id, nome, cliente_id FROM {$prefix}sottoclienti WHERE nome != ''");
    $sottoclienti = $stmtSottoclienti->fetchAll(PDO::FETCH_ASSOC);

    $stmtClienti = $pdo->query("SELECT id, ragione_sociale FROM {$prefix}clienti WHERE ragione_sociale != ''");
    $clienti = $stmtClienti->fetchAll(PDO::FETCH_ASSOC);

    $stmt = $pdo->query("SELECT id, data_trasferta, descrizione, cliente_id, sottocliente_id FROM {$prefix}trasferte WHERE descrizione LIKE '%Titolo Originario: ITA%' AND data_trasferta >= '2026-04-16'");
    $trasferte = $stmt->fetchAll(PDO::FETCH_ASSOC);

    foreach($trasferte as $t) {
        $desc = $t['descrizione'];
        $summary = "ITA";
        $location = "";
        
        $searchStrings = [];
        if (!empty($summary)) {
            $parts = preg_split('/[\s\-]+/', $summary);
            foreach ($parts as $p) {
                $p = trim($p);
                if (strlen($p) >= 3) $searchStrings[] = strtolower($p);
            }
            $searchStrings[] = strtolower(trim($summary));
        }

        echo "TESTING TRASFERTA {$t['id']} con search strings: ";
        print_r($searchStrings);

        $matchedClienteId = null;
                        $matchedSottoclienteId = null;
        foreach ($sottoclienti as $sc) {
            $nomeSc = strtolower(trim($sc['nome']));
            foreach ($searchStrings as $s) {
                if (strpos($nomeSc, $s) !== false || strpos($s, $nomeSc) !== false) {
                    $matchedSottoclienteId = $sc['id'];
                    $matchedClienteId = $sc['cliente_id'];
                    echo "\nMATCH TROVATO in sottocliente: {$nomeSc} con id {$matchedSottoclienteId}\n";
                    break 2;
                }
            }
        }
        
        echo "Updating DB for ID {$t['id']}...\n";
        $sqlUpdate = "UPDATE {$prefix}trasferte SET cliente_id = ?, sottocliente_id = ? WHERE id = ?";
        $pdo->prepare($sqlUpdate)->execute([$matchedClienteId, $matchedSottoclienteId, $t['id']]);
        echo "Update result: success\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
