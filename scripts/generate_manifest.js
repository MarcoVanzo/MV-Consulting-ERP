const fs = require('fs');
const path = require('path');
const crypto = require('crypto');

// ═══════════════════════════════════════════════════════════════
// MV Consulting ERP — Deploy Manifest Generator v3
// Genera manifest con hash SHA-256 per deploy incrementale
// ═══════════════════════════════════════════════════════════════

const EXCLUDE_DIR_NAMES = [
    '.git', 'node_modules', '__pycache__', '.vscode', '.idea',
    '.gemini', '.agents', 'storage', 'uploads',
    'tmp_pdf_parse', 'tmp_venv', 'PDF Pagamenti',
    'scripts', 'tests',
];

const EXCLUDE_DIR_PATHS = ['.github'];

const EXCLUDE_FILES = [
    'deploy_manifest.json', '.deploy_cache.json', '.deploy.lock',
    '.deploy_history.log', 'deploy.py', 'deploy', 'deploy.config',
    '.DS_Store', '.env', '.env.deploy', '.env.local',
    '.env.production', '.env.example',
    'package.json', 'package-lock.json',
    'query.php', 'test_sync.php',
    'README.md',
];

const EXCLUDE_PREFIXES = [
    'test_', 'debug_', 'scratch_', 'fix_', 'tmp_',
    'reset_', 'setup_db', 'check_db', 'migrate_',
    'cleanup', 'db_dump', 'delete_token',
    'query_', 'deploy_debug', 'list_tables',
];

const EXCLUDE_EXTENSIONS = [
    '.zip', '.log', '.swp', '.swo', '.pyc', '.sql', '.db', '.sqlite', '.py',
];

const EXCLUDE_PATHS = [];

function fileSHA256(filePath) {
    const content = fs.readFileSync(filePath);
    return crypto.createHash('sha256').update(content).digest('hex');
}

function getFiles(dir, allFiles = []) {
    const files = fs.readdirSync(dir);
    files.forEach(file => {
        const filePath = path.join(dir, file);
        const stats = fs.statSync(filePath);
        const relPath = path.relative(process.cwd(), filePath).replace(/\\/g, '/');

        if (stats.isDirectory()) {
            const dirName = path.basename(filePath);
            if (EXCLUDE_DIR_NAMES.includes(dirName)) return;
            if (dirName.startsWith('.')) return;
            if (EXCLUDE_DIR_PATHS.some(p => relPath === p || relPath.startsWith(p + '/'))) return;
            getFiles(filePath, allFiles);
        } else {
            const ext = path.extname(file);
            const baseName = path.basename(file);
            if (baseName.startsWith('.') && baseName !== '.htaccess') return;
            if (EXCLUDE_FILES.includes(baseName)) return;
            if (EXCLUDE_EXTENSIONS.includes(ext)) return;
            if (EXCLUDE_PREFIXES.some(prefix => baseName.startsWith(prefix))) return;
            if (EXCLUDE_PATHS.includes(relPath)) return;
            if (ext.length === 0 && baseName !== '.htaccess') return;
            if (baseName.includes('.bak') || baseName.includes('.orig')) return;

            const hash = fileSHA256(filePath);
            allFiles.push({ path: relPath, hash });
        }
    });
    return allFiles;
}

console.log('═══════════════════════════════════════════');
console.log('  MV Consulting ERP — Manifest Generator v3');
console.log('═══════════════════════════════════════════');

try {
    const startTime = Date.now();
    const files = getFiles(process.cwd());
    files.sort((a, b) => a.path.localeCompare(b.path));

    const manifest = {
        version: 3,
        generated_at: new Date().toISOString(),
        file_count: files.length,
        files: files,
    };

    fs.writeFileSync('deploy_manifest.json', JSON.stringify(manifest, null, 2));
    const elapsed = Date.now() - startTime;
    console.log(`✅ Manifest generato: ${files.length} file con hash SHA-256 (${elapsed}ms)`);
} catch (err) {
    console.error('❌ Errore generazione manifest:', err.message);
    process.exit(1);
}
