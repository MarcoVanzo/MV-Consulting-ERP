<?php
/**
 * Clienti Controller — CRUD + VAT Lookup via OpenAPI.it
 */

class ClientiController {
    private $pdo;
    private $prefix;

    public function __construct() {
        $this->pdo = Database::getConnection();
        $this->prefix = getenv('DB_PREFIX') ?: 'mv_';
    }

    public function list() {
        $stmt = $this->pdo->query("SELECT c.*, 
            (SELECT COUNT(*) FROM {$this->prefix}sottoclienti WHERE cliente_id = c.id) as num_sottoclienti
            FROM {$this->prefix}clienti c ORDER BY c.ragione_sociale ASC");
        $clienti = $stmt->fetchAll();
        Response::json(true, '', $clienti);
    }

    public function get($id) {
        $stmt = $this->pdo->prepare("SELECT * FROM {$this->prefix}clienti WHERE id = ?");
        $stmt->execute([$id]);
        $cliente = $stmt->fetch();
        if (!$cliente) {
            Response::json(false, 'Cliente non trovato');
        }
        // Fetch sottoclienti
        $stmt2 = $this->pdo->prepare("SELECT * FROM {$this->prefix}sottoclienti WHERE cliente_id = ? ORDER BY nome ASC");
        $stmt2->execute([$id]);
        $cliente['sottoclienti'] = $stmt2->fetchAll();
        Response::json(true, '', $cliente);
    }

    public function save($data) {
        $id = $data['id'] ?? null;
        $fields = [
            'ragione_sociale' => trim($data['ragione_sociale'] ?? ''),
            'partita_iva'     => trim($data['partita_iva'] ?? ''),
            'codice_fiscale'  => trim($data['codice_fiscale'] ?? ''),
            'indirizzo'       => trim($data['indirizzo'] ?? ''),
            'citta'           => trim($data['citta'] ?? ''),
            'cap'             => trim($data['cap'] ?? ''),
            'provincia'       => trim($data['provincia'] ?? ''),
            'pec'             => trim($data['pec'] ?? ''),
            'sdi'             => trim($data['sdi'] ?? ''),
            'telefono'        => trim($data['telefono'] ?? ''),
            'email'           => trim($data['email'] ?? ''),
            'note'            => trim($data['note'] ?? '')
        ];

        if (empty($fields['ragione_sociale'])) {
            Response::json(false, 'Ragione sociale obbligatoria');
        }

        if ($id) {
            $sets = [];
            $vals = [];
            foreach ($fields as $k => $v) {
                $sets[] = "$k = ?";
                $vals[] = $v;
            }
            $vals[] = $id;
            $sql = "UPDATE {$this->prefix}clienti SET " . implode(', ', $sets) . " WHERE id = ?";
            $this->pdo->prepare($sql)->execute($vals);
            Audit::log('UPDATE', 'clienti', $id, null, null, ['ragione_sociale' => $fields['ragione_sociale']]);
            Response::json(true, 'Cliente aggiornato', ['id' => $id]);
        } else {
            $cols = implode(', ', array_keys($fields));
            $placeholders = implode(', ', array_fill(0, count($fields), '?'));
            $sql = "INSERT INTO {$this->prefix}clienti ($cols) VALUES ($placeholders)";
            $this->pdo->prepare($sql)->execute(array_values($fields));
            $newId = $this->pdo->lastInsertId();
            Audit::log('INSERT', 'clienti', $newId, null, null, ['ragione_sociale' => $fields['ragione_sociale']]);
            Response::json(true, 'Cliente creato', ['id' => $newId]);
        }
    }

    public function delete($id) {
        $stmt = $this->pdo->prepare("DELETE FROM {$this->prefix}clienti WHERE id = ?");
        $stmt->execute([$id]);
        Audit::log('DELETE', 'clienti', $id, null, null, null);
        Response::json(true, 'Cliente eliminato');
    }

    /**
     * VAT Lookup via OpenAPI.it (ported from MV-ERP)
     */
    public function lookupVat($vatCode) {
        $vatCode = preg_replace('/[^0-9]/', '', trim($vatCode));

        if (!preg_match('/^\d{11}$/', $vatCode)) {
            Response::json(false, 'Formato Partita IVA non valido. Deve essere di 11 cifre.');
        }

        $apiToken = getenv('OPENAPI_IT_TOKEN');
        if (!$apiToken) {
            Response::json(false, "Servizio di ricerca P.IVA non configurato. Aggiungere OPENAPI_IT_TOKEN nel file .env.");
        }

        $url = "https://company.openapi.com/IT-start/" . $vatCode;

        $ch = curl_init($url);
        $curlOpts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_HTTPHEADER     => [
                "Authorization: Bearer " . $apiToken,
                "Accept: application/json"
            ],
            CURLOPT_TIMEOUT        => 20,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 3
        ];
        curl_setopt_array($ch, $curlOpts);

        $response = curl_exec($ch);
        $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $curlError = curl_error($ch);
        curl_close($ch);

        if ($curlError) {
            Response::json(false, "Errore di connessione al servizio di ricerca. Riprovare.");
        }
        if ($httpCode === 404) {
            Response::json(false, 'Nessuna azienda trovata per la P.IVA: ' . $vatCode);
        }
        if ($httpCode === 401 || $httpCode === 403) {
            Response::json(false, 'Errore di autenticazione con il servizio OpenAPI. Verificare il token.');
        }
        if ($httpCode !== 200) {
            Response::json(false, "Errore dal servizio di ricerca (HTTP $httpCode).");
        }

        $data = json_decode($response, true);
        if (!$data) {
            Response::json(false, 'Risposta non valida dal servizio di ricerca.');
        }

        // Map OpenAPI response to our ERP fields
        $company = null;
        if (isset($data['data']) && is_array($data['data']) && count($data['data']) > 0) {
            $company = $data['data'][0];
        } else {
            $company = $data;
        }

        $office = $company['address']['registeredOffice'] ?? [];

        $streetParts = array_filter([
            $office['toponym'] ?? '',
            $office['street'] ?? '',
            $office['streetNumber'] ?? ''
        ]);
        $fullAddress = trim(implode(' ', $streetParts));
        if (empty($fullAddress) && !empty($office['streetName'])) {
            $fullAddress = $office['streetName'];
        }

        $result = [
            'ragione_sociale'    => $company['companyName'] ?? $company['denominazione'] ?? '',
            'partita_iva'        => $company['vatCode'] ?? $company['partita_iva'] ?? $vatCode,
            'codice_fiscale'     => $company['taxCode'] ?? $company['codice_fiscale'] ?? '',
            'indirizzo'          => $fullAddress,
            'citta'              => $office['town'] ?? $office['comune'] ?? '',
            'cap'                => $office['zipCode'] ?? $office['cap'] ?? '',
            'provincia'          => $office['province'] ?? $office['provincia'] ?? '',
            'sdi'                => $company['sdiCode'] ?? $company['codice_destinatario'] ?? '',
            'pec'                => $company['pec'] ?? '',
            'stato_attivita'     => $company['activityStatus'] ?? $company['stato_attivita'] ?? ''
        ];

        Response::json(true, 'Dati azienda trovati', $result);
    }
}
