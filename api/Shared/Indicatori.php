<?php
/**
 * Indicatori — l'unico posto dove si calcolano i numeri di sintesi dell'ERP.
 *
 * Le definizioni sono in docs/indicatori.md: le schede (Fatture, Commesse, Offerte, Scadenzario,
 * dashboard) leggono da qui invece di ricalcolare ciascuna la sua versione.
 *
 * Due ambiti:
 * - periodo  ($anno valorizzato): documenti emessi / commesse acquisite / offerte fatte nell'anno;
 * - situazione ($anno null): tutto ciò che è aperto oggi, di qualunque anno.
 *
 * Una fattura con più sottoclienti è salvata in più record (vedi RiconciliazioneDocumenti):
 * gli importi si sommano per record, i conteggi per documento (numero + anno + cliente).
 *
 * SQL volutamente portabile (MySQL e SQLite dei test): niente YEAR(), GREATEST(), CURDATE().
 */
declare(strict_types=1);

class Indicatori
{
    private $pdo;
    private $p;
    private $oggi;

    public function __construct(PDO $pdo, string $prefix, ?string $oggi = null)
    {
        $this->pdo = $pdo;
        $this->p = $prefix;
        $this->oggi = $oggi ?? date('Y-m-d');
    }

    /** Chiave in settings dei clienti che l'utente ha tolto dai conteggi delle fatture (scelta per utente). */
    public static function chiaveClientiEsclusi(): string
    {
        return 'fatture_clienti_esclusi_u' . (int)($GLOBALS['userContext']['id'] ?? 0);
    }

    /** Id dei clienti esclusi dai conteggi dell'utente corrente (0 = fatture senza cliente). */
    public function clientiEsclusi(): array
    {
        try {
            $stmt = $this->pdo->prepare("SELECT setting_value FROM {$this->p}settings WHERE setting_key = ?");
            $stmt->execute([self::chiaveClientiEsclusi()]);
            $v = json_decode((string)$stmt->fetchColumn(), true);
            return is_array($v) ? array_values(array_map('intval', $v)) : [];
        } catch (PDOException $e) {
            return [];
        }
    }

