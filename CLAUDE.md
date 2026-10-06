# CLAUDE.md — MV Consulting ERP

> Regole permanenti per Claude su questo repo. Le regole globali (`~/.claude/CLAUDE.md`)
> valgono sempre; questo file specializza.

## Cos'è
Gestionale MV Consulting (ibrido CRM/ERP): **PHP 8.2** + frontend statico (`index.html` + `js/` + `css/`),
API in `api/`. Repo GitHub `MV-Consulting-ERP`, **pubblico**: niente dati reali di clienti nei file
di test o di esempio, niente output delle API nei log dei workflow.

## Leggi prima di operare
- `.env.example` per le variabili attese.
- `docs/indicatori.md` — definizione unica dei numeri (fatturato, scaduto, da incassare…).

## Deploy — un solo percorso
`.github/workflows/deploy-ftp.yml`, su `push` a `main` o `workflow_dispatch`:
**test** (`test.yml`: `php -l` + `tests/*_cli.php`) → **FTPS** (`SamKirkland/FTP-Deploy-Action`) →
**controllo di salute** su `api/health.php`. Se i test falliscono non si pubblica nulla.
Secret: `FTP_SERVER`, `FTP_USERNAME`, `FTP_PASSWORD`, `FTP_PATH`. Nessun rollback automatico: si torna
indietro con un revert su `main`.

```bash
gh run list --workflow deploy-ftp.yml --limit 1 && gh run watch <id>
```

Test in locale (PHP non è installato sul Mac):
```bash
docker run --rm -v "$PWD":/app -w /app php:8.2-cli sh -c 'for t in tests/*_cli.php; do php $t || exit 1; done'
```
(`lista_fatture_cli.php` richiede l'estensione zip, assente nell'immagine base: in CI c'è.)

Migrazioni DB: partono **da sole a ogni deploy**, subito dopo l'FTP (step di `deploy-ftp.yml`, secret
`DEPLOY_KEY`); il controllo di salute fallisce se ne resta qualcuna (`api/health.php` → `migrazioni_pendenti`).
Per rilanciarle a mano: workflow `migrazione.yml`. L'elenco sta in `api/Shared/migrazioni.php` (lo usano
`api/migrate.php` e `health.php`): si aggiunge **solo in coda**, la versione è la posizione; una migrazione già
applicata non si modifica (si aggiunge una nuova in coda). Un `MODIFY ... ENUM(...)` mette i **valori nuovi sempre in
fondo all'elenco**: inserirli in mezzo (come v104–v105, `viaggio`/`noleggio` prima di `altro`) cambia la posizione dei
valori esistenti e obbliga MySQL a ricopiare la tabella, invece della modifica istantanea. Devono essere
**additive e ripetibili** (il codice vecchio gira ancora per qualche secondo; in CI `test.yml` le lancia due volte
su MySQL 8 e la seconda non deve applicare niente).
Backup: `.github/workflows/backup.yml` ogni notte; per migrazioni rischiose lancialo a mano prima.
Segreti in `.env` / `.env.deploy` **non tracciati** — non committarli.
**Avvisami quando il deploy finisce** (`gh run watch`/`gh run list` in background).

## Note
- Cartelle `tmp_*` (`tmp_pdf_parse`, `tmp_root_redirect`, `tmp_venv`) sono di lavoro: non versionare artefatti.
- **Numeri di sintesi solo da `api/Shared/Indicatori.php`** (definizioni in `docs/indicatori.md`,
  prova `php tests/indicatori_cli.php`). Endpoint per la dashboard: `indicatori/riepilogo`.
- **File importati**: il router passa ogni import (azioni in `ArchivioImport::AZIONI`) ad
  `api/Shared/ArchivioImport.php`, che salva l'originale in `storage/import/<sha256>.<ext>` e lo
  registra in `import_file` (v077). Un import nuovo va aggiunto a `AZIONI` e il frontend manda il file
  nel campo `originale` quando al server arriva solo il testo estratto con pdf.js.
- **Viste** (dal 28/09/2026): sei voci di menu, ognuna con le sue schede e senza sovrapposizioni —
  Oggi (dashboard, `js/modules/oggi.js` su `indicatori/oggi`, sotto le scadenze nel dettaglio di `commerciale.js`),
  Vendite (offerte, commesse con margine previsto: si ferma alla fattura, niente incassi), Fatture (id vista `incassi`: emesse e ricevute, numeri tutti IVA inclusa), Banca (movimenti, da classificare,
  andamento), Trasferte (viaggi, mezzi), Anagrafiche (clienti e prospect, partner e fornitori).
  Schede generiche `.vtabs/.vtab/.vpane` (`UI.initVtabs`), anno unico `UI.anno()` (selettori `.sel-anno`),
  router in `app.js` (tabella `CARICA`, indirizzo `#vista/scheda`). Stili in `css/viste.css`; su telefono il menu
  diventa una barra in basso e le tabelle `.tabella-schede` diventano schede (etichette da `UI.initTabelleSchede`, titolo `.cella-titolo`). Niente `prompt()`: `UI.chiedi()` (finestra con calendario).
