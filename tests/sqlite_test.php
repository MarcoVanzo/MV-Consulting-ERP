<?php
/**
 * SQLite in memoria per i test CLI, incluso dopo api/Shared/Database.php (non è un test:
 * la CI lancia solo tests/*_cli.php). Da PHP 8.4 c'è Pdo\Sqlite con createFunction();
 * PDO::sqliteCreateFunction() è deprecato in 8.5 e resta solo per le versioni precedenti.
 */

/** PDO SQLite con le transazioni annidabili di MvPdo. */
function mvPdoSqliteTest(): PDO {
    if (!class_exists('Pdo\\Sqlite')) return new MvPdo('sqlite::memory:');
    return new class('sqlite::memory:') extends Pdo\Sqlite { use TransazioniAnnidate; };
}

/** Registra una funzione SQL (es. YEAR, NOW di MySQL) sul PDO SQLite dei test. */
function mvSqliteFunzione(PDO $pdo, string $nome, callable $fn, int $argomenti): void {
    if ($pdo instanceof Pdo\Sqlite) { $pdo->createFunction($nome, $fn, $argomenti); return; }
    $pdo->sqliteCreateFunction($nome, $fn, $argomenti);
}