    /**
     * Fatture emesse.
     * fatturato = imponibile · totale = IVA inclusa · incassato/da_incassare/scaduto = IVA inclusa (quello che paga il cliente)
     */
    public function fatture(?int $anno = null, ?array $esclusi = null): array
    {
        $esclusi = $esclusi ?? $this->clientiEsclusi();
        [$where, $params] = $this->periodo('data_emissione', $anno);
        if ($esclusi) {
            $where .= ' AND COALESCE(cliente_id, 0) NOT IN (' . implode(',', array_map('intval', $esclusi)) . ')';
        }
        $sql = "SELECT
                COALESCE(SUM(imponibile), 0) AS fatturato,
                COALESCE(SUM(totale), 0) AS totale,
                COALESCE(SUM(incassato), 0) AS incassato,
                COALESCE(SUM(aperto), 0) AS da_incassare,
                COALESCE(SUM(scaduto), 0) AS scaduto,
                COUNT(*) AS num_documenti,
                COUNT(CASE WHEN aperto < 0.005 AND totale > 0 THEN 1 END) AS num_incassati,
                COUNT(CASE WHEN aperto >= 0.005 THEN 1 END) AS num_da_incassare,
                COUNT(CASE WHEN scaduto >= 0.005 THEN 1 END) AS num_scaduti
            FROM (
                SELECT SUM(imponibile) AS imponibile, SUM(importo_totale) AS totale,
                    SUM(CASE WHEN stato = 'pagata' THEN importo_totale ELSE 0 END) AS incassato,
                    SUM(CASE WHEN stato <> 'pagata' THEN importo_totale ELSE 0 END) AS aperto,
                    SUM(CASE WHEN stato <> 'pagata' AND data_scadenza IS NOT NULL AND data_scadenza < ? THEN importo_totale ELSE 0 END) AS scaduto
                FROM {$this->p}fatture
                WHERE $where
                GROUP BY numero_fattura, COALESCE(cliente_id, 0), SUBSTR(data_emissione, 1, 4)
            ) d";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute(array_merge([$this->oggi], $params));
        return $this->numeri($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    /**
     * Commesse (incarichi): tutto al netto IVA, confrontabile col valore della commessa.
     * da_fatturare = valore non ancora coperto da fatture, mai negativo.
     */
    public function commesse(?int $anno = null): array
    {
        [$where, $params] = $this->periodo('i.data_incarico', $anno);
        $sql = "SELECT COUNT(*) AS num_commesse,
                COALESCE(SUM(i.importo_totale), 0) AS valore,
                COALESCE(SUM(COALESCE(f.fatturato, 0)), 0) AS fatturato,
                COALESCE(SUM(COALESCE(f.incassato, 0)), 0) AS incassato_netto,
                COALESCE(SUM(CASE WHEN i.importo_totale - COALESCE(f.fatturato, 0) > 0
                    THEN i.importo_totale - COALESCE(f.fatturato, 0) ELSE 0 END), 0) AS da_fatturare,
                COUNT(CASE WHEN i.importo_totale - COALESCE(f.fatturato, 0) >= 0.01 THEN 1 END) AS num_da_fatturare
            FROM {$this->p}incarichi i
            LEFT JOIN (SELECT incarico_id, SUM(imponibile) AS fatturato,
                    SUM(CASE WHEN stato = 'pagata' THEN imponibile ELSE 0 END) AS incassato
                FROM {$this->p}fatture WHERE incarico_id IS NOT NULL GROUP BY incarico_id) f ON f.incarico_id = i.id
            WHERE $where";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $this->numeri($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    /** Rate di commessa senza fattura con data prevista entro $giorni (le rate senza data contano sempre). */
    public function rateDaFatturare(int $giorni = 30): array
    {
        $limite = date('Y-m-d', strtotime($this->oggi . " +$giorni days"));
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(importo), 0) AS importo, COUNT(*) AS num_rate
            FROM {$this->p}incarichi_rate WHERE fattura_id IS NULL AND (data_prevista IS NULL OR data_prevista <= ?)");
        $stmt->execute([$limite]);
        return $this->numeri(($stmt->fetch(PDO::FETCH_ASSOC) ?: []) + ['giorni' => $giorni]);
    }

    /**
     * Offerte. pipeline = offerte inviate e non ancora decise (le bozze no: non le ha viste nessuno).
     * tasso_conversione = accettate / (accettate + perse), in percentuale.
     */
    public function offerte(?int $anno = null): array
    {
        [$where, $params] = $this->periodo('data_offerta', $anno);
        $stmt = $this->pdo->prepare("SELECT
                COUNT(CASE WHEN stato <> 'sostituita' THEN 1 END) AS num_offerte,
                COALESCE(SUM(CASE WHEN stato = 'inviata' THEN imponibile ELSE 0 END), 0) AS pipeline,
                COUNT(CASE WHEN stato = 'inviata' THEN 1 END) AS num_inviate,
                COALESCE(SUM(CASE WHEN stato = 'bozza' THEN imponibile ELSE 0 END), 0) AS bozze,
                COUNT(CASE WHEN stato = 'bozza' THEN 1 END) AS num_bozze,
                COALESCE(SUM(CASE WHEN stato = 'accettata' THEN imponibile ELSE 0 END), 0) AS accettato,
                COUNT(CASE WHEN stato = 'accettata' THEN 1 END) AS num_accettate,
                COUNT(CASE WHEN stato IN ('rifiutata', 'scaduta') THEN 1 END) AS num_perse
            FROM {$this->p}offerte WHERE deleted_at IS NULL AND $where");
        $stmt->execute($params);
        $k = $this->numeri($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
        $chiuse = $k['num_accettate'] + $k['num_perse'];
        $k['tasso_conversione'] = $chiuse > 0 ? (int)round($k['num_accettate'] / $chiuse * 100) : null;
        return $k;
    }

    /** Fatture dei partner/fornitori non ancora pagate: importo_totale è già il netto a pagare (senza ritenuta). */
    public function partnerDaPagare(): array
    {
        $stmt = $this->pdo->prepare("SELECT
                COALESCE(SUM(importo_totale), 0) AS da_pagare,
                COUNT(*) AS num_da_pagare,
                COALESCE(SUM(CASE WHEN data_scadenza IS NOT NULL AND data_scadenza < ? THEN importo_totale ELSE 0 END), 0) AS scaduto,
                COUNT(CASE WHEN data_scadenza IS NOT NULL AND data_scadenza < ? THEN 1 END) AS num_scaduti
            FROM {$this->p}fatture_passive WHERE stato = 'da_pagare'");
        $stmt->execute([$this->oggi, $this->oggi]);
        return $this->numeri($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
    }

    /** Situazione di oggi, per la dashboard. */
    public function riepilogo(int $giorniFatturare = 30): array
    {
        return [
            'oggi' => $this->oggi,
            // Situazione: nessun cliente escluso, gli incassi aperti vanno visti tutti
            'fatture' => $this->fatture(null, []),
            'rate_da_fatturare' => $this->rateDaFatturare($giorniFatturare),
            'offerte' => $this->offerte(null),
            'partner' => $this->partnerDaPagare(),
        ];
    }

    /** Filtro sull'anno come intervallo di date (usa gli indici e funziona su MySQL e SQLite). */
    private function periodo(string $colonna, ?int $anno): array
    {
        if ($anno === null) return ['1 = 1', []];
        return ["$colonna BETWEEN ? AND ?", ["$anno-01-01", "$anno-12-31"]];
    }

    /** Importi arrotondati al centesimo, conteggi interi. */
    private function numeri(array $r): array
    {
        foreach ($r as $k => $v) {
            if ($v === null) continue;
            $r[$k] = (strpos((string)$k, 'num_') === 0 || $k === 'giorni') ? (int)$v : round((float)$v, 2);
        }
        return $r;
    }
}
