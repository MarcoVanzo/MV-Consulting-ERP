<?php
/**
 * RiconciliazioneMatch — funzioni pure del motore di riconciliazione (niente database):
 * riferimenti a fatture nelle causali, subset-sum sugli importi, punteggio delle proposte.
 * Provate da tests/riconciliazione_cli.php.
 */
declare(strict_types=1);

class RiconciliazioneMatch
{
    // Data in causale: 28/06/2026, 12.12.2025, 30-11-25, 251125, 30012026
    private const RE_DATA = '\d{1,2}[\/.\-]\d{1,2}[\/.\-](?:\d{4}|\d{2})|\d{8}|\d{6}';
    private const RE_PAROLA = '(?:FATTURE|FATTURA|FATT|FTT|FAT|FT)';

    /**
     * Riferimenti a fatture citati in una causale bancaria.
     * Formati visti: "FATT. 46/001 DEL 28/06/2026", "SALDO FATTURA NR. 67 DEL 12.12.2025",
     * "FATT. N. 62-2025 DEL 251125", "FATT. N. 7-001 30012026", "FATT. 64/2025 DEL 30/11/2025",
     * "FATTURE 12, 13 E 14", "... 19-050326" (numero-ggmmaa), "46/001" senza parola chiave.
     * @return array<int, array{numero:int, registro:?string, anno:?int, data:?string}>
     */
    public static function estraiRiferimenti(string $testo): array
    {
        $t = mb_strtoupper($testo, 'UTF-8');
        $refs = [];
        $num = '(\d{1,5})(?:\s*[\/\-]\s*(\d{2,4})(?!\d))?';
        $re = '/\b' . self::RE_PAROLA . '\.?\s*(?:(?:N|NR|NUM|NUMERO)\.?\s*)?[°º:]?\s*' . $num
            . '((?:\s*(?:,|;|\bE\b|\+)\s*\d{1,5}(?:\s*[\/\-]\s*\d{2,4}(?!\d))?)*)'
            . '(?:\s*(?:DEL|DD|DT)?\.?\s*(' . self::RE_DATA . ')(?!\d))?/u';
        if (preg_match_all($re, $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $data = isset($m[4]) && $m[4] !== '' ? self::dataCausale($m[4]) : null;
                $refs[] = self::ref((int)$m[1], $m[2] ?? '', $data);
                // Elenco "FATTURE 12, 13 E 14": stessa data per tutti
                if (!empty($m[3]) && preg_match_all('/(\d{1,5})(?:\s*[\/\-]\s*(\d{2,4}))?/', $m[3], $ll, PREG_SET_ORDER)) {
                    foreach ($ll as $l) $refs[] = self::ref((int)$l[1], $l[2] ?? '', $data);
                }
            }
        }
        // numero-ggmmaa (es. "19-050326" = fattura 19 del 05/03/26)
        if (preg_match_all('/(?<![\d.\/\-])(\d{1,4})-(\d{6})(?![\d\/\-])/', $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) {
                $data = self::dataCausale($m[2]);
                if ($data) $refs[] = self::ref((int)$m[1], '', $data);
            }
        }
        // "46/001" senza parola chiave: il sezionale a tre cifre basta a riconoscerlo
        if (preg_match_all('/(?<![\d.\/\-])(\d{1,5})\s*[\/\-]\s*(00\d)(?![\d\/\-])/', $t, $mm, PREG_SET_ORDER)) {
            foreach ($mm as $m) $refs[] = self::ref((int)$m[1], $m[2], null);
        }

        // Doppioni (stesso numero/registro/anno): si tiene il più informativo
        $out = [];
        foreach ($refs as $r) {
            if ($r['numero'] <= 0) continue;
            $k = $r['numero'] . '|' . ($r['registro'] ?? '') . '|' . ($r['anno'] ?? '');
            if (!isset($out[$k]) || (!$out[$k]['data'] && $r['data'])) $out[$k] = $r;
        }
        // Ridondante se c'è lo stesso numero con più informazioni compatibili (es. "46/001" e "FATT. 46/001 DEL ...")
        $info = fn($r) => ($r['registro'] !== null ? 1 : 0) + ($r['anno'] !== null ? 1 : 0);
        foreach ($out as $k => $r) {
            foreach ($out as $k2 => $r2) {
                if ($k2 === $k || $r2['numero'] !== $r['numero'] || $info($r2) <= $info($r)) continue;
                if (($r['registro'] === null || $r['registro'] === $r2['registro']) && ($r['anno'] === null || $r['anno'] === $r2['anno'])) {
                    unset($out[$k]);
                    break;
                }
            }
        }
        return array_values($out);
    }

