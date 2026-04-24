#!/usr/bin/env bash
# ============================================================
# git_auto_backup.sh
# Salva automaticamente lo stato del progetto su un branch
# dedicato "auto-backup", mantenendo le ultime MAX_BACKUPS versioni.
# ============================================================

set -euo pipefail

# --- Configurazione ---
REPO_DIR="/Users/marcovanzo/Fusion ERP"
BACKUP_BRANCH="auto-backup"
MAX_BACKUPS=12
LOG_FILE="$REPO_DIR/scripts/auto_backup.log"

# --- Helper log ---
log() {
  echo "[$(date '+%Y-%m-%d %H:%M:%S')] $*" | tee -a "$LOG_FILE"
}

# --- Entra nella directory del progetto ---
cd "$REPO_DIR" || { log "ERRORE: directory non trovata $REPO_DIR"; exit 1; }

log "=== Avvio backup automatico ==="

# --- Salva il branch corrente ---
CURRENT_BRANCH=$(git rev-parse --abbrev-ref HEAD)
log "Branch corrente: $CURRENT_BRANCH"

# --- Assicurati che il branch auto-backup esista ---
if ! git show-ref --quiet "refs/heads/$BACKUP_BRANCH"; then
  log "Creazione branch $BACKUP_BRANCH ..."
  git branch "$BACKUP_BRANCH" HEAD
fi

# --- Stash delle modifiche in corso (se presenti) ---
STASH_CREATED=false
if ! git diff --quiet || ! git diff --cached --quiet; then
  log "Stash delle modifiche in corso..."
  git stash push -u -m "auto_backup_stash_$(date +%s)"
  STASH_CREATED=true
fi

# --- Passa al branch di backup ---
git checkout "$BACKUP_BRANCH" > /dev/null 2>&1
log "Passato a branch: $BACKUP_BRANCH"

# --- Torna al branch originale per fare il merge dei file ---
git checkout "$CURRENT_BRANCH" -- . > /dev/null 2>&1 || true

# --- Aggiunge tutti i file al commit (rispettando .gitignore) ---
git add -A > /dev/null 2>&1

# --- Controlla se ci sono davvero modifiche da committare ---
if git diff --cached --quiet; then
  log "Nessuna modifica da salvare rispetto all'ultimo backup."
  git checkout "$CURRENT_BRANCH" > /dev/null 2>&1
  if $STASH_CREATED; then
    git stash pop > /dev/null 2>&1 || true
  fi
  log "=== Fine backup (nessuna modifica) ==="
  exit 0
fi

# --- Crea il commit di backup ---
TIMESTAMP=$(date '+%Y-%m-%d %H:%M:%S')
COMMIT_MSG="[AUTO-BACKUP] $TIMESTAMP"
git commit -m "$COMMIT_MSG" > /dev/null 2>&1
log "Commit creato: $COMMIT_MSG"

# --- Rotazione: mantieni solo gli ultimi MAX_BACKUPS commit ---
BACKUP_COUNT=$(git rev-list --count "$BACKUP_BRANCH")
log "Backup presenti: $BACKUP_COUNT / $MAX_BACKUPS"

if [ "$BACKUP_COUNT" -gt "$MAX_BACKUPS" ]; then
  EXCESS=$(( BACKUP_COUNT - MAX_BACKUPS ))
  log "Rimozione dei $EXCESS backup più vecchi..."

  # Trova l'hash del commit che diventa il nuovo root
  NEW_BASE=$(git rev-list "$BACKUP_BRANCH" | tail -n "$MAX_BACKUPS" | tail -1)
  
  # Usa grafting temporaneo per troncare la storia
  git replace --graft "$NEW_BASE" > /dev/null 2>&1 || true
  git filter-branch --tag-name-filter cat -- --all > /dev/null 2>&1 || true
  git for-each-ref --format="%(refname)" refs/original/ | xargs -r git update-ref -d > /dev/null 2>&1 || true
  git replace -d "$NEW_BASE" > /dev/null 2>&1 || true
  git reflog expire --expire=now --all > /dev/null 2>&1 || true
  git gc --prune=now --quiet > /dev/null 2>&1 || true
  log "Rotazione completata. Backup mantenuti: $MAX_BACKUPS"
fi

# --- Torna al branch originale ---
git checkout "$CURRENT_BRANCH" > /dev/null 2>&1
log "Tornato a branch: $CURRENT_BRANCH"

# --- Ripristina eventuali modifiche in stash ---
if $STASH_CREATED; then
  git stash pop > /dev/null 2>&1 || true
  log "Modifiche in corso ripristinate dallo stash."
fi

log "=== Backup completato con successo ==="
