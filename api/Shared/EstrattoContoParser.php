<?php
/**
 * EstrattoContoParser — movimenti da un estratto conto.
 *
 * Formato principale: XML CBI (parseXmlCbi), esportato dall'home banking (Centromarca Banca e altre).
 * Riserva per il PDF (testo estratto nel browser con pdf.js), a strati:
 *   1. parser specifico della banca, se riconosciuta (Centromarca Banca in PDF: stub, vedi parseCentromarca)
 *   2. lettura AI (DocumentAi), se ANTHROPIC_API_KEY è configurata
 *   3. parser a regole generico per estratti conto italiani
 *
 * Ogni movimento: {data_operazione, data_valuta, importo (con segno), descrizione, controparte, segno_incerto}
 * più, dal CBI, {riferimento, codice_operazione, iban}.
 * Solo funzioni pure: niente database, così si prova da CLI (tests/riconciliazione_cli.php).
 */
declare(strict_types=1);

class EstrattoContoParser
{
    private const RE_DATA = '\d{1,2}[\/.\-]\d{1,2}[\/.\-](?:\d{4}|\d{2})';
    // Importo italiano: 1.234,56 / 1234,56 con segno davanti o dietro
    private const RE_IMPORTO = '[+\-]?\s?\d{1,3}(?:\.\d{3})*,\d{2}(?:\s?[+\-])?(?![\d])';

    // Righe da non trattare come movimenti né come seguito di una descrizione
    private const RIGHE_DA_SALTARE = '/\b(SALDO\s+(INIZIALE|FINALE|CONTABILE|DISPONIBILE|AL|PRECEDENTE)|TOTALE\s+(MOVIMENTI|DARE|AVERE|ADDEBITI|ACCREDITI)|RIPORTO|A\s+RIPORTARE|PAGINA\s+\d|PAG\.\s*\d|DATA\s+(CONTABILE|OPERAZIONE)|DATA\s+VALUTA|ESTRATTO\s+CONTO|DESCRIZIONE\s+OPERAZION)/i';

    // Parole che dicono il verso del movimento quando l'importo non ha segno (colonne dare/avere perse nel testo).
    // Forti: bastano da sole. Deboli: contano solo se non ci sono parole forti (es. "pagamento fattura" nella causale di un incasso)
    private const ACCREDITO_FORTI = ['ACCREDITO', 'A VOSTRO FAVORE', 'A VS FAVORE', 'A VS. FAVORE', 'BONIFICO DA', 'BON. DA',
        'VERSAMENTO', 'INCASSO', 'ORDINANTE', 'GIROCONTO A CREDITO', 'INTERESSI CREDITORI'];
    private const ADDEBITO_FORTI = ['ADDEBITO', 'A FAVORE DI', 'DISPOSIZIONE', 'COMMISSION', 'IMPOSTA', 'BOLLO',
        'PRELIEVO', 'CANONE', 'F24', 'SDD', 'INTERESSI DEBITORI', 'VS. DISPOSIZIONE'];
    private const ACCREDITO_DEBOLI = ['RIMBORSO', 'STORNO'];
    private const ADDEBITO_DEBOLI = ['PAGAMENTO', 'SPESE', 'POS ', 'CARTA', 'UTENZ', 'RATA '];

