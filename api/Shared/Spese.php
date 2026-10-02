<?php
/**
 * Spese — le spese di trasferta, unica fonte per vitto, alloggio e ogni altra voce.
 *
 * - perGiorno(): vitto, alloggio e altre spese per data; le trasferte le leggono da qui
 *   (l'indennità dipende da vitto e alloggio rimborsati, TrasferteRegole).
 * - abbinaCarta(): collega le spese pagate con carta ai movimenti dell'estratto carta.
 * - Carburante doppio: un pieno di tasca propria in un giorno con rimborso km è già pagato dalla tariffa
 *   ACI (che comprende il carburante): si rendiconta ma non entra in da_rimborsare (perGiorno, perMetodo).
 * - daMovimento(): una spesa della carta non ancora registrata diventa spesa di trasferta.
 * Tracciabilità (L. 207/2024): vitto, alloggio, viaggio e taxi sono deducibili solo se pagati
 * con strumenti tracciabili: in contanti vanno segnalati.
 * SQL portabile (MySQL e SQLite dei test).
 */
declare(strict_types=1);

class Spese
{
    public const CATEGORIE = ['vitto', 'alloggio', 'treno', 'aereo', 'taxi', 'pedaggio', 'parcheggio', 'carburante', 'altro'];
    public const METODI = ['carta', 'carta_personale', 'bancomat', 'bonifico', 'contanti', 'altro'];
    /** Pagate dalla società (carta aziendale, bonifico): si rendicontano ma non si rimborsano. */
    public const AZIENDALI = ['carta', 'bonifico'];
    /** Finestra dell'abbinamento automatico alla carta (le spese più vecchie non si ritentano). */
    private const GIORNI_ABBINAMENTO = 120;
    /** Categorie per cui conta la tracciabilità del pagamento. */
    public const DA_TRACCIARE = ['vitto', 'alloggio', 'treno', 'aereo', 'taxi'];
    /** Giorni di scarto tra la data della spesa e quella del movimento della carta. */
    private const SCARTO_GIORNI = 3;

    private $pdo;
    private $p;
    /** Costo al km generale (false = da leggere in settings alla prima richiesta). */
    private float|null|false $costoKm;

    /** $costoKm: costo al km generale già noto (null = non impostato); omesso, si legge da settings. */
    public function __construct(PDO $pdo, string $prefix, float|null|false $costoKm = false)
    {
        $this->pdo = $pdo;
        $this->p = $prefix;
        $this->costoKm = $costoKm;
    }

