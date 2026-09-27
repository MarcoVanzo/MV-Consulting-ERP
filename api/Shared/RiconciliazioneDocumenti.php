<?php
/**
 * RiconciliazioneDocumenti — le fatture viste dalla riconciliazione.
 *
 * Una fattura emessa con più sottoclienti è salvata in più record di {prefix}fatture (uno per
 * gruppo sottocliente, ciascuno col suo incarico_id e la sua rata): qui quei record tornano a essere
 * UN documento (stesso numero_fattura, anno di emissione e cliente), che è quello che il cliente paga.
 * Le riconciliazioni restano per record (documento_id = id del record), così rata e incarico
 * di ogni riga seguono il pagamento.
 *
 * Fuori dalla riconciliazione: registro agenzia viaggi (numeri "NNAV" / sezionale 002) e documenti a
 * importo negativo (note di credito).
 */
declare(strict_types=1);

require_once __DIR__ . '/RiconciliazioneMatch.php';

class RiconciliazioneDocumenti
{
    private $pdo;
    private $p;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->p = $prefix;
    }

    /**
     * Documenti con qualcosa da incassare/pagare, i più vecchi prima.
     * Forma comune: {tipo, id (primo record), numero, data_emissione, data_scadenza, anagrafica_id, anagrafica_nome,
     * sottoclienti, totale, ritenuta, riconciliato, residuo, pagata, righe[]}
     */
    public function aperti(string $tipo, ?int $anagraficaId = null): array
    {
        if ($tipo === 'fattura') {
            $sql = $this->selectFatture() . " WHERE f.stato <> 'pagata' AND f.importo_totale > 0"
                . ($anagraficaId ? ' AND f.cliente_id = ?' : '') . " ORDER BY COALESCE(f.data_scadenza, f.data_emissione), f.id";
        } else {
            $sql = $this->selectPassive() . " WHERE fp.stato = 'da_pagare' AND fp.importo_totale > 0"
                . ($anagraficaId ? ' AND fp.fornitore_id = ?' : '') . " ORDER BY COALESCE(fp.data_scadenza, fp.data_emissione), fp.id";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($anagraficaId ? [$anagraficaId] : []);
        $docs = $this->raggruppa($tipo, $stmt->fetchAll(PDO::FETCH_ASSOC));
        return array_values(array_filter($docs, fn($d) => $d['residuo'] > 0.005 && !RiconciliazioneMatch::escluso($d['numero'])));
    }

    /** Il documento a cui appartiene il record $id, con tutte le sue righe (anche già pagate). */
    public function documento(string $tipo, int $id): ?array
    {
        if ($tipo === 'fattura') {
            $stmt = $this->pdo->prepare("SELECT numero_fattura, data_emissione, cliente_id FROM {$this->p}fatture WHERE id = ?");
            $stmt->execute([$id]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) return null;
            $anno = substr((string)$r['data_emissione'], 0, 4);
            $sql = $this->selectFatture() . " WHERE f.numero_fattura = ? AND f.data_emissione BETWEEN ? AND ?"
                . ($r['cliente_id'] === null ? ' AND f.cliente_id IS NULL' : ' AND f.cliente_id = ?') . " ORDER BY f.id";
            $params = [$r['numero_fattura'], "$anno-01-01", "$anno-12-31"];
            if ($r['cliente_id'] !== null) $params[] = $r['cliente_id'];
        } elseif ($tipo === 'fattura_passiva') {
            $sql = $this->selectPassive() . " WHERE fp.id = ?";
            $params = [$id];
        } else {
            return null;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $docs = $this->raggruppa($tipo, $stmt->fetchAll(PDO::FETCH_ASSOC));
        return $docs[0] ?? null;
    }

    /** Riconciliazioni di un movimento, una riga per documento (i record della stessa fattura sommati). */
    public function delMovimento(int $movimentoId): array
    {
        $stmt = $this->pdo->prepare("SELECT r.tipo, r.documento_id, r.importo, r.metodo,
                COALESCE(f.numero_fattura, fp.numero) AS numero, COALESCE(f.data_emissione, fp.data_emissione) AS data_emissione,
                COALESCE(c.ragione_sociale, fo.ragione_sociale, '') AS anagrafica_nome, f.incarico_id
            FROM {$this->p}riconciliazioni r
            LEFT JOIN {$this->p}fatture f ON r.tipo = 'fattura' AND f.id = r.documento_id
            LEFT JOIN {$this->p}clienti c ON c.id = f.cliente_id
            LEFT JOIN {$this->p}fatture_passive fp ON r.tipo = 'fattura_passiva' AND fp.id = r.documento_id
            LEFT JOIN {$this->p}fornitori fo ON fo.id = fp.fornitore_id
            WHERE r.movimento_id = ? ORDER BY r.id");
        $stmt->execute([$movimentoId]);
        $out = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $k = $r['tipo'] . '|' . $r['numero'] . '|' . substr((string)$r['data_emissione'], 0, 4) . '|' . $r['anagrafica_nome'];
            if (!isset($out[$k])) {
                $out[$k] = ['tipo' => $r['tipo'], 'id' => (int)$r['documento_id'], 'numero' => (string)$r['numero'],
                    'data_emissione' => $r['data_emissione'], 'anagrafica_nome' => $r['anagrafica_nome'],
                    'importo' => 0.0, 'metodo' => $r['metodo'], 'righe' => 0, 'incarichi' => []];
            }
            $out[$k]['importo'] = round($out[$k]['importo'] + (float)$r['importo'], 2);
            $out[$k]['righe']++;
            if ($r['incarico_id']) $out[$k]['incarichi'][(int)$r['incarico_id']] = true;
        }
        foreach ($out as &$d) {
            $d['incarichi'] = array_map(fn($id) => $this->rateIncarico($id), array_keys($d['incarichi']));
        }
        unset($d);
        return array_values($out);
    }

    /** Rate incassate dell'incarico (la rata è incassata quando la sua fattura è pagata). */
    public function rateIncarico(int $incaricoId): array
    {
        $stmt = $this->pdo->prepare("SELECT COUNT(r.id) AS rate_totali,
                COALESCE(SUM(CASE WHEN f.stato = 'pagata' THEN 1 ELSE 0 END), 0) AS rate_incassate
            FROM {$this->p}incarichi_rate r LEFT JOIN {$this->p}fatture f ON f.id = r.fattura_id
            WHERE r.incarico_id = ?");
        $stmt->execute([$incaricoId]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
        return ['id' => $incaricoId, 'rate_totali' => (int)($r['rate_totali'] ?? 0), 'rate_incassate' => (int)($r['rate_incassate'] ?? 0)];
    }

    /** Voci per il subset-sum: il residuo e, se la ritenuta non è stata trattenuta, anche il lordo. */
    public static function voci(array $docs): array
    {
        $voci = [];
        foreach ($docs as $d) {
            $opz = [(int)round($d['residuo'] * 100)];
            if ($d['ritenuta'] > 0 && $d['riconciliato'] <= 0) $opz[] = (int)round(($d['residuo'] + $d['ritenuta']) * 100);
            $voci[] = ['id' => $d['id'], 'opzioni' => array_values(array_unique($opz))];
        }
        return $voci;
    }

    public static function proposta(array $d, float $importo): array
    {
        return ['tipo' => $d['tipo'], 'id' => $d['id'], 'numero' => $d['numero'], 'anagrafica_nome' => $d['anagrafica_nome'],
            'sottoclienti' => $d['sottoclienti'], 'righe' => count($d['righe']),
            'data_emissione' => $d['data_emissione'], 'data_scadenza' => $d['data_scadenza'],
            'residuo' => $d['residuo'], 'importo' => round($importo, 2)];
    }

    // ── Helper ──────────────────────────────────────────

    private function selectFatture(): string
    {
        return "SELECT f.*, f.numero_fattura AS numero, f.cliente_id AS anagrafica_id,
                COALESCE(c.ragione_sociale, '') AS anagrafica_nome, sc.nome AS sottocliente_nome,
                COALESCE((SELECT SUM(r.importo) FROM {$this->p}riconciliazioni r WHERE r.tipo = 'fattura' AND r.documento_id = f.id), 0) AS riconciliato
            FROM {$this->p}fatture f
            LEFT JOIN {$this->p}clienti c ON c.id = f.cliente_id
            LEFT JOIN {$this->p}sottoclienti sc ON sc.id = f.sottocliente_id";
    }

    private function selectPassive(): string
    {
        return "SELECT fp.*, fp.fornitore_id AS anagrafica_id, COALESCE(fo.ragione_sociale, '') AS anagrafica_nome, NULL AS sottocliente_nome,
                COALESCE((SELECT SUM(r.importo) FROM {$this->p}riconciliazioni r WHERE r.tipo = 'fattura_passiva' AND r.documento_id = fp.id), 0) AS riconciliato
            FROM {$this->p}fatture_passive fp
            LEFT JOIN {$this->p}fornitori fo ON fo.id = fp.fornitore_id";
    }

    /** Record → documenti (per le passive un record è un documento). */
    private function raggruppa(string $tipo, array $rows): array
    {
        $docs = [];
        foreach ($rows as $r) {
            $totale = round((float)$r['importo_totale'], 2);
            // Emesse: ritenuta d'acconto solo se la colonna esiste (oggi no). Passive: importo_totale è già il netto a pagare
            $ritenuta = $tipo === 'fattura' ? round((float)($r['ritenuta'] ?? 0), 2) : 0.0;
            $ric = round((float)$r['riconciliato'], 2);
            $pagata = $r['stato'] === 'pagata';
            $riga = [
                'id' => (int)$r['id'], 'totale' => $totale, 'ritenuta' => $ritenuta, 'riconciliato' => $ric,
                'residuo' => $pagata ? 0.0 : max(0.0, round($totale - $ritenuta - $ric, 2)), 'pagata' => $pagata,
                'incarico_id' => isset($r['incarico_id']) && $r['incarico_id'] !== null ? (int)$r['incarico_id'] : null,
                'data_scadenza' => $r['data_scadenza'] ?? null, 'sottocliente_nome' => $r['sottocliente_nome'] ?? null,
            ];
            $k = $tipo === 'fattura'
                ? strtoupper(trim((string)$r['numero'])) . '|' . substr((string)$r['data_emissione'], 0, 4) . '|' . ($r['anagrafica_id'] ?? '')
                : 'p' . $r['id'];
            if (!isset($docs[$k])) {
                $docs[$k] = [
                    'tipo' => $tipo, 'id' => (int)$r['id'], 'numero' => (string)$r['numero'],
                    'data_emissione' => $r['data_emissione'], 'data_scadenza' => $r['data_scadenza'] ?? null,
                    'anagrafica_id' => $r['anagrafica_id'] !== null ? (int)$r['anagrafica_id'] : null,
                    'anagrafica_nome' => (string)$r['anagrafica_nome'], 'sottoclienti' => [],
                    'totale' => 0.0, 'ritenuta' => 0.0, 'riconciliato' => 0.0, 'residuo' => 0.0, 'pagata' => true, 'righe' => [],
                ];
            }
            $d = &$docs[$k];
            $d['righe'][] = $riga;
            $d['totale'] = round($d['totale'] + $totale, 2);
            $d['ritenuta'] = round($d['ritenuta'] + $ritenuta, 2);
            $d['riconciliato'] = round($d['riconciliato'] + $ric, 2);
            $d['residuo'] = round($d['residuo'] + $riga['residuo'], 2);
            $d['pagata'] = $d['pagata'] && $pagata;
            if ($riga['data_scadenza'] && (!$d['data_scadenza'] || $riga['data_scadenza'] < $d['data_scadenza'])) $d['data_scadenza'] = $riga['data_scadenza'];
            if ($riga['sottocliente_nome']) $d['sottoclienti'][] = $riga['sottocliente_nome'];
            unset($d);
        }
        return array_values($docs);
    }
}