    /**
     * @param string[] $pages testo di ogni pagina
     * @return array{banca:string, metodo:string, movimenti:array, avvisi:string[]}
     */
    public static function parse(array $pages, string $banca = ''): array
    {
        $testo = implode("\n", array_map('strval', $pages));
        $avvisi = [];
        if ($banca === '') $banca = self::riconosciBanca($testo);

        // 1. Parser della banca
        if (stripos($banca, 'centromarca') !== false) {
            $mov = self::parseCentromarca($testo);
            if ($mov !== null) return ['banca' => $banca, 'metodo' => 'centromarca', 'movimenti' => $mov, 'avvisi' => $avvisi];
        }

        // 2. Lettura AI, a gruppi di pagine per restare nei limiti di risposta
        if (class_exists('ClaudeClient') && ClaudeClient::isConfigured() && class_exists('DocumentAi')) {
            try {
                $mov = [];
                foreach (array_chunk($pages, 4) as $gruppo) {
                    $out = DocumentAi::estraiMovimentiBancari(implode("\n", $gruppo));
                    if ($banca === '' && !empty($out['banca'])) $banca = (string)$out['banca'];
                    foreach ($out['movimenti'] ?? [] as $m) {
                        $n = self::normalizzaMovimento($m);
                        if ($n) $mov[] = $n;
                    }
                }
                if ($mov) return ['banca' => $banca, 'metodo' => 'ai', 'movimenti' => $mov, 'avvisi' => $avvisi];
                $avvisi[] = 'La lettura AI non ha trovato movimenti: uso il parser a regole.';
            } catch (Throwable $e) {
                $avvisi[] = 'Lettura AI non riuscita (' . $e->getMessage() . '): uso il parser a regole.';
            }
        }

        // 3. Regole
        $r = self::parseRegole($testo);
        return ['banca' => $banca, 'metodo' => 'regole', 'movimenti' => $r['movimenti'], 'avvisi' => array_merge($avvisi, $r['avvisi'])];
    }

    /**
     * Estratto conto XML CBI (BkToCstmrStmt, es. export di Centromarca Banca): è il formato principale.
     * I prefissi dei namespace cambiano da banca a banca: si naviga con local-name().
     * @return array{banca:string, iban:string, metodo:string, movimenti:array, avvisi:string[]}
     * @throws RuntimeException se il file non è un estratto conto CBI
     */
    public static function parseXmlCbi(string $xml, string $banca = ''): array
    {
        // Niente DTD: un estratto conto CBI non ne ha, e le entità esterne sono un rischio
        if (preg_match('/<!DOCTYPE/i', $xml)) throw new RuntimeException('XML non valido: dichiarazione DOCTYPE non ammessa');
        $dom = new DOMDocument();
        $prev = libxml_use_internal_errors(true);
        $ok = $xml !== '' && $dom->loadXML($xml, LIBXML_NONET);
        libxml_clear_errors();
        libxml_use_internal_errors($prev);
        if (!$ok) throw new RuntimeException('XML non leggibile');
        $xp = new DOMXPath($dom);
        $el = fn(string $nome) => "*[local-name()='$nome']";
        $testo = function (?DOMNode $ctx, string $path) use ($xp): string {
            if (!$ctx) return '';
            $n = $xp->query($path, $ctx);
            return ($n && $n->length) ? trim((string)$n->item(0)->textContent) : '';
        };

        // camt.053 (Stmt), camt.052 (Rpt), camt.054 (Ntfctn): stessa struttura dei movimenti
        $stmts = $xp->query('//' . $el('Stmt') . ' | //' . $el('Rpt') . ' | //' . $el('Ntfctn'));
        if (!$stmts || !$stmts->length) throw new RuntimeException('Non è un estratto conto CBI/camt (manca Stmt)');
        $movimenti = [];
        $avvisi = [];
        $iban = '';
        foreach ($stmts as $stmt) {
            $ibanStmt = $testo($stmt, './' . $el('Acct') . '/' . $el('Id') . '/' . $el('IBAN'));
            if ($iban === '') $iban = $ibanStmt;
            $saldi = [];
            foreach ($xp->query('./' . $el('Bal'), $stmt) as $bal) {
                $cod = $testo($bal, './/' . $el('Cd'));
                $amt = (float)$testo($bal, './' . $el('Amt'));
                if ($testo($bal, './' . $el('CdtDbtInd')) === 'DBIT') $amt = -$amt;
                $saldi[$cod] = $amt;
            }
            $somma = 0.0;
            foreach ($xp->query('./' . $el('Ntry'), $stmt) as $n) {
                $imp = (float)str_replace(',', '.', $testo($n, './' . $el('Amt')));
                $dbit = $testo($n, './' . $el('CdtDbtInd')) === 'DBIT';
                $imp = round($dbit ? -$imp : $imp, 2);
                $somma += $imp;
                $dataOp = substr($testo($n, './' . $el('BookgDt') . '/*'), 0, 10);
                $dataVal = substr($testo($n, './' . $el('ValDt') . '/*'), 0, 10);
                if (!self::data($dataOp) || abs($imp) < 0.005) continue;
                // Causale: tutte le AddtlTxInf dei dettagli, altrimenti l'informazione della riga
                $causali = [];
                foreach ($xp->query('.//' . $el('AddtlTxInf'), $n) as $c) $causali[] = trim($c->textContent);
                // camt standard: la causale del bonifico sta in RmtInf/Ustrd
                foreach ($xp->query('.//' . $el('RmtInf') . '/' . $el('Ustrd'), $n) as $c) $causali[] = trim($c->textContent);
                $causali = array_values(array_unique($causali));
                $descr = trim(implode(' ', array_filter($causali))) ?: $testo($n, './' . $el('AddtlNtryInf'));
                $parte = $testo($n, './/' . $el('RltdPties') . '/' . $el($dbit ? 'Cdtr' : 'Dbtr') . '/' . $el('Nm'))
                    ?: $testo($n, './/' . $el('RltdPties') . '//' . $el('Nm'));
                $movimenti[] = [
                    'data_operazione' => $dataOp,
                    'data_valuta' => self::data($dataVal) ? $dataVal : $dataOp,
                    'importo' => $imp,
                    'descrizione' => preg_replace('/\s+/u', ' ', $descr) ?? $descr,
                    'controparte' => $parte !== '' ? $parte : self::controparte($descr),
                    'riferimento' => $testo($n, './' . $el('NtryRef')) ?: $testo($n, './/' . $el('AcctSvcrRef')),
                    'codice_operazione' => self::codiceOperazione($xp, $n, $el),
                    'iban' => $ibanStmt,
                    'segno_incerto' => false,
                ];
            }
            // Controllo di quadratura: saldo iniziale + movimenti = saldo finale
            if (isset($saldi['OPBD'], $saldi['CLBD']) && abs($saldi['OPBD'] + $somma - $saldi['CLBD']) > 0.01) {
                $avvisi[] = 'Saldi non quadrati: ' . number_format($saldi['OPBD'], 2, ',', '.') . ' + movimenti ≠ ' . number_format($saldi['CLBD'], 2, ',', '.') . '.';
            }
        }
        if ($banca === '') $banca = self::riconosciBanca($xml);
        return ['banca' => $banca, 'iban' => $iban, 'metodo' => 'cbi', 'movimenti' => $movimenti, 'avvisi' => $avvisi];
    }

