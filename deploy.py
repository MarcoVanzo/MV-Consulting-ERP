#!/usr/bin/env python3
"""
MV Consulting ERP — Fast Smart Auto-Deploy

Upload incrementale (solo file modificati), FTP parallelo, cache busting,
e health check post-deploy. Pattern identico a Fusion ERP.

Usage:
    python3 deploy.py                # Deploy completo
    python3 deploy.py --dry-run      # Simula senza caricare
    python3 deploy.py --force        # Ignora cache, carica tutto
    python3 deploy.py --no-git       # Salta git (usato dallo shell script)
    python3 deploy.py --skip-health  # Salta health check finale
"""
import os
import sys
import re
import ftplib
import hashlib
import json
import subprocess
import time as _time
import threading
import concurrent.futures
import argparse
import http.client
import urllib.parse
import ssl
from datetime import datetime
from typing import Optional

# ── Configuration ────────────────────────────────────────────────────────────
CACHE_FILE = '.deploy_cache.json'
MAX_WORKERS = 4           # Connessioni FTP parallele (MV ERP è piccolo, 4 bastano)
FTP_CONNECT_TIMEOUT = 30  # Timeout connessione FTP (secondi)
FTP_OP_TIMEOUT = 30       # Timeout operazioni FTP (secondi)
GIT_TIMEOUT = 30
GIT_PUSH_TIMEOUT = 60

# Directory da escludere dall'upload
EXCLUDE_DIRS = [
    '.git', 'node_modules', '.github', '.gemini',
    'tmp_pdf_parse', 'tmp_venv', 'PDF Pagamenti',
    '__pycache__', '.vscode', '.idea',
    'storage', 'uploads',
]

# File specifici da escludere
EXCLUDE_FILES = [
    'deploy', 'deploy.py', '.env.deploy', '.env', '.env.local',
    '.env.production', '.DS_Store', CACHE_FILE,
    '.deploy_manifest.json',  # vecchio manifest
    # Antigravity/planning artifacts
    'task.md', 'walkthrough.md', 'implementation_plan.md',
]

# Pattern prefissi da escludere (sicurezza: niente debug/test in produzione)
EXCLUDE_PREFIXES = (
    'test_', 'debug_', 'scratch_', 'fix_',
    'deploy_debug', 'reset_', 'setup_db',
    'check_db', 'migrate_', 'list_tables',
    'cleanup', 'db_dump', 'delete_token',
)


# ── Utility Functions ────────────────────────────────────────────────────────

def load_env(filepath='.env.deploy'):
    """Load environment variables from .env.deploy file."""
    if not os.path.exists(filepath):
        return False
    with open(filepath) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            if '=' in line:
                key, val = line.split('=', 1)
                os.environ.setdefault(key.strip(), val.strip().strip("'").strip('"'))
    return True


def get_file_hash(filepath):
    """Calculate SHA-256 hash of a file."""
    hasher = hashlib.sha256()
    try:
        with open(filepath, 'rb') as f:
            while True:
                chunk = f.read(65536)
                if not chunk:
                    break
                hasher.update(chunk)
        return hasher.hexdigest()
    except Exception as e:
        print(f"⚠️ Error hashing {filepath}: {e}")
        return None


def load_cache() -> dict:
    """Load the file hash cache from disk."""
    if os.path.exists(CACHE_FILE):
        try:
            with open(CACHE_FILE, 'r') as f:
                data = json.load(f)
                return dict(data) if isinstance(data, dict) else {}
        except Exception:
            return {}
    return {}


def save_cache(cache):
    """Save the file hash cache to disk."""
    try:
        with open(CACHE_FILE, 'w') as f:
            json.dump(cache, f, indent=2)
    except Exception as e:
        print(f"⚠️ Error saving cache: {e}")


def ensure_remote_dir(ftp, remote_dir, created_dirs):
    """Ensure a remote directory exists, using in-memory cache to reduce FTP commands."""
    if remote_dir in created_dirs:
        return True

    try:
        ftp.cwd(remote_dir)
        ftp.cwd('/')
        created_dirs.add(remote_dir)
        return True
    except ftplib.error_perm:
        try:
            parent = os.path.dirname(remote_dir)
            if parent and parent != '/' and parent != remote_dir:
                ensure_remote_dir(ftp, parent, created_dirs)
            ftp.mkd(remote_dir)
            created_dirs.add(remote_dir)
            return True
        except Exception as e:
            print(f"⚠️ Could not create directory {remote_dir}: {e}")
            return False