- **Vendite** (dal 28/09/2026): il **lead** è un'offerta in stato `lead` (valore stimato, fonte, probabilità,
  prossima azione con data in `data_followup`); «Prepara l'offerta» la porta in bozza. **Ogni commessa nasce da
  un'offerta**: una commessa creata a mano o da lettera d'incarico registra un'offerta già accettata
  (`CommessaService::offertaRapida`, origine `rapida`). Scheda cliente 360° (`js/modules/scheda.js`,
  `SchedaClienteController`): referenti (v080), note datate (`attivita`, v081) e storico che unisce le note agli
  eventi di offerte, commesse e fatture senza duplicarli. Prova: `php tests/vendite_cli.php`.
  Attenzione agli alias SQL: `lead` è riservato in MySQL 8 (SQLite dei test non se ne accorge).
- **Fatture senza commessa** (dal 02/10/2026): Vendite › Commesse mostra quanto fatturato dell'anno non è su una
  commessa e apre «Collega alle commesse» (`commesse/da_collegare`, `CommessaService::proposteCollegamento`): proposte
  da confermare a mano (protocollo, rif. offerta, rata di pari importo, unica commessa con residuo — mai per canoni
  ripetuti). Se la commessa manca si crea dalle fatture (`commesse/crea_da_fatture`): singola (es. un viaggio EXACT,
  un contratto per viaggio, tipo `viaggio`) o ricorrente a canone (una rata al mese, tipo `noleggio` o altro); le
  fatture successive del canone si agganciano da sole all'import (`trovaIncaricoPerRata`). «Crea tutte le commesse mancanti»
  (`commesse/crea_mancanti`, `CommessaService::creaCommesseMancanti`, tutti gli anni) collega le proposte sicure, lascia a mano
  quelle «unica commessa con residuo» e crea il resto (una per fattura; tipo da `tipoDalTesto`). **Canone** = almeno 3
  fatture stesso cliente/sottocliente/importo in mesi diversi a 25–35 giorni l'una dall'altra (`serieMensili`; un salto
  chiude la serie): una rata per ogni mese fatturato, niente orizzonte fisso. Acconto e saldo con la stessa intestazione
  (testo prima dei «:») fanno una commessa sola se entro 12 mesi dalla prima. Una fattura che **prosegue** una commessa già
  creata dalle fatture (nota «Commessa creata dalle fatture emesse» o «Canone di …»: stessa intestazione entro 12 mesi, o
  stesso importo un mese dopo l'ultima rata) si aggancia lì e la commessa cresce di una rata (`commessaDaProseguire`,
  `aggiungiFattura`): così le fatture importate una alla volta finiscono sulla stessa commessa; le commesse da lettera o
  da offerta non si allungano mai da sole. Le note di credito vanno con la fattura che stornano (numero citato, anche solo
  la parte numerica se unica nell'anno, o stesso importo) e un documento stornato per intero non diventa commessa né rata;
  le percentuali delle rate sommano sempre 100. Lock `GET_LOCK` contro due esecuzioni insieme. La finestra Importa lo
  lancia dopo l'import se è spuntata «Crea le commesse che mancano» (ricordata in localStorage).
  Prova: `php tests/collega_commesse_cli.php`.
- **Grafici** (dal 02/10/2026): `js/core/grafici.js` + `css/grafici.css`, HTML/SVG senza librerie (barre, barra impilata,
  ciambella, divergenti). Vendite › Commesse: da fatturare, valore per tipo, numero contro valore; sopra, il «ponte» con
  Fatture (`Indicatori::ponteFatturato`). Fatture: anzianità del da incassare (`Indicatori::anzianitaCrediti`) e peso dei
  clienti. Il margine previsto si mostra solo sulle commesse con costi partner registrati.
- **Note spese** (dal 28/09/2026): tutte le spese di trasferta stanno in `spese` (v082; vitto e alloggio delle
  trasferte migrati da v083, le colonne `trasferte.vitto/alloggio` non si usano più). `api/Shared/Spese.php`: spese
  per giorno messe sulle righe di trasferta (indennità), abbinamento alla carta (stesso importo, ±3 giorni, solo senza
  ambiguità), spesa da movimento. Rimborso km al costo ACI del mezzo (`mezzi.costo_km`, v084), altrimenti al costo
  generale. Nota spese mensile in `rimborsi` (v085): presentata congela i totali e blocca le modifiche finché non si
  riapre. Scontrini da «Importa file» (`spese/importa_scontrino`, Claude legge anche le foto). Prova: `php tests/spese_cli.php`.
  **La carta dell'estratto è aziendale**: le spese con `metodo` carta o bonifico (`Spese::AZIENDALI`) si rendicontano
  e riducono l'indennità, ma non entrano in `da_rimborsare`; per le spese di tasca propria c'è `carta_personale` (v086).
  Un mese con nota presentata blocca spese, trasferte e mezzo finché non si riapre (`Spese::mesePresentato`).
- **Importazione unica** (`js/modules/importa.js`): ogni pulsante `[data-importa]` e i file trascinati sulla
  finestra aprono la stessa finestra, che riconosce il tipo, chiede l'**anteprima** e poi importa. L'anteprima
  è l'import vero eseguito dentro una transazione annullata (`api/Shared/Anteprima.php`): le transazioni
  interne diventano savepoint (`MvPdo` in `Database.php`) e `Response::json` lancia `RispostaCatturata` invece
  di uscire. Il codice con effetti fuori dal DB (AI, email) controlla `Anteprima::attiva()`.
  Fatture XML/p7m entrano da `importa/fattura` (`ImportaController`: emessa o ricevuta da `AZIENDA_PARTITA_IVA`).
  Estratti CSV/Excel: `EstrattoTabellare` (colonne scelte dall'utente salvate in settings per intestazione).
  Le risposte AI sono in cache 7 giorni in `storage/cache/ai/`. Prova: `php tests/importa_cli.php`.
- **Da FattureWeb** (dal 02/10/2026): pulsante da trascinare nei preferiti (`js/modules/fattureweb.js`, link nella
  finestra Importa file). Premuto sulla lista «Fatture di vendita» di FattureWeb scarica la FatturaPA di ogni fattura già inviata allo SDI
  mostrata (`option=saveXML`) nella sessione dell'utente (il login ha un reCAPTCHA: niente accesso dal server) e la passa
  all'ERP con `postMessage` (`index.html?da=fattureweb`, origine controllata) → Importa file. Nell'import XML ogni riga
  va alla sua commessa con `CommessaService::incaricoDellaRiga` (chiavi di `chiaviProtocollo` in modo testo libero: basta il
  codice SZ.DPS o uno dei due numeri di protocollo, ma un «N/AAAA» conta solo dopo «Prot.» o dopo un «+»; mai i mesi di
  competenza/periodo né «fattura n. 45/2026»); un record per sottocliente **e** commessa; un documento già entrato dall'elenco
  Excel (`ContabilitaController::DA_ELENCO`) si completa tenendo l'id e, se l'XML non trova commessa o sottocliente, quelli
  già assegnati; uno già dettagliato si salta. Solo fatture in euro (`Divisa` diversa: rifiutata con avviso); uno scarto
  tra `ImportoTotaleDocumento` e riepilogo (bollo, arrotondamento) si segnala. Il pulsante aspetta che «Tutti» abbia
  ricaricato la lista (chiede se resta paginata), decodifica l'XML con l'encoding dichiarato e avvisa se oltre 500 fatture
  ne restano fuori. Prova: `php tests/fattura_righe_cli.php`.
- **Elenchi di fatture Excel** (dal 28/09/2026): `importa/lista` (`ImportaController::lista`) legge Lista Fatture di
  Sistemi ed elenchi del portale; il verso lo dà l'intestazione (colonna Fornitore → ricevute in `fatture_passive`,
  Cliente → emesse). Clienti e fornitori mancanti si creano col solo nome (`piva_ricerca = 'da_cercare'`, v088–v089)
  e il frontend chiama `importa/cerca_piva` uno alla volta: Claude con ricerca web (`ClaudeClient::cercaSulWeb`),
  P.IVA controllata con la cifra di controllo; se è già di un'altra anagrafica le fatture passano lì e il doppione
  si toglie (`AnagraficaAuto`). Le ricevute dall'elenco hanno solo il totale: l'XML le completa (`DA_ELENCO`).
  Prova: `php tests/elenco_fatture_cli.php`.
  Lettere d'incarico: lette quattro alla volta (`incarichi/import_pdf`, non salva) e riviste in **una tabella**,
  poi salvate con `incarichi/save`. Doppioni per protocollo (`CommessaService::chiaviProtocollo`: «820/2026» e
  «SZ.DPS.F011.26» sono chiavi della stessa lettera): esclusi in tabella e rifiutati dal `save`.
- **Termini di pagamento** (dal 29/09/2026): scadenze solo da `api/Shared/TerminiPagamento.php` (giorni, fine mese,
  giorno fisso: «60 gg d.f.f.m. al 10»). Colonne `fine_mese`/`giorno_pagamento` su `incarichi` e termini standard su
  `clienti` (v096–v100; Unindustria: 60/fine mese/10 e piano `6_12`, 50% a 6 e 50% a 12 mesi dall'accettazione;
  v101–v103 lo applicano alle commesse già registrate senza nulla di fatturato):
  una commessa nuova li prende dal cliente (`CommessaService::terminiCliente`). Avviso «Pagamento Fornitore»:
  `AvvisoPagamentoParser` (un movimento atteso per ogni «TOTALE PAGAMENTO»), con controllo di importo, data e valuta
  rispetto a `CommessaService::scadenzaAttesa`. Le scadenze importate da Sistemi
  sono a fine mese: le fatture aperte con giorno fisso passano al giorno fisso successivo (31/03 → 10/04) con
  `CommessaService::allineaScadenzeGiornoFisso`, lanciato dal promemoria giornaliero prima di marcare le scadute (v107–v108 sullo storico). Prova: `php tests/termini_pagamento_cli.php`.
- **Rate**: una fattura che non combacia con nessuna rata non si aggancia più alla prima libera: `Avvisi` lo segnala
  e l'utente la collega a mano.
- **Cestino delle commesse** (dal 06/10/2026): eliminare una commessa passa da `Cestino::eliminaCommessa`, che salva in
  `cestino` (v109) la fotografia JSON di commessa, rate, costi, offerte e fatture collegate; `incarichi/ripristina` (id della voce)
  la rimette con lo stesso id, `incarichi/cestino` le elenca. I file della commessa restano finché è nel cestino
  (`Cestino::fileTrattenuti` in `Documenti::pulisciOrfani`). Prova: `php tests/cestino_cli.php`.
- **Cliente o prospect** è calcolato in `ClientiController::list` (ha commesse o fatture → cliente), non salvato.
- **Ricerca P.IVA** nel frontend solo con `UI.cercaPiva()`: segnala anche chi è già in anagrafica.
- **Allegati**: mai in `uploads/` (sull'hosting il server statico può ignorare `.htaccess`). Si salvano con
  `Documenti::salvaUpload` in `storage/documenti/`; gli allegati storici delle manutenzioni vengono spostati
  lì dal promemoria giornaliero (`Documenti::migraAllegatiStorici`).

## Modulo commerciale (offerte → incarico → rate → fatture, partner, margini)
- Logica condivisa in `api/Shared/CommessaService.php` (margine, rate, abbinamento fattura↔rata) e
  `api/Shared/Scadenzario.php`: il margine si calcola solo lì.
- Le fatture si emettono in **Sistemi** (nessuna integrazione): l'ERP prepara il testo con il riferimento
  `Rif. OFF-AAAA-NNN`, e l'import XML lo usa per riagganciare la fattura alla commessa e alla rata.
- Lettura AI (lettere d'incarico Unindustria, preventivi Cowork): `api/Shared/DocumentAi.php` via
  `ClaudeClient.php` (HTTP diretto, niente composer). Senza `ANTHROPIC_API_KEY` si ripiega sul parser a regole.
- Documenti allegati in `storage/documenti/` con nome casuale, scaricabili solo dall'API autenticata.
- Promemoria email: `.github/workflows/promemoria.yml` → router `module=cron&action=promemoria` con
  `X-Cron-Token` (`PROMEMORIA_CRON_TOKEN` nel `.env` del server e nei secret del repo).
- Tabelle: migrazioni v047–v056 in `api/Shared/migrazioni.php`.
- Riconciliazione pagamenti (tab Contabilità → Riconciliazione): `api/Shared/Riconciliatore.php` + `EstrattoContoParser.php`
  (XML CBI, PDF di riserva), tabelle `movimenti_banca`/`riconciliazioni` (v057–v058). Prova: `php tests/riconciliazione_cli.php`.
- Estratto carta di credito (Riconciliazione → «Estratto carta PDF», CartaBCC/Numia): `EstrattoContoParser::parseEstrattoCarta`,
  movimenti con `origine = 'estratto_carta'` (v070). Restano fuori da categorie e grafici: sul conto c'è già l'addebito mensile.
  Un estratto carta caricato come estratto conto viene riconosciuto (`eEstrattoCarta`) e importato comunque come carta; v076 sposta quelli già importati male.
- Lista fatture di Sistemi (Fatture → «Lista fatture (Excel)», .xlsx): `api/Shared/ListaFattureParser.php` + `ContabilitaController::importListaFatture`;
  crea solo le fatture mancanti, il «Residuo» di Sistemi si ignora (è sempre uguale al totale). Prova: `php tests/lista_fatture_cli.php`.
- Categoria di un movimento: tendina sulla riga in Banca › Movimenti (`ModMovimenti.tendina` / `scegliRapido`, stesse
  impostazioni predefinite della finestra: impara la regola e la applica ai simili); «Altre opzioni…» apre la finestra.
- **Abbinamento per fornitore** (dal 02/10/2026, Fatture › ricevute → «Abbina ai bonifici»): `api/Shared/PagamentiFornitore.php`,
  `riconciliazione/per_fornitore` e `abbina_fornitore`. Per i viaggi un bonifico non corrisponde a una fattura: acconti e
  caparre partono prima che l'hotel fatturi, un bonifico copre più fatture, in banca c'è il marchio dell'hotel. Si mettono
  insieme i bonifici aperti del fornitore (da 180 giorni prima della prima fattura a 120 dopo l'ultima) e le sue fatture, e si
  distribuiscono in ordine di data (FIFO) con `Riconciliatore::registra` (si annulla dalla Banca); una differenza fino al 5%
  si chiude con una nota sulla fattura. Il beneficiario del bonifico (24 caratteri dopo «*», o il nome dopo «BEN» degli
  istantanei) si impara in `fornitori_alias` (v106) e da lì vale anche per l'abbinamento normale (`contesto`). In automatico
  («Riprova abbinamento») solo se i bonifici riconosciuti per nome fanno esattamente il totale delle fatture. Le fatture pagate
  fuori dagli estratti (carta, altro conto) si chiudono con «Segna pagate» (`passive/set_pagate`, con nota).
  Prova: `php tests/pagamenti_fornitore_cli.php`.
- Categorie dei movimenti (tab Da classificare / Andamento): `api/Shared/Classificatore.php` (fatture → regole apprese →
  euristiche; il resto lo chiede all'utente) e `CategorieMovimenti.php` (grafici). Tabelle v059–v063.


## Trasferte (km, indennità, sincronizzazione Google Calendar)
- Regole pure (giorni e fasce degli eventi in ora di Roma, ordine tappe, ripartizione km, indennità
  46,48 / 30,99 / 15,49 €) in `api/Shared/TrasferteRegole.php`: l'indennità si calcola solo lì, il JS la legge da `list`.
- Percorso della giornata: partenza dalla base o dal luogo del pernottamento della notte prima → tutte le tappe
  (mattino, intere, pomeriggio) → base, salvo notte fuori con trasferta il giorno dopo. Ogni modifica ricalcola
  anche il giorno prima e quello dopo (`ricalcolaIntorno`). I km si dividono in decimi: la somma delle righe è il
  totale del percorso. Tappa = indirizzo del sottocliente/cliente, altrimenti `luogo_arrivo` (non i link di call).
  Notte fuori = pulsante o spesa di alloggio (la colonna `trasferte.alloggio` è obsoleta). Nessun ricalcolo e
  nessuna sync Google toccano un mese con nota spese presentata.
- Coordinate in cache nella tabella `geocache` (`api/Shared/Percorsi.php`); routing su `OSRM_URL` (default: server demo pubblico).
- Sync Google: `api/Shared/TrasferteSync.php` + `CalendarioMatcher.php`. Le righe con `modifica_manuale = 1` non vengono
  riscritte né eliminate; le righe senza più un evento si eliminano solo se il calendario è stato letto per intero.
- Costo al km nella tabella `settings` (chiave `trasferte_costo_km`). Migrazioni v067–v069. Prova: `php tests/trasferte_cli.php`.
- Indennità solo con almeno un cliente fuori dal comune della sede (`BASE_COMUNE`, altrimenti da `BASE_ADDRESS`); città
  mancante = giornata «da verificare», senza indennità (`TrasferteRegole::giornate`, regole in `docs/indicatori.md`).
  Carburante di tasca propria nei giorni con rimborso km: escluso da da_rimborsare (`Spese::carburanteDoppio`).
  Un mese con nota presentata mostra in Viaggi e nel PDF i totali congelati di `rimborsi` e avvisa se il ricalcolo
  differisce (`TrasferteController::notePresentate`).
