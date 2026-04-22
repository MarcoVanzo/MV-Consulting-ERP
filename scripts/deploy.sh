#!/bin/bash
# ═══════════════════════════════════════════════════════════════
# ERP — Unified Deploy Script v4
# Pipeline: pre-flight → manifest → build → cache-bust → git → deploy → migrate → health check
#
# Questo script è IDENTICO in tutti i progetti ERP.
# Le variabili di progetto vengono lette da deploy.config
#
# Usage:
#   bash scripts/deploy.sh                         # Deploy standard
#   bash scripts/deploy.sh "fix: bug login"        # Con messaggio commit
#   bash scripts/deploy.sh --migrate               # Con migrazione DB
#   bash scripts/deploy.sh --dry-run               # Simulazione
#   bash scripts/deploy.sh --ftp                   # Forza FTP-TLS
#   bash scripts/deploy.sh --skip-checks           # Salta pre-flight
#   bash scripts/deploy.sh --no-build              # Salta React build
#   bash scripts/deploy.sh --force                 # Ignora lock e branch
# ═══════════════════════════════════════════════════════════════
set -euo pipefail

# ── Resolve project directory ──
SCRIPT_DIR="$(cd "$(dirname "$0")" && pwd)"
PROJECT_DIR="$(cd "$SCRIPT_DIR/.." && pwd)"
CONFIG_FILE="$PROJECT_DIR/deploy.config"

# ── Load config ──
if [[ ! -f "$CONFIG_FILE" ]]; then
    echo "❌ deploy.config non trovato in $PROJECT_DIR"
    exit 1
fi
source "$CONFIG_FILE"

# ── Colori ──
RED='\033[0;31m'
GREEN='\033[0;32m'
YELLOW='\033[1;33m'
CYAN='\033[0;36m'
BOLD='\033[1m'
DIM='\033[2m'
NC='\033[0m'

# ── Timing ──
DEPLOY_START=$(date +%s)

# ── Tool detection ──
NODE_BIN="${NODE_BIN:-$(which node 2>/dev/null || echo 'node')}"
PYTHON_BIN="${PYTHON_BIN:-$(which python3 2>/dev/null || echo 'python3')}"
LOCK_FILE="$PROJECT_DIR/.deploy.lock"
DEPLOY_TIMEOUT=300
HEALTH_RETRIES=3
HEALTH_WAIT=5

# ── Parse argomenti ──
DO_MIGRATE=false
SKIP_CHECKS=false
SKIP_BUILD=false
FORCE_BUILD=false
DRY_RUN=false
USE_FTP=false
FORCE=false
COMMIT_MSG=""

for arg in "$@"; do
    case "$arg" in
        --migrate)      DO_MIGRATE=true ;;
        --skip-checks)  SKIP_CHECKS=true ;;
        --no-build)     SKIP_BUILD=true ;;
        --force-build)  FORCE_BUILD=true ;;
        --dry-run)      DRY_RUN=true ;;
        --ftp)          USE_FTP=true ;;
        --force)        FORCE=true ;;
        --help)
            echo "Usage: deploy.sh [MESSAGE] [OPTIONS]"
            echo ""
            echo "Options:"
            echo "  --migrate      Esegui migrazioni DB dopo il deploy"
            echo "  --skip-checks  Salta pre-flight checks (PHPStan, Stress Test)"
            echo "  --no-build     Salta la build delle React apps"
            echo "  --force-build  Forza la build anche se invariata"
            echo "  --dry-run      Simula il deploy senza modifiche"
            echo "  --ftp          Usa FTP-TLS invece di HTTP Pull"
            echo "  --force        Ignora lock, branch check"
            echo "  --help         Mostra questo help"
            exit 0
            ;;
        *)              COMMIT_MSG="$arg" ;;
    esac
done

if [[ -z "$COMMIT_MSG" ]]; then
    COMMIT_MSG="deploy: $(date '+%Y-%m-%d %H:%M')"
fi

