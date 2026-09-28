<?php
/**
 * ClaudeClient — chiamata alla Messages API di Anthropic con risposta JSON vincolata a uno schema.
 *
 * HTTP diretto con cURL e non l'SDK PHP: l'ERP non usa composer e va online via FTP,
 * quindi una dipendenza in vendor/ non arriverebbe sul server.
 *
 * Configurazione (.env): ANTHROPIC_API_KEY (obbligatoria), ANTHROPIC_MODEL (default claude-opus-5).
 */
declare(strict_types=1);

class ClaudeClient
{
    private const API_URL = 'https://api.anthropic.com/v1/messages';
    private const DEFAULT_MODEL = 'claude-opus-5';
    /** Tempo massimo complessivo della chiamata (sotto il timeout del proxy) */
    private const BUDGET_SECONDS = 95;
    /** Secondi residui minimi per tentare di nuovo */
    private const MIN_RETRY_SECONDS = 30;

    public static function isConfigured(): bool
    {
        return (string)getenv('ANTHROPIC_API_KEY') !== '';
    }

    /**
     * Invia un documento (PDF in base64 oppure testo) con un'istruzione e restituisce
     * l'oggetto JSON prodotto dal modello, già validato dall'API contro $schema.
     *
     * @param array{pdf_base64?: string, image_base64?: string, media_type?: string, text?: string} $document
     * @throws RuntimeException se la chiamata fallisce o il modello non risponde col JSON atteso
     */
    public static function extractJson(string $system, string $instruction, array $document, array $schema): array
    {
        $apiKey = (string)getenv('ANTHROPIC_API_KEY');
        if ($apiKey === '') {
            throw new RuntimeException('ANTHROPIC_API_KEY non configurata');
        }

        $content = [];
        if (!empty($document['pdf_base64'])) {
            $content[] = [
                'type' => 'document',
                'source' => ['type' => 'base64', 'media_type' => 'application/pdf', 'data' => $document['pdf_base64']],
            ];
        } elseif (!empty($document['image_base64'])) {
            // Foto di scontrini e ricevute: JPEG, PNG, WebP o GIF
            $media = in_array($document['media_type'] ?? '', ['image/jpeg', 'image/png', 'image/webp', 'image/gif'], true) ? $document['media_type'] : 'image/jpeg';
            $content[] = [
                'type' => 'image',
                'source' => ['type' => 'base64', 'media_type' => $media, 'data' => $document['image_base64']],
            ];
        } elseif (isset($document['text']) && trim($document['text']) !== '') {
            $content[] = ['type' => 'text', 'text' => "<documento>\n" . $document['text'] . "\n</documento>"];
        } else {
            throw new RuntimeException('Documento vuoto');
        }
        $content[] = ['type' => 'text', 'text' => $instruction];

        $body = [
            'model' => getenv('ANTHROPIC_MODEL') ?: self::DEFAULT_MODEL,
            'max_tokens' => 16000,
            'system' => $system,
            'messages' => [['role' => 'user', 'content' => $content]],
            // Estrazione: poco ragionamento basta, e tiene la chiamata entro i tempi del PHP condiviso
            'output_config' => [
                'effort' => 'low',
                'format' => ['type' => 'json_schema', 'schema' => $schema],
            ],
            // Se i filtri di sicurezza rifiutano la richiesta, l'API la ripete su un altro modello
            'fallbacks' => 'default',
        ];

        // Testi in codifiche diverse da UTF-8 (es. .txt da Windows): caratteri sostituiti, non richiesta vuota
        $payload = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
        if ($payload === false) {
            throw new RuntimeException('Documento non leggibile (codifica del testo)');
        }

        // Stessa richiesta già fatta (es. anteprima e poi import dello stesso file): risposta dalla cache
        $cache = self::fileCache($payload);
        if (is_file($cache) && filemtime($cache) > time() - self::CACHE_TTL) {
            $salvato = json_decode((string)file_get_contents($cache), true);
            if (is_array($salvato)) return $salvato;
        }

        // Il proxy di Aruba chiude le richieste dopo circa 100 s: tutto (tentativi compresi) sta sotto i 95 s
        $scadenza = microtime(true) + self::BUDGET_SECONDS;
        @set_time_limit(self::BUDGET_SECONDS + 15);
        $ch = curl_init(self::API_URL);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_HTTPHEADER => [
                'Content-Type: application/json',
                'x-api-key: ' . $apiKey,
                'anthropic-version: 2023-06-01',
                'anthropic-beta: server-side-fallback-2026-07-01',
            ],
            CURLOPT_POSTFIELDS => $payload,
        ]);

        // Un solo nuovo tentativo, e solo su errori rapidi e temporanei (connessione, 429, 5xx).
        // Dopo un timeout no: il tempo è finito e la richiesta potrebbe essere ancora in lavorazione.
        $raw = false;
        $httpCode = 0;
        $curlErrno = 0;
        $curlError = '';
        for ($attempt = 1; $attempt <= 2; $attempt++) {
            $residuo = (int)floor($scadenza - microtime(true));
            curl_setopt($ch, CURLOPT_TIMEOUT, max(5, $residuo));
            $raw = curl_exec($ch);
            $httpCode = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $curlErrno = curl_errno($ch);
            $curlError = curl_error($ch);
            $erroreRete = $raw === false && $curlErrno !== CURLE_OPERATION_TIMEOUTED;
            $retryable = $erroreRete || $httpCode === 429 || $httpCode >= 500;
            // Serve margine per un secondo tentativo sensato
            if (!$retryable || $attempt === 2 || ($scadenza - microtime(true)) < self::MIN_RETRY_SECONDS) break;
            sleep(3);
        }

        if ($raw === false && $curlErrno === CURLE_OPERATION_TIMEOUTED) {
            throw new RuntimeException('Il servizio AI non ha risposto in tempo: riprova o usa un documento più breve');
        }
        if ($raw === false) {
            throw new RuntimeException('Servizio AI non raggiungibile: ' . $curlError);
        }
        $response = json_decode((string)$raw, true);
        if ($httpCode !== 200 || !is_array($response)) {
            $msg = $response['error']['message'] ?? ('HTTP ' . $httpCode);
            error_log('[ClaudeClient] ' . $httpCode . ' ' . substr((string)$raw, 0, 500));
            throw new RuntimeException('Errore del servizio AI: ' . $msg);
        }

        $stopReason = $response['stop_reason'] ?? '';
        if ($stopReason === 'refusal') {
            throw new RuntimeException('Il servizio AI ha rifiutato di elaborare il documento');
        }
        if ($stopReason === 'max_tokens') {
            throw new RuntimeException('Risposta AI troncata: documento troppo lungo');
        }

        foreach ($response['content'] ?? [] as $block) {
            if (($block['type'] ?? '') === 'text') {
                $data = json_decode($block['text'], true);
                if (is_array($data)) {
                    self::salvaCache($cache, $data);
                    return $data;
                }
            }
        }
        throw new RuntimeException('Risposta AI non interpretabile');
    }

    /** Durata della cache delle risposte (secondi). */
    private const CACHE_TTL = 7 * 86400;

    private static function fileCache(string $payload): string
    {
        return dirname(__DIR__, 2) . '/storage/cache/ai/' . hash('sha256', $payload) . '.json';
    }

    /** Salva la risposta e toglie quelle scadute; un errore di scrittura non blocca l'estrazione. */
    private static function salvaCache(string $file, array $data): void
    {
        $dir = dirname($file);
        if (!is_dir($dir) && !@mkdir($dir, 0750, true)) return;
        @file_put_contents($file, json_encode($data, JSON_UNESCAPED_UNICODE), LOCK_EX);
        foreach (glob($dir . '/*.json') ?: [] as $f) {
            if (filemtime($f) < time() - self::CACHE_TTL) @unlink($f);
        }
    }
}
