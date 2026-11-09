<?php
/**
 * Prova da riga di comando della lettura delle fatture PDF (FatturaPdf): ricevuta da un professionista con
 * ritenuta ed etichette a colonna, emessa dal gestionale di fatturazione, FatturaPA ricostruita.
 * Testi nel formato di pdf.js (una riga per riga del PDF), dati inventati.
 *
 *   php tests/fattura_pdf_cli.php
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

require_once __DIR__ . '/../api/Shared/Database.php';
require_once __DIR__ . '/../api/Shared/Response.php';
require_once __DIR__ . '/../api/Shared/FatturaPdf.php';
require_once __DIR__ . '/../api/Controllers/ImportaController.php';

$ok = 0;
$ko = 0;
function check(string $nome, bool $cond, $dettaglio = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok   $nome\n"; return; }
    $ko++;
    echo "  FAIL $nome" . ($dettaglio !== null ? ' → ' . json_encode($dettaglio, JSON_UNESCAPED_UNICODE) : '') . "\n";
}

const NOSTRA = '01111111111';

$ricevuta = <<<TXT
Mario Bianchi
P.IVA 03333333333
CF BNCMRA80A01F205X
Via Roma, 1
20100 Milano (MI)
Pag. 1/1
   DESTINATARIO:
 ESEMPIO S.R.L.
 VIA VERDI 5
Italia
 P.IVA 01111111111
CF 01111111111
 FATTURA
 Totale dovuto:
 € 1.632,00
 Data fattura:
 29/09/2026
 Fattura #:
 095
 Descrizione  Prezzo  Quantità  Importo netto  IVA
 (Cod. X1) Consulenza generale
 Progetto Alfa
€ 3.200,00  0,50  € 1.600,00  22%
 Modalità di pagamento: Bonifico bancario
Intestatario: Mario Bianchi
   1.632,00 € da corrispondere entro il 30/09/2026
 IMPONIBILE:  € 1.600,00
 IVA 22% su € 1.600,00:  € 352,00
 TOTALE DOCUMENTO:  € 1.952,00
 R.A. DEL 20% SU € 1.600,00:  (€ 320,00)
 Totale dovuto:  € 1.632,00
TXT;

$emessa = <<<TXT
ESEMPIO S.R.L.
 Spett.
 CLIENTE BETA SPA
 VIA CASTELLO 5
33000 UDINE UD
ITALIA P.Iva:  02222222222
C.fisc:  02222222222
fattura n. 82/001 del 09/11/2026
 DESCRIZIONE  UM  QT  PREZZO UN.  SCONTO  IMPORTO
 Assessment di conformità
NIS2 (Prima rata)
1,00  2.250,00  2.250,00
Subtotale  € 2.250,00
 Totale imponibile  € 2.250,00
 Importo Iva  € 495,00
 Totale  € 2.745,00
Netto a pagare  € 2.745,00
Scadenze:
30/11/2026  € 2.745,00
 ESEMPIO S.R.L. - VIA VERDI 5 - p.iva: 01111111111 - c.f.:
01111111111
TXT;

echo "Fattura ricevuta (professionista, etichette a colonna)\n";
$r = FatturaPdf::leggi($ricevuta, 'IT' . NOSTRA);
check('ricevuta: verso passiva', $r['verso'] === 'passiva', $r['verso']);
check('numero dopo «Fattura #:» a capo', $r['numero'] === '095', $r['numero']);
check('data da «Data fattura»', $r['data'] === '2026-09-29', $r['data']);
check('imponibile, IVA, aliquota', $r['imponibile'] === 1600.0 && $r['iva'] === 352.0 && $r['aliquota'] === 22.0, $r);
check('ritenuta «R.A. … SU € x: (€ y)» = y', $r['ritenuta'] === 320.0, $r['ritenuta']);
check('totale documento', $r['totale'] === 1952.0, $r['totale']);
check('scadenza da «entro il»', $r['scadenza'] === '2026-09-30', $r['scadenza']);
check('fornitore: P.IVA, CF, nome', $r['controparte'] === ['piva' => '03333333333', 'cf' => 'BNCMRA80A01F205X', 'nome' => 'Mario Bianchi'], $r['controparte']);
check('descrizione senza colonne di numeri', $r['descrizione'] === '(Cod. X1) Consulenza generale Progetto Alfa', $r['descrizione']);
check('nessun avviso', $r['avvisi'] === [], $r['avvisi']);

echo "Fattura emessa (gestionale)\n";
$e = FatturaPdf::leggi($emessa, NOSTRA);
check('emessa: verso attiva (prima P.IVA dopo «Spett.» è del cliente)', $e['verso'] === 'attiva', $e['verso']);
check('numero e data da «fattura n. … del …»', $e['numero'] === '82/001' && $e['data'] === '2026-11-09', [$e['numero'], $e['data']]);
check('«Subtotale» e «Totale imponibile» non confusi col totale', $e['imponibile'] === 2250.0 && $e['iva'] === 495.0 && $e['totale'] === 2745.0, $e);
check('nessuna ritenuta', $e['ritenuta'] === 0.0);
check('scadenza da «Scadenze:» a capo', $e['scadenza'] === '2026-11-30', $e['scadenza']);
check('cliente: nome dopo «Spett.»', $e['controparte']['nome'] === 'CLIENTE BETA SPA' && $e['controparte']['piva'] === '02222222222', $e['controparte']);

echo "FatturaPA ricostruita\n";
$xml = FatturaPdf::xml($r, 'passiva');
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
check('ricevuta: cessionario siamo noi', ImportaController::verso($xml, $pdo, 'mv_', NOSTRA) === 'passiva');
check('emessa: cedente siamo noi', ImportaController::verso(FatturaPdf::xml($e, 'attiva'), $pdo, 'mv_', NOSTRA) === 'attiva');
$sx = simplexml_load_string(preg_replace('/(<\/?)p:/', '$1', $xml));
$b = $sx->FatturaElettronicaBody;
$g = $b->DatiGenerali->DatiGeneraliDocumento;
check('XML leggibile', $sx !== false);
check('numero, data, ritenuta', (string)$g->Numero === '095' && (string)$g->Data === '2026-09-29'
    && (string)$g->DatiRitenuta->ImportoRitenuta === '320.00', $g);
check('riepilogo IVA', (string)$b->DatiBeniServizi->DatiRiepilogo->ImponibileImporto === '1600.00'
    && (string)$b->DatiBeniServizi->DatiRiepilogo->Imposta === '352.00');
check('scadenza e netto da pagare', (string)$b->DatiPagamento->DettaglioPagamento->DataScadenzaPagamento === '2026-09-30'
    && (string)$b->DatiPagamento->DettaglioPagamento->ImportoPagamento === '1632.00');
check('caratteri speciali protetti', str_contains(FatturaPdf::xml(['controparte' => ['piva' => '', 'cf' => '', 'nome' => 'A & B <srl>']] + $r, 'passiva'), 'A &amp; B &lt;srl&gt;'));

echo "Casi incerti\n";
$senza = FatturaPdf::leggi("Fattura n. 7 del 01/02/2026\nP.IVA 05555555555\nTotale € 122,00", '');
check('senza imponibile: ricavato dal totale al 22% con avviso', $senza['imponibile'] === 100.0 && $senza['iva'] === 22.0 && count($senza['avvisi']) === 1, $senza);
check('senza destinatario né nostra P.IVA: verso da chiedere', $senza['verso'] === null);
$nc = FatturaPdf::leggi("NOTA DI CREDITO\nDocumento n. 3 del 05/03/2026\nSpett.\nCliente\nP.IVA 02222222222\nImponibile € 100,00\nImporto IVA € 22,00", NOSTRA);
check('nota di credito → TD04', $nc['nota_credito'] && str_contains(FatturaPdf::xml($nc, 'attiva'), '<TipoDocumento>TD04</TipoDocumento>'));

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