# ── Leggi variabili dal file env ──
DEPLOY_KEY=""
GITHUB_TOKEN=""
MIGRATION_TOKEN=""
FULL_ENV_PATH="$PROJECT_DIR/$ENV_FILE"
if [[ -f "$FULL_ENV_PATH" ]]; then
    DEPLOY_KEY=$(grep -E '^DEPLOY_KEY=' "$FULL_ENV_PATH" | cut -d'=' -f2- | tr -d "\"' " || true)
    GITHUB_TOKEN=$(grep -E '^GITHUB_TOKEN=' "$FULL_ENV_PATH" | cut -d'=' -f2- | tr -d "\"' " || true)
    MIGRATION_TOKEN=$(grep -E '^MIGRATION_TOKEN=' "$FULL_ENV_PATH" | cut -d'=' -f2- | tr -d "\"' " || true)
    # Fallback: MIGRATION_TOKEN = DEPLOY_KEY
    if [[ -z "$MIGRATION_TOKEN" ]] && [[ -n "$DEPLOY_KEY" ]]; then
        MIGRATION_TOKEN="$DEPLOY_KEY"
    fi
fi

# ── Helpers ──
timestamp() { date '+%H:%M:%S'; }
step_ok()   { echo -e "  ${GREEN}✅ $1${NC}"; }
step_warn() { echo -e "  ${YELLOW}⚠️  $1${NC}"; }
step_fail() { echo -e "  ${RED}❌ $1${NC}"; }
step_info() { echo -e "  ${CYAN}→ $1${NC}"; }
TOTAL_STEPS=8
CURRENT_STEP=0
ERRORS=0
step() {
    CURRENT_STEP=$((CURRENT_STEP + 1))
    echo ""
    echo -e "${YELLOW}[$(timestamp)] [$CURRENT_STEP/$TOTAL_STEPS]${NC} ${BOLD}$1${NC}"
}

cleanup() { rm -f "$LOCK_FILE" 2>/dev/null || true; }
trap cleanup EXIT

# ── Banner ──
echo ""
echo -e "${BOLD}${CYAN}═══════════════════════════════════════════════════${NC}"
echo -e "${BOLD}${CYAN}  🚀 $PROJECT_NAME — Deploy in Produzione v4${NC}"
if $DRY_RUN; then
    echo -e "${BOLD}${YELLOW}  ⚠️  MODALITÀ DRY RUN — nessuna modifica${NC}"
fi
CURRENT_BRANCH=$(git -C "$PROJECT_DIR" branch --show-current 2>/dev/null || echo "unknown")
echo -e "${DIM}  Branch: $CURRENT_BRANCH | $(date '+%Y-%m-%d %H:%M:%S')${NC}"
echo -e "${BOLD}${CYAN}═══════════════════════════════════════════════════${NC}"

# ═══════════════════════════════════════════════════
# STEP 1: Pre-flight checks
# ═══════════════════════════════════════════════════
step "Pre-flight checks..."

# Lock file
if [[ -f "$LOCK_FILE" ]]; then
    LOCK_AGE=$(( $(date +%s) - $(stat -f%m "$LOCK_FILE" 2>/dev/null || echo 0) ))
    if [[ $LOCK_AGE -gt 600 ]]; then
        step_warn "Lock file stale (${LOCK_AGE}s), lo rimuovo."
        rm -f "$LOCK_FILE"
    elif ! $FORCE; then
        step_fail "Deploy già in corso (lock da ${LOCK_AGE}s). Usa --force."
        exit 1
    fi
fi
echo "$$" > "$LOCK_FILE"
step_ok "Lock acquisito"

# ENV file
if [[ ! -f "$FULL_ENV_PATH" ]]; then
    step_fail "File $ENV_FILE non trovato!"
    exit 1
fi
step_ok "$ENV_FILE trovato"

# DEPLOY_KEY (necessaria per HTTP Pull)
if [[ -z "$DEPLOY_KEY" ]] && [[ "$USE_FTP" == false ]]; then
    step_warn "DEPLOY_KEY non trovata in $ENV_FILE — uso FTP-TLS come fallback"
    USE_FTP=true
