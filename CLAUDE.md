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

Migrazioni DB: **mai automatiche**. Dopo il deploy, workflow manuale `migrazione.yml` (secret
`DEPLOY_KEY`), oppure POST a `api/router.php?module=admin&action=migrate` con header `X-Deploy-Key`
(`api/migrate.php` diretto è bloccato da `api/.htaccess`: 403). Si aggiungono query **solo in coda**
all'array di `api/migrate.php`: la versione è la posizione.
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
  Vendite (offerte, commesse, margini), Incassi (fatture emesse e ricevute), Banca (movimenti, da classificare,
  andamento), Trasferte (viaggi, mezzi), Anagrafiche (clienti e prospect, partner e fornitori).
  Schede generiche `.vtabs/.vtab/.vpane` (`UI.initVtabs`), anno unico `UI.anno()` (selettori `.sel-anno`),
  router in `app.js` (tabella `CARICA`, indirizzo `#vista/scheda`). Stili in `css/viste.css`; su telefono il menu
  diventa una barra in basso. Niente `prompt()`: `UI.chiedi()` (finestra con calendario).
- **Importazione unica** (`js/modules/importa.js`): ogni pulsante `[data-importa]` e i file trascinati sulla
  finestra aprono la stessa finestra, che riconosce il tipo, chiede l'**anteprima** e poi importa. L'anteprima
  è l'import vero eseguito dentro una transazione annullata (`api/Shared/Anteprima.php`): le transazioni
  interne diventano savepoint (`MvPdo` in `Database.php`) e `Response::json` lancia `RispostaCatturata` invece
  di uscire. Il codice con effetti fuori dal DB (AI, email) controlla `Anteprima::attiva()`.
  Fatture XML/p7m entrano da `importa/fattura` (`ImportaController`: emessa o ricevuta da `AZIENDA_PARTITA_IVA`).
  Estratti CSV/Excel: `EstrattoTabellare` (colonne scelte dall'utente salvate in settings per intestazione).
  Le risposte AI sono in cache 7 giorni in `storage/cache/ai/`. Prova: `php tests/importa_cli.php`.
- **Rate**: una fattura che non combacia con nessuna rata non si aggancia più alla prima libera: `Avvisi` lo segnala
  e l'utente la collega a mano.
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
- Tabelle: migrazioni v047–v056 in `api/migrate.php`.
- Riconciliazione pagamenti (tab Contabilità → Riconciliazione): `api/Shared/Riconciliatore.php` + `EstrattoContoParser.php`
  (XML CBI, PDF di riserva), tabelle `movimenti_banca`/`riconciliazioni` (v057–v058). Prova: `php tests/riconciliazione_cli.php`.
- Estratto carta di credito (Riconciliazione → «Estratto carta PDF», CartaBCC/Numia): `EstrattoContoParser::parseEstrattoCarta`,
  movimenti con `origine = 'estratto_carta'` (v070). Restano fuori da categorie e grafici: sul conto c'è già l'addebito mensile.
  Un estratto carta caricato come estratto conto viene riconosciuto (`eEstrattoCarta`) e importato comunque come carta; v076 sposta quelli già importati male.
- Lista fatture di Sistemi (Fatture → «Lista fatture (Excel)», .xlsx): `api/Shared/ListaFattureParser.php` + `ContabilitaController::importListaFatture`;
  crea solo le fatture mancanti, il «Residuo» di Sistemi si ignora (è sempre uguale al totale). Prova: `php tests/lista_fatture_cli.php`.
- Categorie dei movimenti (tab Da classificare / Andamento): `api/Shared/Classificatore.php` (fatture → regole apprese →
  euristiche; il resto lo chiede all'utente) e `CategorieMovimenti.php` (grafici). Tabelle v059–v063.


## Trasferte (km, indennità, sincronizzazione Google Calendar)
- Regole pure (giorni e fasce degli eventi in ora di Roma, ordine tappe, ripartizione km, indennità
  46,48 / 30,99 / 15,49 €) in `api/Shared/TrasferteRegole.php`: l'indennità si calcola solo lì, il JS la legge da `list`.
- Percorso della giornata: partenza dalla base o dal luogo del pernottamento della notte prima → tutte le tappe
  (mattino, intere, pomeriggio) → base, salvo notte fuori con trasferta il giorno dopo. Ogni modifica ricalcola
  anche il giorno prima e quello dopo (`ricalcolaIntorno`).
- Coordinate in cache nella tabella `geocache` (`api/Shared/Percorsi.php`); routing su `OSRM_URL` (default: server demo pubblico).
- Sync Google: `api/Shared/TrasferteSync.php` + `CalendarioMatcher.php`. Le righe con `modifica_manuale = 1` non vengono
  riscritte né eliminate; le righe senza più un evento si eliminano solo se il calendario è stato letto per intero.
- Costo al km nella tabella `settings` (chiave `trasferte_costo_km`). Migrazioni v067–v069. Prova: `php tests/trasferte_cli.php`.
