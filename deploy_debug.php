<?php
$content = file_get_contents('api/Controllers/GoogleAuthController.php');
$inject = <<<INJECT
                            if (!isset(\$debugUnmatched)) \$debugUnmatched = [];
                            if (\$matchedClienteId) {
                                // matched
                            } else {
                                if (count(\$debugUnmatched) < 10) {
                                    \$debugUnmatched[] = \$summary . " (" . implode(',', \$searchStrings) . ")";
                                }
                            }
INJECT;

// remove the old @file_put_contents
$content = preg_replace('/@file_put_contents[^\n]+\n/', '', $content);
$content = preg_replace('/if \(\$matchedClienteId\) \{\s*\} else \{\s*\}/s', '', $content); // clean up old empty else if any

$content = str_replace(
    '// Per ogni giorno attraversato da questo evento',
    $inject . "\n                        // Per ogni giorno attraversato da questo evento",
    $content
);

$content = str_replace(
    'return [\'status\' => \'success\', \'imported\' => $countImported, \'message\' => "Sincronizzazione completata: Elaborati $analyzed contatti. Aggiornati/Importati: $countImported"];',
    'return [\'status\' => \'success\', \'imported\' => $countImported, \'message\' => "Elaborati $analyzed contatti. Imp: $countImported. NON ASSOC: " . implode(" | ", $debugUnmatched ?? [])];',
    $content
);

file_put_contents('api/Controllers/GoogleAuthController.php', $content);