fi
if [[ -n "$DEPLOY_KEY" ]]; then
    step_ok "DEPLOY_KEY caricata"
fi

# Network check
if ! curl -s --max-time 5 -o /dev/null -w "%{http_code}" "https://api.github.com" | grep -qE '^[23]'; then
    step_fail "Nessuna connessione a GitHub."
    exit 1
fi
step_ok "Connessione GitHub OK"

# Branch check
if [[ "$CURRENT_BRANCH" != "$DEPLOY_BRANCH" ]]; then
    step_warn "Branch corrente: $CURRENT_BRANCH (atteso: $DEPLOY_BRANCH)"
    if ! $FORCE; then
        step_warn "Il deploy prosegue, ma il server fa pull da '$DEPLOY_BRANCH'."
    fi
fi
step_ok "Branch: $CURRENT_BRANCH"

# Merge conflicts
cd "$PROJECT_DIR"
if git ls-files -u 2>/dev/null | grep -q .; then
    step_fail "Conflitti di merge non risolti!"
    exit 1
fi
step_ok "Nessun conflitto di merge"

# Validazione sicurezza remote URL (auto-pulizia token)
REMOTE_URL=$(git remote get-url origin 2>/dev/null || true)
if echo "$REMOTE_URL" | grep -qE 'ghp_|github_pat_'; then
    step_warn "Token GitHub esposto nella remote URL! Pulizia..."
    CLEAN_URL=$(echo "$REMOTE_URL" | sed -E 's|https://[^@]+@|https://|')
    git remote set-url origin "$CLEAN_URL"
    step_ok "Remote URL ripulita"
fi

# PHPStan (se abilitato e non skip)
if ! $SKIP_CHECKS && ! $DRY_RUN && [[ "$HAS_PHPSTAN" == true ]]; then
    if command -v composer &>/dev/null; then
        step_info "PHPStan..."
        if composer phpstan --working-dir="$PROJECT_DIR" 2>/dev/null; then
            step_ok "PHPStan passed"
        else
            step_fail "PHPStan fallito! Correggi prima di deployare."
            exit 1
        fi
    else
        step_warn "Composer non trovato, skip PHPStan"
    fi
fi

# Stress Test (se abilitato, NON bloccante)
if ! $SKIP_CHECKS && ! $DRY_RUN && [[ "$HAS_STRESS_TEST" == true ]]; then
    if [[ -f "$PROJECT_DIR/scripts/stress_checker.py" ]]; then
        step_info "Stress Test (non bloccante)..."
        if $PYTHON_BIN "$PROJECT_DIR/scripts/stress_checker.py" 2>/dev/null; then
            step_ok "Stress Test passed"
        else
            step_warn "Stress Test fallito (non bloccante)"
        fi
    fi
fi

# Security scan: cerca credenziali hardcoded in file PHP
if ! $SKIP_CHECKS; then
    step_info "Security scan credenziali hardcoded..."
    CRED_FILES=$(grep -rlE "new\s+PDO\s*\(\s*[\"'].*[\"'],\s*[\"'][^\"']+[\"'],\s*[\"'][^\"']+[\"']" \
        --include="*.php" "$PROJECT_DIR" 2>/dev/null \
        | grep -v "vendor/" | grep -v "node_modules/" | grep -v ".git/" || true)
    if [[ -n "$CRED_FILES" ]]; then
        step_fail "Credenziali DB hardcoded trovate in:"
        echo "$CRED_FILES" | while read -r line; do echo "    ❌ $line"; done
        step_warn "Rimuovi le credenziali hardcoded prima del deploy!"
        # Non bloccante ma avvisa
    else
        step_ok "Nessuna credenziale hardcoded"
    fi
fi

if $DRY_RUN; then
    step_info "DRY RUN — nessuna modifica verrà effettuata"
fi

# ═══════════════════════════════════════════════════
# STEP 2: Genera manifest (SHA-256)
# ═══════════════════════════════════════════════════
step "Generazione manifest..."
cd "$PROJECT_DIR"

