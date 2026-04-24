<?php
/**
 * GoogleDrive — Backup Upload Helper
 * Fusion ERP v1.0
 *
 * Uses OAuth2 refresh_token (no Service Account key needed).
 * Applies immutability features:
 *   1. contentRestrictions (readOnly + ownerRestricted) — file locked
 *   2. Revision keepForever                             — version preserved forever
 *   3. HMAC-SHA256 in file description                  — tamper detection
 *
 * Required .env variables:
 *   GDRIVE_CLIENT_ID
 *   GDRIVE_CLIENT_SECRET
 *   GDRIVE_REFRESH_TOKEN
 *   GDRIVE_FOLDER_ID
 *   APP_SECRET (used as HMAC key)
 */

namespace FusionERP\Shared;

class GoogleDrive
{
    // ── Static convenience wrapper ────────────────────────────────────────────

    /**
     * Upload a file to Google Drive, lock it immutably, and return its Drive file ID.
     *
     * @param string $filePath  Absolute path to the local file
     * @param string $filename  Filename to use on Drive (defaults to basename)
     * @return string           Drive file ID
     * @throws \RuntimeException on failure
     */
    public static function uploadFile(string $filePath, string $filename = ''): string
    {
        if (empty($filename)) {
            $filename = basename($filePath);
        }

        $instance = new self(
            getenv('GDRIVE_CLIENT_ID') ?: '',
            getenv('GDRIVE_CLIENT_SECRET') ?: '',
            getenv('GDRIVE_REFRESH_TOKEN') ?: '',
            getenv('GDRIVE_FOLDER_ID') ?: ''
            );

        if (!$instance->isConfigured()) {
            throw new \RuntimeException('Google Drive non configurato: verifica GDRIVE_CLIENT_ID, GDRIVE_CLIENT_SECRET, GDRIVE_REFRESH_TOKEN, GDRIVE_FOLDER_ID nel .env');
        }

        $hmacSecret = getenv('APP_SECRET') ?: 'fusion_erp_default';
        $result = $instance->uploadAndLock($filePath, $filename, $hmacSecret);

        if ($result['status'] !== 'success') {
            throw new \RuntimeException($result['error'] ?? 'Upload Drive fallito');
        }

        return $result['fileId'];
    }

    // ── Instance implementation ───────────────────────────────────────────────

    private string $clientId;
    private string $clientSecret;
    private string $refreshToken;
    private string $folderId;
    private ?string $accessToken = null;
    private int $tokenExpiry = 0;

    public function __construct(string $clientId, string $clientSecret, string $refreshToken, string $folderId)
    {
        $this->clientId = $clientId;
        $this->clientSecret = $clientSecret;
        $this->refreshToken = $refreshToken;
        $this->folderId = $folderId;
    }

    public function isConfigured(): bool
    {
        return !empty($this->clientId)
            && !empty($this->clientSecret)
            && !empty($this->refreshToken)
            && !empty($this->folderId);
    }

    /**
     * Upload, lock, and sign a backup file.
     *
     * @return array{status:string, fileId?:string, hmac?:string, locked?:bool,
     *               keepForever?:bool, error?:string}
     */
    public function uploadAndLock(string $filePath, string $filename, string $hmacSecret): array
    {
        if (!$this->isConfigured()) {
            return ['status' => 'error', 'error' => 'Google Drive non configurato'];
        }

        try {
            // 1. Get access token
            $token = $this->getAccessToken();
            if (!$token) {
                return ['status' => 'error', 'error' => 'Autenticazione Google fallita (refresh token non valido?)'];
            }

            // 2. HMAC signature
            $hmac = hash_hmac_file('sha256', $filePath, $hmacSecret);
            if ($hmac === false) {
                return ['status' => 'error', 'error' => "Hash fallito per: {$filePath}"];
            }

            // 3. Upload (Streams directly from disk to avoid memory leaks)
            $fileId = $this->doUpload($filePath, $filename, $token);
            if (!$fileId) {
                return ['status' => 'error', 'error' => 'Upload su Google Drive fallito'];
            }

            // 4. Lock (contentRestrictions readOnly + ownerRestricted)
            $locked = $this->lockFile($fileId, $token);

            // 5. keepForever on latest revision
            $kept = $this->setKeepForever($fileId, $token);

            // 6. Store HMAC in file description
            $this->setDescription(
                $fileId,
                'HMAC-SHA256: ' . $hmac . ' | Fusion ERP Backup ' . date('d/m/Y H:i'),
                $token
            );

            return [
                'status' => 'success',
                'fileId' => $fileId,
                'hmac' => $hmac,
                'locked' => $locked,
                'keepForever' => $kept,
            ];
        }
        catch (\Throwable $e) {
            error_log('[FUSION-ERP] GoogleDrive error: ' . $e->getMessage());
            return ['status' => 'error', 'error' => $e->getMessage()];
        }
    }

    // ── Private helpers ───────────────────────────────────────────────────────

