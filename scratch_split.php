<?php
$summary = "Sviluppo CDM / MSLV o C.D.M.";
$parts = preg_split('/[\s\-]+/', $summary);
$searchStrings = [];
foreach ($parts as $p) {
    $p = trim($p);
    if (mb_strlen($p, 'UTF-8') >= 2) $searchStrings[] = mb_strtolower($p, 'UTF-8');
}
print_r($searchStrings);
