<?php
/**
 * Env — Caricamento centralizzato variabili d'ambiente
 * MV Consulting ERP
 * 
 * FIX 2.3: Elimina la duplicazione del parsing .env tra router.php e migrate.php
 */
declare(strict_types=1);

class Env
{
    private static bool $loaded = false;

    /**
     * Carica le variabili dal file .env (idempotente).
     */
    public static function load(?string $path = null): void
    {
        if (self::$loaded) return;

        $envPath = $path ?? dirname(__DIR__, 2) . '/.env';
        if (!file_exists($envPath)) return;

        $lines = file($envPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
        foreach ($lines as $line) {
            if (strpos(trim($line), '#') === 0) continue;
            if (strpos($line, '=') === false) continue;
            [$name, $value] = explode('=', $line, 2);
            $name = trim($name);
            $value = trim($value, " \t\n\r\0\x0B\"");
            putenv("$name=$value");
            $_ENV[$name] = $value;
        }

        self::$loaded = true;
    }
}