    /** BkTxCd: codice proprietario (es. 48//00), altrimenti dominio ISO (Domn/Cd-Fmly/Cd-SubFmlyCd). */
    private static function codiceOperazione(DOMXPath $xp, DOMNode $n, callable $el): string
    {
        $q = fn(string $path) => ($r = $xp->query($path, $n)) && $r->length ? trim((string)$r->item(0)->textContent) : '';
        $b = './' . $el('BkTxCd');
        $prtry = $q($b . '/' . $el('Prtry') . '/' . $el('Cd'));
        if ($prtry !== '') return $prtry;
        $parti = array_filter([$q($b . '/' . $el('Domn') . '/' . $el('Cd')), $q($b . '/' . $el('Domn') . '/' . $el('Fmly') . '/' . $el('Cd')),
            $q($b . '/' . $el('Domn') . '/' . $el('Fmly') . '/' . $el('SubFmlyCd'))]);
        return implode('-', $parti);
    }

    /**
     * Punto di estensione per l'estratto conto PDF di Centromarca Banca (per l'XML CBI c'è già parseXmlCbi).
     * Da scrivere se servirà importare anche i PDF, con un estratto di esempio: deve restituire i movimenti
     * nello stesso formato di parseRegole() (vedi normalizzaMovimento), oppure null per
     * lasciare il lavoro ai livelli successivi (AI, regole).
     * Cose da verificare sul PDF reale: ordine delle colonne (data contabile/valuta,
     * dare/avere o importo con segno), righe di descrizione su più linee, riporti di pagina.
     */
    public static function parseCentromarca(string $testo): ?array
    {
        return null;
    }

