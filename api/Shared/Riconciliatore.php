<?php
/**
 * Riconciliatore — abbina i movimenti bancari alle fatture.
 *
 *   accredito (+) → fatture emesse non pagate (per documento: numero + anno + cliente, vedi RiconciliazioneDocumenti)
 *   addebito  (−) → fatture passive da pagare (partner di commessa o fornitori generici)
 *
 * All'import si salvano tutti i movimenti (le categorie li classificano, vedi Classificatore.php);
 * l'abbinamento alle fatture si tenta solo su quelli con un aggancio: numero di fattura in causale,
 * controparte riconosciuta tra i clienti (accrediti) o i fornitori (addebiti), avviso in attesa.
 * Gli altri hanno abbinabile = 0 e restano fuori dalla coda "da riconciliare".
 *
 * Modalità "automatico se sicuro" — si registra da solo solo se:
 *   (a) un solo avviso di pagamento registrato con lo stesso importo e valuta entro ±5 giorni;
 *   (b) i numeri in causale danno un'unica combinazione di fatture che fa l'importo e le copre tutte;
 *   (c) causale troncata: i numeri letti + un'unica combinazione di altre fatture aperte dello
 *       stesso cliente con la stessa data documento fanno l'importo;
 *   (d) nessun numero, cliente/fornitore riconosciuto e un solo sottoinsieme delle sue fatture aperte.
 * Altrimenti il movimento resta da riconciliare con al massimo 5 proposte.
 *
 * SQL portabile (MySQL in produzione, SQLite nel test CLI): niente YEAR(), DATEDIFF(), NOW().
 */
declare(strict_types=1);

require_once __DIR__ . '/AnagraficaMatcher.php';
require_once __DIR__ . '/RiconciliazioneMatch.php';
require_once __DIR__ . '/RiconciliazioneDocumenti.php';
require_once __DIR__ . '/EstrattoContoParser.php';
require_once __DIR__ . '/Classificatore.php';

class Riconciliatore
{
    public const GIORNI_AVVISO = 5;
    public const MAX_APERTE = 20;
    public const MAX_PROPOSTE = 5;

    private $pdo;
    private $p;
    private $docs;
    /** @var callable|null fn(int $fatturaId): void — rata e incarico del record dopo il cambio di stato */
    private $dopoFattura;

    public function __construct(PDO $pdo, string $prefix, ?callable $dopoFattura = null)
    {
        $this->pdo = $pdo;
        $this->p = $prefix;
        $this->docs = new RiconciliazioneDocumenti($pdo, $prefix);
        $this->dopoFattura = $dopoFattura;
    }

    public function documenti(): RiconciliazioneDocumenti
    {
        return $this->docs;
    }