    private static function ref(int $numero, string $suffisso, ?string $data): array
    {
        $registro = null;
        $anno = $data ? (int)substr($data, 0, 4) : null;
        if (strlen($suffisso) === 3) {
            $registro = $suffisso;             // 001 = sezionale
        } elseif (strlen($suffisso) === 4) {
            $anno = (int)$suffisso;            // 62-2025
        } elseif (strlen($suffisso) === 2) {
            $anno = 2000 + (int)$suffisso;     // 46/26
        }
        return ['numero' => $numero, 'registro' => $registro, 'anno' => $anno, 'data' => $data];
    }

    /** Data scritta nella causale → AAAA-MM-GG (null se non valida). */
    public static function dataCausale(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/', $s, $m)) {
            [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^(\d{2})(\d{2})(\d{4})$/', $s, $m) || preg_match('/^(\d{2})(\d{2})(\d{2})$/', $s, $m)) {
            [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } else {
            return null;
        }
        if ($y < 100) $y += 2000;
        if ($y < 2000 || $y > 2099 || !checkdate($mo, $d, $y)) return null;
        return sprintf('%04d-%02d-%02d', $y, $mo, $d);
    }

    /**
     * Numero documento dell'ERP → [numero base, registro|null, anno dal numero|null].
     * "46/001" → [46,'001',null], "64/2025" → [64,null,2025], "12" → [12,null,null], "15AV" → [15,'AV',null]
     */
    public static function scomponiNumero(string $numero): array
    {
        $n = strtoupper(trim($numero));
        if (preg_match('/^0*(\d+)\s*AV$/', $n, $m)) return [(int)$m[1], 'AV', null];
        if (preg_match('/^0*(\d+)\s*[\/\-]\s*(\d{2,4})$/', $n, $m)) {
            if (strlen($m[2]) === 3) return [(int)$m[1], $m[2], null];
            return [(int)$m[1], null, strlen($m[2]) === 4 ? (int)$m[2] : 2000 + (int)$m[2]];
        }
        if (preg_match('/(\d+)/', $n, $m)) return [(int)$m[1], null, null];
        return [0, null, null];
    }

    /** Il riferimento letto in causale indica questo documento (numero, anno di emissione)? */
    public static function corrisponde(array $ref, string $numeroDoc, ?string $dataEmissione): bool
    {
        [$base, $registro, $annoNum] = self::scomponiNumero($numeroDoc);
        if ($base !== $ref['numero']) return false;
        if ($ref['registro'] !== null && $registro !== null && $registro !== $ref['registro']) return false;
        $anno = $dataEmissione ? (int)substr($dataEmissione, 0, 4) : $annoNum;
        if ($ref['anno'] !== null && $anno !== null && $anno !== $ref['anno']) return false;
        return true;
    }

    /** Fatture del registro agenzia viaggi (AV / 002): fuori dalla riconciliazione. */
    public static function escluso(string $numeroDoc): bool
    {
        $r = self::scomponiNumero($numeroDoc)[1];
        return $r === 'AV' || $r === '002';
    }

    /**
     * Sottoinsiemi che sommano esattamente a $target (centesimi, tolleranza 1 cent).
     * $voci: [['id' => mixed, 'opzioni' => [int centesimi, ...]]] — più opzioni per la stessa voce
     * (es. con/senza ritenuta): se ne usa al massimo una.
     * Ricerca limitata: al massimo $maxSoluzioni e $maxNodi nodi (troncato = true se si è fermata prima).
     * @return array{soluzioni: array<int, array<mixed,int>>, troncato: bool}
     */
    public static function subsetSum(array $voci, int $target, int $maxSoluzioni = 2, int $maxNodi = 200000): array
    {
        $voci = array_values(array_filter(array_map(function ($v) {
            $v['opzioni'] = array_values(array_filter(array_map('intval', $v['opzioni']), fn($c) => $c > 0));
            return $v;
        }, $voci), fn($v) => $v['opzioni']));
        usort($voci, fn($a, $b) => max($b['opzioni']) <=> max($a['opzioni']));
        $n = count($voci);
        $suffisso = array_fill(0, $n + 1, 0);
        for ($i = $n - 1; $i >= 0; $i--) $suffisso[$i] = $suffisso[$i + 1] + max($voci[$i]['opzioni']);

        $soluzioni = [];
        $nodi = 0;
        $troncato = false;
        $dfs = function (int $i, int $resto, array $scelte) use (&$dfs, &$soluzioni, &$nodi, &$troncato, $voci, $n, $suffisso, $maxSoluzioni, $maxNodi) {
            if (count($soluzioni) >= $maxSoluzioni) return;
            if (++$nodi > $maxNodi) { $troncato = true; return; }
            if (abs($resto) <= 1 && $scelte) { $soluzioni[] = $scelte; return; }
            if ($i >= $n || $resto < 0 || $suffisso[$i] < $resto - 1) return;
            foreach ($voci[$i]['opzioni'] as $c) {
                if ($c <= $resto + 1) $dfs($i + 1, $resto - $c, $scelte + [$voci[$i]['id'] => $c]);
            }
            $dfs($i + 1, $resto, $scelte);
        };
        $dfs(0, $target, []);
        return ['soluzioni' => $soluzioni, 'troncato' => $troncato];
    }

    /**
     * Punteggio di una singola fattura come proposta per un movimento.
     * @param array $doc forma comune (residuo, totale, ritenuta, anagrafica_id, data_scadenza)
     * @return array{punteggio:int, motivi:string[], opzione:?int} opzione = importo esatto in centesimi, se c'è
     */
    public static function punteggio(array $doc, int $cent, bool $numeroInCausale, ?int $anagId, string $dataMovimento): array
    {
        $p = 0;
        $motivi = [];
        $opzione = null;
        $residuo = (int)round($doc['residuo'] * 100);
        $lordo = (int)round(($doc['residuo'] + ($doc['ritenuta'] ?? 0)) * 100);
        if (abs($residuo - $cent) <= 1) { $p += 50; $motivi[] = 'Importo esatto'; $opzione = $residuo; }
        elseif (($doc['ritenuta'] ?? 0) > 0 && abs($lordo - $cent) <= 1) { $p += 45; $motivi[] = 'Importo esatto (senza ritenuta)'; $opzione = $lordo; }
        elseif ($cent < $residuo) { $p += 5; $motivi[] = 'Possibile acconto'; }
        if ($anagId && (int)$doc['anagrafica_id'] === $anagId) { $p += 25; $motivi[] = 'Stesso intestatario'; }
        if ($numeroInCausale) { $p += 30; $motivi[] = 'Numero in causale'; }
        if (!empty($doc['data_scadenza'])) {
            $g = self::giorni($dataMovimento, $doc['data_scadenza']);
            $bonus = max(0, 15 - intdiv($g, 4));
            if ($bonus > 0) { $p += $bonus; if ($g <= 10) $motivi[] = 'Vicino alla scadenza'; }
        }
        // Senza importo, intestatario o numero la vicinanza alla scadenza da sola non basta
        if ($opzione === null && !$numeroInCausale && !($anagId && (int)$doc['anagrafica_id'] === $anagId)) $p = 0;
        return ['punteggio' => $p, 'motivi' => $motivi, 'opzione' => $opzione];
    }

    /** Distanza in giorni tra due date AAAA-MM-GG. */
    public static function giorni(string $a, string $b): int
    {
        $ta = strtotime(substr($a, 0, 10) . ' 00:00:00 UTC');
        $tb = strtotime(substr($b, 0, 10) . ' 00:00:00 UTC');
        if ($ta === false || $tb === false) return PHP_INT_MAX;
        return (int)abs(round(($ta - $tb) / 86400));
    }
}
