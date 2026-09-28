<?php
/**
 * SchedaClienteController — la scheda 360° di un cliente o prospect: dati, referenti, note datate,
 * offerte, commesse, situazione delle fatture e storico.
 *
 * Lo storico unisce le note scritte a mano (tabella attivita) con gli eventi che l'ERP conosce già
 * (offerta creata/inviata/chiusa, commessa, fattura emessa/pagata): non si duplicano in tabella.
 */
declare(strict_types=1);

require_once __DIR__ . '/../Shared/Database.php';
require_once __DIR__ . '/../Shared/Response.php';
require_once __DIR__ . '/../Shared/Audit.php';

class SchedaClienteController
{
    private const TIPI_NOTA = ['nota', 'chiamata', 'email', 'incontro'];

    private $pdo;
    private $p;

    public function __construct(?PDO $pdo = null, ?string $prefix = null)
    {
        $this->pdo = $pdo ?? Database::getConnection();
        $this->p = $prefix ?? (getenv('DB_PREFIX') ?: 'mv_');
    }

    public function scheda($id): void
    {
        $dati = $this->dati((int)$id);
        if (!$dati) Response::json(false, 'Cliente non trovato', null, 404);
        Response::json(true, '', $dati);
    }

    /** Tutto quello che serve alla scheda (separato dalla risposta per poterlo provare da CLI). */
    public function dati(int $id): ?array
    {
        $p = $this->p;
        $stmt = $this->pdo->prepare("SELECT * FROM {$p}clienti WHERE id = ?");
        $stmt->execute([$id]);
        $c = $stmt->fetch(PDO::FETCH_ASSOC);
        if (!$c) return null;

        $q = function (string $sql) use ($id) {
            $stmt = $this->pdo->prepare($sql);
            $stmt->execute([$id]);
            return $stmt->fetchAll(PDO::FETCH_ASSOC);
        };
        $referenti = $q("SELECT id, nome, ruolo, email, telefono, principale, note FROM {$p}referenti
            WHERE cliente_id = ? AND deleted_at IS NULL ORDER BY principale DESC, nome");
        $note = $q("SELECT id, tipo, data, testo, offerta_id, created_at FROM {$p}attivita WHERE cliente_id = ? ORDER BY data DESC, id DESC");
        $offerte = $q("SELECT id, numero, versione, oggetto, stato, imponibile, probabilita, data_offerta, data_invio, data_esito,
                data_followup, prossima_azione, motivo_esito, incarico_id
            FROM {$p}offerte WHERE cliente_id = ? AND deleted_at IS NULL AND stato <> 'sostituita' ORDER BY data_offerta DESC, id DESC");
        $commesse = $q("SELECT i.id, i.data_incarico, i.descrizione, i.tipo_commessa, i.importo_totale, i.stato,
                COALESCE((SELECT SUM(f.imponibile) FROM {$p}fatture f WHERE f.incarico_id = i.id), 0) AS fatturato
            FROM {$p}incarichi i WHERE i.cliente_id = ? ORDER BY i.data_incarico DESC, i.id DESC");
        $fatture = $q("SELECT numero_fattura, data_emissione, data_scadenza, data_pagamento, imponibile, importo_totale, stato
            FROM {$p}fatture WHERE cliente_id = ? ORDER BY data_emissione DESC, id DESC");
        $sottoclienti = $q("SELECT id, nome FROM {$p}sottoclienti WHERE cliente_id = ? ORDER BY nome");

        $oggi = date('Y-m-d');
        $situazione = ['fatturato_totale' => 0.0, 'da_incassare' => 0.0, 'scaduto' => 0.0];
        foreach ($fatture as $f) {
            $situazione['fatturato_totale'] += (float)$f['imponibile'];
            if ($f['stato'] !== 'pagata') {
                $situazione['da_incassare'] += (float)$f['importo_totale'];
                if ($f['data_scadenza'] && $f['data_scadenza'] < $oggi) $situazione['scaduto'] += (float)$f['importo_totale'];
            }
        }
        $situazione = array_map(fn($v) => round($v, 2), $situazione);
        $c['tipo'] = ($commesse || $fatture) ? 'cliente' : 'prospect';

        return [
            'cliente' => $c,
            'sottoclienti' => $sottoclienti,
            'referenti' => $referenti,
            'offerte' => $offerte,
            'commesse' => $commesse,
            'situazione' => $situazione + ['num_fatture' => count($fatture)],
            'storico' => $this->storico($note, $offerte, $commesse, $fatture),
        ];
    }

    /** Eventi in ordine dal più recente: note a mano + quelli ricavati dai documenti. */
    private function storico(array $note, array $offerte, array $commesse, array $fatture): array
    {
        $ev = [];
        $e = function (?string $data, string $tipo, string $testo, array $extra = []) use (&$ev) {
            if ($data) $ev[] = ['data' => substr($data, 0, 10), 'tipo' => $tipo, 'testo' => $testo] + $extra;
        };
        foreach ($note as $n) $e($n['data'], $n['tipo'], $n['testo'], ['nota_id' => (int)$n['id']]);
        foreach ($offerte as $o) {
            $nome = $o['numero'] . ($o['versione'] > 1 ? ' v' . $o['versione'] : '') . ' — ' . $o['oggetto'];
            $e($o['data_offerta'], 'offerta', ($o['stato'] === 'lead' ? 'Lead aperto: ' : 'Offerta ') . $nome, ['offerta_id' => (int)$o['id']]);
            $e($o['data_invio'], 'offerta', 'Offerta inviata: ' . $nome, ['offerta_id' => (int)$o['id']]);
            if (in_array($o['stato'], ['accettata', 'rifiutata', 'scaduta'], true)) {
                $e($o['data_esito'], 'offerta', 'Offerta ' . $o['stato'] . ': ' . $nome . ($o['motivo_esito'] ? ' (' . $o['motivo_esito'] . ')' : ''), ['offerta_id' => (int)$o['id']]);
            }
        }
        foreach ($commesse as $c) $e($c['data_incarico'], 'commessa', 'Commessa: ' . ($c['descrizione'] ?: '#' . $c['id']), ['incarico_id' => (int)$c['id']]);
        foreach ($fatture as $f) {
            $e($f['data_emissione'], 'fattura', 'Fattura ' . $f['numero_fattura'] . ' emessa: ' . number_format((float)$f['importo_totale'], 2, ',', '.') . ' €');
            if ($f['stato'] === 'pagata') $e($f['data_pagamento'], 'incasso', 'Fattura ' . $f['numero_fattura'] . ' pagata');
        }
        usort($ev, fn($a, $b) => strcmp($b['data'], $a['data']));
        return array_slice($ev, 0, 80);
    }

    public function salvaReferente(array $data): void
    {
        $id = (int)($data['id'] ?? 0);
        $modifica = $id > 0;
        $f = [
            'cliente_id' => (int)($data['cliente_id'] ?? 0),
            'nome' => mb_substr(trim((string)($data['nome'] ?? '')), 0, 150),
            'ruolo' => mb_substr(trim((string)($data['ruolo'] ?? '')), 0, 100) ?: null,
            'email' => mb_substr(trim((string)($data['email'] ?? '')), 0, 150) ?: null,
            'telefono' => mb_substr(trim((string)($data['telefono'] ?? '')), 0, 50) ?: null,
            'principale' => !empty($data['principale']) && $data['principale'] !== '0' ? 1 : 0,
            'note' => mb_substr(trim((string)($data['note'] ?? '')), 0, 255) ?: null,
        ];
        if ($f['nome'] === '') Response::json(false, 'Scrivi il nome del referente');
        if ($f['email'] && !filter_var($f['email'], FILTER_VALIDATE_EMAIL)) Response::json(false, 'Email non valida');
        if (!$this->clienteEsiste($f['cliente_id'])) Response::json(false, 'Cliente non trovato', null, 404);
        $this->pdo->beginTransaction();
        // Un solo referente principale per cliente
        if ($f['principale']) $this->pdo->prepare("UPDATE {$this->p}referenti SET principale = 0 WHERE cliente_id = ?")->execute([$f['cliente_id']]);
        if ($id) {
            $sets = implode(', ', array_map(fn($k) => "$k = ?", array_keys($f)));
            $this->pdo->prepare("UPDATE {$this->p}referenti SET $sets WHERE id = ? AND deleted_at IS NULL")->execute(array_merge(array_values($f), [$id]));
        } else {
            $this->pdo->prepare("INSERT INTO {$this->p}referenti (" . implode(', ', array_keys($f)) . ") VALUES (" . implode(', ', array_fill(0, count($f), '?')) . ")")
                ->execute(array_values($f));
            $id = (int)$this->pdo->lastInsertId();
        }
        $this->pdo->commit();
        Audit::log($modifica ? 'UPDATE' : 'INSERT', 'referenti', (string)$id, null, ['cliente_id' => $f['cliente_id'], 'nome' => $f['nome']]);
        Response::json(true, 'Referente salvato', ['id' => $id]);
    }

    public function eliminaReferente($id): void
    {
        $this->pdo->prepare("UPDATE {$this->p}referenti SET deleted_at = NOW() WHERE id = ?")->execute([(int)$id]);
        Audit::log('DELETE', 'referenti', (string)(int)$id, null, null);
        Response::json(true, 'Referente eliminato');
    }

    public function salvaNota(array $data): void
    {
        $clienteId = (int)($data['cliente_id'] ?? 0);
        $testo = trim((string)($data['testo'] ?? ''));
        $tipo = in_array($data['tipo'] ?? '', self::TIPI_NOTA, true) ? $data['tipo'] : 'nota';
        $d = (string)($data['data'] ?? '');
        $d = preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && checkdate((int)substr($d, 5, 2), (int)substr($d, 8, 2), (int)substr($d, 0, 4)) ? $d : date('Y-m-d');
        if ($testo === '') Response::json(false, 'Scrivi il testo della nota');
        if (!$this->clienteEsiste($clienteId)) Response::json(false, 'Cliente non trovato', null, 404);
        $offertaId = !empty($data['offerta_id']) ? (int)$data['offerta_id'] : null;
        $this->pdo->prepare("INSERT INTO {$this->p}attivita (cliente_id, offerta_id, tipo, data, testo, user_id) VALUES (?, ?, ?, ?, ?, ?)")
            ->execute([$clienteId, $offertaId, $tipo, $d, mb_substr($testo, 0, 5000), $GLOBALS['userContext']['id'] ?? null]);
        Response::json(true, 'Nota salvata', ['id' => (int)$this->pdo->lastInsertId()]);
    }

    public function eliminaNota($id): void
    {
        $this->pdo->prepare("DELETE FROM {$this->p}attivita WHERE id = ?")->execute([(int)$id]);
        Response::json(true, 'Nota eliminata');
    }

    private function clienteEsiste(int $id): bool
    {
        $stmt = $this->pdo->prepare("SELECT 1 FROM {$this->p}clienti WHERE id = ?");
        $stmt->execute([$id]);
        return (bool)$stmt->fetchColumn();
    }
}
