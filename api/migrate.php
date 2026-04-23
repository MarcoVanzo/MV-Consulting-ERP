<?php
/**
 * MV Consulting ERP — Database Migration
 * Creates tables: mv_clienti, mv_sottoclienti, mv_trasferte, mv_fatture
 */

require_once __DIR__ . '/Shared/Database.php';

// Load .env
$envPath = __DIR__ . '/../.env';
if (file_exists($envPath)) {
    $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
    foreach ($lines as $line) {
        if (strpos(trim($line), '#') === 0) continue;
        list($name, $value) = explode('=', $line, 2);
        putenv(trim($name) . '=' . trim($value));
        $_ENV[trim($name)] = trim($value);
    }
}

$pdo = Database::getConnection();
$prefix = getenv('DB_PREFIX') ?: 'mv_';

$queries = [

    // ── Users table (if not exists) ──────────────────────
    "CREATE TABLE IF NOT EXISTS {$prefix}users (
        id INT AUTO_INCREMENT PRIMARY KEY,
        name VARCHAR(100) DEFAULT NULL,
        username VARCHAR(100) DEFAULT NULL,
        full_name VARCHAR(150) DEFAULT NULL,
        email VARCHAR(150) NOT NULL UNIQUE,
        password VARCHAR(255) NOT NULL,
        role VARCHAR(30) DEFAULT 'admin',
        is_active TINYINT(1) DEFAULT 1,
        last_login_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Clienti ──────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS {$prefix}clienti (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ragione_sociale VARCHAR(255) NOT NULL,
        partita_iva VARCHAR(16) DEFAULT NULL,
        codice_fiscale VARCHAR(20) DEFAULT NULL,
        indirizzo VARCHAR(255) DEFAULT NULL,
        citta VARCHAR(100) DEFAULT NULL,
        cap VARCHAR(10) DEFAULT NULL,
        provincia VARCHAR(5) DEFAULT NULL,
        pec VARCHAR(150) DEFAULT NULL,
        sdi VARCHAR(10) DEFAULT NULL,
        telefono VARCHAR(30) DEFAULT NULL,
        email VARCHAR(150) DEFAULT NULL,
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Sottoclienti (per gestire ad es. Unindustria con sedi/dipartimenti) ──
    "CREATE TABLE IF NOT EXISTS {$prefix}sottoclienti (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        nome VARCHAR(255) NOT NULL,
        riferimento VARCHAR(255) DEFAULT NULL COMMENT 'Persona di riferimento',
        indirizzo VARCHAR(255) DEFAULT NULL,
        citta VARCHAR(100) DEFAULT NULL,
        cap VARCHAR(10) DEFAULT NULL,
        provincia VARCHAR(5) DEFAULT NULL,
        telefono VARCHAR(30) DEFAULT NULL,
        email VARCHAR(150) DEFAULT NULL,
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (cliente_id) REFERENCES {$prefix}clienti(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Trasferte ────────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS {$prefix}trasferte (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT DEFAULT NULL,
        sottocliente_id INT DEFAULT NULL,
        data_trasferta DATE NOT NULL,
        fascia_oraria ENUM('intera', 'mattino', 'pomeriggio') DEFAULT 'intera',
        descrizione TEXT DEFAULT NULL,
        luogo_partenza VARCHAR(255) DEFAULT 'Padova',
        luogo_arrivo VARCHAR(255) DEFAULT NULL,
        google_event_id VARCHAR(255) DEFAULT NULL COMMENT 'Per evitare duplicati nella sincronizzazione',
        google_calendar_id VARCHAR(255) DEFAULT NULL,
        km_andata DECIMAL(8,1) DEFAULT 0,
        km_ritorno DECIMAL(8,1) DEFAULT 0,

        vitto DECIMAL(8,2) DEFAULT 0,
        alloggio DECIMAL(8,2) DEFAULT 0,
        note_spese TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (cliente_id) REFERENCES {$prefix}clienti(id) ON DELETE SET NULL,
        FOREIGN KEY (sottocliente_id) REFERENCES {$prefix}sottoclienti(id) ON DELETE SET NULL,
        UNIQUE KEY uk_google_event (google_event_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "ALTER TABLE {$prefix}trasferte ADD COLUMN fascia_oraria ENUM('intera', 'mattino', 'pomeriggio') DEFAULT 'intera' AFTER data_trasferta",
    "ALTER TABLE {$prefix}trasferte ADD COLUMN pernottamento TINYINT(1) DEFAULT 0 AFTER alloggio",
    "ALTER TABLE {$prefix}trasferte ADD COLUMN km_bloccati TINYINT(1) DEFAULT 0 AFTER pernottamento",

    // ── Fatture / Contabilità ────────────────────────────
    "CREATE TABLE IF NOT EXISTS {$prefix}fatture (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero_fattura VARCHAR(30) NOT NULL,
        data_emissione DATE NOT NULL,
        cliente_id INT DEFAULT NULL,
        sottocliente_id INT DEFAULT NULL,
        descrizione TEXT DEFAULT NULL,
        imponibile DECIMAL(10,2) NOT NULL DEFAULT 0,
        iva_percentuale DECIMAL(5,2) DEFAULT 22.00,
        importo_iva DECIMAL(10,2) DEFAULT 0,
        importo_totale DECIMAL(10,2) NOT NULL DEFAULT 0,
        stato ENUM('emessa','inviata','pagata','scaduta') DEFAULT 'emessa',
        data_scadenza DATE DEFAULT NULL,
        data_pagamento DATE DEFAULT NULL,
        metodo_pagamento VARCHAR(50) DEFAULT NULL,
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (cliente_id) REFERENCES {$prefix}clienti(id) ON DELETE SET NULL,
        FOREIGN KEY (sottocliente_id) REFERENCES {$prefix}sottoclienti(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Google Calendar tokens ───────────────────────────
    "CREATE TABLE IF NOT EXISTS {$prefix}google_tokens (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT DEFAULT NULL,
        access_token TEXT NOT NULL,
        refresh_token TEXT NOT NULL,
        token_type VARCHAR(50) DEFAULT 'Bearer',
        expires_at DATETIME NOT NULL,
        scope TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Mezzi (Flotta aziendale) ─────────────────────────
    "CREATE TABLE IF NOT EXISTS {$prefix}mezzi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        nome VARCHAR(255) NOT NULL,
        targa VARCHAR(20) NOT NULL UNIQUE,
        capacita INT DEFAULT 9,
        stato ENUM('attivo', 'manutenzione', 'fuori_servizio') DEFAULT 'attivo',
        scadenza_assicurazione DATE NULL,
        scadenza_bollo DATE NULL,
        note TEXT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Mezzi - Manutenzioni ─────────────────────────────
    "CREATE TABLE IF NOT EXISTS {$prefix}mezzi_manutenzioni (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mezzo_id INT NOT NULL,
        data_manutenzione DATE NOT NULL,
        tipo ENUM('tagliando', 'gomme_estive', 'gomme_invernali', 'riparazione', 'revisione', 'altro') NOT NULL,
        descrizione TEXT NULL,
        costo DECIMAL(10, 2) DEFAULT 0.00,
        chilometraggio INT NULL,
        prossima_scadenza_data DATE NULL,
        prossima_scadenza_km INT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (mezzo_id) REFERENCES {$prefix}mezzi(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Mezzi - Anomalie/Segnalazioni ────────────────────
    "CREATE TABLE IF NOT EXISTS {$prefix}mezzi_anomalie (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mezzo_id INT NOT NULL,
        data_segnalazione DATETIME DEFAULT CURRENT_TIMESTAMP,
        segnalatore_id INT NULL,
        descrizione TEXT NOT NULL,
        gravita ENUM('low', 'medium', 'high', 'critical') DEFAULT 'medium',
        stato ENUM('open', 'in_progress', 'resolved') DEFAULT 'open',
        note_risoluzione TEXT NULL,
        data_risoluzione DATETIME NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (mezzo_id) REFERENCES {$prefix}mezzi(id) ON DELETE CASCADE,
        FOREIGN KEY (segnalatore_id) REFERENCES {$prefix}users(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Settings (chiave-valore) ─────────────────────────
    "CREATE TABLE IF NOT EXISTS {$prefix}settings (
        setting_key VARCHAR(100) PRIMARY KEY,
        setting_value TEXT DEFAULT NULL,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "ALTER TABLE {$prefix}sottoclienti ADD COLUMN partita_iva VARCHAR(16) DEFAULT NULL AFTER nome",
    "ALTER TABLE {$prefix}sottoclienti ADD COLUMN codice_fiscale VARCHAR(20) DEFAULT NULL AFTER partita_iva",
    "ALTER TABLE {$prefix}sottoclienti ADD COLUMN sdi VARCHAR(10) DEFAULT NULL AFTER email",
    "ALTER TABLE {$prefix}sottoclienti ADD COLUMN pec VARCHAR(150) DEFAULT NULL AFTER sdi",

    // ── Allineamento schema users (per DB esistenti) ──
    "ALTER TABLE {$prefix}users ADD COLUMN username VARCHAR(100) DEFAULT NULL AFTER name",
    "ALTER TABLE {$prefix}users ADD COLUMN full_name VARCHAR(150) DEFAULT NULL AFTER username",
    "ALTER TABLE {$prefix}users ADD COLUMN is_active TINYINT(1) DEFAULT 1 AFTER role",
    "ALTER TABLE {$prefix}users ADD COLUMN last_login_at DATETIME DEFAULT NULL AFTER is_active"
];

$results = [];
foreach ($queries as $sql) {
    try {
        $pdo->exec($sql);
        // Extract table name from query
        preg_match('/CREATE TABLE IF NOT EXISTS\s+(\S+)/i', $sql, $m);
        $tableName = $m[1] ?? 'unknown';
        $results[] = ['table' => $tableName, 'status' => 'OK'];
    } catch (PDOException $e) {
        preg_match('/CREATE TABLE IF NOT EXISTS\s+(\S+)/i', $sql, $m);
        $tableName = $m[1] ?? 'unknown';
        $results[] = ['table' => $tableName, 'status' => 'ERROR', 'message' => $e->getMessage()];
    }
}

header('Content-Type: application/json');
echo json_encode(['success' => true, 'migrations' => $results], JSON_PRETTY_PRINT);
