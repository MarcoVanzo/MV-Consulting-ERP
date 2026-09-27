<?php
/**
 * Classificatore — assegna una categoria (entrata/uscita) a ogni movimento dell'estratto conto.
 *
 * In ordine, e solo se la risposta è univoca:
 *   (a) fattura: riconciliato con fatture emesse → "Incassi clienti"; con fatture di fornitori →
 *       categoria predefinita del fornitore, altrimenti "Fornitori e partner";
 *   (b) regole apprese (chiave normalizzata di controparte/causale e/o codice operazione CBI, per segno);
 *   (c) euristiche sulla causale (commissioni, F24, rata mutuo, carta di credito...);
 * altrimenti il movimento resta "da classificare", con una proposta (movimento simile già classificato
 * o, su richiesta, l'AI) che l'utente conferma in un clic. Le scelte dell'utente non vengono mai sovrascritte.
 *
 * SQL portabile (MySQL in produzione, SQLite nel test CLI).
 */
declare(strict_types=1);

class Classificatore
{
    // Parole che non distinguono una controparte: forme di pagamento, sigle bancarie, forme giuridiche
    private const GENERICHE = ['BONIFICO', 'BONIFICI', 'SEPA', 'ISTANTANEO', 'INSTANT', 'INST', 'SCT', 'SDD', 'CORE', 'B2B', 'RID',
        'VS', 'VOSTRO', 'VOSTRA', 'NS', 'NOSTRO', 'FAVORE', 'DISPOSIZIONE', 'DISP', 'ADDEBITO', 'ACCREDITO', 'PAGAMENTO', 'PAG',
        'ORD', 'ORDINANTE', 'BEN', 'BENEF', 'BENEFICIARIO', 'CAUSALE', 'CAUS', 'CRO', 'TRN', 'RIF', 'ID', 'INFO', 'NOTPROVIDED',
        'IBAN', 'BIC', 'EUR', 'EURO', 'DATA', 'VALUTA', 'OPERAZIONE', 'DEL', 'DI', 'DA', 'AL', 'ALLA', 'ALLE', 'DEI', 'DELLA', 'DELLE',
        'IL', 'LO', 'LA', 'LE', 'GLI', 'UN', 'UNA', 'PER', 'CON', 'SU', 'IN', 'ED', 'SRL', 'SRLS', 'SPA', 'SAS', 'SNC', 'SCARL', 'SOC',
        'COOP', 'ASD', 'SSD', 'FATT', 'FATTURA', 'FATTURE', 'FT', 'NR', 'NUM', 'SALDO', 'ACCONTO', 'RATA', 'GIROCONTO'];

    // (c) Euristiche: categoria → [segno, espressione]. Valgono solo se ne scatta una sola
    private const EURISTICHE = [
        'commissioni_banca' => [-1, '/\b(COMMISSION[EI]|COMM\.|SPESE\s+(TENUTA|CONTO|BONIFIC\w*|INVIO|GESTIONE|FISSE|PRATICA)|CANONE\s+(CONTO|MENSILE|SERVIZI|HOME|INTERNET|CANALE)|COMPETENZE\s+DI\s+CHIUSURA|IMPOSTA\s+(DI\s+)?BOLLO|BOLLO\s+(E\/C|ESTRATTO|CONTO))/'],
        'imposte_tasse' => [-1, '/(\bF24\b|DELEGA\s+(UNIFICATA|F24)|PAGAMENTO\s+DELEGHE|DELEGHE\s+F\s?24|AGENZIA\s+(DELLE\s+)?ENTRATE|\bPAGOPA\b|\bTRIBUTI\b)/'],
        'mutuo_interessi' => [-1, '/(RATA\s+(MUTUO|FINANZIAMENTO|PRESTITO)|RIMBORSO\s+(RATA|FINANZIAMENTO|MUTUO)|AMMORTAMENTO\s+MUTUO|INTERESSI\s+(DEBITORI|PASSIVI))/'],
        'carte_credito' => [-1, '/(CARTA\s+DI\s+CREDITO|ADDEBITO\s+(SALDO\s+)?CARTA|ESTRATTO\s+CONTO\s+CARTA|\bNEXI\b|CARTASI|AMERICAN\s+EXPRESS|\bAMEX\b)/'],
        'assicurazioni' => [-1, '/\b(ASSICURAZ\w*|POLIZZ[AE]|PREMIO\s+ASSICURATIVO|UNIPOL\w*|ALLIANZ|REALE\s+MUTUA)\b/'],
        'stipendi_contributi' => [-1, '/\b(STIPENDI|STIPENDIO|EMOLUMENTI|RETRIBUZION[EI]|CEDOLINO)\b/'],
        'finanziamenti' => [1, '/(EROGAZIONE\s+(MUTUO|FINANZIAMENTO|PRESTITO)|ACCREDITO\s+(MUTUO|FINANZIAMENTO))/'],
        'versamenti_soci' => [1, '/(AUMENTO\s+(DI\s+)?CAPITALE|VERSAMENTO\s+SOCI|FINANZIAMENTO\s+SOCI|FUTURO\s+AUMENTO|CONTO\s+CAPITALE)/'],
        'altre_entrate' => [1, '/INTERESSI\s+(CREDITORI|ATTIVI)/'],
        'rimborsi' => [1, '/\b(RIMBORSO|RIMBORSI|STORNO)\b/'],
    ];

