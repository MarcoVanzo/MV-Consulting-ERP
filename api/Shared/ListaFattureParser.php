<?php
/**
 * ListaFattureParser — elenco di fatture in Excel (.xlsx): la "Lista Fatture" di Sistemi (emesse) e gli
 * elenchi del portale fatture della banca, emesse o ricevute.
 *
 * Colonne attese (riconosciute dall'intestazione, in qualunque ordine): Tipo Documento, Numero, Data,
 * Cliente o Fornitore, Imponibile, Iva, Totale. Registro, F/N e Stato sono facoltative. La colonna
 * Fornitore dice che l'elenco è di fatture ricevute (verso "passiva"), Cliente che sono emesse ("attiva").
 * Il "Residuo" si ignora: Sistemi non registra gli incassi (residuo = totale), i pagamenti li porta la
 * riconciliazione con la banca. Le note di credito escono sempre con importi negativi.
 * Il .xlsx è uno zip di XML: si legge con ZipArchive, senza librerie. Il vecchio .xls binario non è supportato.
 * Solo funzioni pure: niente database, così si prova da CLI (tests/lista_fatture_cli.php).
 */
declare(strict_types=1);

class ListaFattureParser
{
    private const COLONNE = [
        'tipo' => ['tipo documento', 'tipo doc', 'tipo'],
        'fn' => ['f/n'],
        'registro' => ['registro', 'sezionale'],
        'numero' => ['numero', 'numero documento', 'n. documento', 'nr'],
        'data' => ['data', 'data documento', 'data emissione'],
        'cliente' => ['cliente', 'ragione sociale', 'intestatario', 'destinatario', 'cessionario', 'committente'],
        'fornitore' => ['fornitore', 'cedente', 'cedente/prestatore', 'emittente', 'mittente'],
        'stato' => ['stato fte', 'stato sdi', 'stato'],
        'imponibile' => ['imponibile'],
        'iva' => ['iva', 'imposta'],
        'totale' => ['totale', 'totale documento'],
    ];

    /**
     * Righe del primo foglio come array di celle ("A" => valore).
     * @throws RuntimeException se il file non è un .xlsx leggibile
     */
    public static function leggiXlsx(string $percorso): array
    {
        if (!class_exists('ZipArchive')) throw new RuntimeException('Il server non ha l\'estensione zip: impossibile leggere il file Excel');
        $zip = new ZipArchive();
        if ($zip->open($percorso) !== true) throw new RuntimeException('File non leggibile: serve un Excel .xlsx (il vecchio .xls va risalvato come .xlsx)');
        // Un foglio decompresso oltre 30 MB non è un estratto né una lista fatture (e manderebbe il PHP fuori memoria)
        for ($i = 0; $i < $zip->numFiles; $i++) {
            $st = $zip->statIndex($i);
            if ($st && $st['size'] > 30 * 1024 * 1024) {
                $zip->close();
                throw new RuntimeException('File Excel troppo grande una volta aperto');
            }
        }
        try {
            $stringhe = [];
            $ss = $zip->getFromName('xl/sharedStrings.xml');
            if ($ss !== false) {
                $x = self::xml($ss);
                foreach ($x->si as $si) {
                    // Testo semplice (<t>) o formattato a pezzi (<r><t>)
                    $t = isset($si->t) ? (string)$si->t : '';
                    foreach ($si->r as $r) $t .= (string)$r->t;
                    $stringhe[] = $t;
                }
            }
            $foglio = $zip->getFromName(self::primoFoglio($zip));
            if ($foglio === false) throw new RuntimeException('Nessun foglio nel file Excel');
            $x = self::xml($foglio);
            $righe = [];
            foreach ($x->sheetData->row as $row) {
                $celle = [];
                foreach ($row->c as $c) {
                    $col = preg_replace('/\d+/', '', (string)$c['r']);
                    $tipo = (string)$c['t'];
                    if ($tipo === 's') $v = $stringhe[(int)$c->v] ?? '';
                    elseif ($tipo === 'inlineStr') $v = (string)$c->is->t;
                    else $v = (string)$c->v;
                    $celle[$col] = trim($v);
                }
                $righe[] = $celle;
            }
            return $righe;
        } finally {
            $zip->close();
        }
    }

