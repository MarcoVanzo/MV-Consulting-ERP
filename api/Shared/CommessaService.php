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

require_once __DIR__ . '/TerminiPagamento.php';

class CommessaService
{
    /** Tipi di commessa di offerte e incarichi: codice → etichetta (stesso elenco in js/core/ui.js). */
    public const TIPI = [
        'assistenza' => 'Assistenza', 'dpo' => 'DPO', 'formazione' => 'Formazione', 'nis2' => 'Consulenza NIS 2',
        'ict' => 'Consulenza ICT', 'digital' => 'Consulenza Digital', 'sviluppo_software' => 'Sviluppo Software', 'viaggio' => 'Viaggio', 'noleggio' => 'Noleggio', 'altro' => 'Altro',
    ];

    /** Proposta di collegamento da confermare a mano: non la applica creaCommesseMancanti. */
    private const MOTIVO_DEBOLE = 'unica commessa del cliente con residuo sufficiente';

    private $pdo;
    private $p;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->p = $prefix;
    }

    /**
     * Chiavi di confronto di un protocollo. Le lettere Unindustria riportano due codici
     * («Prot. n. 820/2026 (SZ.DPS.F011.26)») e la lettura ne può restituire uno o entrambi:
     * due protocolli sono lo stesso se hanno almeno una chiave in comune.
     * @return string[]
     */
    public static function chiaviProtocollo(?string $protocollo): array
    {
        $s = mb_strtoupper(trim((string)$protocollo), 'UTF-8');
        if ($s === '') return [];
        $chiavi = [];
        if (preg_match_all('/\b(\d{1,6})\s*\/\s*(\d{4})\b/', $s, $m, PREG_SET_ORDER)) {
            foreach ($m as $x) $chiavi[] = (int)$x[1] . '/' . $x[2];
        }
        // Il codice alfanumerico si cerca senza la parte «Prot. n. 820/2026», che darebbe «PROT.N.820»
        $resto = preg_replace(['/\bPROT\w*\.?\s*(N\w*\.?)?/', '/\b\d{1,6}\s*\/\s*\d{4}\b/'], ' ', $s);
        if (preg_match_all('/\b[A-Z]{2,}(?:\s*\.\s*[A-Z0-9]+){2,}\b/', $resto, $m)) {
            foreach ($m[0] as $x) $chiavi[] = preg_replace('/\s+/', '', $x);
        }
        if (!$chiavi) $chiavi[] = preg_replace('/[^A-Z0-9]/', '', $s);
        return array_values(array_unique(array_filter($chiavi)));
    }

    /**
     * Commessa di una riga di fattura: quella che ha un protocollo in comune col testo della riga
     * («… Prot. n. 3991/2026 + 452/2027 (SZ.DPS.F307.26)»). $incarichi: righe con id, numero_protocollo,
     * sottocliente_id. Più commesse candidate: vince quella del sottocliente della riga, se noto.
     */
    public static function incaricoDellaRiga(string $descrizione, array $incarichi, ?int $sottoclienteId = null): ?array
    {
        // Solo i codici veri: senza numeri di protocollo né codice alfanumerico la riga non si abbina
        // Le date («ft. 65 del 25/11/2025») non sono protocolli
        $descrizione = preg_replace('/\b\d{1,2}\/\d{1,2}\/\d{4}\b/', ' ', $descrizione);
        if (!preg_match('/\d\s*\/\s*\d{4}|\b[A-Z]{2,}(?:\s*\.\s*[A-Z0-9]+){2,}\b/i', $descrizione)) return null;
        $chiavi = self::chiaviProtocollo($descrizione);
        $trovati = [];
        foreach ($incarichi as $inc) {
            if (array_intersect($chiavi, self::chiaviProtocollo($inc['numero_protocollo'] ?? ''))) $trovati[] = $inc;
        }
        if (count($trovati) > 1 && $sottoclienteId) {
            $stesso = array_values(array_filter($trovati, fn($i) => (int)($i['sottocliente_id'] ?? 0) === $sottoclienteId));
            if ($stesso) $trovati = $stesso;
        }
        return $trovati[0] ?? null;
    }

    /** Commessa già registrata con lo stesso protocollo (vedi chiaviProtocollo), o null. */
    public function commessaConProtocollo(?string $protocollo, ?int $esclusa = null): ?array
    {
        $cerca = self::chiaviProtocollo($protocollo);
        if (!$cerca) return null;
        $stmt = $this->pdo->query("SELECT i.id, i.numero_protocollo, i.data_incarico, i.importo_totale, s.nome AS sottocliente
            FROM {$this->p}incarichi i LEFT JOIN {$this->p}sottoclienti s ON s.id = i.sottocliente_id
            WHERE i.numero_protocollo IS NOT NULL AND i.numero_protocollo <> ''");
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($esclusa && (int)$r['id'] === $esclusa) continue;
            if (array_intersect($cerca, self::chiaviProtocollo($r['numero_protocollo']))) return $r;
        }
        return null;
    }

    /** Prossimo numero d'offerta dell'anno (OFF-AAAA-NNN). Calcolato in PHP: la stessa query vale su MySQL e SQLite. */
    public function nuovoNumeroOfferta(int $anno): string
    {
        $stmt = $this->pdo->prepare("SELECT numero FROM {$this->p}offerte WHERE numero LIKE ?");
        $stmt->execute(["OFF-$anno-%"]);
        $max = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_COLUMN) as $n) $max = max($max, (int)substr((string)$n, 9));
        return sprintf('OFF-%d-%03d', $anno, $max + 1);
    }

    /**
     * Ogni commessa nasce da un'offerta: per una commessa creata a mano (o da una lettera d'incarico)
     * si registra un'offerta già accettata con gli stessi dati, così pipeline e conversione tornano.
     * Da chiamare dentro la transazione che crea l'incarico. Restituisce l'id dell'offerta.
     */
    public function offertaRapida(int $incaricoId, array $i): int
    {
        $data = (string)($i['data_incarico'] ?? date('Y-m-d'));
        for ($tentativo = 1; ; $tentativo++) {
            $numero = $this->nuovoNumeroOfferta((int)substr($data, 0, 4));
            try {
                $this->inserisciOffertaRapida($numero, $data, $incaricoId, $i);
                break;
            } catch (PDOException $e) {
                // Numero preso da un salvataggio contemporaneo (indice unico numero+versione): si riprova
                if ($tentativo >= 3 || !in_array((string)$e->getCode(), ['23000'], true)) throw $e;
            }
        }
        $offertaId = (int)$this->pdo->lastInsertId();
        $this->pdo->prepare("UPDATE {$this->p}incarichi SET offerta_id = ? WHERE id = ?")->execute([$offertaId, $incaricoId]);
        return $offertaId;
    }

    private function inserisciOffertaRapida(string $numero, string $data, int $incaricoId, array $i): void
    {
        $this->pdo->prepare("INSERT INTO {$this->p}offerte (numero, versione, cliente_id, sottocliente_id, data_offerta, oggetto,
                tipo_commessa, num_giornate, imponibile, giorni_pagamento, condizioni_pagamento, stato, data_esito, incarico_id, origine, note)
            VALUES (?, 1, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'accettata', ?, ?, 'rapida', ?)")
            ->execute([$numero, $i['cliente_id'], $i['sottocliente_id'] ?? null, $data,
                mb_substr(trim((string)($i['descrizione'] ?? '')) ?: 'Commessa ' . ($i['numero_protocollo'] ?? $numero), 0, 255),
                $i['tipo_commessa'] ?? 'assistenza', (float)($i['num_giornate'] ?? 0), (float)$i['importo_totale'],
                (int)($i['giorni_pagamento'] ?? 30), $i['condizioni_pagamento'] ?? null, $data, $incaricoId,
                'Offerta registrata insieme alla commessa']);
    }

    /**
     * Crea le rate di un incarico da un piano [{descrizione, percentuale, giorni_da_accettazione | mesi_da_accettazione}].
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
            $mesi = $r['mesi_da_accettazione'] ?? null;
            $data = ($mesi !== null && $mesi !== '') ? TerminiPagamento::piuMesi($dataBase, (int)$mesi)
                : (($giorni === null || $giorni === '') ? null : date('Y-m-d', strtotime($dataBase . ' +' . (int)$giorni . ' days')));
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
        $stmt = $this->pdo->prepare("SELECT id, numero_fattura, incarico_id, imponibile, data_emissione, data_scadenza FROM {$this->p}fatture WHERE id = ?");
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
        if (!$gruppo) {
            // Nessuna rata (o sequenza di rate) con quell'importo: meglio chiedere che agganciare la rata sbagliata
            require_once __DIR__ . '/Avvisi.php';
            $importi = implode(', ', array_map(fn($r) => number_format((float)$r['importo'], 2, ',', '.'), $libere));
            Avvisi::aggiungi("Fattura {$f['numero_fattura']}: " . number_format((float)$f['imponibile'], 2, ',', '.')
                . " € non corrisponde a nessuna rata libera della commessa ($importi €). Collegala a mano dalla scheda commessa.");
            return null;
        }
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
            $scad = TerminiPagamento::scadenza((string)$f['data_emissione'], ...TerminiPagamento::daRiga(
                $this->terminiIncarico((int)$f['incarico_id']), (int)$scelta['giorni_pagamento']));
            $this->pdo->prepare("UPDATE {$this->p}fatture SET data_scadenza = ? WHERE id = ?")->execute([$scad, $fatturaId]);
        }
        return (int)$scelta['id'];
    }

    /**
     * Rate coperte da un imponibile (tolleranza 1 €): prima una rata di pari importo,
     * poi una sequenza di rate consecutive; altrimenti nessuna (la sceglie l'utente).
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
        return [];
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

    /**
     * Commessa del cliente con una rata libera dello stesso importo (±1 €) prevista entro 40 giorni dalla data:
     * è così che le fatture di una commessa ricorrente (canone mensile) si agganciano da sole all'import.
     * Solo se la candidata è una sola: con più commesse possibili si lascia scegliere all'utente.
     */
    public function trovaIncaricoPerRata(?int $clienteId, float $imponibile, string $data): ?int
    {
        if (!$clienteId || $imponibile <= 0) return null;
        $stmt = $this->pdo->prepare("SELECT DISTINCT r.incarico_id, r.data_prevista FROM {$this->p}incarichi_rate r
            JOIN {$this->p}incarichi i ON i.id = r.incarico_id
            WHERE i.cliente_id = ? AND r.fattura_id IS NULL AND r.data_prevista IS NOT NULL AND ABS(r.importo - ?) <= 1");
        $stmt->execute([$clienteId, $imponibile]);
        $ids = [];
        $t = strtotime($data);
        foreach ($stmt->fetchAll() as $r) {
            if (abs(strtotime((string)$r['data_prevista']) - $t) <= 40 * 86400) $ids[(int)$r['incarico_id']] = true;
        }
        return count($ids) === 1 ? (int)array_key_first($ids) : null;
    }

    /**
     * Fatture emesse dell'anno non collegate a una commessa, ciascuna con la commessa proposta e il motivo:
     * protocollo citato, riferimento all'offerta, rata libera di pari importo, unica commessa del cliente con
     * residuo sufficiente (non per i canoni ripetuti né per fatture precedenti la commessa). Le proposte si confermano a mano (Vendite › Commesse › «Collega alle commesse»).
     * Le fatture che si ripetono con lo stesso importo (almeno 3) sono segnate «ricorrente»: candidate a una
     * commessa a canone. Restituisce anche le commesse di ogni cliente per la scelta manuale.
     */
    public function proposteCollegamento(int $anno): array
    {
        $stmt = $this->pdo->prepare("SELECT f.id, f.numero_fattura, f.data_emissione, f.cliente_id, f.sottocliente_id, f.imponibile,
                f.importo_totale, f.descrizione, f.stato, c.ragione_sociale AS cliente_nome, sc.nome AS sottocliente_nome
            FROM {$this->p}fatture f
            LEFT JOIN {$this->p}clienti c ON c.id = f.cliente_id
            LEFT JOIN {$this->p}sottoclienti sc ON sc.id = f.sottocliente_id
            WHERE f.incarico_id IS NULL AND f.data_emissione BETWEEN ? AND ?
            ORDER BY c.ragione_sociale, f.data_emissione, f.id");
        $stmt->execute(["$anno-01-01", "$anno-12-31"]);
        $fatture = $stmt->fetchAll(PDO::FETCH_ASSOC);

        $clienti = array_values(array_unique(array_filter(array_map(fn($f) => (int)$f['cliente_id'], $fatture))));
        $commesse = [];
        if ($clienti) {
            $in = implode(',', $clienti);
            $rows = $this->pdo->query("SELECT i.id, i.cliente_id, i.sottocliente_id, i.data_incarico, i.tipo_commessa, i.descrizione,
                    i.numero_protocollo, i.importo_totale, COALESCE(i.importo_fatturato, 0) AS importo_fatturato, sc.nome AS sottocliente_nome
                FROM {$this->p}incarichi i LEFT JOIN {$this->p}sottoclienti sc ON sc.id = i.sottocliente_id
                WHERE i.cliente_id IN ($in) ORDER BY i.data_incarico DESC, i.id DESC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $r['residuo'] = round(max(0, (float)$r['importo_totale'] - (float)$r['importo_fatturato']), 2);
                $commesse[(int)$r['cliente_id']][] = $r;
            }
        }

        $ripetute = [];
        foreach ($fatture as $f) {
            if ((float)$f['imponibile'] > 0) $ripetute[$f['cliente_id'] . '|' . round((float)$f['imponibile'], 2)][] = 1;
        }

        foreach ($fatture as &$f) {
            $f['ricorrente'] = count($ripetute[$f['cliente_id'] . '|' . round((float)$f['imponibile'], 2)] ?? []) >= 3;
            $f['proposta'] = (float)$f['imponibile'] > 0
                ? $this->proposta($f, $commesse[(int)$f['cliente_id']] ?? []) : null;
        }
        unset($f);
        return ['fatture' => $fatture, 'commesse' => $commesse];
    }

    /** Commessa proposta per una fattura: [incarico_id, motivo] o null. */
    private function proposta(array $f, array $commesse): ?array
    {
        if (!$commesse) return null;
        $testo = (string)$f['descrizione'];
        $inc = self::incaricoDellaRiga($testo, $commesse, $f['sottocliente_id'] ? (int)$f['sottocliente_id'] : null);
        if ($inc) return ['incarico_id' => (int)$inc['id'], 'motivo' => 'protocollo citato in fattura'];
        $id = $this->trovaIncaricoPerRiferimento($testo, (int)$f['cliente_id']);
        if ($id) return ['incarico_id' => $id, 'motivo' => "riferimento all'offerta"];
        $id = $this->trovaIncaricoPerRata((int)$f['cliente_id'], (float)$f['imponibile'], (string)$f['data_emissione']);
        if ($id) return ['incarico_id' => $id, 'motivo' => 'rata di pari importo'];
        // Ultima risorsa, solo per fatture una tantum: un canone che si ripete non va sulla commessa di un progetto,
        // e una fattura emessa prima della commessa non ne fa parte
        if ($f['ricorrente']) return null;
        $aperte = array_values(array_filter($commesse, fn($c) => (float)$c['residuo'] >= (float)$f['imponibile'] - 1
            && (string)$c['data_incarico'] <= (string)$f['data_emissione']));
        if (count($aperte) === 1) return ['incarico_id' => (int)$aperte[0]['id'], 'motivo' => self::MOTIVO_DEBOLE];
        return null;
    }

    /**
     * Collega una fattura emessa a una commessa e alla sua rata. Il ricalcolo dei totali della commessa
     * (IncarchiController::recalculate) resta a chi chiama.
     */
    public function collegaFatturaACommessa(int $fatturaId, int $incaricoId): void
    {
        $this->pdo->prepare("UPDATE {$this->p}incarichi_rate SET fattura_id = NULL WHERE fattura_id = ? AND incarico_id <> ?")
            ->execute([$fatturaId, $incaricoId]);
        $this->pdo->prepare("UPDATE {$this->p}fatture SET incarico_id = ? WHERE id = ?")->execute([$incaricoId, $fatturaId]);
        $this->collegaFatturaARata($fatturaId);
    }

    /**
     * Commessa costruita da fatture già emesse dello stesso cliente.
     *  - singola (es. un viaggio EXACT): valore = somma delle fatture, note di credito comprese; una rata per fattura,
     *    già collegata; le note di credito riducono le rate a partire dall'ultima.
     *  - ricorrente (canone): valore = canone × mesi, una rata al mese dalla prima fattura; le fatture si
     *    agganciano alle rate in ordine di data e le prossime arriveranno da sole (trovaIncaricoPerRata).
     * $o: tipo_commessa, descrizione, ricorrente (bool), mesi (ricorrente, default 12).
     * Da chiamare dentro una transazione. Restituisce l'id della commessa.
     */
    public function creaDaFatture(array $fatturaIds, array $o): int
    {
        $fatturaIds = array_values(array_unique(array_map('intval', $fatturaIds)));
        if (!$fatturaIds) throw new InvalidArgumentException('Nessuna fattura scelta');
        $in = implode(',', $fatturaIds);
        $fatture = $this->pdo->query("SELECT id, cliente_id, sottocliente_id, data_emissione, imponibile, incarico_id
            FROM {$this->p}fatture WHERE id IN ($in) ORDER BY data_emissione, id")->fetchAll(PDO::FETCH_ASSOC);
        if (count($fatture) !== count($fatturaIds)) throw new InvalidArgumentException('Fattura non trovata');
        $clienti = array_unique(array_map(fn($f) => (int)$f['cliente_id'], $fatture));
        if (count($clienti) !== 1 || !$clienti[0]) throw new InvalidArgumentException('Le fatture devono essere dello stesso cliente');
        if (array_filter($fatture, fn($f) => $f['incarico_id'])) throw new InvalidArgumentException('Una delle fatture è già collegata a una commessa');
        $sotto = array_unique(array_map(fn($f) => (int)$f['sottocliente_id'], $fatture));

        $positive = array_values(array_filter($fatture, fn($f) => (float)$f['imponibile'] > 0));
        if (!$positive) throw new InvalidArgumentException('Serve almeno una fattura (le note di credito da sole non bastano)');
        $ricorrente = !empty($o['ricorrente']);
        $mesi = max(1, min(60, (int)($o['mesi'] ?? 12)));
        $canone = round((float)$positive[0]['imponibile'], 2);
        $totale = $ricorrente ? round($canone * $mesi, 2)
            : round(array_sum(array_map(fn($f) => (float)$f['imponibile'], $fatture)), 2);
        if ($totale <= 0) throw new InvalidArgumentException('Il valore della commessa sarebbe zero o negativo');

        $termini = $this->terminiCliente((int)$clienti[0]);
        $i = [
            'cliente_id' => (int)$clienti[0],
            'sottocliente_id' => count($sotto) === 1 && $sotto[0] ? $sotto[0] : null,
            'data_incarico' => (string)$fatture[0]['data_emissione'],
            'tipo_commessa' => isset(self::TIPI[$o['tipo_commessa'] ?? '']) ? $o['tipo_commessa'] : 'altro',
            'descrizione' => trim((string)($o['descrizione'] ?? '')) ?: null,
            'num_giornate' => 0,
            'importo_totale' => $totale,
            'giorni_pagamento' => $termini['giorni_pagamento'] ?? 30,
            'fine_mese' => $termini['fine_mese'] ?? 0,
            'giorno_pagamento' => $termini['giorno_pagamento'] ?? null,
            'condizioni_pagamento' => null,
            'note' => $ricorrente ? "Canone di " . number_format($canone, 2, ',', '.') . " € al mese per $mesi mesi" : 'Commessa creata dalle fatture emesse',
        ];
        $cols = array_keys($i);
        $this->pdo->prepare("INSERT INTO {$this->p}incarichi (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")
            ->execute(array_values($i));
        $id = (int)$this->pdo->lastInsertId();

        $ins = $this->pdo->prepare("INSERT INTO {$this->p}incarichi_rate
            (incarico_id, ordine, descrizione, percentuale, importo, data_prevista, giorni_pagamento, fattura_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($ricorrente) {
            $perc = round(100 / $mesi, 2);
            for ($k = 0; $k < $mesi; $k++) {
                $data = TerminiPagamento::piuMesi($i['data_incarico'], $k);
                $ins->execute([$id, $k + 1, 'Canone ' . self::meseAnno($data), $perc, $canone, $data, $i['giorni_pagamento'], null]);
            }
            $upd = $this->pdo->prepare("UPDATE {$this->p}fatture SET incarico_id = ? WHERE id = ?");
            foreach ($fatture as $f) {
                $upd->execute([$id, $f['id']]);
                if ((float)$f['imponibile'] > 0) $this->collegaFatturaARata((int)$f['id']);
            }
        } else {
            // Note di credito: riducono le rate dall'ultima, mai sotto zero
            $storno = -array_sum(array_map(fn($f) => min(0, (float)$f['imponibile']), $fatture));
            $importi = array_map(fn($f) => (float)$f['imponibile'], $positive);
            for ($k = count($importi) - 1; $k >= 0 && $storno > 0.005; $k--) {
                $tolto = min($importi[$k], $storno);
                $importi[$k] = round($importi[$k] - $tolto, 2);
                $storno -= $tolto;
            }
            foreach ($positive as $k => $f) {
                $ins->execute([$id, $k + 1, count($positive) === 1 ? 'Saldo' : 'Fattura ' . ($k + 1), round($importi[$k] / $totale * 100, 2),
                    $importi[$k], $f['data_emissione'], $i['giorni_pagamento'], $f['id']]);
            }
            $this->pdo->exec("UPDATE {$this->p}fatture SET incarico_id = $id WHERE id IN ($in)");
        }
        $this->offertaRapida($id, $i);
        return $id;
    }

    /** Tipo di commessa indovinato dal testo della fattura (prima parola chiave trovata), altrimenti «altro». */
    public static function tipoDalTesto(string $testo): string
    {
        foreach ([
            '/\bnis\s?2\b/i' => 'nis2', '/\bdpo\b|privacy|gdpr/i' => 'dpo', '/formazion|\bcorso\b/i' => 'formazione',
            '/noleggi|\bcanone\b/i' => 'noleggio', '/viaggi|trasport|\bvolo\b|hotel|soggiorn/i' => 'viaggio',
            '/sviluppo|software|\bapp\b|sito web/i' => 'sviluppo_software', '/assistenz|supporto/i' => 'assistenza',
        ] as $re => $tipo) {
            if (preg_match($re, $testo)) return $tipo;
        }
        return 'altro';
    }

    /**
     * Commesse per tutte le fatture emesse senza commessa (o solo per quelle indicate):
     *  1. collega quelle con una proposta sicura (protocollo, riferimento all'offerta, rata di pari importo);
     *  2. quelle per cui la proposta è solo «unica commessa con residuo» restano da confermare a mano;
     *  3. le altre diventano commesse: un canone (stesso cliente, sottocliente e importo almeno 3 volte) fa una
     *     commessa ricorrente che copre tutti i mesi fatturati (almeno 12), ogni altra fattura una commessa singola.
     * Note di credito e fatture senza cliente restano fuori. Da chiamare dentro una transazione;
     * il ricalcolo dei totali delle commesse toccate resta a chi chiama.
     * @return array{collegate:int, create:int, ricorrenti:int, da_scegliere:int, saltate:int, commesse:int[]}
     */
    public function creaCommesseMancanti(?array $fatturaIds = null): array
    {
        $esito = ['collegate' => 0, 'create' => 0, 'ricorrenti' => 0, 'da_scegliere' => 0, 'saltate' => 0, 'commesse' => []];
        // La ricorrenza si giudica su tutte le fatture senza commessa del cliente, anche fuori dall'elenco indicato
        $tutte = $this->pdo->query("SELECT id, cliente_id, sottocliente_id, data_emissione, imponibile, descrizione
            FROM {$this->p}fatture WHERE incarico_id IS NULL ORDER BY cliente_id, data_emissione, id")->fetchAll(PDO::FETCH_ASSOC);
        $scelte = $fatturaIds === null ? null : array_flip(array_map('intval', $fatturaIds));
        $chiave = fn($f) => $f['cliente_id'] . '|' . (int)$f['sottocliente_id'] . '|' . round((float)$f['imponibile'], 2);
        $gruppi = [];
        foreach ($tutte as $f) {
            if ($f['cliente_id'] && (float)$f['imponibile'] > 0) $gruppi[$chiave($f)][] = $f;
        }

        $daCreare = [];
        foreach ($tutte as $f) {
            if ($scelte !== null && !isset($scelte[(int)$f['id']])) continue;
            if (!$f['cliente_id'] || (float)$f['imponibile'] <= 0) { $esito['saltate']++; continue; }
            $f['ricorrente'] = count($gruppi[$chiave($f)]) >= 3;
            $prop = $this->proposta($f, $this->commesseDelCliente((int)$f['cliente_id']));
            if ($prop && $prop['motivo'] !== self::MOTIVO_DEBOLE) {
                $this->collegaFatturaACommessa((int)$f['id'], $prop['incarico_id']);
                $esito['collegate']++;
                $esito['commesse'][$prop['incarico_id']] = true;
            } elseif ($prop) {
                $esito['da_scegliere']++;
            } else {
                $daCreare[$f['ricorrente'] ? 'r' . $chiave($f) : 's' . $f['id']][] = $f;
            }
        }

        foreach ($daCreare as $k => $fatture) {
            $prima = trim((string)preg_replace('/^\[Nota di credito\]\s*/', '', strtok((string)$fatture[0]['descrizione'], "\n") ?: ''));
            $testo = implode("\n", array_column($fatture, 'descrizione'));
            if ($k[0] === 'r') {
                $date = array_column($fatture, 'data_emissione');
                $mesi = ((int)substr(max($date), 0, 4) - (int)substr(min($date), 0, 4)) * 12
                    + (int)substr(max($date), 5, 2) - (int)substr(min($date), 5, 2) + 1;
                $id = $this->creaDaFatture(array_column($fatture, 'id'), ['ricorrente' => true, 'mesi' => max(12, $mesi),
                    'tipo_commessa' => self::tipoDalTesto($testo), 'descrizione' => mb_substr('Canone ' . $prima, 0, 120)]);
                $esito['ricorrenti']++;
            } else {
                $id = $this->creaDaFatture([$fatture[0]['id']], ['tipo_commessa' => self::tipoDalTesto($testo), 'descrizione' => mb_substr($prima, 0, 120)]);
            }
            $esito['create']++;
            $esito['commesse'][$id] = true;
        }
        $esito['commesse'] = array_keys($esito['commesse']);
        return $esito;
    }

    /** Commesse del cliente con il residuo da fatturare, nel formato di proposta(). */
    private function commesseDelCliente(int $clienteId): array
    {
        $stmt = $this->pdo->prepare("SELECT id, cliente_id, sottocliente_id, data_incarico, numero_protocollo, importo_totale,
                COALESCE(importo_fatturato, 0) AS importo_fatturato
            FROM {$this->p}incarichi WHERE cliente_id = ? ORDER BY data_incarico DESC, id DESC");
        $stmt->execute([$clienteId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as &$r) $r['residuo'] = round(max(0, (float)$r['importo_totale'] - (float)$r['importo_fatturato']), 2);
        unset($r);
        return $rows;
    }

    private static function meseAnno(string $data): string
    {
        $mesi = ['gennaio', 'febbraio', 'marzo', 'aprile', 'maggio', 'giugno', 'luglio', 'agosto', 'settembre', 'ottobre', 'novembre', 'dicembre'];
        return $mesi[(int)substr($data, 5, 2) - 1] . ' ' . substr($data, 0, 4);
    }

    /** Termini di pagamento della commessa (giorni_pagamento, fine_mese, giorno_pagamento). */
    public function terminiIncarico(int $incaricoId): array
    {
        $stmt = $this->pdo->prepare("SELECT giorni_pagamento, fine_mese, giorno_pagamento FROM {$this->p}incarichi WHERE id = ?");
        $stmt->execute([$incaricoId]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: [];
    }

    /**
     * Termini e piano standard del cliente, se impostati (giorni_pagamento non nullo), altrimenti null.
     * Valgono per le commesse nuove del cliente: vincono sui 30 giorni di default della lettera o del modulo.
     */
    public function terminiCliente(?int $clienteId): ?array
    {
        if (!$clienteId) return null;
        $stmt = $this->pdo->prepare("SELECT giorni_pagamento, fine_mese, giorno_pagamento, piano_fatturazione FROM {$this->p}clienti WHERE id = ?");
        $stmt->execute([$clienteId]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c || $c['giorni_pagamento'] === null) return null;
        return [
            'giorni_pagamento' => (int)$c['giorni_pagamento'],
            'fine_mese' => (int)!empty($c['fine_mese']),
            'giorno_pagamento' => $c['giorno_pagamento'] ? (int)$c['giorno_pagamento'] : null,
            'piano' => TerminiPagamento::PIANI[$c['piano_fatturazione'] ?? ''] ?? null,
        ];
    }

    /**
     * Quando la fattura andava pagata secondo i termini: quelli della rata e della commessa, poi quelli standard
     * del cliente, infine la scadenza registrata. Restituisce [scadenza, descrizione dei termini] o null.
     */
    public function scadenzaAttesa(int $fatturaId): ?array
    {
        $stmt = $this->pdo->prepare("SELECT f.data_emissione, f.data_scadenza, f.cliente_id, f.incarico_id,
                (SELECT r.giorni_pagamento FROM {$this->p}incarichi_rate r WHERE r.fattura_id = f.id ORDER BY r.ordine LIMIT 1) AS giorni_rata
            FROM {$this->p}fatture f WHERE f.id = ?");
        $stmt->execute([$fatturaId]);
        $f = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$f) return null;
        $termini = $f['incarico_id'] ? $this->terminiIncarico((int)$f['incarico_id']) : null;
        if (!$termini) $termini = $this->terminiCliente($f['cliente_id'] ? (int)$f['cliente_id'] : null);
        if ($termini) {
            $t = TerminiPagamento::daRiga($termini, $f['giorni_rata'] !== null ? (int)$f['giorni_rata'] : null);
            return [TerminiPagamento::scadenza((string)$f['data_emissione'], ...$t), TerminiPagamento::descrivi(...$t)];
        }
        return $f['data_scadenza'] ? [(string)$f['data_scadenza'], 'scadenza della fattura'] : null;
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

        $inc['termini'] = TerminiPagamento::descrivi(...TerminiPagamento::daRiga($inc));
        $rate = $this->rate($incaricoId);
        foreach ($rate as &$r) {
            $r['testo_fattura'] = $this->testoFattura($inc, $r);
            $r['termini'] = TerminiPagamento::descrivi(...TerminiPagamento::daRiga($inc, (int)$r['giorni_pagamento']));
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
        $oggetto = trim((string)($inc['offerta_oggetto'] ?? '')) ?: trim((string)($inc['descrizione'] ?? '')) ?: (self::TIPI[$inc['tipo_commessa']] ?? ucfirst((string)$inc['tipo_commessa']));
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