if [[ -f "scripts/generate_manifest.js" ]]; then
    if command -v node &>/dev/null || [[ -x "$NODE_BIN" ]]; then
        ${NODE_BIN:-node} scripts/generate_manifest.js
        step_ok "Manifest generato"
    else
        step_warn "Node.js non trovato, skip manifest"
    fi
else
    step_warn "generate_manifest.js non trovato, skip manifest"
fi

# ═══════════════════════════════════════════════════
# STEP 3: Build React apps (se configurato)
# ═══════════════════════════════════════════════════
step "Build React apps..."
if [[ "$HAS_REACT_APPS" != true ]] || $SKIP_BUILD; then
    step_info "Skippato ($(if [[ "$HAS_REACT_APPS" != true ]]; then echo 'N/A'; else echo '--no-build'; fi))"
else
    for APP_DIR in $REACT_APPS; do
        if [[ -d "$PROJECT_DIR/$APP_DIR" ]]; then
            DIST_DIR="$PROJECT_DIR/$APP_DIR/dist"
            SRC_DIR="$PROJECT_DIR/$APP_DIR/src"

            # Smart check: hash src/ per evitare build inutili
            if [[ -d "$SRC_DIR" ]]; then
                CURRENT_HASH=$(find "$SRC_DIR" -type f -not -name '.*' -exec md5 -q {} + 2>/dev/null | md5 -q 2>/dev/null || echo "unknown")
                HASH_FILE="$PROJECT_DIR/$APP_DIR/.build_hash"
                CACHED_HASH=""
                [[ -f "$HASH_FILE" ]] && CACHED_HASH=$(cat "$HASH_FILE" 2>/dev/null)

                if [[ "$FORCE_BUILD" != true ]] && [[ "$CURRENT_HASH" == "$CACHED_HASH" ]] && [[ -d "$DIST_DIR" ]]; then
                    step_info "$APP_DIR: invariato, skip build"
                    continue
                fi
            fi

            step_info "Building $APP_DIR..."
            if ! command -v npm &>/dev/null; then
                if [[ -d "$DIST_DIR" ]]; then
                    step_warn "npm non trovato, uso build esistente per $APP_DIR"
                else
                    step_fail "npm non trovato e $APP_DIR/dist non esiste!"
                    exit 1
                fi
                continue
            fi

            [[ ! -d "$PROJECT_DIR/$APP_DIR/node_modules" ]] && npm install --prefix "$PROJECT_DIR/$APP_DIR" --silent 2>/dev/null || true
            if npm run build --prefix "$PROJECT_DIR/$APP_DIR" 2>&1 | tail -5; then
                step_ok "$APP_DIR build riuscita"
                [[ -n "${CURRENT_HASH:-}" ]] && echo "$CURRENT_HASH" > "$HASH_FILE"
            else
                step_fail "Build fallita per $APP_DIR!"
                exit 1
            fi
        fi
    done
fi

# ═══════════════════════════════════════════════════
# STEP 4: Cache busting
# ═══════════════════════════════════════════════════
step "Cache busting..."
if ! $DRY_RUN; then
    VERSION=$(date +%s)
    INDEX_FILE="$PROJECT_DIR/index.html"
    if [[ -f "$INDEX_FILE" ]]; then
        if [[ "$(uname)" == "Darwin" ]]; then
            sed -i '' -E "s/(\.(css|js)\?v=)[a-zA-Z0-9._]+/\1${VERSION}/g" "$INDEX_FILE"
            sed -i '' -E "s/(meta name=\"app-version\" content=\")[^\"]+/\1${VERSION}/" "$INDEX_FILE"
        else
            sed -i -E "s/(\.(css|js)\?v=)[a-zA-Z0-9._]+/\1${VERSION}/g" "$INDEX_FILE"
            sed -i -E "s/(meta name=\"app-version\" content=\")[^\"]+/\1${VERSION}/" "$INDEX_FILE"
        fi
        step_ok "Cache bust applicato (v=$VERSION)"
    fi

    # Service Worker
    if [[ "$HAS_SERVICE_WORKER" == true ]]; then
        SW_FILE="$PROJECT_DIR/sw.js"
        if [[ -f "$SW_FILE" ]]; then
            if [[ "$(uname)" == "Darwin" ]]; then
                sed -i '' -E "s/const CACHE_VERSION = '[^']+';/const CACHE_VERSION = '${VERSION}';/" "$SW_FILE"
            else
                sed -i -E "s/const CACHE_VERSION = '[^']+';/const CACHE_VERSION = '${VERSION}';/" "$SW_FILE"
            fi
            step_ok "sw.js CACHE_VERSION aggiornato"
        fi
    fi
