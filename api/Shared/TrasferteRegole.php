<?php
/**
 * TrasferteRegole — regole pure delle trasferte (niente DB, niente rete): giorni coperti da
 * un evento di calendario, fascia oraria, eventi da ignorare, ordine delle tappe, ripartizione
 * dei km e indennità giornaliera. Provate in tests/trasferte_cli.php.
 */
declare(strict_types=1);

class TrasferteRegole
{
    public const FUSO = 'Europe/Rome';
    public const FASCE = ['intera', 'mattino', 'pomeriggio'];

    /**
     * Indennità di trasferta forfettaria in Italia (art. 51 c. 5 TUIR): 46,48 € al giorno,
     * ridotta di un terzo se è rimborsato il vitto o l'alloggio, di due terzi se lo sono entrambi.
     */
    public const INDENNITA_PIENA = 46.48;
    public const INDENNITA_UN_RIMBORSO = 30.99;
    public const INDENNITA_DUE_RIMBORSI = 15.49;

    /** Titoli di calendario che non sono trasferte (confronto per sottostringa, minuscolo) */
    private const DA_SALTARE = ['non disponibile', 'evento senza titolo', 'annullato', 'cancelled'];

    public static function daSaltare(string $titolo): bool
    {
        $t = mb_strtolower(trim($titolo), 'UTF-8');
        if ($t === '') return true;
        foreach (self::DA_SALTARE as $p) {
            if (mb_strpos($t, $p) !== false) return true;
        }
        return false;
    }

    /**
     * Giorni (Y-m-d, ora di Roma) coperti da un evento Google: start/end come arrivano dall'API
     * ({date} per gli eventi di giornata intera, con fine esclusiva; {dateTime} per gli altri).
     * Si avanza per giorni di calendario, non per 86400 secondi: col cambio dell'ora il giorno
     * dura 23 o 25 ore e il conteggio a secondi saltava l'ultimo giorno.
     */
    public static function giorniEvento(array $start, array $end): array
    {
        $tz = new DateTimeZone(self::FUSO);
        $inizio = self::giornoDi($start, $tz);
        if ($inizio === null) return [];

        $fine = self::giornoDi($end, $tz) ?? $inizio;
        if (isset($end['date'])) {
            $fine = $fine->modify('-1 day'); // fine esclusiva negli eventi di giornata intera
        } elseif (isset($end['dateTime']) && $fine > $inizio
            && (new DateTimeImmutable($end['dateTime']))->setTimezone($tz)->format('H:i') === '00:00') {
            $fine = $fine->modify('-1 day'); // un evento che finisce a mezzanotte non occupa il giorno dopo
        }
        if ($fine < $inizio) $fine = $inizio;

        $giorni = [];
        for ($g = $inizio; $g <= $fine && count($giorni) < 62; $g = $g->modify('+1 day')) {
            $giorni[] = $g->format('Y-m-d');
        }
        return $giorni;
    }

    private static function giornoDi(array $quando, DateTimeZone $tz): ?DateTimeImmutable
    {
        if (!empty($quando['date'])) {
            return DateTimeImmutable::createFromFormat('!Y-m-d', substr($quando['date'], 0, 10), $tz) ?: null;
        }
        if (!empty($quando['dateTime'])) {
            $d = (new DateTimeImmutable($quando['dateTime']))->setTimezone($tz);
            return $d->setTime(0, 0);
        }
        return null;
    }

    /** Fascia oraria di un evento, con le ore lette nel fuso di Roma (non in quello del server) */
    public static function fasciaEvento(array $start, array $end): string
    {
        if (empty($start['dateTime']) || empty($end['dateTime'])) return 'intera';
        $tz = new DateTimeZone(self::FUSO);
        $inizio = (new DateTimeImmutable($start['dateTime']))->setTimezone($tz);
        $fine = (new DateTimeImmutable($end['dateTime']))->setTimezone($tz);
        if ($inizio->format('Y-m-d') !== $fine->format('Y-m-d')) return 'intera';

        $hInizio = (int)$inizio->format('G');
        $hFine = (int)$fine->format('G');
        if ($hInizio < 13 && $hFine <= 14) return 'mattino';
        if ($hInizio >= 13) return 'pomeriggio';
        return 'intera';
    }

    /**
     * Tappe della giornata nell'ordine del percorso: mattino, giornata intera, pomeriggio;
     * a parità di fascia vale l'ordine di inserimento. Ogni tappa è ['id', 'fascia_oraria', ...].
     */
    public static function ordinaTappe(array $tappe): array
    {
        $peso = ['mattino' => 0, 'intera' => 1, 'pomeriggio' => 2];
        usort($tappe, function ($a, $b) use ($peso) {
            $pa = $peso[$a['fascia_oraria'] ?? 'intera'] ?? 1;
            $pb = $peso[$b['fascia_oraria'] ?? 'intera'] ?? 1;
            return $pa <=> $pb ?: (int)$a['id'] <=> (int)$b['id'];
        });
        return $tappe;
    }

