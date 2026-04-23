<?php
/**
 * MezziController
 * Gestione flotta aziendale per MV Consulting ERP
 */

require_once __DIR__ . '/../Shared/Database.php';
require_once __DIR__ . '/../Shared/Response.php';

class MezziController {
    private $pdo;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    public function getAllVehicles() {
        try {
            $stmt = $this->pdo->query("SELECT * FROM {$this->prefix}mezzi ORDER BY nome ASC");
            $mezzi = $stmt->fetchAll();

            // Calcola anomalie aperte per mezzo
            $stmtAnom = $this->pdo->query("SELECT mezzo_id, COUNT(*) as open_anomalies FROM {$this->prefix}mezzi_anomalie WHERE stato != 'resolved' GROUP BY mezzo_id");
            $anomCounts = [];
            while ($row = $stmtAnom->fetch()) {
                $anomCounts[$row['mezzo_id']] = (int)$row['open_anomalies'];
            }

            foreach ($mezzi as &$mezzo) {
                $mezzo['open_anomalies'] = $anomCounts[$mezzo['id']] ?? 0;
            }

            Response::json(true, "Mezzi recuperati", $mezzi);
        } catch (PDOException $e) {
            Response::json(false, "Errore DB: " . $e->getMessage());
        }
    }

    public function getVehicleById() {
        $id = $_GET['id'] ?? null;
        if (!$id) Response::json(false, "ID mancante");

        try {
            $stmt = $this->pdo->prepare("SELECT * FROM {$this->prefix}mezzi WHERE id = ?");
            $stmt->execute([$id]);
            $mezzo = $stmt->fetch();
            
            if (!$mezzo) Response::json(false, "Mezzo non trovato");

            $stmtMaint = $this->pdo->prepare("SELECT * FROM {$this->prefix}mezzi_manutenzioni WHERE mezzo_id = ? ORDER BY data_manutenzione DESC");
            $stmtMaint->execute([$id]);
            $mezzo['maintenance'] = $stmtMaint->fetchAll();

            $stmtAnom = $this->pdo->prepare("
                SELECT a.*, u.full_name as reporter_name 
                FROM {$this->prefix}mezzi_anomalie a 
                LEFT JOIN {$this->prefix}users u ON a.segnalatore_id = u.id 
                WHERE a.mezzo_id = ? 
                ORDER BY a.data_segnalazione DESC
            ");
            $stmtAnom->execute([$id]);
            $mezzo['anomalies'] = $stmtAnom->fetchAll();

            Response::json(true, "Mezzo recuperato", $mezzo);
        } catch (PDOException $e) {
            Response::json(false, "Errore DB: " . $e->getMessage());
        }
    }

    public function createVehicle($data) {
        if (!$data || empty($data['nome']) || empty($data['targa'])) {
            Response::json(false, "Nome e Targa obbligatori");
        }

        try {
            $sql = "INSERT INTO {$this->prefix}mezzi (nome, targa, capacita, stato, scadenza_assicurazione, scadenza_bollo, note) VALUES (?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $data['nome'],
                $data['targa'],
                $data['capacita'] ?? 9,
                $data['stato'] ?? 'attivo',
                $data['scadenza_assicurazione'] ?? null,
                $data['scadenza_bollo'] ?? null,
                $data['note'] ?? null
            ]);
            Response::json(true, "Mezzo inserito con successo", ["id" => $this->pdo->lastInsertId()]);
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                Response::json(false, "La targa inserita è già presente nel sistema");
            }
            Response::json(false, "Errore DB: " . $e->getMessage());
        }
    }

    public function updateVehicle($data) {
        if (!$data || empty($data['id']) || empty($data['nome']) || empty($data['targa'])) {
            Response::json(false, "Dati mancanti per l'aggiornamento");
        }

        try {
            $sql = "UPDATE {$this->prefix}mezzi SET nome=?, targa=?, capacita=?, stato=?, scadenza_assicurazione=?, scadenza_bollo=?, note=? WHERE id=?";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $data['nome'],
                $data['targa'],
                $data['capacita'] ?? 9,
                $data['stato'] ?? 'attivo',
                $data['scadenza_assicurazione'] ?? null,
                $data['scadenza_bollo'] ?? null,
                $data['note'] ?? null,
                $data['id']
            ]);
            Response::json(true, "Mezzo aggiornato");
        } catch (PDOException $e) {
            if ($e->errorInfo[1] == 1062) {
                Response::json(false, "La targa inserita è già presente nel sistema");
            }
            Response::json(false, "Errore DB: " . $e->getMessage());
        }
    }

    public function deleteVehicle($data) {
        if (!$data || empty($data['id'])) Response::json(false, "ID mezzo mancante");

        try {
            $stmt = $this->pdo->prepare("DELETE FROM {$this->prefix}mezzi WHERE id = ?");
            $stmt->execute([$data['id']]);
            Response::json(true, "Mezzo eliminato");
        } catch (PDOException $e) {
            Response::json(false, "Errore DB: " . $e->getMessage());
        }
    }

    public function addMaintenance($data) {
        if (!$data || empty($data['vehicle_id']) || empty($data['maintenance_date']) || empty($data['type'])) {
            Response::json(false, "Dati manutenzione mancanti");
        }

        try {
            $sql = "INSERT INTO {$this->prefix}mezzi_manutenzioni (mezzo_id, data_manutenzione, tipo, descrizione, costo, chilometraggio, prossima_scadenza_data, prossima_scadenza_km) VALUES (?, ?, ?, ?, ?, ?, ?, ?)";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $data['vehicle_id'],
                $data['maintenance_date'],
                $data['type'],
                $data['description'] ?? null,
                $data['cost'] ?? 0,
                $data['mileage'] ?? null,
                $data['next_maintenance_date'] ?? null,
                $data['next_maintenance_mileage'] ?? null
            ]);
            Response::json(true, "Manutenzione registrata");
        } catch (PDOException $e) {
            Response::json(false, "Errore DB: " . $e->getMessage());
        }
    }

    public function addAnomaly($data) {
        if (!$data || empty($data['vehicle_id']) || empty($data['description'])) {
            Response::json(false, "Dati anomalia mancanti");
        }
        
        // Supponiamo di estrarre l'ID utente dal token (non implementato globalmente nel mock, passiamo null o lo prendiamo dalla session se presente)
        $userId = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : null;

        try {
            $sql = "INSERT INTO {$this->prefix}mezzi_anomalie (mezzo_id, segnalatore_id, descrizione, gravita, stato) VALUES (?, ?, ?, ?, 'open')";
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([
                $data['vehicle_id'],
                $userId,
                $data['description'],
                $data['severity'] ?? 'medium'
            ]);
            Response::json(true, "Anomalia registrata");
        } catch (PDOException $e) {
            Response::json(false, "Errore DB: " . $e->getMessage());
        }
    }

    public function updateAnomalyStatus($data) {
        if (!$data || empty($data['id']) || empty($data['status'])) {
            Response::json(false, "Dati anomalia mancanti per aggiornamento stato");
        }

        try {
            $sql = "UPDATE {$this->prefix}mezzi_anomalie SET stato = ?";
            $params = [$data['status']];

            if ($data['status'] === 'resolved') {
                $sql .= ", note_risoluzione = ?, data_risoluzione = NOW()";
                $params[] = $data['resolution_notes'] ?? null;
            }

            $sql .= " WHERE id = ?";
            $params[] = $data['id'];

            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($params);
            
            Response::json(true, "Stato anomalia aggiornato");
        } catch (PDOException $e) {
            Response::json(false, "Errore DB: " . $e->getMessage());
        }
    }
}
