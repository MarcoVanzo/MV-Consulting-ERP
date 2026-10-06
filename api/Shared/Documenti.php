<?php
/**
 * Documenti — archivio dei file allegati a offerte, incarichi e costi dei partner.
 *
 * I file stanno in storage/documenti/ con nome casuale (non in uploads/, che è pubblica):
 * contengono prezzi e condizioni, si scaricano solo dall'API con utente autenticato.
 */
declare(strict_types=1);

class Documenti
{
    private const ESTENSIONI = ['pdf', 'docx', 'doc', 'txt', 'md', 'jpg', 'jpeg', 'png', 'webp'];
    private const MIME = [
        'pdf' => 'application/pdf',
        'docx' => 'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
        'doc' => 'application/msword',
        'txt' => 'text/plain; charset=utf-8',
        'md' => 'text/markdown; charset=utf-8',
        'jpg' => 'image/jpeg',
        'jpeg' => 'image/jpeg',
        'png' => 'image/png',
        'webp' => 'image/webp',
    ];

    private static function dir(): string
    {
        return dirname(__DIR__, 2) . '/storage/documenti/';
    }

    /**
     * Salva il file caricato nel campo $campo. Restituisce il riferimento da tenere nel DB
     * ("documenti/<hash>.<ext>") oppure null se non è stato caricato nulla.
     * @throws RuntimeException per file non ammessi
     */
    public static function salvaUpload(string $campo): ?string
    {
        $f = $_FILES[$campo] ?? null;
        if (!$f || ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) return null;
        if ($f['error'] !== UPLOAD_ERR_OK) {
            throw new RuntimeException('Caricamento del file non riuscito');
        }
        $ext = strtolower(pathinfo((string)$f['name'], PATHINFO_EXTENSION));
        if (!in_array($ext, self::ESTENSIONI, true)) {
            throw new RuntimeException('Tipo di file non ammesso: ' . $ext);
        }
        $max = (int)(getenv('MAX_UPLOAD_SIZE') ?: 10485760);
        if ($f['size'] > $max) {
            throw new RuntimeException('File troppo grande');
        }
        $dir = self::dir();
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $name = bin2hex(random_bytes(16)) . '.' . $ext;
        if (!move_uploaded_file($f['tmp_name'], $dir . $name)) {
            throw new RuntimeException('Impossibile salvare il file');
        }
        // In anteprima il record si annulla: il file non deve restare orfano
        if (class_exists('Anteprima') && Anteprima::attiva()) {
            $percorso = $dir . $name;
            Anteprima::allaFine(fn() => @unlink($percorso));
        }
        return 'documenti/' . $name;
    }

    /**
     * Percorso assoluto di un riferimento, o null se il riferimento non è valido o il file manca.
     * Accetta anche gli allegati delle manutenzioni salvati prima in uploads/ (ora non più pubblica).
     */
    public static function percorso(?string $ref): ?string
    {
        if ($ref && preg_match('#^documenti/[a-f0-9]{32}\.([a-z]{2,4})$#', $ref)) {
            $path = dirname(__DIR__, 2) . '/storage/' . $ref;
        } elseif ($ref && preg_match('#^uploads/manutenzioni/maint_\d+_[a-f0-9]{8}\.(pdf|jpe?g|png|docx?)$#', $ref)) {
            $path = dirname(__DIR__, 2) . '/' . $ref;
        } else {
            return null;
        }
        return is_file($path) ? $path : null;
    }

    /** Invia il file al browser e termina la richiesta. */
    public static function invia(?string $ref, string $nomeDownload): void
    {
        $path = self::percorso($ref);
        if (!$path) {
            Response::json(false, 'Documento non trovato', null, 404);
        }
        $ext = strtolower(pathinfo($path, PATHINFO_EXTENSION));
        $nome = preg_replace('/[^A-Za-z0-9._-]+/', '_', $nomeDownload) . '.' . $ext;
        header_remove('Content-Type');
        header('Content-Type: ' . (self::MIME[$ext] ?? 'application/octet-stream'));
        // Solo PDF e immagini si aprono nel browser; il resto si scarica e non viene interpretato
        $inline = in_array($ext, ['pdf', 'jpg', 'jpeg', 'png'], true);
        header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $nome . '"');
        header('Content-Length: ' . filesize($path));
        header('Cache-Control: private, no-store');
        readfile($path);
        exit;
    }

    /**
     * Elimina i file mai collegati a nulla (es. PDF analizzati ma poi non salvati), più vecchi di 2 giorni.
     * @return int file eliminati
     */
    public static function pulisciOrfani(PDO $pdo, string $prefix): int
    {
        $dir = self::dir();
        if (!is_dir($dir)) return 0;
        $usati = [];
        foreach ([["offerte", "file_path"], ["incarichi", "pdf_path"], ["commessa_costi", "offerta_fornitore_file"], ["mezzi_manutenzioni", "allegato_url"], ["spese", "documento"]] as [$t, $c]) {
            foreach ($pdo->query("SELECT $c FROM {$prefix}$t WHERE $c LIKE 'documenti/%'")->fetchAll(PDO::FETCH_COLUMN) as $ref) {
                $usati[$ref] = true;
            }
        }
        // I file delle commesse nel cestino restano finché la voce non viene ripristinata o svuotata
        require_once __DIR__ . '/Cestino.php';
        foreach (Cestino::fileTrattenuti($pdo, $prefix) as $ref) $usati[$ref] = true;
        $n = 0;
        foreach (glob($dir . '*') ?: [] as $path) {
            $ref = 'documenti/' . basename($path);
            if (!isset($usati[$ref]) && is_file($path) && filemtime($path) < time() - 2 * 86400) {
                @unlink($path);
                $n++;
            }
        }
        return $n;
    }

    /**
     * Sposta in storage/documenti gli allegati delle manutenzioni salvati in passato in uploads/.
     * Sull'hosting il web server statico non applica sempre .htaccess: i file in uploads/ vanno tolti
     * da lì, non solo bloccati. Idempotente; i riferimenti a file mancanti restano come sono.
     * @return int allegati spostati
     */
    public static function migraAllegatiStorici(PDO $pdo, string $prefix): int
    {
        $rows = $pdo->query("SELECT id, allegato_url FROM {$prefix}mezzi_manutenzioni WHERE allegato_url LIKE 'uploads/%'")->fetchAll(PDO::FETCH_ASSOC);
        $dir = self::dir();
        $n = 0;
        foreach ($rows as $r) {
            $da = self::percorso($r['allegato_url']);
            if (!$da) continue;
            if (!is_dir($dir)) mkdir($dir, 0750, true);
            $ref = 'documenti/' . bin2hex(random_bytes(16)) . '.' . strtolower(pathinfo($da, PATHINFO_EXTENSION));
            if (!@rename($da, dirname(__DIR__, 2) . '/storage/' . $ref)) continue;
            $pdo->prepare("UPDATE {$prefix}mezzi_manutenzioni SET allegato_url = ? WHERE id = ?")->execute([$ref, $r['id']]);
            $n++;
        }
        return $n;
    }

    public static function elimina(?string $ref): void
    {
        $path = self::percorso($ref);
        if ($path) @unlink($path);
    }
}
