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
 *   (a) un solo avviso di pagamento registrato (con fatture) con lo stesso importo e valuta entro ±5 giorni,
 *       un solo accredito candidato per quell'avviso, e causale coerente (numeri dell'avviso o stesso cliente);
 *   (b) i numeri in causale danno un'unica combinazione di fatture che fa l'importo e le copre tutte;
 *   (c) causale troncata: i numeri letti + un'unica combinazione di altre fatture aperte dello
 *       stesso cliente con la stessa data documento fanno l'importo;
 *   (d) nessun numero, cliente/fornitore riconosciuto con al massimo 20 fatture aperte e un solo
 *       sottoinsieme (fino a 4 fatture) che fa l'importo.
 * Le note di credito entrano come voci negative dello stesso intestatario (fattura − NC = importo).
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
    /** Anagrafiche e fatture aperte caricate una volta per richiesta (import di centinaia di movimenti) */
    private $cache = ['clienti' => null, 'fornitori' => null, 'aperti' => []];

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

    /**
     * Quante riconciliazioni ha un record di fattura (tipo 'fattura' o 'fattura_passiva').
     * Serve a bloccare cancellazioni o cambi di stato che le lascerebbero orfane.
     */
    public static function riconciliazioniDi(PDO $pdo, string $prefix, string $tipo, int $id): int
    {
        if (!self::tabellePresenti($pdo, $prefix)) return 0;
        $stmt = $pdo->prepare("SELECT COUNT(*) FROM {$prefix}riconciliazioni WHERE tipo = ? AND documento_id = ?");
        $stmt->execute([$tipo, $id]);
        return (int)$stmt->fetchColumn();
    }

    // ── Import ──────────────────────────────────────────

    /**
     * Salva i movimenti nuovi e prova l'abbinamento automatico. Deduplica: hash attuale, hash della
     * prima versione e, tra XML e PDF dello stesso estratto, data + importo + causale simile.
     * $conto: {banca, iban, file_nome}. Va chiamato dentro una transazione del chiamante.
     */
    public function importaMovimenti(array $movimenti, array $conto, ?int $userId): array
    {
        $out = ['letti' => count($movimenti), 'nuovi' => 0, 'gia_presenti' => 0, 'altro_formato' => 0, 'senza_aggancio' => 0,
            'abbinati' => 0, 'da_verificare' => 0, 'ids' => [], 'movimenti' => [], 'avvisi' => []];
        $conCategorie = Classificatore::tabellePresenti($this->pdo, $this->p);
        $movimenti = EstrattoContoParser::conHash($movimenti);
        $out['avvisi'] = $this->docs->sovrapposizioni($movimenti, $conto);

        // 1. Tutti i movimenti del file in archivio, poi l'analisi: così l'unicità degli agganci
        //    (avviso ↔ accredito) considera anche gli altri movimenti dello stesso file
        $daAnalizzare = [];
        foreach ($movimenti as $m) {
            $m['iban'] = $m['iban'] ?? ($conto['iban'] ?? '');
            $gia = $this->docs->giaPresente($m);
            if ($gia) { $out[$gia === 'altro_formato' ? 'altro_formato' : 'gia_presenti']++; continue; }
            $mov = $m + ['id' => 0, 'origine' => 'estratto_conto', 'controparte' => null];
            $ctx = $this->contesto($mov);
            $abbinabile = $ctx['refs'] || $ctx['anagrafica_id'] || $ctx['avvisi'];
            if (!$abbinabile && !$conCategorie) { $out['senza_aggancio']++; continue; } // schema vecchio: come prima
            $id = $this->inserisciMovimento($m, $conto, 'estratto_conto', $abbinabile);
            $out['nuovi']++;
            $out['ids'][] = $id;
            if ($abbinabile) $daAnalizzare[] = [$id, !empty($m['segno_incerto']), $ctx];
            else $out['senza_aggancio']++;
        }
        // 2. Abbinamento. Candidati per ogni avviso contati prima di registrare qualcosa: se un avviso ha
        //    due accrediti possibili nel file, non si aggancia a nessuno nemmeno dopo che uno è stato usato
        $candidatiAvviso = [];
        foreach ($daAnalizzare as [$id, $incerto, $ctx]) {
            foreach ($this->docs->avvisiPerAccredito($this->movimento($id)) as $a) {
                $candidatiAvviso[(int)$a['id']] ??= count($this->docs->accreditiPerAvviso($a));
            }
        }
        foreach ($daAnalizzare as [$id, $incerto, $ctx]) {
            $ctx['candidati_avviso'] = $candidatiAvviso;
            $mov = $this->movimento($id);
            $esito = ['id' => $id, 'data' => $mov['data_valuta'], 'importo' => (float)$mov['importo'],
                'descrizione' => $mov['descrizione'], 'esito' => 'da_verificare', 'dettaglio' => '', 'proposte' => []];
            $ctx['avvisi'] = (float)$mov['importo'] > 0 ? $this->docs->avvisiPerAccredito($mov) : [];
            $an = $this->analizza($mov, $incerto, $ctx);
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
        if ($out['altro_formato']) {
            $out['avvisi'][] = "{$out['altro_formato']} movimenti erano già stati importati dall'altro formato (XML/PDF) e non sono stati duplicati.";
        }
        return $out;
    }

    /**
     * Avviso di pagamento del cliente (es. "Pagamento Fornitore" Unindustria): movimento atteso con le
     * sue riconciliazioni. Stesso file + data + totale = stesso avviso: un reimport aggiunge solo le fatture
     * nuove (e riattiva un avviso annullato). Se l'accredito è già sull'estratto conto, ed è l'unico
     * candidato coerente, lo collega (nessun doppio pagamento).
     * $documenti: [{tipo:'fattura', id, importo|null}] (null = tutto il residuo). Restituisce l'id dell'avviso.
     */
    public function registraAvviso(array $avviso, array $documenti, ?int $userId, float $tolleranza = 0.01): int
    {
        $importo = round(abs((float)$avviso['importo']), 2);
        $m = ['data_operazione' => $avviso['data'], 'data_valuta' => $avviso['data'], 'importo' => $importo,
            'descrizione' => (string)$avviso['descrizione'], 'controparte' => $avviso['controparte'] ?? null];
        $m['hash_riga'] = EstrattoContoParser::hashAvviso((string)($avviso['file_nome'] ?? ''), (string)$avviso['data'], $importo);
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->p}movimenti_banca WHERE origine = 'avviso_pagamento' AND hash_riga IN (?, ?)");
        $stmt->execute([$m['hash_riga'], EstrattoContoParser::hashRiga($m)]); // la seconda: avvisi della prima versione
        $esistente = $stmt->fetch(PDO::FETCH_ASSOC);
        if ($esistente) {
            $id = (int)$esistente['id'];
            $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET stato = CASE WHEN stato = 'ignorato' THEN 'da_riconciliare' ELSE stato END,
                importo = ? WHERE id = ?")->execute([$importo, $id]);
        } else {
            $id = $this->inserisciMovimento($m, ['file_nome' => $avviso['file_nome'] ?? ''], 'avviso_pagamento');
        }
        if ($documenti) $this->registra($id, $documenti, 'auto', $userId, $tolleranza);

        $avv = $this->movimento($id);
        if ($this->riconciliatoSulMovimento($id) > 0) {
            $cand = $this->docs->accreditiPerAvviso($avv);
            if (count($cand) === 1) {
                $ctx = $this->contesto($cand[0]);
                if (count($this->docs->avvisiPerAccredito($cand[0])) === 1 && $this->avvisoCoerente($id, $ctx)) {
                    $this->collegaAvviso((int)$cand[0]['id'], $id);
                }
            }
        }
        return $id;
    }

    /**
     * Voci da registrare per una riga dell'avviso: i record ERP con quel numero ($righe: id, numero_fattura,
     * cliente_id, stato) e l'importo pagato secondo l'avviso. Un documento per numero/cliente; l'importo
     * dell'avviso si usa solo se il documento è unico e senza righe già pagate, e mai oltre il residuo
     * (altrimenti null = residuo delle righe aperte). Così un documento già in parte pagato non fa fallire l'import.
     */
    public function vociAvviso(array $righe, float $importo): array
    {
        $primo = [];
        $conPagate = [];
        foreach ($righe as $r) {
            $k = $r['numero_fattura'] . '|' . $r['cliente_id'];
            if ($r['stato'] === 'pagata') { $conPagate[$k] = true; continue; }
            $primo[$k] ??= (int)$r['id'];
        }
        $voci = [];
        foreach ($primo as $k => $id) {
            $imp = null;
            if (count($primo) === 1 && empty($conPagate[$k])) {
                $doc = $this->docs->documento('fattura', $id);
                if ($doc && $doc['residuo'] > 0) $imp = min(round($importo, 2), $doc['residuo']);
            }
            $voci[] = ['tipo' => 'fattura', 'id' => $id, 'importo' => $imp];
        }
        return $voci;
    }

    // ── Analisi ─────────────────────────────────────────

    /** Riferimenti in causale, intestatario riconosciuto e avvisi in attesa per un movimento. */
    public function contesto(array $mov): array
    {
        $testo = trim($mov['descrizione'] . ' ' . ($mov['controparte'] ?? ''));
        $accredito = (float)$mov['importo'] > 0;
        if ($accredito) {
            $anag = AnagraficaMatcher::trovaTra($this->anagrafiche('clienti'), null, null, $testo, true);
        } else {
            $piva = preg_match('/\b(?:IT)?(\d{11})\b/', $testo, $mm) ? $mm[1] : null;
            $anag = AnagraficaMatcher::trovaTra($this->anagrafiche('fornitori'), $piva, null, $testo, false);
        }
        return [
            'testo' => $testo,
            'refs' => RiconciliazioneMatch::estraiRiferimenti($testo),
            'anagrafica_id' => $anag,
            'avvisi' => $accredito && ($mov['origine'] ?? 'estratto_conto') === 'estratto_conto' ? $this->docs->avvisiPerAccredito($mov) : [],
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
        $gia = $this->riconciliatoSulMovimento((int)$mov['id']);

        // (a) Avvisi di pagamento in attesa: automatico solo se unico da entrambe le parti e coerente
        if ($gia > 0) $ctx['avvisi'] = [];
        if (count($ctx['avvisi']) === 1 && $puoi && (int)$mov['id']) {
            $a = $ctx['avvisi'][0];
            $acc = $this->docs->accreditiPerAvviso($a);
            $unico = count($acc) === 1 && (int)$acc[0]['id'] === (int)$mov['id'] && ($ctx['candidati_avviso'][(int)$a['id']] ?? 1) === 1;
            if ($unico && $this->avvisoCoerente((int)$a['id'], $ctx)) {
                $res['avviso_id'] = (int)$a['id'];
                return $res;
            }
        }
        foreach ($ctx['avvisi'] as $a) {
            $proposte[] = ['tipo' => 'avviso', 'avviso_id' => (int)$a['id'], 'punteggio' => 95, 'totale' => (float)$a['importo'],
                'motivi' => ['Avviso di pagamento del ' . $a['data_valuta']], 'documenti' => $this->docs->delMovimento((int)$a['id'])];
        }

        $cent = (int)round(abs((float)$mov['importo']) * 100) - (int)round($gia * 100);
        if ($cent <= 0) return $res;
        $aperti = $this->aperti($tipo);
        $perId = [];
        foreach ($aperti as $d) $perId[$d['id']] = $d;
        $nc = fn($anag) => array_filter($aperti, fn($d) => $d['residuo'] < 0 && $d['anagrafica_id'] === $anag);

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
            foreach ($valide as $s) $proposte[] = $this->propostaMultipla($s, $perId, 85, 'Numeri in causale');

            // (c) causale troncata: completamento con fatture dello stesso cliente e stessa data documento
            //     (e con le sue note di credito)
            $univoci = !array_filter($perRef, fn($ids) => count($ids) !== 1);
            $clienti = array_unique(array_map(fn($d) => $d['anagrafica_id'], $cand));
            $somma = array_sum(array_map(fn($d) => (int)round($d['residuo'] * 100), $cand));
            $cliente = reset($clienti);
            if ($univoci && count($clienti) === 1 && $cliente && $somma !== $cent) {
                $date = array_unique(array_merge(array_column($cand, 'data_emissione'),
                    array_filter(array_map(fn($r) => $r['data'], $ctx['refs']))));
                $pool = array_filter($aperti, fn($d) => $d['anagrafica_id'] === $cliente && !isset($cand[$d['id']])
                    && ($d['residuo'] < 0 || in_array($d['data_emissione'], $date, true)));
                $base = [];
                foreach ($cand as $d) $base[$d['id']] = (int)round($d['residuo'] * 100);
                $sol2 = RiconciliazioneMatch::subsetSum(RiconciliazioneDocumenti::voci(array_slice($pool, 0, self::MAX_APERTE)), $cent - $somma, 3);
                if ($puoi && count($sol2['soluzioni']) === 1 && !$sol2['troncato']) {
                    $res['sicuro'] = $this->daSoluzione($base + $sol2['soluzioni'][0], $perId);
                    return $res;
                }
                foreach ($sol2['soluzioni'] as $s) {
                    $proposte[] = $this->propostaMultipla($base + $s, $perId, 75, 'Causale troncata: completata con fatture della stessa data');
                }
            }
        } elseif ($anagId) {
            // (d) solo l'intestatario: un unico sottoinsieme (fino a 4 documenti) delle sue fatture aperte.
            //     Oltre MAX_APERTE fatture aperte l'unicità non è verificabile: solo proposte
            $suoi = array_filter($aperti, fn($d) => $d['anagrafica_id'] === $anagId);
            $sol = RiconciliazioneMatch::subsetSum(RiconciliazioneDocumenti::voci(array_slice($suoi, 0, self::MAX_APERTE)), $cent, 3, 200000, 4);
            if ($puoi && count($suoi) <= self::MAX_APERTE && count($sol['soluzioni']) === 1 && !$sol['troncato']) {
                $res['sicuro'] = $this->daSoluzione($sol['soluzioni'][0], $perId);
                return $res;
            }
            foreach ($sol['soluzioni'] as $s) {
                if (count($s) > 1) $proposte[] = $this->propostaMultipla($s, $perId, 70, 'Somma esatta di fatture dello stesso intestatario');
            }
        }

        // Singole fatture con punteggio (le note di credito da sole non pagano niente)
        foreach ($aperti as $d) {
            if ($d['residuo'] <= 0) continue;
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
     * Le note di credito hanno importo negativo e vanno con almeno una fattura: il totale netto è il pagamento.
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
        $positivi = 0;
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
            if (abs($doc['residuo']) <= 0.005) throw new RuntimeException('Fattura ' . $doc['numero'] . ' già pagata');
            $segno = $doc['residuo'] < 0 ? -1 : 1;
            $imp = isset($d['importo']) && $d['importo'] !== '' && $d['importo'] !== null ? round((float)$d['importo'], 2) : $doc['residuo'];
            if ($imp * $segno <= 0) throw new RuntimeException($segno < 0 ? 'Nota di credito ' . $doc['numero'] . ': importo negativo' : 'Importo non valido');
            if (abs($imp) > abs($doc['residuo']) + max(0.01, $tolleranza)) throw new RuntimeException('Importo oltre il residuo della fattura ' . $doc['numero']);
            $imp = $segno * min(abs($imp), abs($doc['residuo']));
            if ($segno > 0) $positivi++;
            $totale += $imp;
            $piano[] = [$doc, $imp];
        }
        if (!$piano) throw new RuntimeException('Nessun documento da abbinare');
        if (!$positivi || $totale <= 0) throw new RuntimeException('Una nota di credito va abbinata insieme a una fattura');
        if ($totale > $disponibile + max(0.01, $tolleranza * count($piano))) throw new RuntimeException('La somma supera l\'importo del movimento');

        $saldati = [];
        $tollCent = max(1, (int)round($tolleranza * 100));
        $conStato = RiconciliazioneDocumenti::colonna($this->pdo, "{$this->p}riconciliazioni", 'stato_precedente');
        $this->transazione(function () use ($movimentoId, $piano, $metodo, $userId, $mov, $disponibile, $totale, $tolleranza, $tollCent, $conStato, &$saldati) {
            $ins = $this->pdo->prepare($conStato
                ? "INSERT INTO {$this->p}riconciliazioni (movimento_id, tipo, documento_id, importo, metodo, created_by, stato_precedente) VALUES (?, ?, ?, ?, ?, ?, ?)"
                : "INSERT INTO {$this->p}riconciliazioni (movimento_id, tipo, documento_id, importo, metodo, created_by) VALUES (?, ?, ?, ?, ?, ?)");
            $dataPag = $mov['data_valuta'] ?: $mov['data_operazione'];
            foreach ($piano as [$doc, $imp]) {
                $segno = $imp < 0 ? -1 : 1;
                $resto = (int)round(abs($imp) * 100);
                $aperte = array_values(array_filter($doc['righe'], fn($r) => abs($r['residuo']) > 0.005 && ($r['residuo'] < 0) === ($segno < 0)));
                foreach ($aperte as $i => $r) {
                    if ($resto <= 0) break;
                    $resRiga = (int)round(abs($r['residuo']) * 100);
                    $ultima = $i === count($aperte) - 1;
                    // L'ultima riga assorbe gli arrotondamenti
                    $quota = $ultima ? $resto : min($resto, $resRiga);
                    $valori = [$movimentoId, $doc['tipo'], $r['id'], $segno * $quota / 100, $metodo === 'auto' ? 'auto' : 'manuale', $userId];
                    if ($conStato) $valori[] = $r['stato'];
                    $ins->execute($valori);
                    $resto -= $quota;
                    if ($quota >= $resRiga - ($ultima ? $tollCent : 1)) $this->segnaPagata($doc['tipo'], $r['id'], $dataPag);
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
        $this->cache['aperti'] = [];
        return $saldati;
    }

    /** Il movimento bancario è l'accredito di un avviso già registrato: nessun nuovo pagamento. */
    public function collegaAvviso(int $movimentoId, int $avvisoId): void
    {
        $mov = $this->movimento($movimentoId);
        $avv = $this->movimento($avvisoId);
        if ($avv['origine'] !== 'avviso_pagamento' || $mov['origine'] !== 'estratto_conto') throw new RuntimeException('Collegamento non valido');
        if ($avv['stato'] === 'ignorato' || $this->riconciliatoSulMovimento($avvisoId) <= 0) throw new RuntimeException('L\'avviso non ha più fatture: è stato annullato');
        if ($this->riconciliatoSulMovimento($movimentoId) > 0) throw new RuntimeException('Movimento già abbinato a fatture');
        $stmt = $this->pdo->prepare("SELECT COUNT(*) FROM {$this->p}movimenti_banca WHERE avviso_id = ? AND id <> ?");
        $stmt->execute([$avvisoId, $movimentoId]);
        if ((int)$stmt->fetchColumn() > 0) throw new RuntimeException('Avviso già collegato a un altro accredito');
        $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET avviso_id = ?, stato = 'riconciliato' WHERE id = ?")->execute([$avvisoId, $movimentoId]);
    }

    /**
     * Toglie le riconciliazioni del movimento: i record non più coperti tornano allo stato che avevano
     * prima del pagamento. Un avviso annullato diventa 'ignorato' (non si aggancia più a nessun accredito)
     * e scollega il suo accredito; un accredito scollega l'avviso, che resta valido.
     */
    public function annulla(int $movimentoId): array
    {
        $mov = $this->movimento($movimentoId);
        $riaperte = [];
        $conStato = RiconciliazioneDocumenti::colonna($this->pdo, "{$this->p}riconciliazioni", 'stato_precedente');
        $this->transazione(function () use ($movimentoId, $mov, $conStato, &$riaperte) {
            $stmt = $this->pdo->prepare("SELECT DISTINCT tipo, documento_id FROM {$this->p}riconciliazioni WHERE movimento_id = ?");
            $stmt->execute([$movimentoId]);
            $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);
            // Stato del record prima del primo pagamento registrato (prima di cancellare le righe)
            $prima = [];
            if ($conStato) {
                $st = $this->pdo->prepare("SELECT stato_precedente FROM {$this->p}riconciliazioni WHERE tipo = ? AND documento_id = ? ORDER BY id LIMIT 1");
                foreach ($righe as $r) {
                    $st->execute([$r['tipo'], (int)$r['documento_id']]);
                    $prima[$r['tipo'] . '|' . $r['documento_id']] = $st->fetchColumn() ?: null;
                }
            }
            $this->pdo->prepare("DELETE FROM {$this->p}riconciliazioni WHERE movimento_id = ?")->execute([$movimentoId]);
            foreach ($righe as $r) {
                if ($this->riapriSeScoperta($r['tipo'], (int)$r['documento_id'], $prima[$r['tipo'] . '|' . $r['documento_id']] ?? null)) {
                    $riaperte[] = ['tipo' => $r['tipo'], 'id' => (int)$r['documento_id']];
                }
            }
            if ($mov['origine'] === 'avviso_pagamento') {
                $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET avviso_id = NULL, stato = 'da_riconciliare' WHERE avviso_id = ?")->execute([$movimentoId]);
                $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET stato = 'ignorato' WHERE id = ?")->execute([$movimentoId]);
            } else {
                $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET avviso_id = NULL, stato = 'da_riconciliare' WHERE id = ?")->execute([$movimentoId]);
            }
        });
        $this->cache['aperti'] = [];
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

    /** Movimenti con le loro riconciliazioni (vedi RiconciliazioneDocumenti::movimenti). */
    public function lista(array $f): array
    {
        return $this->docs->movimenti($f);
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
            ->execute([(string)$taglia($conto['banca'] ?? '', 100), $taglia($m['iban'] ?? ($conto['iban'] ?? null), 34),
                $taglia($m['riferimento'] ?? null, 100), $taglia($m['codice_operazione'] ?? null, 20),
                $m['data_operazione'], $m['data_valuta'] ?: $m['data_operazione'], round((float)$m['importo'], 2),
                (string)$m['descrizione'], $taglia($m['controparte'] ?? null, 255), $m['hash_riga'], $origine,
                $taglia($conto['file_nome'] ?? null, 255)]);
        $id = (int)$this->pdo->lastInsertId();
        if (!$abbinabile) $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET abbinabile = 0 WHERE id = ?")->execute([$id]);
        return $id;
    }

    /**
     * L'accredito è coerente con l'avviso? Se la causale cita fatture, devono essere tutte dell'avviso;
     * se non ne cita, il cliente riconosciuto nella causale deve essere quello delle fatture dell'avviso.
     */
    private function avvisoCoerente(int $avvisoId, array $ctx): bool
    {
        $docs = $this->docs->delMovimento($avvisoId);
        if (!$docs) return false;
        if ($ctx['refs']) {
            foreach ($ctx['refs'] as $ref) {
                $ok = false;
                foreach ($docs as $d) if (RiconciliazioneMatch::corrisponde($ref, $d['numero'], $d['data_emissione'])) { $ok = true; break; }
                if (!$ok) return false;
            }
            return true;
        }
        return $ctx['anagrafica_id'] !== null && in_array($ctx['anagrafica_id'], array_column($docs, 'cliente_id'), true);
    }

    private function riconciliatoSulMovimento(int $id): float
    {
        if ($id <= 0) return 0.0;
        $stmt = $this->pdo->prepare("SELECT COALESCE(SUM(importo), 0) FROM {$this->p}riconciliazioni WHERE movimento_id = ?");
        $stmt->execute([$id]);
        return round((float)$stmt->fetchColumn(), 2);
    }

    /** Documenti aperti di un tipo, caricati una volta finché non si registra o annulla un pagamento. */
    private function aperti(string $tipo): array
    {
        return $this->cache['aperti'][$tipo] ??= $this->docs->aperti($tipo);
    }

    private function anagrafiche(string $tabella): array
    {
        return $this->cache[$tabella] ??= $this->pdo->query("SELECT id, partita_iva, codice_fiscale, ragione_sociale FROM {$this->p}{$tabella}"
            . ($tabella === 'fornitori' ? ' WHERE deleted_at IS NULL' : ''))->fetchAll(PDO::FETCH_ASSOC);
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
            'motivi' => [$motivo, count($dd) . ' documenti'], 'documenti' => $dd];
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

    /**
     * Il record pagato non è più coperto dalle riconciliazioni rimaste: torna allo stato di prima
     * ($prima, salvato alla prima riconciliazione) o, per le righe della prima versione, a emessa/scaduta.
     */
    private function riapriSeScoperta(string $tipo, int $id, ?string $prima): bool
    {
        $tab = $tipo === 'fattura' ? 'fatture' : 'fatture_passive';
        $stmt = $this->pdo->prepare("SELECT t.*, COALESCE((SELECT SUM(r.importo) FROM {$this->p}riconciliazioni r
                WHERE r.tipo = ? AND r.documento_id = t.id), 0) AS riconciliato FROM {$this->p}{$tab} t WHERE t.id = ?");
        $stmt->execute([$tipo, $id]);
        $r = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$r || $r['stato'] !== 'pagata') return false;
        $netto = (float)$r['importo_totale'] - ($tipo === 'fattura' ? (float)($r['ritenuta'] ?? 0) : 0);
        if (abs((float)$r['riconciliato']) >= abs($netto) - 0.01) return false;
        if ($tipo === 'fattura') {
            $stato = in_array($prima, ['emessa', 'inviata', 'scaduta'], true) ? $prima
                : ((!empty($r['data_scadenza']) && $r['data_scadenza'] < date('Y-m-d')) ? 'scaduta' : 'emessa');
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
