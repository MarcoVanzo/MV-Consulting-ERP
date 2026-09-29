<?php
/**
 * Termini di pagamento: scadenza di una fattura e piani di fatturazione standard.
 *
 * Forme ammesse (giorni, fine_mese, giorno):
 *   30, 0, null  → «30 gg d.f.»: data fattura + 30 giorni
 *   60, 1, null  → «60 gg d.f.f.m.»: fine del mese della fattura + 60 giorni (a multipli di 30: fine del mese
 *                  di due mesi dopo, come nella prassi bancaria)
 *   60, 1, 10    → «60 gg d.f.f.m. al 10»: come sopra, poi il giorno 10 del mese successivo (Unindustria:
 *                  fattura del 28/06 → 31/08 → pagata il 10/09)
 * Unico posto dove si calcola la scadenza dai termini: commesse, previsioni e avvisi di pagamento passano da qui.
 */
class TerminiPagamento
{
    /** Piani di fatturazione proponibili per un cliente: codice → rate [{descrizione, percentuale, mesi_da_accettazione}]. */
    public const PIANI = [
        '6_12' => [
            ['descrizione' => 'Acconto 50% a 6 mesi', 'percentuale' => 50, 'mesi_da_accettazione' => 6],
            ['descrizione' => 'Saldo 50% a 12 mesi', 'percentuale' => 50, 'mesi_da_accettazione' => 12],
        ],
    ];

    public static function scadenza(string $dataFattura, int $giorni, bool $fineMese = false, ?int $giorno = null): string
    {
        $d = new DateTimeImmutable(substr($dataFattura, 0, 10));
        $giorni = max(0, $giorni);
        if ($fineMese) {
            $d = $d->modify('last day of this month');
            $d = $giorni % 30 === 0
                ? $d->modify('first day of this month')->modify('+' . intdiv($giorni, 30) . ' months')->modify('last day of this month')
                : $d->modify("+$giorni days");
        } else {
            $d = $d->modify("+$giorni days");
        }
        if ($giorno !== null && $giorno >= 1 && $giorno <= 31) {
            // Giorno fisso: dopo il fine mese è quello del mese successivo, altrimenti il primo utile da lì in avanti
            $mese = ($fineMese || (int)$d->format('j') > $giorno) ? $d->modify('first day of next month') : $d->modify('first day of this month');
            $d = self::giornoDelMese($mese, $giorno);
        }
        return $d->format('Y-m-d');
    }

    /** Data + N mesi senza sforare (31/08 + 6 mesi = 28/02, non 03/03). */
    public static function piuMesi(string $data, int $mesi): string
    {
        $d = new DateTimeImmutable(substr($data, 0, 10));
        $primo = $d->modify('first day of this month')->modify("+$mesi months");
        return self::giornoDelMese($primo, (int)$d->format('j'))->format('Y-m-d');
    }

    /** Testo breve per schede e fatture: «60 gg d.f.f.m. al 10». */
    public static function descrivi(int $giorni, bool $fineMese = false, ?int $giorno = null): string
    {
        return $giorni . ' gg d.f.' . ($fineMese ? 'f.m.' : '') . ($giorno ? ' al ' . $giorno : '');
    }

    /** I termini di una riga (incarico, rata, cliente) con i nomi delle colonne. */
    public static function daRiga(array $r, ?int $giorni = null): array
    {
        return [
            $giorni ?? (int)($r['giorni_pagamento'] ?? 30),
            !empty($r['fine_mese']),
            !empty($r['giorno_pagamento']) ? (int)$r['giorno_pagamento'] : null,
        ];
    }

    private static function giornoDelMese(DateTimeImmutable $primoDelMese, int $giorno): DateTimeImmutable
    {
        $ultimo = (int)$primoDelMese->format('t');
        return $primoDelMese->setDate((int)$primoDelMese->format('Y'), (int)$primoDelMese->format('n'), min($giorno, $ultimo));
    }
}
