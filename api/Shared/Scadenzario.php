<?php
/**
 * Scadenzario — cosa c'è da fare oggi sul ciclo attivo e passivo.
 * Lo usano la vista "Scadenzario" dell'ERP e l'email giornaliera (cron/promemoria_giornaliero.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/CommessaService.php';

class Scadenzario
{
    private $pdo;
    private $p;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->p = $prefix;
    }

    /** @param int $giorni orizzonte di preavviso */
    public function calcola(int $giorni = 7): array
    {
        $oggi = date('Y-m-d');
        $limite = date('Y-m-d', strtotime("+$giorni days"));
        return [
            'oggi' => $oggi,
            'orizzonte_giorni' => $giorni,
            'offerte_da_ricontattare' => $this->offerteDaRicontattare($oggi),
            'offerte_in_scadenza' => $this->offerteInScadenza($limite),
            'rate_da_fatturare' => $this->rateDaFatturare($limite),
            'incassi_scaduti' => $this->incassi("f.data_scadenza < ?", [$oggi], $oggi),
            'incassi_in_arrivo' => $this->incassi("f.data_scadenza BETWEEN ? AND ?", [$oggi, $limite], $oggi),
            'pagamenti_partner' => $this->pagamentiPartner($limite),
        ];
    }

    private function offerteDaRicontattare(string $oggi): array
    {
        $stmt = $this->pdo->prepare("SELECT o.id, o.numero, o.versione, o.oggetto, o.imponibile, o.data_invio, o.data_followup,
                COALESCE(c.ragione_sociale, o.cliente_nome) AS cliente_nome, c.email AS cliente_email
            FROM {$this->p}offerte o LEFT JOIN {$this->p}clienti c ON c.id = o.cliente_id
            WHERE o.deleted_at IS NULL AND o.stato = 'inviata' AND o.data_followup IS NOT NULL AND o.data_followup <= ?
            ORDER BY o.data_followup");
        $stmt->execute([$oggi]);
        return $stmt->fetchAll();
    }

    private function offerteInScadenza(string $limite): array
    {
        $stmt = $this->pdo->prepare("SELECT o.id, o.numero, o.versione, o.oggetto, o.imponibile, o.data_scadenza,
                COALESCE(c.ragione_sociale, o.cliente_nome) AS cliente_nome
            FROM {$this->p}offerte o LEFT JOIN {$this->p}clienti c ON c.id = o.cliente_id
            WHERE o.deleted_at IS NULL AND o.stato = 'inviata' AND o.data_scadenza IS NOT NULL AND o.data_scadenza <= ?
            ORDER BY o.data_scadenza");
        $stmt->execute([$limite]);
        return $stmt->fetchAll();
    }

    /** Rate da fatturare, con i dati da ricopiare in Sistemi. */
    private function rateDaFatturare(string $limite): array
    {
        $stmt = $this->pdo->prepare("SELECT r.id, r.incarico_id, r.descrizione, r.importo, r.data_prevista, r.giorni_pagamento,
                i.tipo_commessa, i.descrizione AS incarico_descrizione, i.numero_protocollo,
                c.ragione_sociale AS cliente_nome, c.partita_iva AS cliente_piva, c.codice_fiscale AS cliente_cf,
                c.sdi AS cliente_sdi, c.pec AS cliente_pec, sc.nome AS sottocliente_nome,
                o.numero AS offerta_numero, o.oggetto AS offerta_oggetto, o.iva_percentuale
            FROM {$this->p}incarichi_rate r
            JOIN {$this->p}incarichi i ON i.id = r.incarico_id
            LEFT JOIN {$this->p}clienti c ON c.id = i.cliente_id
            LEFT JOIN {$this->p}sottoclienti sc ON sc.id = i.sottocliente_id
            LEFT JOIN {$this->p}offerte o ON o.id = i.offerta_id
            WHERE r.fattura_id IS NULL AND r.data_prevista IS NOT NULL AND r.data_prevista <= ?
            ORDER BY r.data_prevista, r.id");
        $stmt->execute([$limite]);
        $rows = $stmt->fetchAll();
        $svc = new CommessaService($this->pdo, $this->p);
        foreach ($rows as &$r) {
            $r['testo_fattura'] = $svc->testoFattura([
                'offerta_oggetto' => $r['offerta_oggetto'], 'descrizione' => $r['incarico_descrizione'],
                'tipo_commessa' => $r['tipo_commessa'], 'offerta_numero' => $r['offerta_numero'],
                'numero_protocollo' => $r['numero_protocollo'], 'sottocliente_nome' => $r['sottocliente_nome'],
            ], $r);
            if ($r['iva_percentuale'] === null) $r['iva_percentuale'] = 22;
        }
        unset($r);
        return $rows;
    }

    private function incassi(string $cond, array $params, string $oggi): array
    {
        $stmt = $this->pdo->prepare("SELECT f.id, f.numero_fattura, f.data_emissione, f.data_scadenza, f.importo_totale, f.stato,
                f.incarico_id, DATEDIFF(?, f.data_scadenza) AS giorni_ritardo,
                c.ragione_sociale AS cliente_nome, c.email AS cliente_email, c.pec AS cliente_pec,
                sc.nome AS sottocliente_nome
            FROM {$this->p}fatture f
            LEFT JOIN {$this->p}clienti c ON c.id = f.cliente_id
            LEFT JOIN {$this->p}sottoclienti sc ON sc.id = f.sottocliente_id
            WHERE f.stato <> 'pagata' AND f.importo_totale > 0 AND f.data_scadenza IS NOT NULL AND $cond
            ORDER BY f.data_scadenza");
        $stmt->execute(array_merge([$oggi], $params));
        return $stmt->fetchAll();
    }

    /**
     * Fatture dei partner da pagare.
     * - condizione "scadenza": quando la scadenza cade entro l'orizzonte (o manca)
     * - condizione "back_to_back": quando il cliente ha già pagato una quota della commessa
     *   sufficiente a coprire quanto si pagherebbe al partner (in proporzione al costo previsto)
     */
    private function pagamentiPartner(string $limite): array
    {
        $stmt = $this->pdo->prepare("SELECT fp.id, fp.numero, fp.data_emissione, fp.data_scadenza, fp.imponibile, fp.importo_totale,
                fp.incarico_id, fp.costo_id,
                fo.ragione_sociale AS fornitore_nome, fo.iban AS fornitore_iban,
                cc.condizione_pagamento, cc.importo_previsto AS costo_previsto,
                i.importo_totale AS commessa_totale, cl.ragione_sociale AS cliente_nome,
                (SELECT COALESCE(SUM(f.imponibile), 0) FROM {$this->p}fatture f
                  WHERE f.incarico_id = fp.incarico_id AND f.stato = 'pagata') AS commessa_incassato,
                (SELECT COALESCE(SUM(x.imponibile), 0) FROM {$this->p}fatture_passive x
                  WHERE x.costo_id = fp.costo_id AND x.stato = 'pagata') AS costo_gia_pagato
            FROM {$this->p}fatture_passive fp
            LEFT JOIN {$this->p}fornitori fo ON fo.id = fp.fornitore_id
            LEFT JOIN {$this->p}commessa_costi cc ON cc.id = fp.costo_id
            LEFT JOIN {$this->p}incarichi i ON i.id = fp.incarico_id
            LEFT JOIN {$this->p}clienti cl ON cl.id = i.cliente_id
            WHERE fp.stato = 'da_pagare'
            ORDER BY COALESCE(fp.data_scadenza, fp.data_emissione)");
        $stmt->execute();
        $out = [];
        $segnalato = []; // per costo: fatture back-to-back già proposte in questo giro
        foreach ($stmt->fetchAll() as $r) {
            if (($r['condizione_pagamento'] ?? '') === 'back_to_back' && (float)$r['commessa_totale'] > 0) {
                $cid = (int)$r['costo_id'];
                $quotaIncassata = min(1.0, (float)$r['commessa_incassato'] / (float)$r['commessa_totale']);
                $pagabile = round($quotaIncassata * (float)$r['costo_previsto'] - (float)$r['costo_gia_pagato'] - ($segnalato[$cid] ?? 0), 2);
                $r['quota_cliente_incassata_pct'] = round($quotaIncassata * 100);
                if ($pagabile + 1 < (float)$r['imponibile']) continue;
                $segnalato[$cid] = ($segnalato[$cid] ?? 0) + (float)$r['imponibile'];
                $r['motivo'] = 'Il cliente ha pagato: puoi saldare il partner';
            } else {
                if ($r['data_scadenza'] && $r['data_scadenza'] > $limite) continue;
                $r['motivo'] = $r['data_scadenza'] ? 'In scadenza' : 'Senza scadenza';
            }
            $out[] = $r;
        }
        return $out;
    }
}
