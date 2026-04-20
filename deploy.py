#!/usr/bin/env python3
import os
import sys
import ftplib
from datetime import datetime

def load_env(filepath):
    if not os.path.exists(filepath):
        return
    with open(filepath) as f:
        for line in f:
            line = line.strip()
            if not line or line.startswith('#'):
                continue
            if '=' in line:
                key, val = line.split('=', 1)
                os.environ[key.strip()] = val.strip().strip("'").strip('"')

load_env('.env.deploy')

if 'FTP_SERVER' not in os.environ or 'FTP_PASSWORD' not in os.environ:
    print("❌ ERRORE CRITICO: Parametri di deploy mancanti!")
    sys.exit(1)

FTP_SERVER = os.environ.get("FTP_SERVER")
FTP_USERNAME = os.environ.get("FTP_USERNAME")
FTP_PASSWORD = os.environ.get("FTP_PASSWORD")
FTP_PATH = os.environ.get("FTP_PATH", "")

def upload_ftp():
    EXCLUDE_DIRS = ['.git', 'node_modules', 'dist', '.github', '.gemini']
    EXCLUDE_FILES = ['deploy', '.env.deploy', '.DS_Store', 'task.md', 'walkthrough.md', 'implementation_plan.md']

    print(f"🚀 Connessione a {FTP_SERVER} come {FTP_USERNAME}...")
    try:
        ftp = ftplib.FTP(FTP_SERVER)
        ftp.login(FTP_USERNAME, FTP_PASSWORD)
        
        if FTP_PATH and FTP_PATH.strip() and FTP_PATH != '/':
             try:
                 ftp.cwd(FTP_PATH)
                 print(f"📁 Root di destinazione: {FTP_PATH}")
             except Exception as e:
                 print(f"⚠️ Impossibile accedere alla cartella {FTP_PATH}: {e}")
                 # fallback tries to create it
                 try:
                     ftp.mkd(FTP_PATH)
                     ftp.cwd(FTP_PATH)
                     print(f"✅ Cartella {FTP_PATH} creata.")
                 except Exception as e2:
                     print(f"❌ Fallita creazione root: {e2}")

        print(f"📤 Upload ricorsivo dei file in corso...")
        start_time = datetime.now()
        
        for root, dirs, files in os.walk("."):
            dirs[:] = [d for d in dirs if d not in EXCLUDE_DIRS]
            
            for file in files:
                if file in EXCLUDE_FILES or file.endswith(".example"):
                    continue
                
                local_path = os.path.join(root, file)
                rel_path = os.path.relpath(local_path, ".")
                
                dirs_to_make = os.path.dirname(rel_path).split(os.sep)
                
                if FTP_PATH and FTP_PATH.strip() and FTP_PATH != '/':
                    ftp.cwd('/' + FTP_PATH)
                else:
                    ftp.cwd('/')

                if dirs_to_make and dirs_to_make[0] != '':
                    for d in dirs_to_make:
                        try:
                            ftp.cwd(d)
                        except:
                            ftp.mkd(d)
                            ftp.cwd(d)
                            
                with open(local_path, 'rb') as f:
                    try:
                        ftp.storbinary(f'STOR {file}', f)
                    except:
                         pass

        elapsed = (datetime.now() - start_time).total_seconds()
        print(f"✅ Deploy completato in {elapsed:.1f} secondi.")
        ftp.quit()
    except Exception as e:
        print(f"❌ Errore FTP: {e}")
        sys.exit(1)

def main():
    upload_ftp()

if __name__ == "__main__":
    main()