    /** Le tabelle esistono? (codice online prima che la migrazione sia lanciata) */
    public static function tabellePresenti(PDO $pdo, string $prefix): bool
    {
        try {
            $pdo->query("SELECT 1 FROM {$prefix}movimenti_banca WHERE 1 = 0");
            $pdo->query("SELECT 1 FROM {$prefix}riconciliazioni WHERE 1 = 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    // ── Import ──────────────────────────────────────────

    /**
     * Salva i movimenti pertinenti nuovi (deduplica su hash_riga) e prova l'abbinamento automatico.
     * $conto: {banca, iban, file_nome}. Va chiamato dentro una transazione del chiamante.
     */
    public function importaMovimenti(array $movimenti, array $conto, ?int $userId): array
    {
        $out = ['letti' => count($movimenti), 'nuovi' => 0, 'gia_presenti' => 0, 'senza_aggancio' => 0,
            'abbinati' => 0, 'da_verificare' => 0, 'ids' => [], 'movimenti' => []];
        $conCategorie = Classificatore::tabellePresenti($this->pdo, $this->p);
        foreach (EstrattoContoParser::conHash($movimenti) as $m) {
            $stmt = $this->pdo->prepare("SELECT id FROM {$this->p}movimenti_banca WHERE hash_riga = ?");
            $stmt->execute([$m['hash_riga']]);
            if ($stmt->fetchColumn()) { $out['gia_presenti']++; continue; }

            // Si salvano tutti i movimenti (servono per categorie e grafici); si prova l'abbinamento
            // alle fatture solo se c'è un aggancio: numero in causale, cliente/fornitore, avviso in attesa
            $mov = $m + ['id' => 0, 'origine' => 'estratto_conto', 'controparte' => null];
            $ctx = $this->contesto($mov);
            $abbinabile = $ctx['refs'] || $ctx['anagrafica_id'] || $ctx['avvisi'];
            if (!$abbinabile && !$conCategorie) { $out['senza_aggancio']++; continue; } // schema vecchio: come prima
            $id = $this->inserisciMovimento($m, $conto, 'estratto_conto', $abbinabile);
            $out['nuovi']++;
            $out['ids'][] = $id;
            if (!$abbinabile) { $out['senza_aggancio']++; continue; }
            $mov = $this->movimento($id);
            $esito = ['id' => $id, 'data' => $mov['data_valuta'], 'importo' => (float)$mov['importo'],
                'descrizione' => $mov['descrizione'], 'esito' => 'da_verificare', 'dettaglio' => '', 'proposte' => []];

            $an = $this->analizza($mov, !empty($m['segno_incerto']), $ctx);
            if ($an['avviso_id']) {
                $this->collegaAvviso($id, $an['avviso_id']);
                $esito['esito'] = 'abbinato';
                $esito['dettaglio'] = 'Avviso di pagamento già registrato';
                $out['abbinati']++;
            } elseif ($an['sicuro']) {
                $this->registra($id, $an['sicuro'], 'auto', $userId);
                $esito['esito'] = 'abbinato';
                $esito['dettaglio'] = implode(', ', array_map(fn($d) => $d['numero'], $an['sicuro']));
                $out['abbinati']++;
            } else {
                $esito['proposte'] = $an['proposte'];
                $out['da_verificare']++;
            }
            $out['movimenti'][] = $esito;
        }
        return $out;
    }

    /**
     * Avviso di pagamento del cliente (es. "Pagamento Fornitore" Unindustria): movimento atteso con le
     * sue riconciliazioni. Se l'accredito è già sull'estratto conto, lo collega (nessun doppio pagamento).
     * $documenti: [{tipo:'fattura', id, importo|null}] (null = tutto il residuo). Restituisce l'id dell'avviso.
     * $tolleranza: differenza ammessa tra quanto pagato e il residuo della fattura (arrotondamenti del cliente).
     */
    public function registraAvviso(array $avviso, array $documenti, ?int $userId, float $tolleranza = 0.01): int
    {
        $m = [
            'data_operazione' => $avviso['data'], 'data_valuta' => $avviso['data'],
            'importo' => round(abs((float)$avviso['importo']), 2),
            'descrizione' => (string)$avviso['descrizione'], 'controparte' => $avviso['controparte'] ?? null,
        ];
        $m['hash_riga'] = EstrattoContoParser::hashRiga($m);
        $stmt = $this->pdo->prepare("SELECT id FROM {$this->p}movimenti_banca WHERE hash_riga = ?");
        $stmt->execute([$m['hash_riga']]);
        $id = (int)$stmt->fetchColumn();
        if (!$id) $id = $this->inserisciMovimento($m, ['file_nome' => $avviso['file_nome'] ?? ''], 'avviso_pagamento');
        if ($documenti) $this->registra($id, $documenti, 'auto', $userId, $tolleranza);

        $cand = $this->accreditiPerAvviso($this->movimento($id));
        if (count($cand) === 1) $this->collegaAvviso((int)$cand[0]['id'], $id);
        return $id;
    }

    // ── Analisi ─────────────────────────────────────────

    /** Riferimenti in causale, intestatario riconosciuto e avvisi in attesa per un movimento. */
    public function contesto(array $mov): array
    {
        $testo = trim($mov['descrizione'] . ' ' . ($mov['controparte'] ?? ''));
        $accredito = (float)$mov['importo'] > 0;
        return [
            'testo' => $testo,
            'refs' => RiconciliazioneMatch::estraiRiferimenti($testo),
            'anagrafica_id' => $accredito
                ? AnagraficaMatcher::trovaCliente($this->pdo, $this->p, null, null, $testo, true)
                : $this->trovaFornitore($testo),
            'avvisi' => $accredito && ($mov['origine'] ?? 'estratto_conto') === 'estratto_conto' ? $this->avvisiPerAccredito($mov) : [],
        ];
    }

    /**
     * @return array{sicuro: ?array, avviso_id: ?int, proposte: array}  sicuro = [{tipo,id,numero,importo,...}]
     */
    public function analizza(array $mov, bool $segnoIncerto = false, ?array $ctx = null): array
    {
        $ctx = $ctx ?? $this->contesto($mov);
        $tipo = (float)$mov['importo'] > 0 ? 'fattura' : 'fattura_passiva';
        $data = $mov['data_valuta'] ?: $mov['data_operazione'];
        $anagId = $ctx['anagrafica_id'];
        $res = ['sicuro' => null, 'avviso_id' => null, 'proposte' => []];
        $proposte = [];
        $puoi = !$segnoIncerto;

        // (a) Avvisi di pagamento in attesa
        if ($ctx['avvisi'] && (int)$mov['id'] && $this->riconciliatoSulMovimento((int)$mov['id']) > 0) $ctx['avvisi'] = [];
        if (count($ctx['avvisi']) === 1 && $puoi) {
            $res['avviso_id'] = (int)$ctx['avvisi'][0]['id'];
            return $res;
        }
        foreach ($ctx['avvisi'] as $a) {
            $proposte[] = ['tipo' => 'avviso', 'avviso_id' => (int)$a['id'], 'punteggio' => 95, 'totale' => (float)$a['importo'],
                'motivi' => ['Avviso di pagamento del ' . $a['data_valuta']], 'documenti' => $this->docs->delMovimento((int)$a['id'])];
        }

        $cent = (int)round(abs((float)$mov['importo']) * 100) - (int)round($this->riconciliatoSulMovimento((int)$mov['id']) * 100);
        if ($cent <= 0) return $res;
        $aperti = $this->docs->aperti($tipo);
        $perId = [];
        foreach ($aperti as $d) $perId[$d['id']] = $d;

        // Candidati dai numeri in causale (dello stesso intestatario, se riconosciuto e se ce ne sono)
        $perRef = [];
        foreach ($ctx['refs'] as $i => $ref) {
            foreach ($aperti as $d) {
                if (RiconciliazioneMatch::corrisponde($ref, $d['numero'], $d['data_emissione'])) $perRef[$i][] = $d['id'];
            }
        }
        if ($anagId) {
            $filtrati = array_map(fn($ids) => array_values(array_filter($ids, fn($id) => $perId[$id]['anagrafica_id'] === $anagId)), $perRef);
            if (array_filter($filtrati)) $perRef = $filtrati;
        }
        $perRef = array_filter($perRef);
        $cand = [];
        foreach ($perRef as $ids) foreach ($ids as $id) $cand[$id] = $perId[$id];

        if ($cand) {
            // (b) un'unica combinazione dei candidati che copre tutti i numeri letti
            $sol = RiconciliazioneMatch::subsetSum(RiconciliazioneDocumenti::voci(array_slice($cand, 0, self::MAX_APERTE, true)), $cent, 4);
            $valide = array_values(array_filter($sol['soluzioni'], function ($s) use ($perRef) {
                foreach ($perRef as $ids) if (!array_intersect($ids, array_keys($s))) return false;
                return true;
            }));
            if ($puoi && count($valide) === 1 && !$sol['troncato']) {
                $res['sicuro'] = $this->daSoluzione($valide[0], $perId);
                return $res;
            }
            foreach ($valide as $s) {
                $proposte[] = $this->propostaMultipla($s, $perId, 85, 'Numeri in causale');
            }

            // (c) causale troncata: completamento con fatture dello stesso cliente e stessa data documento
            $univoci = !array_filter($perRef, fn($ids) => count($ids) !== 1);
            $clienti = array_unique(array_map(fn($d) => $d['anagrafica_id'], $cand));
            $somma = array_sum(array_map(fn($d) => (int)round($d['residuo'] * 100), $cand));
            if ($univoci && count($clienti) === 1 && reset($clienti) && $somma < $cent) {
                $date = array_unique(array_merge(array_column($cand, 'data_emissione'),
                    array_filter(array_map(fn($r) => $r['data'], $ctx['refs']))));
                $pool = array_filter($aperti, fn($d) => $d['anagrafica_id'] === reset($clienti) && !isset($cand[$d['id']])
                    && in_array($d['data_emissione'], $date, true));
                $sol2 = RiconciliazioneMatch::subsetSum(RiconciliazioneDocumenti::voci(array_slice($pool, 0, self::MAX_APERTE)), $cent - $somma, 3);
                $base = [];
                foreach ($cand as $d) $base[$d['id']] = (int)round($d['residuo'] * 100);
                if ($puoi && count($sol2['soluzioni']) === 1 && !$sol2['troncato']) {
                    $res['sicuro'] = $this->daSoluzione($base + $sol2['soluzioni'][0], $perId);
                    return $res;
                }
                foreach ($sol2['soluzioni'] as $s) {
                    $proposte[] = $this->propostaMultipla($base + $s, $perId, 75, 'Causale troncata: completata con fatture della stessa data');
                }
            }
        } elseif ($anagId) {
            // (d) solo l'intestatario: un unico sottoinsieme delle sue fatture aperte
            $suoi = array_filter($aperti, fn($d) => $d['anagrafica_id'] === $anagId);
            $sol = RiconciliazioneMatch::subsetSum(RiconciliazioneDocumenti::voci(array_slice($suoi, 0, self::MAX_APERTE)), $cent, 3);
            if ($puoi && count($sol['soluzioni']) === 1 && !$sol['troncato']) {
                $res['sicuro'] = $this->daSoluzione($sol['soluzioni'][0], $perId);
                return $res;
            }
            foreach ($sol['soluzioni'] as $s) {
                if (count($s) > 1) $proposte[] = $this->propostaMultipla($s, $perId, 70, 'Somma esatta di fatture dello stesso intestatario');
            }
        }

        // Singole fatture con punteggio
        foreach ($aperti as $d) {
            $p = RiconciliazioneMatch::punteggio($d, $cent, isset($cand[$d['id']]), $anagId, $data);
            if ($p['punteggio'] < 25) continue;
            $imp = ($p['opzione'] ?? min($cent, (int)round($d['residuo'] * 100))) / 100;
            $proposte[] = ['tipo' => 'documenti', 'punteggio' => $p['punteggio'], 'totale' => $imp, 'motivi' => $p['motivi'],
                'documenti' => [RiconciliazioneDocumenti::proposta($d, $imp)]];
        }
        usort($proposte, fn($a, $b) => $b['punteggio'] <=> $a['punteggio']);
        $res['proposte'] = array_slice($proposte, 0, self::MAX_PROPOSTE);
        return $res;
    }

    public function proposte(int $movimentoId): array
    {
        $mov = $this->movimento($movimentoId);
        if ($mov['stato'] === 'ignorato' || (int)($mov['avviso_id'] ?? 0)) return [];
        return $this->analizza($mov, true)['proposte'];
    }

    // ── Scritture ───────────────────────────────────────

    /**
     * Registra i pagamenti del movimento. $documenti: [{tipo, id, importo|null}] — id di un record qualsiasi
     * del documento; l'importo si distribuisce sui suoi record aperti in ordine (null = tutto il residuo).
     * I record saldati diventano pagati con la data valuta e il loro incarico/rata si aggiorna.
     * $tolleranza: scarto ammesso per considerare saldata una fattura (default 1 centesimo).
     * Transazione propria se il chiamante non ne ha una. Restituisce i documenti saldati.
     */
    public function registra(int $movimentoId, array $documenti, string $metodo, ?int $userId, float $tolleranza = 0.01): array
    {
        $mov = $this->movimento($movimentoId);
        if ($mov['stato'] === 'ignorato') throw new RuntimeException('Movimento ignorato: ripristinalo prima di abbinarlo');
        if ((int)($mov['avviso_id'] ?? 0)) throw new RuntimeException('Movimento già coperto da un avviso di pagamento');
        $tipoAtteso = (float)$mov['importo'] > 0 ? 'fattura' : 'fattura_passiva';
        $disponibile = round(abs((float)$mov['importo']) - $this->riconciliatoSulMovimento($movimentoId), 2);

        $piano = [];
        $visti = [];
        $totale = 0.0;
        foreach ($documenti as $d) {
            $tipo = (string)($d['tipo'] ?? '');
            if ($tipo !== $tipoAtteso) {
                throw new RuntimeException($tipoAtteso === 'fattura' ? 'Un accredito si abbina solo a fatture emesse' : 'Un addebito si abbina solo a fatture di fornitori');
            }
            $doc = $this->docs->documento($tipo, (int)($d['id'] ?? 0));
            if (!$doc) throw new RuntimeException('Documento non trovato');
            $chiave = $tipo . '|' . $doc['id'];
            if (isset($visti[$chiave])) throw new RuntimeException('Fattura ' . $doc['numero'] . ' indicata due volte');
            $visti[$chiave] = true;
            if ($doc['residuo'] <= 0.005) throw new RuntimeException('Fattura ' . $doc['numero'] . ' già pagata');
            $imp = isset($d['importo']) && $d['importo'] !== '' && $d['importo'] !== null ? round((float)$d['importo'], 2) : $doc['residuo'];
            if ($imp <= 0) throw new RuntimeException('Importo non valido');
            if ($imp > $doc['residuo'] + max(0.01, $tolleranza)) throw new RuntimeException('Importo oltre il residuo della fattura ' . $doc['numero']);
            $imp = min($imp, $doc['residuo']);
            $totale += $imp;
            $piano[] = [$doc, $imp];
        }
        if (!$piano) throw new RuntimeException('Nessun documento da abbinare');
        if ($totale > $disponibile + max(0.01, $tolleranza * count($piano))) throw new RuntimeException('La somma supera l\'importo del movimento');

        $saldati = [];
        $tollCent = max(1, (int)round($tolleranza * 100));
        $this->transazione(function () use ($movimentoId, $piano, $metodo, $userId, $mov, $disponibile, $totale, $tolleranza, $tollCent, &$saldati) {
            $ins = $this->pdo->prepare("INSERT INTO {$this->p}riconciliazioni (movimento_id, tipo, documento_id, importo, metodo, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $dataPag = $mov['data_valuta'] ?: $mov['data_operazione'];
            foreach ($piano as [$doc, $imp]) {
                $resto = (int)round($imp * 100);
                $aperte = array_values(array_filter($doc['righe'], fn($r) => $r['residuo'] > 0.005));
                foreach ($aperte as $i => $r) {
                    if ($resto <= 0) break;
                    // L'ultima riga assorbe gli arrotondamenti
                    $quota = $i === count($aperte) - 1 ? $resto : min($resto, (int)round($r['residuo'] * 100));
                    $ins->execute([$movimentoId, $doc['tipo'], $r['id'], $quota / 100, $metodo === 'auto' ? 'auto' : 'manuale', $userId]);
                    $resto -= $quota;
                    $saldata = $quota >= (int)round($r['residuo'] * 100) - ($i === count($aperte) - 1 ? $tollCent : 1);
                    if ($saldata) $this->segnaPagata($doc['tipo'], $r['id'], $dataPag);
                }
                $dopo = $this->docs->documento($doc['tipo'], $doc['id']);
                if ($dopo && $dopo['pagata']) {
                    $saldati[] = ['tipo' => $doc['tipo'], 'id' => $doc['id'], 'numero' => $doc['numero'], 'righe' => count($doc['righe'])];
                }
            }
            // Chiuso quando è coperto; l'abbinamento manuale lo chiude comunque (spese, arrotondamenti)
            if ($metodo !== 'auto' || $totale >= $disponibile - max(0.01, $tolleranza)) {
                $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET stato = 'riconciliato' WHERE id = ?")->execute([$movimentoId]);
            }
        });
        return $saldati;
    }

    /** Il movimento bancario è l'accredito di un avviso già registrato: nessun nuovo pagamento. */
    public function collegaAvviso(int $movimentoId, int $avvisoId): void
    {
        $mov = $this->movimento($movimentoId);
        $avv = $this->movimento($avvisoId);
        if ($avv['origine'] !== 'avviso_pagamento' || $mov['origine'] !== 'estratto_conto') throw new RuntimeException('Collegamento non valido');
        if ($this->riconciliatoSulMovimento($movimentoId) > 0) throw new RuntimeException('Movimento già abbinato a fatture');
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->p}movimenti_banca WHERE avviso_id = ? AND id <> ?");
        $stmt->execute([$avvisoId, $movimentoId]);
        if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('Avviso già collegato a un altro accredito');
        $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET avviso_id = ?, stato = 'riconciliato' WHERE id = ?")->execute([$avvisoId, $movimentoId]);
    }

    /**
     * Toglie le riconciliazioni del movimento: i record non più coperti tornano da pagare.
     * Per un avviso scollega anche l'accredito bancario; per un accredito scollega l'avviso.
     */
    public function annulla(int $movimentoId): array
    {
        $mov = $this->movimento($movimentoId);
        $riaperte = [];
        $this->transazione(function () use ($movimentoId, $mov, &$riaperte) {
            $stmt = $this->pdo->prepare("SELECT DISTINCT tipo, documento_id FROM {$this->p}riconciliazioni WHERE movimento_id = ?");
            $stmt->execute([$movimentoId]);
            $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);
            $this->pdo->prepare("DELETE FROM {$this->p}riconciliazioni WHERE movimento_id = ?")->execute([$movimentoId]);
            foreach ($righe as $r) {
                if ($this->riapriSeScoperta($r['tipo'], (int)$r['documento_id'])) {
                    $riaperte[] = ['tipo' => $r['tipo'], 'id' => (int)$r['documento_id']];
                }
            }
            if ($mov['origine'] === 'avviso_pagamento') {
                $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET avviso_id = NULL, stato = 'da_riconciliare' WHERE avviso_id = ?")->execute([$movimentoId]);
            }
            $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET avviso_id = NULL, stato = 'da_riconciliare' WHERE id = ?")->execute([$movimentoId]);
        });
        return $riaperte;
    }

    public function ignora(int $movimentoId, bool $ripristina = false): void
    {
        $mov = $this->movimento($movimentoId);
        if ($ripristina) {
            if ($mov['stato'] === 'ignorato') {
                $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET stato = 'da_riconciliare' WHERE id = ?")->execute([$movimentoId]);
            }
            return;
        }
        if ($this->riconciliatoSulMovimento($movimentoId) > 0 || (int)($mov['avviso_id'] ?? 0)) {
            throw new RuntimeException('Movimento abbinato: annulla prima la riconciliazione');
        }
        $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET stato = 'ignorato' WHERE id = ?")->execute([$movimentoId]);
    }

    // ── Letture ─────────────────────────────────────────

    /** Movimenti con le loro riconciliazioni. Filtri: stato, origine, dal, al (su data valuta). */
    /**
     * Movimenti con le loro riconciliazioni. Filtri: stato, origine, dal, al (su data valuta) e, con le
     * categorie attive: abbinabili, classificazione, categoria_id ('nessuna' = non classificati), tipo (entrata/uscita).
     */
    public function lista(array $f): array
    {
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
            if (!empty($f['abbinabili'])) $where[] = 'm.abbinabile = 1';
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
        foreach ($rows as &$r) {
            $r['importo'] = (float)$r['importo'];
            $r['riconciliazioni'] = $this->docs->delMovimento((int)$r['id']);
            if ($cat) $r['chiave_suggerita'] = Classificatore::chiaveSuggerita((string)$r['descrizione'], $r['controparte']);
        }
        unset($r);
        return $rows;
    }

    public function movimento(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->p}movimenti_banca WHERE id = ?");
        $stmt->execute([$id]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new RuntimeException('Movimento non trovato');
        return $m;
    }

    // ── Helper ──────────────────────────────────────────

    private function inserisciMovimento(array $m, array $conto, string $origine, bool $abbinabile = true): int
    {
        $taglia = fn($v, int $n) => ($v === null || $v === '') ? null : mb_substr((string)$v, 0, $n, 'UTF-8');
        $this->pdo->prepare("INSERT INTO {$this->p}movimenti_banca
                (banca, iban, riferimento_banca, codice_operazione, data_operazione, data_valuta, importo, descrizione,
                 controparte, hash_riga, stato, origine, file_nome)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'da_riconciliare', ?, ?)")
            ->execute([(string)$taglia($conto['banca'] ?? '', 100), $taglia($conto['iban'] ?? null, 34),
                $taglia($m['riferimento'] ?? null, 100), $taglia($m['codice_operazione'] ?? null, 20),
                $m['data_operazione'], $m['data_valuta'] ?: $m['data_operazione'], round((float)$m['importo'], 2),
                (string)$m['descrizione'], $taglia($m['controparte'] ?? null, 255), $m['hash_riga'], $origine,
                $taglia($conto['file_nome'] ?? null, 255)]);
        $id = (int)$this->pdo->lastInsertId();
        if (!$abbinabile) $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET abbinabile = 0 WHERE id = ?")->execute([$id]);
        return $id;
    }

    /** Avvisi registrati, non ancora visti in banca, con lo stesso importo e valuta entro ±5 giorni. */
    private function avvisiPerAccredito(array $mov): array
    {
        $stmt = $this->pdo->prepare("SELECT a.* FROM {$this->p}movimenti_banca a
            WHERE a.origine = 'avviso_pagamento' AND a.stato <> 'ignorato' AND a.importo BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM {$this->p}movimenti_banca b WHERE b.avviso_id = a.id)");
        $imp = abs((float)$mov['importo']);
        $stmt->execute([$imp - 0.01, $imp + 0.01]);
        $data = $mov['data_valuta'] ?: $mov['data_operazione'];
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
            fn($a) => RiconciliazioneMatch::giorni($data, $a['data_valuta'] ?: $a['data_operazione']) <= self::GIORNI_AVVISO));
    }

