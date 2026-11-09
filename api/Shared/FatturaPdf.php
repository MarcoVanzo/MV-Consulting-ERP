<?php
/**
 * FatturaPdf — legge una fattura in PDF (testo estratto con pdf.js, con gli a capo) e ne ricava una
 * FatturaPA minima, così che entri dagli stessi import degli XML: emessa (ContabilitaController::importXmlData,
 * commessa, rate, scadenza) o ricevuta (FatturePassiveController::importXml, fornitore, costo di commessa, ritenuta).
 *
 * Lettura a regole, senza AI: etichette italiane comuni («Fattura n.», «Fattura #», «Data fattura», «Imponibile»,
 * «Importo Iva», «IVA 22% su …», «R.A.»/«Ritenuta», «Totale documento», «Scadenze», «entro il …»).
 * Chi è il destinatario lo dice la prima partita IVA dopo «Spett.»/«Destinatario»/«Cessionario»/«Cliente»:
 * se è la nostra la fattura è ricevuta, altrimenti emessa (anche se è un codice estero, «P.Iva: US000000»).
 */
declare(strict_types=1);

require_once __DIR__ . '/AnagraficaMatcher.php';

class FatturaPdf
{
    private const IMPORTO = '(\d{1,3}(?:\.\d{3})+,\d{2}|\d+,\d{2})';
    private const DATA = '(\d{1,2}[\/.\-]\d{1,2}[\/.\-]\d{4})';
    private const DESTINATARIO = '/\b(?:spett\.|spettabile\b|destinatari[oa]\b|cessionario\b|committente\b|intestata\s+a\b|cliente\s*:)/iu';

