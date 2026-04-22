---
description: Deploy dell'ERP in produzione su mv-consulting.it
---

# Deploy in Produzione

Pipeline unificata v4: pre-flight → manifest (SHA-256) → cache-bust → commit → push → deploy (HTTP Pull + rollback) → migrazione DB → health check.

## Steps

// turbo-all

1. Esegui il deploy completo:
```bash
bash "/Users/marcovanzo/MV Consulting ERP/scripts/deploy.sh"
```
Se nell'output il conteggio errori è **> 0**, controlla i file falliti e investiga.

2. Se sono state modificate tabelle o colonne del DB, esegui anche la migrazione:
```bash
bash "/Users/marcovanzo/MV Consulting ERP/scripts/deploy.sh" --migrate
```
Nota: `--migrate` può essere passato anche al primo comando per fare tutto insieme.

3. Se necessario, apri https://www.mv-consulting.it/ERP/ nel browser per verificare visivamente.

## Uso diretto da terminale

```bash
# Deploy standard (HTTP Pull — solo file modificati)
bash "/Users/marcovanzo/MV Consulting ERP/scripts/deploy.sh"

# Deploy con messaggio di commit personalizzato
bash "/Users/marcovanzo/MV Consulting ERP/scripts/deploy.sh" "fix: risolto bug login"

# Deploy con migrazione DB
bash "/Users/marcovanzo/MV Consulting ERP/scripts/deploy.sh" --migrate

# Deploy via FTP-TLS (fallback)
bash "/Users/marcovanzo/MV Consulting ERP/scripts/deploy.sh" --ftp

# Simulazione — non tocca nulla
bash "/Users/marcovanzo/MV Consulting ERP/scripts/deploy.sh" --dry-run

# Forza deploy (ignora lock, branch check)
bash "/Users/marcovanzo/MV Consulting ERP/scripts/deploy.sh" --force
```

## Note
- Le variabili di progetto sono in `deploy.config` — non modificare `deploy.sh` direttamente.
- La DEPLOY_KEY viene letta da `.env.deploy` (locale) e `.env` (server).
- Il manifest viene rigenerato automaticamente ad ogni deploy.
- ⚠️ MAI committare la deploy key nel repository!