    /** Accrediti dell'estratto conto ancora liberi che corrispondono a un avviso. */
    private function accreditiPerAvviso(array $avv): array
    {
        $stmt = $this->pdo->prepare("SELECT m.* FROM {$this->p}movimenti_banca m
            WHERE m.origine = 'estratto_conto' AND m.stato = 'da_riconciliare' AND m.avviso_id IS NULL AND m.importo BETWEEN ? AND ?
              AND NOT EXISTS (SELECT 1 FROM {$this->p}riconciliazioni r WHERE r.movimento_id = m.id)");
        $imp = abs((float)$avv['importo']);
        $stmt->execute([$imp - 0.01, $imp + 0.01]);
        $data = $avv['data_valuta'] ?: $avv['data_operazione'];
        return array_values(array_filter($stmt->fetchAll(PDO::FETCH_ASSOC),
            fn($m) => RiconciliazioneMatch::giorni($data, $m['data_valuta'] ?: $m['data_operazione']) <= self::GIORNI_AVVISO));
    }

    private function riconciliatoSulMovimento(int $id): float
    {
        if ($id <= 0) return 0.0;
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(importo), 0) FROM {$this->p}riconciliazioni WHERE movimento_id = ?");
        $stmt->execute([$id]);
        return round((float)$stmt->fetchColumn(), 2);
    }

