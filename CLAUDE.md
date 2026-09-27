# CLAUDE.md — MV Consulting ERP

> Regole permanenti per Claude su questo repo. Le regole globali (`~/.claude/CLAUDE.md`)
> valgono sempre; questo file specializza.

## Cos'è
Applicativo gestionale MV Consulting: **PHP** + frontend statico (`index.html` + `js/` + `css/`),
API in `api/`, gestione pagamenti/PDF (`PDF Pagamenti`, `tmp_pdf_parse`). Repo GitHub `MV-Consulting-ERP`.

## Leggi prima di operare
- `.agents/workflows/deploy.md` — workflow di deploy.
- `.env.example` per le variabili attese; `deploy.config` per la configurazione di deploy.

## Deploy — attenzione: due meccanismi diversi coesistono

**1. GitHub Actions (quello che va davvero in produzione).**
`.github/workflows/deploy-ftp.yml` — **FTP push** con `SamKirkland/FTP-Deploy-Action`.
Trigger: `push` su `main` **oppure** `workflow_dispatch`. Secret: `FTP_SERVER`, `FTP_USERNAME`,
`FTP_PASSWORD`, `FTP_PATH`.

> ⚠️ **Nessun gate**: niente test, niente lint, nessun health check, **nessun rollback e nessun
> backup**. Un `git push origin main` è già la produzione.
> ⚠️ Il workflow **ignora** `deploy_manifest.json`: i due meccanismi possono divergere.

```bash
gh workflow run deploy-ftp.yml --ref main   # oppure git push origin main
gh run list --workflow deploy-ftp.yml --limit 1 && gh run watch <id>
```

**2. Pipeline locale pull-based** (alternativa, non usata dalle Actions).
`./deploy` → `deploy.py` (FTP-TLS multi-worker, pre-flight, security scan, lock, health check su
`APP_URL`, storico in `.deploy_history.log`) e `deploy_update.php` con manifest
(`deploy_manifest.json`) e cache (`.deploy_cache.json`). Questo è l'unico percorso che ha
backup e rollback.

> ⚠️ `./deploy` fa `git add .` + commit + push **automatici**: con il working tree sporco
> pubblica tutto. Verifica `git status` prima di lanciarlo.

Migrazioni DB: **mai automatiche**, si lanciano a mano via `api/migrate.php` (HTTP).
Segreti in `.env` / `.env.deploy` **non tracciati** — non committarli.
**Avvisami quando il deploy finisce** (`gh run watch`/`gh run list` in background).

## Note
- Cartelle `tmp_*` (`tmp_pdf_parse`, `tmp_root_redirect`, `tmp_venv`) sono di lavoro: non versionare artefatti.

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
