<?php
/**
 * Prova da riga di comando del lettore della "Lista Fatture" di Sistemi (non va online: tests/ è escluso dal deploy).
 *
 *   php tests/lista_fatture_cli.php
 *   LISTA_FILE=/percorso/Lista\ Fatture.xlsx php tests/lista_fatture_cli.php   (conta le righe di un file vero, non stampa dati)
 *
 * Costruisce un .xlsx sintetico con ZipArchive e dati inventati.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/ListaFattureParser.php';

$ok = 0;
$ko = 0;
function check(string $nome, bool $cond, $dettaglio = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok   $nome\n"; return; }
    $ko++;
    echo "  FAIL $nome" . ($dettaglio !== null ? ' → ' . json_encode($dettaglio, JSON_UNESCAPED_UNICODE) : '') . "\n";
}

/** .xlsx minimo: stringhe condivise per i testi, numeri come valori, come lo esporta Sistemi. */
function xlsx(array $righe): string
{
    $stringhe = [];
    $xmlRighe = '';
    foreach ($righe as $i => $riga) {
        $celle = '';
        foreach (array_values($riga) as $j => $v) {
            $ref = chr(65 + $j) . ($i + 1);
            if (is_string($v)) {
                $stringhe[] = htmlspecialchars($v, ENT_XML1);
                $celle .= '<c r="' . $ref . '" t="s"><v>' . (count($stringhe) - 1) . '</v></c>';
            } else {
                $celle .= '<c r="' . $ref . '"><v>' . $v . '</v></c>';
            }
        }
        $xmlRighe .= '<row r="' . ($i + 1) . '">' . $celle . '</row>';
    }
    $file = tempnam(sys_get_temp_dir(), 'lista') . '.xlsx';
    $zip = new ZipArchive();
    $zip->open($file, ZipArchive::CREATE | ZipArchive::OVERWRITE);
    $ns = 'xmlns="http://schemas.openxmlformats.org/spreadsheetml/2006/main"';
    $zip->addFromString('xl/workbook.xml', '<workbook ' . $ns . ' xmlns:r="http://schemas.openxmlformats.org/officeDocument/2006/relationships"><sheets><sheet name="Lista" sheetId="1" r:id="rId1"/></sheets></workbook>');
    $zip->addFromString('xl/_rels/workbook.xml.rels', '<Relationships xmlns="http://schemas.openxmlformats.org/package/2006/relationships"><Relationship Id="rId1" Type="worksheet" Target="worksheets/sheet1.xml"/></Relationships>');
    $zip->addFromString('xl/sharedStrings.xml', '<sst ' . $ns . '>' . implode('', array_map(fn($s) => "<si><t>$s</t></si>", $stringhe)) . '</sst>');
    $zip->addFromString('xl/worksheets/sheet1.xml', '<worksheet ' . $ns . '><sheetData>' . $xmlRighe . '</sheetData></worksheet>');
    $zip->close();
    return $file;
}

echo "Lettura del file\n";
$file = xlsx([
    ['Tipo Documento', 'F/N', 'Registro', 'Numero', 'Data', 'Cliente', 'Imponibile', 'Iva', 'Totale', 'Residuo'],
    ['Nota di credito', 'N', '002', '5AV', '2026-03-10', 'Alfa Sports L.L.C.', -1000.5, 0.0, -1000.5, -1000.5],
    ['Fattura', 'F', '001', '12/001', '2026-03-08', 'Beta & Gamma S.r.l.', 480.74000000000001, 105.76000000000001, 586.5, 586.5],
    ['Fattura', 'F', '001', '11/001', '08/03/2026', 'Delta S.p.A.', 1000.0, 220.0, 1220.0, 1220],
    ['', '', '', '', '', '', '', '', '', ''],
    ['Fattura', 'F', '001', '10/001', '', 'Senza data', 10.0, 2.2, 12.2, 12.2],
]);
$righe = ListaFattureParser::leggiXlsx($file);
check('righe lette dal foglio', count($righe) === 6, count($righe));
$r = ListaFattureParser::fatture($righe);
$f = $r['fatture'];
check('tre fatture valide', count($f) === 3, count($f));
check('riga senza data segnalata', count($r['avvisi']) === 1, $r['avvisi']);
check('nota di credito riconosciuta', $f[0]['nota_credito'] && $f[0]['totale'] === -1000.5 && !$f[1]['nota_credito']);
check('importi arrotondati al centesimo', $f[1]['imponibile'] === 480.74 && $f[1]['iva'] === 105.76);
check('testo con & decodificato', $f[1]['cliente'] === 'Beta & Gamma S.r.l.', $f[1]['cliente']);
check('data italiana convertita', $f[2]['data'] === '2026-03-08');
check('registro e numero', $f[1]['registro'] === '001' && $f[1]['numero'] === '12/001');
unlink($file);

