<?php
/**
 * Percorsi — geocodifica degli indirizzi (Nominatim) e distanza stradale (OSRM) per le trasferte.
 *
 * Le coordinate si salvano in {prefix}geocache, indicizzate sul testo dell'indirizzo: un
 * indirizzo cambiato è una chiave nuova, quindi non c'è niente da invalidare. Così Nominatim
 * (massimo 1 richiesta al secondo) si interroga una volta per indirizzo, non a ogni salvataggio.
 * Un indirizzo non trovato si riprova dopo 7 giorni.
 *
 * Il routing usa OSRM_URL dal .env; senza, il server dimostrativo pubblico di OSRM, che non ha
 * garanzie di servizio.
 */
declare(strict_types=1);

class Percorsi
{
    private const OSRM_DEMO = 'https://router.project-osrm.org';
    private const RIPROVA_NON_TROVATI = 7 * 86400;

    private PDO $pdo;
    private string $prefix;
    private array $memoria = [];
    private float $ultimaRichiesta = 0.0;

    public function __construct(PDO $pdo, string $prefix)
    {
        $this->pdo = $pdo;
        $this->prefix = $prefix;
    }

    public static function indirizzoBase(): string
    {
        return getenv('BASE_ADDRESS') ?: 'Via Manzoni 5, Zero Branco, TV';
    }

    /** Coordinate ['lat','lon'] di un indirizzo, o null se non si trova */
    public function geocode(string $indirizzo): ?array
    {
        $norm = mb_strtolower(trim(preg_replace('/\s+/', ' ', $indirizzo)), 'UTF-8');
        if ($norm === '') return null;
        $chiave = md5($norm);
        if (array_key_exists($chiave, $this->memoria)) return $this->memoria[$chiave];

        $cache = $this->leggiCache($chiave);
        if ($cache !== false) return $this->memoria[$chiave] = $cache;

        $coord = $this->nominatim($indirizzo);
        if ($coord !== false) $this->scriviCache($chiave, $norm, $coord);
        return $this->memoria[$chiave] = ($coord ?: null);
    }

    /** false = non in cache (o da riprovare); null = non trovato di recente; array = coordinate */
    private function leggiCache(string $chiave)
    {
        try {
            $stmt = $this->pdo->prepare("SELECT lat, lon, updated_at FROM {$this->prefix}geocache WHERE indirizzo_hash = ?");
            $stmt->execute([$chiave]);
            $r = $stmt->fetch(PDO::FETCH_ASSOC);
        } catch (PDOException $e) {
            return false; // tabella non ancora migrata: si lavora senza cache
        }
        if (!$r) return false;
        if ($r['lat'] !== null && $r['lon'] !== null) return ['lat' => (float)$r['lat'], 'lon' => (float)$r['lon']];
        return (time() - strtotime((string)$r['updated_at'])) < self::RIPROVA_NON_TROVATI ? null : false;
    }

    private function scriviCache(string $chiave, string $norm, ?array $coord): void
    {
        try {
            $this->pdo->prepare("REPLACE INTO {$this->prefix}geocache (indirizzo_hash, indirizzo, lat, lon, updated_at) VALUES (?, ?, ?, ?, ?)")
                ->execute([$chiave, mb_substr($norm, 0, 500), $coord['lat'] ?? null, $coord['lon'] ?? null, date('Y-m-d H:i:s')]);
        } catch (PDOException $e) {
            error_log('[Percorsi] cache non scritta: ' . $e->getMessage());
        }
    }

    /** array = trovato, null = Nominatim risponde ma non trova, false = errore (non va in cache) */
    private function nominatim(string $indirizzo)
    {
        $attesa = 1.1 - (microtime(true) - $this->ultimaRichiesta);
        if ($attesa > 0) usleep((int)($attesa * 1000000)); // limite Nominatim: 1 richiesta al secondo
        $this->ultimaRichiesta = microtime(true);

        $url = 'https://nominatim.openstreetmap.org/search?' . http_build_query([
            'q' => $indirizzo, 'format' => 'json', 'limit' => 1, 'countrycodes' => 'it',
        ]);
        [$codice, $corpo, $err] = $this->get($url, 10, ['User-Agent: MV-Consulting-ERP/1.0 (marco@mv-consulting.it)']);
        if ($err || $codice !== 200) {
            error_log("[Percorsi] Geocode HTTP $codice $err per '$indirizzo'");
            return false;
        }
        $data = json_decode((string)$corpo, true);
        if (isset($data[0]['lat'], $data[0]['lon'])) return ['lat' => (float)$data[0]['lat'], 'lon' => (float)$data[0]['lon']];
        error_log("[Percorsi] Geocode: nessun risultato per '$indirizzo'");
        return null;
    }

    /** Km stradali del percorso che tocca i punti nell'ordine dato */
    public function km(array $punti): array
    {
        $base = rtrim(getenv('OSRM_URL') ?: self::OSRM_DEMO, '/');
        $coords = implode(';', array_map(fn($p) => $p['lon'] . ',' . $p['lat'], $punti));
        [$codice, $corpo, $err] = $this->get("$base/route/v1/driving/$coords?overview=false", 15);
        if ($err) {
            error_log("[Percorsi] OSRM: $err");
            return ['success' => false, 'message' => "Errore routing: $err"];
        }
        $data = json_decode((string)$corpo, true);
        if ($codice !== 200 || ($data['code'] ?? '') !== 'Ok' || !isset($data['routes'][0]['distance'])) {
            error_log("[Percorsi] OSRM HTTP $codice: " . ($data['code'] ?? 'risposta non valida'));
            return ['success' => false, 'message' => 'Impossibile calcolare il percorso (servizio di routing non disponibile).'];
        }
        return ['success' => true, 'totKm' => round($data['routes'][0]['distance'] / 1000, 1)];
    }

    private function get(string $url, int $timeout, array $header = []): array
    {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => $timeout,
            CURLOPT_CONNECTTIMEOUT => 5,
            CURLOPT_HTTPHEADER => $header,
        ]);
        $corpo = curl_exec($ch);
        $codice = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        curl_close($ch);
        return [$codice, $corpo, $err];
    }
}
