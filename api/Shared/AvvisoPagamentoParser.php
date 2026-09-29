<?php
/**
 * Avviso di pagamento del cliente («Pagamento Fornitore» di Unindustria): testo del PDF → bonifici e fatture.
 *
 * Una riga è «numero  data documento  valuta  [Fissa]  importo»; il numero può avere il suffisso di Sistemi
 * («29/001»). Ogni «TOTALE PAGAMENTO» chiude un bonifico: lo stesso avviso può contenerne più d'uno, ognuno
 * accreditato a parte (e il «TOTALE GENERALE» li somma). Funzione pura: nessun accesso al database.
 */
class AvvisoPagamentoParser
{
    private const DATA = '\d{1,2}\/\d{1,2}\/\d{2,4}';
    private const IMPORTO = '\d{1,3}(?:\.\d{3})*,\d{2}';

    /**
     * @param string[] $pagine testo delle pagine
     * @return array{data_avviso: ?string, bonifici: array, totale: float}
     *   bonifici = [{totale, valuta, righe: [{numero, numero_base, data_documento, valuta, importo}]}]
     */
    public static function leggi(array $pagine): array
    {
        $t = preg_replace('/\s+/u', ' ', implode(' ', $pagine));
        $dataAvviso = preg_match('/\b[A-Z][A-Za-zÀ-ú\']+\s*,\s*(' . self::DATA . ')/u', $t, $m) ? self::data($m[1]) : null;

        // Righe e totali nell'ordine in cui compaiono: un totale chiude il bonifico in corso
        $eventi = [];
        preg_match_all('/(?<![\d\/])(\d{1,6}(?:\/\d{1,4})?)\s+(' . self::DATA . ')\s+(' . self::DATA . ')\s+(?:[A-Za-z]+\s+)?(' . self::IMPORTO . ')(?![\d,])/u',
            $t, $righe, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($righe as $r) {
            $eventi[] = [$r[0][1], 'riga', [
                'numero' => $r[1][0],
                'numero_base' => explode('/', $r[1][0])[0],
                'data_documento' => self::data($r[2][0]),
                'valuta' => self::data($r[3][0]),
                'importo' => self::importo($r[4][0]),
            ]];
        }
        preg_match_all('/TOTALE\s+(PAGAMENTO|GENERALE)\s*\**\s*EURO\s+(' . self::IMPORTO . ')/iu', $t, $tot, PREG_SET_ORDER | PREG_OFFSET_CAPTURE);
        foreach ($tot as $x) $eventi[] = [$x[0][1], strtolower($x[1][0]), self::importo($x[2][0])];
        usort($eventi, fn($a, $b) => $a[0] <=> $b[0]);

        $bonifici = [];
        $inCorso = [];
        $generale = null;
        $chiudi = function (?float $totale) use (&$bonifici, &$inCorso) {
            if (!$inCorso) return;
            $somma = round(array_sum(array_column($inCorso, 'importo')), 2);
            $bonifici[] = ['totale' => $totale ?? $somma, 'somma_righe' => $somma, 'valuta' => max(array_column($inCorso, 'valuta')), 'righe' => $inCorso];
            $inCorso = [];
        };
        foreach ($eventi as [, $tipo, $v]) {
            if ($tipo === 'riga') $inCorso[] = $v;
            elseif ($tipo === 'pagamento') $chiudi($v);
            else $generale = $v;
        }
        $chiudi(null);

        return [
            'data_avviso' => $dataAvviso,
            'bonifici' => $bonifici,
            'totale' => $generale ?? round(array_sum(array_column($bonifici, 'totale')), 2),
        ];
    }

    /** «9/09/26» → 2026-09-09 */
    private static function data(string $s): ?string
    {
        [$g, $m, $a] = array_map('intval', explode('/', $s));
        if ($a < 100) $a += 2000;
        return checkdate($m, $g, $a) ? sprintf('%04d-%02d-%02d', $a, $m, $g) : null;
    }

    private static function importo(string $s): float
    {
        return (float)str_replace(['.', ','], ['', '.'], $s);
    }
}
