<?php
/**
 * EstrattoTabellare — estratti conto esportati come tabella (CSV o Excel .xlsx).
 *
 * Ogni banca ha colonne diverse: si riconoscono dall'intestazione (Data, Valuta, Descrizione,
 * Importo oppure Dare/Avere, Entrate/Uscite…). Se non basta, l'utente sceglie le colonne una volta
 * e la scelta si salva per quell'intestazione (RiconciliazioneController), così il file successivo
 * della stessa banca entra da solo.
 * Solo funzioni pure: si prova da CLI (tests/importa_cli.php).
 */
declare(strict_types=1);

require_once __DIR__ . '/EstrattoContoParser.php';

/** Colonne non riconosciute: il frontend mostra l'intestazione e fa scegliere. */
class MappaturaRichiesta extends RuntimeException
{
    public array $intestazione;
    public array $esempio;
    public array $proposta;

    public function __construct(array $intestazione, array $esempio, array $proposta)
    {
        parent::__construct('Scegli quali colonne contengono data, descrizione e importo');
        $this->intestazione = $intestazione;
        $this->esempio = $esempio;
        $this->proposta = $proposta;
    }
}

class EstrattoTabellare
{
    /** Nomi di colonna riconosciuti (minuscolo, senza punteggiatura). L'ordine conta: il primo che combacia vince. */
    private const NOMI = [
        'data_valuta' => ['data valuta', 'valuta', 'data val'],
        'data_operazione' => ['data operazione', 'data contabile', 'data registrazione', 'data movimento', 'data op', 'data'],
        'dare' => ['dare', 'uscite', 'addebiti', 'addebito', 'importo dare', 'uscita'],
        'avere' => ['avere', 'entrate', 'accrediti', 'accredito', 'importo avere', 'entrata'],
        'importo' => ['importo', 'importo eur', 'importo euro', 'ammontare', 'importo in euro', 'movimento', 'amount'],
        'descrizione' => ['descrizione', 'descrizione operazione', 'causale', 'dettagli', 'dettaglio', 'operazione', 'descrizione estesa', 'causale descrizione'],
    ];

    /** Righe di un CSV (separatore ; , o tab, riconosciuto da solo; BOM e codifica Windows gestiti). */
    public static function daCsv(string $testo): array
    {
        $testo = preg_replace('/^\xEF\xBB\xBF/', '', $testo) ?? $testo;
        if (!mb_check_encoding($testo, 'UTF-8')) $testo = mb_convert_encoding($testo, 'UTF-8', 'Windows-1252');
        $linee = preg_split('/\r\n|\r|\n/', $testo) ?: [];
        $campione = implode("\n", array_slice($linee, 0, 20));
        $sep = ';';
        $max = -1;
        foreach ([';', ',', "\t", '|'] as $s) {
            $n = substr_count($campione, $s);
            if ($n > $max) { $max = $n; $sep = $s; }
        }
        $righe = [];
        foreach ($linee as $l) {
            if (trim($l) === '') continue;
            $righe[] = array_map(fn($c) => trim((string)$c), str_getcsv($l, $sep, '"', '\\'));
        }
        return $righe;
    }

    /** Righe del primo foglio di un .xlsx (celle in ordine di colonna). */
    public static function daXlsx(string $percorso): array
    {
        require_once __DIR__ . '/ListaFattureParser.php';
        $righe = [];
        foreach (ListaFattureParser::leggiXlsx($percorso) as $celle) {
            if (!$celle) continue;
            $out = [];
            foreach ($celle as $col => $v) $out[self::indiceColonna((string)$col)] = trim((string)$v);
            $max = max(array_keys($out));
            $riga = [];
            for ($i = 0; $i <= $max; $i++) $riga[] = $out[$i] ?? '';
            $righe[] = $riga;
        }
        return $righe;
    }

