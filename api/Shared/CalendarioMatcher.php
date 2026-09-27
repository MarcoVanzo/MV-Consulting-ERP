<?php
/**
 * CalendarioMatcher — riconosce cliente e sottocliente dal titolo, dal luogo e dalla
 * descrizione di un evento di Google Calendar. Spostato così com'era da
 * GoogleAuthController::sync(): alias espliciti, poi acronimi, parole intere, errori di
 * battitura (Levenshtein) e corrispondenze parziali, prima sui sottoclienti poi sui clienti.
 */
declare(strict_types=1);

class CalendarioMatcher
{
    private const FORBIDDEN = ['spa', 'srl', 'snc', 'sas', 'per', 'con', 'del', 'dal', 'all', 'una',
        'ita', 'titolo', 'non', 'disponibile', 'the', 'group', 'gruppo',
        'formazione', 'originario', 'descrizione', 'dpo', 'mkt', 'commerciale',
        'societa', 'società', 'responsabilita', 'limitata', 'consortile', 'il',
        'lo', 'la', 'di', 'de', 'da', 'in', 'su', 'tra', 'fra', 'ed', 'zaggia'];
    private const STOP_WORDS = ['di', 'de', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra', 'il', 'lo',
        'la', 'i', 'gli', 'le', 'un', 'uno', 'una', 'ed', 'e', 'o', 'a', '&', 'and'];

    private array $clienti;
    private array $sottoclienti;
    private array $aliasMap = [];

    /** $clienti: righe {id, ragione_sociale}; $sottoclienti: righe {id, nome, cliente_id} */
    public function __construct(array $clienti, array $sottoclienti)
    {
        $this->clienti = $clienti;
        $this->sottoclienti = $sottoclienti;

        // Alias espliciti: nomi abbreviati nel calendario → sottocliente
        // (casi impossibili da riconoscere per algoritmo: acronimi ambigui, umlaut, ecc.)
        foreach ($sottoclienti as $sc) {
            $nLow = mb_strtolower(trim($sc['nome']), 'UTF-8');
            if (mb_strpos($nLow, 'centro di medicina') !== false) $this->aliasMap['cdm'] = $sc;
            if (mb_strpos($nLow, 'lu-ve') !== false || mb_strpos($nLow, 'luve') !== false || mb_strpos($nLow, 'lu ve') !== false) $this->aliasMap['luve'] = $sc;
            if (mb_strpos($nLow, 'itagency') !== false) $this->aliasMap['ita'] = $sc;
        }
    }

    /** @return array{0: ?int, 1: ?int} [cliente_id, sottocliente_id] */
    public function abbina(string $summary, string $location, string $description): array
    {
        $summaryLower = mb_strtolower(trim($summary), 'UTF-8');

        // Alias: prima parola per parola, poi il titolo intero
        foreach (preg_split('/[\s\-]+/', $summaryLower) as $token) {
            $token = trim(str_replace(['.', ','], '', $token));
            if (isset($this->aliasMap[$token])) return $this->daSottocliente($this->aliasMap[$token]);
        }
        $clean = preg_replace('/\s+/', '', trim(str_replace(['.', ',', '-'], '', $summaryLower)));
        if (isset($this->aliasMap[$clean])) return $this->daSottocliente($this->aliasMap[$clean]);

        $searchStrings = array_merge(self::stringhe($summary), self::stringhe($location));

        // Prima i sottoclienti (più specifici)
        foreach ($this->sottoclienti as $sc) {
            $scClean = trim(preg_replace('/\([^)]*\)/', '', $sc['nome']));
            if (mb_strlen($scClean, 'UTF-8') < 3) continue;
            if (mb_strtolower($scClean, 'UTF-8') === 'azienda non trovata') continue;
            if (self::isMatch($sc['nome'], '', $description)) return $this->daSottocliente($sc);
            foreach ($searchStrings as $s) {
                if (self::isMatch($sc['nome'], $s)) return $this->daSottocliente($sc);
            }
        }

        // Poi i clienti
        foreach ($this->clienti as $c) {
            if (self::isMatch($c['ragione_sociale'], '', $description)) return [(int)$c['id'], null];
            foreach ($searchStrings as $s) {
                if (self::isMatch($c['ragione_sociale'], $s)) return [(int)$c['id'], null];
            }
        }
        return [null, null];
    }

    private function daSottocliente(array $sc): array
    {
        return [(int)$sc['cliente_id'], (int)$sc['id']];
    }