    /**
     * Estratto conto della carta di credito (CartaBCC / Numia: "DATA ACQUISTO  DATA REGISTR.  DESCRIZIONE  IMPORTO IN EURO").
     * Ogni riga comincia con due date; gli acquisti in valuta vanno a capo ("COGNITO-TEAM" / "39,00 USD" / "34,07"),
     * così la riga si chiude al primo importo in euro. Importi positivi = spese (addebiti), negativi = rimborsi.
     * Il "TOTALE OPERAZIONI" serve da controllo: se non torna, lo dice negli avvisi.
     * @param string[] $pages testo di ogni pagina, righe separate da \n
     * @return array{banca:string, iban:string, metodo:string, movimenti:array, avvisi:string[]}
     */
    public static function parseEstrattoCarta(array $pages): array
    {
        $testo = implode("\n", array_map('strval', $pages));
        $avvisi = [];
        $carta = preg_match('/CARTA\s+NUMERO\s*:?\s*(\d{4})[\s*X]+(\d{4})\b/i', $testo, $mc) ? "{$mc[1]} **** {$mc[2]}" : '';
        $emittente = preg_match('/NUMIA|CARTABCC/i', $testo) ? 'CartaBCC' : 'Carta';
        $banca = trim($emittente . ($carta !== '' ? ' ' . $carta : ''));

        $reImp = '-?\s?\d{1,3}(?:\.\d{3})*,\d{2}';
        $movimenti = [];
        $aperta = null; // riga in costruzione: [data acquisto, data registrazione, testo, valuta estera, righe lette]
        $chiudi = function (float $importo) use (&$aperta, &$movimenti) {
            $descr = trim(preg_replace('/\s+/', ' ', $aperta[2]) ?? $aperta[2]);
            if ($aperta[3] !== '') $descr .= ' (' . $aperta[3] . ')';
            $movimenti[] = ['data_operazione' => $aperta[0], 'data_valuta' => $aperta[1], 'importo' => round(-$importo, 2),
                'descrizione' => $descr, 'controparte' => mb_substr($descr, 0, 255, 'UTF-8'), 'segno_incerto' => false];
            $aperta = null;
        };
        $totale = null;
        foreach (preg_split('/\R/u', $testo) as $riga) {
            $riga = trim(preg_replace('/\s+/u', ' ', $riga) ?? $riga);
            if ($riga === '') continue;
            if (preg_match('/^TOTALE\s+OPERAZIONI\s+(' . $reImp . ')$/i', $riga, $m)) {
                $totale = self::importoIt($m[1]);
                $aperta = null;
                continue;
            }
            if (preg_match('/^(\d{2}\/\d{2}\/\d{4}) (\d{2}\/\d{2}\/\d{4})(?: (.*))?$/', $riga, $m)) {
                if ($aperta) $avvisi[] = "Riga del {$aperta[0]} senza importo: saltata.";
                $aperta = [self::data($m[1]), self::data($m[2]), '', '', 0];
                if (!$aperta[0]) { $aperta = null; continue; }
                $riga = $m[3] ?? '';
                if ($riga === '') continue;
            } elseif (!$aperta) {
                continue;
            }
            // Importo estero ("39,00 USD"), da solo o in coda al testo
            if (preg_match('/^(.*?)\s?(\d{1,3}(?:\.\d{3})*,\d{2}) ([A-Z]{3})$/', $riga, $m) && $m[3] !== 'EUR') {
                $aperta[3] = $m[2] . ' ' . $m[3];
                $aperta[2] .= ' ' . $m[1];
            } elseif (preg_match('/^(.*?)\s?(' . $reImp . ')$/', $riga, $m) && ($imp = self::importoIt($m[2])) !== null) {
                $aperta[2] .= ' ' . $m[1];
                $chiudi($imp);
                continue;
            } else {
                $aperta[2] .= ' ' . $riga;
            }
            if (++$aperta[4] > 4) { $avvisi[] = "Riga del {$aperta[0]} senza importo: saltata."; $aperta = null; }
        }

        if ($totale !== null) {
            $somma = -array_sum(array_column($movimenti, 'importo'));
            if (abs($somma - $totale) > 0.005) {
                $avvisi[] = 'Il totale delle operazioni lette (' . number_format($somma, 2, ',', '.') . ') non corrisponde al totale dell\'estratto ('
                    . number_format($totale, 2, ',', '.') . '): controlla le righe.';
            }
        } elseif ($movimenti) {
            $avvisi[] = 'Totale operazioni non trovato: impossibile verificare che tutte le righe siano state lette.';
        }
        return ['banca' => $banca, 'iban' => '', 'metodo' => 'carta', 'movimenti' => $movimenti, 'avvisi' => $avvisi];
    }

