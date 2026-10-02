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
| Fatturato (vista Fatture) | lo stesso con l'IVA (`totale`): in Fatture tutti i numeri sono IVA inclusa, l'imponibile è nel sottotitolo | IVA inclusa |
| Incassato (fatture) | fatture con stato `pagata` | IVA inclusa |
| Da incassare | fatture non `pagata` | IVA inclusa |
| Scaduto | da incassare con `data_scadenza` passata (non dipende dal cron che mette `scaduta`) | IVA inclusa |
| Numero di fatture | documenti distinti per numero + anno + cliente + segno: una fattura divisa tra più sottoclienti conta una volta, una nota di credito con lo stesso numero è un documento a parte | — |
| Scaduto (per cliente) | non supera mai quanto resta da incassare a quel cliente: una nota di credito aperta lo riduce. Una nota di credito che storna per intero fatture aperte le chiude all'import (DatiFattureCollegate) | IVA inclusa |
| Valore commesse | somma di `incarichi.importo_totale` | netto IVA |
| Fatturato commesse | imponibile delle fatture collegate alla commessa | netto IVA |
| Fatturato senza commessa | fatturato dell'anno (stessa definizione e stessi clienti esclusi di «Fatturato») delle fatture non collegate a una commessa: è il motivo per cui «Fatturato su commesse» in Vendite non coincide con l'imponibile del «Fatturato» in Fatture, insieme alle date (commessa dell'anno vs fattura dell'anno) | netto IVA |
| Incassato commesse | imponibile delle fatture collegate e pagate (non più mostrato in Vendite, che si ferma alla fattura) | netto IVA |
| Margine previsto (Vendite) | valore delle commesse dell'anno − costi partner previsti (`CommessaService::margini`) | netto IVA |
| Da fatturare (commesse) | valore della commessa non ancora coperto da fatture, mai negativo | netto IVA |
| Rate da fatturare | rate senza fattura con data prevista entro l'orizzonte (le rate senza data contano sempre), escluse quelle di commesse già fatturate per intero | netto IVA |
| Pipeline | offerte in stato `inviata` (le bozze sono a parte: non le ha ancora viste nessuno) | netto IVA |
| Pipeline pesata | somma di valore × probabilità di lead, bozze e inviate; probabilità dell'offerta o quella dello stato (lead 10%, bozza 30%, inviata 50%, `Indicatori::PROBABILITA`) | netto IVA |
| Conversione | accettate / (accettate + rifiutate + scadute), contando solo offerte davvero proposte: fuori le offerte registrate insieme a una commessa (origine `rapida`) e i lead persi senza essere mai stati inviati | % |
| Partner da pagare | fatture passive `da_pagare`; `importo_totale` è già il netto a pagare dopo la ritenuta. In Oggi: lo stesso elenco dello scadenzario (i back-to-back aspettano l'incasso del cliente) | IVA inclusa |
| Incassi attesi (Oggi) | per settimana: fatture aperte alla scadenza (IVA inclusa) + rate da fatturare alla data prevista + giorni di pagamento, con l'IVA dell'offerta (22% se manca); una rata con data passata va nella settimana corrente | IVA inclusa |
| Trasferte: rimborso km | km × costo ACI del mezzo (`mezzi.costo_km`), altrimenti × costo al km generale | € |
| Trasferte: indennità | per giornata con cliente (TrasferteRegole): vitto e alloggio pagati, da chiunque, la riducono | € |
| Trasferte: da rimborsare | rimborso km + indennità + spese pagate di tasca propria; le spese con carta aziendale o bonifico le ha già pagate la società | € |

Ogni vista guarda una fase sola: Vendite arriva fino alla fattura (valore, fatturato, da fatturare,
margine previsto, tutto netto IVA); gli incassi si leggono solo in Fatture, dove tutti i numeri sono IVA inclusa. Così lo stesso nome non
compare in due viste con due numeri diversi.

## Stato «pagata»

Una fattura è pagata quando la riconciliazione la abbina a un movimento bancario, oppure quando
la si segna a mano (fatture vecchie, pagamenti fuori dal conto). La via normale è la
riconciliazione: una fattura abbinata non si può rimettere a «non pagata» senza annullare
l'abbinamento.
