<?php
/**
 * Prova da riga di comando delle trasferte (non va online: tests/ è escluso dal deploy).
 *
 *   php tests/trasferte_cli.php
 *
 * Usa SQLite in memoria con lo schema minimo delle tabelle coinvolte, dati inventati e un
 * finto servizio di percorsi: nessuna chiamata a Google, Nominatim o OSRM.
 */
declare(strict_types=1);

if (PHP_SAPI !== 'cli') { http_response_code(404); exit; }

putenv('DB_PREFIX=mv_');
putenv('BASE_ADDRESS=Base');
require_once __DIR__ . '/../api/Shared/Database.php';
require_once __DIR__ . '/../api/Shared/TrasferteRegole.php';
require_once __DIR__ . '/../api/Shared/TrasferteSync.php';
require_once __DIR__ . '/../api/Shared/Percorsi.php';
require_once __DIR__ . '/../api/Controllers/TrasferteController.php';

$ok = 0;
$ko = 0;
function check(string $nome, bool $cond, $dettaglio = null): void
{
    global $ok, $ko;
    if ($cond) { $ok++; echo "  ok   $nome\n"; return; }
    $ko++;
    echo "  FAIL $nome" . ($dettaglio !== null ? ' → ' . json_encode($dettaglio, JSON_UNESCAPED_UNICODE) : '') . "\n";
}

// ═══ 1. Regole pure ═════════════════════════════════════════
echo "Eventi da ignorare\n";
check('Non disponibile', TrasferteRegole::daSaltare('Non disponibile'));
check('ANNULLATO - Rossi', TrasferteRegole::daSaltare('ANNULLATO - Rossi'));
check('titolo vuoto', TrasferteRegole::daSaltare('  '));
check('visita cliente', !TrasferteRegole::daSaltare('Visita Rossi Srl'));

echo "Giorni di un evento\n";
$g = TrasferteRegole::giorniEvento(['date' => '2026-03-10'], ['date' => '2026-03-11']);
check('giornata intera: un giorno', $g === ['2026-03-10'], $g);
$g = TrasferteRegole::giorniEvento(['date' => '2026-10-24'], ['date' => '2026-10-27']);
check('a cavallo del cambio dell\'ora: tre giorni', $g === ['2026-10-24', '2026-10-25', '2026-10-26'], $g);
$g = TrasferteRegole::giorniEvento(['date' => '2026-03-28'], ['date' => '2026-03-31']);
check('cambio dell\'ora di marzo: tre giorni', $g === ['2026-03-28', '2026-03-29', '2026-03-30'], $g);
$g = TrasferteRegole::giorniEvento(['dateTime' => '2026-07-01T22:30:00Z'], ['dateTime' => '2026-07-01T23:30:00Z']);
check('orario in UTC letto in ora di Roma', $g === ['2026-07-02'], $g);
$g = TrasferteRegole::giorniEvento(['dateTime' => '2026-05-04T20:00:00+02:00'], ['dateTime' => '2026-05-05T00:00:00+02:00']);
check('fine a mezzanotte non occupa il giorno dopo', $g === ['2026-05-04'], $g);

echo "Fascia oraria\n";
$f = fn($a, $b) => TrasferteRegole::fasciaEvento(['dateTime' => $a], ['dateTime' => $b]);
check('9-12 mattino', $f('2026-05-04T09:00:00+02:00', '2026-05-04T12:00:00+02:00') === 'mattino');
check('14-17 pomeriggio', $f('2026-05-04T14:00:00+02:00', '2026-05-04T17:00:00+02:00') === 'pomeriggio');
check('9-17 intera', $f('2026-05-04T09:00:00+02:00', '2026-05-04T17:00:00+02:00') === 'intera');
check('12:00Z d\'estate = 14:00 a Roma → pomeriggio', $f('2026-07-01T12:00:00Z', '2026-07-01T15:00:00Z') === 'pomeriggio');
check('giornata intera', TrasferteRegole::fasciaEvento(['date' => '2026-05-04'], ['date' => '2026-05-05']) === 'intera');

echo "Ordine delle tappe\n";
$ord = array_column(TrasferteRegole::ordinaTappe([
    ['id' => 5, 'fascia_oraria' => 'pomeriggio'], ['id' => 3, 'fascia_oraria' => 'intera'],
    ['id' => 9, 'fascia_oraria' => 'mattino'], ['id' => 1, 'fascia_oraria' => 'intera'],
]), 'id');
check('mattino, intere, pomeriggio', $ord === [9, 1, 3, 5], $ord);

