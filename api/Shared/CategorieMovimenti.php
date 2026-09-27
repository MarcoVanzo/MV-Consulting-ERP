<?php
/**
 * CategorieMovimenti — anagrafica delle categorie di entrata/uscita e dati per i grafici "Andamento".
 * Nei grafici entrano solo i movimenti dell'estratto conto (gli avvisi di pagamento sono attese, non soldi).
 */
declare(strict_types=1);

class CategorieMovimenti
{
    public const COLORE_NON_CLASSIFICATO = '#CBD5E1';

    private $pdo;
    private $p;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->p = $prefix;
    }

    /** Crea o modifica una categoria: nome, tipo (solo alla creazione), colore, ordine, attiva. */
    public function salva(array $d): int
    {
        $id = (int)($d['id'] ?? 0);
        $nome = trim((string)($d['nome'] ?? ''));
        if ($nome === '' || mb_strlen($nome, 'UTF-8') > 100) throw new RuntimeException('Nome non valido');
        $colore = strtoupper(trim((string)($d['colore'] ?? '')));
        if (!preg_match('/^#[0-9A-F]{6}$/', $colore)) $colore = '#64748B';
        $ordine = (int)($d['ordine'] ?? 0);
        $attiva = in_array((string)($d['attiva'] ?? '1'), ['0', 'false'], true) ? 0 : 1;
        if ($id) {
            $this->pdo->prepare("UPDATE {$this->p}categorie_movimento SET nome = ?, colore = ?, ordine = ?, attiva = ? WHERE id = ?")
                ->execute([$nome, $colore, $ordine, $attiva, $id]);
            return $id;
        }
        $tipo = ($d['tipo'] ?? '') === 'entrata' ? 'entrata' : (($d['tipo'] ?? '') === 'uscita' ? 'uscita' : '');
        if ($tipo === '') throw new RuntimeException('Tipo non valido');
        $this->pdo->prepare("INSERT INTO {$this->p}categorie_movimento (nome, tipo, colore, ordine, attiva) VALUES (?, ?, ?, ?, ?)")
            ->execute([$nome, $tipo, $colore, $ordine ?: 500, $attiva]);
        return (int)$this->pdo->lastInsertId();
    }

    /** Entrate/uscite per mese e per categoria nel periodo [dal, al]. */
    public function statistiche(string $dal, string $al): array
    {
        $stmt = $this->pdo->prepare("SELECT COALESCE(data_valuta, data_operazione) AS data, importo, categoria_id
            FROM {$this->p}movimenti_banca WHERE origine = 'estratto_conto'
              AND COALESCE(data_valuta, data_operazione) BETWEEN ? AND ?");
        $stmt->execute([$dal, $al]);
        $categorie = $this->pdo->query("SELECT id, nome, tipo, colore, ordine FROM {$this->p}categorie_movimento")->fetchAll(PDO::FETCH_ASSOC);
        return self::aggrega($stmt->fetchAll(PDO::FETCH_ASSOC), $categorie, $dal, $al);
    }

    /**
     * Aggregazione pura (provata dal test CLI).
     * $movimenti: [{data, importo, categoria_id}], $categorie: [{id, nome, tipo, colore}]
     * @return array{mesi: array, entrate: array, uscite: array, totali: array}
     *   mesi: [{mese:'AAAA-MM', entrate, uscite, netto}] per ogni mese del periodo (anche vuoti)
     *   entrate/uscite: {totale, categorie:[{id|null, nome, colore, totale, numero}]} ordinate per totale,
     *   con la quota "Non classificato" (id null)
     */
    public static function aggrega(array $movimenti, array $categorie, string $dal, string $al): array
    {
        $perId = [];
        foreach ($categorie as $c) $perId[(int)$c['id']] = $c;
        $mesi = [];
        for ($t = strtotime(substr($dal, 0, 7) . '-01'); $t <= strtotime($al); $t = strtotime('+1 month', $t)) {
            $mesi[date('Y-m', $t)] = ['mese' => date('Y-m', $t), 'entrate' => 0.0, 'uscite' => 0.0, 'netto' => 0.0];
        }
        $gruppi = ['entrate' => [], 'uscite' => []];
        foreach ($movimenti as $m) {
            $imp = round((float)$m['importo'], 2);
            $lato = $imp >= 0 ? 'entrate' : 'uscite';
            $val = abs($imp);
            $mese = substr((string)$m['data'], 0, 7);
            if (isset($mesi[$mese])) {
                $mesi[$mese][$lato] = round($mesi[$mese][$lato] + $val, 2);
                $mesi[$mese]['netto'] = round($mesi[$mese]['netto'] + $imp, 2);
            }
            $cid = $m['categoria_id'] !== null ? (int)$m['categoria_id'] : null;
            $k = $cid ?? 'nc';
            if (!isset($gruppi[$lato][$k])) {
                $c = $cid !== null ? ($perId[$cid] ?? null) : null;
                $gruppi[$lato][$k] = ['id' => $cid, 'nome' => $c['nome'] ?? 'Non classificato',
                    'colore' => $c['colore'] ?? self::COLORE_NON_CLASSIFICATO, 'totale' => 0.0, 'numero' => 0];
            }
            $gruppi[$lato][$k]['totale'] = round($gruppi[$lato][$k]['totale'] + $val, 2);
            $gruppi[$lato][$k]['numero']++;
        }
        $out = ['mesi' => array_values($mesi)];
        foreach ($gruppi as $lato => $g) {
            $g = array_values($g);
            usort($g, fn($a, $b) => $b['totale'] <=> $a['totale']);
            $out[$lato] = ['totale' => round(array_sum(array_column($g, 'totale')), 2), 'categorie' => $g];
        }
        $out['totali'] = ['entrate' => $out['entrate']['totale'], 'uscite' => $out['uscite']['totale'],
            'netto' => round($out['entrate']['totale'] - $out['uscite']['totale'], 2)];
        return $out;
    }
}
