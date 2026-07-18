# CLAUDE.md — MV Consulting ERP

> Regole permanenti per Claude su questo repo. Le regole globali (`~/.claude/CLAUDE.md`)
> valgono sempre; questo file specializza.

## Cos'è
Applicativo gestionale MV Consulting: **PHP** + frontend statico (`index.html` + `js/` + `css/`),
API in `api/`, gestione pagamenti/PDF (`PDF Pagamenti`, `tmp_pdf_parse`). Repo GitHub `MV-Consulting-ERP`.

## Leggi prima di operare
- `.agents/workflows/deploy.md` — workflow di deploy.
- `.env.example` per le variabili attese; `deploy.config` per la configurazione di deploy.

## Deploy (pull-based, come MV-ERP)
Deploy via `deploy.py` / `deploy_update.php` con manifest (`deploy_manifest.json`) e cache
(`.deploy_cache.json`), storico in `.deploy_history.log`. Segreti in `.env` / `.env.deploy`
**non tracciati** — non committarli. **Avvisami quando il deploy finisce**
(`gh run watch`/`gh run list` in background).

## Note
- Cartelle `tmp_*` (`tmp_pdf_parse`, `tmp_root_redirect`, `tmp_venv`) sono di lavoro: non versionare artefatti.
