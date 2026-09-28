<?php
/**
 * Spese — le spese di trasferta, unica fonte per vitto, alloggio e ogni altra voce.
 *
 * - perGiorno(): vitto, alloggio e altre spese per data; le trasferte le leggono da qui
 *   (l'indennità dipende da vitto e alloggio rimborsati, TrasferteRegole).
 * - abbinaCarta(): collega le spese pagate con carta ai movimenti dell'estratto carta.
 * - daMovimento(): una spesa della carta non ancora registrata diventa spesa di trasferta.
 * Tracciabilità (L. 207/2024): vitto, alloggio, viaggio e taxi sono deducibili solo se pagati
 * con strumenti tracciabili: in contanti vanno segnalati.
 * SQL portabile (MySQL e SQLite dei test).
 */
declare(strict_types=1);

class Spese
{
    public const CATEGORIE = ['vitto', 'alloggio', 'treno', 'aereo', 'taxi', 'pedaggio', 'parcheggio', 'carburante', 'altro'];
    public const METODI = ['carta', 'bancomat', 'bonifico', 'contanti', 'altro'];
    /** Categorie per cui conta la tracciabilità del pagamento. */
    public const DA_TRACCIARE = ['vitto', 'alloggio', 'treno', 'aereo', 'taxi'];
    /** Giorni di scarto tra la data della spesa e quella del movimento della carta. */
    private const SCARTO_GIORNI = 3;

