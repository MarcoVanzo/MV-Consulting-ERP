<?php
/**
 * PagamentiFornitore — abbina i bonifici alle fatture passive fornitore per fornitore.
 *
 * Il Riconciliatore guarda un movimento alla volta: funziona quando un bonifico paga fatture con lo stesso
 * importo. Con i viaggi non succede: acconti e saldi partono prima che l'hotel fatturi, un bonifico copre più
 * fatture o una fattura si paga in più rate, e in banca il beneficiario ha un altro nome (il marchio
 * dell'hotel, non la società che fattura). Qui si mettono insieme tutti i bonifici aperti di un fornitore
 * e tutte le sue fatture aperte e si distribuiscono i primi sulle seconde in ordine di data (FIFO).
 *
 *   riepilogo()   per ogni fornitore con fatture da pagare: fatture, bonifici candidati, differenza
 *   abbina()      registra la distribuzione (riconciliazioni normali: si annullano dalla Banca);
 *                 con $chiudiDifferenza salda anche una differenza fino a MAX_DIFFERENZA del totale
 *   abbinaSicuri() automatico solo se i bonifici riconosciuti per nome fanno esattamente il totale delle fatture
 *
 * Nomi in banca: il beneficiario letto dal bonifico (24 caratteri dopo «*», o il nome dopo «BEN» degli
 * istantanei) si salva in fornitori_alias quando si conferma un abbinamento; da lì in poi il fornitore
 * si riconosce anche nell'abbinamento normale (Riconciliatore::contesto).
 *
 * SQL portabile (MySQL in produzione, SQLite nel test CLI).
 */
declare(strict_types=1);

require_once __DIR__ . '/RiconciliazioneDocumenti.php';
require_once __DIR__ . '/RiconciliazioneMatch.php';

class PagamentiFornitore
{
    /** Bonifici fino a 180 giorni prima della prima fattura (caparre) e 120 dopo l'ultima */
    public const GIORNI_PRIMA = 180;
    public const GIORNI_DOPO = 120;
    /** Differenza che si può chiudere senza bonifico (tassa di soggiorno, extra pagati in loco): 5% del totale */
    public const MAX_DIFFERENZA = 0.05;

    /** Parole che non distinguono un fornitore (forme societarie, parole generiche) */
    private const PAROLE_COMUNI = ['SRL', 'SRLS', 'SPA', 'SAS', 'SNC', 'SOCIETA', 'SOCIO', 'UNICO', 'CON', 'HOTEL', 'HOTELS',
        'SEDE', 'SECONDARIA', 'SUCCURSALE', 'ITALIA', 'ITALY', 'GROUP', 'SERVIZI', 'STUDIO', 'ASSOCIATO', 'DELLA', 'DELLE',
        'SARL', 'GMBH', 'LTD', 'COOP', 'CONSORTILE', 'RESPONSABILITA', 'LIMITATA', 'SPORT', 'ARREDAMENTI', 'MANAGEMENT',
        // ricorrono nelle causali: «Saldo viaggio luglio», «Acconto fattura»
        'VIAGGI', 'VIAGGIO', 'TRAVEL', 'TOURS', 'TURISMO', 'TRASPORTI', 'AUTOSERVIZI', 'SALDO', 'ACCONTO', 'FATTURA', 'BONIFICO'];

    private $pdo;
    private $p;
    private $ric;

    public function __construct(PDO $pdo, string $prefix, Riconciliatore $ric)
    {
        $this->pdo = $pdo;
        $this->p = $prefix;
        $this->ric = $ric;
    }

    // ── Nomi ────────────────────────────────────────────

