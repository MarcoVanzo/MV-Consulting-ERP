<?php
/**
 * Sottoclienti Controller — CRUD per sotto-entità (es. Unindustria)
 */

class SottoclientiController {
    private $pdo;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    public function listByCliente($clienteId) {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->prefix}sottoclienti WHERE cliente_id = ? ORDER BY nome ASC");
        $stmt->execute([$clienteId]);
        Response::json(true, '', $stmt->fetchAll());
    }

    public function save($data) {
        $id = $data['id'] ?? null;
        $fields = [
            'cliente_id'  => (int)($data['cliente_id'] ?? 0),
            'nome'        => trim($data['nome'] ?? ''),
            'riferimento' => trim($data['riferimento'] ?? ''),
            'indirizzo'   => trim($data['indirizzo'] ?? ''),
            'citta'       => trim($data['citta'] ?? ''),
            'cap'         => trim($data['cap'] ?? ''),
            'provincia'   => trim($data['provincia'] ?? ''),
            'telefono'    => trim($data['telefono'] ?? ''),
            'email'       => trim($data['email'] ?? ''),
            'note'        => trim($data['note'] ?? '')
        ];

        if (empty($fields['nome'])) {
            Response::json(false, 'Nome sottocliente obbligatorio');
        }
        if (empty($fields['cliente_id'])) {
            Response::json(false, 'cliente_id obbligatorio');
        }

        if ($id) {
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = "$k = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $sql = "UPDATE {$this->prefix}sottoclienti SET " . implode(', ', $sets) . " WHERE id = ?";
            $this->pdo->prepare($sql)->execute($vals);
            Response::json(true, 'Sottocliente aggiornato', ['id' => $id]);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO {$this->prefix}sottoclienti ($cols) VALUES ($placeholders)";
            $this->pdo->prepare($sql)->execute(array_values($fields));
            Response::json(true, 'Sottocliente creato', ['id' => $this->pdo->lastInsertId()]);
        }
    }

    public function delete($id) {
        $this->pdo->prepare("DELETE FROM {$this->prefix}sottoclienti WHERE id = ?")->execute([$id]);
        Response::json(true, 'Sottocliente eliminato');
    }
}
