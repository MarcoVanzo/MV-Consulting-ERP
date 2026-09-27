<?php
/**
 * Mailer — invio email in SMTP
 * MV Consulting ERP
 *
 * Riusa invio-smtp.php e config-smtp.php del sito vetrina, che sul server stanno
 * nella cartella sopra /ERP. Non si usa mail(): su Aruba consegna a sendmail e la
 * richiesta resta appesa fino al timeout del proxy.
 */
declare(strict_types=1);

class Mailer
{
    public static function send(string $to, ?string $name, string $subject, string $message): bool
    {
        $root = dirname(__DIR__, 3);
        $libreria = $root . '/invio-smtp.php';
        $config = $root . '/config-smtp.php';
        if (!is_readable($libreria) || !is_readable($config)) {
            error_log('Mailer: invio-smtp.php o config-smtp.php non trovati in ' . $root);
            return false;
        }

        require_once $libreria;
        $accesso = require $config;
        // Il mittente è la casella autenticata: è l'unico che il server accetti
        $mittente = (string)($accesso['utente'] ?? '');
        $intestazioni = [
            'From: MV Consulting ERP <' . $mittente . '>',
            'Content-Type: text/plain; charset=UTF-8',
        ];

        // Oggetto codificato: un accento in chiaro rende l'intestazione malformata
        $oggetto = '=?UTF-8?B?' . base64_encode($subject) . '?=';
        [$ok, $errore] = spedisciSmtp($accesso, $mittente, $to, $oggetto, $message, $intestazioni);
        if (!$ok) {
            error_log('Mailer: invio a ' . $to . ' non riuscito: ' . $errore);
        }
        return $ok;
    }
}
