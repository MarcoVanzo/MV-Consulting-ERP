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
 * Fuori dalla riconciliazione: registro agenzia viaggi (numeri "NNAV" / sezionale 002).
 * Le note di credito (importo negativo) sono documenti con residuo negativo: entrano nelle combinazioni
 * fattura − nota di credito dello stesso intestatario, mai da sole.
 */
declare(strict_types=1);

require_once __DIR__ . '/RiconciliazioneMatch.php';
require_once __DIR__ . '/EstrattoContoParser.php';

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
            $sql = $this->selectFatture() . " WHERE f.stato <> 'pagata' AND f.importo_totale <> 0"
                . ($anagraficaId ? ' AND f.cliente_id = ?' : '') . " ORDER BY COALESCE(f.data_scadenza, f.data_emissione), f.id";
        } else {
            $sql = $this->selectPassive() . " WHERE fp.stato = 'da_pagare' AND fp.importo_totale <> 0"
                . ($anagraficaId ? ' AND fp.fornitore_id = ?' : '') . " ORDER BY COALESCE(fp.data_scadenza, fp.data_emissione), fp.id";
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($anagraficaId ? [$anagraficaId] : []);
        $docs = $this->raggruppa($tipo, $stmt->fetchAll(PDO::FETCH_ASSOC));
        return array_values(array_filter($docs, fn($d) => abs($d['residuo']) > 0.005 && !RiconciliazioneMatch::escluso($d['numero'])));
    }

    /**
     * Documenti segnati pagati a mano senza bonifico: «scoperto» = le righe pagate senza nessuna riconciliazione.
     * Un bonifico arrivato dopo, o pagato dall'altro conto e poi visto qui, vi si può ancora abbinare: il residuo
     * della forma comune vale lo scoperto e gia_pagata = true. Emissione tra $dal e $al. Fuori le fatture emesse
     * con qualche riga ancora aperta: sono tra gli aperti(), e il pagamento andrebbe su quelle righe.
     */
    public function pagateScoperte(string $tipo, string $dal, string $al, ?int $anagraficaId = null): array
    {
        $t = $tipo === 'fattura' ? 'f' : 'fp';
        $anag = $tipo === 'fattura' ? 'f.cliente_id' : 'fp.fornitore_id';
        $sql = ($tipo === 'fattura' ? $this->selectFatture() : $this->selectPassive())
            . " WHERE $t.stato = 'pagata' AND $t.importo_totale > 0 AND $t.data_emissione BETWEEN ? AND ?"
            . ($anagraficaId ? " AND $anag = ?" : '') . " ORDER BY $t.data_emissione, $t.id";
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($anagraficaId ? [$dal, $al, $anagraficaId] : [$dal, $al]);
        $conAperte = [];
        if ($tipo === 'fattura') {
            $st = $this->pdo->prepare("SELECT DISTINCT numero_fattura, cliente_id, data_emissione FROM {$this->p}fatture
                WHERE stato <> 'pagata' AND data_emissione BETWEEN ? AND ?");
            $st->execute([substr($dal, 0, 4) . '-01-01', substr($al, 0, 4) . '-12-31']);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $conAperte[strtoupper(trim((string)$r['numero_fattura'])) . '|' . substr((string)$r['data_emissione'], 0, 4) . '|' . ($r['cliente_id'] ?? '')] = true;
            }
        }
        $out = [];
        foreach ($this->raggruppa($tipo, $stmt->fetchAll(PDO::FETCH_ASSOC)) as $d) {
            if (!$d['pagata'] || $d['scoperto'] <= 0.005 || RiconciliazioneMatch::escluso($d['numero'])) continue;
            if (isset($conAperte[strtoupper(trim($d['numero'])) . '|' . substr((string)$d['data_emissione'], 0, 4) . '|' . ($d['anagrafica_id'] ?? '')])) continue;
            $out[] = ['residuo' => $d['scoperto'], 'gia_pagata' => true] + $d;
        }
        return $out;
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

    /**
     * Movimenti con le loro riconciliazioni (tre query in tutto). Filtri: stato, origine, dal, al (su data valuta)
     * e, con le categorie attive: abbinabili, classificazione, categoria_id ('nessuna' = non classificati), tipo.
     */
    public function movimenti(array $f): array
    {
        require_once __DIR__ . '/Classificatore.php';
        $cat = Classificatore::tabellePresenti($this->pdo, $this->p);
        $where = [];
        $params = [];
        if (!empty($f['stato'])) { $where[] = 'm.stato = ?'; $params[] = $f['stato']; }
        if (!empty($f['origine'])) { $where[] = 'm.origine = ?'; $params[] = $f['origine']; }
        if (!empty($f['dal'])) { $where[] = 'COALESCE(m.data_valuta, m.data_operazione) >= ?'; $params[] = $f['dal']; }
        if (!empty($f['al'])) { $where[] = 'COALESCE(m.data_valuta, m.data_operazione) <= ?'; $params[] = $f['al']; }
        if (($f['tipo'] ?? '') === 'entrata') $where[] = 'm.importo > 0';
        if (($f['tipo'] ?? '') === 'uscita') $where[] = 'm.importo < 0';
        if ($cat) {
            if (!empty($f['abbinabili'])) $where[] = Classificatore::sqlDaAbbinare($this->p, 'm');
            if (!empty($f['classificazione'])) { $where[] = 'm.classificazione = ?'; $params[] = $f['classificazione']; }
            if (($f['categoria_id'] ?? '') === 'nessuna') $where[] = 'm.categoria_id IS NULL';
            elseif (!empty($f['categoria_id'])) { $where[] = 'm.categoria_id = ?'; $params[] = (int)$f['categoria_id']; }
        }
        $select = $cat
            ? "SELECT m.*, c.nome AS categoria_nome, c.colore AS categoria_colore, cp.nome AS proposta_nome, rg.chiave AS regola_chiave
                FROM {$this->p}movimenti_banca m
                LEFT JOIN {$this->p}categorie_movimento c ON c.id = m.categoria_id
                LEFT JOIN {$this->p}categorie_movimento cp ON cp.id = m.categoria_proposta_id
                LEFT JOIN {$this->p}regole_categoria rg ON rg.id = m.regola_id"
            : "SELECT m.* FROM {$this->p}movimenti_banca m";
        $stmt = $this->pdo->prepare($select . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
            . " ORDER BY COALESCE(m.data_valuta, m.data_operazione) DESC, m.id DESC LIMIT 1000");
        $stmt->execute($params);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $ric = $this->delMovimenti(array_column($rows, 'id'));
        foreach ($rows as &$r) {
            $r['importo'] = (float)$r['importo'];
            $r['riconciliazioni'] = $ric[(int)$r['id']] ?? [];
            if ($cat) $r['chiave_suggerita'] = Classificatore::chiaveSuggerita((string)$r['descrizione'], $r['controparte']);
        }
        unset($r);
        return $rows;
    }

    /** Riconciliazioni di un movimento, una riga per documento (i record della stessa fattura sommati). */
    public function delMovimento(int $movimentoId): array
    {
        return $this->delMovimenti([$movimentoId])[$movimentoId] ?? [];
    }

    /** Come delMovimento per molti movimenti con due query in tutto: [movimento_id => [...]]. */
    public function delMovimenti(array $ids): array
    {
        $ids = array_values(array_unique(array_filter(array_map('intval', $ids))));
        if (!$ids) return [];
        $in = implode(',', array_fill(0, count($ids), '?'));
        $stmt = $this->pdo->prepare("SELECT r.movimento_id, r.tipo, r.documento_id, r.importo, r.metodo,
                COALESCE(f.numero_fattura, fp.numero) AS numero, COALESCE(f.data_emissione, fp.data_emissione) AS data_emissione,
                COALESCE(c.ragione_sociale, fo.ragione_sociale, '') AS anagrafica_nome, f.cliente_id, f.incarico_id
            FROM {$this->p}riconciliazioni r
            LEFT JOIN {$this->p}fatture f ON r.tipo = 'fattura' AND f.id = r.documento_id
            LEFT JOIN {$this->p}clienti c ON c.id = f.cliente_id
            LEFT JOIN {$this->p}fatture_passive fp ON r.tipo = 'fattura_passiva' AND fp.id = r.documento_id
            LEFT JOIN {$this->p}fornitori fo ON fo.id = fp.fornitore_id
            WHERE r.movimento_id IN ($in) ORDER BY r.id");
        $stmt->execute($ids);
        $out = [];
        $incarichi = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $mid = (int)$r['movimento_id'];
            $k = $r['tipo'] . '|' . $r['numero'] . '|' . substr((string)$r['data_emissione'], 0, 4) . '|' . $r['anagrafica_nome'];
            if (!isset($out[$mid][$k])) {
                $out[$mid][$k] = ['tipo' => $r['tipo'], 'id' => (int)$r['documento_id'], 'numero' => (string)$r['numero'],
                    'data_emissione' => $r['data_emissione'], 'anagrafica_nome' => $r['anagrafica_nome'],
                    'cliente_id' => $r['cliente_id'] !== null ? (int)$r['cliente_id'] : null,
                    'importo' => 0.0, 'metodo' => $r['metodo'], 'righe' => 0, 'incarichi' => []];
            }
            $out[$mid][$k]['importo'] = round($out[$mid][$k]['importo'] + (float)$r['importo'], 2);
            $out[$mid][$k]['righe']++;
            if ($r['incarico_id']) { $out[$mid][$k]['incarichi'][(int)$r['incarico_id']] = true; $incarichi[(int)$r['incarico_id']] = true; }
        }
        $rate = $this->rateIncarichi(array_keys($incarichi));
        foreach ($out as $mid => $docs) {
            foreach ($docs as $k => $d) {
                $out[$mid][$k]['incarichi'] = array_map(fn($id) => $rate[$id], array_keys($d['incarichi']));
            }
            $out[$mid] = array_values($out[$mid]);
        }
        return $out;
    }

    /** Rate incassate dell'incarico (la rata è incassata quando la sua fattura è pagata). */
    public function rateIncarico(int $incaricoId): array
    {
        return $this->rateIncarichi([$incaricoId])[$incaricoId];
    }

    /** [incarico_id => {id, rate_totali, rate_incassate}] con una query. */
    public function rateIncarichi(array $ids): array
    {
        $out = [];
        foreach ($ids as $id) $out[(int)$id] = ['id' => (int)$id, 'rate_totali' => 0, 'rate_incassate' => 0];
        if (!$out) return [];
        $in = implode(',', array_fill(0, count($out), '?'));
        $stmt = $this->pdo->prepare("SELECT r.incarico_id, COUNT(r.id) AS rate_totali,
                COALESCE(SUM(CASE WHEN f.stato = 'pagata' THEN 1 ELSE 0 END), 0) AS rate_incassate
            FROM {$this->p}incarichi_rate r LEFT JOIN {$this->p}fatture f ON f.id = r.fattura_id
            WHERE r.incarico_id IN ($in) GROUP BY r.incarico_id");
        $stmt->execute(array_keys($out));
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $out[(int)$r['incarico_id']]['rate_totali'] = (int)$r['rate_totali'];
            $out[(int)$r['incarico_id']]['rate_incassate'] = (int)$r['rate_incassate'];
        }
        return $out;
    }

    // ── Deduplica e avvisi (usati dal Riconciliatore) ──

    /** La colonna esiste? (migrazioni v064+ lanciate a mano, anche dopo il deploy del codice) */
    public static function colonna(PDO $pdo, string $tabella, string $colonna): bool
    {
        static $cache = [];
        $k = "$tabella.$colonna";
        if (!isset($cache[$k])) {
            try { $pdo->query("SELECT $colonna FROM $tabella WHERE 1 = 0"); $cache[$k] = true; }
            catch (Throwable $e) { $cache[$k] = false; }
        }
        return $cache[$k];
    }

    /**
     * Il movimento c'è già? 'hash' (stesso hash, anche nella forma della prima versione) oppure
     * 'altro_formato': stesso estratto importato prima in XML e poi in PDF (o viceversa) —
     * stessa data, stesso importo con segno e causale simile, ma l'uno con riferimento banca e l'altro no.
     */
    public function giaPresente(array $m, string $origine = 'estratto_conto'): ?string
    {
        $hash = array_values(array_filter([$m['hash_riga'], EstrattoContoParser::hashRigaV1($m)]));
        $stmt = $this->pdo->prepare("SELECT id FROM {$this->p}movimenti_banca WHERE hash_riga IN (" . implode(',', array_fill(0, count($hash), '?')) . ")");
        $stmt->execute($hash);
        if ($stmt->fetchColumn()) return 'hash';
        if ($origine !== 'estratto_conto') return null; // la carta ha un solo formato: basta l'hash
        $stmt = $this->pdo->prepare("SELECT descrizione, controparte FROM {$this->p}movimenti_banca
            WHERE origine = 'estratto_conto' AND data_operazione = ? AND importo BETWEEN ? AND ?
              AND riferimento_banca IS " . (empty($m['riferimento']) ? 'NOT NULL' : 'NULL'));
        $imp = round((float)$m['importo'], 2);
        $stmt->execute([$m['data_operazione'], $imp - 0.005, $imp + 0.005]);
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $e) {
            if (RiconciliazioneMatch::causaliSimili($m['descrizione'] . ' ' . ($m['controparte'] ?? ''), $e['descrizione'] . ' ' . ($e['controparte'] ?? ''))) {
                return 'altro_formato';
            }
        }
        return null;
    }

    /** Avviso se nel periodo del file ci sono già movimenti dello stesso conto importati dall'altro formato. */
    public function sovrapposizioni(array $movimenti, array $conto): array
    {
        if (!$movimenti) return [];
        $date = array_column($movimenti, 'data_operazione');
        $conRif = count(array_filter($movimenti, fn($m) => !empty($m['riferimento']))) * 2 >= count($movimenti);
        $iban = (string)($conto['iban'] ?? '');
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->p}movimenti_banca WHERE origine = 'estratto_conto'
            AND data_operazione BETWEEN ? AND ? AND riferimento_banca IS " . ($conRif ? 'NULL' : 'NOT NULL')
            . ($iban !== '' ? " AND (iban = ? OR iban IS NULL OR iban = '')" : ''));
        $stmt->execute(array_merge([min($date), max($date)], $iban !== '' ? [$iban] : []));
        $n = (int)$stmt->fetchColumn();
        return $n ? ["Nel periodo del file ci sono già $n movimenti dello stesso conto importati " . ($conRif ? 'da PDF' : 'da XML CBI')
            . ': i doppioni riconoscibili (stessa data, importo e causale simile) sono stati saltati, controlla gli altri.'] : [];
    }

    /** Avvisi vivi (con fatture, non annullati), non ancora visti in banca, stesso importo e valuta entro ±5 giorni. */
    public function avvisiPerAccredito(array $mov): array
    {
        $stmt = $this->pdo->prepare("SELECT a.* FROM {$this->p}movimenti_banca a
            WHERE a.origine = 'avviso_pagamento' AND a.stato <> 'ignorato' AND a.importo BETWEEN ? AND ?
              AND EXISTS (SELECT 1 FROM {$this->p}riconciliazioni r WHERE r.movimento_id = a.id)
              AND NOT EXISTS (SELECT 1 FROM {$this->p}movimenti_banca b WHERE b.avviso_id = a.id)");
        $imp = abs((float)$mov['importo']);
        $stmt->execute([$imp - 0.01, $imp + 0.01]);
        $data = $mov['data_valuta'] ?: $mov['data_operazione'];
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
            fn($a) => RiconciliazioneMatch::giorni($data, $a['data_valuta'] ?: $a['data_operazione']) <= Riconciliatore::GIORNI_AVVISO));
    }

    /** Accrediti dell'estratto conto ancora liberi che corrispondono a un avviso. */
    public function accreditiPerAvviso(array $avv): array
    {
        $stmt = $this->pdo->prepare("SELECT m.* FROM {$this->p}movimenti_banca m
            WHERE m.origine = 'estratto_conto' AND m.stato = 'da_riconciliare' AND m.avviso_id IS NULL AND m.importo BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM {$this->p}riconciliazioni r WHERE r.movimento_id = m.id)");
        $imp = abs((float)$avv['importo']);
        $stmt->execute([$imp - 0.01, $imp + 0.01]);
        $data = $avv['data_valuta'] ?: $avv['data_operazione'];
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
            fn($m) => RiconciliazioneMatch::giorni($data, $m['data_valuta'] ?: $m['data_operazione']) <= Riconciliatore::GIORNI_AVVISO));
    }

    /** Voci per il subset-sum: il residuo e, se la ritenuta non è stata trattenuta, anche il lordo. */
    public static function voci(array $docs): array
    {
        $voci = [];
        foreach ($docs as $d) {
            $opz = [(int)round($d['residuo'] * 100)];
            if ($d['residuo'] > 0 && $d['ritenuta'] > 0 && $d['riconciliato'] <= 0) $opz[] = (int)round(($d['residuo'] + $d['ritenuta']) * 100);
            $voci[] = ['id' => $d['id'], 'opzioni' => array_values(array_unique($opz))];
        }
        return $voci;
    }

    public static function proposta(array $d, float $importo): array
    {
        return ['tipo' => $d['tipo'], 'id' => $d['id'], 'numero' => $d['numero'], 'anagrafica_nome' => $d['anagrafica_nome'],
            'sottoclienti' => $d['sottoclienti'], 'righe' => count($d['righe']),
            'data_emissione' => $d['data_emissione'], 'data_scadenza' => $d['data_scadenza'],
            'residuo' => $d['residuo'], 'importo' => round($importo, 2), 'nota_credito' => $d['residuo'] < 0,
            'gia_pagata' => !empty($d['gia_pagata'])];
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
                // Nota di credito: residuo negativo (quanto resta da compensare)
                'residuo' => $pagata ? 0.0 : ($totale < 0 ? min(0.0, round($totale - $ric, 2)) : max(0.0, round($totale - $ritenuta - $ric, 2))),
                // Segnata pagata senza nessun bonifico (una chiusa con differenza o tolleranza ne ha almeno uno: non è scoperta)
                'scoperto' => $pagata && $totale > 0 && abs($ric) <= 0.005 ? round($totale - $ritenuta, 2) : 0.0,
                'pagata' => $pagata, 'stato' => (string)$r['stato'],
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
                    'totale' => 0.0, 'ritenuta' => 0.0, 'riconciliato' => 0.0, 'residuo' => 0.0, 'scoperto' => 0.0, 'pagata' => true, 'righe' => [],
                ];
            }
            $d = &$docs[$k];
            $d['righe'][] = $riga;
            $d['totale'] = round($d['totale'] + $totale, 2);
            $d['ritenuta'] = round($d['ritenuta'] + $ritenuta, 2);
            $d['riconciliato'] = round($d['riconciliato'] + $ric, 2);
            $d['residuo'] = round($d['residuo'] + $riga['residuo'], 2);
            $d['scoperto'] = round($d['scoperto'] + $riga['scoperto'], 2);
            $d['pagata'] = $d['pagata'] && $pagata;
            if ($riga['data_scadenza'] && (!$d['data_scadenza'] || $riga['data_scadenza'] < $d['data_scadenza'])) $d['data_scadenza'] = $riga['data_scadenza'];
            if ($riga['sottocliente_nome']) $d['sottoclienti'][] = $riga['sottocliente_nome'];
            unset($d);
        }
        return array_values($docs);
    }
}