    /**
     * Fatture dalle righe del foglio: la prima riga con Numero, Data e Totale fa da intestazione.
     * @return array{fatture:array, avvisi:string[], verso:?string} fattura: {nota_credito, registro, numero, data,
     *         cliente (la controparte: cliente o fornitore), imponibile, iva, totale}; verso: passiva | attiva | null
     */
    public static function fatture(array $righe): array
    {
        $mappa = null;
        $fatture = [];
        $avvisi = [];
        foreach ($righe as $i => $celle) {
            if ($mappa === null) {
                $mappa = self::intestazione($celle);
                continue;
            }
            $v = fn(string $k) => isset($mappa[$k]) ? trim((string)($celle[$mappa[$k]] ?? '')) : '';
            if ($v('numero') === '' && $v('totale') === '') continue;
            $data = self::data($v('data'));
            $totale = self::numero($v('totale'));
            if ($v('numero') === '' || !$data || $totale === null) {
                $avvisi[] = 'Riga ' . ($i + 1) . ': numero, data o totale mancanti, saltata.';
                continue;
            }
            // Fatture scartate o rifiutate dallo SdI non esistono per il fisco
            if (preg_match('/scartat|rifiutat/i', $v('stato'))) {
                $avvisi[] = 'Riga ' . ($i + 1) . ': fattura ' . $v('numero') . ' ' . mb_strtolower($v('stato'), 'UTF-8') . ', saltata.';
                continue;
            }
            $tipo = mb_strtolower($v('tipo'), 'UTF-8');
            $nota = str_contains($tipo, 'nota') || strtoupper($v('fn')) === 'N' || $totale < 0;
            $imponibile = self::numero($v('imponibile'));
            $iva = self::numero($v('iva'));
            if ($imponibile === null) $imponibile = $iva !== null ? round($totale - $iva, 2) : $totale;
            if ($iva === null) $iva = round($totale - $imponibile, 2);
            // Il portale scrive le note di credito in positivo, Sistemi in negativo: qui sempre negative
            if ($nota && $totale > 0) [$totale, $imponibile, $iva] = [-$totale, -$imponibile, -$iva];
            $fatture[] = [
                'nota_credito' => $nota,
                'registro' => $v('registro'),
                'numero' => $v('numero'),
                'data' => $data,
                'cliente' => html_entity_decode($v(isset($mappa['fornitore']) ? 'fornitore' : 'cliente'), ENT_QUOTES | ENT_XML1, 'UTF-8'),
                'imponibile' => $imponibile,
                'iva' => $iva,
                'totale' => $totale,
            ];
        }
        if ($mappa === null) throw new RuntimeException('Il file è vuoto');
        $verso = isset($mappa['fornitore']) ? 'passiva' : (isset($mappa['cliente']) ? 'attiva' : null);
        return ['fatture' => $fatture, 'avvisi' => $avvisi, 'verso' => $verso];
    }

    /** Colonna di ogni campo; eccezione se mancano Numero, Data o Totale. */
    private static function intestazione(array $celle): array
    {
        $mappa = [];
        foreach ($celle as $col => $testo) {
            $t = mb_strtolower(trim((string)$testo), 'UTF-8');
            foreach (self::COLONNE as $campo => $nomi) {
                if (!isset($mappa[$campo]) && in_array($t, $nomi, true)) { $mappa[$campo] = $col; break; }
            }
        }
        $mancano = array_diff(['numero', 'data', 'totale'], array_keys($mappa));
        if ($mancano) throw new RuntimeException('Colonne mancanti nella prima riga: ' . implode(', ', $mancano) . ' (serve un elenco fatture con Numero, Data e Totale)');
        return $mappa;
    }

    /** 2026-09-08, 08/09/2026 o numero seriale di Excel → AAAA-MM-GG. */
    public static function data(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{4})-(\d{2})-(\d{2})/', $s, $m)) return checkdate((int)$m[2], (int)$m[3], (int)$m[1]) ? "$m[1]-$m[2]-$m[3]" : null;
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{4})$/', $s, $m)) {
            return checkdate((int)$m[2], (int)$m[1], (int)$m[3]) ? sprintf('%04d-%02d-%02d', $m[3], $m[2], $m[1]) : null;
        }
        if (preg_match('/^\d{5}(\.\d+)?$/', $s)) return gmdate('Y-m-d', ((int)$s - 25569) * 86400);
        return null;
    }

    /** "51467.90000000000146" → 51467.9 ; "1.234,56" → 1234.56 */
    public static function numero(string $s): ?float
    {
        $s = str_replace([' ', '€'], '', trim($s));
        if ($s === '') return null;
        if (preg_match('/^-?\d{1,3}(\.\d{3})*,\d+$|^-?\d+,\d+$/', $s)) $s = str_replace(['.', ','], ['', '.'], $s);
        return is_numeric($s) ? round((float)$s, 2) : null;
    }

    private static function primoFoglio(ZipArchive $zip): string
    {
        // Il primo foglio del workbook, risolto tramite le relazioni; di solito è sheet1.xml
        $wb = $zip->getFromName('xl/workbook.xml');
        $rels = $zip->getFromName('xl/_rels/workbook.xml.rels');
        if ($wb !== false && $rels !== false
            && preg_match('/<sheet\b[^>]*\br:id="([^"]+)"/', $wb, $m)
            && preg_match('/<Relationship\b[^>]*\bId="' . preg_quote($m[1], '/') . '"[^>]*\bTarget="([^"]+)"/', $rels, $t)) {
            $target = ltrim($t[1], '/');
            return str_starts_with($target, 'xl/') ? $target : 'xl/' . $target;
        }
        return 'xl/worksheets/sheet1.xml';
    }

    private static function xml(string $s): SimpleXMLElement
    {
        if (preg_match('/<!DOCTYPE/i', $s)) throw new RuntimeException('File Excel non valido');
        $prev = libxml_use_internal_errors(true);
        $x = simplexml_load_string($s, SimpleXMLElement::class, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if ($x === false) throw new RuntimeException('File Excel non leggibile');
        return $x;
    }
}
