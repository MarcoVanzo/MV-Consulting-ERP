<?php
/**
 * CommessaService — logica condivisa della commessa (incarico):
 * piano di fatturazione, abbinamento fattura ↔ rata, costi dei partner e margine.
 *
 * Unico posto dove si calcola il margine: controller, scadenzario e cron passano da qui.
 *
 * Margine previsto  = valore incarico − costi previsti dei partner
 * Margine effettivo = fatturato (imponibile) − fatture passive collegate (imponibile)
 */
declare(strict_types=1);

class CommessaService
{
    private $pdo;
    private $p;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->p = $prefix;
    }

    /**
     * Crea le rate di un incarico da un piano [{descrizione, percentuale, giorni_da_accettazione}].
     * L'ultima rata assorbe gli arrotondamenti, così la somma torna al centesimo.
     */
    public function creaRateDaPiano(int $incaricoId, float $totale, array $piano, string $dataBase, int $giorniPagamento): void
    {
        $piano = array_values(array_filter($piano, fn($r) => (float)($r['percentuale'] ?? 0) > 0));
        if (!$piano) {
            $piano = [['descrizione' => 'Saldo', 'percentuale' => 100, 'giorni_da_accettazione' => 0]];
        }
        $ins = $this->pdo->prepare("INSERT INTO {$this->p}incarichi_rate
            (incarico_id, ordine, descrizione, percentuale, importo, data_prevista, giorni_pagamento)
            VALUES (?, ?, ?, ?, ?, ?, ?)");
        $assegnato = 0.0;
        $n = count($piano);
        foreach ($piano as $i => $r) {
            $perc = round((float)$r['percentuale'], 2);
            $importo = $i === $n - 1 ? round($totale - $assegnato, 2) : round($totale * $perc / 100, 2);
            $assegnato += $importo;
            $giorni = $r['giorni_da_accettazione'] ?? null;
            $data = ($giorni === null || $giorni === '') ? null : date('Y-m-d', strtotime($dataBase . ' +' . (int)$giorni . ' days'));
            $ins->execute([$incaricoId, $i + 1, trim((string)($r['descrizione'] ?? 'Rata ' . ($i + 1))) ?: 'Rata ' . ($i + 1),
                $perc, $importo, $data, $giorniPagamento]);
        }
    }

    /**
     * Abbina una fattura emessa alle rate libere del suo incarico: la rata con lo stesso importo
     * oppure più rate consecutive la cui somma corrisponde (es. acconto + saldo fatturati insieme);
     * in mancanza, la prima rata libera. Completa la scadenza della fattura.
     * Restituisce l'id della (prima) rata o null.
     */
    public function collegaFatturaARata(int $fatturaId): ?int
    {
        $stmt = $this->pdo->prepare("SELECT id, incarico_id, imponibile, data_emissione, data_scadenza FROM {$this->p}fatture WHERE id = ?");
        $stmt->execute([$fatturaId]);
        $f = $stmt->fetch();
        if (!$f || !$f['incarico_id'] || (float)$f['imponibile'] <= 0) return null;

        $stmt = $this->pdo->prepare("SELECT id FROM {$this->p}incarichi_rate WHERE fattura_id = ? ORDER BY ordine, id LIMIT 1");
        $stmt->execute([$fatturaId]);
        $gia = $stmt->fetchColumn();
        if ($gia) return (int)$gia;

        $stmt = $this->pdo->prepare("SELECT id, descrizione, percentuale, importo, giorni_pagamento FROM {$this->p}incarichi_rate
            WHERE incarico_id = ? AND fattura_id IS NULL ORDER BY ordine, id");
        $stmt->execute([$f['incarico_id']]);
        $libere = $stmt->fetchAll();
        if (!$libere) return null;

        $gruppo = $this->rateCoperte($libere, (float)$f['imponibile']);
        $scelta = $gruppo[0];
        $upd = $this->pdo->prepare("UPDATE {$this->p}incarichi_rate SET fattura_id = ? WHERE id = ?");
        $upd->execute([$fatturaId, $scelta['id']]);
        foreach (array_slice($gruppo, 1) as $r) {
            try {
                $upd->execute([$fatturaId, $r['id']]);
            } catch (PDOException $e) {
                if ((int)($e->errorInfo[1] ?? 0) !== 1062) throw $e;
                // Indice unico su fattura_id ancora presente (migrazione non applicata):
                // si collega solo la prima rata, le altre restano da abbinare a mano.
                // Mai fondere o cancellare rate in automatico.
                error_log("collegaFatturaARata: fattura {$fatturaId} copre più rate ma l'indice unico lo impedisce; lancia le migrazioni");
                break;
            }
        }
        if (!$f['data_scadenza']) {
            $scad = date('Y-m-d', strtotime($f['data_emissione'] . ' +' . (int)$scelta['giorni_pagamento'] . ' days'));
            $this->pdo->prepare("UPDATE {$this->p}fatture SET data_scadenza = ? WHERE id = ?")->execute([$scad, $fatturaId]);
        }
        return (int)$scelta['id'];
    }

    /**
     * Rate coperte da un imponibile (tolleranza 1 €): prima una rata di pari importo,
     * poi una sequenza di rate consecutive; altrimenti la prima rata libera.
     */
    private function rateCoperte(array $libere, float $imponibile): array
    {
        foreach ($libere as $r) {
            if (abs((float)$r['importo'] - $imponibile) <= 1.0) return [$r];
        }
        $n = count($libere);
        for ($i = 0; $i < $n; $i++) {
            $somma = 0.0;
            for ($j = $i; $j < $n; $j++) {
                $somma += (float)$libere[$j]['importo'];
                if (abs($somma - $imponibile) <= 1.0) return array_slice($libere, $i, $j - $i + 1);
                if ($somma > $imponibile + 1.0) break;
            }
        }
        return [$libere[0]];
    }

    /**
     * Incarico citato in una descrizione di fattura tramite il numero dell'offerta
     * ("Rif. OFF-2026-004"): è il riferimento che l'ERP suggerisce di scrivere in Sistemi.
     */
    public function trovaIncaricoPerRiferimento(string $testo, ?int $clienteId): ?int
    {
        if (!preg_match_all('/\bOFF-(\d{4})-(\d{1,5})\b/i', $testo, $m, PREG_SET_ORDER)) return null;
        foreach ($m as $hit) {
            $numero = sprintf('OFF-%s-%03d', $hit[1], (int)$hit[2]);
            $sql = "SELECT incarico_id FROM {$this->p}offerte WHERE numero = ? AND incarico_id IS NOT NULL AND deleted_at IS NULL";
            $params = [$numero];
            if ($clienteId) { $sql .= " AND cliente_id = ?"; $params[] = $clienteId; }
            $stmt = $this->pdo->prepare($sql . " ORDER BY versione DESC LIMIT 1");
            $stmt->execute($params);
            $id = $stmt->fetchColumn();
            if ($id) return (int)$id;
        }
        return null;
    }

    /** Rate dell'incarico con lo stato derivato dalla fattura collegata. */
    public function rate(int $incaricoId): array
    {
        $stmt = $this->pdo->prepare("SELECT r.*, f.numero_fattura, f.data_emissione AS fattura_data, f.stato AS fattura_stato,
                f.data_scadenza AS fattura_scadenza, f.data_pagamento AS fattura_pagamento,
                CASE WHEN f.id IS NULL THEN 'da_fatturare' WHEN f.stato = 'pagata' THEN 'incassata' ELSE 'fatturata' END AS stato
            FROM {$this->p}incarichi_rate r
            LEFT JOIN {$this->p}fatture f ON f.id = r.fattura_id
            WHERE r.incarico_id = ? ORDER BY r.ordine, r.id");
        $stmt->execute([$incaricoId]);
        return $stmt->fetchAll();
    }

    /** Costi dei partner della commessa (o dell'offerta) con quanto già fatturato e pagato. */
    public function costi(?int $incaricoId, ?int $offertaId = null): array
    {
        $where = $incaricoId ? 'c.incarico_id = ?' : 'c.offerta_id = ? AND c.incarico_id IS NULL';
        $stmt = $this->pdo->prepare("SELECT c.*, fo.ragione_sociale AS fornitore_nome,
                COALESCE(SUM(fp.imponibile), 0) AS fatturato,
                COALESCE(SUM(CASE WHEN fp.stato = 'pagata' THEN fp.imponibile ELSE 0 END), 0) AS pagato,
                COUNT(fp.id) AS num_fatture
            FROM {$this->p}commessa_costi c
            LEFT JOIN {$this->p}fornitori fo ON fo.id = c.fornitore_id
            LEFT JOIN {$this->p}fatture_passive fp ON fp.costo_id = c.id
            WHERE $where GROUP BY c.id ORDER BY c.id");
        $stmt->execute([$incaricoId ?: $offertaId]);
        return $stmt->fetchAll();
    }

    /** Scheda completa della commessa: incarico, offerta, rate, costi, fatture e margine. */
    public function riepilogo(int $incaricoId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT i.*, c.ragione_sociale AS cliente_nome, c.partita_iva AS cliente_piva,
                c.sdi AS cliente_sdi, c.pec AS cliente_pec, sc.nome AS sottocliente_nome,
                o.numero AS offerta_numero, o.versione AS offerta_versione, o.oggetto AS offerta_oggetto
            FROM {$this->p}incarichi i
            LEFT JOIN {$this->p}clienti c ON c.id = i.cliente_id
            LEFT JOIN {$this->p}sottoclienti sc ON sc.id = i.sottocliente_id
            LEFT JOIN {$this->p}offerte o ON o.id = i.offerta_id
            WHERE i.id = ?");
        $stmt->execute([$incaricoId]);
        $inc = $stmt->fetch();
        if (!$inc) return null;

        $stmt = $this->pdo->prepare("SELECT id, numero_fattura, data_emissione, imponibile, importo_totale, stato, data_scadenza, data_pagamento
            FROM {$this->p}fatture WHERE incarico_id = ? ORDER BY data_emissione");
        $stmt->execute([$incaricoId]);
        $fatture = $stmt->fetchAll();

        $stmt = $this->pdo->prepare("SELECT fp.*, fo.ragione_sociale AS fornitore_nome
            FROM {$this->p}fatture_passive fp LEFT JOIN {$this->p}fornitori fo ON fo.id = fp.fornitore_id
            WHERE fp.incarico_id = ? ORDER BY fp.data_emissione");
        $stmt->execute([$incaricoId]);
        $passive = $stmt->fetchAll();

        $rate = $this->rate($incaricoId);
        foreach ($rate as &$r) {
            $r['testo_fattura'] = $this->testoFattura($inc, $r);
        }
        unset($r);

        return [
            'incarico' => $inc,
            'rate' => $rate,
            'costi' => $this->costi($incaricoId),
            'fatture' => $fatture,
            'fatture_passive' => $passive,
            'margine' => $this->margineIncarico($incaricoId),
        ];
    }

    /** Descrizione da copiare in Sistemi: contiene il riferimento che poi riaggancia la fattura. */
    public function testoFattura(array $inc, array $rata): string
    {
        $oggetto = trim((string)($inc['offerta_oggetto'] ?? '')) ?: trim((string)($inc['descrizione'] ?? '')) ?: ucfirst((string)$inc['tipo_commessa']);
        $rif = [];
        if (!empty($inc['offerta_numero'])) $rif[] = 'Rif. ' . $inc['offerta_numero'];
        if (!empty($inc['numero_protocollo'])) $rif[] = 'Prot. n. ' . $inc['numero_protocollo'];
        $presso = !empty($inc['sottocliente_nome']) ? ' presso ' . $inc['sottocliente_nome'] : '';
        return $oggetto . $presso . ' — ' . $rata['descrizione'] . ($rif ? ' (' . implode(', ', $rif) . ')' : '');
    }

    /** Margine di una commessa. */
    public function margineIncarico(int $incaricoId): array
    {
        $rows = $this->margini(null, $incaricoId);
        return $rows[0] ?? [];
    }

    /**
     * Margini per commessa. Con $anno filtra per data incarico, con $incaricoId una sola.
     * Importi IVA esclusa.
     */
    public function margini(?int $anno, ?int $incaricoId = null): array
    {
        $where = [];
        $params = [];
        if ($anno) { $where[] = 'YEAR(i.data_incarico) = ?'; $params[] = $anno; }
        if ($incaricoId) { $where[] = 'i.id = ?'; $params[] = $incaricoId; }
        $sql = "SELECT i.id, i.data_incarico, i.tipo_commessa, i.numero_protocollo, i.descrizione, i.stato,
                c.ragione_sociale AS cliente_nome, sc.nome AS sottocliente_nome,
                i.importo_totale AS ricavo_previsto,
                COALESCE(cp.costi_previsti, 0) AS costi_previsti,
                COALESCE(fa.fatturato, 0) AS fatturato,
                COALESCE(fa.incassato, 0) AS incassato,
                COALESCE(pa.costi_effettivi, 0) AS costi_effettivi,
                COALESCE(pa.pagato_partner, 0) AS pagato_partner
            FROM {$this->p}incarichi i
            LEFT JOIN {$this->p}clienti c ON c.id = i.cliente_id
            LEFT JOIN {$this->p}sottoclienti sc ON sc.id = i.sottocliente_id
            LEFT JOIN (SELECT incarico_id, SUM(importo_previsto) AS costi_previsti
                       FROM {$this->p}commessa_costi WHERE incarico_id IS NOT NULL GROUP BY incarico_id) cp ON cp.incarico_id = i.id
            LEFT JOIN (SELECT incarico_id, SUM(imponibile) AS fatturato,
                              SUM(CASE WHEN stato = 'pagata' THEN imponibile ELSE 0 END) AS incassato
                       FROM {$this->p}fatture WHERE incarico_id IS NOT NULL GROUP BY incarico_id) fa ON fa.incarico_id = i.id
            LEFT JOIN (SELECT incarico_id, SUM(imponibile) AS costi_effettivi,
                              SUM(CASE WHEN stato = 'pagata' THEN imponibile ELSE 0 END) AS pagato_partner
                       FROM {$this->p}fatture_passive WHERE incarico_id IS NOT NULL GROUP BY incarico_id) pa ON pa.incarico_id = i.id"
            . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY i.data_incarico DESC, i.id DESC";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $rows = $stmt->fetchAll();

        foreach ($rows as &$r) {
            $ricavo = (float)$r['ricavo_previsto'];
            $r['margine_previsto'] = round($ricavo - (float)$r['costi_previsti'], 2);
            $r['margine_previsto_pct'] = $ricavo > 0 ? round($r['margine_previsto'] / $ricavo * 100, 1) : null;
            $fatt = (float)$r['fatturato'];
            // A commessa aperta il consuntivo usa il fatturato; i costi del partner possono arrivare dopo
            $r['margine_effettivo'] = round($fatt - (float)$r['costi_effettivi'], 2);
            $r['margine_effettivo_pct'] = $fatt > 0 ? round($r['margine_effettivo'] / $fatt * 100, 1) : null;
        }
        unset($r);
        return $rows;
    }
}