    /**
     * Movimenti dalle righe. $mappa: indici di colonna {data_operazione, data_valuta?, descrizione (int o int[]),
     * importo? | dare?+avere?, intestazione (indice della riga di intestazione)}.
     * @throws MappaturaRichiesta se le colonne non si riconoscono e $mappa manca
     */
    public static function parse(array $righe, ?array $mappa = null, string $banca = ''): array
    {
        [$iInt, $intestazione] = self::intestazione($righe);
        $proposta = self::mappaAutomatica($intestazione);
        $proposta['intestazione'] = $iInt;
        $mappa = $mappa ?? $proposta;
        if (!self::mappaCompleta($mappa)) {
            throw new MappaturaRichiesta($intestazione, array_slice($righe, $iInt + 1, 3), $proposta);
        }
        $movimenti = [];
        $scartate = 0;
        foreach (array_slice($righe, (int)$mappa['intestazione'] + 1) as $r) {
            $data = self::dataCella($r[$mappa['data_operazione']] ?? '');
            $importo = self::importo($r, $mappa);
            if (!$data || $importo === null || abs($importo) < 0.005) {
                if (array_filter($r, fn($c) => $c !== '')) $scartate++;
                continue;
            }
            $cols = is_array($mappa['descrizione']) ? $mappa['descrizione'] : [$mappa['descrizione']];
            $descr = trim(implode(' ', array_filter(array_map(fn($i) => $r[$i] ?? '', $cols))));
            $descr = preg_replace('/\s+/u', ' ', $descr) ?? $descr;
            $valuta = isset($mappa['data_valuta']) && $mappa['data_valuta'] !== null ? self::dataCella($r[$mappa['data_valuta']] ?? '') : null;
            $movimenti[] = [
                'data_operazione' => $data,
                'data_valuta' => $valuta ?? $data,
                'importo' => round($importo, 2),
                'descrizione' => $descr,
                'controparte' => EstrattoContoParser::controparte($descr),
                'segno_incerto' => false,
            ];
        }
        $avvisi = $scartate ? ["$scartate righe senza data o importo validi non importate (totali, saldi, note)."] : [];
        return ['banca' => $banca, 'iban' => '', 'metodo' => 'tabella', 'movimenti' => $movimenti, 'avvisi' => $avvisi,
            'mappa' => $mappa, 'firma' => self::firma($intestazione)];
    }

    /** Chiave dell'intestazione: la stessa banca esporta sempre le stesse colonne. */
    public static function firma(array $intestazione): string
    {
        return substr(sha1(implode('|', array_map([self::class, 'norm'], $intestazione))), 0, 16);
    }

    /** Intestazione delle righe (per ritrovare la mappatura salvata prima di analizzare). */
    public static function firmaDi(array $righe): string
    {
        return self::firma(self::intestazione($righe)[1]);
    }

    /** Numero in formato italiano o inglese, con o senza migliaia e simbolo di valuta. */
    public static function numero(string $s): ?float
    {
        $s = trim(str_replace(["\xc2\xa0", ' ', '€', 'EUR', 'eur'], '', $s));
        if ($s === '') return null;
        $neg = false;
        if (preg_match('/^\((.*)\)$/', $s, $m)) { $s = $m[1]; $neg = true; }
        if (str_ends_with($s, '-')) { $s = substr($s, 0, -1); $neg = true; }
        if (str_starts_with($s, '-')) { $s = substr($s, 1); $neg = !$neg; }
        if (str_starts_with($s, '+')) $s = substr($s, 1);
        if (!preg_match('/^[\d.,]+$/', $s)) return null;
        $v = strrpos($s, ',');
        $p = strrpos($s, '.');
        if ($v !== false && $p !== false) {
            $dec = $v > $p ? ',' : '.';
            $s = str_replace($dec === ',' ? '.' : ',', '', $s);
            $s = str_replace(',', '.', $s);
        } elseif ($v !== false) {
            $s = preg_match('/^\d{1,3}(,\d{3})+$/', $s) ? str_replace(',', '', $s) : str_replace(',', '.', $s);
        } elseif ($p !== false && preg_match('/^\d{1,3}(\.\d{3})+$/', $s)) {
            $s = str_replace('.', '', $s);
        }
        if (!is_numeric($s)) return null;
        return $neg ? -(float)$s : (float)$s;
    }

