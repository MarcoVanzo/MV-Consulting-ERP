<?php
/**
 * Avvisi — segnalazioni raccolte durante una richiesta da codice che non risponde direttamente
 * all'utente (es. una fattura che non corrisponde a nessuna rata). Response::json le aggiunge
 * alla risposta in data.avvisi_sistema, così l'import le mostra invece di perderle nel log.
 */
declare(strict_types=1);

class Avvisi
{
    private static array $avvisi = [];

    public static function aggiungi(string $testo): void
    {
        if (!in_array($testo, self::$avvisi, true)) self::$avvisi[] = $testo;
    }

    public static function tutti(): array
    {
        return self::$avvisi;
    }

    public static function svuota(): void
    {
        self::$avvisi = [];
    }
}