echo "Ripartizione km\n";
check('andata e ritorno da casa', TrasferteRegole::ripartisciKm(100, 2, false, true) === [[25.0, 25.0], [25.0, 25.0]]);
check('si dorme fuori stasera', TrasferteRegole::ripartisciKm(100, 2, false, false) === [[50.0, 0.0], [50.0, 0.0]]);
check('si rientra dopo la notte fuori', TrasferteRegole::ripartisciKm(100, 1, true, true) === [[0.0, 100.0]]);
check('seconda notte fuori', TrasferteRegole::ripartisciKm(90, 3, true, false) === array_fill(0, 3, [30.0, 0.0]));
$q = TrasferteRegole::ripartisciKm(40, 3, false, true);
check('40 km su tre tappe: i decimi avanzati alle prime', $q === [[6.7, 6.7], [6.6, 6.7], [6.6, 6.7]], $q);
check('la somma delle tappe è il totale del percorso', abs(array_sum(array_merge(...$q)) - 40.0) < 1e-9, array_sum(array_merge(...$q)));
check('nessuna tappa', TrasferteRegole::ripartisciKm(40, 0, false, true) === []);

echo "Indennità\n";
check('piena', TrasferteRegole::indennitaGiornata(true, 0, 0) === 46.48);
check('con vitto', TrasferteRegole::indennitaGiornata(true, 20, 0) === 30.99);
check('con vitto e alloggio', TrasferteRegole::indennitaGiornata(true, 20, 80) === 15.49);
check('senza cliente', TrasferteRegole::indennitaGiornata(false, 0, 0) === 0.0);
$gg = TrasferteRegole::giornate([
    ['data_trasferta' => '2026-05-04', 'cliente_id' => null, 'sottocliente_id' => null, 'vitto' => 0, 'alloggio' => 0],
    ['data_trasferta' => '2026-05-04', 'cliente_id' => 1, 'sottocliente_id' => null, 'vitto' => 15, 'alloggio' => 0],
]);
check('giornata con un cliente e il vitto', $gg['2026-05-04']['indennita'] === 30.99, $gg);
$gs = fn(?string $citta) => TrasferteRegole::giornate([['data_trasferta' => '2026-05-04', 'cliente_id' => 1, 'cliente_citta' => $citta]], 'Zero Branco')['2026-05-04']['indennita'];
check('cliente nel comune della sede: niente indennità', $gs('Zero Branco (TV)') === 0.0);
check('cliente fuori comune: indennità piena', $gs('Treviso') === 46.48);
check('cliente senza città: si considera fuori', $gs(null) === 46.48);
$gg = TrasferteRegole::giornate([
    ['data_trasferta' => '2026-05-04', 'cliente_id' => 1, 'cliente_citta' => 'zero branco'],
    ['data_trasferta' => '2026-05-04', 'cliente_id' => 2, 'sottocliente_id' => 7, 'cliente_citta' => 'Zero Branco', 'sottocliente_citta' => 'Padova'],
], 'Zero Branco');
check('basta un cliente fuori comune (conta la città del sottocliente)', $gg['2026-05-04']['indennita'] === 46.48, $gg);
putenv('BASE_ADDRESS=Via Manzoni 5, 31059 Zero Branco, TV');
check('comune della sede ricavato dall\'indirizzo', Percorsi::comuneBase() === 'Zero Branco', Percorsi::comuneBase());
putenv('BASE_ADDRESS=Base');

echo "Validazione\n";
check('data valida', TrasferteRegole::errore(['data_trasferta' => '2026-02-28'], false) === null);
check('data impossibile', TrasferteRegole::errore(['data_trasferta' => '2026-02-30'], false) !== null);
check('data mancante in creazione', TrasferteRegole::errore([], false) !== null);
check('data assente in modifica', TrasferteRegole::errore(['vitto' => '10'], true) === null);
check('fascia inventata', TrasferteRegole::errore(['data_trasferta' => '2026-02-28', 'fascia_oraria' => 'sera'], false) !== null);
check('km negativi', TrasferteRegole::errore(['data_trasferta' => '2026-02-28', 'km_andata' => '-5'], false) !== null);