check('Sistemi: colonna Cliente → fatture emesse', $r['verso'] === 'attiva', $r['verso']);

echo "Elenco del portale (fatture ricevute)\n";
$file = xlsx([
    ['Da leggere', 'Firma', 'Allegati', 'Annotazioni', 'Fornitore', 'Data', 'Numero', 'Tipo documento', 'Totale', 'Stato FTE', 'Data consegna'],
    ['1', '', 'Allegato presente', '', 'Epsilon Servizi S.r.l.', 46290, '5/FE', 'Fattura', 9202, 'Ricevuta', 46290],
    ['1', '', '', '', 'Zeta S.p.A.', 46287, 'NC-3', 'Nota di credito', 150.5, 'Ricevuta', 46287],
    ['1', '', '', '', 'Eta S.n.c.', 46286, '9', 'Fattura', 80, 'Scartata', 46286],
]);
$r = ListaFattureParser::fatture(ListaFattureParser::leggiXlsx($file));
$f = $r['fatture'];
check('colonna Fornitore → fatture ricevute', $r['verso'] === 'passiva', $r['verso']);
check('fornitore letto come controparte', $f[0]['cliente'] === 'Epsilon Servizi S.r.l.', $f[0]['cliente']);
check('data del portale (seriale Excel)', $f[0]['data'] === '2026-09-25', $f[0]['data']);
check('solo totale: imponibile = totale, IVA 0', $f[0]['imponibile'] === 9202.0 && $f[0]['iva'] === 0.0);
check('nota di credito in positivo resa negativa', $f[1]['nota_credito'] && $f[1]['totale'] === -150.5 && $f[1]['imponibile'] === -150.5, $f[1]);
check('fattura scartata dallo SdI saltata', count($f) === 2 && str_contains($r['avvisi'][0] ?? '', 'scartata'), $r['avvisi']);
unlink($file);

echo "Casi limite\n";
check('data seriale di Excel', ListaFattureParser::data('46089') === '2026-03-08', ListaFattureParser::data('46089'));
check('importo italiano', ListaFattureParser::numero('1.234,56') === 1234.56);
$file = xlsx([['Cliente', 'Imponibile'], ['Alfa', 10]]);
try {
    ListaFattureParser::fatture(ListaFattureParser::leggiXlsx($file));
    check('intestazione senza Numero/Data/Totale rifiutata', false);
} catch (RuntimeException $e) {
    check('intestazione senza Numero/Data/Totale rifiutata', str_contains($e->getMessage(), 'Colonne mancanti'));
}
unlink($file);
$finto = tempnam(sys_get_temp_dir(), 'lista');
file_put_contents($finto, "non sono un file excel");
try {
    ListaFattureParser::leggiXlsx($finto);
    check('file non .xlsx rifiutato', false);
} catch (RuntimeException $e) {
    check('file non .xlsx rifiutato', true);
}
unlink($finto);

// File vero (facoltativo)
$vero = getenv('LISTA_FILE') ?: '';
if ($vero !== '' && is_readable($vero)) {
    echo "Lista fatture reale — solo conteggi\n";
    $r = ListaFattureParser::fatture(ListaFattureParser::leggiXlsx($vero));
    echo '  fatture: ' . count($r['fatture']) . ', note di credito: ' . count(array_filter($r['fatture'], fn($x) => $x['nota_credito']))
        . ', righe saltate: ' . count($r['avvisi']) . "\n";
}

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