    /**
     * Il testo è un estratto della carta di credito (intestazione "DATA ACQUISTO ... DATA REGISTR." o emittente Numia/CartaBCC)?
     * Serve a non importarlo come estratto conto: le spese si sommerebbero all'addebito mensile già sul conto.
     */
    public static function eEstrattoCarta(string $testo): bool
    {
        return (bool)preg_match('/DATA\s+ACQUISTO\s+DATA\s+REGISTR/i', $testo)
            || (preg_match('/NUMIA|CARTA\s*BCC/i', $testo) && preg_match('/CARTA\s+NUMERO/i', $testo));
    }

    public static function riconosciBanca(string $testo): string
    {
        $note = ['Centromarca Banca' => '/CENTROMARCA/i', 'Intesa Sanpaolo' => '/INTESA\s*SANPAOLO/i', 'UniCredit' => '/UNICREDIT/i',
            'Banco BPM' => '/BANCO\s*BPM/i', 'BPER' => '/\bBPER\b/i', 'Crédit Agricole' => '/CR[EÉ]DIT\s*AGRICOLE/i',
            'Banca Sella' => '/BANCA\s*SELLA/i', 'Fineco' => '/FINECO/i', 'Monte dei Paschi' => '/PASCHI/i'];
        foreach ($note as $nome => $re) {
            if (preg_match($re, $testo)) return $nome;
        }
        return '';
    }

    /**
     * Parser generico: righe che iniziano con due date (operazione, valuta) e contengono un importo;
     * le righe successive senza data si accodano alla descrizione del movimento precedente.
     * @return array{movimenti:array, avvisi:string[]}
     */
    public static function parseRegole(string $testo): array
    {
        $avvisi = [];
        $testo = str_replace(["\r\n", "\r"], "\n", $testo);
        // Testo senza a capo (pagine unite con spazi): si va a capo prima di ogni coppia di date
        if (substr_count($testo, "\n") < 3) {
            $testo = preg_replace('/\s(?=' . self::RE_DATA . '\s+' . self::RE_DATA . '\s)/', "\n", $testo) ?? $testo;
        }
        $reRiga = '/^\s*(' . self::RE_DATA . ')\s+(' . self::RE_DATA . ')\s+(.*)$/u';

        $movimenti = [];
        $corrente = null;
        foreach (explode("\n", $testo) as $riga) {
            $riga = trim(preg_replace('/\s+/u', ' ', $riga) ?? $riga);
            if ($riga === '') continue;
            if (preg_match($reRiga, $riga, $m)) {
                if ($corrente) $movimenti[] = $corrente;
                $corrente = null;
                if (preg_match(self::RIGHE_DA_SALTARE, $m[3])) continue;
                $corrente = self::rigaMovimento($m[1], $m[2], $m[3]);
                continue;
            }
            if (preg_match(self::RIGHE_DA_SALTARE, $riga)) {
                // Riporto o intestazione: chiude il movimento in corso
                if ($corrente) { $movimenti[] = $corrente; $corrente = null; }
                continue;
            }
            if ($corrente) {
                // Movimento senza importo sulla prima riga: può arrivare su quella dopo
                if ($corrente['importo'] === null) {
                    $tmp = self::rigaMovimento($corrente['data_operazione'], (string)$corrente['data_valuta'], $corrente['descrizione'] . ' ' . $riga);
                    if ($tmp) { $corrente = $tmp; continue; }
                }
                $corrente['descrizione'] = trim($corrente['descrizione'] . ' ' . $riga);
            }
        }
        if ($corrente) $movimenti[] = $corrente;

        $out = [];
        foreach ($movimenti as $mv) {
            if ($mv['importo'] === null || abs($mv['importo']) < 0.005) continue;
            $mv['controparte'] = self::controparte($mv['descrizione']);
            $out[] = $mv;
        }
        $incerti = count(array_filter($out, fn($m) => $m['segno_incerto']));
        if ($incerti) {
            $avvisi[] = "$incerti movimenti senza segno certo (colonna dare/avere non leggibile): restano da verificare a mano.";
        }
        return ['movimenti' => $out, 'avvisi' => $avvisi];
    }