# ── FTP Connection Pool (Thread-safe) ────────────────────────────────────────

class FtpThreadLocal(threading.local):
    ftp: ftplib.FTP

thread_local = FtpThreadLocal()
active_ftp_connections = []
connection_lock = threading.Lock()


def get_ftp_connection(host, user, password):
    """Get or create a thread-local FTP connection (with TLS fallback)."""
    if not hasattr(thread_local, "ftp"):
        try:
            # Try FTP_TLS first
            ftp = ftplib.FTP_TLS(host, timeout=FTP_CONNECT_TIMEOUT)
            ftp.login(user, password)
            ftp.prot_p()  # Switch to secure data connection
            ftp.set_pasv(True)
        except Exception:
            # Fallback to plain FTP if TLS not supported
            ftp = ftplib.FTP(host, timeout=FTP_CONNECT_TIMEOUT)
            ftp.login(user, password)

        if ftp.sock:
            ftp.sock.settimeout(FTP_OP_TIMEOUT)
        thread_local.ftp = ftp
        with connection_lock:
            active_ftp_connections.append(ftp)
    return thread_local.ftp


def quit_ftp_connection():
    """Close the current thread's FTP connection."""
    if hasattr(thread_local, "ftp"):
        ftp = thread_local.ftp
        try:
            ftp.quit()
        except Exception:
            pass
        with connection_lock:
            if ftp in active_ftp_connections:
                active_ftp_connections.remove(ftp)
        del thread_local.ftp


def quit_all_ftp_connections():
    """Close all active FTP connections across all threads."""
    with connection_lock:
        for ftp in active_ftp_connections:
            try:
                ftp.quit()
            except Exception:
                pass
        active_ftp_connections.clear()


def worker_upload(item, host, user, password, max_retries=3):
    """Upload a single file to FTP (called from thread pool)."""
    local_path, remote_filename, remote_dir = item
    for attempt in range(1, max_retries + 1):
        try:
            ftp = get_ftp_connection(host, user, password)
            ftp.cwd('/')
            ftp.cwd(remote_dir)
            with open(local_path, 'rb') as fbin:
                ftp.storbinary(f'STOR {remote_filename}', fbin)
            return True, local_path, None
        except Exception as e:
            quit_ftp_connection()
            if attempt == max_retries:
                return False, local_path, str(e)
            _time.sleep(2)
    return False, local_path, "Max retries exceeded"


# ── Core Deploy Functions ────────────────────────────────────────────────────

def update_index_version():
    """Aggiorna il parametro ?v=... in index.html per forzare il refresh della cache del browser."""
    version = str(int(_time.time()))
    index_path = 'index.html'
    if not os.path.exists(index_path):
        return

    try:
        with open(index_path, 'r', encoding='utf-8') as f:
            content = f.read()

        # Sostituisce .css?v=... e .js?v=... con la nuova versione (timestamp)
        new_content = re.sub(r'(\.(css|js)\?v=)[\w\.]+', r'\g<1>' + version, content)

        if new_content != content:
            with open(index_path, 'w', encoding='utf-8') as f:
                f.write(new_content)
            print(f"✅ Cache busting: index.html aggiornato (v={version})")
        else:
            print(f"ℹ️  Nessun parametro ?v= trovato in index.html.")
    except Exception as e:
        print(f"⚠️ Errore durante l'aggiornamento cache in index.html: {e}")


