<?php
/**
 * ArchivioImport — conserva il file originale di ogni importazione (fatture XML/PDF, estratti conto,
 * avvisi di pagamento, lista fatture, lettere d'incarico, offerte).
 *
 * I file si salvano in storage/import/<sha256>.<ext>: lo stesso file caricato due volte occupa un
 * solo posto e la tabella {prefix}import_file ne tiene prima e ultima importazione. Serve come
 * giustificativo (conservazione) e per sapere se un file è già entrato.
 *
 * Non blocca mai l'importazione: se l'archivio non riesce, si scrive nel log e si prosegue.
 */
declare(strict_types=1);

class ArchivioImport
{
    /** Azioni di importazione → tipo di documento registrato. */
    public const AZIONI = [
        'contabilita' => [
            'import_pdf' => 'fattura_pdf',
            'import_xml' => 'fattura_xml',
            'import_payment_pdf' => 'avviso_pagamento',
            'import_lista' => 'lista_fatture',
        ],
        'passive' => ['import_xml' => 'fattura_passiva'],
        'riconciliazione' => ['import_estratto' => 'estratto'],
        'incarichi' => ['import_pdf' => 'lettera_incarico'],
        'offerte' => ['importa' => 'offerta'],
    ];

    private const ESTENSIONI = ['xml', 'p7m', 'pdf', 'xlsx', 'docx', 'doc', 'txt', 'md'];

    /** Archivia il file della richiesta se $module/$action è un'importazione. */
    public static function daRichiesta(PDO $pdo, string $prefix, string $module, string $action, array $data): ?array
    {
        $tipo = self::AZIONI[$module][$action] ?? null;
        if ($tipo === null) return null;
        try {
            [$contenuto, $nome] = self::contenuto($data);
            if ($contenuto === null || $contenuto === '') return null;
            if ($tipo === 'estratto' && ($data['tipo'] ?? '') === 'carta') $tipo = 'estratto_carta';
            return self::registra($pdo, $prefix, $tipo, $nome, $contenuto, (int)($GLOBALS['userContext']['id'] ?? 0) ?: null);
        } catch (Throwable $e) {
            error_log('[ArchivioImport] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Salva il contenuto (se non c'è già) e aggiorna il registro.
     * @return array{id:int, sha256:string, gia_importato:?string}
     */
    public static function registra(PDO $pdo, string $prefix, string $tipo, string $nome, string $contenuto, ?int $userId = null): array
    {
        $sha = hash('sha256', $contenuto);
        $ext = strtolower(pathinfo($nome, PATHINFO_EXTENSION));
        if (!in_array($ext, self::ESTENSIONI, true)) $ext = 'bin';
        $dir = self::dir();
        if (!is_dir($dir)) mkdir($dir, 0750, true);
        $file = $dir . $sha . '.' . $ext;
        if (!is_file($file) && file_put_contents($file, $contenuto, LOCK_EX) === false) {
            throw new RuntimeException('Impossibile salvare il file importato');
        }

        $stmt = $pdo->prepare("SELECT id, prima_importazione FROM {$prefix}import_file WHERE sha256 = ?");
        $stmt->execute([$sha]);
        $gia = $stmt->fetch(PDO::FETCH_ASSOC) ?: null;
        $ora = date('Y-m-d H:i:s');
        if ($gia) {
            $pdo->prepare("UPDATE {$prefix}import_file SET volte = volte + 1, ultima_importazione = ? WHERE id = ?")
                ->execute([$ora, $gia['id']]);
            return ['id' => (int)$gia['id'], 'sha256' => $sha, 'gia_importato' => (string)$gia['prima_importazione']];
        }
        $pdo->prepare("INSERT INTO {$prefix}import_file (sha256, tipo, nome_file, estensione, dimensione, user_id, prima_importazione, ultima_importazione)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
            ->execute([$sha, $tipo, mb_substr($nome, 0, 255), $ext, strlen($contenuto), $userId, $ora, $ora]);
        return ['id' => (int)$pdo->lastInsertId(), 'sha256' => $sha, 'gia_importato' => null];
    }

    /**
     * Il file originale della richiesta: campo "originale" (mandato apposta dal frontend), poi "file",
     * poi il contenuto testuale o base64 che alcune importazioni già inviano.
     * @return array{0:?string, 1:string} contenuto e nome del file
     */
    private static function contenuto(array $data): array
    {
        $nome = (string)($data['file_nome'] ?? '');
        foreach (['originale', 'file'] as $campo) {
            $f = $_FILES[$campo] ?? null;
            if ($f && ($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_OK && is_uploaded_file($f['tmp_name'])) {
                return [(string)file_get_contents($f['tmp_name']), $nome !== '' ? $nome : (string)$f['name']];
            }
        }
        if (!empty($data['file_b64']) && is_string($data['file_b64'])) {
            $bin = base64_decode($data['file_b64'], true);
            return [$bin === false ? null : $bin, $nome !== '' ? $nome : 'documento.p7m'];
        }
        if (!empty($data['xml']) && is_string($data['xml'])) {
            return [$data['xml'], $nome !== '' ? $nome : 'documento.xml'];
        }
        return [null, $nome];
    }

    /** Cartella dell'archivio; i test la spostano in una cartella temporanea. */
    public static ?string $cartella = null;

    private static function dir(): string
    {
        return self::$cartella ?? dirname(__DIR__, 2) . '/storage/import/';
    }
}