    /**
     * Dati letti: numero, data (Y-m-d), imponibile, iva, aliquota, ritenuta, totale, scadenza, descrizione,
     * nostra/controparte (piva, cf, nome), verso (attiva|passiva|null), nota_credito, avvisi[].
     */
    public static function leggi(string $testo, string $nostraPiva): array
    {
        $t = str_replace(["\r", "\u{00A0}"], ["", ' '], $testo);
        $nostra = AnagraficaMatcher::normalizzaCodice($nostraPiva);
        $avvisi = [];

        // Partite IVA e codici fiscali con la loro posizione nel testo. Un cliente estero ha un codice col prefisso
        // del paese («US000000», «DE123456789»): conta come controparte, così la nostra P.IVA a piè di pagina
        // non diventa la prima dopo «Spett.»
        $piva = [];
        if (preg_match_all('/(?:p\.?\s?iva|partita\s+iva|vat(?:\s+number)?)\s*[:.]?\s*(?:IT\s*)?(\d{11}\b|(?-i:(?!IT)[A-Z]{2})(?=[A-Z0-9]{0,12}\d)[A-Z0-9]{2,13}\b)/iu', $t, $m, PREG_OFFSET_CAPTURE)) {
            foreach ($m[1] as [$v, $pos]) $piva[] = [$v, $pos];
        }
        $cf = [];
        if (preg_match_all('/(?:c\.?\s?f\.?|c\.\s?fisc\.?|codice\s+fiscale)\s*[:.]?\s*([A-Z0-9]{16}|\d{11})\b/iu', $t, $m)) {
            $cf = array_map('strtoupper', $m[1]);
        }
        $altra = null;
        foreach ($piva as [$v, $pos]) if ($v !== $nostra) { $altra = [$v, $pos]; break; }
        $cfAltro = '';
        foreach ($cf as $c) if ($c !== $nostra && (!$altra || $c !== $altra[0])) { $cfAltro = $c; break; }
        if (!$altra && $cfAltro === '') $avvisi[] = 'Partita IVA della controparte non trovata nel PDF';

        // Verso: la prima partita IVA dopo la parola che introduce il destinatario
        $verso = null;
        if (preg_match(self::DESTINATARIO, $t, $k, PREG_OFFSET_CAPTURE)) {
            foreach ($piva as [$v, $pos]) {
                if ($pos < $k[0][1]) continue;
                if ($nostra !== '' && $v === $nostra) $verso = 'passiva';
                elseif ($altra && $v === $altra[0]) $verso = 'attiva';
                break;
            }
        }
        // Solo la nostra P.IVA come intestataria (il fornitore può avere solo il codice fiscale)
        if ($verso === null && $nostra !== '' && !$altra && $cfAltro !== '' && in_array($nostra, array_column($piva, 0), true)) $verso = 'passiva';

        // «nota di credito n.» prima di «fattura n.»: nel testo di uno storno c'è anche il numero della fattura stornata
        $numero = self::primo('/\bnota\s+di\s+credito\s*(?:n\.?|nr\.?|n°|numero|#|no\.)\s*[:.]?\s*([A-Z0-9][A-Z0-9\/\-_.]*[A-Z0-9]|[A-Z0-9])/iu', $t)
            ?? self::primo('/\b(?:fattura|documento|doc\.)\s*(?:n\.?|nr\.?|n°|numero|#|no\.)\s*[:.]?\s*([A-Z0-9][A-Z0-9\/\-_.]*[A-Z0-9]|[A-Z0-9])/iu', $t)
            ?? self::primo('/\bnumero\s+(?:fattura|documento)\s*[:.]?\s*([A-Z0-9][A-Z0-9\/\-_.]*[A-Z0-9]|[A-Z0-9])/iu', $t);
        $data = self::data(self::primo('/\bdata\s+(?:fattura|documento|emissione)\s*[:.]?\s*' . self::DATA . '/iu', $t)
            ?? self::primo('/\bnota\s+di\s+credito\s*(?:n\.?|nr\.?|n°|numero|#)[^\n]{0,40}?\bdel\s+' . self::DATA . '/iu', $t)
            ?? self::primo('/\bfattura\s*(?:n\.?|nr\.?|n°|numero|#)[^\n]{0,40}?\bdel\s+' . self::DATA . '/iu', $t)
            ?? self::primo('/' . self::DATA . '/u', $t));

        $imponibile = self::importo('/\b(?:totale\s+)?imponibile\s*[:.]?\s*€?\s*' . self::IMPORTO . '/iu', $t);
        $iva = self::importo('/\b(?:importo\s+iva|totale\s+iva|iva\s+totale|totale\s+imposta|imposta)\s*[:.]?\s*€?\s*' . self::IMPORTO . '/iu', $t)
            ?? self::importo('/\biva\s+\d+(?:[.,]\d+)?\s*%(?:\s+su\s+€?\s*[\d.]+,\d{2})?\s*:?\s*€?\s*' . self::IMPORTO . '/iu', $t);
        $ritenuta = self::importo('/(?:ritenuta|\br\.\s?a\.)[^\n€]*?(?:\s*su\s*€?\s*[\d.]+,\d{2}\s*:?)?\s*\(?\s*-?\s*€?\s*' . self::IMPORTO . '/iu', $t) ?? 0.0;
        $totale = self::importo('/\btotale\s+documento\s*[:.]?\s*€?\s*' . self::IMPORTO . '/iu', $t)
            ?? self::importo('/\btotale(?:\s+fattura)?\s*[:.]?\s*€?\s*' . self::IMPORTO . '/iu', $t);

        // Importi che mancano ricavati dagli altri
        if ($imponibile !== null && $iva === null && $totale !== null) $iva = round($totale - $imponibile, 2);
        if ($imponibile === null && $iva !== null && $totale !== null) $imponibile = round($totale - $iva, 2);
        if ($imponibile === null && $totale !== null) {
            $imponibile = round($totale / 1.22, 2);
            $iva = round($totale - $imponibile, 2);
            $avvisi[] = 'Imponibile non trovato: ricavato dal totale con IVA al 22%';
        }
        $iva ??= 0.0;
        if ($totale !== null && $imponibile !== null && abs($imponibile + $iva - $totale) > 0.05) {
            $avvisi[] = sprintf('Imponibile + IVA (%.2f) diverso dal totale letto (%.2f)', $imponibile + $iva, $totale);
        }
        $aliquota = 0.0;
        if ($imponibile) {
            $grezza = $iva / $imponibile * 100;
            foreach ([22, 10, 5, 4, 0] as $a) if (abs($grezza - $a) < 0.6) { $aliquota = (float)$a; break; }
            if ($aliquota === 0.0 && $iva > 0) $aliquota = round($grezza, 2);
        }

        $scadenza = self::data(self::primo('/\b(?:data\s+scadenza|scadenz[ae]|entro\s+il)\s*[:.]?\s*' . self::DATA . '/iu', $t));

        return [
            'numero' => $numero,
            'data' => $data,
            'imponibile' => $imponibile,
            'iva' => round($iva, 2),
            'aliquota' => $aliquota,
            'ritenuta' => round($ritenuta, 2),
            'totale' => $imponibile !== null ? round($imponibile + $iva, 2) : null,
            'scadenza' => $scadenza,
            'descrizione' => self::descrizione($t),
            'nota_credito' => (bool)preg_match('/\bnota\s+di\s+credito\b/iu', $t),
            'nostra' => $nostra,
            'controparte' => ['piva' => $altra[0] ?? '', 'cf' => $cfAltro, 'nome' => self::nome($t, $altra, $nostra)],
            'verso' => $verso,
            'avvisi' => $avvisi,
        ];
    }

