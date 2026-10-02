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

require_once __DIR__ . '/TerminiPagamento.php';

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
     * Documento = numero + cliente + anno + segno (una nota di credito con lo stesso numero è un altro documento).
     * Lo scaduto di un cliente non supera mai quanto gli resta da incassare: una nota di credito aperta lo riduce.
     * $senzaCommessa: solo le fatture non collegate a una commessa (la parte che le Commesse non vedono).
     */
    public function fatture(?int $anno = null, ?array $esclusi = null, ?int $clienteId = null, bool $senzaCommessa = false): array
    {
        $esclusi = $esclusi ?? $this->clientiEsclusi();
        [$where, $params] = $this->periodo('data_emissione', $anno);
        if ($esclusi) {
            $where .= ' AND COALESCE(cliente_id, 0) NOT IN (' . implode(',', array_map('intval', $esclusi)) . ')';
        }
        if ($clienteId !== null) {
            $where .= ' AND cliente_id = ?';
            $params[] = $clienteId;
        }
        if ($senzaCommessa) {
            $where .= ' AND incarico_id IS NULL';
        }
        $sql = "SELECT
                COALESCE(SUM(imponibile), 0) AS fatturato,
                COALESCE(SUM(totale), 0) AS totale,
                COALESCE(SUM(incassato), 0) AS incassato,
                COALESCE(SUM(aperto), 0) AS da_incassare,
                COALESCE(SUM(CASE WHEN scaduto > aperto THEN (CASE WHEN aperto > 0 THEN aperto ELSE 0 END) ELSE scaduto END), 0) AS scaduto,
                COALESCE(SUM(num_documenti), 0) AS num_documenti,
                COALESCE(SUM(num_incassati), 0) AS num_incassati,
                COALESCE(SUM(num_da_incassare), 0) AS num_da_incassare,
                COALESCE(SUM(CASE WHEN scaduto >= 0.005 AND aperto >= 0.005 THEN num_scaduti ELSE 0 END), 0) AS num_scaduti
            FROM (
                SELECT cli,
                    SUM(imponibile) AS imponibile, SUM(totale) AS totale, SUM(incassato) AS incassato,
                    SUM(aperto) AS aperto, SUM(scaduto) AS scaduto,
                    COUNT(*) AS num_documenti,
                    COUNT(CASE WHEN aperto < 0.005 AND totale > 0 THEN 1 END) AS num_incassati,
                    COUNT(CASE WHEN aperto >= 0.005 THEN 1 END) AS num_da_incassare,
                    COUNT(CASE WHEN scaduto >= 0.005 THEN 1 END) AS num_scaduti
                FROM (
                    SELECT COALESCE(cliente_id, 0) AS cli,
                        SUM(imponibile) AS imponibile, SUM(importo_totale) AS totale,
                        SUM(CASE WHEN stato = 'pagata' THEN importo_totale ELSE 0 END) AS incassato,
                        SUM(CASE WHEN stato <> 'pagata' THEN importo_totale ELSE 0 END) AS aperto,
                        SUM(CASE WHEN stato <> 'pagata' AND data_scadenza IS NOT NULL AND data_scadenza < ? THEN importo_totale ELSE 0 END) AS scaduto
                    FROM {$this->p}fatture
                    WHERE $where
                    GROUP BY numero_fattura, COALESCE(cliente_id, 0), SUBSTR(data_emissione, 1, 4), CASE WHEN importo_totale < 0 THEN 1 ELSE 0 END
                ) d
                GROUP BY cli
            ) c";
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
        // Una commessa già coperta dalle fatture non ha più rate da fatturare, anche se la fattura non è
        // stata agganciata a una rata (importo diverso: lo segnala CommessaService)
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(r.importo), 0) AS importo, COUNT(*) AS num_rate
            FROM {$this->p}incarichi_rate r
            WHERE r.fattura_id IS NULL AND (r.data_prevista IS NULL OR r.data_prevista <= ?) AND " . $this->rataAperta('r'));
        $stmt->execute([$limite]);
        return $this->numeri(($stmt->fetch(PDO::FETCH_ASSOC) ?: []) + ['giorni' => $giorni]);
    }

    /** Condizione SQL: la rata appartiene a una commessa non ancora fatturata per intero. */
    private function rataAperta(string $alias): string
    {
        return "(SELECT i.importo_totale - COALESCE((SELECT SUM(f.imponibile) FROM {$this->p}fatture f WHERE f.incarico_id = i.id), 0)
            FROM {$this->p}incarichi i WHERE i.id = $alias.incarico_id) >= 0.01";
    }

    /** Probabilità di chiusura predefinita per stato, se l'offerta non ne ha una sua (%). */
    public const PROBABILITA = ['lead' => 10, 'bozza' => 30, 'inviata' => 50];

    /**
     * Offerte. pipeline = offerte inviate e non ancora decise (le bozze no: non le ha viste nessuno).
     * pipeline_pesata = somma di imponibile × probabilità di lead, bozze e inviate (probabilità dell'offerta
     * o quella predefinita dello stato). tasso_conversione = accettate / (accettate + perse), in percentuale.
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
                COALESCE(SUM(CASE WHEN stato = 'lead' THEN imponibile ELSE 0 END), 0) AS valore_lead,
                COUNT(CASE WHEN stato = 'lead' THEN 1 END) AS num_lead,
                COALESCE(SUM(CASE WHEN stato IN ('lead', 'bozza', 'inviata') THEN imponibile * COALESCE(probabilita,
                    CASE stato WHEN 'lead' THEN " . self::PROBABILITA['lead'] . " WHEN 'bozza' THEN " . self::PROBABILITA['bozza'] . "
                    ELSE " . self::PROBABILITA['inviata'] . " END) / 100.0 ELSE 0 END), 0) AS pipeline_pesata,
                COALESCE(SUM(CASE WHEN stato = 'accettata' THEN imponibile ELSE 0 END), 0) AS accettato,
                COUNT(CASE WHEN stato = 'accettata' THEN 1 END) AS num_accettate,
                COUNT(CASE WHEN stato IN ('rifiutata', 'scaduta') THEN 1 END) AS num_perse,
                COUNT(CASE WHEN stato = 'accettata' AND COALESCE(origine, '') <> 'rapida' THEN 1 END) AS num_accettate_da_offerta,
                COUNT(CASE WHEN stato IN ('rifiutata', 'scaduta') AND data_invio IS NOT NULL THEN 1 END) AS num_perse_inviate
            FROM {$this->p}offerte WHERE deleted_at IS NULL AND $where");
        $stmt->execute($params);
        $k = $this->numeri($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
        // Conversione delle offerte davvero proposte: fuori le offerte registrate insieme a una commessa
        // (origine rapida) e i lead persi prima di ricevere un'offerta
        $chiuse = $k['num_accettate_da_offerta'] + $k['num_perse_inviate'];
        $k['tasso_conversione'] = $chiuse > 0 ? (int)round($k['num_accettate_da_offerta'] / $chiuse * 100) : null;
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

    /**
     * Cose da fare oggi (conteggi e importi), ognuna con la vista dove si risolve.
     * $giorni: orizzonte per rate da fatturare e partner da pagare.
     */
    public function daFare(int $giorni = 7): array
    {
        $limite = date('Y-m-d', strtotime($this->oggi . " +$giorni days"));
        $uno = function (string $sql, array $par) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($par);
            return $this->numeri($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
        };
        $p = $this->p;
        return [
            // Stessa definizione di fatture(): per documento, al netto delle note di credito del cliente
            'incassi_scaduti' => (function () {
                $f = $this->fatture(null, []);
                return ['num' => $f['num_scaduti'], 'importo' => $f['scaduto']];
            })(),
            'rate_da_fatturare' => $uno("SELECT COUNT(*) AS num, COALESCE(SUM(r.importo), 0) AS importo FROM {$p}incarichi_rate r
                WHERE r.fattura_id IS NULL AND (r.data_prevista IS NULL OR r.data_prevista <= ?) AND " . $this->rataAperta('r'), [$limite]),
            'partner_da_pagare' => $uno("SELECT COUNT(*) AS num, COALESCE(SUM(importo_totale), 0) AS importo FROM {$p}fatture_passive
                WHERE stato = 'da_pagare' AND (data_scadenza IS NULL OR data_scadenza <= ?)", [$limite]),
            'offerte_da_ricontattare' => $uno("SELECT COUNT(*) AS num, COALESCE(SUM(imponibile), 0) AS importo FROM {$p}offerte
                WHERE deleted_at IS NULL AND stato IN ('lead', 'inviata') AND data_followup IS NOT NULL AND data_followup <= ?", [$this->oggi]),
            'movimenti_da_abbinare' => $uno("SELECT COUNT(*) AS num, COALESCE(SUM(importo), 0) AS importo FROM {$p}movimenti_banca
                WHERE stato = 'da_riconciliare' AND abbinabile = 1 AND origine = 'estratto_conto'", []),
            // Movimenti del conto che chiedono un intervento (da abbinare o da classificare), contati una volta
            'movimenti_da_sistemare' => $uno("SELECT COUNT(*) AS num FROM {$p}movimenti_banca WHERE origine = 'estratto_conto'
                AND ((stato = 'da_riconciliare' AND abbinabile = 1) OR classificazione = 'da_classificare')", []),
            // Note spese (mese corrente e precedente): spese senza giustificativo, uscite della carta non registrate
            'spese_senza_giustificativo' => $this->unoSicuro("SELECT COUNT(*) AS num, COALESCE(SUM(importo), 0) AS importo FROM {$p}spese
                WHERE deleted_at IS NULL AND documento IS NULL AND data >= ?", [date('Y-m-01', strtotime(substr($this->oggi, 0, 7) . '-01 -1 month'))]),
            'carta_da_registrare' => $this->unoSicuro("SELECT COUNT(*) AS num, COALESCE(SUM(-m.importo), 0) AS importo FROM {$p}movimenti_banca m
                WHERE m.origine = 'estratto_carta' AND m.importo < 0 AND m.data_operazione >= ?
                  AND NOT EXISTS (SELECT 1 FROM {$p}spese s WHERE s.movimento_id = m.id AND s.deleted_at IS NULL)",
                [date('Y-m-01', strtotime(substr($this->oggi, 0, 7) . '-01 -1 month'))]),
            'giorni' => $giorni,
        ];
    }

    /**
     * Incassi attesi per settimana (lunedì-domenica) nelle prossime $settimane, più quanto è già scaduto.
     * fatturate = fatture aperte per data di scadenza (IVA inclusa);
     * da_fatturare = rate senza fattura alla data prevista + giorni di pagamento (imponibile + IVA dell'offerta, 22% se manca).
     */
    public function incassiAttesi(int $settimane = 12): array
    {
        $lunedi = date('Y-m-d', strtotime($this->oggi . ' -' . ((int)date('N', strtotime($this->oggi)) - 1) . ' days'));
        $fine = date('Y-m-d', strtotime($lunedi . ' +' . ($settimane * 7 - 1) . ' days'));
        $out = ['scaduto' => ['fatturate' => 0.0, 'da_fatturare' => 0.0], 'settimane' => []];
        for ($i = 0; $i < $settimane; $i++) {
            $out['settimane'][] = ['dal' => date('Y-m-d', strtotime("$lunedi +" . ($i * 7) . ' days')), 'fatturate' => 0.0, 'da_fatturare' => 0.0];
        }
        $metti = function (string $data, float $importo, string $tipo) use (&$out, $lunedi, $fine) {
            if ($data < $this->oggi) { $out['scaduto'][$tipo] += $importo; return; }
            if ($data > $fine) return;
            // Giorni di calendario (DateTime): con strtotime il giorno del cambio d'ora dura 23 ore
            $i = intdiv((new DateTimeImmutable($lunedi))->diff(new DateTimeImmutable($data))->days, 7);
            if (isset($out['settimane'][$i])) $out['settimane'][$i][$tipo] += $importo;
        };
        $stmt = $this->pdo->prepare("SELECT data_scadenza, importo_totale FROM {$this->p}fatture
            WHERE stato <> 'pagata' AND importo_totale > 0 AND data_scadenza IS NOT NULL AND data_scadenza <= ?");
        $stmt->execute([$fine]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) $metti((string)$r['data_scadenza'], (float)$r['importo_totale'], 'fatturate');
        $stmt = $this->pdo->prepare("SELECT r.data_prevista, r.importo, r.giorni_pagamento, i.fine_mese, i.giorno_pagamento, o.iva_percentuale
            FROM {$this->p}incarichi_rate r JOIN {$this->p}incarichi i ON i.id = r.incarico_id
            LEFT JOIN {$this->p}offerte o ON o.id = i.offerta_id
            WHERE r.fattura_id IS NULL AND r.data_prevista IS NOT NULL AND " . $this->rataAperta('r'));
        $stmt->execute();
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $incasso = TerminiPagamento::scadenza((string)$r['data_prevista'], ...TerminiPagamento::daRiga($r));
            $iva = $r['iva_percentuale'] === null ? 22.0 : (float)$r['iva_percentuale'];
            // Una rata non fatturata non è mai "scaduta" come incasso: se la data è passata, va nella settimana corrente
            $metti(max($incasso, $this->oggi), (float)$r['importo'] * (1 + $iva / 100), 'da_fatturare');
        }
        $arr = fn(array $x) => array_map(fn($v) => is_float($v) ? round($v, 2) : $v, $x);
        $out['scaduto'] = $arr($out['scaduto']);
        $out['settimane'] = array_map($arr, $out['settimane']);
        return $out;
    }

    /**
     * Trasferte del periodo: km, rimborso chilometrico (costo ACI del mezzo, altrimenti il costo al km
     * generale), indennità (TrasferteRegole, con vitto e alloggio presi dalle spese: pagati dalla società o
     * no, riducono l'indennità), spese. da_rimborsare = km + indennità + solo le spese pagate di tasca propria:
     * quelle con carta aziendale o bonifico le ha già pagate la società. Giornate senza cliente = da assegnare.
     */
    public function trasferte(string $dal, string $al, ?float $costoKm): array
    {
        require_once __DIR__ . '/TrasferteRegole.php';
        require_once __DIR__ . '/Spese.php';
        require_once __DIR__ . '/Percorsi.php';
        $stmt = $this->pdo->prepare("SELECT t.data_trasferta, t.cliente_id, t.sottocliente_id, t.km_andata, t.km_ritorno, m.costo_km,
                c.citta AS cliente_citta, sc.citta AS sottocliente_citta
            FROM {$this->p}trasferte t LEFT JOIN {$this->p}mezzi m ON m.id = t.mezzo_id
            LEFT JOIN {$this->p}clienti c ON c.id = t.cliente_id
            LEFT JOIN {$this->p}sottoclienti sc ON sc.id = t.sottocliente_id
            WHERE t.data_trasferta BETWEEN ? AND ?");
        $stmt->execute([$dal, $al]);
        $spese = new Spese($this->pdo, $this->p);
        $perGiorno = $spese->perGiorno($dal, $al);
        $righe = Spese::applicaAlleTrasferte($stmt->fetchAll(PDO::FETCH_ASSOC), $perGiorno);
        $km = 0.0;
        $rimborsoKm = 0.0;
        $senzaCosto = false;
        foreach ($righe as $r) {
            $k = (float)$r['km_andata'] + (float)$r['km_ritorno'];
            $km += $k;
            $costo = $r['costo_km'] !== null ? (float)$r['costo_km'] : $costoKm;
            if ($costo === null) { if ($k > 0) $senzaCosto = true; continue; }
            $rimborsoKm += $k * $costo;
        }
        $totSpese = array_sum(array_map(fn($g) => $g['vitto'] + $g['alloggio'] + $g['altre'], $perGiorno));
        $metodo = $spese->perMetodo($dal, $al);
        $giornate = TrasferteRegole::giornate($righe, Percorsi::comuneBase());
        $indennita = array_sum(array_column($giornate, 'indennita'));
        $rimborsoKm = $senzaCosto && $rimborsoKm == 0.0 ? null : round($rimborsoKm, 2);
        return [
            'dal' => $dal, 'al' => $al,
            'num_giornate' => count($giornate),
            'num_senza_cliente' => count(array_filter($giornate, fn($g) => !$g['con_cliente'])),
            'km' => round($km, 1),
            'costo_km' => $costoKm,
            'rimborso_km' => $rimborsoKm,
            'km_senza_costo' => $senzaCosto,
            'indennita' => round($indennita, 2),
            'spese' => round($totSpese, 2),
            'spese_aziendali' => $metodo['aziendali'],
            'spese_da_rimborsare' => $metodo['da_rimborsare'],
            'da_rimborsare' => round(($rimborsoKm ?? 0) + $indennita + $metodo['da_rimborsare'], 2),
        ];
    }

    /** Una riga di conteggio che non rompe la dashboard se la tabella non è ancora migrata. */
    private function unoSicuro(string $sql, array $par): array
    {
        try {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute($par);
            return $this->numeri($stmt->fetch(PDO::FETCH_ASSOC) ?: []);
        } catch (PDOException $e) {
            return ['num' => 0, 'importo' => 0.0];
        }
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
            $r[$k] = (strpos((string)$k, 'num') === 0 || $k === 'giorni') ? (int)$v : round((float)$v, 2);
        }
        return $r;
    }
}