else
    step_info "Skippato (dry-run)"
fi

# ═══════════════════════════════════════════════════
# STEP 5: Git commit + push
# ═══════════════════════════════════════════════════
step "Git commit + push..."
cd "$PROJECT_DIR"

if $DRY_RUN; then
    step_info "Skippato (dry-run)"
else
    git add -A
    if git diff --cached --quiet 2>/dev/null; then
        step_info "Nessuna modifica da committare"
    else
        git commit -m "$COMMIT_MSG" --quiet
        step_ok "Commit: $COMMIT_MSG"
    fi

    # Push: tentativo 1 — credential helper / SSH
    step_info "Push su GitHub ($CURRENT_BRANCH)..."
    PUSH_OK=false

    if git push origin "$CURRENT_BRANCH" 2>&1 | tail -3; then
        PUSH_OK=true
    fi

    # Tentativo 2: GITHUB_TOKEN
    if [[ "$PUSH_OK" == false ]] && [[ -n "$GITHUB_TOKEN" ]]; then
        step_warn "Retry con GITHUB_TOKEN..."
        CLEAN_URL=$(git remote get-url origin)
        AUTH_URL=$(echo "$CLEAN_URL" | sed "s|https://|https://MarcoVanzo:${GITHUB_TOKEN}@|")
        git remote set-url origin "$AUTH_URL"
        if git push origin "$CURRENT_BRANCH" 2>&1 | tail -3; then
            step_ok "Push completato (via token)"
        else
            step_warn "Push fallito (non bloccante)"
        fi
        # SEMPRE ripulire
        git remote set-url origin "$CLEAN_URL"
    elif [[ "$PUSH_OK" == false ]]; then
        step_warn "Push fallito, nessun GITHUB_TOKEN disponibile"
    fi
fi

# ═══════════════════════════════════════════════════
# STEP 6: Deploy sul server
# ═══════════════════════════════════════════════════
step "Deploy sul server di produzione..."

if $DRY_RUN; then
    step_info "Skippato (dry-run)"
elif $USE_FTP; then
    step_info "Modalità FTP-TLS..."
    $PYTHON_BIN "$PROJECT_DIR/deploy.py" --no-git --skip-checks
    if [[ $? -ne 0 ]]; then
        ERRORS=$((ERRORS + 1))
    fi