    /** Un movimento da data operazione, data valuta e resto della riga (descrizione + importi). */
    private static function rigaMovimento(string $dOp, string $dVal, string $resto): ?array
    {
        $dataOp = self::data($dOp);
        if (!$dataOp) return null;
        $importo = null;
        $segnoIncerto = false;
        $descrizione = $resto;
        if (preg_match_all('/(?<![\d.,])' . self::RE_IMPORTO . '/u', $resto, $mm, PREG_OFFSET_CAPTURE)) {
            $trovati = $mm[0];
            // Con più importi (es. dare/avere/saldo) il movimento è il primo: il saldo progressivo sta in coda
            $primo = $trovati[0][0];
            $importo = self::importoIt($primo);
            $conSegno = (bool)preg_match('/[+\-]/', $primo);
            foreach (array_reverse($trovati) as [$txt, $pos]) {
                $descrizione = substr_replace($descrizione, ' ', $pos, strlen($txt));
            }
            if ($importo !== null && !$conSegno) {
                [$segno, $certo] = self::segnoDaDescrizione($descrizione);
                // Senza indizi si assume addebito, ma il movimento non verrà abbinato in automatico
                $importo = abs($importo) * ($segno ?: -1);
                $segnoIncerto = !$certo;
            }
        }
        return [
            'data_operazione' => $dataOp,
            'data_valuta' => self::data($dVal) ?? $dataOp,
            'importo' => $importo !== null ? round($importo, 2) : null,
            'descrizione' => trim(preg_replace('/\s+/u', ' ', $descrizione) ?? $descrizione),
            'controparte' => null,
            'segno_incerto' => $segnoIncerto,
        ];
    }

    /**
     * Verso del movimento dalla descrizione: [+1 accredito | -1 addebito | 0 non si sa, certo].
     * Certo solo se ci sono parole forti di un solo verso.
     */
    public static function segnoDaDescrizione(string $descrizione): array
    {
        $d = ' ' . mb_strtoupper($descrizione, 'UTF-8') . ' ';
        $conta = function (array $parole) use ($d): int {
            $n = 0;
            foreach ($parole as $p) if (strpos($d, $p) !== false) $n++;
            return $n;
        };
        $piu = $conta(self::ACCREDITO_FORTI);
        $meno = $conta(self::ADDEBITO_FORTI);
        if ($piu && !$meno) return [1, true];
        if ($meno && !$piu) return [-1, true];
        if ($piu || $meno) return [$piu > $meno ? 1 : ($meno > $piu ? -1 : 0), false];
        $piu = $conta(self::ACCREDITO_DEBOLI);
        $meno = $conta(self::ADDEBITO_DEBOLI);
        return [$piu > $meno ? 1 : ($meno > $piu ? -1 : 0), false];
    }

    public static function controparte(string $descrizione): ?string
    {
        $re = '/\b(?:ORDINANTE|ORD\.?|BENEFICIARIO|BENEF\.?|A\s+FAVORE\s+DI|DA\s*:|A\s*:)\s*:?\s*([A-Z0-9][A-Z0-9&\'.,\- ]{2,70}?)(?=\s+(?:CRO|TRN|CAUSALE|CAUS\.?|RIF\.?|ID|INFO|IBAN|BIC|COD\.?|NOTPROVIDED|DATA)\b|\s*$)/iu';
        if (preg_match($re, $descrizione, $m)) {
            $c = trim($m[1], " .,-");
            return $c !== '' ? mb_substr($c, 0, 255, 'UTF-8') : null;
        }
        return null;
    }

    /** "1.234,56-" → -1234.56 ; "+ 10,00" → 10.0 */
    public static function importoIt(string $s): ?float
    {
        $s = str_replace(' ', '', trim($s));
        $neg = str_starts_with($s, '-') || str_ends_with($s, '-');
        $num = trim($s, '+-');
        if (!preg_match('/^\d{1,3}(?:\.\d{3})*,\d{2}$|^\d+,\d{2}$/', $num)) return null;
        $v = (float)str_replace(['.', ','], ['', '.'], $num);
        return $neg ? -$v : $v;
    }

