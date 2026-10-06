<?php
/**
 * Elenco delle migrazioni del database, in ordine. La versione è la POSIZIONE (v001 = prima voce):
 * aggiungere nuove migrazioni SOLO in coda, mai in mezzo e mai toglierne.
 * Lo usano api/migrate.php (le applica) e api/health.php (conta quelle mancanti).
 * Richiede $prefix nello scope di chi lo include.
 */
return [

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

    // ── Trasferte — mezzo utilizzato (colonna) ────────────
    // FK aggiunta dopo la creazione della tabella mezzi (vedi fine array)
    "ALTER TABLE {$prefix}trasferte ADD COLUMN mezzo_id INT DEFAULT NULL AFTER km_bloccati",

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

    // ── Incarichi — numero protocollo per match fatture XML ──
    "ALTER TABLE {$prefix}incarichi ADD COLUMN numero_protocollo VARCHAR(100) DEFAULT NULL COMMENT 'Numero protocollo univoco (es. 1350/2026)' AFTER tipo_commessa",

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
    "ALTER TABLE {$prefix}audit_logs CHANGE timestamp created_at DATETIME DEFAULT CURRENT_TIMESTAMP",

    // ── FK differita: trasferte → mezzi (ora mezzi esiste) ──
    "ALTER TABLE {$prefix}trasferte ADD FOREIGN KEY fk_trasferte_mezzo (mezzo_id) REFERENCES {$prefix}mezzi(id) ON DELETE SET NULL",

    // ── Allineamento password_history (tabella preesistente senza pwd_hash) ──
    "ALTER TABLE {$prefix}password_history ADD COLUMN pwd_hash VARCHAR(255) DEFAULT NULL AFTER user_id",

    // ── Registro backup DB (stesso schema di AdminController::ensureTables) ──
    "CREATE TABLE IF NOT EXISTS {$prefix}db_backups (
        id VARCHAR(50) PRIMARY KEY,
        filename VARCHAR(255) NOT NULL,
        filesize BIGINT NOT NULL DEFAULT 0,
        row_count INT NULL DEFAULT 0,
        status VARCHAR(20) DEFAULT 'ok',
        created_by INT NULL,
        created_at DATETIME DEFAULT CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ═══ Modulo commerciale: offerte → incarico → rate → fatture, partner e margini ═══

    // ── Offerte ai clienti ──
    "CREATE TABLE IF NOT EXISTS {$prefix}offerte (
        id INT AUTO_INCREMENT PRIMARY KEY,
        numero VARCHAR(30) NOT NULL COMMENT 'es. OFF-2026-001',
        versione INT NOT NULL DEFAULT 1,
        cliente_id INT DEFAULT NULL,
        cliente_nome VARCHAR(255) DEFAULT NULL COMMENT 'Prospect non ancora in anagrafica',
        sottocliente_id INT DEFAULT NULL,
        data_offerta DATE NOT NULL,
        data_scadenza DATE DEFAULT NULL COMMENT 'Fine validità offerta',
        oggetto VARCHAR(255) NOT NULL,
        descrizione TEXT DEFAULT NULL,
        tipo_commessa ENUM('assistenza','dpo','formazione') NOT NULL DEFAULT 'assistenza',
        num_giornate DECIMAL(6,1) NOT NULL DEFAULT 0,
        imponibile DECIMAL(15,2) NOT NULL DEFAULT 0,
        iva_percentuale DECIMAL(5,2) NOT NULL DEFAULT 22.00,
        condizioni_pagamento TEXT DEFAULT NULL,
        giorni_pagamento INT NOT NULL DEFAULT 30,
        piano_rate TEXT DEFAULT NULL COMMENT 'JSON: [{descrizione, percentuale, giorni_da_accettazione}]',
        stato ENUM('bozza','inviata','accettata','rifiutata','scaduta','sostituita') NOT NULL DEFAULT 'bozza',
        data_invio DATE DEFAULT NULL,
        data_followup DATE DEFAULT NULL COMMENT 'Quando ricontattare il cliente',
        data_esito DATE DEFAULT NULL,
        motivo_esito VARCHAR(255) DEFAULT NULL,
        incarico_id INT DEFAULT NULL,
        file_path VARCHAR(255) DEFAULT NULL,
        origine VARCHAR(20) NOT NULL DEFAULT 'manuale',
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME DEFAULT NULL,
        UNIQUE KEY uq_offerte_numero_versione (numero, versione),
        KEY idx_offerte_stato (stato),
        KEY idx_offerte_data (data_offerta),
        CONSTRAINT fk_offerte_cliente FOREIGN KEY (cliente_id) REFERENCES {$prefix}clienti(id) ON DELETE SET NULL,
        CONSTRAINT fk_offerte_sottocliente FOREIGN KEY (sottocliente_id) REFERENCES {$prefix}sottoclienti(id) ON DELETE SET NULL,
        CONSTRAINT fk_offerte_incarico FOREIGN KEY (incarico_id) REFERENCES {$prefix}incarichi(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "CREATE TABLE IF NOT EXISTS {$prefix}offerte_righe (
        id INT AUTO_INCREMENT PRIMARY KEY,
        offerta_id INT NOT NULL,
        ordine INT NOT NULL DEFAULT 0,
        descrizione TEXT NOT NULL,
        quantita DECIMAL(10,2) NOT NULL DEFAULT 1,
        unita VARCHAR(20) DEFAULT NULL,
        prezzo_unitario DECIMAL(15,2) NOT NULL DEFAULT 0,
        importo DECIMAL(15,2) NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_offerte_righe_offerta (offerta_id),
        CONSTRAINT fk_offerte_righe_offerta FOREIGN KEY (offerta_id) REFERENCES {$prefix}offerte(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Incarichi: legame con l'offerta e termini di pagamento ──
    "ALTER TABLE {$prefix}incarichi ADD COLUMN offerta_id INT DEFAULT NULL AFTER sottocliente_id",
    "ALTER TABLE {$prefix}incarichi ADD CONSTRAINT fk_incarichi_offerta FOREIGN KEY (offerta_id) REFERENCES {$prefix}offerte(id) ON DELETE SET NULL",
    "ALTER TABLE {$prefix}incarichi ADD COLUMN giorni_pagamento INT NOT NULL DEFAULT 30 AFTER importo_pagato",
    "ALTER TABLE {$prefix}incarichi ADD COLUMN condizioni_pagamento TEXT DEFAULT NULL AFTER giorni_pagamento",

    // ── Piano di fatturazione della commessa (acconto, SAL, saldo) ──
    // Lo stato non si salva: deriva dalla fattura collegata (nessuna → da fatturare, pagata → incassata)
    "CREATE TABLE IF NOT EXISTS {$prefix}incarichi_rate (
        id INT AUTO_INCREMENT PRIMARY KEY,
        incarico_id INT NOT NULL,
        ordine INT NOT NULL DEFAULT 0,
        descrizione VARCHAR(255) NOT NULL,
        percentuale DECIMAL(5,2) DEFAULT NULL,
        importo DECIMAL(15,2) NOT NULL DEFAULT 0,
        data_prevista DATE DEFAULT NULL COMMENT 'Quando emettere la fattura',
        giorni_pagamento INT NOT NULL DEFAULT 30,
        fattura_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_rate_incarico (incarico_id),
        KEY idx_rate_data (data_prevista),
        UNIQUE KEY uq_rate_fattura (fattura_id),
        CONSTRAINT fk_rate_incarico FOREIGN KEY (incarico_id) REFERENCES {$prefix}incarichi(id) ON DELETE CASCADE,
        CONSTRAINT fk_rate_fattura FOREIGN KEY (fattura_id) REFERENCES {$prefix}fatture(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Partner e fornitori ──
    "CREATE TABLE IF NOT EXISTS {$prefix}fornitori (
        id INT AUTO_INCREMENT PRIMARY KEY,
        ragione_sociale VARCHAR(255) NOT NULL,
        tipo ENUM('partner','fornitore') NOT NULL DEFAULT 'partner',
        partita_iva VARCHAR(16) DEFAULT NULL,
        codice_fiscale VARCHAR(20) DEFAULT NULL,
        email VARCHAR(150) DEFAULT NULL,
        pec VARCHAR(150) DEFAULT NULL,
        telefono VARCHAR(30) DEFAULT NULL,
        iban VARCHAR(34) DEFAULT NULL,
        giorni_pagamento INT NOT NULL DEFAULT 30,
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME DEFAULT NULL,
        KEY idx_fornitori_piva (partita_iva)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Costi della commessa: cosa devo a un partner, con la sua offerta ──
    // Nasce sull'offerta (costo previsto) e segue l'incarico quando l'offerta è accettata
    "CREATE TABLE IF NOT EXISTS {$prefix}commessa_costi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        incarico_id INT DEFAULT NULL,
        offerta_id INT DEFAULT NULL,
        fornitore_id INT DEFAULT NULL,
        descrizione VARCHAR(255) NOT NULL,
        importo_previsto DECIMAL(15,2) NOT NULL DEFAULT 0,
        offerta_fornitore_numero VARCHAR(50) DEFAULT NULL,
        offerta_fornitore_data DATE DEFAULT NULL,
        offerta_fornitore_file VARCHAR(255) DEFAULT NULL,
        condizione_pagamento ENUM('scadenza','back_to_back') NOT NULL DEFAULT 'scadenza' COMMENT 'back_to_back: pago il partner quando incasso',
        giorni_pagamento INT NOT NULL DEFAULT 30,
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_costi_incarico (incarico_id),
        KEY idx_costi_offerta (offerta_id),
        CONSTRAINT fk_costi_incarico FOREIGN KEY (incarico_id) REFERENCES {$prefix}incarichi(id) ON DELETE CASCADE,
        CONSTRAINT fk_costi_offerta FOREIGN KEY (offerta_id) REFERENCES {$prefix}offerte(id) ON DELETE SET NULL,
        CONSTRAINT fk_costi_fornitore FOREIGN KEY (fornitore_id) REFERENCES {$prefix}fornitori(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ── Fatture ricevute dai partner / fornitori ──
    "CREATE TABLE IF NOT EXISTS {$prefix}fatture_passive (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fornitore_id INT DEFAULT NULL,
        incarico_id INT DEFAULT NULL,
        costo_id INT DEFAULT NULL,
        numero VARCHAR(50) NOT NULL,
        data_emissione DATE NOT NULL,
        descrizione TEXT DEFAULT NULL,
        imponibile DECIMAL(15,2) NOT NULL DEFAULT 0,
        importo_iva DECIMAL(15,2) NOT NULL DEFAULT 0,
        ritenuta DECIMAL(15,2) NOT NULL DEFAULT 0,
        importo_totale DECIMAL(15,2) NOT NULL DEFAULT 0 COMMENT 'Netto a pagare (totale documento meno ritenuta)',
        data_scadenza DATE DEFAULT NULL,
        data_pagamento DATE DEFAULT NULL,
        stato ENUM('da_pagare','pagata') NOT NULL DEFAULT 'da_pagare',
        note TEXT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_passive_fornitore_numero (fornitore_id, numero, data_emissione),
        KEY idx_passive_scadenza (data_scadenza),
        KEY idx_passive_incarico (incarico_id),
        CONSTRAINT fk_passive_fornitore FOREIGN KEY (fornitore_id) REFERENCES {$prefix}fornitori(id) ON DELETE SET NULL,
        CONSTRAINT fk_passive_incarico FOREIGN KEY (incarico_id) REFERENCES {$prefix}incarichi(id) ON DELETE SET NULL,
        CONSTRAINT fk_passive_costo FOREIGN KEY (costo_id) REFERENCES {$prefix}commessa_costi(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ═══ Riconciliazione pagamenti (v057–v058) ═══
    // Movimenti dell'estratto conto e avvisi di pagamento dei clienti (attesi, non ancora visti in banca).
    // Si salvano solo i movimenti pertinenti (agganciabili a una fattura, un cliente o un fornitore).
    // avviso_id: sul movimento bancario, l'avviso già registrato che quell'accredito salda (niente doppio pagamento)
    "CREATE TABLE IF NOT EXISTS {$prefix}movimenti_banca (
        id INT AUTO_INCREMENT PRIMARY KEY,
        banca VARCHAR(100) NOT NULL DEFAULT '',
        iban VARCHAR(34) DEFAULT NULL,
        riferimento_banca VARCHAR(100) DEFAULT NULL COMMENT 'NtryRef / AcctSvcrRef del CBI',
        codice_operazione VARCHAR(20) DEFAULT NULL COMMENT 'BkTxCd CBI (es. 48 bonifico)',
        data_operazione DATE NOT NULL,
        data_valuta DATE DEFAULT NULL,
        importo DECIMAL(15,2) NOT NULL COMMENT '+ accredito, - addebito',
        descrizione TEXT DEFAULT NULL,
        controparte VARCHAR(255) DEFAULT NULL,
        hash_riga CHAR(64) NOT NULL,
        stato ENUM('da_riconciliare','riconciliato','ignorato') NOT NULL DEFAULT 'da_riconciliare',
        origine ENUM('estratto_conto','avviso_pagamento') NOT NULL DEFAULT 'estratto_conto',
        avviso_id INT DEFAULT NULL,
        file_nome VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_movimenti_hash (hash_riga),
        KEY idx_movimenti_stato (stato, data_valuta),
        KEY idx_movimenti_avviso (avviso_id),
        CONSTRAINT fk_movimenti_avviso FOREIGN KEY (avviso_id) REFERENCES {$prefix}movimenti_banca(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // Un movimento può saldare più fatture (bonifico cumulativo), una fattura può essere pagata in più movimenti (acconti)
    "CREATE TABLE IF NOT EXISTS {$prefix}riconciliazioni (
        id INT AUTO_INCREMENT PRIMARY KEY,
        movimento_id INT NOT NULL,
        tipo ENUM('fattura','fattura_passiva') NOT NULL,
        documento_id INT NOT NULL,
        importo DECIMAL(15,2) NOT NULL,
        metodo ENUM('auto','manuale') NOT NULL DEFAULT 'manuale',
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        KEY idx_ric_movimento (movimento_id),
        KEY idx_ric_documento (tipo, documento_id),
        CONSTRAINT fk_ric_movimento FOREIGN KEY (movimento_id) REFERENCES {$prefix}movimenti_banca(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    // ═══ Categorie dei movimenti bancari (v059–v063) ═══
    // codice: chiave fissa delle categorie usate dal codice (fatture, euristiche); NULL per quelle create dall'utente
    "CREATE TABLE IF NOT EXISTS {$prefix}categorie_movimento (
        id INT AUTO_INCREMENT PRIMARY KEY,
        codice VARCHAR(40) DEFAULT NULL,
        nome VARCHAR(100) NOT NULL,
        tipo ENUM('entrata','uscita') NOT NULL,
        colore CHAR(7) NOT NULL DEFAULT '#64748B',
        ordine INT NOT NULL DEFAULT 0,
        attiva TINYINT(1) NOT NULL DEFAULT 1,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_categorie_codice (codice),
        KEY idx_categorie_tipo (tipo, attiva, ordine)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "INSERT IGNORE INTO {$prefix}categorie_movimento (codice, nome, tipo, colore, ordine) VALUES
        ('incassi_clienti', 'Incassi clienti', 'entrata', '#10B981', 10),
        ('finanziamenti', 'Finanziamenti e mutui', 'entrata', '#0EA5E9', 20),
        ('versamenti_soci', 'Versamenti soci / capitale', 'entrata', '#6366F1', 30),
        ('rimborsi', 'Rimborsi', 'entrata', '#14B8A6', 40),
        ('altre_entrate', 'Altre entrate', 'entrata', '#84CC16', 50),
        ('fornitori_partner', 'Fornitori e partner', 'uscita', '#F97316', 110),
        ('commercialista_paghe', 'Commercialista e consulenza paghe', 'uscita', '#A855F7', 120),
        ('compensi_collaboratori', 'Compensi e collaboratori', 'uscita', '#EC4899', 130),
        ('stipendi_contributi', 'Stipendi e contributi', 'uscita', '#F43F5E', 140),
        ('imposte_tasse', 'Imposte e tasse (F24)', 'uscita', '#EF4444', 150),
        ('mutuo_interessi', 'Rate mutuo e interessi', 'uscita', '#0284C7', 160),
        ('commissioni_banca', 'Commissioni e spese bancarie', 'uscita', '#94A3B8', 170),
        ('carte_credito', 'Carte di credito', 'uscita', '#EAB308', 180),
        ('software_abbonamenti', 'Software e abbonamenti', 'uscita', '#8B5CF6', 190),
        ('viaggi_trasferte', 'Viaggi e trasferte', 'uscita', '#06B6D4', 200),
        ('altre_uscite', 'Altre uscite', 'uscita', '#78716C', 210)",

    // Classificazione del movimento. abbinabile = 0: nessun aggancio a fatture/clienti/fornitori all'import
    // (resta fuori dalla coda "da riconciliare", ma si classifica e finisce nei grafici)
    "ALTER TABLE {$prefix}movimenti_banca
        ADD COLUMN categoria_id INT DEFAULT NULL,
        ADD COLUMN categoria_fonte ENUM('fattura','regola','codice_banca','ai','utente') DEFAULT NULL,
        ADD COLUMN classificazione ENUM('da_classificare','classificato') NOT NULL DEFAULT 'da_classificare',
        ADD COLUMN regola_id INT DEFAULT NULL,
        ADD COLUMN categoria_proposta_id INT DEFAULT NULL,
        ADD COLUMN proposta_motivo VARCHAR(255) DEFAULT NULL,
        ADD COLUMN abbinabile TINYINT(1) NOT NULL DEFAULT 1,
        ADD KEY idx_movimenti_classif (classificazione, categoria_id),
        ADD CONSTRAINT fk_movimenti_categoria FOREIGN KEY (categoria_id) REFERENCES {$prefix}categorie_movimento(id) ON DELETE SET NULL",

    // Regole apprese: chiave normalizzata (controparte/causale) e/o codice operazione CBI, per segno
    "CREATE TABLE IF NOT EXISTS {$prefix}regole_categoria (
        id INT AUTO_INCREMENT PRIMARY KEY,
        chiave VARCHAR(150) NOT NULL DEFAULT '',
        codice_operazione VARCHAR(20) NOT NULL DEFAULT '',
        segno TINYINT NOT NULL COMMENT '1 entrata, -1 uscita',
        categoria_id INT NOT NULL,
        utilizzi INT NOT NULL DEFAULT 0,
        created_by INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_regole (chiave, codice_operazione, segno),
        CONSTRAINT fk_regole_categoria FOREIGN KEY (categoria_id) REFERENCES {$prefix}categorie_movimento(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4",

    "ALTER TABLE {$prefix}fornitori
        ADD COLUMN categoria_default_id INT DEFAULT NULL,
        ADD CONSTRAINT fk_fornitori_categoria FOREIGN KEY (categoria_default_id) REFERENCES {$prefix}categorie_movimento(id) ON DELETE SET NULL",

    // v064: stato della fattura prima del pagamento, per ripristinarlo se la riconciliazione viene annullata
    "ALTER TABLE {$prefix}riconciliazioni ADD COLUMN stato_precedente VARCHAR(20) DEFAULT NULL",

    // v065: tipo documento SDI (TD01 fattura, TD04/TD08 nota di credito...): distingue documenti con lo stesso numero
    "ALTER TABLE {$prefix}fatture ADD COLUMN tipo_documento VARCHAR(4) DEFAULT NULL",

    // v066: una fattura può chiudere più rate (es. acconto + saldo fatturati insieme): l'indice su fattura_id non è più unico
    "ALTER TABLE {$prefix}incarichi_rate DROP INDEX uq_rate_fattura, ADD KEY idx_rate_fattura (fattura_id)",

    // v067: trasferta ritoccata a mano → la sincronizzazione Google non la riscrive né la elimina
    "ALTER TABLE {$prefix}trasferte ADD COLUMN modifica_manuale TINYINT(1) NOT NULL DEFAULT 0",

    // v068: coordinate degli indirizzi già geocodificati (Nominatim: 1 richiesta al secondo)
    "CREATE TABLE IF NOT EXISTS {$prefix}geocache (
        indirizzo_hash CHAR(32) NOT NULL PRIMARY KEY,
        indirizzo VARCHAR(500) NOT NULL,
        lat DECIMAL(10,7) DEFAULT NULL,
        lon DECIMAL(10,7) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // v069: le trasferte si leggono per intervallo di date e, nella sincronizzazione, per calendario
    "ALTER TABLE {$prefix}trasferte ADD KEY idx_trasferte_data (data_trasferta), ADD KEY idx_trasferte_calendario (google_calendar_id, data_trasferta)",

    // v070: spese dell'estratto conto della carta di credito (fuori da categorie e grafici: sul conto c'è l'addebito mensile)
    "ALTER TABLE {$prefix}movimenti_banca MODIFY origine ENUM('estratto_conto','avviso_pagamento','estratto_carta') NOT NULL DEFAULT 'estratto_conto'",

    // v071–v072: nuovi tipi di commessa (consulenza NIS 2, ICT, digital, sviluppo software) su incarichi e offerte
    "ALTER TABLE {$prefix}incarichi MODIFY tipo_commessa ENUM('assistenza','dpo','formazione','nis2','ict','digital','sviluppo_software') NOT NULL DEFAULT 'assistenza'",
    "ALTER TABLE {$prefix}offerte MODIFY tipo_commessa ENUM('assistenza','dpo','formazione','nis2','ict','digital','sviluppo_software') NOT NULL DEFAULT 'assistenza'",

    // v073: categoria di uscita Assicurazioni (polizze e premi), riconosciuta anche dalle euristiche del Classificatore
    "INSERT IGNORE INTO {$prefix}categorie_movimento (codice, nome, tipo, colore, ordine) VALUES ('assicurazioni', 'Assicurazioni', 'uscita', '#B45309', 175)",

    // v074–v075: i prospect delle offerte già salvate entrano in anagrafica clienti e le offerte si collegano
    "INSERT INTO {$prefix}clienti (ragione_sociale, note)
        SELECT DISTINCT TRIM(o.cliente_nome), 'Prospect: aggiunto da un preventivo' FROM {$prefix}offerte o
        WHERE o.cliente_id IS NULL AND TRIM(COALESCE(o.cliente_nome, '')) <> ''
          AND NOT EXISTS (SELECT 1 FROM {$prefix}clienti c WHERE LOWER(TRIM(c.ragione_sociale)) = LOWER(TRIM(o.cliente_nome)))",
    "UPDATE {$prefix}offerte o JOIN {$prefix}clienti c ON LOWER(TRIM(c.ragione_sociale)) = LOWER(TRIM(o.cliente_nome))
        SET o.cliente_id = c.id, o.cliente_nome = NULL
        WHERE o.cliente_id IS NULL",

    // v076: estratti carta importati per errore come estratto conto → origine carta (si sommavano all'addebito mensile)
    "UPDATE {$prefix}movimenti_banca SET origine = 'estratto_carta', categoria_id = NULL, categoria_proposta_id = NULL
        WHERE origine = 'estratto_conto' AND banca REGEXP '(numia|carta ?bcc)'",

    // v077: archivio dei file importati (originale in storage/import/<sha256>.<ext>, vedi ArchivioImport)
    "CREATE TABLE IF NOT EXISTS {$prefix}import_file (
        id INT AUTO_INCREMENT PRIMARY KEY,
        sha256 CHAR(64) NOT NULL,
        tipo VARCHAR(30) NOT NULL,
        nome_file VARCHAR(255) NOT NULL,
        estensione VARCHAR(5) NOT NULL,
        dimensione INT UNSIGNED NOT NULL DEFAULT 0,
        volte INT UNSIGNED NOT NULL DEFAULT 1,
        user_id INT DEFAULT NULL,
        prima_importazione DATETIME NOT NULL,
        ultima_importazione DATETIME NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_import_file_sha (sha256),
        KEY idx_import_file_tipo (tipo, ultima_importazione)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // v078–v079: il lead è un'offerta prima della bozza, con fonte, probabilità e prossima azione (data in data_followup)
    "ALTER TABLE {$prefix}offerte MODIFY stato ENUM('lead','bozza','inviata','accettata','rifiutata','scaduta','sostituita') NOT NULL DEFAULT 'bozza'",
    "ALTER TABLE {$prefix}offerte ADD COLUMN fonte VARCHAR(40) DEFAULT NULL AFTER origine,
        ADD COLUMN probabilita TINYINT UNSIGNED DEFAULT NULL COMMENT 'Percentuale; NULL = quella predefinita dello stato' AFTER fonte,
        ADD COLUMN prossima_azione VARCHAR(255) DEFAULT NULL AFTER data_followup",

    // v080: referenti dei clienti (più persone per azienda)
    "CREATE TABLE IF NOT EXISTS {$prefix}referenti (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        nome VARCHAR(150) NOT NULL,
        ruolo VARCHAR(100) DEFAULT NULL,
        email VARCHAR(150) DEFAULT NULL,
        telefono VARCHAR(50) DEFAULT NULL,
        principale TINYINT(1) NOT NULL DEFAULT 0,
        note VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME DEFAULT NULL,
        KEY idx_referenti_cliente (cliente_id),
        CONSTRAINT fk_referenti_cliente FOREIGN KEY (cliente_id) REFERENCES {$prefix}clienti(id) ON DELETE CASCADE
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // v081: note datate sul cliente (chiamate, email, incontri); il resto dello storico si ricava da offerte, commesse e fatture
    "CREATE TABLE IF NOT EXISTS {$prefix}attivita (
        id INT AUTO_INCREMENT PRIMARY KEY,
        cliente_id INT NOT NULL,
        offerta_id INT DEFAULT NULL,
        tipo ENUM('nota','chiamata','email','incontro') NOT NULL DEFAULT 'nota',
        data DATE NOT NULL,
        testo TEXT NOT NULL,
        user_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        KEY idx_attivita_cliente (cliente_id, data),
        CONSTRAINT fk_attivita_cliente FOREIGN KEY (cliente_id) REFERENCES {$prefix}clienti(id) ON DELETE CASCADE,
        CONSTRAINT fk_attivita_offerta FOREIGN KEY (offerta_id) REFERENCES {$prefix}offerte(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // v082: note spese — ogni spesa di trasferta con giustificativo, metodo di pagamento e movimento della carta
    "CREATE TABLE IF NOT EXISTS {$prefix}spese (
        id INT AUTO_INCREMENT PRIMARY KEY,
        data DATE NOT NULL,
        categoria ENUM('vitto','alloggio','treno','aereo','taxi','pedaggio','parcheggio','carburante','altro') NOT NULL DEFAULT 'altro',
        descrizione VARCHAR(255) DEFAULT NULL,
        esercente VARCHAR(150) DEFAULT NULL,
        importo DECIMAL(10,2) NOT NULL,
        metodo ENUM('carta','bancomat','bonifico','contanti','altro') NOT NULL DEFAULT 'carta',
        cliente_id INT DEFAULT NULL,
        movimento_id INT DEFAULT NULL COMMENT 'Movimento della carta che la paga',
        documento VARCHAR(255) DEFAULT NULL COMMENT 'Giustificativo in storage/documenti',
        origine ENUM('manuale','scontrino','carta','trasferta') NOT NULL DEFAULT 'manuale',
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        deleted_at DATETIME DEFAULT NULL,
        KEY idx_spese_data (data),
        UNIQUE KEY uq_spese_movimento (movimento_id),
        CONSTRAINT fk_spese_cliente FOREIGN KEY (cliente_id) REFERENCES {$prefix}clienti(id) ON DELETE SET NULL,
        CONSTRAINT fk_spese_movimento FOREIGN KEY (movimento_id) REFERENCES {$prefix}movimenti_banca(id) ON DELETE SET NULL
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // v083: vitto e alloggio già scritti sulle trasferte diventano spese (le colonne restano, non più usate)
    "INSERT INTO {$prefix}spese (data, categoria, descrizione, importo, metodo, cliente_id, origine)
        SELECT data_trasferta, 'vitto', 'Vitto (dalla trasferta)', vitto, 'altro', cliente_id, 'trasferta' FROM {$prefix}trasferte t WHERE vitto > 0
            AND NOT EXISTS (SELECT 1 FROM {$prefix}spese s WHERE s.origine = 'trasferta' AND s.categoria = 'vitto' AND s.data = t.data_trasferta)
        UNION ALL
        SELECT data_trasferta, 'alloggio', 'Alloggio (dalla trasferta)', alloggio, 'altro', cliente_id, 'trasferta' FROM {$prefix}trasferte t WHERE alloggio > 0
            AND NOT EXISTS (SELECT 1 FROM {$prefix}spese s WHERE s.origine = 'trasferta' AND s.categoria = 'alloggio' AND s.data = t.data_trasferta)",

    // v084: costo chilometrico ACI del modello, per mezzo (senza: vale il costo al km generale)
    "ALTER TABLE {$prefix}mezzi ADD COLUMN costo_km DECIMAL(7,4) DEFAULT NULL COMMENT 'Costo €/km dalle tabelle ACI' AFTER capacita,
        ADD COLUMN modello_aci VARCHAR(150) DEFAULT NULL AFTER costo_km",

    // v085: nota spese del mese — stato e totali congelati quando si presenta
    "CREATE TABLE IF NOT EXISTS {$prefix}rimborsi (
        id INT AUTO_INCREMENT PRIMARY KEY,
        mese CHAR(7) NOT NULL COMMENT 'AAAA-MM',
        stato ENUM('presentata','rimborsata') NOT NULL DEFAULT 'presentata',
        km DECIMAL(10,1) NOT NULL DEFAULT 0,
        importo_km DECIMAL(10,2) NOT NULL DEFAULT 0,
        indennita DECIMAL(10,2) NOT NULL DEFAULT 0,
        spese DECIMAL(10,2) NOT NULL DEFAULT 0,
        totale DECIMAL(10,2) NOT NULL DEFAULT 0,
        data_presentazione DATE NOT NULL,
        data_rimborso DATE DEFAULT NULL,
        note VARCHAR(255) DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        UNIQUE KEY uq_rimborsi_mese (mese)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // v086: la carta dell'estratto è aziendale (la paga la società, non si rimborsa): per le spese pagate
    // di tasca propria con una carta personale serve un metodo a parte
    "ALTER TABLE {$prefix}spese MODIFY metodo ENUM('carta','carta_personale','bancomat','bonifico','contanti','altro') NOT NULL DEFAULT 'carta'",

    // v087: indice per le uscite della carta per periodo (spese, dashboard)
    "ALTER TABLE {$prefix}movimenti_banca ADD KEY idx_movimenti_origine_data (origine, data_operazione)",

    // v088–v089: clienti e fornitori creati dagli elenchi di fatture col solo nome; la P.IVA si cerca sul web (AnagraficaAuto)
    "ALTER TABLE {$prefix}clienti ADD COLUMN piva_ricerca ENUM('da_cercare','trovata','non_trovata') DEFAULT NULL",
    "ALTER TABLE {$prefix}fornitori ADD COLUMN piva_ricerca ENUM('da_cercare','trovata','non_trovata') DEFAULT NULL",

    // v090–v091: tipo di commessa «altro» per quello che non è DPO, assistenza, formazione né le consulenze specifiche
    "ALTER TABLE {$prefix}incarichi MODIFY tipo_commessa ENUM('assistenza','dpo','formazione','nis2','ict','digital','sviluppo_software','altro') NOT NULL DEFAULT 'assistenza'",
    "ALTER TABLE {$prefix}offerte MODIFY tipo_commessa ENUM('assistenza','dpo','formazione','nis2','ict','digital','sviluppo_software','altro') NOT NULL DEFAULT 'assistenza'",

    // v092–v093: ogni commessa nasce da un'offerta (CommessaService::offertaRapida). Le commesse registrate prima
    // non l'hanno: si crea l'offerta già accettata con gli stessi dati, numerata dopo l'ultima dell'anno
    // (conteggio correlato invece di ROW_NUMBER, per non dipendere da MySQL 8), poi si collega alla commessa
    "INSERT INTO {$prefix}offerte (numero, versione, cliente_id, sottocliente_id, data_offerta, oggetto, tipo_commessa,
            num_giornate, imponibile, giorni_pagamento, condizioni_pagamento, stato, data_esito, incarico_id, origine, note)
        SELECT CONCAT('OFF-', YEAR(i.data_incarico), '-', LPAD(
                COALESCE((SELECT MAX(CAST(SUBSTRING(o2.numero, 10) AS UNSIGNED)) FROM {$prefix}offerte o2
                    WHERE o2.numero LIKE CONCAT('OFF-', YEAR(i.data_incarico), '-%')), 0)
                + (SELECT COUNT(*) FROM {$prefix}incarichi i2
                    WHERE i2.offerta_id IS NULL AND YEAR(i2.data_incarico) = YEAR(i.data_incarico)
                      AND (i2.data_incarico < i.data_incarico OR (i2.data_incarico = i.data_incarico AND i2.id <= i.id))
                      AND NOT EXISTS (SELECT 1 FROM {$prefix}offerte o3 WHERE o3.incarico_id = i2.id AND o3.deleted_at IS NULL)),
                3, '0')),
            1, i.cliente_id, i.sottocliente_id, i.data_incarico,
            LEFT(COALESCE(NULLIF(TRIM(i.descrizione), ''), CONCAT('Commessa ', COALESCE(NULLIF(i.numero_protocollo, ''), i.id))), 255),
            i.tipo_commessa, COALESCE(i.num_giornate, 0), i.importo_totale, i.giorni_pagamento, i.condizioni_pagamento,
            'accettata', i.data_incarico, i.id, 'rapida', 'Offerta registrata insieme alla commessa'
        FROM {$prefix}incarichi i
        WHERE i.offerta_id IS NULL
          AND NOT EXISTS (SELECT 1 FROM {$prefix}offerte o WHERE o.incarico_id = i.id AND o.deleted_at IS NULL)",
    "UPDATE {$prefix}incarichi i JOIN {$prefix}offerte o ON o.incarico_id = i.id AND o.deleted_at IS NULL
        SET i.offerta_id = o.id WHERE i.offerta_id IS NULL",

    // v094–v095: movimenti che Riconciliatore::riabbina non abbina da solo (solo proposte): segno non letto dal PDF
    // (colonna dare/avere illeggibile, come all'import) o abbinamento annullato a mano (non si rifà lo stesso errore).
    // Gli annullamenti già fatti si ricavano dal registro delle operazioni
    "ALTER TABLE {$prefix}movimenti_banca
        ADD COLUMN segno_incerto TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN abbinamento_annullato TINYINT(1) NOT NULL DEFAULT 0",
    "UPDATE {$prefix}movimenti_banca SET abbinamento_annullato = 1
        WHERE id IN (SELECT CAST(record_id AS UNSIGNED) FROM {$prefix}audit_logs WHERE action = 'ANNULLA' AND table_name = 'movimenti_banca')",

    // v096–v100: termini di pagamento a fine mese con giorno fisso (TerminiPagamento) e piano di fatturazione del cliente.
    // Unindustria: 60 gg d.f.f.m. al 10, fatturazione 50% a 6 mesi e 50% a 12 mesi dall'accettazione.
    // Sulle commesse esistenti cambiano solo i termini (anche delle rate non ancora fatturate), non il piano
    "ALTER TABLE {$prefix}clienti
        ADD COLUMN giorni_pagamento INT DEFAULT NULL COMMENT 'Termini standard: NULL = quelli della commessa',
        ADD COLUMN fine_mese TINYINT(1) NOT NULL DEFAULT 0,
        ADD COLUMN giorno_pagamento TINYINT DEFAULT NULL COMMENT 'Giorno fisso del mese di pagamento',
        ADD COLUMN piano_fatturazione VARCHAR(20) DEFAULT NULL COMMENT 'Chiave di TerminiPagamento::PIANI'",
    "ALTER TABLE {$prefix}incarichi
        ADD COLUMN fine_mese TINYINT(1) NOT NULL DEFAULT 0 AFTER giorni_pagamento,
        ADD COLUMN giorno_pagamento TINYINT DEFAULT NULL AFTER fine_mese",
    "UPDATE {$prefix}clienti SET giorni_pagamento = 60, fine_mese = 1, giorno_pagamento = 10, piano_fatturazione = '6_12'
        WHERE LOWER(ragione_sociale) LIKE '%unindustria%'",
    "UPDATE {$prefix}incarichi i JOIN {$prefix}clienti c ON c.id = i.cliente_id
        SET i.giorni_pagamento = c.giorni_pagamento, i.fine_mese = c.fine_mese, i.giorno_pagamento = c.giorno_pagamento
        WHERE c.giorni_pagamento IS NOT NULL",
    "UPDATE {$prefix}incarichi_rate r JOIN {$prefix}incarichi i ON i.id = r.incarico_id JOIN {$prefix}clienti c ON c.id = i.cliente_id
        SET r.giorni_pagamento = c.giorni_pagamento
        WHERE c.giorni_pagamento IS NOT NULL AND r.fattura_id IS NULL",

    // v101–v103: commesse già registrate dei clienti col piano 6_12 → 50% a 6 mesi e 50% a 12 mesi dalla data della
    // commessa (DATE_ADD tiene il fine mese, come TerminiPagamento::piuMesi). Solo se niente è ancora fatturato:
    // nessuna rata con fattura e nessuna fattura sulla commessa. Le rate nuove entrano con ordine 101–102,
    // poi si tolgono le vecchie e si rinumera
    "INSERT INTO {$prefix}incarichi_rate (incarico_id, ordine, descrizione, percentuale, importo, data_prevista, giorni_pagamento)
        SELECT i.id, 100 + n.k, IF(n.k = 1, 'Acconto 50% a 6 mesi', 'Saldo 50% a 12 mesi'), 50,
            IF(n.k = 1, ROUND(i.importo_totale / 2, 2), i.importo_totale - ROUND(i.importo_totale / 2, 2)),
            DATE_ADD(i.data_incarico, INTERVAL 6 * n.k MONTH), i.giorni_pagamento
        FROM {$prefix}incarichi i
        JOIN {$prefix}clienti c ON c.id = i.cliente_id AND c.piano_fatturazione = '6_12'
        JOIN (SELECT 1 AS k UNION ALL SELECT 2) n
        WHERE i.importo_totale > 0
          AND NOT EXISTS (SELECT 1 FROM {$prefix}incarichi_rate r WHERE r.incarico_id = i.id AND (r.fattura_id IS NOT NULL OR r.ordine > 100))
          AND NOT EXISTS (SELECT 1 FROM {$prefix}fatture f WHERE f.incarico_id = i.id)",
    "DELETE r FROM {$prefix}incarichi_rate r
        JOIN (SELECT DISTINCT incarico_id FROM {$prefix}incarichi_rate WHERE ordine > 100) n ON n.incarico_id = r.incarico_id
        WHERE r.ordine <= 100 AND r.fattura_id IS NULL",
    "UPDATE {$prefix}incarichi_rate SET ordine = ordine - 100 WHERE ordine > 100",

    // v104–v105: tipi di commessa per i viaggi (un contratto per viaggio) e i noleggi a canone
    "ALTER TABLE {$prefix}incarichi MODIFY tipo_commessa ENUM('assistenza','dpo','formazione','nis2','ict','digital','sviluppo_software','viaggio','noleggio','altro') NOT NULL DEFAULT 'assistenza'",
    "ALTER TABLE {$prefix}offerte MODIFY tipo_commessa ENUM('assistenza','dpo','formazione','nis2','ict','digital','sviluppo_software','viaggio','noleggio','altro') NOT NULL DEFAULT 'assistenza'",

    // v106: nomi in banca dei fornitori (beneficiario del bonifico diverso dalla ragione sociale), imparati
    // dall'abbinamento per fornitore (PagamentiFornitore)
    "CREATE TABLE IF NOT EXISTS {$prefix}fornitori_alias (
        id INT AUTO_INCREMENT PRIMARY KEY,
        fornitore_id INT NOT NULL,
        alias VARCHAR(80) NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY uq_fornitori_alias (alias),
        KEY idx_fornitori_alias_fornitore (fornitore_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",

    // v107–v108: scadenze delle fatture aperte al giorno fisso del cliente/commessa (Unindustria: 60 gg d.f.f.m. al 10).
    // Sistemi esporta il fine mese (31/03): il pagamento è il 10/04. Stessa regola di
    // CommessaService::allineaScadenzeGiornoFisso (che il promemoria ripete ogni giorno per i nuovi import)
    "UPDATE {$prefix}fatture f
        LEFT JOIN {$prefix}incarichi i ON i.id = f.incarico_id
        LEFT JOIN {$prefix}clienti c ON c.id = f.cliente_id
        SET f.data_scadenza = IF(DAY(f.data_scadenza) > COALESCE(i.giorno_pagamento, c.giorno_pagamento), (f.data_scadenza - INTERVAL (DAY(f.data_scadenza) - 1) DAY) + INTERVAL 1 MONTH, (f.data_scadenza - INTERVAL (DAY(f.data_scadenza) - 1) DAY)) + INTERVAL LEAST(COALESCE(i.giorno_pagamento, c.giorno_pagamento), DAY(LAST_DAY(IF(DAY(f.data_scadenza) > COALESCE(i.giorno_pagamento, c.giorno_pagamento), (f.data_scadenza - INTERVAL (DAY(f.data_scadenza) - 1) DAY) + INTERVAL 1 MONTH, (f.data_scadenza - INTERVAL (DAY(f.data_scadenza) - 1) DAY))))) - 1 DAY
        WHERE f.stato <> 'pagata' AND f.data_scadenza IS NOT NULL AND COALESCE(i.giorno_pagamento, c.giorno_pagamento) BETWEEN 1 AND 31
          AND DAY(f.data_scadenza) <> LEAST(COALESCE(i.giorno_pagamento, c.giorno_pagamento), DAY(LAST_DAY(f.data_scadenza)))",
    "UPDATE {$prefix}fatture SET stato = 'emessa' WHERE stato = 'scaduta' AND data_scadenza >= CURDATE()",

    // v109: cestino delle commesse (Cestino.php): fotografia JSON di ciò che l'eliminazione toglie, per ripristinarla
    "CREATE TABLE IF NOT EXISTS {$prefix}cestino (
        id INT AUTO_INCREMENT PRIMARY KEY,
        tabella VARCHAR(40) NOT NULL,
        record_id INT NOT NULL,
        descrizione VARCHAR(255) DEFAULT NULL,
        dati LONGTEXT NOT NULL,
        file_refs TEXT DEFAULT NULL COMMENT 'File in storage/documenti da non trattare come orfani',
        user_id INT DEFAULT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        ripristinato_at DATETIME DEFAULT NULL,
        KEY idx_cestino_record (tabella, record_id)
    ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci"
    // NB: le versioni sono per posizione — aggiungere nuove migrazioni SOLO in coda.
];
