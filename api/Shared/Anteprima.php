<?php
/**
 * Anteprima — esegue un import vero e poi annulla tutto.
 *
 * L'import gira dentro una transazione esterna (le sue transazioni diventano savepoint, vedi MvPdo);
 * la risposta del controller viene catturata invece di uscire (Response::$cattura), poi la
 * transazione si annulla. Così l'anteprima usa lo stesso codice dell'import e mostra esattamente
 * cosa succederebbe. Il codice con effetti fuori dal database (proposte AI, email) controlla
 * Anteprima::attiva() e in anteprima non li esegue.
 */
declare(strict_types=1);

require_once __DIR__ . '/Response.php';

class Anteprima
{
    private static bool $attiva = false;
    /** Da eseguire a fine anteprima (es. cancellare i file caricati: il DB si annulla, il disco no). */
    private static array $allaFine = [];

    public static function allaFine(callable $f): void
    {
        self::$allaFine[] = $f;
    }

    public static function attiva(): bool
    {
        return self::$attiva;
    }

    /** @return array la risposta che il controller avrebbe dato (success, message, data) */
    public static function esegui(PDO $pdo, callable $import): array
    {
        self::$attiva = true;
        Response::$cattura = true;
        $pdo->beginTransaction();
        try {
            $import();
            $esito = ['success' => false, 'message' => 'L\'import non ha dato risposta'];
        } catch (RispostaCatturata $r) {
            $esito = $r->risposta;
        } catch (Throwable $e) {
            // Il dettaglio (anche SQL) resta nel log, come nel router
            error_log('[Anteprima] ' . $e->getMessage());
            $esito = ['success' => false, 'message' => 'Anteprima non riuscita: errore interno, riprova o importa il file da solo'];
        } finally {
            // Annulla tutto, anche i savepoint lasciati aperti da un'eccezione dentro l'import
            while ($pdo->inTransaction()) $pdo->rollBack();
            Response::fineCattura();
            self::$attiva = false;
            foreach (self::$allaFine as $f) {
                try { $f(); } catch (Throwable $e) { error_log('[Anteprima] pulizia: ' . $e->getMessage()); }
            }
            self::$allaFine = [];
        }
        return $esito;
    }
}