    /** gg/mm/aa(aa) (anche con . o -) → AAAA-MM-GG, null se non valida. */
    public static function data(string $s): ?string
    {
        if (!preg_match('/^(\d{1,2})[\/.\-](\d{1,2})[\/.\-](\d{2}|\d{4})$/', trim($s), $m)) {
            return preg_match('/^\d{4}-\d{2}-\d{2}$/', trim($s)) ? trim($s) : null;
        }
        $y = strlen($m[3]) === 2 ? 2000 + (int)$m[3] : (int)$m[3];
        if (!checkdate((int)$m[2], (int)$m[1], $y)) return null;
        return sprintf('%04d-%02d-%02d', $y, (int)$m[2], (int)$m[1]);
    }

    /** Movimento letto dall'AI → formato interno (null se inutilizzabile). */
    public static function normalizzaMovimento(array $m): ?array
    {
        $dataOp = self::data((string)($m['data_operazione'] ?? ''));
        $importo = isset($m['importo']) && is_numeric($m['importo']) ? round((float)$m['importo'], 2) : null;
        if (!$dataOp || $importo === null || abs($importo) < 0.005) return null;
        $descr = trim((string)($m['descrizione'] ?? ''));
        return [
            'data_operazione' => $dataOp,
            'data_valuta' => self::data((string)($m['data_valuta'] ?? '')) ?? $dataOp,
            'importo' => $importo,
            'descrizione' => $descr,
            'controparte' => trim((string)($m['controparte'] ?? '')) ?: self::controparte($descr),
            'segno_incerto' => false,
        ];
    }

    public static function normalizzaDescrizione(string $d): string
    {
        $d = mb_strtoupper($d, 'UTF-8');
        $d = preg_replace('/[^A-Z0-9]+/u', ' ', $d) ?? $d;
        return trim(preg_replace('/\s+/', ' ', $d) ?? $d);
    }

    /**
     * Chiave di deduplica. Con il riferimento della banca (NtryRef/AcctSvcrRef del CBI):
     * sha256("ref|IBAN|riferimento|data_operazione|importo") — data e importo evitano di perdere movimenti
     * se la banca usa riferimenti progressivi che ripartono o fissi. Senza riferimento:
     * sha256(data_operazione|importo|descrizione normalizzata). Movimenti identici nello stesso file
     * ricevono "|#2", "|#3"... in ordine, così reimportando lo stesso estratto gli hash tornano uguali.
     */
    public static function hashRiga(array $m, int $occorrenza = 1): string
    {
        $dataImporto = $m['data_operazione'] . '|' . number_format((float)$m['importo'], 2, '.', '');
        if (!empty($m['riferimento'])) {
            $base = 'ref|' . ($m['iban'] ?? '') . '|' . $m['riferimento'] . '|' . $dataImporto;
        } else {
            $base = $dataImporto . '|' . self::normalizzaDescrizione((string)$m['descrizione']);
        }
        return hash('sha256', $occorrenza > 1 ? $base . '|#' . $occorrenza : $base);
    }

    /** Hash della prima versione (v057, solo riferimento): i movimenti già importati così contano come presenti. */
    public static function hashRigaV1(array $m): ?string
    {
        return !empty($m['riferimento']) ? hash('sha256', 'ref|' . ($m['iban'] ?? '') . '|' . $m['riferimento']) : null;
    }

    /** Avviso di pagamento: stesso file, data e totale = stesso avviso (reimport anche parziale). */
    public static function hashAvviso(string $fileNome, string $data, float $totale): string
    {
        return hash('sha256', 'avviso|' . mb_strtolower(trim($fileNome), 'UTF-8') . '|' . $data . '|' . number_format(abs($totale), 2, '.', ''));
    }

    /** Aggiunge hash_riga a ogni movimento, numerando i doppioni. */
    public static function conHash(array $movimenti): array
    {
        $visti = [];
        foreach ($movimenti as &$m) {
            $h = self::hashRiga($m);
            $visti[$h] = ($visti[$h] ?? 0) + 1;
            $m['hash_riga'] = $visti[$h] > 1 ? self::hashRiga($m, $visti[$h]) : $h;
        }
        unset($m);
        return $movimenti;
    }
}