    /** Data di una cella: gg/mm/aaaa, aaaa-mm-gg (anche con ora) o numero seriale di Excel. */
    public static function dataCella(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^\d{4,5}(\.\d+)?$/', $s) && (float)$s > 20000 && (float)$s < 80000) {
            return gmdate('Y-m-d', (int)(((int)$s - 25569) * 86400));
        }
        if (preg_match('/^(\d{4}-\d{2}-\d{2})[ T]/', $s, $m)) $s = $m[1];
        if (preg_match('/^(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{2,4})\s/', $s, $m)) $s = $m[1];
        $d = EstrattoContoParser::data($s);
        if ($d && preg_match('/^\d{4}-\d{2}-\d{2}$/', $d)) {
            [$y, $mo, $g] = array_map('intval', explode('-', $d));
            return checkdate($mo, $g, $y) ? $d : null;
        }
        return null;
    }

    private static function importo(array $r, array $mappa): ?float
    {
        if (isset($mappa['importo']) && $mappa['importo'] !== null) {
            return self::numero((string)($r[$mappa['importo']] ?? ''));
        }
        $dare = isset($mappa['dare']) && $mappa['dare'] !== null ? self::numero((string)($r[$mappa['dare']] ?? '')) : null;
        $avere = isset($mappa['avere']) && $mappa['avere'] !== null ? self::numero((string)($r[$mappa['avere']] ?? '')) : null;
        if ($dare === null && $avere === null) return null;
        // Dare = uscita (negativa) anche quando la banca la scrive già col meno
        return (float)($avere ?? 0) - abs((float)($dare ?? 0));
    }

    private static function mappaCompleta(array $m): bool
    {
        $ha = fn($k) => isset($m[$k]) && $m[$k] !== null && $m[$k] !== '' && $m[$k] !== [];
        return $ha('data_operazione') && $ha('descrizione') && ($ha('importo') || $ha('dare') || $ha('avere'));
    }

    /**
     * Riga di intestazione: la prima (tra le prime 30) con una colonna data e una di importo/dare/avere.
     * Se i nomi non si riconoscono, la riga subito prima del primo movimento (una data e un numero):
     * così i titoli sopra la tabella non vengono scambiati per l'intestazione.
     */
    private static function intestazione(array $righe): array
    {
        $prime = array_slice($righe, 0, 30, true);
        foreach ($prime as $i => $r) {
            $m = self::mappaAutomatica($r);
            if (isset($m['data_operazione']) && (isset($m['importo']) || isset($m['dare']) || isset($m['avere']))) return [$i, $r];
        }
        $indici = array_keys($prime);
        foreach ($indici as $k => $i) {
            $dopo = $righe[$indici[$k + 1] ?? -1] ?? null;
            if (!$dopo || self::eMovimento($righe[$i])) continue;
            if (self::eMovimento($dopo)) return [$i, $righe[$i]];
        }
        return [0, $righe[0] ?? []];
    }

    /** Riga con almeno una data e un numero: sembra un movimento. */
    private static function eMovimento(array $r): bool
    {
        $data = $num = false;
        foreach ($r as $c) {
            if (!$data && self::dataCella((string)$c)) { $data = true; continue; }
            if (!$num && self::numero((string)$c) !== null) $num = true;
        }
        return $data && $num;
    }

    private static function mappaAutomatica(array $intestazione): array
    {
        $norm = array_map([self::class, 'norm'], $intestazione);
        $mappa = [];
        $usate = [];
        foreach (self::NOMI as $campo => $nomi) {
            foreach ($nomi as $nome) {
                foreach ($norm as $i => $n) {
                    if (isset($usate[$i]) || $n !== $nome) continue;
                    $mappa[$campo] = $i;
                    $usate[$i] = true;
                    continue 3;
                }
            }
        }
        return $mappa;
    }

    private static function norm(string $s): string
    {
        $s = mb_strtolower(trim($s), 'UTF-8');
        $s = preg_replace('/\(.*?\)|[^a-z0-9àèéìòù ]+/u', ' ', $s) ?? $s;
        return trim(preg_replace('/\s+/', ' ', $s) ?? $s);
    }

    private static function indiceColonna(string $col): int
    {
        $col = strtoupper(preg_replace('/\d+/', '', $col) ?? $col);
        $n = 0;
        foreach (str_split($col) as $c) $n = $n * 26 + (ord($c) - 64);
        return max(0, $n - 1);
    }
}