else
    step_info "Modalità HTTP Pull..."
    DEPLOY_OK=false
    MAX_RETRIES=2

    for ATTEMPT in $(seq 1 $MAX_RETRIES); do
        STEP_START=$(date +%s)

        DEPLOY_TS=$(date +%s)
        DEPLOY_OUTPUT=$(curl -sf \
            --max-time "$DEPLOY_TIMEOUT" \
            -H "X-Deploy-Key: $DEPLOY_KEY" \
            -H "X-Deploy-Timestamp: $DEPLOY_TS" \
            -H "Accept: application/json" \
            "$DEPLOY_URL" 2>&1) || {
            STEP_ELAPSED=$(( $(date +%s) - STEP_START ))
            if [[ "$ATTEMPT" -lt "$MAX_RETRIES" ]]; then
                step_warn "Tentativo $ATTEMPT fallito. Retry in 5s..."
                sleep 5
                continue
            fi
            step_fail "Connessione al server fallita dopo $MAX_RETRIES tentativi!"
            step_info "URL: $DEPLOY_URL"

            # Fallback automatico a FTP-TLS
            step_warn "Tentativo fallback FTP-TLS..."
            $PYTHON_BIN "$PROJECT_DIR/deploy.py" --no-git --skip-checks
            if [[ $? -eq 0 ]]; then
                DEPLOY_OK=true
                step_ok "Deploy completato via FTP-TLS (fallback)"
            else
                ERRORS=$((ERRORS + 1))
            fi
            break
        }

        STEP_ELAPSED=$(( $(date +%s) - STEP_START ))

        # Prova a parsare come JSON
        if echo "$DEPLOY_OUTPUT" | $NODE_BIN -e "JSON.parse(require('fs').readFileSync('/dev/stdin','utf8'))" 2>/dev/null; then
            DEPLOY_STATUS=$(echo "$DEPLOY_OUTPUT" | $NODE_BIN -e "
                const r = JSON.parse(require('fs').readFileSync('/dev/stdin','utf8'));
                console.log(r.status || 'unknown');
            " 2>/dev/null)

            DEPLOY_SUMMARY=$(echo "$DEPLOY_OUTPUT" | $NODE_BIN -e "
                const r = JSON.parse(require('fs').readFileSync('/dev/stdin','utf8'));
                const s = r.summary || {};
                console.log('Aggiornati: ' + (s.updated || 0) + ', Skippati: ' + (s.skipped || 0) + ', Falliti: ' + (s.failed || 0));
            " 2>/dev/null)

            if [[ "$DEPLOY_STATUS" == "ok" ]]; then
                step_ok "Deploy riuscito in ${STEP_ELAPSED}s"
                step_info "$DEPLOY_SUMMARY"
                DEPLOY_OK=true
                break
            elif [[ "$DEPLOY_STATUS" == "rolled_back" ]]; then
                step_fail "Deploy fallito — ROLLBACK automatico eseguito!"
                step_info "$DEPLOY_SUMMARY"
                ERRORS=$((ERRORS + 1))
                break
            else
                step_warn "Deploy con stato: $DEPLOY_STATUS"
                step_info "$DEPLOY_SUMMARY"
                DEPLOY_OK=true
                break
            fi
        else
            # Legacy HTML response
            CLEAN_OUTPUT=$(echo "$DEPLOY_OUTPUT" | sed 's/<[^>]*>//g')
            echo "$CLEAN_OUTPUT" | grep -E '(✅|❌|⚠️|📊|Riepilogo)' | head -15 | while read -r line; do
                step_info "$line"
            done

            if echo "$CLEAN_OUTPUT" | grep -q "0 falliti"; then
                step_ok "Deploy riuscito (${STEP_ELAPSED}s)"
                DEPLOY_OK=true
                break
            elif echo "$CLEAN_OUTPUT" | grep -q "falliti"; then
                FAIL_LINE=$(echo "$CLEAN_OUTPUT" | grep "falliti" || true)
                step_warn "$FAIL_LINE"
                DEPLOY_OK=true
                break
            elif [[ "$ATTEMPT" -lt "$MAX_RETRIES" ]]; then
                step_warn "Tentativo $ATTEMPT inconcludente. Retry in 5s..."
                sleep 5
            else
                step_warn "Impossibile determinare l'esito."
                ERRORS=$((ERRORS + 1))
            fi
        fi
    done
fi

# ═══════════════════════════════════════════════════
# STEP 7: Migrazioni DB
# ═══════════════════════════════════════════════════
step "Migrazione database..."

if $DRY_RUN; then
    step_info "Skippato (dry-run)"
elif $DO_MIGRATE; then
    if [[ -n "$MIGRATE_URL" ]]; then
        step_info "Trigger migrazione: $MIGRATE_URL"
        MIGRATE_OUTPUT=$(curl -sf --max-time 60 \
            -H "X-Deploy-Key: $DEPLOY_KEY" \
            -H "X-Migration-Token: $MIGRATION_TOKEN" \
            -H "Content-Type: application/json" \
            -X POST "$MIGRATE_URL" 2>&1) || {
            step_fail "Migrazione fallita! Verifica manualmente."
        }
        if [[ -n "$MIGRATE_OUTPUT" ]]; then
            CLEAN_MIGRATE=$(echo "$MIGRATE_OUTPUT" | sed 's/<[^>]*>//g')
            echo "$CLEAN_MIGRATE" | head -10 | while read -r line; do
                [[ -n "$line" ]] && step_info "$line"
            done
            step_ok "Migrazione completata"
        fi
    else
        step_warn "MIGRATE_URL non configurato"
    fi
else
    step_info "Skippato (usa --migrate per abilitare)"
fi

# ═══════════════════════════════════════════════════
# STEP 8: Health check post-deploy
# ═══════════════════════════════════════════════════
step "Health check post-deploy..."

if $DRY_RUN; then
    step_info "Skippato (dry-run)"
else
    HC_PASS=0
    HC_TOTAL=0

    # Check 1: Ping (se configurato)
    if [[ -n "$PING_URL" ]]; then
        HC_TOTAL=$((HC_TOTAL + 1))
        PING_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 5 "$PING_URL" 2>/dev/null || echo "000")
        if [[ "$PING_CODE" == "200" ]]; then
            step_ok "Ping → $PING_CODE"
            HC_PASS=$((HC_PASS + 1))
        else
            step_fail "Ping → $PING_CODE"
        fi
    fi

    # Check 2: Health endpoint (se configurato)
    if [[ -n "$HEALTH_URL" ]]; then
        HC_TOTAL=$((HC_TOTAL + 1))
        HEALTH_RESPONSE=$(curl -sf --max-time 10 "$HEALTH_URL" 2>/dev/null || echo '{"status":"error"}')
        HEALTH_STATUS=$(echo "$HEALTH_RESPONSE" | $NODE_BIN -e "
            try { console.log(JSON.parse(require('fs').readFileSync('/dev/stdin','utf8')).status); }
            catch(e) { console.log('error'); }
        " 2>/dev/null)

        if [[ "$HEALTH_STATUS" == "ok" ]]; then
            HEALTH_LATENCY=$(echo "$HEALTH_RESPONSE" | $NODE_BIN -e "
                try { console.log(JSON.parse(require('fs').readFileSync('/dev/stdin','utf8')).latency_ms); }
                catch(e) { console.log('?'); }
            " 2>/dev/null)
            step_ok "Health API OK — latenza: ${HEALTH_LATENCY}ms"
            HC_PASS=$((HC_PASS + 1))
        else
            step_warn "Health API: $HEALTH_STATUS"
        fi
    fi

    # Check 3: Homepage
    HC_TOTAL=$((HC_TOTAL + 1))
    HOME_CODE=$(curl -s -o /dev/null -w "%{http_code}" --max-time 10 "$APP_URL" 2>/dev/null || echo "000")
    if [[ "$HOME_CODE" == "200" ]]; then
        step_ok "Homepage → $HOME_CODE"
        HC_PASS=$((HC_PASS + 1))
    else
        step_warn "Homepage → $HOME_CODE"
    fi

    step_info "Health check: $HC_PASS/$HC_TOTAL superati"
fi

# ═══════════════════════════════════════════════════
# Riepilogo finale
# ═══════════════════════════════════════════════════
DEPLOY_END=$(date +%s)
DEPLOY_ELAPSED=$((DEPLOY_END - DEPLOY_START))

echo ""
echo -e "${BOLD}${GREEN}═══════════════════════════════════════════════════${NC}"
if $DRY_RUN; then
    echo -e "${BOLD}${YELLOW}  🏁 Dry run completato in ${DEPLOY_ELAPSED}s${NC}"
elif [[ $ERRORS -gt 0 ]]; then
    echo -e "${BOLD}${RED}  ⚠️  Deploy completato con $ERRORS errori (${DEPLOY_ELAPSED}s)${NC}"
    echo -e "${BOLD}${RED}  🌐 $APP_URL${NC}"
else
    echo -e "${BOLD}${GREEN}  ✅ Deploy completato in ${DEPLOY_ELAPSED}s${NC}"
    echo -e "${BOLD}${GREEN}  🌐 $APP_URL${NC}"
fi
echo -e "${BOLD}${GREEN}═══════════════════════════════════════════════════${NC}"
echo ""
