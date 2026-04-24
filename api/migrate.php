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
        status ENUM('Attivo', 'Invitato', 'Disattivato') DEFAULT 'Attivo',
        blocked TINYINT(1) DEFAULT 0,
        failed_attempts INT DEFAULT 0,
        must_change_password TINYINT(1) DEFAULT 0,
        last_password_change DATETIME DEFAULT NULL,
        verification_token VARCHAR(255) DEFAULT NULL,
        token_expires_at DATETIME DEFAULT NULL,
        is_active TINYINT(1) DEFAULT 1,
        last_login_at DATETIME DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS {$prefix}password_history (
        id INT AUTO_INCREMENT PRIMARY KEY,
        user_id INT NOT NULL,
        pwd_hash VARCHAR(255) NOT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        KEY idx_pwdhist_user (user_id),
        KEY idx_pwdhist_created (created_at),
        FOREIGN KEY (user_id) REFERENCES {$prefix}users(id) ON DELETE CASCADE
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

    // ── Incarichi (Commesse / Assignments) ─────────────────
    "CREATE TABLE IF NOT EXISTS {$prefix}incarichi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        sottocliente_id INT DEFAULT NULL,
        data_incarico DATE NOT NULL,
        tipo_commessa ENUM('assistenza','dpo','formazione') NOT NULL DEFAULT 'assistenza',
        descrizione TEXT DEFAULT NULL,
        num_giornate DECIMAL(5,1) DEFAULT 0,
        importo_totale DECIMAL(10,2) NOT NULL DEFAULT 0,
        importo_fatturato DECIMAL(10,2) DEFAULT 0 COMMENT 'Somma importi fatture collegate',
        importo_pagato DECIMAL(10,2) DEFAULT 0 COMMENT 'Somma importi fatture pagate collegate',
        stato ENUM('attivo','parziale','fatturato','pagato') DEFAULT 'attivo',
        pdf_path VARCHAR(255) DEFAULT NULL COMMENT 'Path al PDF originale dell incarico',
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (cliente_id) REFERENCES {$prefix}clienti(id) ON DELETE CASCADE,
        FOREIGN KEY (sottocliente_id) REFERENCES {$prefix}sottoclienti(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Link fatture → incarichi
    "ALTER TABLE {$prefix}fatture ADD COLUMN incarico_id INT DEFAULT NULL AFTER sottocliente_id",
    "ALTER TABLE {$prefix}fatture ADD FOREIGN KEY fk_fatture_incarico (incarico_id) REFERENCES {$prefix}incarichi(id) ON DELETE SET NULL",

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
        allegato_url VARCHAR(255) NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        FOREIGN KEY (mezzo_id) REFERENCES {$prefix}mezzi(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    "ALTER TABLE {$prefix}mezzi_manutenzioni ADD COLUMN allegato_url VARCHAR(255) DEFAULT NULL AFTER prossima_scadenza_km",

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
        FOREIGN KEY (mezzo_id) REFERENCES {$prefix}mezzi(id) ON DELETE CASCADE
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
    "ALTER TABLE {$prefix}users ADD COLUMN last_login_at DATETIME DEFAULT NULL AFTER is_active",
    "ALTER TABLE {$prefix}users ADD COLUMN blocked TINYINT(1) DEFAULT 0 AFTER is_active",
    "ALTER TABLE {$prefix}users ADD COLUMN failed_attempts INT DEFAULT 0 AFTER blocked",
    "ALTER TABLE {$prefix}users ADD COLUMN must_change_password TINYINT(1) DEFAULT 0 AFTER failed_attempts",
    "ALTER TABLE {$prefix}users ADD COLUMN last_password_change DATETIME DEFAULT NULL AFTER must_change_password",

    // ── Audit Logs ───────────────────────────────────────
    "CREATE TABLE IF NOT EXISTS {$prefix}audit_logs (
        id VARCHAR(50) PRIMARY KEY,
        tenant_id INT NOT NULL DEFAULT 1,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP,
        user_id VARCHAR(50) DEFAULT NULL,
        username VARCHAR(100) DEFAULT NULL,
        role VARCHAR(20) DEFAULT NULL,
        event_type VARCHAR(50) NOT NULL DEFAULT 'crud',
        action VARCHAR(100) NOT NULL,
        table_name VARCHAR(100) DEFAULT NULL,
        record_id VARCHAR(100) DEFAULT NULL,
        ip_address VARCHAR(45) DEFAULT NULL,
        user_agent VARCHAR(512) DEFAULT NULL,
        http_status SMALLINT DEFAULT 200,
        before_snapshot MEDIUMTEXT DEFAULT NULL,
        after_snapshot MEDIUMTEXT DEFAULT NULL,
        details TEXT DEFAULT NULL,
        KEY idx_audit_user (user_id),
        KEY idx_audit_created (created_at),
        KEY idx_audit_action (action),
        KEY idx_audit_event_type (event_type),
        KEY idx_audit_table (table_name)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",
    // Se la tabella esisteva già prima con la vecchia struttura, le colonne verranno create o modificate.
    "ALTER TABLE {$prefix}audit_logs CHANGE user_name username VARCHAR(100) DEFAULT NULL",
    "ALTER TABLE {$prefix}audit_logs ADD COLUMN id VARCHAR(50) PRIMARY KEY FIRST",
    "ALTER TABLE {$prefix}audit_logs ADD COLUMN tenant_id INT NOT NULL DEFAULT 1 AFTER id",
    "ALTER TABLE {$prefix}audit_logs ADD COLUMN role VARCHAR(20) DEFAULT NULL AFTER username",
    "ALTER TABLE {$prefix}audit_logs ADD COLUMN event_type VARCHAR(50) NOT NULL DEFAULT 'crud' AFTER role",
    "ALTER TABLE {$prefix}audit_logs ADD COLUMN user_agent VARCHAR(512) DEFAULT NULL AFTER ip_address",
    "ALTER TABLE {$prefix}audit_logs ADD COLUMN http_status SMALLINT DEFAULT 200 AFTER user_agent",
    "ALTER TABLE {$prefix}audit_logs ADD COLUMN before_snapshot MEDIUMTEXT DEFAULT NULL AFTER http_status",
    "ALTER TABLE {$prefix}audit_logs ADD COLUMN after_snapshot MEDIUMTEXT DEFAULT NULL AFTER before_snapshot",
    "ALTER TABLE {$prefix}audit_logs CHANGE timestamp created_at DATETIME DEFAULT CURRENT_TIMESTAMP"
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
