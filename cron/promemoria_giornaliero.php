<?php
/**
 * Cron — promemoria giornaliero dello scadenzario via email.
 * MV Consulting ERP
 *
 * Da riga di comando (crontab):
 *   30 7 * * * php /percorso/del/progetto/cron/promemoria_giornaliero.php
 * Senza crontab sul server lo lancia GitHub Actions (.github/workflows/promemoria.yml)
 * tramite il router: module=cron&action=promemoria con header X-Cron-Token.
 *
 * Opzione --prova: calcola e stampa l'email senza inviarla.
 */

declare(strict_types=1);

if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    exit("Solo da riga di comando\n");
}

$rootDir = dirname(__DIR__);
require_once $rootDir . '/api/Shared/Env.php';
Env::load($rootDir . '/.env');
require_once $rootDir . '/api/Shared/Database.php';
require_once $rootDir . '/api/Shared/Promemoria.php';

$prova = in_array('--prova', $argv, true);
$prefix = getenv('DB_PREFIX') ?: 'mv_';
$pdo = Database::getConnection();
$promemoria = new Promemoria($pdo, $prefix);

if ($prova) {
    echo $promemoria->html((new Scadenzario($pdo, $prefix))->calcola(7)), "\n";
    exit(0);
}
$esito = $promemoria->esegui();
echo date('c'), ' ', json_encode($esito, JSON_UNESCAPED_UNICODE), "\n";
exit($esito['voci'] > 0 && !$esito['inviata'] && $esito['destinatari'] ? 1 : 0);
