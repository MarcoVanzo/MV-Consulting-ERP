# Indicatori — una sola definizione per ogni numero

Tutti i numeri di sintesi dell'ERP si calcolano in `api/Shared/Indicatori.php`. Le schede
(Fatture, Commesse, Verifica pagamenti, Offerte) e l'endpoint `indicatori/riepilogo` leggono da
lì. Se serve un numero nuovo, si aggiunge lì con la sua definizione qui sotto e un caso in
`tests/indicatori_cli.php`, invece di scrivere un'altra query in un controller.

## Due ambiti

| Ambito | Quando | Cosa conta |
|---|---|---|
| **Periodo** | schede con il selettore anno | documenti emessi, commesse acquisite, offerte fatte in quell'anno |
| **Situazione** | dashboard, scadenzario | tutto ciò che è aperto oggi, di qualunque anno |

Il filtro «Clienti nel conteggio» della scheda Fatture vale solo per l'ambito periodo: nella
situazione di oggi gli incassi aperti si vedono tutti.

## Definizioni

| Indicatore | Definizione | Base |
|---|---|---|
| Fatturato | somma dell'imponibile delle fatture emesse (note di credito sottratte) | netto IVA |
| Incassato (fatture) | fatture con stato `pagata` | IVA inclusa |
| Da incassare | fatture non `pagata` | IVA inclusa |
| Scaduto | da incassare con `data_scadenza` passata (non dipende dal cron che mette `scaduta`) | IVA inclusa |
| Numero di fatture | documenti distinti per numero + anno + cliente: una fattura divisa tra più sottoclienti conta una volta | — |
| Valore commesse | somma di `incarichi.importo_totale` | netto IVA |
| Fatturato commesse | imponibile delle fatture collegate alla commessa | netto IVA |
| Incassato commesse | imponibile delle fatture collegate e pagate | netto IVA |
| Da fatturare (commesse) | valore della commessa non ancora coperto da fatture, mai negativo | netto IVA |
| Rate da fatturare | rate senza fattura con data prevista entro l'orizzonte (le rate senza data contano sempre) | netto IVA |
| Pipeline | offerte in stato `inviata` (le bozze sono a parte: non le ha ancora viste nessuno) | netto IVA |
| Pipeline pesata | somma di valore × probabilità di lead, bozze e inviate; probabilità dell'offerta o quella dello stato (lead 10%, bozza 30%, inviata 50%, `Indicatori::PROBABILITA`) | netto IVA |
| Conversione | accettate / (accettate + rifiutate + scadute) | % |
| Partner da pagare | fatture passive `da_pagare`; `importo_totale` è già il netto a pagare dopo la ritenuta | IVA inclusa |

«Incassato» ha due basi volutamente diverse: nelle fatture è quello che il cliente ha pagato
(IVA inclusa), nelle commesse è l'imponibile, per confrontarlo col valore della commessa.
Le etichette nelle schede dicono sempre quale delle due si sta guardando.

## Stato «pagata»

Una fattura è pagata quando la riconciliazione la abbina a un movimento bancario, oppure quando
la si segna a mano (fatture vecchie, pagamenti fuori dal conto). La via normale è la
riconciliazione: una fattura abbinata non si può rimettere a «non pagata» senza annullare
l'abbinamento.
