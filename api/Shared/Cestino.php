<?php
/**
 * Cestino delle commesse: prima di eliminare una commessa se ne salva la fotografia (commessa, rate, costi,
 * offerte collegate con il loro stato, fatture emesse e ricevute agganciate) nella tabella cestino (v109),
 * così l'eliminazione si può annullare con ripristinaCommessa. Le query del resto dell'ERP non cambiano:
 * la commessa esce davvero da incarichi e torna con lo stesso id.
 * Il PDF della commessa resta in storage/documenti finché la voce è nel cestino (Documenti::pulisciOrfani).
 */
declare(strict_types=1);

class Cestino
{
    /** Elimina la commessa salvandone prima la fotografia. Restituisce l'id della voce di cestino. */
    public static function eliminaCommessa(PDO $pdo, string $p, int $id, ?int $userId = null): int
    {
        $inc = self::righe($pdo, "SELECT * FROM {$p}incarichi WHERE id = ?", [$id]);
        if (!$inc) throw new RuntimeException('Commessa non trovata');
        $dati = [
            'incarico' => $inc[0],
            'rate' => self::righe($pdo, "SELECT * FROM {$p}incarichi_rate WHERE incarico_id = ?", [$id]),
            'costi' => self::righe($pdo, "SELECT * FROM {$p}commessa_costi WHERE incarico_id = ?", [$id]),
            'offerte' => self::righe($pdo, "SELECT id, stato, data_esito, deleted_at FROM {$p}offerte WHERE incarico_id = ?", [$id]),
            'fatture' => array_column(self::righe($pdo, "SELECT id FROM {$p}fatture WHERE incarico_id = ?", [$id]), 'id'),
            'fatture_passive' => array_column(self::righe($pdo, "SELECT id FROM {$p}fatture_passive WHERE incarico_id = ?", [$id]), 'id'),
        ];
        $file = array_values(array_filter(array_merge([$inc[0]['pdf_path'] ?? null], array_column($dati['costi'], 'offerta_fornitore_file')),
            fn($f) => is_string($f) && str_starts_with($f, 'documenti/')));

        $pdo->beginTransaction();
        try {
            $pdo->prepare("INSERT INTO {$p}cestino (tabella, record_id, descrizione, dati, file_refs, user_id) VALUES ('incarichi', ?, ?, ?, ?, ?)")
                ->execute([$id, mb_substr((string)($inc[0]['descrizione'] ?? ''), 0, 255), json_encode($dati, JSON_UNESCAPED_UNICODE),
                    implode("\n", $file), $userId]);
            $cestinoId = (int)$pdo->lastInsertId();
            $pdo->prepare("UPDATE {$p}fatture SET incarico_id = NULL WHERE incarico_id = ?")->execute([$id]);
            $pdo->prepare("UPDATE {$p}fatture_passive SET incarico_id = NULL WHERE incarico_id = ?")->execute([$id]);
            // L'offerta registrata insieme alla commessa (rapida) sparisce con lei; un'offerta vera torna aperta
            $pdo->prepare("UPDATE {$p}offerte SET deleted_at = CURRENT_TIMESTAMP, incarico_id = NULL WHERE incarico_id = ? AND origine = 'rapida'")->execute([$id]);
            $pdo->prepare("UPDATE {$p}offerte SET stato = CASE WHEN data_invio IS NULL THEN 'bozza' ELSE 'inviata' END,
                incarico_id = NULL, data_esito = NULL WHERE incarico_id = ?")->execute([$id]);
            // I costi nati sull'offerta tornano all'offerta, gli altri spariscono con l'incarico
            $pdo->prepare("UPDATE {$p}commessa_costi SET incarico_id = NULL WHERE incarico_id = ? AND offerta_id IS NOT NULL")->execute([$id]);
            $pdo->prepare("DELETE FROM {$p}commessa_costi WHERE incarico_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM {$p}incarichi_rate WHERE incarico_id = ?")->execute([$id]);
            $pdo->prepare("DELETE FROM {$p}incarichi WHERE id = ?")->execute([$id]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $cestinoId;
    }

    /** Rimette la commessa com'era: stesso id, rate, costi, offerte e fatture (solo quelle non passate ad altre commesse). */
    public static function ripristinaCommessa(PDO $pdo, string $p, int $cestinoId): int
    {
        $voce = self::righe($pdo, "SELECT * FROM {$p}cestino WHERE id = ? AND tabella = 'incarichi' AND ripristinato_at IS NULL", [$cestinoId]);
        if (!$voce) throw new RuntimeException('Voce del cestino non trovata o già ripristinata');
        $d = json_decode((string)$voce[0]['dati'], true);
        $id = (int)$d['incarico']['id'];
        if (self::righe($pdo, "SELECT id FROM {$p}incarichi WHERE id = ?", [$id])) throw new RuntimeException("La commessa $id esiste già");

        $pdo->beginTransaction();
        try {
            self::inserisci($pdo, "{$p}incarichi", $d['incarico']);
            foreach ($d['rate'] as $r) self::inserisci($pdo, "{$p}incarichi_rate", $r);
            foreach ($d['costi'] as $c) {
                if (self::righe($pdo, "SELECT id FROM {$p}commessa_costi WHERE id = ?", [$c['id']])) {
                    $pdo->prepare("UPDATE {$p}commessa_costi SET incarico_id = ? WHERE id = ? AND incarico_id IS NULL")->execute([$id, $c['id']]);
                } else {
                    self::inserisci($pdo, "{$p}commessa_costi", $c);
                }
            }
            $off = $pdo->prepare("UPDATE {$p}offerte SET incarico_id = ?, stato = ?, data_esito = ?, deleted_at = ? WHERE id = ? AND incarico_id IS NULL");
            foreach ($d['offerte'] as $o) $off->execute([$id, $o['stato'], $o['data_esito'], $o['deleted_at'], $o['id']]);
            foreach (['fatture' => 'fatture', 'fatture_passive' => 'fatture_passive'] as $k => $t) {
                $st = $pdo->prepare("UPDATE {$p}$t SET incarico_id = ? WHERE id = ? AND incarico_id IS NULL");
                foreach ($d[$k] as $fid) $st->execute([$id, $fid]);
            }
            $pdo->prepare("UPDATE {$p}cestino SET ripristinato_at = CURRENT_TIMESTAMP WHERE id = ?")->execute([$cestinoId]);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            throw $e;
        }
        return $id;
    }

    /** Commesse nel cestino, le più recenti prima. */
    public static function lista(PDO $pdo, string $p): array
    {
        return self::righe($pdo, "SELECT id, tabella, record_id, descrizione, user_id, created_at FROM {$p}cestino
            WHERE ripristinato_at IS NULL ORDER BY id DESC");
    }

    /** File in storage/documenti referenziati da voci del cestino non ripristinate (da non cancellare come orfani). */
    public static function fileTrattenuti(PDO $pdo, string $p): array
    {
        try {
            $refs = $pdo->query("SELECT file_refs FROM {$p}cestino WHERE ripristinato_at IS NULL AND file_refs IS NOT NULL")->fetchAll(PDO::FETCH_COLUMN);
        } catch (PDOException $e) {
            return []; // migrazione non ancora applicata
        }
        return array_values(array_filter(explode("\n", implode("\n", $refs))));
    }

    private static function righe(PDO $pdo, string $sql, array $params = []): array
    {
        $st = $pdo->prepare($sql);
        $st->execute($params);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    }

    /** INSERT con le sole colonne che la tabella ha ancora (lo schema può essere cambiato nel frattempo). */
    private static function inserisci(PDO $pdo, string $tabella, array $riga): void
    {
        $st = $pdo->query("SELECT * FROM $tabella LIMIT 0");
        $colonne = [];
        for ($i = 0; $i < $st->columnCount(); $i++) $colonne[] = $st->getColumnMeta($i)['name'];
        $riga = array_intersect_key($riga, array_flip($colonne));
        $pdo->prepare("INSERT INTO $tabella (" . implode(', ', array_keys($riga)) . ") VALUES ("
            . implode(', ', array_fill(0, count($riga), '?')) . ")")->execute(array_values($riga));
    }
}