def deploy_files_via_ftp(dry_run=False, force=False):
    """Upload project files via FTP in parallel, only if changed."""
    if dry_run:
        print("\n🔍 DRY RUN: Nessun file verrà caricato.")
    print("\n🚀 Avvio Smart Auto-Deploy (Parallelo)...")

    host = os.getenv('FTP_SERVER', '')
    user = os.getenv('FTP_USERNAME', '')
    password = os.getenv('FTP_PASSWORD', '')
    ftp_path = os.getenv('FTP_PATH', '')

    if not host or not password:
        print("❌ ERRORE CRITICO: Parametri di deploy mancanti (FTP_SERVER/FTP_PASSWORD)!")
        print("Controlla il file .env.deploy")
        return False

    try:
        # Load local cache
        file_cache = load_cache() if not force else {}
        new_cache = file_cache.copy()

        # Build the base remote dir
        base_remote_dir = '/' + ftp_path if ftp_path else '/'

        upload_jobs = []
        required_dirs = set()
        required_dirs.add(base_remote_dir)
        skip_count = 0

        print("🔍 Scanning file modificati...")
        for root, dirs, files in os.walk('.'):
            # Prune ignored directories in-place
            dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS]

            rel_path = os.path.relpath(root, '.')
            rel_path_unix = rel_path.replace('\\', '/')

            # Calculate remote subdirectory
            remote_sub_dir = base_remote_dir if rel_path_unix == '.' else f"{base_remote_dir}/{rel_path_unix}"

            for file in files:
                # Skip hidden files (except .htaccess)
                if file != '.htaccess' and file.startswith('.'):
                    skip_count += 1
                    continue

                # Skip explicitly excluded files
                if file in EXCLUDE_FILES or file.endswith('.example'):
                    skip_count += 1
                    continue

                # Skip files with dangerous prefixes
                if file.startswith(EXCLUDE_PREFIXES):
                    skip_count += 1
                    continue

                # Skip non-deployable extensions
                if file.endswith(('.pyc', '.log', '.sqlite', '.db', '.sql')):
                    skip_count += 1
                    continue

                local_file_path = os.path.join(root, file)

                # Calculate file hash
                file_hash = get_file_hash(local_file_path)
                if not file_hash:
                    continue

                # Skip if unchanged (cache hit)
                cached_hash = file_cache.get(local_file_path, '')
                if cached_hash and cached_hash == file_hash:
                    skip_count += 1
                    continue

                # Add to upload queue
                upload_jobs.append((local_file_path, file, remote_sub_dir, file_hash))
                required_dirs.add(remote_sub_dir)

        if not upload_jobs:
            print(f"\n✅ Tutti i file sono aggiornati! ({skip_count} file invariati)")
            return True

        print(f"📦 Trovati {len(upload_jobs)} file da caricare.")

        if dry_run:
            for job in upload_jobs:
                local_path, remote_filename, remote_sub_dir, _ = job
                print(f"  [DRY] {local_path} → {remote_sub_dir}/{remote_filename}")
            print(f"\n🏁 Dry run completato. {len(upload_jobs)} file sarebbero stati caricati.")
            return True

        # Step 1: Create directories (single connection)
        print("🔌 Preparazione directory remote...")
        ftp_setup = get_ftp_connection(host, user, password)
        created_dirs = set()
        ftp_setup.cwd('/')
        for d in sorted(required_dirs, key=len):
            ensure_remote_dir(ftp_setup, d, created_dirs)
        quit_ftp_connection()
        print("✅ Directory pronte.\n")

        # Step 2: Parallel upload
        print(f"🚀 Upload via {MAX_WORKERS} connessioni parallele...")
        upload_count = 0
        failed_jobs = []

        with concurrent.futures.ThreadPoolExecutor(max_workers=MAX_WORKERS) as executor:
            future_to_job = {}
            for job in upload_jobs:
                local_path, remote_filename, remote_sub_dir, f_hash = job
                item = (local_path, remote_filename, remote_sub_dir)
                future = executor.submit(worker_upload, item, host, user, password)
                future_to_job[future] = job

            for future in concurrent.futures.as_completed(future_to_job):
                job = future_to_job[future]
                local_path, remote_filename, remote_sub_dir, f_hash = job
                try:
                    success, completed_local_path, error_msg = future.result()
                    if success:
                        upload_count += 1
                        new_cache[completed_local_path] = f_hash
                        print(f"  ⬆️  [{upload_count}/{len(upload_jobs)}] {local_path}")
                        # Salvataggio intermedio cache ogni 20 file
                        if upload_count % 20 == 0:
                            save_cache(new_cache)
                    else:
                        print(f"  ❌ Fallito: {local_path}: {error_msg}")
                        failed_jobs.append(completed_local_path)
                except Exception as e:
                    print(f"  ❌ Eccezione: {local_path}: {e}")
                    failed_jobs.append(local_path)

        # Cleanup all FTP connections
        quit_all_ftp_connections()

        # Save final cache
        save_cache(new_cache)

        elapsed_msg = f"{upload_count} file caricati, {skip_count} invariati"
        if failed_jobs:
            print(f"\n⚠️ Upload completato con {len(failed_jobs)} errori. {elapsed_msg}")
            return False
        else:
            print(f"\n✅ Upload completato! {elapsed_msg}")
            return True

    except Exception as e:
        print(f"❌ Errore FTP: {e}")
        return False


