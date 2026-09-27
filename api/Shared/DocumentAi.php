<?php
/**
 * DocumentAi — lettura con Claude dei documenti che entrano nell'ERP:
 *   - lettere d'incarico dei clienti (es. Unindustria), in PDF
 *   - preventivi preparati con Cowork, in PDF, DOCX o testo
 *
 * Restituisce solo dati: il riconoscimento di cliente e sottocliente in anagrafica
 * lo fa AnagraficaMatcher, così il modello non deve conoscere il database.
 */
declare(strict_types=1);

require_once __DIR__ . '/ClaudeClient.php';

class DocumentAi
{
    private const SYSTEM = "Estrai dati strutturati da documenti commerciali italiani per il gestionale di "
        . "MV Consulting S.r.l., società di consulenza (privacy/GDPR, DPO, sicurezza, formazione). "
        . "Riporta solo ciò che il documento dice: se un dato manca, restituisci null e non dedurlo. "
        . "Importi in euro come numeri (5.000,00 → 5000), date in formato AAAA-MM-GG. "
        . "Per partita_iva, codice_fiscale ed email di cliente e sottocliente, se il dato manca usa la stringa vuota.";

    /** Lettera d'incarico ricevuta da un cliente. */
    public static function estraiIncarico(array $document): array
    {
        $instruction = <<<TXT
Il documento è una lettera d'incarico (o ordine) che un cliente invia a MV Consulting.
MV Consulting è il DESTINATARIO e il fornitore: non è mai il cliente.

Spesso il committente è Unindustria (associazione industriali) che affida a MV Consulting attività
da svolgere presso una sua azienda associata. In quel caso:
- cliente = chi commissiona e paga (Unindustria o altro ente committente)
- sottocliente = l'azienda presso cui si svolge l'attività ("presso ...", "azienda associata", "beneficiaria")
Se l'attività è per il committente stesso, il sottocliente è null.

Campi:
- numero_protocollo: il riferimento del committente, copiato esattamente. Due formati tipici:
  codice puntato come "SZ.DPS.F142.26" oppure "Prot. n. 1350/2026" (in quel caso restituisci "1350/2026").
  Non confonderlo con numeri di telefono, CAP, codici fiscali o partite IVA.
- data_incarico: la data della lettera o dell'incarico, non date di scadenza o di svolgimento.
- importo_totale: il compenso complessivo per MV Consulting, IVA esclusa. Se il documento indica
  una tariffa per giornata e il numero di giornate, restituisci il prodotto. Se l'importo è
  dichiarato IVA inclusa, riportalo al netto e scrivilo in note_estrazione.
- num_giornate: giornate, verifiche, audit, sopralluoghi o sessioni previsti (numero).
- tipo_commessa: "dpo" se l'incarico è il ruolo di DPO/Responsabile Protezione Dati;
  "formazione" se si tratta di corsi o formazione; "nis2" per la consulenza sulla direttiva NIS 2
  (D.Lgs. 138/2024, cybersicurezza); "ict" per la consulenza su sistemi informativi, reti e infrastruttura IT;
  "digital" per la consulenza digital (marketing digitale, social, siti, trasformazione digitale);
  "sviluppo_software" per sviluppo di software, applicazioni o gestionali; altrimenti "assistenza"
  (l'"assistenza annuale privacy" è assistenza, non DPO).
- descrizione: una riga che riassume l'attività.
- condizioni_pagamento: come e quando verrà pagato, se indicato (testo breve).
- giorni_pagamento: giorni di pagamento dalla fattura, se indicati (es. "30 gg d.f." → 30).
- note_estrazione: dubbi o ambiguità da far verificare a chi legge, altrimenti null.
TXT;
        return self::normalizzaSoggetti(ClaudeClient::extractJson(self::SYSTEM, $instruction, $document, [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'cliente', 'sottocliente', 'numero_protocollo', 'data_incarico', 'importo_totale',
                'num_giornate', 'tipo_commessa', 'descrizione', 'condizioni_pagamento',
                'giorni_pagamento', 'note_estrazione',
            ],
            'properties' => [
                'cliente' => self::soggetto(),
                'sottocliente' => ['anyOf' => [self::soggetto(), ['type' => 'null']]],
                'numero_protocollo' => self::nullable('string'),
                'data_incarico' => ['anyOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'null']]],
                'importo_totale' => self::nullable('number'),
                'num_giornate' => self::nullable('number'),
                'tipo_commessa' => ['type' => 'string', 'enum' => ['assistenza', 'dpo', 'formazione', 'nis2', 'ict', 'digital', 'sviluppo_software']],
                'descrizione' => self::nullable('string'),
                'condizioni_pagamento' => self::nullable('string'),
                'giorni_pagamento' => self::nullable('integer'),
                'note_estrazione' => self::nullable('string'),
            ],
        ]));
    }

    /** Preventivo che MV Consulting invia a un cliente (preparato con Cowork). */
    public static function estraiOfferta(array $document): array
    {
        $instruction = <<<TXT
Il documento è un preventivo/offerta che MV Consulting invia a un cliente.
MV Consulting è l'EMITTENTE: il cliente è il destinatario dell'offerta.

Campi:
- cliente: il destinatario dell'offerta.
- sottocliente: sede, reparto o azienda specifica a cui è destinato il servizio, se diversa dal cliente; altrimenti null.
- oggetto: titolo breve dell'offerta.
- descrizione: sintesi del servizio offerto (2-3 frasi).
- data_offerta: data del documento.
- validita_giorni: per quanti giorni vale l'offerta, se indicato.
- tipo_commessa: "dpo", "formazione", "nis2", "ict", "digital", "sviluppo_software" o "assistenza" (vedi natura del servizio).
- righe: le voci economiche dell'offerta, ciascuna con descrizione, quantità, unità (es. "giornate", "ore", "a corpo"),
  prezzo unitario e importo (IVA esclusa). Se c'è un solo prezzo complessivo, una sola riga.
  Non includere come righe l'IVA né i totali.
- imponibile: totale IVA esclusa.
- iva_percentuale: aliquota IVA indicata (22 se è scritto "+ IVA" senza aliquota; null se non se ne parla).
- num_giornate: giornate complessive previste, se indicate.
- condizioni_pagamento: il testo delle condizioni di pagamento.
- giorni_pagamento: giorni dalla data fattura (es. "bonifico a 30 gg d.f." → 30).
- piano_rate: le rate di fatturazione previste (es. acconto 30% all'accettazione, saldo a fine lavori),
  con percentuale sul totale e, se si può dire, dopo quanti giorni dall'accettazione si fattura
  (0 = all'accettazione). Se c'è un pagamento unico, una sola rata al 100%.
  Le percentuali devono sommare a 100 sull'intera offerta: se il documento le dà per fase o per voce
  (es. "Fase 1: 40% all'affidamento, 40% alla consegna, 20% all'avvio"), convertile sul totale
  (40% di una fase da 10.000 € su un'offerta da 20.000 € = 20%). In importo metti gli euro della rata
  (IVA esclusa), se ricavabili; altrimenti null.
- note_estrazione: dubbi o ambiguità da far verificare, altrimenti null.
TXT;
        return self::normalizzaSoggetti(ClaudeClient::extractJson(self::SYSTEM, $instruction, $document, [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => [
                'cliente', 'sottocliente', 'oggetto', 'descrizione', 'data_offerta', 'validita_giorni',
                'tipo_commessa', 'righe', 'imponibile', 'iva_percentuale', 'num_giornate',
                'condizioni_pagamento', 'giorni_pagamento', 'piano_rate', 'note_estrazione',
            ],
            'properties' => [
                'cliente' => self::soggetto(),
                'sottocliente' => ['anyOf' => [self::soggetto(), ['type' => 'null']]],
                'oggetto' => ['type' => 'string'],
                'descrizione' => self::nullable('string'),
                'data_offerta' => ['anyOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'null']]],
                'validita_giorni' => self::nullable('integer'),
                'tipo_commessa' => ['type' => 'string', 'enum' => ['assistenza', 'dpo', 'formazione', 'nis2', 'ict', 'digital', 'sviluppo_software']],
                'righe' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['descrizione', 'quantita', 'unita', 'prezzo_unitario', 'importo'],
                        'properties' => [
                            'descrizione' => ['type' => 'string'],
                            'quantita' => ['type' => 'number'],
                            'unita' => self::nullable('string'),
                            'prezzo_unitario' => ['type' => 'number'],
                            'importo' => ['type' => 'number'],
                        ],
                    ],
                ],
                'imponibile' => ['type' => 'number'],
                'iva_percentuale' => self::nullable('number'),
                'num_giornate' => self::nullable('number'),
                'condizioni_pagamento' => self::nullable('string'),
                'giorni_pagamento' => self::nullable('integer'),
                'piano_rate' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['descrizione', 'percentuale', 'importo', 'giorni_da_accettazione'],
                        'properties' => [
                            'descrizione' => ['type' => 'string'],
                            'percentuale' => ['type' => 'number'],
                            'importo' => self::nullable('number'),
                            'giorni_da_accettazione' => self::nullable('integer'),
                        ],
                    ],
                ],
                'note_estrazione' => self::nullable('string'),
            ],
        ]));
    }

    /** Movimenti di un estratto conto bancario (testo estratto dal PDF). */
    public static function estraiMovimentiBancari(string $testo): array
    {
        $instruction = <<<TXT
Il documento è (una parte di) un estratto conto bancario italiano di MV Consulting S.r.l.
Elenca TUTTI i movimenti, nell'ordine in cui compaiono. Non includere saldi iniziali/finali, riporti né totali.

Campi di ogni movimento:
- data_operazione: data contabile/operazione (AAAA-MM-GG).
- data_valuta: data valuta, se presente; altrimenti null.
- importo: numero con segno, positivo se entra sul conto (accredito, colonna Avere/Entrate),
  negativo se esce (addebito, colonna Dare/Uscite). 1.234,56 → 1234.56.
- descrizione: il testo completo del movimento, comprese le righe successive (causale, ordinante, CRO...).
- controparte: chi paga (per gli accrediti) o chi riceve (per gli addebiti), se si capisce; altrimenti null.
banca: il nome della banca che emette l'estratto, se indicato.
TXT;
        return ClaudeClient::extractJson(self::SYSTEM, $instruction, ['text' => $testo], [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['banca', 'movimenti'],
            'properties' => [
                'banca' => self::nullable('string'),
                'movimenti' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['data_operazione', 'data_valuta', 'importo', 'descrizione', 'controparte'],
                        'properties' => [
                            'data_operazione' => ['type' => 'string', 'format' => 'date'],
                            'data_valuta' => ['anyOf' => [['type' => 'string', 'format' => 'date'], ['type' => 'null']]],
                            'importo' => ['type' => 'number'],
                            'descrizione' => ['type' => 'string'],
                            'controparte' => self::nullable('string'),
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Proposta di categoria per movimenti bancari non riconosciuti (solo proposte: conferma l'utente).
     * $movimenti: [{id, data_valuta, importo, descrizione, controparte}], $categorie: [{nome, tipo}]
     */
    public static function proponiCategorie(array $movimenti, array $categorie): array
    {
        $righe = array_map(fn($m) => ['id' => (int)$m['id'], 'data' => $m['data_valuta'], 'importo' => (float)$m['importo'],
            'causale' => (string)$m['descrizione'], 'controparte' => $m['controparte']], $movimenti);
        $entrate = implode(', ', array_map(fn($c) => $c['nome'], array_filter($categorie, fn($c) => $c['tipo'] === 'entrata')));
        $uscite = implode(', ', array_map(fn($c) => $c['nome'], array_filter($categorie, fn($c) => $c['tipo'] === 'uscita')));
        $instruction = <<<TXT
Il documento è un elenco JSON di movimenti del conto corrente di MV Consulting (società di consulenza).
Per ognuno proponi la categoria più adatta, scegliendo SOLO tra queste (usa il nome esatto):
- importo positivo (entrate): $entrate
- importo negativo (uscite): $uscite
Se la causale non basta per decidere con ragionevole sicurezza, categoria = null.
motivo: una frase breve che spiega la scelta (es. "F24: pagamento imposte", "abbonamento software").
TXT;
        return ClaudeClient::extractJson(self::SYSTEM, $instruction,
            ['text' => json_encode($righe, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE)], [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['proposte'],
            'properties' => [
                'proposte' => [
                    'type' => 'array',
                    'items' => [
                        'type' => 'object',
                        'additionalProperties' => false,
                        'required' => ['id', 'categoria', 'motivo'],
                        'properties' => [
                            'id' => ['type' => 'integer'],
                            'categoria' => self::nullable('string'),
                            'motivo' => ['type' => 'string'],
                        ],
                    ],
                ],
            ],
        ]);
    }

    /**
     * Testo leggibile da un .docx (Word, anche esportato da Cowork), senza librerie esterne.
     * @throws RuntimeException se il file non è un docx valido
     */
    public static function testoDaDocx(string $path): string
    {
        if (!class_exists('ZipArchive')) {
            throw new RuntimeException('Estensione zip non disponibile: carica il preventivo in PDF');
        }
        $zip = new ZipArchive();
        if ($zip->open($path) !== true) {
            throw new RuntimeException('File Word non leggibile');
        }
        $xml = $zip->getFromName('word/document.xml');
        $zip->close();
        if ($xml === false) {
            throw new RuntimeException('File Word non valido');
        }
        // Paragrafi, righe di tabella e celle diventano separatori leggibili
        $xml = preg_replace(['#</w:p>#', '#</w:tr>#', '#</w:tc>#', '#<w:tab/>#', '#<w:br[^>]*/>#'], ["\n", "\n", ' | ', "\t", "\n"], $xml);
        $text = html_entity_decode(strip_tags((string)$xml), ENT_QUOTES | ENT_XML1, 'UTF-8');
        return trim(preg_replace("/\n{3,}/", "\n\n", $text) ?? $text);
    }

    /**
     * I dati facoltativi del soggetto sono stringhe (vuote se mancano) e non nullable:
     * l'API accetta al massimo 16 campi con anyOf per schema, e il preventivo ne avrebbe 17.
     */
    private static function soggetto(): array
    {
        return [
            'type' => 'object',
            'additionalProperties' => false,
            'required' => ['nome', 'partita_iva', 'codice_fiscale', 'email'],
            'properties' => [
                'nome' => ['type' => 'string'],
                'partita_iva' => ['type' => 'string'],
                'codice_fiscale' => ['type' => 'string'],
                'email' => ['type' => 'string'],
            ],
        ];
    }

    /** Riporta a null le stringhe vuote di cliente e sottocliente, come si aspettano i controller. */
    private static function normalizzaSoggetti(array $dati): array
    {
        foreach (['cliente', 'sottocliente'] as $chiave) {
            if (!is_array($dati[$chiave] ?? null)) {
                continue;
            }
            foreach (['partita_iva', 'codice_fiscale', 'email'] as $campo) {
                if (trim((string)($dati[$chiave][$campo] ?? '')) === '') {
                    $dati[$chiave][$campo] = null;
                }
            }
        }
        return $dati;
    }

    private static function nullable(string $type): array
    {
        return ['anyOf' => [['type' => $type], ['type' => 'null']]];
    }
}