    /** FatturaPA minima con i dati letti: la leggono gli import XML già esistenti. */
    public static function xml(array $f, string $verso): string
    {
        $e = fn($s) => htmlspecialchars((string)$s, ENT_XML1 | ENT_QUOTES, 'UTF-8');
        $n = fn(float $v) => number_format($v, 2, '.', '');
        $soggetto = function (string $piva, string $cf, string $nome) use ($e): string {
            $s = '<DatiAnagrafici>';
            // Codice estero: il prefisso è il paese («US000000» → US / 000000)
            [$paese, $codice] = preg_match('/^[A-Z]{2}(?=.)/', $piva) ? [substr($piva, 0, 2), substr($piva, 2)] : ['IT', $piva];
            if ($piva !== '') $s .= '<IdFiscaleIVA><IdPaese>' . $e($paese) . '</IdPaese><IdCodice>' . $e($codice) . '</IdCodice></IdFiscaleIVA>';
            if ($cf !== '') $s .= '<CodiceFiscale>' . $e($cf) . '</CodiceFiscale>';
            return $s . '<Anagrafica><Denominazione>' . $e($nome) . '</Denominazione></Anagrafica></DatiAnagrafici>';
        };
        $c = $f['controparte'];
        $loro = $soggetto($c['piva'], $c['cf'], $c['nome'] ?: 'Da fattura PDF');
        $noi = $soggetto($f['nostra'], '', 'MV Consulting');
        [$ced, $ces] = $verso === 'passiva' ? [$loro, $noi] : [$noi, $loro];

        $imp = (float)$f['imponibile'];
        $iva = (float)$f['iva'];
        $gen = '<TipoDocumento>' . ($f['nota_credito'] ? 'TD04' : 'TD01') . '</TipoDocumento><Divisa>EUR</Divisa>'
            . '<Data>' . $e($f['data']) . '</Data><Numero>' . $e($f['numero']) . '</Numero>';
        if ($f['ritenuta'] > 0) {
            $gen .= '<DatiRitenuta><TipoRitenuta>RT01</TipoRitenuta><ImportoRitenuta>' . $n($f['ritenuta']) . '</ImportoRitenuta>'
                . '<AliquotaRitenuta>' . $n($imp ? $f['ritenuta'] / $imp * 100 : 0) . '</AliquotaRitenuta><CausalePagamento>A</CausalePagamento></DatiRitenuta>';
        }
        $gen .= '<ImportoTotaleDocumento>' . $n($imp + $iva) . '</ImportoTotaleDocumento>';
        $pag = $f['scadenza'] ? '<DatiPagamento><CondizioniPagamento>TP02</CondizioniPagamento><DettaglioPagamento><ModalitaPagamento>MP05</ModalitaPagamento>'
            . '<DataScadenzaPagamento>' . $e($f['scadenza']) . '</DataScadenzaPagamento><ImportoPagamento>' . $n($imp + $iva - $f['ritenuta'])
            . '</ImportoPagamento></DettaglioPagamento></DatiPagamento>' : '';

        return '<?xml version="1.0" encoding="UTF-8"?>'
            . '<p:FatturaElettronica versione="FPR12" xmlns:p="http://ivaservizi.agenziaentrate.gov.it/docs/xsd/fatture/v1.2">'
            . '<FatturaElettronicaHeader><CedentePrestatore>' . $ced . '</CedentePrestatore>'
            . '<CessionarioCommittente>' . $ces . '</CessionarioCommittente></FatturaElettronicaHeader>'
            . '<FatturaElettronicaBody><DatiGenerali><DatiGeneraliDocumento>' . $gen . '</DatiGeneraliDocumento></DatiGenerali>'
            . '<DatiBeniServizi><DettaglioLinee><NumeroLinea>1</NumeroLinea><Descrizione>' . $e($f['descrizione'] ?: 'Fattura ' . $f['numero'])
            . '</Descrizione><Quantita>1.00</Quantita><PrezzoUnitario>' . $n($imp) . '</PrezzoUnitario><PrezzoTotale>' . $n($imp)
            . '</PrezzoTotale><AliquotaIVA>' . $n($f['aliquota']) . '</AliquotaIVA></DettaglioLinee>'
            . '<DatiRiepilogo><AliquotaIVA>' . $n($f['aliquota']) . '</AliquotaIVA><ImponibileImporto>' . $n($imp)
            . '</ImponibileImporto><Imposta>' . $n($iva) . '</Imposta></DatiRiepilogo></DatiBeniServizi>'
            . $pag . '</FatturaElettronicaBody></p:FatturaElettronica>';
    }