def git_commit_and_push(skip=False):
    """Committa tutte le modifiche locali e fa push su GitHub."""
    if skip:
        print("\n⏩ Skipping Git push (--no-git).")
        return
    print("\n📦 Salvataggio codice su GitHub...")
    try:
        result = subprocess.run(
            ['git', 'status', '--porcelain'],
            capture_output=True, text=True, check=True,
            timeout=GIT_TIMEOUT
        )
        if not result.stdout.strip():
            print("  ℹ️  Nessuna modifica locale da committare.")
        else:
            timestamp = _time.strftime('%Y-%m-%d %H:%M')
            subprocess.run(['git', 'add', '-A'], check=True, timeout=GIT_TIMEOUT)
            subprocess.run(
                ['git', 'commit', '-m', f'deploy: {timestamp}'],
                check=True, timeout=GIT_TIMEOUT
            )
            print(f"  ✅ Commit creato: deploy: {timestamp}")

        push_result = subprocess.run(
            ['git', 'push', 'origin', 'main'],
            timeout=GIT_PUSH_TIMEOUT
        )
        if push_result.returncode == 0:
            print("  ✅ Push su GitHub completato.")
        else:
            print("  ⚠️ Push fallito (non bloccante).")

    except subprocess.TimeoutExpired:
        print("  ⚠️ Git timeout — continuo il deploy senza push...")
    except subprocess.CalledProcessError as e:
        print(f"  ⚠️ Git error (non bloccante): {e}")
    print()


def verify_deployment():
    """Health check on the production site."""
    print("\n🩺 Health Check sul sito live...")
    url = os.getenv('APP_URL', '')
    if not url:
        print("  ⚠️ APP_URL non configurato in .env.deploy. Skip health check.")
        return

    try:
        parsed_url = urllib.parse.urlparse(url)
        if parsed_url.scheme == 'https':
            context = ssl._create_unverified_context()
            conn = http.client.HTTPSConnection(parsed_url.netloc, timeout=10, context=context)
        else:
            conn = http.client.HTTPConnection(parsed_url.netloc, timeout=10)

        conn.request("GET", parsed_url.path or "/")
        response = conn.getresponse()
        if response.status == 200:
            print(f"  ✅ Health Check superato! {url} → 200 OK")
        else:
            print(f"  ⚠️ Health Check: {url} → {response.status}")
    except Exception as e:
        print(f"  ❌ Errore Health Check: {e}")


# ── Main ─────────────────────────────────────────────────────────────────────

def main():
    t0 = _time.time()

    parser = argparse.ArgumentParser(description="MV Consulting ERP — Smart Auto-Deploy")
    parser.add_argument("--dry-run", action="store_true", help="Simula il deploy senza caricare file")
    parser.add_argument("--force", action="store_true", help="Ignora la cache e carica tutti i file")
    parser.add_argument("--no-git", action="store_true", help="Salta il commit e push su GitHub")
    parser.add_argument("--skip-health", action="store_true", help="Salta il health check finale")
    args = parser.parse_args()

    # Load environment
    if not load_env('.env.deploy'):
        print("❌ File .env.deploy non trovato!")
        print("Crea il file con: FTP_SERVER, FTP_USERNAME, FTP_PASSWORD, FTP_PATH, APP_URL")
        sys.exit(1)

    if 'FTP_SERVER' not in os.environ or 'FTP_PASSWORD' not in os.environ:
        print("❌ ERRORE CRITICO: Parametri di deploy mancanti!")
        sys.exit(1)

    print("=====================================")
    print("   MV Consulting ERP - Smart Deploy  ")
    print("=====================================\n")

    # 1. Cache busting (prima del commit git)
    if not args.dry_run:
        update_index_version()

    # 2. Git commit & push
    git_commit_and_push(skip=(args.no_git or args.dry_run))

    # 3. FTP upload
    try:
        success = deploy_files_via_ftp(dry_run=args.dry_run, force=args.force)
    except KeyboardInterrupt:
        print("\n🛑 Deploy interrotto dall'utente. Cache salvata per i file caricati.")
        success = False

    # 4. Health check
    if success and not args.dry_run and not args.skip_health:
        verify_deployment()

    elapsed = _time.time() - t0
    print()
    if success:
        if args.dry_run:
            print(f"🏁 Dry run completato in {elapsed:.1f}s — nessuna modifica effettuata.")
        else:
            print(f"🎉 Deploy completato in {elapsed:.1f}s!")
    else:
        print(f"💥 Deploy fallito dopo {elapsed:.1f}s. Controlla gli errori sopra.")
        sys.exit(1)

    print("=====================================")


if __name__ == '__main__':
    main()
