<?php
/**
 * IncaricoPdfParser — lettura a regole (regex) di una lettera d'incarico dal testo estratto da pdf.js.
 *
 * È il metodo di riserva: la via principale è DocumentAi::estraiIncarico, che legge il PDF con Claude.
 * Si usa quando ANTHROPIC_API_KEY non è configurata o il servizio AI non risponde.
 * Il riconoscimento di cliente e sottocliente sta in AnagraficaMatcher.
 */
declare(strict_types=1);

class IncaricoPdfParser
{
    /**
     * @param string[] $pages testo di ciascuna pagina
     * @return array{data_incarico: ?string, importo_totale: float|int, num_giornate: float|int, tipo_commessa: string, numero_protocollo: ?string, full_text: string}
     */
    public static function parse(array $pages): array
    {
        $fullText = implode(' ', $pages);
        $fullText = preg_replace('/\s+/', ' ', $fullText);

        // ─── Normalizzazione testo PDF ───
        // pdf.js spesso spezza numeri e parole con spazi interni
        // Es: "€ 5 . 000 , 00" → "€ 5.000,00", "ver ifiche" → "verifiche", "N r." → "Nr."

        // 1. Rimuovi spazi attorno a . e , tra cifre: "5 . 000 , 00" → "5.000,00"
        $fullText = preg_replace('/(\d)\s*\.\s*(\d)/', '$1.$2', $fullText);
        $fullText = preg_replace('/(\d)\s*,\s*(\d)/', '$1,$2', $fullText);

        // 2. Rimuovi spazi tra cifre adiacenti causati da splitting: "5 000" → "5000" (solo quando preceduto da € o "euro")
        // Ma attenzione a non unire date o altri numeri — lo facciamo solo in contesto monetario
        $fullText = preg_replace('/€\s*(\d+)\s+(\d{3})\b/', '€ $1$2', $fullText);

        // 3. Ricomponi parole comuni spezzate da pdf.js
        $brokenWords = [
            '/\bver\s+ifich/i' => 'verifich',
            '/\bgiorn\s+at/i' => 'giornat',
            '/\bgiorn\s+o\b/i' => 'giorno',
            '/\bgiorn\s+i\b/i' => 'giorni',
            '/\bN\s+r\s*\./i' => 'Nr.',
            '/\bN\s+r\s+(\d)/i' => 'Nr. $1',
            '/\bsopral\s+luogh/i' => 'sopralluogh',
            '/\bispez\s+ion/i' => 'ispezion',
            '/\binter\s+vent/i' => 'intervent',
            '/\bsess\s+ion/i' => 'session',
            '/\bcompen\s+so\b/i' => 'compenso',
            '/\bimport\s+o\b/i' => 'importo',
            '/\bcorri\s+spettiv/i' => 'corrispettiv',
            '/\bonor\s+ario/i' => 'onorario',
            '/\bpre\s+vist/i' => 'previst',
            '/\bformaz\s+ione/i' => 'formazione',
            '/\bassist\s+enza/i' => 'assistenza',
            '/\bcinque\s*mila\b/i' => 'cinquemila',
        ];
        foreach ($brokenWords as $pattern => $replacement) {
            $fullText = preg_replace($pattern, $replacement, $fullText);
        }

        $textLower = mb_strtolower($fullText, 'UTF-8');

        $extracted = [
            'data_incarico' => null,
            'importo_totale' => 0,
            'num_giornate' => 0,
            'tipo_commessa' => 'assistenza',
            'numero_protocollo' => null,
            '_debug_text' => mb_substr(trim($fullText), 0, 2000, 'UTF-8'), // debug: per vedere il testo estratto
            '_debug_importo_candidates' => [],
            '_debug_giornate_candidates' => []
        ];

        // ─── Estrai data (DD/MM/YYYY, DD-MM-YYYY, DD.MM.YYYY o YYYY-MM-DD) ───
        if (preg_match('/(\d{2}[\/.\\-]\d{2}[\/.\\-]\d{4})/', $fullText, $m)) {
            $parts = preg_split('/[\\/\\.\\-]/', trim($m[1]));
            if (count($parts) === 3 && strlen($parts[2]) === 4) {
                $extracted['data_incarico'] = $parts[2] . '-' . $parts[1] . '-' . $parts[0];
            }
        } elseif (preg_match('/(\d{4}[\-]\d{2}[\-]\d{2})/', $fullText, $m)) {
            $extracted['data_incarico'] = $m[1];
        }

        // ─── Estrai importo — strategia multi-pattern migliorata ───
        // Helper: converte stringa importo italiano in float
        // "5.000,00" → 5000.00, "5.000" → 5000, "5000" → 5000, "5,50" → 5.50
        $parseImporto = function($str) {
            // Separatore migliaia a spazio (anche NBSP / spazio stretto): "5 000,00" → "5000,00"
            $str = preg_replace('/[\s\x{00A0}\x{202F}]+/u', '', $str) ?? trim($str);
            // Se contiene sia . che , → il punto è separatore migliaia, la virgola decimali
            if (strpos($str, '.') !== false && strpos($str, ',') !== false) {
                return (float)str_replace(['.', ','], ['', '.'], $str);
            }
            // Se contiene solo . → potrebbe essere migliaia (es. "5.000") o decimale (es. "5.50")
            if (strpos($str, '.') !== false) {
                // Se dopo il punto ci sono 3 cifre → separatore migliaia
                if (preg_match('/\.(\d{3})(?:\D|$)/', $str)) {
                    return (float)str_replace('.', '', $str);
                }
                return (float)$str;
            }
            // Se contiene solo , → decimale
            if (strpos($str, ',') !== false) {
                return (float)str_replace(',', '.', $str);
            }
            return (float)$str;
        };

        // Raccogli tutti gli importi candidati e prendi il maggiore
        $importoCandidates = [];
        $debugImporto = [];

        // Pattern 1: Keyword + importo formattato ("compenso di € 5.000,00", "compenso di Euro 5.000")
        // (fino a 40 caratteri tra la parola chiave e la cifra: "compenso complessivo è di € 2.400,00")
        $keywordCandidates = [];
        if (preg_match_all('/(?:compenso|importo|corrispettivo|onorario|costo|pari\s+a)[^€\d]{0,40}?(?:€|euro|eur\.?)?\s*([0-9]{1,3}(?:[.\s]\d{3})*(?:[,]\d{1,2})?)(?:\s*(?:euro|€))?/i', $fullText, $matches)) {
            foreach ($matches[1] as $m) {
                $val = $parseImporto($m);
                $debugImporto[] = ['pattern' => 'P1-keyword', 'raw' => $m, 'parsed' => $val, 'accepted' => $val >= 100];
                if ($val >= 100) { $importoCandidates[] = $val; $keywordCandidates[] = $val; }
            }
        }

        // Pattern 2: € o "Euro" seguito da importo ("€ 5.000,00", "Euro 5.000", "€5000")
        if (preg_match_all('/(?:€|euro|eur\.?)\s*([0-9]{1,3}(?:[.\s]\d{3})*(?:[,]\d{1,2})?)/i', $fullText, $matches)) {
            foreach ($matches[1] as $m) {
                $val = $parseImporto($m);
                $debugImporto[] = ['pattern' => 'P2-euro-prefix', 'raw' => $m, 'parsed' => $val, 'accepted' => $val >= 100];
                if ($val >= 100) $importoCandidates[] = $val;
            }
        }

        // Pattern 3: Importo seguito da € o euro ("5.000,00 €", "5.000 euro")
        if (preg_match_all('/([0-9]{1,3}(?:[.\s]\d{3})*(?:[,]\d{1,2})?)\s*(?:€|euro)/i', $fullText, $matches)) {
            foreach ($matches[1] as $m) {
                $val = $parseImporto($m);
                $debugImporto[] = ['pattern' => 'P3-euro-suffix', 'raw' => $m, 'parsed' => $val, 'accepted' => $val >= 100];
                if ($val >= 100) $importoCandidates[] = $val;
            }
        }

        // Pattern 4: "totale" seguito da importo ("totale 5.000,00", "totale Euro 5.000")
        if (preg_match_all('/totale[:\s]*(?:€|euro|eur\.?)?\s*([0-9]{1,3}(?:[.\s]\d{3})*(?:[,]\d{1,2})?)/i', $fullText, $matches)) {
            foreach ($matches[1] as $m) {
                $val = $parseImporto($m);
                $debugImporto[] = ['pattern' => 'P4-totale', 'raw' => $m, 'parsed' => $val, 'accepted' => $val >= 100];
                if ($val >= 100) $importoCandidates[] = $val;
            }
        }

        // Pattern 5 (fallback): importo con formato italiano >= 100 vicino a contesto monetario
        if (empty($importoCandidates)) {
            if (preg_match_all('/([0-9]{1,3}(?:\.\d{3})+(?:[,]\d{1,2})?)/i', $fullText, $matches)) {
                foreach ($matches[1] as $m) {
                    $val = $parseImporto($m);
                    $debugImporto[] = ['pattern' => 'P5-fallback', 'raw' => $m, 'parsed' => $val, 'accepted' => $val >= 100];
                    if ($val >= 100) $importoCandidates[] = $val;
                }
            }
        }

        $extracted['_debug_importo_candidates'] = $debugImporto;

        // Prendi l'importo massimo tra i candidati (il più probabile per un contratto)
        // I prezzi reali degli incarichi sono sempre in centinaia o migliaia di euro
        // Gli importi accanto a "compenso/corrispettivo" vincono sugli altri: il massimo di tutto
        // il documento prendeva cifre estranee (fatturati, massimali assicurativi)
        if (!empty($keywordCandidates)) {
            $extracted['importo_totale'] = max($keywordCandidates);
        } elseif (!empty($importoCandidates)) {
            $extracted['importo_totale'] = max($importoCandidates);
        }

        // ─── Estrai numero giornate / verifiche / audit ───
        // Raccogli tutti i candidati e prendi il maggiore
        $giornCandidates = [];
        $debugGiornate = [];

        // Pattern prioritario: "sono previste N ..." (es. "sono previste nr. 8 verifiche", "sono previste n. 3 giornate")
        if (preg_match_all('/sono\s+previst[eio]\s+(?:(?:nr|n|num|numero)\\.?\s*)?(?:complessiv(?:amente|e)\s+)?(\d+(?:[.,]\d+)?)/i', $fullText, $matches)) {
            foreach ($matches[1] as $m) {
                $val = (float)str_replace(',', '.', $m);
                $debugGiornate[] = ['pattern' => 'sono-previste', 'raw' => $m, 'parsed' => $val];
                $giornCandidates[] = $val;
            }
        }

        // Pattern diretto: "8 verifiche", "12 giornate", "3 audit"
        if (preg_match_all('/(\d+(?:[.,]\d+)?)\s*(?:giornat[ae]|gg(?!\s*(?:d\.?\s*f|data|dalla|fine|df))|verifich[ae]|verifica|audit|sopralluogh?[io]|interventi|sessioni|ispezioni)/i', $fullText, $matches)) {
            foreach ($matches[1] as $m) {
                $val = (float)str_replace(',', '.', $m);
                $debugGiornate[] = ['pattern' => 'N-keyword', 'raw' => $m, 'parsed' => $val];
                $giornCandidates[] = $val;
            }
        }
        // Pattern inverso: "n. 8 verifiche" o "numero 8 verifiche"
        if (preg_match_all('/(?:n\.?|num\.?|numero|nr\.?)\s*(\d+)\s*(?:verifich[ae]|verifica|giornat[ae]|audit|sopralluogh?[io]|interventi|sessioni)/i', $fullText, $matches)) {
            foreach ($matches[1] as $m) {
                $val = (float)$m;
                $debugGiornate[] = ['pattern' => 'n-N-keyword', 'raw' => $m, 'parsed' => $val];
                $giornCandidates[] = $val;
            }
        }

        $extracted['_debug_giornate_candidates'] = $debugGiornate;
        if (!empty($giornCandidates)) {
            $extracted['num_giornate'] = max($giornCandidates);
        }

        // ─── Rileva tipo commessa ───
        // PRIORITÀ 1: "Assistenza annuale privacy" → tipo assistenza (NON dpo)
        $isAssistenzaPrivacy = (mb_strpos($textLower, 'assistenza annuale privacy') !== false
            || mb_strpos($textLower, 'assistenza privacy') !== false
            || mb_strpos($textLower, 'assistenza annuale') !== false);

        if ($isAssistenzaPrivacy) {
            $extracted['tipo_commessa'] = 'assistenza';
        } else {
            // Solo keyword fortemente specifiche per DPO (non parole generiche come "verifiche" o "regolamento")
            $dpoStrongKeywords = ['dpo', 'data protection', 'protezione dati', 'gdpr', 'reg. ue 2016/679', 'regolamento ue 2016'];
            $isDpo = false;
            foreach ($dpoStrongKeywords as $kw) {
                if (mb_strpos($textLower, $kw) !== false) {
                    $isDpo = true;
                    break;
                }
            }
            // "privacy" con contesto specifico DPO (non generico)
            if (!$isDpo && mb_strpos($textLower, 'privacy') !== false) {
                // Solo se accompagnato da altri indicatori DPO
                if (mb_strpos($textLower, 'responsabile') !== false || mb_strpos($textLower, 'incaricato') !== false
                    || mb_strpos($textLower, 'trattamento') !== false || mb_strpos($textLower, 'titolare') !== false) {
                    $isDpo = true;
                }
            }

            if (preg_match('/\bnis\s?2\b|138\/2024/u', $textLower)) {
                $extracted['tipo_commessa'] = 'nis2';
            } elseif ($isDpo) {
                $extracted['tipo_commessa'] = 'dpo';
            } elseif (strpos($textLower, 'formazione') !== false || strpos($textLower, 'corso') !== false || strpos($textLower, 'training') !== false) {
                $extracted['tipo_commessa'] = 'formazione';
            } elseif (strpos($textLower, 'assistenza') !== false || strpos($textLower, 'consulenza') !== false) {
                $extracted['tipo_commessa'] = 'assistenza';
            } else {
                $extracted['tipo_commessa'] = 'altro';
            }
        }

        // ─── Estrai numero protocollo ───
        // PRIORITÀ 1: Codice alfanumerico con punti (es. SZ.DPS.F142.26)
        // Pattern: almeno 2 segmenti separati da punto, con lettere e/o cifre,
        // tipicamente nel formato XX.YYY.ZZZZ.NN
        if (preg_match('/\b([A-Z]{1,5}\.[A-Z]{2,5}\.[A-Z0-9]{2,10}(?:\.[A-Z0-9]{1,6})*)\b/i', $fullText, $mAlpha)) {
            $extracted['numero_protocollo'] = strtoupper(trim($mAlpha[1]));
        }
        // PRIORITÀ 2 (fallback): formato numerico "Prot. n. 1350/2026"
        if (!$extracted['numero_protocollo'] && preg_match('/Prot\.?\s*n\.?\s*(\d+\s*\/\s*\d{4})/i', $fullText, $mProt)) {
            $extracted['numero_protocollo'] = preg_replace('/\s+/', '', trim($mProt[1]));
        }

        $extracted['full_text'] = $fullText;
        return $extracted;
    }
}