    private static function primo(string $re, string $t): ?string
    {
        return preg_match($re, $t, $m) ? trim($m[1]) : null;
    }

    private static function importo(string $re, string $t): ?float
    {
        $v = self::primo($re, $t);
        return $v === null ? null : (float)str_replace(['.', ','], ['', '.'], $v);
    }

    private static function data(?string $d): ?string
    {
        if ($d === null) return null;
        $p = preg_split('/[\/.\-]/', $d);
        if (count($p) !== 3 || !checkdate((int)$p[1], (int)$p[0], (int)$p[2])) return null;
        return sprintf('%04d-%02d-%02d', $p[2], $p[1], $p[0]);
    }

    /** Righe sotto l'intestazione «Descrizione» fino ai totali, senza le colonne di numeri. */
    private static function descrizione(string $t): string
    {
        $righe = explode("\n", $t);
        $out = [];
        $dentro = false;
        foreach ($righe as $r) {
            $r = trim(preg_replace('/\s+/u', ' ', $r));
            if (!$dentro) {
                if (preg_match('/^descrizione\b/iu', $r)) $dentro = true;
                continue;
            }
            if (preg_match('/\b(?:sub\s?totale|totale|imponibile|modalit[aà]|pagamento|riepilogo|iban)\b/iu', $r)) break;
            // Via importi, quantità e percentuali: resta il testo
            $testo = trim(preg_replace('/(?:€\s*)?\b\d{1,3}(?:\.\d{3})*(?:,\d+)?\s*(?:%|€)?(?=\s|$)/u', '', $r));
            if (preg_match('/\p{L}{3,}/u', $testo)) $out[] = $testo;
        }
        return mb_substr(implode(' ', $out), 0, 1000);
    }

    /** Nome della controparte: dopo «Spett./Destinatario» se è lei, altrimenti la riga sopra la sua partita IVA. */
    private static function nome(string $t, ?array $altra, string $nostra): string
    {
        if (!$altra) {
            return preg_match('/\bintestatario\s*:\s*([^\n]+)/iu', $t, $m) ? trim($m[1]) : '';
        }
        if (preg_match(self::DESTINATARIO, $t, $k, PREG_OFFSET_CAPTURE) && $k[0][1] < $altra[1]) {
            $tra = substr($t, $k[0][1], $altra[1] - $k[0][1]);
            if ($nostra === '' || !str_contains($tra, $nostra)) {
                $righe = array_values(array_filter(array_map('trim', explode("\n", $tra))));
                // La parola chiave sta da sola sulla riga: il nome è la riga dopo
                $prima = trim((string)preg_replace(self::DESTINATARIO, '', $righe[0] ?? ''), " :.\t");
                $nome = $prima !== '' ? $prima : ($righe[1] ?? '');
                if (preg_match('/\p{L}{2,}/u', $nome)) return mb_substr($nome, 0, 200);
            }
        }
        $prima = substr($t, 0, $altra[1]);
        $righe = array_values(array_filter(array_map('trim', explode("\n", $prima))));
        $ultima = (string)array_pop($righe);
        $suRiga = trim((string)preg_replace('/(?:p\.?\s?iva|partita\s+iva|vat)\s*[:.]?\s*(?:IT\s*)?$/iu', '', $ultima));
        $nome = preg_match('/\p{L}{3,}/u', $suRiga) ? $suRiga : (string)array_pop($righe);
        if (preg_match('/\bintestatario\s*:\s*([^\n]+)/iu', $t, $m)) {
            // L'intestatario del conto è di solito chi emette: vale se il candidato sembra un indirizzo
            if (preg_match('/^\d{5}\b|^(?:via|viale|piazza|corso)\b/iu', $nome)) $nome = trim($m[1]);
        }
        return mb_substr($nome, 0, 200);
    }
}