    /** Parole (≥ 2 caratteri, anche senza punteggiatura) e testo intero, in minuscolo */
    private static function stringhe(string $testo): array
    {
        if (trim($testo) === '') return [];
        $out = [];
        foreach (preg_split('/[\s\-]+/', $testo) as $p) {
            $p = trim($p);
            if (mb_strlen($p, 'UTF-8') < 2) continue;
            $out[] = mb_strtolower($p, 'UTF-8');
            $pClean = str_replace(['.', ',', ';', ':'], '', $p);
            if ($p !== $pClean && mb_strlen($pClean, 'UTF-8') >= 2) $out[] = mb_strtolower($pClean, 'UTF-8');
        }
        $out[] = mb_strtolower(trim($testo), 'UTF-8');
        return $out;
    }

    public static function isMatch(string $dbName, string $str, string $fullDescription = ''): bool
    {
        $dbName = mb_strtolower(trim($dbName), 'UTF-8');
        // Via la forma societaria e il contenuto tra parentesi (es. P.IVA)
        $dbName = preg_replace('/\b(s\.r\.l\.|s\.p\.a\.|srl|spa|snc|sas|s\.r\.l|s\.p\.a)\b/iu', '', $dbName);
        $dbName = trim(preg_replace('/\([^)]*\)/', '', $dbName));

        if (mb_strlen($dbName, 'UTF-8') < 2) return false;
        if ($dbName === $str) return true;
        if (in_array($dbName, self::FORBIDDEN, true) || in_array($str, self::FORBIDDEN, true)) return false;
        if (mb_strlen($str, 'UTF-8') < 3 && mb_strlen($dbName, 'UTF-8') < 3) return false;

        // Acronimi
        $words = array_values(array_filter(preg_split('/[\s\-]+/', $dbName), fn($w) => mb_strlen($w, 'UTF-8') > 0));
        $acronymAll = '';
        $acronymNoStop = '';
        foreach ($words as $w) {
            $acronymAll .= mb_substr($w, 0, 1, 'UTF-8');
            if (!in_array($w, self::STOP_WORDS, true)) $acronymNoStop .= mb_substr($w, 0, 1, 'UTF-8');
        }
        if (mb_strlen($str, 'UTF-8') >= 2 && mb_strlen($str, 'UTF-8') <= 5) {
            if ($str === $acronymAll || $str === $acronymNoStop) return true;
        }

        // Parole intere (almeno 3 caratteri)
        if (mb_strlen($dbName, 'UTF-8') >= 3 && mb_strlen($str, 'UTF-8') >= 3) {
            if (preg_match('/\b' . preg_quote($dbName, '/') . '\b/iu', $str)) return true;
            if (preg_match('/\b' . preg_quote($str, '/') . '\b/iu', $dbName)) return true;
        }

        // Errori di battitura: 1 carattere fino a 8, 2 da 9 in su
        if (mb_strlen($str, 'UTF-8') >= 5) {
            foreach ($words as $w) {
                if (mb_strlen($w, 'UTF-8') >= 4) {
                    $distance = levenshtein($str, $w);
                    $threshold = max(mb_strlen($str, 'UTF-8'), mb_strlen($w, 'UTF-8')) >= 9 ? 2 : 1;
                    if ($distance <= $threshold && $distance > 0) return true;
                }
            }
            $dbNameNoSpaces = str_replace(' ', '', $dbName);
            if (mb_strlen($dbNameNoSpaces, 'UTF-8') >= 5) {
                $distance = levenshtein($str, $dbNameNoSpaces);
                $threshold = max(mb_strlen($str, 'UTF-8'), mb_strlen($dbNameNoSpaces, 'UTF-8')) >= 9 ? 2 : 1;
                if ($distance <= $threshold && $distance > 0) return true;
            }
        }

        // Nome citato nella descrizione dell'evento
        if (mb_strlen($dbName, 'UTF-8') > 4 && $fullDescription !== '') {
            if (preg_match('/\b' . preg_quote($dbName, '/') . '\b/iu', $fullDescription)) return true;
        }

        // Corrispondenza parziale per stringhe lunghe
        if (mb_strlen($str, 'UTF-8') >= 5 && mb_strpos($dbName, $str) !== false) return true;
        if (mb_strlen($dbName, 'UTF-8') >= 5 && mb_strpos($str, $dbName) !== false) return true;

        return false;
    }
}