    private function getAccessToken(): ?string
    {
        // Return cached token if still valid (with 60s margin)
        if ($this->accessToken && time() < $this->tokenExpiry - 60) {
            return $this->accessToken;
        }

        $ch = curl_init('https://oauth2.googleapis.com/token');
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => http_build_query([
                'client_id' => $this->clientId,
                'client_secret' => $this->clientSecret,
                'refresh_token' => $this->refreshToken,
                'grant_type' => 'refresh_token',
            ]),
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            error_log('[FUSION-ERP] Google OAuth refresh failed: HTTP ' . $httpCode . ' — ' . $response);
            return null;
        }

        $data = json_decode($response, true);
        $this->accessToken = $data['access_token'] ?? null;
        $this->tokenExpiry = time() + ($data['expires_in'] ?? 3600);

        return $this->accessToken;
    }

    private function doUpload(string $filePath, string $filename, string $token): ?string
    {
        $mimeType = 'application/zip';
        if (str_ends_with($filename, '.sql')) {
            $mimeType = 'application/sql';
        }

        $filesize = filesize($filePath);
        if ($filesize === false) {
            return null;
        }

        // STEP 1: Init Resumable Session
        $metadata = json_encode([
            'name' => $filename,
            'parents' => [$this->folderId],
            'mimeType' => $mimeType,
            'description' => 'Fusion ERP Automatic Backup — ' . date('d/m/Y H:i'),
        ]);

        $ch1 = curl_init('https://www.googleapis.com/upload/drive/v3/files?uploadType=resumable');
        curl_setopt_array($ch1, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $metadata,
            CURLOPT_HEADER => true,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$token}",
                "Content-Type: application/json; charset=UTF-8",
                "X-Upload-Content-Type: {$mimeType}",
                "X-Upload-Content-Length: {$filesize}"
            ],
            CURLOPT_SSL_VERIFYPEER => true,
        ]);

        $response1 = curl_exec($ch1);
        $httpCode1 = curl_getinfo($ch1, CURLINFO_HTTP_CODE);
        $headerSize = curl_getinfo($ch1, CURLINFO_HEADER_SIZE);
        $headers = substr($response1, 0, $headerSize);

        if ($httpCode1 !== 200) {
            error_log('[FUSION-ERP] Resumable upload init failed: HTTP ' . $httpCode1);
            return null;
        }

        $locationMatches = [];
        if (!preg_match('/^Location:\s*(.*)$/mi', $headers, $locationMatches)) {
            error_log('[FUSION-ERP] Resumable upload location missing');
            return null;
        }
        $uploadUrl = trim($locationMatches[1]);

        // STEP 2: Stream Data via PUT
        $fh = fopen($filePath, 'r');
        if (!$fh) {
            return null;
        }

        $ch2 = curl_init($uploadUrl);
        curl_setopt_array($ch2, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_PUT => true,
            CURLOPT_INFILE => $fh,
            CURLOPT_INFILESIZE => $filesize,
            CURLOPT_HTTPHEADER => [
                "Content-Length: {$filesize}",
                "Content-Type: {$mimeType}"
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 300,
        ]);

        $response2 = curl_exec($ch2);
        $httpCode2 = curl_getinfo($ch2, CURLINFO_HTTP_CODE);
        fclose($fh);

        if ($httpCode2 !== 200 && $httpCode2 !== 201) {
            error_log('[FUSION-ERP] Drive resumable upload failed: HTTP ' . $httpCode2 . ' — ' . $response2);
            return null;
        }

        $data = json_decode($response2, true);
        return $data['id'] ?? null;
    }

    /**
     * Apply Content Restriction — makes the file read-only and owner-restricted.
     * This is as close to "immutable" as Google Drive permits via API.
     */
    private function lockFile(string $fileId, string $token): bool
    {
        $payload = json_encode([
            'contentRestrictions' => [[
                    'readOnly' => true,
                    'reason' => 'Backup Fusion ERP immutabile — bloccato automaticamente',
                    'ownerRestricted' => true,
                ]],
        ]);

        $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => $payload,
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        if ($httpCode !== 200) {
            error_log('[FUSION-ERP] Content restriction failed: HTTP ' . $httpCode . ' — ' . $response);
            return false;
        }

        return true;
    }

    /**
     * Set keepForever on the latest revision — prevents Google auto-purging old versions.
     */
    private function setKeepForever(string $fileId, string $token): bool
    {
        // Get revision list
        $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}/revisions?fields=revisions(id)");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER => ["Authorization: Bearer {$token}"],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $response = curl_exec($ch);

        $data = json_decode($response, true);
        $revisions = $data['revisions'] ?? [];
        if (empty($revisions))
            return false;

        $revisionId = end($revisions)['id'];

        $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}/revisions/{$revisionId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode(['keepForever' => true]),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 15,
        ]);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);

        return $httpCode === 200;
    }

    private function setDescription(string $fileId, string $description, string $token): void
    {
        $ch = curl_init("https://www.googleapis.com/drive/v3/files/{$fileId}");
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST => 'PATCH',
            CURLOPT_POSTFIELDS => json_encode(['description' => $description]),
            CURLOPT_HTTPHEADER => [
                "Authorization: Bearer {$token}",
                'Content-Type: application/json',
            ],
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        curl_exec($ch);

    }
}