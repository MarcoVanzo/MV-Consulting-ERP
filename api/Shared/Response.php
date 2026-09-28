<?php

require_once __DIR__ . '/Avvisi.php';

/** Lanciata al posto dell'uscita quando la risposta va catturata (anteprima degli import). */
class RispostaCatturata extends RuntimeException {
    public array $risposta;

    public function __construct(array $risposta) {
        parent::__construct('Risposta catturata');
        $this->risposta = $risposta;
    }
}

class Response {
    /** In anteprima la risposta non esce: si lancia RispostaCatturata e decide Anteprima. */
    public static bool $cattura = false;
    /** Prima risposta catturata: le successive (es. da un catch che la intercetta) la ripetono. */
    private static ?array $catturata = null;

    public static function json($success, $message = '', $data = null, $httpCode = 200) {
        $response = [
            'success' => $success,
            'message' => $message,
        ];

        // Segnalazioni raccolte durante la richiesta (Avvisi): mai perse, anche se il controller non le passa
        $avvisi = Avvisi::tutti();
        if ($avvisi && (is_array($data) || $data === null)) {
            $data = is_array($data) ? $data : [];
            $data['avvisi_sistema'] = array_values(array_unique(array_merge($data['avvisi_sistema'] ?? [], $avvisi)));
        }

        if ($data !== null) {
            $response['data'] = $data;
        } elseif (!$success) {
            $response['error'] = $message;
        }

        if (self::$cattura) {
            $response['http_code'] = $httpCode;
            if (self::$catturata === null) self::$catturata = $response;
            throw new RispostaCatturata(self::$catturata);
        }

        http_response_code($httpCode);
        echo json_encode($response);
        exit;
    }

    /** Fine della cattura: la prossima risposta esce normalmente. */
    public static function fineCattura(): void {
        self::$cattura = false;
        self::$catturata = null;
    }
}
