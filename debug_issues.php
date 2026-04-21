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

    // === PARTE 1: Sottoclienti con dati mancanti ===
    echo "=== SOTTOCLIENTI CON DATI MANCANTI ===\n\n";
    $stmt = $pdo->query("SELECT id, nome, cliente_id, partita_iva, codice_fiscale, indirizzo, citta, cap, provincia, pec, sdi, email FROM {$prefix}sottoclienti ORDER BY id");
    $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    $missing = 0;
    foreach ($rows as $r) {
        $lacks = [];
        if (empty(trim($r['partita_iva'] ?? ''))) $lacks[] = 'P.IVA';
        if (empty(trim($r['codice_fiscale'] ?? ''))) $lacks[] = 'CF';
        if (empty(trim($r['indirizzo'] ?? ''))) $lacks[] = 'Indirizzo';
        if (empty(trim($r['citta'] ?? ''))) $lacks[] = 'Città';
        if (empty(trim($r['cap'] ?? ''))) $lacks[] = 'CAP';
        if (empty(trim($r['provincia'] ?? ''))) $lacks[] = 'Prov';
        if (empty(trim($r['pec'] ?? ''))) $lacks[] = 'PEC';
        if (empty(trim($r['sdi'] ?? ''))) $lacks[] = 'SDI';
        if (empty(trim($r['email'] ?? ''))) $lacks[] = 'Email';
        
        if (count($lacks) > 0) {
            $missing++;
            echo "ID {$r['id']} | {$r['nome']}\n";
            echo "  Mancano: " . implode(', ', $lacks) . "\n";
            echo "  Ha: P.IVA=" . ($r['partita_iva'] ?: '-') . " | CF=" . ($r['codice_fiscale'] ?: '-') . "\n\n";
        }
    }
    echo "Sottoclienti con dati mancanti: $missing / " . count($rows) . "\n\n";

    // === PARTE 2: Trasferte del 21 aprile ===
    echo "\n=== TRASFERTE 21 APRILE 2026 ===\n\n";
    $stmt2 = $pdo->query("SELECT t.id, t.data_trasferta, t.fascia_oraria, t.descrizione, 
        t.cliente_id, t.sottocliente_id, t.google_event_id,
        c.ragione_sociale, sc.nome as sc_nome
        FROM {$prefix}trasferte t
        LEFT JOIN {$prefix}clienti c ON t.cliente_id = c.id
        LEFT JOIN {$prefix}sottoclienti sc ON t.sottocliente_id = sc.id
        WHERE t.data_trasferta = '2026-04-21'
        ORDER BY t.fascia_oraria, t.id");
    $trasferte = $stmt2->fetchAll(PDO::FETCH_ASSOC);
    
    echo "Trovate: " . count($trasferte) . " trasferte\n\n";
    foreach ($trasferte as $t) {
        $summary = '';
        if (preg_match('/Titolo Originario:\s*(.*?)(?:\n|$)/i', $t['descrizione'], $m)) {
            $summary = trim($m[1]);
        } else {
            $summary = substr(trim(strip_tags(str_replace("\n", " ", $t['descrizione']))), 0, 60);
        }
        echo "ID {$t['id']} | Fascia: {$t['fascia_oraria']} | Cliente: {$t['ragione_sociale']} | SC: {$t['sc_nome']}\n";
        echo "  Summary: \"$summary\"\n";
        echo "  Google Event ID: " . substr($t['google_event_id'] ?? 'N/A', 0, 40) . "\n\n";
    }

    // === PARTE 3: Tutti gli eventi del 21 aprile come li vede il DB ===
    echo "\n=== TUTTE LE TRASFERTE NELLA SETTIMANA 21 APRILE ===\n";
    $stmt3 = $pdo->query("SELECT t.id, t.data_trasferta, t.fascia_oraria, t.cliente_id, t.sottocliente_id,
        sc.nome as sc_nome
        FROM {$prefix}trasferte t
        LEFT JOIN {$prefix}sottoclienti sc ON t.sottocliente_id = sc.id
        WHERE t.data_trasferta BETWEEN '2026-04-20' AND '2026-04-27'
        ORDER BY t.data_trasferta, t.fascia_oraria");
    $week = $stmt3->fetchAll(PDO::FETCH_ASSOC);
    foreach ($week as $w) {
        echo "{$w['data_trasferta']} | {$w['fascia_oraria']} | SC: {$w['sc_nome']} (CL:{$w['cliente_id']}, SC:{$w['sottocliente_id']})\n";
    }

} catch (Exception $e) {
    echo "ERROR: " . $e->getMessage() . "\n";
}