    /**
     * Km per trasferta: il totale si divide fra le $n tappe da aggiornare. Si parte da fuori se la
     * notte prima si è dormito fuori; si rientra se il percorso di oggi torna alla base.
     * Restituisce [km_andata, km_ritorno] per ciascuna tappa. Si conta in decimi di km e i decimi
     * avanzati vanno alle prime tappe: la somma delle righe è esattamente il totale del percorso
     * (arrotondando ogni quota, tre tappe su 40 km davano 40,2 km).
     */
    public static function ripartisciKm(float $totKm, int $n, bool $partenzaDaFuori, bool $rientro): array
    {
        if ($n <= 0) return [];
        $decimi = (int)round(max(0.0, $totKm) * 10);
        $base = intdiv($decimi, $n);
        $resto = $decimi % $n;
        $out = [];
        for ($i = 0; $i < $n; $i++) {
            $q = $base + ($i < $resto ? 1 : 0);
            if ($partenzaDaFuori && $rientro) $out[] = [0.0, $q / 10.0];
            elseif (!$rientro) $out[] = [$q / 10.0, 0.0];
            else $out[] = [intdiv($q, 2) / 10.0, ($q - intdiv($q, 2)) / 10.0];
        }
        return $out;
    }

    /** Indennità di una giornata: spetta solo se nella giornata c'è almeno un cliente */
    public static function indennitaGiornata(bool $conCliente, float $vitto, float $alloggio): float
    {
        if (!$conCliente) return 0.0;
        $rimborsi = ($vitto > 0 ? 1 : 0) + ($alloggio > 0 ? 1 : 0);
        return [self::INDENNITA_PIENA, self::INDENNITA_UN_RIMBORSO, self::INDENNITA_DUE_RIMBORSI][$rimborsi];
    }

    /**
     * Riepilogo per giornata delle righe di list(): cliente presente, spese, indennità.
     * È l'unico punto in cui si calcola l'indennità (tabella, KPI e PDF la leggono da qui).
     * Nel comune della sede l'indennità non è esente (art. 51 c. 5 TUIR): spetta solo se almeno un
     * cliente della giornata è fuori da $comuneSede (città di sottocliente o cliente; senza città
     * si considera fuori). $comuneSede vuoto: nessuna esclusione.
     */
    public static function giornate(array $righe, string $comuneSede = ''): array
    {
        $sede = self::normaComune($comuneSede);
        $g = [];
        foreach ($righe as $r) {
            $d = $r['data_trasferta'];
            $g[$d] ??= ['con_cliente' => false, 'fuori_comune' => false, 'vitto' => 0.0, 'alloggio' => 0.0];
            if (!empty($r['cliente_id']) || !empty($r['sottocliente_id'])) {
                $g[$d]['con_cliente'] = true;
                $citta = self::normaComune((string)(($r['sottocliente_citta'] ?? '') ?: ($r['cliente_citta'] ?? '')));
                if ($sede === '' || $citta === '' || $citta !== $sede) $g[$d]['fuori_comune'] = true;
            }
            $g[$d]['vitto'] += (float)($r['vitto'] ?? 0);
            $g[$d]['alloggio'] += (float)($r['alloggio'] ?? 0);
        }
        foreach ($g as $d => $v) {
            $g[$d]['indennita'] = self::indennitaGiornata($v['con_cliente'] && $v['fuori_comune'], $v['vitto'], $v['alloggio']);
        }
        return $g;
    }

    /** «Zero Branco (TV)» → «zero branco» */
    private static function normaComune(string $c): string
    {
        $c = preg_replace('/\(.*?\)/u', '', mb_strtolower($c, 'UTF-8'));
        return trim((string)preg_replace('/\s+/u', ' ', (string)$c));
    }

    /** Controllo dei campi di una trasferta: restituisce il messaggio d'errore o null */
    public static function errore(array $data, bool $isUpdate): ?string
    {
        if (!$isUpdate || array_key_exists('data_trasferta', $data)) {
            $d = DateTimeImmutable::createFromFormat('!Y-m-d', (string)($data['data_trasferta'] ?? ''));
            if (!$d || $d->format('Y-m-d') !== ($data['data_trasferta'] ?? '')) return 'Data non valida';
        }
        if (isset($data['fascia_oraria']) && !in_array($data['fascia_oraria'], self::FASCE, true)) {
            return 'Fascia oraria non valida';
        }
        foreach (['km_andata', 'km_ritorno', 'vitto', 'alloggio'] as $k) {
            if (!isset($data[$k]) || $data[$k] === '') continue;
            if (!is_numeric($data[$k]) || (float)$data[$k] < 0 || (float)$data[$k] > 100000) {
                return "Valore non valido per $k";
            }
        }
        return null;
    }
}
