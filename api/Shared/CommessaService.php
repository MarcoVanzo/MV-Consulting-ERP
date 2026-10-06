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

    /** Nota delle commesse create dalle fatture (non a canone): così commessaDaProseguire le riconosce. */
    private const NOTA_DA_FATTURE = 'Commessa creata dalle fatture emesse';

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
     *
     * $testoLibero (descrizione di una fattura): un «N/AAAA» è un protocollo solo dopo «Prot.» o in coda a un
     * altro protocollo («Prot. n. 3991/2026 + 452/2027»); senza codici non c'è nessuna chiave. Sempre esclusi:
     * le date, i mesi di competenza o di periodo («competenza 03/2026», «periodo 01/2026 - 06/2026») e i numeri
     * di fattura («fattura n. 45/2026»).
     * @return string[]
     */
    public static function chiaviProtocollo(?string $protocollo, bool $testoLibero = false): array
    {
        $s = mb_strtoupper(trim((string)$protocollo), 'UTF-8');
        if ($s === '') return [];
        $s = preg_replace([
            '/\b\d{1,2}\/\d{1,2}\/\d{2,4}\b/',
            '/\b(?:COMPETENZA|MESE|MESI|PERIODO)\b\W{0,5}(?:(?:DI|DEL|DAL|AL|DA|A)\s+)?\d{1,2}\s*\/\s*\d{4}(?:\s*(?:-|AL|A)\s*\d{1,2}\s*\/\s*\d{4})?/u',
            '/\b(?:FATT\w*|FT)\.?\s*(?:N(?:R|UM)?\w*\.?\s*|NUMERO\s*)?°?\s*\d{1,6}\s*\/\s*\d{2,4}\b/u',
        ], ' ', $s);
        $chiavi = [];
        $numeri = fn(string $t) => preg_match_all('/\b(\d{1,6})\s*\/\s*(\d{4})\b/', $t, $m, PREG_SET_ORDER)
            ? array_map(fn($x) => (int)$x[1] . '/' . $x[2], $m) : [];
        if (!$testoLibero) {
            $chiavi = $numeri($s);
        } else {
            $n = '\d{1,6}\s*\/\s*\d{4}\b';
            if (preg_match_all("/\\bPROT\\w*\\.?\\s*(?:N[°ºR]?\\w*\\.?\\s*)?°?\\s*($n(?:\\s*(?:\\+|,|\\bE\\b)\\s*$n)*)/u", $s, $m)) {
                foreach ($m[1] as $x) $chiavi = array_merge($chiavi, $numeri($x));
            }
            if (preg_match_all("/\\+\\s*($n)/u", $s, $m)) {
                foreach ($m[1] as $x) $chiavi = array_merge($chiavi, $numeri($x));
            }
        }
        // Il codice alfanumerico si cerca senza la parte «Prot. n. 820/2026», che darebbe «PROT.N.820»
        $resto = preg_replace(['/\bPROT\w*\.?\s*(N\w*\.?)?/', '/\b\d{1,6}\s*\/\s*\d{4}\b/'], ' ', $s);
        if (preg_match_all('/\b[A-Z]{2,}(?:\s*\.\s*[A-Z0-9]+){2,}\b/', $resto, $m)) {
            foreach ($m[0] as $x) $chiavi[] = preg_replace('/\s+/', '', $x);
        }
        if (!$chiavi && !$testoLibero) $chiavi[] = preg_replace('/[^A-Z0-9]/', '', $s);
        return array_values(array_unique(array_filter($chiavi)));
    }

    /**
     * Commessa di una riga di fattura: quella che ha un protocollo in comune col testo della riga
     * («… Prot. n. 3991/2026 + 452/2027 (SZ.DPS.F307.26)»). $incarichi: righe con id, numero_protocollo,
     * sottocliente_id. Più commesse candidate: vince quella del sottocliente della riga, se noto.
     */
    public static function incaricoDellaRiga(string $descrizione, array $incarichi, ?int $sottoclienteId = null): ?array
    {
        // Solo i codici veri: senza protocollo («Prot. n. …», «+ n/aaaa») né codice alfanumerico la riga non si abbina
        $chiavi = self::chiaviProtocollo($descrizione, true);
        if (!$chiavi) return null;
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
     * Le fatture segnate «ricorrente» sono un canone (serieMensili: stesso cliente, sottocliente e importo, almeno
     * 3 in mesi diversi a 25–35 giorni l'una dall'altra): candidate a una commessa a canone. Restituisce anche le commesse di ogni cliente per la scelta manuale.
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
            $stmt = $this->pdo->prepare("SELECT i.id, i.cliente_id, i.sottocliente_id, i.data_incarico, i.tipo_commessa, i.descrizione,
                    i.numero_protocollo, i.importo_totale, COALESCE(i.importo_fatturato, 0) AS importo_fatturato, sc.nome AS sottocliente_nome
                FROM {$this->p}incarichi i LEFT JOIN {$this->p}sottoclienti sc ON sc.id = i.sottocliente_id
                WHERE i.cliente_id IN (" . self::segnaposto($clienti) . ") ORDER BY i.data_incarico DESC, i.id DESC");
            $stmt->execute($clienti);
            foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $r['residuo'] = round(max(0, (float)$r['importo_totale'] - (float)$r['importo_fatturato']), 2);
                $commesse[(int)$r['cliente_id']][] = $r;
            }
        }

        $canoni = self::serieMensili($fatture);
        foreach ($fatture as &$f) {
            $f['ricorrente'] = count($canoni[(int)$f['id']] ?? []) >= 3;
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
        // «Acconto 30%»: la percentuale citata del valore di una sola commessa con quel residuo
        if (preg_match_all('/\b(\d{1,3})\s*%/', $testo, $m)) {
            $hit = [];
            foreach ($commesse as $c) {
                foreach ($m[1] as $pc) {
                    if ((int)$pc > 0 && (int)$pc <= 100 && abs((float)$c['importo_totale'] * (int)$pc / 100 - (float)$f['imponibile']) <= 1
                        && (float)$c['residuo'] >= (float)$f['imponibile'] - 1) $hit[(int)$c['id']] = true;
                }
            }
            if (count($hit) === 1) return ['incarico_id' => (int)array_key_first($hit), 'motivo' => 'percentuale del valore della commessa'];
        }
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
        $stmt = $this->pdo->prepare("SELECT id, cliente_id, sottocliente_id, data_emissione, imponibile, incarico_id
            FROM {$this->p}fatture WHERE id IN (" . self::segnaposto($fatturaIds) . ") ORDER BY data_emissione, id");
        $stmt->execute($fatturaIds);
        $fatture = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (count($fatture) !== count($fatturaIds)) throw new InvalidArgumentException('Fattura non trovata');
        $clienti = array_unique(array_map(fn($f) => (int)$f['cliente_id'], $fatture));
        if (count($clienti) !== 1 || !$clienti[0]) throw new InvalidArgumentException('Le fatture devono essere dello stesso cliente');
        if (array_filter($fatture, fn($f) => $f['incarico_id'])) throw new InvalidArgumentException('Una delle fatture è già collegata a una commessa');
        $sotto = array_unique(array_map(fn($f) => (int)$f['sottocliente_id'], $fatture));

        $positive = array_values(array_filter($fatture, fn($f) => (float)$f['imponibile'] > 0));
        if (!$positive) throw new InvalidArgumentException('Serve almeno una fattura (le note di credito da sole non bastano)');
        $ricorrente = !empty($o['ricorrente']);
        $mesi = max(1, min(120, (int)($o['mesi'] ?? 12)));
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
            'note' => $ricorrente ? self::notaCanone($canone, $mesi) : self::NOTA_DA_FATTURE,
        ];
        $cols = array_keys($i);
        $this->pdo->prepare("INSERT INTO {$this->p}incarichi (" . implode(', ', $cols) . ") VALUES (" . implode(', ', array_fill(0, count($cols), '?')) . ")")
            ->execute(array_values($i));
        $id = (int)$this->pdo->lastInsertId();

        $ins = $this->pdo->prepare("INSERT INTO {$this->p}incarichi_rate
            (incarico_id, ordine, descrizione, percentuale, importo, data_prevista, giorni_pagamento, fattura_id) VALUES (?, ?, ?, ?, ?, ?, ?, ?)");
        if ($ricorrente) {
            $perc = self::percentuali(array_fill(0, $mesi, $canone));
            for ($k = 0; $k < $mesi; $k++) {
                $data = TerminiPagamento::piuMesi($i['data_incarico'], $k);
                $ins->execute([$id, $k + 1, 'Canone ' . self::meseAnno($data), $perc[$k], $canone, $data, $i['giorni_pagamento'], null]);
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
            // Una fattura stornata per intero non diventa una rata da 0 €; le percentuali sommano 100
            $rate = array_values(array_filter(array_map(null, $positive, $importi), fn($x) => $x[1] > 0.005));
            $perc = self::percentuali(array_column($rate, 1));
            foreach ($rate as $k => [$f, $importo]) {
                $ins->execute([$id, $k + 1, count($rate) === 1 ? 'Saldo' : 'Fattura ' . ($k + 1), $perc[$k],
                    $importo, $f['data_emissione'], $i['giorni_pagamento'], $f['id']]);
            }
            $this->pdo->prepare("UPDATE {$this->p}fatture SET incarico_id = ? WHERE id IN (" . self::segnaposto($fatturaIds) . ")")
                ->execute(array_merge([$id], $fatturaIds));
        }
        $this->offertaRapida($id, $i);
        return $id;
    }

    /** Tipo di commessa indovinato dal testo della fattura (prima parola chiave trovata), altrimenti «altro». */
    public static function tipoDalTesto(string $testo): string
    {
        foreach ([
            '/\bnis\s?2\b/i' => 'nis2', '/\bdpo\b|privacy|gdpr/i' => 'dpo', '/formazion|\bcorso\b/i' => 'formazione',
            '/noleggi|\bcanone\b/i' => 'noleggio', '/viaggi|trasport|\bvolo\b|hotel|soggiorn|\btrip\b|travel/i' => 'viaggio',
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
     *  3. una fattura che prosegue una commessa già creata dalle fatture (commessaDaProseguire: stessa intestazione
     *     entro 12 mesi, o stesso importo un mese dopo l'ultima rata) si aggancia lì e la commessa cresce: le fatture
     *     importate una alla volta finiscono sulla stessa commessa come se fossero arrivate insieme;
     *  4. le altre diventano commesse: un canone (serieMensili, almeno 3 mesi di fila) fa una commessa ricorrente con
     *     una rata per ogni mese fatturato; due fatture mensili di pari importo una commessa con due rate; le fatture
     *     con la stessa intestazione (il testo prima dei due punti: acconto e saldo dello stesso viaggio) emesse entro
     *     12 mesi dalla prima una commessa sola; ogni altra fattura una commessa singola.
     * Una nota di credito va con la fattura che storna (numero citato o stesso importo): sulla sua commessa, o nel
     * gruppo che la crea; un gruppo stornato per intero non diventa commessa. Fatture senza cliente restano fuori.
     * Da chiamare dentro una transazione; il ricalcolo dei totali delle commesse toccate resta a chi chiama.
     * @return array{collegate:int, create:int, ricorrenti:int, da_scegliere:int, saltate:int, commesse:int[]}
     */
    public function creaCommesseMancanti(?array $fatturaIds = null): array
    {
        $esito = ['collegate' => 0, 'create' => 0, 'ricorrenti' => 0, 'da_scegliere' => 0, 'saltate' => 0, 'commesse' => []];
        // La ricorrenza si giudica su tutte le fatture senza commessa del cliente, anche fuori dall'elenco indicato
        $tutte = $this->pdo->query("SELECT id, numero_fattura, cliente_id, sottocliente_id, data_emissione, imponibile, descrizione
            FROM {$this->p}fatture WHERE incarico_id IS NULL ORDER BY cliente_id, data_emissione, id")->fetchAll(PDO::FETCH_ASSOC);
        $scelte = $fatturaIds === null ? null : array_flip(array_map('intval', $fatturaIds));
        $serie = self::serieMensili($tutte);

        $daCreare = [];
        $gruppoDi = [];
        $note = [];
        foreach ($tutte as $f) {
            if ($scelte !== null && !isset($scelte[(int)$f['id']])) continue;
            if (!$f['cliente_id'] || abs((float)$f['imponibile']) < 0.005) { $esito['saltate']++; continue; }
            if ((float)$f['imponibile'] < 0) { $note[] = $f; continue; }
            $mia = $serie[(int)$f['id']] ?? [$f];
            $f['ricorrente'] = count($mia) >= 3;
            $prop = $this->proposta($f, $this->commesseDelCliente((int)$f['cliente_id']));
            if ($prop && $prop['motivo'] !== self::MOTIVO_DEBOLE) {
                $this->collegaFatturaACommessa((int)$f['id'], $prop['incarico_id']);
                $esito['collegate']++;
                $esito['commesse'][$prop['incarico_id']] = true;
            } elseif ($prop) {
                $esito['da_scegliere']++;
            } elseif ($id = $this->commessaDaProseguire($f)) {
                $this->aggiungiFattura($id, $f);
                $esito['collegate']++;
                $esito['commesse'][$id] = true;
            } else {
                $intestazione = self::intestazione((string)$f['descrizione']);
                if (count($mia) >= 2) {
                    $g = 'c' . (int)$mia[0]['id'];
                } elseif (mb_strlen($intestazione) >= 30) {
                    // Stessa intestazione, ma al massimo 12 mesi tra la prima e l'ultima fattura del gruppo
                    $base = 'v' . $f['cliente_id'] . '|' . (int)$f['sottocliente_id'] . '|' . mb_strtolower($intestazione);
                    $g = $base;
                    for ($n = 1; isset($daCreare[$g]) && self::giorni((string)$daCreare[$g][0]['data_emissione'], (string)$f['data_emissione']) > 365; $n++) {
                        $g = $base . '#' . $n;
                    }
                } else {
                    $g = 's' . $f['id'];
                }
                $daCreare[$g][] = $f;
                $gruppoDi[(int)$f['id']] = $g;
            }
        }

        foreach ($note as $n) {
            // Il protocollo citato nella nota vince: è la commessa della fattura stornata anche se quella non è qui
            $inc = self::incaricoDellaRiga((string)$n['descrizione'], $this->commesseDelCliente((int)$n['cliente_id']),
                $n['sottocliente_id'] ? (int)$n['sottocliente_id'] : null);
            if ($inc) {
                $this->collegaFatturaACommessa((int)$n['id'], (int)$inc['id']);
                $esito['collegate']++;
                $esito['commesse'][(int)$inc['id']] = true;
                continue;
            }
            $orig = $this->fatturaStornata($n);
            if ($orig && $orig['incarico_id']) {
                $this->collegaFatturaACommessa((int)$n['id'], (int)$orig['incarico_id']);
                $esito['collegate']++;
                $esito['commesse'][(int)$orig['incarico_id']] = true;
            } elseif ($orig && isset($gruppoDi[(int)$orig['id']])) {
                $daCreare[$gruppoDi[(int)$orig['id']]][] = $n;
            } else {
                $esito['saltate']++;
            }
        }

        foreach ($daCreare as $k => $fatture) {
            $positive = array_values(array_filter($fatture, fn($f) => (float)$f['imponibile'] > 0));
            // Fattura stornata per intero (e magari riemessa altrove): niente commessa
            if (array_sum(array_map(fn($f) => (float)$f['imponibile'], $fatture)) < 0.005) { $esito['saltate'] += count($fatture); continue; }
            $prima = self::intestazione((string)$positive[0]['descrizione']);
            $testo = implode("\n", array_column($fatture, 'descrizione'));
            if ($k[0] === 'c' && count($positive) >= 3) {
                // Canone: una rata per ogni mese fatturato (nella serie c'è una fattura per mese); le prossime la allungano
                $id = $this->creaDaFatture(array_column($fatture, 'id'), ['ricorrente' => true, 'mesi' => count($positive),
                    'tipo_commessa' => self::tipoDalTesto($testo), 'descrizione' => mb_substr('Canone ' . $prima, 0, 120)]);
                $esito['ricorrenti']++;
            } else {
                $id = $this->creaDaFatture(array_column($fatture, 'id'), ['tipo_commessa' => self::tipoDalTesto($testo), 'descrizione' => mb_substr($prima, 0, 120)]);
            }
            $esito['create']++;
            $esito['commesse'][$id] = true;
        }
        $esito['commesse'] = array_keys($esito['commesse']);
        return $esito;
    }

    /**
     * Commessa già creata dalle fatture (creaDaFatture: nota NOTA_DA_FATTURE o canone) che la fattura prosegue, dello
     * stesso cliente e sottocliente:
     *  - una sua fattura ha la stessa intestazione (almeno 30 caratteri) e la prima è al massimo di 12 mesi prima; oppure
     *  - tutte le sue rate hanno l'importo della fattura e l'ultima è di un mese prima (meseDopo): il canone continua.
     * Le commesse da lettera d'incarico o da offerta non si allungano mai da sole. Solo se la candidata è una sola.
     */
    private function commessaDaProseguire(array $f): ?int
    {
        $stmt = $this->pdo->prepare("SELECT i.id, i.sottocliente_id, r.importo, r.data_prevista, ff.descrizione, ff.data_emissione
            FROM {$this->p}incarichi i
            JOIN {$this->p}incarichi_rate r ON r.incarico_id = i.id
            LEFT JOIN {$this->p}fatture ff ON ff.id = r.fattura_id
            WHERE i.cliente_id = ? AND (i.note = ? OR i.note LIKE ?)
            ORDER BY i.id, r.data_prevista, r.ordine, r.id");
        $stmt->execute([(int)$f['cliente_id'], self::NOTA_DA_FATTURE, 'Canone di %']);
        $perCommessa = [];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ((int)$r['sottocliente_id'] === (int)$f['sottocliente_id']) $perCommessa[(int)$r['id']][] = $r;
        }

        $importo = round((float)$f['imponibile'], 2);
        $data = (string)$f['data_emissione'];
        $intestazione = mb_strtolower(self::intestazione((string)$f['descrizione']));
        $trovate = [];
        foreach ($perCommessa as $id => $rate) {
            $ultima = end($rate);
            $canone = !array_filter($rate, fn($r) => abs((float)$r['importo'] - $importo) > 1.0)
                && $ultima['data_prevista'] && self::meseDopo((string)$ultima['data_prevista'], $data);
            $date = array_filter(array_column($rate, 'data_emissione'));
            $stessa = mb_strlen($intestazione) >= 30 && $date && abs(self::giorni(min($date), $data)) <= 365
                && array_filter($rate, fn($r) => $r['descrizione'] !== null && mb_strtolower(self::intestazione((string)$r['descrizione'])) === $intestazione);
            if ($canone || $stessa) $trovate[] = $id;
        }
        return count($trovate) === 1 ? $trovate[0] : null;
    }

    /**
     * Aggiunge a una commessa creata dalle fatture la fattura che la prosegue: una rata in più, già fatturata, e il
     * valore che cresce del suo importo (anche quello dell'offerta registrata con la commessa). Rate tutte uguali e
     * mensili da 3 in su: è un canone, e rate e nota lo dicono.
     */
    private function aggiungiFattura(int $incaricoId, array $f): void
    {
        $stmt = $this->pdo->prepare("SELECT importo_totale, giorni_pagamento FROM {$this->p}incarichi WHERE id = ?");
        $stmt->execute([$incaricoId]);
        $inc = $stmt->fetch(PDO::FETCH_ASSOC);
        $importo = round((float)$f['imponibile'], 2);
        $stmt = $this->pdo->prepare("SELECT COALESCE(MAX(ordine), 0) FROM {$this->p}incarichi_rate WHERE incarico_id = ?");
        $stmt->execute([$incaricoId]);
        $this->pdo->prepare("INSERT INTO {$this->p}incarichi_rate
            (incarico_id, ordine, descrizione, percentuale, importo, data_prevista, giorni_pagamento, fattura_id) VALUES (?, ?, ?, 0, ?, ?, ?, ?)")
            ->execute([$incaricoId, (int)$stmt->fetchColumn() + 1, 'Fattura', $importo, $f['data_emissione'], (int)($inc['giorni_pagamento'] ?? 30), $f['id']]);
        $totale = round((float)$inc['importo_totale'] + $importo, 2);
        $this->pdo->prepare("UPDATE {$this->p}incarichi SET importo_totale = ? WHERE id = ?")->execute([$totale, $incaricoId]);
        $this->pdo->prepare("UPDATE {$this->p}offerte SET imponibile = ? WHERE incarico_id = ? AND origine = 'rapida'")->execute([$totale, $incaricoId]);
        $this->pdo->prepare("UPDATE {$this->p}fatture SET incarico_id = ? WHERE id = ?")->execute([$incaricoId, $f['id']]);

        $stmt = $this->pdo->prepare("SELECT id, importo, data_prevista FROM {$this->p}incarichi_rate WHERE incarico_id = ? ORDER BY ordine, id");
        $stmt->execute([$incaricoId]);
        $rate = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $canone = count($rate) >= 3 && !array_filter($rate, fn($r) => abs((float)$r['importo'] - $importo) > 1.0);
        for ($k = 1; $canone && $k < count($rate); $k++) {
            $canone = self::meseDopo((string)$rate[$k - 1]['data_prevista'], (string)$rate[$k]['data_prevista']);
        }
        $perc = self::percentuali(array_map(fn($r) => (float)$r['importo'], $rate));
        $upd = $this->pdo->prepare("UPDATE {$this->p}incarichi_rate SET descrizione = ?, percentuale = ? WHERE id = ?");
        foreach ($rate as $k => $r) {
            $upd->execute([$canone ? 'Canone ' . self::meseAnno((string)$r['data_prevista']) : (count($rate) === 1 ? 'Saldo' : 'Fattura ' . ($k + 1)),
                $perc[$k], $r['id']]);
        }
        if ($canone) {
            $this->pdo->prepare("UPDATE {$this->p}incarichi SET note = ? WHERE id = ?")->execute([self::notaCanone($importo, count($rate)), $incaricoId]);
        }
    }

    /**
     * Serie mensili di fatture: stesso cliente, sottocliente e importo, ognuna in un mese diverso e 25–35 giorni dopo
     * la precedente (meseDopo). Un salto (un mese senza fattura, due fatture nello stesso mese) chiude la serie e ne
     * apre un'altra. Restituisce id fattura → fatture della sua serie in ordine di data: da 3 in su è un canone.
     * @return array<int, array[]>
     */
    public static function serieMensili(array $fatture): array
    {
        $gruppi = [];
        foreach ($fatture as $f) {
            if ($f['cliente_id'] && (float)$f['imponibile'] > 0) {
                $gruppi[$f['cliente_id'] . '|' . (int)($f['sottocliente_id'] ?? 0) . '|' . round((float)$f['imponibile'], 2)][] = $f;
            }
        }
        $out = [];
        foreach ($gruppi as $g) {
            usort($g, fn($a, $b) => [(string)$a['data_emissione'], (int)$a['id']] <=> [(string)$b['data_emissione'], (int)$b['id']]);
            $serie = [];
            foreach ($g as $f) {
                if ($serie && !self::meseDopo((string)end($serie)['data_emissione'], (string)$f['data_emissione'])) {
                    foreach ($serie as $x) $out[(int)$x['id']] = $serie;
                    $serie = [];
                }
                $serie[] = $f;
            }
            foreach ($serie as $x) $out[(int)$x['id']] = $serie;
        }
        return $out;
    }

    /** Due fatture consecutive di un canone: in mesi diversi, la seconda 25–35 giorni dopo la prima. */
    private static function meseDopo(string $prima, string $dopo): bool
    {
        $g = self::giorni($prima, $dopo);
        return $g >= 25 && $g <= 35 && substr($prima, 0, 7) !== substr($dopo, 0, 7);
    }

    /** Giorni da $a a $b (date Y-m-d), negativi se $b viene prima. */
    private static function giorni(string $a, string $b): int
    {
        return (int)round((strtotime($b) - strtotime($a)) / 86400);
    }

    /** Percentuali degli importi al centesimo: l'ultima è 100 meno le altre, così la somma fa sempre 100. */
    private static function percentuali(array $importi): array
    {
        $importi = array_values($importi);
        $tot = array_sum($importi);
        $n = count($importi);
        $out = [];
        $somma = 0.0;
        foreach ($importi as $k => $v) {
            $p = $k === $n - 1 ? round(100 - $somma, 2) : ($tot > 0 ? round($v / $tot * 100, 2) : 0.0);
            $somma += $p;
            $out[] = $p;
        }
        return $out;
    }

    private static function notaCanone(float $canone, int $mesi): string
    {
        return 'Canone di ' . number_format($canone, 2, ',', '.') . " € al mese per $mesi mesi";
    }

    /** Segnaposto «?, ?, …» per una lista di valori in IN (…). */
    private static function segnaposto(array $valori): string
    {
        return implode(', ', array_fill(0, count($valori), '?'));
    }

    /** Intestazione di una fattura: la prima riga fino ai due punti, senza «[Nota di credito]» e l'articolo «the». */
    private static function intestazione(string $descrizione): string
    {
        $t = preg_replace('/^\s*\[Nota di credito\]\s*/u', '', $descrizione);
        $t = (string)preg_split('/[:\r\n]/', $t)[0];
        return trim((string)preg_replace(['/\s+/u', '/^the\s+/i', '/[\s.]+$/'], [' ', '', ''], $t));
    }

    /**
     * Fattura emessa che una nota di credito storna: quella col numero citato nel testo («storno fattura n.12AV»);
     * se il numero non combacia per intero («n. 12» per «12/2026» o «12/001») quella con la stessa parte numerica,
     * se nell'anno è una sola; altrimenti l'unica dello stesso cliente con lo stesso importo, emessa non dopo la nota.
     * Null se non è certa (anche quando cita una fattura che qui non c'è).
     */
    private function fatturaStornata(array $nota): ?array
    {
        $stmt = $this->pdo->prepare("SELECT id, numero_fattura, sottocliente_id, data_emissione, imponibile, incarico_id FROM {$this->p}fatture
            WHERE cliente_id = ? AND imponibile > 0 AND data_emissione <= ? ORDER BY data_emissione DESC, id DESC");
        $stmt->execute([(int)$nota['cliente_id'], (string)$nota['data_emissione']]);
        $fatture = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $norm = fn($x) => preg_replace('/[^A-Z0-9]/', '', mb_strtoupper((string)$x));
        $numeroDi = fn($x) => preg_match('/^\D*0*(\d+)/', (string)$x, $mm) ? (int)$mm[1] : null;
        $ambigua = false;
        if (preg_match_all('/\bf(?:at)?t(?:ura)?\.?\s*(?:n(?:r|um)?\.?\s*|numero\s*)?([A-Z0-9][A-Z0-9\/-]*)/i', (string)$nota['descrizione'], $m)) {
            $citati = array_filter($m[1], fn($x) => preg_match('/\d/', $x));
            foreach ($citati as $numero) {
                $stessa = array_values(array_filter($fatture, fn($f) => $norm($f['numero_fattura']) === $norm($numero)));
                if ($stessa) return $stessa[0];
                $anno = preg_match('/^\d+\s*\/\s*(\d{4})$/', $numero, $ma) ? $ma[1] : substr((string)$nota['data_emissione'], 0, 4);
                $simili = array_values(array_filter($fatture, fn($f) => $numeroDi($f['numero_fattura']) === $numeroDi($numero)
                    && substr((string)$f['data_emissione'], 0, 4) === $anno));
                if (count($simili) === 1) return $simili[0];
                if ($simili) $ambigua = true;
            }
            // Cita una fattura che qui non c'è (es. di un anno non importato): l'importo da solo non basta
            if ($citati && !$ambigua) return null;
        }
        $importo = -(float)$nota['imponibile'];
        $sotto = (int)($nota['sottocliente_id'] ?? 0);
        $pari = array_values(array_filter($fatture, fn($f) => abs((float)$f['imponibile'] - $importo) < 0.01
            && (!$sotto || !(int)$f['sottocliente_id'] || (int)$f['sottocliente_id'] === $sotto)));
        return count($pari) === 1 ? $pari[0] : null;
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
     * Fatture non pagate di commesse o clienti con giorno fisso di pagamento («… al 10»): la scadenza importata da
     * Sistemi è il fine mese (31/03), il pagamento arriva il giorno fisso successivo (10/04). Senza questo il
     * promemoria e lo «Scaduto» le danno in ritardo prima del tempo. Ripetibile: tocca solo le date non già allineate.
     * Restituisce quante fatture ha spostato.
     */
    public function allineaScadenzeGiornoFisso(): int
    {
        $rows = $this->pdo->query("SELECT f.id, f.data_scadenza, COALESCE(i.giorno_pagamento, c.giorno_pagamento) AS giorno
            FROM {$this->p}fatture f
            LEFT JOIN {$this->p}incarichi i ON i.id = f.incarico_id
            LEFT JOIN {$this->p}clienti c ON c.id = f.cliente_id
            WHERE f.stato <> 'pagata' AND f.data_scadenza IS NOT NULL
              AND COALESCE(i.giorno_pagamento, c.giorno_pagamento) IS NOT NULL")->fetchAll(PDO::FETCH_ASSOC);
        $upd = $this->pdo->prepare("UPDATE {$this->p}fatture SET data_scadenza = ? WHERE id = ?");
        $n = 0;
        foreach ($rows as $r) {
            $giorno = (int)$r['giorno'];
            if ($giorno < 1 || $giorno > 31) continue;
            $d = new DateTimeImmutable(substr((string)$r['data_scadenza'], 0, 10));
            if ((int)$d->format('j') === min($giorno, (int)$d->format('t'))) continue;
            // Come TerminiPagamento::scadenza: il primo giorno fisso utile da lì in avanti
            $mese = (int)$d->format('j') > $giorno ? $d->modify('first day of next month') : $d->modify('first day of this month');
            $upd->execute([$mese->setDate((int)$mese->format('Y'), (int)$mese->format('n'), min($giorno, (int)$mese->format('t')))->format('Y-m-d'), $r['id']]);
            $n++;
        }
        return $n;
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
