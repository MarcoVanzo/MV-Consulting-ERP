<?php

/**
 * PDO con transazioni annidabili: una beginTransaction() dentro un'altra diventa un SAVEPOINT.
 * Serve all'anteprima degli import (Anteprima.php), che esegue l'import vero dentro una
 * transazione esterna e poi la annulla, anche quando l'import apre una sua transazione.
 */
class MvPdo extends PDO {
    private int $livello = 0;

    public function beginTransaction(): bool {
        if ($this->livello === 0) {
            $ok = parent::beginTransaction();
            if ($ok) $this->livello = 1;
            return $ok;
        }
        $this->livello++;
        $this->exec('SAVEPOINT mv_sp' . $this->livello);
        return true;
    }

    public function commit(): bool {
        if ($this->livello <= 1) {
            $this->livello = 0;
            return parent::commit();
        }
        $this->exec('RELEASE SAVEPOINT mv_sp' . $this->livello);
        $this->livello--;
        return true;
    }

    public function rollBack(): bool {
        if ($this->livello <= 1) {
            $this->livello = 0;
            return parent::rollBack();
        }
        $this->exec('ROLLBACK TO SAVEPOINT mv_sp' . $this->livello);
        $this->livello--;
        return true;
    }
}

class Database {
    private static $pdo = null;

    public static function getConnection() {
        if (self::$pdo === null) {
            $host = getenv('DB_HOST');
            $dbName = getenv('DB_NAME');
            $user = getenv('DB_USER');
            $pass = getenv('DB_PASS');

            try {
                self::$pdo = new MvPdo(
                    "mysql:host=$host;dbname=$dbName;charset=utf8mb4",
                    $user,
                    $pass,
                    [
                        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
                        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
                        PDO::ATTR_EMULATE_PREPARES   => false,
                        PDO::ATTR_TIMEOUT            => 5,
                    ]
                );
            } catch (PDOException $e) {
                throw new Exception('Database connection failed: ' . $e->getMessage());
            }
        }
        return self::$pdo;
    }
}