    private function daSoluzione(array $sol, array $perId): array
    {
        $out = [];
        foreach ($sol as $id => $cent) {
            if (isset($perId[$id])) $out[] = RiconciliazioneDocumenti::proposta($perId[$id], $cent / 100);
        }
        return $out;
    }

    private function propostaMultipla(array $sol, array $perId, int $punteggio, string $motivo): array
    {
        $dd = $this->daSoluzione($sol, $perId);
        return ['tipo' => 'documenti', 'punteggio' => $punteggio, 'totale' => round(array_sum(array_column($dd, 'importo')), 2),
            'motivi' => [$motivo, count($dd) . ' fatture'], 'documenti' => $dd];
    }

    private function trovaFornitore(string $testo): ?int
    {
        $piva = preg_match('/\b(?:IT)?(\d{11})\b/', $testo, $m) ? $m[1] : null;
        return AnagraficaMatcher::trovaFornitore($this->pdo, $this->p, $piva, null, $testo);
    }

    private function segnaPagata(string $tipo, int $id, string $data): void
    {
        if ($tipo === 'fattura') {
            $this->pdo->prepare("UPDATE {$this->p}fatture SET stato = 'pagata', data_pagamento = ?, metodo_pagamento = COALESCE(NULLIF(metodo_pagamento, ''), 'bonifico') WHERE id = ?")
                ->execute([$data, $id]);
            if ($this->dopoFattura) ($this->dopoFattura)($id);
        } else {
            $this->pdo->prepare("UPDATE {$this->p}fatture_passive SET stato = 'pagata', data_pagamento = ? WHERE id = ?")->execute([$data, $id]);
        }
    }