    private $pdo;
    private $p;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->p = $prefix;
    }

    /** [data => ['vitto' => €, 'alloggio' => €, 'altre' => €]] nel periodo. */
    public function perGiorno(string $dal, string $al): array
    {
        $stmt = $this->pdo->prepare("SELECT data, categoria, SUM(importo) AS tot FROM {$this->p}spese
            WHERE deleted_at IS NULL AND data BETWEEN ? AND ? GROUP BY data, categoria");
        $stmt->execute([$dal, $al]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d = (string)$r['data'];
            $out[$d] ??= ['vitto' => 0.0, 'alloggio' => 0.0, 'altre' => 0.0];
            $k = in_array($r['categoria'], ['vitto', 'alloggio'], true) ? $r['categoria'] : 'altre';
            $out[$d][$k] += (float)$r['tot'];
        }
        return $out;
    }

    /**
     * Mette sulle righe di trasferta le spese del loro giorno (sulla prima riga della giornata, zero
     * sulle altre): così tabella, indennità e PDF continuano a leggere vitto/alloggio dalla riga.
     */
    public static function applicaAlleTrasferte(array $righe, array $perGiorno): array
    {
        $visti = [];
        foreach ($righe as &$r) {
            $d = (string)$r['data_trasferta'];
            $g = isset($visti[$d]) ? ['vitto' => 0, 'alloggio' => 0, 'altre' => 0] : ($perGiorno[$d] ?? ['vitto' => 0, 'alloggio' => 0, 'altre' => 0]);
            $visti[$d] = true;
            $r['vitto'] = round((float)$g['vitto'], 2);
            $r['alloggio'] = round((float)$g['alloggio'], 2);
            $r['altre_spese'] = round((float)$g['altre'], 2);
        }
        unset($r);
        return $righe;
    }

    /** Spese del periodo, con il movimento della carta abbinato. */
    public function elenco(string $dal, string $al): array
    {
        $stmt = $this->pdo->prepare("SELECT s.*, c.ragione_sociale AS cliente_nome,
                m.data_operazione AS movimento_data, m.descrizione AS movimento_descrizione
            FROM {$this->p}spese s
            LEFT JOIN {$this->p}clienti c ON c.id = s.cliente_id
            LEFT JOIN {$this->p}movimenti_banca m ON m.id = s.movimento_id
            WHERE s.deleted_at IS NULL AND s.data BETWEEN ? AND ?
            ORDER BY s.data DESC, s.id DESC");
        $stmt->execute([$dal, $al]);
        $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($righe as &$r) {
            $r['tracciabile'] = $r['metodo'] !== 'contanti';
            $r['da_segnalare'] = !$r['tracciabile'] && in_array($r['categoria'], self::DA_TRACCIARE, true);
            $r['ha_documento'] = !empty($r['documento']);
            unset($r['documento']);
        }
        unset($r);
        return $righe;
    }

    /**
     * Collega le spese pagate con carta ai movimenti dell'estratto carta: stesso importo, data entro
     * SCARTO_GIORNI, movimento non già usato. Solo abbinamenti senza ambiguità. @return int abbinate
     */
    public function abbinaCarta(): int
    {
        $spese = $this->pdo->query("SELECT id, data, importo FROM {$this->p}spese
            WHERE deleted_at IS NULL AND movimento_id IS NULL AND metodo = 'carta'")->fetchAll(PDO::FETCH_ASSOC);
        $n = 0;
        foreach ($spese as $s) {
            $dal = date('Y-m-d', strtotime($s['data'] . ' -' . self::SCARTO_GIORNI . ' days'));
            $al = date('Y-m-d', strtotime($s['data'] . ' +' . self::SCARTO_GIORNI . ' days'));
            $stmt = $this->pdo->prepare("SELECT m.id FROM {$this->p}movimenti_banca m
                WHERE m.origine = 'estratto_carta' AND ABS(m.importo + ?) < 0.005 AND m.data_operazione BETWEEN ? AND ?
                  AND NOT EXISTS (SELECT 1 FROM {$this->p}spese x WHERE x.movimento_id = m.id AND x.deleted_at IS NULL)");
            $stmt->execute([(float)$s['importo'], $dal, $al]);
            $ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) !== 1) continue;
            $this->pdo->prepare("UPDATE {$this->p}spese SET movimento_id = ? WHERE id = ? AND movimento_id IS NULL")->execute([$ids[0], $s['id']]);
            $n++;
        }
        return $n;
    }

    /** Uscite della carta nel periodo non ancora registrate come spese. */
    public function movimentiCartaLiberi(string $dal, string $al): array
    {
        $stmt = $this->pdo->prepare("SELECT m.id, m.data_operazione, m.importo, m.descrizione, m.controparte
            FROM {$this->p}movimenti_banca m
            WHERE m.origine = 'estratto_carta' AND m.importo < 0 AND m.data_operazione BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM {$this->p}spese s WHERE s.movimento_id = m.id AND s.deleted_at IS NULL)
            ORDER BY m.data_operazione DESC");
        $stmt->execute([$dal, $al]);
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }

    /** Spesa di trasferta dal movimento della carta. @return int id della spesa */
    public function daMovimento(int $movimentoId, string $categoria, ?int $clienteId = null): int
    {
        $stmt = $this->pdo->prepare("SELECT id, data_operazione, importo, descrizione, controparte FROM {$this->p}movimenti_banca
            WHERE id = ? AND origine = 'estratto_carta' AND importo < 0");
        $stmt->execute([$movimentoId]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new RuntimeException('Movimento della carta non trovato');
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->p}spese WHERE movimento_id = ? AND deleted_at IS NULL");
        $stmt->execute([$movimentoId]);
        if ($stmt->fetchColumn()) throw new RuntimeException('Il movimento è già registrato come spesa');
        if (!in_array($categoria, self::CATEGORIE, true)) $categoria = 'altro';
        $this->pdo->prepare("INSERT INTO {$this->p}spese (data, categoria, descrizione, esercente, importo, metodo, cliente_id, movimento_id, origine)
            VALUES (?, ?, ?, ?, ?, 'carta', ?, ?, 'carta')")
            ->execute([$m['data_operazione'], $categoria, mb_substr((string)$m['descrizione'], 0, 255), mb_substr((string)($m['controparte'] ?? ''), 0, 150) ?: null,
                round(abs((float)$m['importo']), 2), $clienteId, $movimentoId]);
        return (int)$this->pdo->lastInsertId();
    }
}
