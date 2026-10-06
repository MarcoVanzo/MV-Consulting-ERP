<?php
/**
 * Promemoria — email giornaliera con lo scadenzario: offerte da ricontattare,
 * rate da fatturare in Sistemi, clienti da sollecitare, partner da pagare.
 *
 * Destinatario: PROMEMORIA_EMAIL nel .env (altrimenti gli admin attivi).
 * Si lancia da cron/promemoria_giornaliero.php (CLI) o dal router con X-Cron-Token.
 */
declare(strict_types=1);

require_once __DIR__ . '/Scadenzario.php';

class Promemoria
{
    private $pdo;
    private $p;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->p = $prefix;
    }

    /** @return array{inviata: bool, destinatari: int, voci: int, fatture_scadute_marcate: int} */
    public function esegui(bool $invia = true): array
    {
        // Scadenze importate a fine mese → giorno fisso del cliente (Unindustria: al 10), prima di decidere cosa è scaduto
        require_once __DIR__ . '/CommessaService.php';
        (new CommessaService($this->pdo, $this->p))->allineaScadenzeGiornoFisso();
        $this->pdo->exec("UPDATE {$this->p}fatture SET stato = 'emessa' WHERE stato = 'scaduta' AND data_scadenza >= CURDATE()");
        // Le fatture oltre la scadenza passano a "scaduta" (stato già usato dalla contabilità)
        $stmt = $this->pdo->prepare("UPDATE {$this->p}fatture SET stato = 'scaduta'
            WHERE stato IN ('emessa','inviata') AND data_scadenza IS NOT NULL AND data_scadenza < CURDATE()");
        $stmt->execute();
        $marcate = $stmt->rowCount();
        require_once __DIR__ . '/Documenti.php';
        Documenti::migraAllegatiStorici($this->pdo, $this->p);
        Documenti::pulisciOrfani($this->pdo, $this->p);

        $s = (new Scadenzario($this->pdo, $this->p))->calcola(7);
        $voci = count($s['offerte_da_ricontattare']) + count($s['offerte_in_scadenza']) + count($s['rate_da_fatturare'])
            + count($s['incassi_scaduti']) + count($s['incassi_in_arrivo']) + count($s['pagamenti_partner']);

        $dest = $this->destinatari();
        $inviata = false;
        if ($invia && $voci > 0 && $dest) {
            $html = $this->html($s);
            // SMTP e non mail(): su Aruba mail() resta appesa fino al timeout del proxy
            require_once __DIR__ . '/Mailer.php';
            $oggetto = 'ERP — da fare oggi (' . $voci . ')';
            $inviata = true;
            foreach ($dest as $d) {
                $inviata = Mailer::send($d, null, $oggetto, $html, true) && $inviata;
            }
        }
        // Solo il numero: gli indirizzi non escono dalla risposta del cron (log di GitHub Actions)
        return ['inviata' => $inviata, 'destinatari' => count($dest), 'voci' => $voci, 'fatture_scadute_marcate' => $marcate];
    }

    private function destinatari(): array
    {
        $env = trim((string)getenv('PROMEMORIA_EMAIL'));
        if ($env !== '') {
            return array_values(array_filter(array_map('trim', explode(',', $env)), fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
        }
        $rows = $this->pdo->query("SELECT email FROM {$this->p}users WHERE role = 'admin' AND COALESCE(is_active, 1) = 1 AND COALESCE(blocked, 0) = 0")->fetchAll(PDO::FETCH_COLUMN);
        return array_values(array_filter($rows, fn($e) => filter_var($e, FILTER_VALIDATE_EMAIL)));
    }

    /** Corpo HTML dell'email (dati sempre escapati). */
    public function html(array $s): string
    {
        $e = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES, 'UTF-8');
        $eur = fn($v) => number_format((float)$v, 2, ',', '.') . ' €';
        $d = fn($v) => $v ? date('d/m/Y', strtotime((string)$v)) : '—';

        $sezioni = [
            ['Rate da fatturare in Sistemi', $s['rate_da_fatturare'], fn($r) =>
                "<b>{$e($r['cliente_nome'])}</b> — {$e($r['testo_fattura'])}<br>Imponibile {$eur($r['importo'])}, "
                . ($r['data_prevista'] ? "da emettere il {$d($r['data_prevista'])}" : 'senza data: pianificala nella scheda commessa')],
            ['Clienti in ritardo di pagamento', $s['incassi_scaduti'], fn($r) =>
                "<b>{$e($r['cliente_nome'])}</b> — fattura {$e($r['numero_fattura'])} da {$eur($r['importo_totale'])}, scaduta il {$d($r['data_scadenza'])} ({$e($r['giorni_ritardo'])} gg)"],
            ['Incassi attesi nei prossimi giorni', $s['incassi_in_arrivo'], fn($r) =>
                "<b>{$e($r['cliente_nome'])}</b> — fattura {$e($r['numero_fattura'])} da {$eur($r['importo_totale'])}, scade il {$d($r['data_scadenza'])}"],
            ['Partner da pagare', $s['pagamenti_partner'], fn($r) =>
                "<b>{$e($r['fornitore_nome'])}</b> — fattura {$e($r['numero'])} da {$eur($r['importo_totale'])}"
                . ($r['data_scadenza'] ? ", scade il {$d($r['data_scadenza'])}" : '') . " · {$e($r['motivo'])}"],
            ['Offerte da ricontattare', $s['offerte_da_ricontattare'], fn($r) =>
                "<b>{$e($r['cliente_nome'])}</b> — {$e($r['numero'])} «{$e($r['oggetto'])}» ({$eur($r['imponibile'])}), inviata il {$d($r['data_invio'])}"],
            ['Offerte in scadenza', $s['offerte_in_scadenza'], fn($r) =>
                "<b>{$e($r['cliente_nome'])}</b> — {$e($r['numero'])} «{$e($r['oggetto'])}», valida fino al {$d($r['data_scadenza'])}"],
        ];

        // Un elemento per riga: l'SMTP ammette righe fino a 998 caratteri, oltre il messaggio si rompe
        $html = '<div style="font-family:Arial,sans-serif;font-size:14px;color:#222;max-width:640px">' . "\r\n"
            . '<h2 style="font-size:18px">Da fare oggi, ' . $d($s['oggi']) . '</h2>' . "\r\n";
        foreach ($sezioni as [$titolo, $righe, $fmt]) {
            if (!$righe) continue;
            $html .= '<h3 style="font-size:15px;margin:20px 0 6px">' . $e($titolo) . ' (' . count($righe) . ')</h3>' . "\r\n"
                . '<ul style="padding-left:18px;margin:0">' . "\r\n";
            foreach ($righe as $r) $html .= '<li style="margin-bottom:6px">' . $fmt($r) . '</li>' . "\r\n";
            $html .= '</ul>' . "\r\n";
        }
        $url = getenv('APP_URL') ?: 'https://www.mv-consulting.it/ERP/';
        $html .= '<p style="margin-top:24px;color:#666">Dettagli e solleciti: <a href="' . $e($url) . '">ERP → Scadenzario</a></p></div>' . "\r\n";
        // Righe ancora troppo lunghe (testi lunghi): a capo sugli spazi, indifferente per l'HTML
        $righe = array_map(fn($l) => wordwrap($l, 900, "\r\n", false), explode("\r\n", $html));
        return implode("\r\n", $righe);
    }
}
