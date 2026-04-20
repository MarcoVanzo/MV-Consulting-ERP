<?php

class Response {
    public static function json($success, $message = '', $data = null, $httpCode = 200) {
        http_response_code($httpCode);
        
        $response = [
            'success' => $success,
            'message' => $message,
        ];
        
        if ($data !== null) {
            $response['data'] = $data;
        } elseif (!$success) {
            $response['error'] = $message;
        }

        echo json_encode($response);
        exit;
    }
}
