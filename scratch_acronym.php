<?php
$isMatch = function($dbName, $str, $fullDescription = '') {
    $dbName = mb_strtolower(trim($dbName), 'UTF-8');
    if ($dbName === $str && mb_strlen($dbName, 'UTF-8') > 0) return true;
    
    // Prevent short generic matches
    $forbidden = ['spa', 'srl', 'snc', 'sas', 'per', 'con', 'del', 'dal', 'all', 'una', 'ita', 'titolo'];
    if (in_array($dbName, $forbidden) || in_array($str, $forbidden)) return false;

    // Clean dbName from common suffixes
    $cleanDbName = preg_replace('/\b(s\.r\.l\.|s\.p\.a\.|srl|spa|snc|sas)\b/iu', '', $dbName);
    $cleanDbName = trim(preg_replace('/\s+/', ' ', $cleanDbName));

    if ($cleanDbName === $str && mb_strlen($cleanDbName, 'UTF-8') > 0) return true;

    // Acronym matching logic
    $words = preg_split('/[\s\-]+/', $cleanDbName);
    $acronymAll = '';
    $acronymNoStop = '';
    $stopWords = ['di', 'a', 'da', 'in', 'con', 'su', 'per', 'tra', 'fra', 'de', 'della', 'del', 'delle', 'dei', 'degli', 'dello', 'alla', 'al', 'allo', 'agli', 'alle', 'e', 'ed', 'un', 'una', 'uno', 'il', 'lo', 'la', 'i', 'gli', 'le'];
    
    foreach ($words as $w) {
        if (mb_strlen($w, 'UTF-8') > 0) {
            $firstChar = mb_substr($w, 0, 1, 'UTF-8');
            $acronymAll .= $firstChar;
            if (!in_array($w, $stopWords)) {
                $acronymNoStop .= $firstChar;
            }
        }
    }

    if (mb_strlen($str, 'UTF-8') >= 2) {
        if ($str === $acronymAll || $str === $acronymNoStop) return true;
    }

    $escapedCleanDb = preg_quote($cleanDbName, '/');
    $escapedDb = preg_quote($dbName, '/');
    $escapedStr = preg_quote($str, '/');

    // Verifica nelle short string usando sia dbName intero che pulito
    if (mb_strlen($cleanDbName, 'UTF-8') >= 3 && preg_match('/\b' . $escapedCleanDb . '\b/iu', $str)) return true;
    if (mb_strlen($dbName, 'UTF-8') >= 3 && preg_match('/\b' . $escapedDb . '\b/iu', $str)) return true;
    
    if (mb_strlen($str, 'UTF-8') >= 3 && preg_match('/\b' . $escapedStr . '\b/iu', $cleanDbName)) return true;

    // Verifica nella descrizione completa del calendario (se fornita)
    if (mb_strlen($cleanDbName, 'UTF-8') > 3 && !empty($fullDescription)) {
        if (preg_match('/\b' . $escapedCleanDb . '\b/iu', $fullDescription)) return true;
    }
    if (mb_strlen($dbName, 'UTF-8') > 4 && !empty($fullDescription)) {
        if (preg_match('/\b' . $escapedDb . '\b/iu', $fullDescription)) return true;
    }
    
    // Extra fallback: se il dbName inizia con la str o viceversa
    if (mb_strlen($str, 'UTF-8') >= 5 && mb_strpos($cleanDbName, $str) !== false) return true;
    if (mb_strlen($cleanDbName, 'UTF-8') >= 5 && mb_strpos($str, $cleanDbName) !== false) return true;

    return false;
};

var_dump($isMatch("Centro di medicina SPA", "cdm"));
var_dump($isMatch("MANUFACTURE DE SOULIERS LOUIS VUITTON SRL", "mslv"));
var_dump($isMatch("Centro di medicina SPA", "centro"));