// ═══ 2. Database di prova ═══════════════════════════════════
$pdo = new PDO('sqlite::memory:', null, null, [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
$pdo->exec("CREATE TABLE mv_clienti (id INTEGER PRIMARY KEY, ragione_sociale TEXT, indirizzo TEXT, citta TEXT)");
// Spese di trasferta (l'alloggio vale come notte fuori)
$pdo->exec("CREATE TABLE mv_spese (id INTEGER PRIMARY KEY, data TEXT, categoria TEXT, importo REAL, deleted_at TEXT)");
$pdo->exec("CREATE TABLE mv_rimborsi (mese TEXT PRIMARY KEY)");
$pdo->exec("CREATE TABLE mv_sottoclienti (id INTEGER PRIMARY KEY, cliente_id INT, nome TEXT, indirizzo TEXT, citta TEXT)");
$pdo->exec("CREATE TABLE mv_trasferte (id INTEGER PRIMARY KEY AUTOINCREMENT, cliente_id INT, sottocliente_id INT,
    data_trasferta TEXT NOT NULL, fascia_oraria TEXT DEFAULT 'intera', descrizione TEXT, luogo_partenza TEXT, luogo_arrivo TEXT,
    google_event_id TEXT UNIQUE, google_calendar_id TEXT, km_andata REAL DEFAULT 0, km_ritorno REAL DEFAULT 0,
    vitto REAL DEFAULT 0, alloggio REAL DEFAULT 0, note_spese TEXT, pernottamento INT DEFAULT 0, km_bloccati INT DEFAULT 0,
    mezzo_id INT, modifica_manuale INT NOT NULL DEFAULT 0)");
$pdo->exec("INSERT INTO mv_clienti VALUES (1, 'Rossi Meccanica Srl', 'Via A 1', 'Treviso'), (2, 'Bianchi Spa', 'Via B 2', 'Verona'), (3, 'Verdi Srl', 'Via C 3', 'Udine')");
$ref = new ReflectionProperty(Database::class, 'pdo');
$ref->setValue(null, $pdo);

$riga = fn(string $evento, string $giorno) => $pdo->query("SELECT * FROM mv_trasferte WHERE google_event_id = " . $pdo->quote($evento . '_' . $giorno))->fetch();
$conta = fn() => (int)$pdo->query("SELECT COUNT(*) FROM mv_trasferte")->fetchColumn();

// ═══ 3. Sincronizzazione Google ═════════════════════════════
echo "Sincronizzazione\n";
$ev = fn($id, $titolo, $start, $end, $extra = []) => array_merge(['id' => $id, 'summary' => $titolo,
    'start' => strlen($start) === 10 ? ['date' => $start] : ['dateTime' => $start],
    'end' => strlen($end) === 10 ? ['date' => $end] : ['dateTime' => $end]], $extra);

$sync = new TrasferteSync($pdo, 'mv_', true);
$sync->importaEvento('cal', $ev('e1', 'Visita Rossi Meccanica', '2026-05-04T09:00:00+02:00', '2026-05-04T12:00:00+02:00'));
$sync->importaEvento('cal', $ev('e2', 'Non disponibile', '2026-05-05', '2026-05-06'));
$sync->importaEvento('cal', $ev('e3', 'Corso Bianchi', '2026-05-06', '2026-05-08'));
$sync->importaEvento('cal', $ev('e4', 'Riunione', '2026-05-07', '2026-05-08', ['status' => 'cancelled']));
$sync->rimuoviAssenti('cal', '2026-01-01', '2026-12-31');
$r = $riga('e1', '2026-05-04');
check('evento importato col cliente e la fascia', $r && (int)$r['cliente_id'] === 1 && $r['fascia_oraria'] === 'mattino', $r);
check('"Non disponibile" non importato', !$riga('e2', '2026-05-05'));
check('evento cancellato non importato', !$riga('e4', '2026-05-07'));
check('evento di due giorni → due righe', $riga('e3', '2026-05-06') && $riga('e3', '2026-05-07'));
check('righe totali', $conta() === 3, $conta());

// Ritocco a mano su e3 del 6 maggio, poi l'evento si sposta e cambia titolo
$pdo->exec("UPDATE mv_trasferte SET descrizione = 'nota mia', cliente_id = 3, modifica_manuale = 1 WHERE google_event_id = 'e3_2026-05-06'");
$sync = new TrasferteSync($pdo, 'mv_', true);
$sync->importaEvento('cal', $ev('e1', 'Visita Rossi Meccanica', '2026-05-11T09:00:00+02:00', '2026-05-11T12:00:00+02:00'));
$sync->importaEvento('cal', $ev('e3', 'Corso Bianchi aggiornato', '2026-05-06', '2026-05-07'));
$sync->rimuoviAssenti('cal', '2026-01-01', '2026-12-31');
check('evento spostato: vecchia riga eliminata', !$riga('e1', '2026-05-04'));
check('evento spostato: nuova riga creata', (bool)$riga('e1', '2026-05-11'));
$r = $riga('e3', '2026-05-06');
check('riga ritoccata a mano non riscritta', $r && $r['descrizione'] === 'nota mia' && (int)$r['cliente_id'] === 3, $r);
check('giorno tolto dall\'evento: riga eliminata', !$riga('e3', '2026-05-07'));
check('date da ricalcolare', isset($sync->dateToccate['2026-05-04'], $sync->dateToccate['2026-05-11'], $sync->dateToccate['2026-05-07']), array_keys($sync->dateToccate));

// Evento con la riga ritoccata a mano cancellato dal calendario: resta e si segnala
$sync = new TrasferteSync($pdo, 'mv_', true);
$sync->importaEvento('cal', $ev('e1', 'Visita Rossi Meccanica', '2026-05-11T09:00:00+02:00', '2026-05-11T12:00:00+02:00'));
$sync->rimuoviAssenti('cal', '2026-01-01', '2026-12-31');
check('riga ritoccata a mano senza evento: tenuta e segnalata', $riga('e3', '2026-05-06') && $sync->manualiOrfane === 1);
check('messaggio di riepilogo', strpos($sync->riepilogo(['cal2']), 'cal2') !== false);

// ═══ 4. Calcolo km ══════════════════════════════════════════
echo "Calcolo km\n";
class PercorsiFinti extends Percorsi
{
    public array $percorsi = [];
    private array $coord = ['base' => [0, 0], 'via a 1 treviso' => [0, 1], 'via b 2 verona' => [0, 2], 'via c 3 udine' => [0, 3]];
    public function __construct() {}
    public function geocode(string $indirizzo): ?array
    {
        $c = $this->coord[mb_strtolower(trim($indirizzo))] ?? null;
        return $c ? ['lat' => (float)$c[0], 'lon' => (float)$c[1]] : null;
    }
    /** 10 km per ogni tratto; si registra il percorso per controllarlo */
    public function km(array $punti): array
    {
        $this->percorsi[] = array_map(fn($p) => (int)$p['lon'], $punti);
        return ['success' => true, 'totKm' => 10.0 * (count($punti) - 1)];
    }
}
$pdo->exec("DELETE FROM mv_trasferte");
$ins = $pdo->prepare("INSERT INTO mv_trasferte (data_trasferta, cliente_id, fascia_oraria, pernottamento, km_bloccati, km_andata) VALUES (?, ?, ?, ?, ?, ?)");
$km = fn(string $data) => array_map(fn($r) => [(float)$r['km_andata'], (float)$r['km_ritorno']],
    $pdo->query("SELECT km_andata, km_ritorno FROM mv_trasferte WHERE data_trasferta = '$data' ORDER BY id")->fetchAll());

// Tre clienti nella stessa giornata: il percorso li tocca tutti
$ins->execute(['2026-06-01', 3, 'pomeriggio', 0, 0, 0]);
$ins->execute(['2026-06-01', 1, 'mattino', 0, 0, 0]);
$ins->execute(['2026-06-01', 2, 'intera', 0, 0, 0]);
$fin = new PercorsiFinti();
$tc = new TrasferteController($fin);
$res = $tc->calcolaKmPerData('2026-06-01');
check('percorso base → mattino → intera → pomeriggio → base', end($fin->percorsi) === [0, 1, 2, 3, 0], end($fin->percorsi));
// In ordine di id: pomeriggio (terza tappa), mattino (prima), intera (seconda); 40 km esatti
check('km divisi fra le tre tappe', $km('2026-06-01') === [[6.6, 6.7], [6.7, 6.7], [6.6, 6.7]], $km('2026-06-01'));

// Km bloccati su una tappa: il resto del percorso va alle altre
$pdo->exec("UPDATE mv_trasferte SET km_bloccati = 1, km_andata = 12, km_ritorno = 0 WHERE cliente_id = 2 AND data_trasferta = '2026-06-01'");
$tc->calcolaKmPerData('2026-06-01');
check('tappa bloccata intatta, 28 km sulle altre due', $km('2026-06-01') === [[7.0, 7.0], [7.0, 7.0], [12.0, 0.0]], $km('2026-06-01'));

// Notte fuori con trasferta il giorno dopo: niente rientro, e domani si parte da lì
$ins->execute(['2026-06-10', 2, 'intera', 1, 0, 0]);
$ins->execute(['2026-06-11', 3, 'intera', 0, 0, 0]);
$tc->ricalcolaIntorno(['2026-06-10']);
$p = $fin->percorsi;
check('giorno del pernottamento: base → Verona, senza rientro', in_array([0, 2], $p, true), $p);
check('giorno dopo: Verona → Udine → base', in_array([2, 3, 0], $p, true), $p);
check('km del giorno dopo tutti al ritorno', $km('2026-06-11') === [[0.0, 20.0]], $km('2026-06-11'));

// Notte fuori ma nessuna trasferta il giorno dopo: il rientro si conta oggi
$ins->execute(['2026-06-20', 1, 'intera', 1, 0, 0]);
$fin->percorsi = [];
$tc->ricalcolaIntorno(['2026-06-20']);
check('pernottamento senza seguito: rientro contato', $fin->percorsi === [[0, 1, 0]], $fin->percorsi);

// Il pernottamento di venerdì non sposta la partenza del lunedì
$ins->execute(['2026-06-26', 1, 'intera', 1, 0, 0]);
$ins->execute(['2026-06-29', 2, 'intera', 0, 0, 0]);
$fin->percorsi = [];
$tc->calcolaKmPerData('2026-06-29');
check('lunedì si parte dalla base', $fin->percorsi === [[0, 2, 0]], $fin->percorsi);

// Senza cliente vale la destinazione scritta sulla trasferta; un link di videochiamata no
$pdo->exec("INSERT INTO mv_trasferte (data_trasferta, luogo_arrivo) VALUES ('2026-07-01', 'Via B 2 Verona'), ('2026-07-02', 'https://meet.google.com/abc')");
$fin->percorsi = [];
$tc->calcolaKmPerData('2026-07-01');
check('destinazione senza cliente: base → Verona → base', $fin->percorsi === [[0, 2, 0]], $fin->percorsi);
$res = $tc->calcolaKmPerData('2026-07-02');
check('link di videochiamata: nessun percorso', count($fin->percorsi) === 1 && ($res['data']['totale_km'] ?? null) === 0, $res);

// La vecchia colonna trasferte.alloggio (prima della v083) non è più una notte fuori
$pdo->exec("INSERT INTO mv_trasferte (data_trasferta, cliente_id, alloggio) VALUES ('2026-07-09', 1, 80)");
$ins->execute(['2026-07-10', 2, 'intera', 0, 0, 0]);
$fin->percorsi = [];
$tc->calcolaKmPerData('2026-07-10');
check('colonna alloggio obsoleta ignorata: si parte dalla base', $fin->percorsi === [[0, 2, 0]], $fin->percorsi);
$pdo->exec("INSERT INTO mv_spese (data, categoria, importo) VALUES ('2026-07-09', 'alloggio', 80)");
$fin->percorsi = [];
$tc->calcolaKmPerData('2026-07-10');
check('spesa di alloggio: si parte da dove si è dormito', $fin->percorsi === [[1, 2, 0]], $fin->percorsi);

// Mese con la nota spese presentata: né il ricalcolo dei giorni vicini né la sync lo toccano
$pdo->exec("INSERT INTO mv_rimborsi (mese) VALUES ('2026-08')");
$pdo->exec("INSERT INTO mv_trasferte (data_trasferta, cliente_id, km_andata) VALUES ('2026-08-31', 1, 5)");
$ins->execute(['2026-09-01', 2, 'intera', 0, 0, 0]);
$esiti = $tc->ricalcolaIntorno(['2026-09-01']);
check('giorno prima in un mese presentato: km intatti', $km('2026-08-31') === [[5.0, 0.0]] && !empty($esiti['2026-08-31']['congelata']), $km('2026-08-31'));
check('il giorno modificato si ricalcola', ($esiti['2026-09-01']['success'] ?? false) === true, $esiti['2026-09-01'] ?? null);
$sync = new TrasferteSync($pdo, 'mv_', true);
$sync->importaEvento('cal', $ev('e9', 'Visita Bianchi', '2026-08-10', '2026-08-11'));
$sync->rimuoviAssenti('cal', '2026-08-01', '2026-08-31');
check('sync: nessuna riga nuova nel mese presentato', !$riga('e9', '2026-08-10') && $sync->congelati === 1);
check('sync: segnalato nel riepilogo', strpos($sync->riepilogo([]), 'nota spese') !== false);

echo "\n$ok ok, $ko falliti\n";
exit($ko ? 1 : 0);