    /**
     * Giorni del periodo con km davvero rimborsati: km > 0 e un costo al km che esiste (quello ACI del mezzo,
     * altrimenti quello generale). Senza costo non c'è rimborso km e il pieno di tasca propria si rimborsa.
     * @return array<string, true> data => true
     */
    public function giorniConKm(string $dal, string $al): array
    {
        if ($this->costoKm === false) {
            try {
                $stmt = $this->pdo->prepare("SELECT setting_value FROM {$this->p}settings WHERE setting_key = 'trasferte_costo_km'");
                $stmt->execute();
                $v = $stmt->fetchColumn();
                $this->costoKm = $v === false || $v === null || $v === '' ? null : (float)$v;
            } catch (PDOException $e) {
                $this->costoKm = null;
            }
        }
        $stmt = $this->pdo->prepare("SELECT DISTINCT t.data_trasferta FROM {$this->p}trasferte t
            LEFT JOIN {$this->p}mezzi m ON m.id = t.mezzo_id
            WHERE t.data_trasferta BETWEEN ? AND ? AND (t.km_andata + t.km_ritorno) > 0 AND COALESCE(m.costo_km, CAST(? AS DECIMAL(10,4))) > 0");
        $stmt->execute([$dal, $al, $this->costoKm ?? 0]);
        return array_fill_keys(array_map('strval', $stmt->fetchAll(PDO::FETCH_COLUMN)), true);
    }

    /** Carburante pagato di tasca propria in un giorno con rimborso km: già compreso nella tariffa ACI. */
    public static function carburanteDoppio(string $categoria, string $metodo, string $data, array $conKm): bool
    {
        return $categoria === 'carburante' && !in_array($metodo, self::AZIENDALI, true) && isset($conKm[$data]);
    }

    /**
     * Primo mese già presentato in nota spese tra le date indicate (AAAA-MM), o null. Una nota presentata
     * ha i totali congelati: spese, km e mezzo di quel mese non cambiano finché non la si riapre.
     */
    public static function mesePresentato(PDO $pdo, string $prefix, array $date): ?string
    {
        $mesi = array_values(array_unique(array_filter(array_map(fn($d) => substr((string)$d, 0, 7), $date))));
        if (!$mesi) return null;
        try {
            $stmt = $pdo->prepare("SELECT mese FROM {$prefix}rimborsi WHERE mese IN (" . implode(',', array_fill(0, count($mesi), '?')) . ") LIMIT 1");
            $stmt->execute($mesi);
            $m = $stmt->fetchColumn();
        } catch (PDOException $e) {
            return null; // tabella non ancora migrata
        }
        return $m ? substr((string)$m, 5, 2) . '/' . substr((string)$m, 0, 4) : null;
    }

    /**
     * [data => ['vitto' => €, 'alloggio' => €, 'altre' => €, 'aziendali' => € pagati dalla società,
     * 'escluse' => € di carburante doppio]] nel periodo. Da rimborsare = vitto + alloggio + altre − aziendali − escluse.
     */
    public function perGiorno(string $dal, string $al): array
    {
        $stmt = $this->pdo->prepare("SELECT data, categoria, metodo, SUM(importo) AS tot FROM {$this->p}spese
            WHERE deleted_at IS NULL AND data BETWEEN ? AND ? GROUP BY data, categoria, metodo");
        $stmt->execute([$dal, $al]);
        $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $conKm = $righe ? $this->giorniConKm($dal, $al) : [];
        $out = [];
        foreach ($righe as $r) {
            $d = (string)$r['data'];
            $out[$d] ??= ['vitto' => 0.0, 'alloggio' => 0.0, 'altre' => 0.0, 'aziendali' => 0.0, 'escluse' => 0.0];
            $k = in_array($r['categoria'], ['vitto', 'alloggio'], true) ? $r['categoria'] : 'altre';
            $out[$d][$k] += (float)$r['tot'];
            if (in_array($r['metodo'] ?? '', self::AZIENDALI, true)) $out[$d]['aziendali'] += (float)$r['tot'];
            elseif (self::carburanteDoppio((string)$r['categoria'], (string)$r['metodo'], $d, $conKm)) $out[$d]['escluse'] += (float)$r['tot'];
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
            $zero = ['vitto' => 0, 'alloggio' => 0, 'altre' => 0, 'aziendali' => 0, 'escluse' => 0];
            $g = isset($visti[$d]) ? $zero : ($perGiorno[$d] ?? $zero) + $zero;
            $visti[$d] = true;
            $r['vitto'] = round((float)$g['vitto'], 2);
            $r['alloggio'] = round((float)$g['alloggio'], 2);
            $r['altre_spese'] = round((float)$g['altre'], 2);
            $r['spese_aziendali'] = round((float)$g['aziendali'], 2);
            $r['spese_escluse'] = round((float)$g['escluse'], 2);
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
        // Giorni con km rimborsati (e un costo al km): la tariffa ACI comprende già il carburante
        $conKm = $righe ? $this->giorniConKm($dal, $al) : [];
        foreach ($righe as &$r) {
            $r['tracciabile'] = $r['metodo'] !== 'contanti';
            // Pieno di tasca propria nel giorno del rimborso km: si rendiconta ma non si rimborsa una seconda volta
            $r['carburante_doppio'] = self::carburanteDoppio((string)$r['categoria'], (string)$r['metodo'], (string)$r['data'], $conKm);
            $r['rimborsabile'] = !in_array($r['metodo'], self::AZIENDALI, true) && !$r['carburante_doppio'];
            $r['da_segnalare'] = !$r['tracciabile'] && in_array($r['categoria'], self::DA_TRACCIARE, true);
            $r['ha_documento'] = !empty($r['documento']);
            unset($r['documento']);
        }
        unset($r);
        return $righe;
    }

    /**
     * Collega le spese pagate con la carta aziendale ai movimenti dell'estratto carta: stesso importo, data
     * entro SCARTO_GIORNI, movimento non già usato. Solo abbinamenti senza ambiguità da entrambi i lati:
     * una sola uscita candidata per la spesa e una sola spesa candidata per l'uscita. @return int abbinate
     */
    public function abbinaCarta(): int
    {
        $dalAbb = date('Y-m-d', strtotime('-' . self::GIORNI_ABBINAMENTO . ' days'));
        $stmt = $this->pdo->prepare("SELECT id, data, importo FROM {$this->p}spese
            WHERE deleted_at IS NULL AND movimento_id IS NULL AND metodo = 'carta' AND data >= ?");
        $stmt->execute([$dalAbb]);
        $spese = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$spese) return 0;
        $candMov = $this->pdo->prepare("SELECT m.id FROM {$this->p}movimenti_banca m
            WHERE m.origine = 'estratto_carta' AND ABS(m.importo + ?) < 0.005 AND m.data_operazione BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM {$this->p}spese x WHERE x.movimento_id = m.id AND x.deleted_at IS NULL)");
        $movData = $this->pdo->prepare("SELECT data_operazione, importo FROM {$this->p}movimenti_banca WHERE id = ?");
        $candSpese = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->p}spese
            WHERE deleted_at IS NULL AND movimento_id IS NULL AND metodo = 'carta' AND ABS(importo + ?) < 0.005 AND data BETWEEN ? AND ?");
        $collega = $this->pdo->prepare("UPDATE {$this->p}spese SET movimento_id = ? WHERE id = ? AND movimento_id IS NULL");
        $finestra = fn(string $d) => [date('Y-m-d', strtotime("$d -" . self::SCARTO_GIORNI . ' days')), date('Y-m-d', strtotime("$d +" . self::SCARTO_GIORNI . ' days'))];
        $n = 0;
        foreach ($spese as $s) {
            [$dal, $al] = $finestra((string)$s['data']);
            $candMov->execute([(float)$s['importo'], $dal, $al]);
            $ids = $candMov->fetchAll(PDO::FETCH_COLUMN);
            if (count($ids) !== 1) continue;
            $movData->execute([$ids[0]]);
            $m = $movData->fetch(PDO::FETCH_ASSOC);
            [$dalM, $alM] = $finestra((string)$m['data_operazione']);
            $candSpese->execute([(float)$m['importo'], $dalM, $alM]);
            if ((int)$candSpese->fetchColumn() !== 1) continue; // due spese uguali vicine: lo decide l'utente
            $collega->execute([$ids[0], $s['id']]);
            $n++;
        }
        return $n;
    }

    /** Spese da rimborsare in giorni senza trasferta (treno o hotel del giorno prima…), per tabella e PDF. */
    public static function fuoriGiornata(array $perGiorno, array $giorniTrasferta): float
    {
        $tot = 0.0;
        foreach ($perGiorno as $d => $g) {
            if (!isset($giorniTrasferta[$d])) $tot += $g['vitto'] + $g['alloggio'] + $g['altre'] - ($g['aziendali'] ?? 0) - ($g['escluse'] ?? 0);
        }
        return round($tot, 2);
    }

    /**
     * Spese del periodo pagate dalla società, da rimborsare (di tasca propria) e carburante doppio (di tasca
     * propria in un giorno con rimborso km: escluso da da_rimborsare). Stessa regola di perGiorno().
     */
    public function perMetodo(string $dal, string $al): array
    {
        $out = ['aziendali' => 0.0, 'da_rimborsare' => 0.0, 'carburante_doppio' => 0.0];
        foreach ($this->perGiorno($dal, $al) as $g) {
            $out['aziendali'] += $g['aziendali'];
            $out['carburante_doppio'] += $g['escluse'];
            $out['da_rimborsare'] += $g['vitto'] + $g['alloggio'] + $g['altre'] - $g['aziendali'] - $g['escluse'];
        }
        return array_map(fn($v) => round($v, 2), $out);
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
