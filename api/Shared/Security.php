<?php
declare(strict_types=1);

class Security
{
    public static function generateTempPassword(int $length = 14): string
    {
        $chars_lower = 'abcdefghjkmnpqrstuvwxyz';
        $chars_upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $chars_digit = '23456789';
        $chars_special = '!@#$%^&*()';
        $all_chars = $chars_lower . $chars_upper . $chars_digit . $chars_special;

        try {
            $pass = $chars_lower[random_int(0, strlen($chars_lower) - 1)]
                . $chars_upper[random_int(0, strlen($chars_upper) - 1)]
                . $chars_digit[random_int(0, strlen($chars_digit) - 1)]
                . $chars_special[random_int(0, strlen($chars_special) - 1)];
            for ($i = 4; $i < $length; $i++) {
                $pass .= $all_chars[random_int(0, strlen($all_chars) - 1)];
            }
            
            $passArr = str_split($pass);
            for ($i = count($passArr) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$passArr[$i], $passArr[$j]] = [$passArr[$j], $passArr[$i]];
            }
            return implode('', $passArr);
        } catch (\Exception $e) {
            return substr(str_shuffle($all_chars), 0, $length);
        }
    }

    public static function validatePasswordComplexity(string $password): bool
    {
        return strlen($password) >= 12 &&
            preg_match('/[A-Z]/', $password) &&
            preg_match('/[a-z]/', $password) &&
            preg_match('/[0-9]/', $password) &&
            preg_match('/[^A-Za-z0-9]/', $password);
    }

    // ─── RATE LIMIT (file in storage/rate_limit, nessuna tabella) ─────────────

    /** Durata del blocco temporaneo dopo troppi tentativi falliti (secondi) */
    public const ACCOUNT_LOCK_SECONDS = 900;
    /** Tentativi falliti prima del blocco temporaneo */
    public const ACCOUNT_MAX_FAILED = 10;

    private static function rateLimitFile(string $bucket, string $key): string
    {
        $dir = dirname(__DIR__, 2) . '/storage/rate_limit';
        if (!is_dir($dir)) @mkdir($dir, 0755, true);
        return $dir . '/' . $bucket . '_' . md5($key) . '.json';
    }

    /** Timestamp dei tentativi ancora dentro la finestra */
    private static function rateLimitAttempts(string $file, int $windowSeconds): array
    {
        if (!file_exists($file)) return [];
        $attempts = json_decode((string)@file_get_contents($file), true) ?: [];
        return array_values(array_filter($attempts, fn($t) => is_int($t) && $t > time() - $windowSeconds));
    }

    /** true se il limite è già raggiunto (non registra il tentativo) */
    public static function isRateLimited(string $bucket, string $key, int $maxAttempts, int $windowSeconds): bool
    {
        return count(self::rateLimitAttempts(self::rateLimitFile($bucket, $key), $windowSeconds)) >= $maxAttempts;
    }

    /** Registra un tentativo nel bucket */
    public static function rateLimitHit(string $bucket, string $key, int $windowSeconds): void
    {
        $file = self::rateLimitFile($bucket, $key);
        $attempts = self::rateLimitAttempts($file, $windowSeconds);
        $attempts[] = time();
        @file_put_contents($file, json_encode($attempts), LOCK_EX);
    }

    /** Azzera il bucket (es. dopo un login riuscito) */
    public static function rateLimitReset(string $bucket, string $key): void
    {
        $file = self::rateLimitFile($bucket, $key);
        if (file_exists($file)) @unlink($file);
    }

    // ─── IP DEL CLIENT E HTTPS DIETRO PROXY ───────────────────────────────────

    /** Reti fidate di default (TRUSTED_PROXIES vuoto): loopback e reti private */
    private const DEFAULT_TRUSTED_PROXIES = '127.0.0.0/8,::1/128,10.0.0.0/8,172.16.0.0/12,192.168.0.0/16,fc00::/7';

    /**
     * IP del client. X-Forwarded-For conta solo se la richiesta arriva da un proxy fidato
     * (TRUSTED_PROXIES nel .env: IP o CIDR separati da virgola); si prende l'indirizzo più a
     * destra che non sia un proxy fidato: quelli a sinistra li scrive il client e sono falsificabili.
     */
    public static function clientIp(): string
    {
        $remote = (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
        $xff = (string)($_SERVER['HTTP_X_FORWARDED_FOR'] ?? '');
        if ($xff === '' || !self::isTrustedProxy($remote)) return $remote;

        $hops = array_reverse(array_values(array_filter(array_map('trim', explode(',', $xff)), 'strlen')));
        $ip = $remote;
        foreach ($hops as $hop) {
            if (!filter_var($hop, FILTER_VALIDATE_IP)) break; // voce malformata: ci si ferma all'ultimo IP valido
            $ip = $hop;
            if (!self::isTrustedProxy($hop)) break;
        }
        return $ip;
    }

    /** true se la richiesta è in HTTPS, anche quando il TLS termina sul proxy */
    public static function isHttps(): bool
    {
        if (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off') return true;
        if (getenv('APP_ENV') === 'production') return true;
        $proto = strtolower(trim(explode(',', (string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? ''))[0]));
        return $proto === 'https' && self::isTrustedProxy((string)($_SERVER['REMOTE_ADDR'] ?? ''));
    }

    public static function isTrustedProxy(string $ip): bool
    {
        if (!filter_var($ip, FILTER_VALIDATE_IP)) return false;
        $lista = trim((string)getenv('TRUSTED_PROXIES')) ?: self::DEFAULT_TRUSTED_PROXIES;
        foreach (explode(',', $lista) as $cidr) {
            $cidr = trim($cidr);
            if ($cidr !== '' && self::ipInCidr($ip, $cidr)) return true;
        }
        return false;
    }

    private static function ipInCidr(string $ip, string $cidr): bool
    {
        [$rete, $bits] = array_pad(explode('/', $cidr, 2), 2, null);
        $ipBin = @inet_pton($ip);
        $reteBin = @inet_pton((string)$rete);
        if ($ipBin === false || $reteBin === false || strlen($ipBin) !== strlen($reteBin)) return false;
        $max = strlen($ipBin) * 8;
        $bits = ($bits === null || $bits === '') ? $max : (int)$bits;
        if ($bits < 0 || $bits > $max) return false;
        $byte = intdiv($bits, 8);
        if (strncmp($ipBin, $reteBin, $byte) !== 0) return false;
        $resto = $bits % 8;
        if ($resto === 0) return true;
        $mask = (0xFF << (8 - $resto)) & 0xFF;
        return (ord($ipBin[$byte]) & $mask) === (ord($reteBin[$byte]) & $mask);
    }

    // ─── BLOCCO TEMPORANEO ACCOUNT ────────────────────────────────────────────

    /** Secondi residui di blocco, 0 se l'account non è bloccato */
    public static function accountLockRemaining($userId): int
    {
        $file = self::rateLimitFile('lock', (string)$userId);
        if (!file_exists($file)) return 0;
        $until = (int)@file_get_contents($file);
        if ($until <= time()) {
            @unlink($file);
            return 0;
        }
        return $until - time();
    }

    public static function lockAccount($userId, int $seconds = self::ACCOUNT_LOCK_SECONDS): void
    {
        @file_put_contents(self::rateLimitFile('lock', (string)$userId), (string)(time() + $seconds), LOCK_EX);
    }

    public static function unlockAccount($userId): void
    {
        self::rateLimitReset('lock', (string)$userId);
    }

    /**
     * Registra un tentativo fallito sull'account: incrementa failed_attempts e,
     * oltre la soglia, blocca l'account per ACCOUNT_LOCK_SECONDS (non più in modo permanente).
     */
    public static function registerFailedAttempt(PDO $db, string $prefix, $userId): void
    {
        try {
            $db->prepare("UPDATE {$prefix}users SET failed_attempts = COALESCE(failed_attempts, 0) + 1 WHERE id = ?")->execute([$userId]);
            $stmt = $db->prepare("SELECT failed_attempts FROM {$prefix}users WHERE id = ?");
            $stmt->execute([$userId]);
            if ((int)$stmt->fetchColumn() >= self::ACCOUNT_MAX_FAILED) {
                self::lockAccount($userId);
                // Nuova serie di tentativi alla scadenza del blocco
                $db->prepare("UPDATE {$prefix}users SET failed_attempts = 0 WHERE id = ?")->execute([$userId]);
                if (class_exists('Audit')) {
                    Audit::log('ACCOUNT_LOCK', 'users', (string)$userId, null, null, ['ip' => self::clientIp(), 'seconds' => self::ACCOUNT_LOCK_SECONDS], 'security');
                }
            }
        } catch (\Throwable $e) {
            error_log('registerFailedAttempt: ' . $e->getMessage());
        }
    }
}