    /** Maiuscolo senza accenti né punteggiatura, spazi singoli. */
    public static function normalizza(string $s): string
    {
        $s = strtoupper(iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $s) ?: $s);
        return trim((string)preg_replace('/[^A-Z0-9]+/', ' ', $s));
    }

    /**
     * Beneficiario di un bonifico in uscita: «*NOME (24 caratteri)causale» oppure, per gli istantanei,
     * «*INSTANT DEL … BEN NOME». Stringa vuota se non si legge.
     */
    public static function nomeInBanca(?string $controparte, string $descrizione): string
    {
        $t = (string)($controparte ?: $descrizione);
        if (stripos($t, 'INSTANT') !== false) {
            // La controparte finisce col nome; nella descrizione segue la causale attaccata: si tengono 30 caratteri
            $nome = preg_match('/\bBEN\s+(.+)$/u', $t, $m) ? $m[1] : '';
            if (!$controparte) $nome = mb_substr($nome, 0, 30, 'UTF-8');
        } else {
            $nome = preg_match('/\*(.{1,24})/u', $t, $m) ? $m[1] : '';
        }
        $nome = self::normalizza($nome);
        return strlen($nome) >= 4 ? $nome : '';
    }

    /** Parole che distinguono un fornitore: almeno 4 lettere, non comuni, non numeri. */
    public static function paroleDistintive(string $ragioneSociale): array
    {
        $parole = explode(' ', self::normalizza($ragioneSociale));
        return array_values(array_unique(array_filter($parole, fn($w) => strlen($w) >= 4 && !ctype_digit($w) && !in_array($w, self::PAROLE_COMUNI, true))));
    }

    /**
     * Fornitore di un movimento dai nomi in banca imparati: $alias = [{fornitore_id, alias}]. L'alias deve
     * comparire all'inizio di una parola del testo (i 24 caratteri del bonifico tagliano a metà parola).
     * Più fornitori diversi = ambiguo = null.
     */
    public static function daAlias(array $alias, string $testo): ?int
    {
        $t = ' ' . self::normalizza($testo);
        $trovati = [];
        foreach ($alias as $a) {
            if ($a['alias'] !== '' && str_contains($t, ' ' . $a['alias'])) $trovati[(int)$a['fornitore_id']] = true;
        }
        return count($trovati) === 1 ? (int)array_key_first($trovati) : null;
    }

    public static function tabellaAlias(PDO $pdo, string $prefix): bool
    {
        try {
            $pdo->query("SELECT 1 FROM {$prefix}fornitori_alias WHERE 1 = 0");
            return true;
        } catch (Throwable $e) {
            return false;
        }
    }

    public static function alias(PDO $pdo, string $prefix): array
    {
        if (!self::tabellaAlias($pdo, $prefix)) return [];
        return $pdo->query("SELECT fornitore_id, alias FROM {$prefix}fornitori_alias")->fetchAll(PDO::FETCH_ASSOC);
    }

    // ── Riepilogo ───────────────────────────────────────

    /**
     * Fornitori con fatture da pagare, i loro bonifici candidati e i bonifici aperti senza fornitore
     * (per aggiungerli a mano: è così che si impara un nome in banca nuovo).
     * Un bonifico va al fornitore riconosciuto dal Riconciliatore (P.IVA, nome, nome in banca imparato)
     * oppure, solo come proposta, a quello di cui contiene una parola distintiva (se è uno solo).
     */
    public function riepilogo(): array
    {
        $fatture = array_values(array_filter($this->ric->documenti()->aperti('fattura_passiva'), fn($d) => $d['residuo'] > 0 && $d['anagrafica_id']));
        $gruppi = [];
        foreach ($fatture as $d) {
            $f = $d['anagrafica_id'];
            $gruppi[$f] ??= ['fornitore_id' => $f, 'nome' => $d['anagrafica_nome'], 'fatture' => [], 'movimenti' => [],
                'dal' => $d['data_emissione'], 'al' => $d['data_emissione']];
            $gruppi[$f]['fatture'][] = ['id' => $d['id'], 'numero' => $d['numero'], 'data_emissione' => $d['data_emissione'],
                'data_scadenza' => $d['data_scadenza'], 'residuo' => $d['residuo']];
            if ($d['data_emissione'] < $gruppi[$f]['dal']) $gruppi[$f]['dal'] = $d['data_emissione'];
            if ($d['data_emissione'] > $gruppi[$f]['al']) $gruppi[$f]['al'] = $d['data_emissione'];
        }
        $parole = [];
        foreach ($gruppi as $f => $g) foreach (self::paroleDistintive($g['nome']) as $w) $parole[$w][$f] = true;

        $liberi = [];
        foreach ($this->movimentiAperti() as $m) {
            $ctx = $this->ric->contesto($m);
            $forn = $ctx['anagrafica_id'];
            $come = 'nome';
            if (!$forn || !isset($gruppi[$forn])) {
                $forn = null;
                $testo = ' ' . self::normalizza($m['descrizione'] . ' ' . ($m['controparte'] ?? '')) . ' ';
                $cand = [];
                foreach ($parole as $w => $ff) if (str_contains($testo, " $w ")) $cand += $ff;
                if (count($cand) === 1) { $forn = (int)array_key_first($cand); $come = 'parola'; }
            }
            $voce = ['id' => (int)$m['id'], 'data' => $m['data'], 'importo' => $m['residuo'], 'descrizione' => (string)$m['descrizione'],
                'nome_banca' => self::nomeInBanca($m['controparte'], (string)$m['descrizione']), 'come' => $come];
            if ($forn && $this->nellaFinestra($m['data'], $gruppi[$forn])) $gruppi[$forn]['movimenti'][] = $voce;
            elseif ($m['origine'] === 'estratto_conto') $liberi[] = $voce + ['come' => ''];
        }

        $out = [];
        foreach ($gruppi as $g) {
            $totF = round(array_sum(array_column($g['fatture'], 'residuo')), 2);
            $totM = round(array_sum(array_column($g['movimenti'], 'importo')), 2);
            $diff = round($totF - min($totM, $totF), 2);
            unset($g['dal'], $g['al']);
            $out[] = $g + ['totale_fatture' => $totF, 'totale_bonifici' => $totM, 'differenza' => $diff,
                'chiudibile' => $totM > 0 && $diff > 0.01 && $diff <= round($totF * self::MAX_DIFFERENZA, 2)];
        }
        usort($out, fn($a, $b) => [(bool)$b['movimenti'], $b['totale_fatture']] <=> [(bool)$a['movimenti'], $a['totale_fatture']]);
        usort($liberi, fn($a, $b) => strcmp($b['data'], $a['data']));
        return ['fornitori' => $out, 'liberi' => $liberi, 'max_differenza' => self::MAX_DIFFERENZA];
    }

    // ── Scritture ───────────────────────────────────────

    /**
     * Distribuisce i bonifici sulle fatture del fornitore, in ordine di data (i bonifici più vecchi pagano le
     * fatture più vecchie). Un bonifico usato in parte resta da riconciliare per il resto. Con $chiudiDifferenza
     * le fatture rimaste scoperte diventano pagate se lo scoperto non supera MAX_DIFFERENZA del totale.
     * I nomi in banca dei bonifici usati si imparano. Va chiamato dentro una transazione.
     */
    public function abbina(int $fornitoreId, array $movimentoIds, array $fatturaIds, bool $chiudiDifferenza, ?int $userId): array
    {
        $fatturaIds = array_map('intval', $fatturaIds);
        $fatture = array_values(array_filter($this->ric->documenti()->aperti('fattura_passiva', $fornitoreId),
            fn($d) => $d['residuo'] > 0 && in_array($d['id'], $fatturaIds, true)));
        if (!$fatture) throw new RuntimeException('Nessuna fattura da pagare tra quelle scelte');
        if (count($fatture) !== count(array_unique($fatturaIds))) throw new RuntimeException('Una delle fatture scelte è già pagata o è di un altro fornitore');
        usort($fatture, fn($a, $b) => [$a['data_emissione'], $a['id']] <=> [$b['data_emissione'], $b['id']]);

        $aperti = [];
        foreach ($this->movimentiAperti() as $m) $aperti[(int)$m['id']] = $m;
        $movimenti = [];
        foreach (array_unique(array_map('intval', $movimentoIds)) as $id) {
            if (!isset($aperti[$id])) throw new RuntimeException('Un bonifico scelto non è più da riconciliare');
            $movimenti[] = $aperti[$id];
        }
        usort($movimenti, fn($a, $b) => [$a['data'], $a['id']] <=> [$b['data'], $b['id']]);

        $resto = [];
        foreach ($fatture as $i => $d) $resto[$i] = (int)round($d['residuo'] * 100);
        $totale = array_sum($resto);
        $usati = 0;
        $conVoci = [];
        $avanzo = 0;
        $ultimaData = null;
        foreach ($movimenti as $m) {
            $disp = (int)round($m['residuo'] * 100);
            $voci = [];
            foreach ($resto as $i => $r) {
                if ($disp <= 0) break;
                if ($r <= 0) continue;
                $q = min($disp, $r);
                $voci[] = ['tipo' => 'fattura_passiva', 'id' => $fatture[$i]['id'], 'importo' => $q / 100];
                $resto[$i] -= $q;
                $disp -= $q;
            }
            if (!$voci) { $avanzo += $disp; continue; }
            // Chiuso solo se usato tutto: il resto può pagare fatture che arriveranno
            $this->ric->registra((int)$m['id'], $voci, 'manuale', $userId, 0.01, $disp <= 0);
            $usati++;
            $conVoci[] = $m;
            $avanzo += $disp;
            $ultimaData = $m['data'];
        }

        $scoperto = array_sum($resto);
        $chiuse = [];
        if ($scoperto > 0 && $chiudiDifferenza) {
            if (!$usati) throw new RuntimeException('Nessun bonifico usato: per le fatture pagate in altro modo usa «Segna pagate»');
            if ($scoperto > (int)round($totale * self::MAX_DIFFERENZA)) {
                throw new RuntimeException('Differenza di ' . number_format($scoperto / 100, 2, ',', '.') . ' €: oltre il '
                    . (int)(self::MAX_DIFFERENZA * 100) . '% del totale, va vista fattura per fattura');
            }
            $nota = $this->pdo->prepare("SELECT note FROM {$this->p}fatture_passive WHERE id = ?");
            $upd = $this->pdo->prepare("UPDATE {$this->p}fatture_passive SET stato = 'pagata', data_pagamento = ?, note = ?
                WHERE id = ? AND stato = 'da_pagare'");
            foreach ($resto as $i => $r) {
                if ($r <= 0) continue;
                $nota->execute([$fatture[$i]['id']]);
                $testo = trim((string)$nota->fetchColumn() . ' Chiusa con l\'abbinamento per fornitore: '
                    . number_format($r / 100, 2, ',', '.') . ' € non trovati tra i bonifici.');
                $upd->execute([$ultimaData, $testo, $fatture[$i]['id']]);
                $chiuse[] = $fatture[$i]['numero'];
            }
            $scoperto = 0;
        }
        $imparati = $this->imparaAlias($fornitoreId, $conVoci);
        $saldate = count(array_filter($resto, fn($r) => $r <= 0));
        return ['fatture_saldate' => $saldate, 'bonifici_usati' => $usati, 'scoperto' => $scoperto / 100,
            'avanzo' => $avanzo / 100, 'chiuse_con_differenza' => $chiuse, 'nomi_imparati' => $imparati];
    }

    /**
     * Automatico: i bonifici riconosciuti per nome (non per parola) fanno esattamente il totale delle fatture
     * aperte del fornitore. Esclusi i movimenti col segno incerto o con un abbinamento annullato a mano.
     */
    public function abbinaSicuri(?int $userId): array
    {
        $esito = ['fornitori' => 0, 'fatture_saldate' => 0];
        $bloccati = [];
        foreach ($this->movimentiAperti() as $m) if (!empty($m['segno_incerto']) || !empty($m['abbinamento_annullato'])) $bloccati[(int)$m['id']] = true;
        foreach ($this->riepilogo()['fornitori'] as $g) {
            $movs = $g['movimenti'];
            if (!$movs || array_filter($movs, fn($m) => $m['come'] !== 'nome' || isset($bloccati[$m['id']]))) continue;
            if (abs($g['totale_bonifici'] - $g['totale_fatture']) > 0.01) continue;
            $r = $this->abbina($g['fornitore_id'], array_column($movs, 'id'), array_column($g['fatture'], 'id'), false, $userId);
            $esito['fornitori']++;
            $esito['fatture_saldate'] += $r['fatture_saldate'];
        }
        return $esito;
    }

    // ── Helper ──────────────────────────────────────────

    /** Uscite ancora da riconciliare (conto e carta) con quanto resta da abbinare. */
    private function movimentiAperti(): array
    {
        $rows = $this->pdo->query("SELECT m.*, COALESCE(m.data_valuta, m.data_operazione) AS data,
                COALESCE((SELECT SUM(r.importo) FROM {$this->p}riconciliazioni r WHERE r.movimento_id = m.id), 0) AS riconciliato
            FROM {$this->p}movimenti_banca m
            WHERE m.importo < 0 AND m.stato = 'da_riconciliare' AND m.avviso_id IS NULL
              AND m.origine IN ('estratto_conto', 'estratto_carta')
            ORDER BY data, m.id")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $m) {
            $m['residuo'] = round(abs((float)$m['importo']) - (float)$m['riconciliato'], 2);
            if ($m['residuo'] > 0.005) $out[] = $m;
        }
        return $out;
    }

    private function nellaFinestra(string $data, array $g): bool
    {
        $dal = date('Y-m-d', strtotime($g['dal'] . ' -' . self::GIORNI_PRIMA . ' days'));
        $al = date('Y-m-d', strtotime($g['al'] . ' +' . self::GIORNI_DOPO . ' days'));
        return $data >= $dal && $data <= $al;
    }

    /** Salva i nomi in banca nuovi del fornitore (uno già di un altro fornitore resta a quello). */
    private function imparaAlias(int $fornitoreId, array $movimenti): array
    {
        if (!self::tabellaAlias($this->pdo, $this->p)) return [];
        $stmt = $this->pdo->prepare("SELECT ragione_sociale FROM {$this->p}fornitori WHERE id = ?");
        $stmt->execute([$fornitoreId]);
        $ragione = self::normalizza((string)$stmt->fetchColumn());
        $esiste = $this->pdo->prepare("SELECT 1 FROM {$this->p}fornitori_alias WHERE alias = ?");
        $ins = $this->pdo->prepare("INSERT INTO {$this->p}fornitori_alias (fornitore_id, alias) VALUES (?, ?)");
        $nuovi = [];
        foreach ($movimenti as $m) {
            $nome = self::nomeInBanca($m['controparte'] ?? null, (string)$m['descrizione']);
            if ($nome === '' || isset($nuovi[$nome]) || ($ragione !== '' && str_starts_with($ragione, $nome))) continue;
            $esiste->execute([$nome]);
            if ($esiste->fetchColumn()) continue;
            $ins->execute([$fornitoreId, $nome]);
            $nuovi[$nome] = true;
        }
        return array_keys($nuovi);
    }
}