    /** Il record pagato non è più coperto dalle riconciliazioni rimaste: torna da pagare. */
    private function riapriSeScoperta(string $tipo, int $id): bool
    {
        $tab = $tipo === 'fattura' ? 'fatture' : 'fatture_passive';
        $stmt = $this->pdo->prepare("SELECT t.*, COALESCE((SELECT SUM(r.importo) FROM {$this->p}riconciliazioni r
                WHERE r.tipo = ? AND r.documento_id = t.id), 0) AS riconciliato FROM {$this->p}{$tab} t WHERE t.id = ?");
        $stmt->execute([$tipo, $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['stato'] !== 'pagata') return false;
        $netto = (float)$r['importo_totale'] - ($tipo === 'fattura' ? (float)($r['ritenuta'] ?? 0) : 0);
        if ((float)$r['riconciliato'] >= $netto - 0.01) return false;
        if ($tipo === 'fattura') {
            $stato = (!empty($r['data_scadenza']) && $r['data_scadenza'] < date('Y-m-d')) ? 'scaduta' : 'emessa';
            $this->pdo->prepare("UPDATE {$this->p}fatture SET stato = ?, data_pagamento = NULL WHERE id = ?")->execute([$stato, $id]);
            if ($this->dopoFattura) ($this->dopoFattura)($id);
        } else {
            $this->pdo->prepare("UPDATE {$this->p}fatture_passive SET stato = 'da_pagare', data_pagamento = NULL WHERE id = ?")->execute([$id]);
        }
        return true;
    }

    /** Esegue in transazione, o dentro quella già aperta dal chiamante. */
    private function transazione(callable $fn): void
    {
        if ($this->pdo->inTransaction()) { $fn(); return; }
        $this->pdo->beginTransaction();
        try {
            $fn();
            $this->pdo->commit();
        } catch (Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