    private $pdo;
    private $p;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->p = $prefix;
    }

    /** Migrazioni v059+ applicate? (codice online prima che la migrazione sia lanciata) */
    public static function tabellePresenti(PDO $pdo, string $prefix): bool
    {
        static $cache = [];
        if (isset($cache[$prefix])) return $cache[$prefix];
        try {
            $pdo->query("SELECT categoria_id, classificazione, abbinabile FROM {$prefix}movimenti_banca WHERE 1 = 0");
            $pdo->query("SELECT 1 FROM {$prefix}regole_categoria WHERE 1 = 0");
            return $cache[$prefix] = true;
        } catch (Throwable $e) {
            return $cache[$prefix] = false;
        }
    }

    // ── Chiavi normalizzate (funzioni pure) ─────────────

    /** Testo del movimento ridotto a parole: maiuscolo, senza accenti, senza numeri, date, ID e parole generiche. */
    public static function testoNormalizzato(string $testo): string
    {
        $t = mb_strtoupper($testo, 'UTF-8');
        $t = strtr($t, ['À' => 'A', 'Á' => 'A', 'È' => 'E', 'É' => 'E', 'Ì' => 'I', 'Í' => 'I', 'Ò' => 'O', 'Ó' => 'O', 'Ù' => 'U', 'Ú' => 'U', '&' => ' E ']);
        $t = preg_replace('/[^A-Z0-9]+/', ' ', $t) ?? '';
        $parole = array_filter(explode(' ', $t), fn($w) =>
            strlen($w) >= 2 && !preg_match('/\d/', $w) && !in_array($w, self::GENERICHE, true));
        return implode(' ', $parole);
    }

    /**
     * Chiave proposta per una regola: la controparte (fino a 4 parole) se c'è, altrimenti
     * le prime 3 parole significative della causale. L'utente la può accorciare o correggere.
     */
    public static function chiaveSuggerita(string $descrizione, ?string $controparte): string
    {
        $cp = self::testoNormalizzato((string)$controparte);
        if (self::chiaveValida($cp)) return implode(' ', array_slice(explode(' ', $cp), 0, 4));
        $d = self::testoNormalizzato($descrizione);
        return implode(' ', array_slice(array_filter(explode(' ', $d)), 0, 3));
    }

    public const MAX_CHIAVE = 150;

    /** Una chiave troppo corta o fatta di sole parole generiche catturerebbe movimenti a caso. */
    public static function chiaveValida(string $chiave): bool
    {
        foreach (explode(' ', trim($chiave)) as $w) {
            if (strlen($w) >= 3) return true;
        }
        return false;
    }

    /** Chiave normalizzata e tagliata a parola intera entro MAX_CHIAVE caratteri (colonna VARCHAR(150)). */
    public static function preparaChiave(string $chiave): string
    {
        $k = self::testoNormalizzato($chiave);
        if (strlen($k) <= self::MAX_CHIAVE) return $k;
        $k = substr($k, 0, self::MAX_CHIAVE + 1);
        return rtrim(substr($k, 0, (int)strrpos($k, ' ')));
    }

    /**
     * La regola vale per il movimento? {chiave, codice_operazione, segno} contro {importo, descrizione, controparte, codice_operazione}.
     * La chiave è obbligatoria; il codice operazione è solo una restrizione in più.
     */
    public static function regolaCorrisponde(array $r, array $mov): bool
    {
        if ((int)$r['segno'] !== ((float)$mov['importo'] >= 0 ? 1 : -1)) return false;
        $chiave = (string)$r['chiave'];
        $codice = (string)$r['codice_operazione'];
        if (!self::chiaveValida($chiave)) return false;
        if ($codice !== '' && $codice !== (string)($mov['codice_operazione'] ?? '')) return false;
        $testo = ' ' . self::testoNormalizzato(($mov['controparte'] ?? '') . ' ' . $mov['descrizione']) . ' ';
        return strpos($testo, ' ' . $chiave . ' ') !== false;
    }

    /** La regola più specifica che vale; null se nessuna o se due ugualmente specifiche dicono categorie diverse. */
    public static function sceltaRegola(array $regole, array $mov): ?array
    {
        $valide = array_values(array_filter($regole, fn($r) => self::regolaCorrisponde($r, $mov)));
        if (!$valide) return null;
        // Peso = lunghezza della chiave, +1 se è limitata a un codice operazione
        $peso = fn($r) => strlen((string)$r['chiave']) + ((string)$r['codice_operazione'] !== '' ? 1 : 0);
        usort($valide, fn($a, $b) => $peso($b) <=> $peso($a));
        if (count($valide) > 1 && $peso($valide[0]) === $peso($valide[1]) && (int)$valide[0]['categoria_id'] !== (int)$valide[1]['categoria_id']) {
            return null;
        }
        return $valide[0];
    }

    /**
     * Variante più specifica di una regola per il movimento: prima il codice operazione, poi una parola
     * in più accanto alla chiave nel testo del movimento. null se non se ne può fare una.
     */
    public static function piuSpecifica(array $mov, string $chiave, string $codice): ?array
    {
        if ($codice === '' && (string)($mov['codice_operazione'] ?? '') !== '') return [$chiave, (string)$mov['codice_operazione']];
        $parole = explode(' ', self::testoNormalizzato(($mov['controparte'] ?? '') . ' ' . $mov['descrizione']));
        $k = explode(' ', $chiave);
        for ($i = 0; $i + count($k) <= count($parole); $i++) {
            if (array_slice($parole, $i, count($k)) !== $k) continue;
            if (isset($parole[$i + count($k)])) return [$chiave . ' ' . $parole[$i + count($k)], $codice];
            if ($i > 0) return [$parole[$i - 1] . ' ' . $chiave, $codice];
        }
        return null;
    }

    /** Codice della categoria indicata dalle euristiche, se una sola scatta. */
    public static function euristica(array $mov): ?string
    {
        $segno = (float)$mov['importo'] >= 0 ? 1 : -1;
        $testo = mb_strtoupper($mov['descrizione'] . ' ' . ($mov['controparte'] ?? ''), 'UTF-8');
        $trovate = [];
        foreach (self::EURISTICHE as $codice => [$s, $re]) {
            if ($s === $segno && preg_match($re, $testo)) $trovate[] = $codice;
        }
        return count($trovate) === 1 ? $trovate[0] : null;
    }

    // ── Classificazione automatica ──────────────────────

    /**
     * Classifica i movimenti dell'estratto conto ancora da classificare (e ricontrolla quelli
     * classificati dalla fattura, che cambiano se la riconciliazione viene annullata).
     * $ids limita ai movimenti indicati. Restituisce i conteggi per fonte.
     */
    public function classifica(?array $ids = null, bool $ancheAutomatiche = false): array
    {
        $cat = $this->codiciCategorie();
        $regole = $this->regole();
        $simili = $this->categoriePerChiave();
        // Con $ancheAutomatiche (dopo una riconciliazione) si rivedono anche le categorie da regola/euristica/AI:
        // la fattura ha la precedenza. Le scelte dell'utente non si toccano mai.
        $fonti = $ancheAutomatiche ? "'fattura','regola','codice_banca','ai'" : "'fattura'";
        $sql = "SELECT m.* FROM {$this->p}movimenti_banca m WHERE m.origine = 'estratto_conto'
            AND (m.classificazione = 'da_classificare' OR m.categoria_fonte IN ($fonti))";
        $params = [];
        if ($ids !== null) {
            $ids = array_values(array_filter(array_map('intval', $ids)));
            if (!$ids) return ['fattura' => 0, 'regola' => 0, 'codice_banca' => 0, 'da_classificare' => 0];
            $sql .= ' AND m.id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')';
            $params = $ids;
        }
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        $out = ['fattura' => 0, 'regola' => 0, 'codice_banca' => 0, 'da_classificare' => 0];
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $id = (int)$m['id'];
            // (a) fattura
            $daFattura = $this->categoriaDaFattura($m, $cat);
            if ($daFattura) {
                if ((int)$m['categoria_id'] !== $daFattura || $m['categoria_fonte'] !== 'fattura') $this->applica($id, $daFattura, 'fattura', null);
                $out['fattura']++;
                continue;
            }
            if (in_array($m['categoria_fonte'], ['fattura', 'regola', 'codice_banca', 'ai'], true)) {
                if ($m['categoria_fonte'] === 'fattura') $this->azzera($id); // riconciliazione annullata
                else $m['categoria_proposta_id'] = null;
            }
            // (b) regole
            $r = self::sceltaRegola($regole, $m);
            if ($r) {
                if ($m['categoria_fonte'] !== 'regola' || (int)$m['regola_id'] !== (int)$r['id']) {
                    $this->pdo->prepare("UPDATE {$this->p}regole_categoria SET utilizzi = utilizzi + 1 WHERE id = ?")->execute([(int)$r['id']]);
                }
                $this->applica($id, (int)$r['categoria_id'], 'regola', (int)$r['id']);
                $out['regola']++;
                continue;
            }
            // (c) euristiche
            $codice = self::euristica($m);
            if ($codice && isset($cat[$codice])) {
                $this->applica($id, $cat[$codice], 'codice_banca', null);
                $out['codice_banca']++;
                continue;
            }
            // Da chiedere all'utente: proposta da un movimento simile già classificato (se non ce n'è già una)
            if ($m['classificazione'] === 'classificato') $this->azzera($id);
            $out['da_classificare']++;
            $segno = (float)$m['importo'] >= 0 ? 1 : -1;
            $k = $segno . '|' . self::chiaveSuggerita((string)$m['descrizione'], $m['controparte']);
            if (empty($m['categoria_proposta_id']) && isset($simili[$k])) {
                $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET categoria_proposta_id = ?, proposta_motivo = ? WHERE id = ?")
                    ->execute([$simili[$k]['categoria_id'], 'Come un movimento simile del ' . $simili[$k]['data'], $id]);
            }
        }
        return $out;
    }

    /**
     * Scelta dell'utente. $opz: applica_simili (bool), chiave (string), usa_codice (bool), aggiorna_regola (bool).
     * Con applica_simili crea (o aggiorna) la regola e la applica subito ai movimenti ancora da classificare;
     * con aggiorna_regola cambia la regola che aveva classificato il movimento e i movimenti che ne derivano.
     * Chi non è admin non modifica regole create da altri: al loro posto nasce una regola più specifica.
     * @return array{regola_id: ?int, aggiornati: int}
     */
    public function classificaUtente(int $movimentoId, int $categoriaId, array $opz, ?int $userId, bool $admin = false): array
    {
        $m = $this->movimento($movimentoId);
        $c = $this->categoria($categoriaId);
        if (!$c || !(int)$c['attiva']) throw new RuntimeException('Categoria non valida');
        $segno = (float)$m['importo'] >= 0 ? 1 : -1;
        if (($segno === 1) !== ($c['tipo'] === 'entrata')) {
            throw new RuntimeException($segno === 1 ? 'Un accredito va in una categoria di entrata' : 'Un addebito va in una categoria di uscita');
        }
        $regolaId = null;
        $aggiornati = 0;
        $avviaTx = !$this->pdo->inTransaction();
        if ($avviaTx) $this->pdo->beginTransaction();
        try {
            if (!empty($opz['aggiorna_regola']) && $m['categoria_fonte'] === 'regola' && (int)$m['regola_id']) {
                $r = $this->regola((int)$m['regola_id']);
                if ($r) $regolaId = $this->salvaRegola((string)$r['chiave'], (string)$r['codice_operazione'], $segno, $categoriaId, $userId, $admin, $m);
            } elseif (!empty($opz['applica_simili'])) {
                $chiave = self::preparaChiave((string)($opz['chiave'] ?? ''));
                $codice = !empty($opz['usa_codice']) ? (string)($m['codice_operazione'] ?? '') : '';
                if (!self::chiaveValida($chiave)) {
                    throw new RuntimeException('Chiave troppo generica: indica almeno una parola che identifichi la controparte');
                }
                if (!self::regolaCorrisponde(['chiave' => $chiave, 'codice_operazione' => $codice, 'segno' => $segno], $m)) {
                    throw new RuntimeException('La chiave non compare in questo movimento');
                }
                $regolaId = $this->salvaRegola($chiave, $codice, $segno, $categoriaId, $userId, $admin, $m);
            }
            $this->applica($movimentoId, $categoriaId, 'utente', null);
            if ($regolaId) $aggiornati = $this->applicaRegola($regolaId, $movimentoId);
            if ($avviaTx) $this->pdo->commit();
        } catch (Throwable $e) {
            if ($avviaTx && $this->pdo->inTransaction()) $this->pdo->rollBack();
            throw $e;
        }
        return ['regola_id' => $regolaId, 'aggiornati' => $aggiornati];
    }

    /**
     * Applica una regola ai movimenti da classificare e a quelli già classificati da regole (mai alle scelte
     * dell'utente), dove è la regola più specifica. Una sola query di lettura. Restituisce quanti ne cambiano.
     */
    public function applicaRegola(int $regolaId, int $escludi = 0): int
    {
        $regole = $this->regole();
        $r = null;
        foreach ($regole as $x) if ((int)$x['id'] === $regolaId) $r = $x;
        if (!$r) return 0;
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->p}movimenti_banca WHERE origine = 'estratto_conto' AND id <> ?
            AND (classificazione = 'da_classificare' OR categoria_fonte = 'regola') AND " . ((int)$r['segno'] === 1 ? 'importo > 0' : 'importo < 0'));
        $stmt->execute([$escludi]);
        $cat = $this->codiciCategorie();
        $n = 0;
        foreach ($stmt->fetchAll(PDO::FETCH_ASSOC) as $m) {
            $scelta = self::sceltaRegola($regole, $m);
            if (!$scelta || (int)$scelta['id'] !== $regolaId) continue;
            if ($m['categoria_fonte'] === 'regola' && (int)$m['regola_id'] === $regolaId && (int)$m['categoria_id'] === (int)$r['categoria_id']) continue;
            // La fattura riconciliata ha la precedenza anche sulle regole
            if ($this->categoriaDaFattura($m, $cat)) continue;
            $this->applica((int)$m['id'], (int)$r['categoria_id'], 'regola', $regolaId);
            $n++;
        }
        if ($n) $this->pdo->prepare("UPDATE {$this->p}regole_categoria SET utilizzi = utilizzi + ? WHERE id = ?")->execute([$n, $regolaId]);
        return $n;
    }

    /**
     * Proposte dell'AI per i movimenti da classificare senza proposta (solo proposte: conferma l'utente).
     * Restituisce quante ne ha registrate. Da chiamare solo se ClaudeClient è configurato.
     */
    public function proponiConAi(int $limite = 60): int
    {
        $cat = array_values(array_filter($this->categorie(), fn($c) => (int)$c['attiva']));
        $stmt = $this->pdo->prepare("SELECT id, data_valuta, importo, descrizione, controparte FROM {$this->p}movimenti_banca
            WHERE origine = 'estratto_conto' AND classificazione = 'da_classificare' AND categoria_proposta_id IS NULL
            ORDER BY data_valuta DESC LIMIT " . max(1, min(200, $limite)));
        $stmt->execute();
        $mov = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$mov) return 0;
        $perNome = [];
        foreach ($cat as $c) $perNome[mb_strtolower($c['nome'], 'UTF-8') . '|' . $c['tipo']] = (int)$c['id'];
        $risposta = DocumentAi::proponiCategorie($mov, $cat);
        $n = 0;
        $tipi = [];
        foreach ($mov as $m) $tipi[(int)$m['id']] = (float)$m['importo'] >= 0 ? 'entrata' : 'uscita';
        $upd = $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET categoria_proposta_id = ?, proposta_motivo = ? WHERE id = ? AND classificazione = 'da_classificare'");
        foreach ($risposta['proposte'] ?? [] as $pr) {
            $id = (int)($pr['id'] ?? 0);
            $k = mb_strtolower((string)($pr['categoria'] ?? ''), 'UTF-8') . '|' . ($tipi[$id] ?? '');
            if (!isset($tipi[$id], $perNome[$k])) continue;
            $upd->execute([$perNome[$k], mb_substr('AI: ' . trim((string)($pr['motivo'] ?? '')), 0, 255, 'UTF-8'), $id]);
            $n++;
        }
        return $n;
    }

    // ── Categorie e regole ──────────────────────────────

    public function categorie(): array
    {
        return $this->pdo->query("SELECT * FROM {$this->p}categorie_movimento ORDER BY tipo, ordine, nome")->fetchAll(PDO::FETCH_ASSOC);
    }

    public function regole(): array
    {
        return $this->pdo->query("SELECT r.*, c.nome AS categoria_nome, c.colore AS categoria_colore FROM {$this->p}regole_categoria r
            JOIN {$this->p}categorie_movimento c ON c.id = r.categoria_id ORDER BY r.utilizzi DESC, r.id")->fetchAll(PDO::FETCH_ASSOC);
    }

    /**
     * Crea la regola o, se esiste già con la stessa chiave, codice e segno, ne cambia la categoria
     * (e con applicaRegola cambiano anche i movimenti che classifica). Una regola creata da altri la
     * modifica solo un admin: per gli altri nasce una regola più specifica ($mov serve a costruirla).
     */
    public function salvaRegola(string $chiave, string $codice, int $segno, int $categoriaId, ?int $userId, bool $admin = true, ?array $mov = null): int
    {
        $chiave = self::preparaChiave($chiave);
        if (!self::chiaveValida($chiave)) throw new RuntimeException('Chiave della regola non valida');
        for ($tentativi = 0; $tentativi < 4; $tentativi++) {
            $stmt = $this->pdo->prepare("SELECT * FROM {$this->p}regole_categoria WHERE chiave = ? AND codice_operazione = ? AND segno = ?");
            $stmt->execute([$chiave, $codice, $segno]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
            if (!$r) {
                $this->pdo->prepare("INSERT INTO {$this->p}regole_categoria (chiave, codice_operazione, segno, categoria_id, created_by) VALUES (?, ?, ?, ?, ?)")
                    ->execute([$chiave, $codice, $segno, $categoriaId, $userId]);
                return (int)$this->pdo->lastInsertId();
            }
            if ((int)$r['categoria_id'] === $categoriaId) return (int)$r['id'];
            $mia = $userId !== null && (int)$r['created_by'] === $userId;
            if ($admin || $mia) {
                $this->pdo->prepare("UPDATE {$this->p}regole_categoria SET categoria_id = ? WHERE id = ?")->execute([$categoriaId, (int)$r['id']]);
                return (int)$r['id'];
            }
            $variante = $mov ? self::piuSpecifica($mov, $chiave, $codice) : null;
            if (!$variante || strlen($variante[0]) > self::MAX_CHIAVE) break;
            [$chiave, $codice] = $variante;
        }
        throw new RuntimeException('Esiste già una regola di un altro utente per questi movimenti: chiedi a un amministratore di modificarla');
    }

    /** Elimina una regola: i movimenti che classificava restano nella loro categoria, ma diventano scelte dell'utente. */
    public function eliminaRegola(int $id): void
    {
        $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET categoria_fonte = 'utente', regola_id = NULL WHERE regola_id = ?")->execute([$id]);
        $this->pdo->prepare("DELETE FROM {$this->p}regole_categoria WHERE id = ?")->execute([$id]);
    }

    public function contaDaClassificare(): int
    {
        return (int)$this->pdo->query("SELECT COUNT(*) FROM {$this->p}movimenti_banca WHERE origine = 'estratto_conto' AND classificazione = 'da_classificare'")->fetchColumn();
    }

    // ── Helper ──────────────────────────────────────────

    private function applica(int $id, int $categoriaId, string $fonte, ?int $regolaId): void
    {
        $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET categoria_id = ?, categoria_fonte = ?, regola_id = ?,
                classificazione = 'classificato', categoria_proposta_id = NULL, proposta_motivo = NULL WHERE id = ?")
            ->execute([$categoriaId, $fonte, $regolaId, $id]);
    }

    private function azzera(int $id): void
    {
        $this->pdo->prepare("UPDATE {$this->p}movimenti_banca SET categoria_id = NULL, categoria_fonte = NULL, regola_id = NULL,
                classificazione = 'da_classificare' WHERE id = ?")->execute([$id]);
    }

    /** (a) Categoria che deriva dalle fatture riconciliate (o dall'avviso di pagamento collegato). */
    private function categoriaDaFattura(array $m, array $cat): ?int
    {
        if ((int)($m['avviso_id'] ?? 0)) return $cat['incassi_clienti'] ?? null;
        $stmt = $this->pdo->prepare("SELECT r.tipo, fp.fornitore_id, fo.categoria_default_id FROM {$this->p}riconciliazioni r
            LEFT JOIN {$this->p}fatture_passive fp ON r.tipo = 'fattura_passiva' AND fp.id = r.documento_id
            LEFT JOIN {$this->p}fornitori fo ON fo.id = fp.fornitore_id
            WHERE r.movimento_id = ? ORDER BY r.id");
        $stmt->execute([(int)$m['id']]);
        $righe = $stmt->fetchAll(PDO::FETCH_ASSOC);
        if (!$righe) return null;
        if ($righe[0]['tipo'] === 'fattura') return $cat['incassi_clienti'] ?? null;
        $default = array_unique(array_filter(array_map(fn($r) => (int)$r['categoria_default_id'], $righe)));
        return count($default) === 1 ? (int)reset($default) : ($cat['fornitori_partner'] ?? null);
    }

    /** codice → id delle categorie di sistema attive. */
    private function codiciCategorie(): array
    {
        $out = [];
        foreach ($this->categorie() as $c) {
            if ($c['codice'] && (int)$c['attiva']) $out[$c['codice']] = (int)$c['id'];
        }
        return $out;
    }

    /** "segno|chiave" → ultima categoria scelta dall'utente o da una regola per movimenti con quella chiave. */
    private function categoriePerChiave(): array
    {
        $rows = $this->pdo->query("SELECT importo, descrizione, controparte, categoria_id, data_valuta FROM {$this->p}movimenti_banca
            WHERE classificazione = 'classificato' AND categoria_fonte IN ('utente', 'regola') ORDER BY data_valuta, id")->fetchAll(PDO::FETCH_ASSOC);
        $out = [];
        foreach ($rows as $r) {
            $chiave = self::chiaveSuggerita((string)$r['descrizione'], $r['controparte']);
            if (!self::chiaveValida($chiave)) continue;
            $out[((float)$r['importo'] >= 0 ? 1 : -1) . '|' . $chiave] = ['categoria_id' => (int)$r['categoria_id'], 'data' => date('d/m/Y', strtotime((string)$r['data_valuta']))];
        }
        return $out;
    }

    private function movimento(int $id): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->p}movimenti_banca WHERE id = ?");
        $stmt->execute([$id]);
        $m = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$m) throw new RuntimeException('Movimento non trovato');
        return $m;
    }

    private function regola(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->p}regole_categoria WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }

    private function categoria(int $id): ?array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->p}categorie_movimento WHERE id = ?");
        $stmt->execute([$id]);
        return $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
    }
}
