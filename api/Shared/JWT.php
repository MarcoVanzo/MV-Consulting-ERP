<?php
/**
 * Lightweight JWT Generator / Validator
 */
class JWT {
    public function __construct() {}

    /**
     * Encode a base64url string (RFC 4648 §5)
     */
    private static function base64UrlEncode(string $data): string {
        return str_replace(['+', '/', '='], ['-', '_', ''], base64_encode($data));
    }

    /**
     * Decode a base64url string back to raw bytes.
     * Converts base64url chars (- _) back to standard base64 (+ /) and restores padding.
     */
    private static function base64UrlDecode(string $data): string {
        $base64 = str_replace(['-', '_'], ['+', '/'], $data);
        // Restore padding
        $remainder = strlen($base64) % 4;
        if ($remainder) {
            $base64 .= str_repeat('=', 4 - $remainder);
        }
        return base64_decode($base64);
    }

    public static function encode($payload, $secret) {
        $base64UrlHeader  = self::base64UrlEncode(json_encode(['typ' => 'JWT', 'alg' => 'HS256']));
        $base64UrlPayload = self::base64UrlEncode(json_encode($payload));

        $signature = hash_hmac('sha256', $base64UrlHeader . "." . $base64UrlPayload, $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        return $base64UrlHeader . "." . $base64UrlPayload . "." . $base64UrlSignature;
    }

    public static function decode($jwt, $secret) {
        $tokenParts = explode('.', $jwt);
        
        if (count($tokenParts) != 3) {
            return false;
        }

        // Verify signature using the RAW base64url parts from the token (no decode/re-encode roundtrip)
        $signature = hash_hmac('sha256', $tokenParts[0] . "." . $tokenParts[1], $secret, true);
        $base64UrlSignature = self::base64UrlEncode($signature);

        if (!hash_equals($base64UrlSignature, $tokenParts[2])) {
            return false;
        }

        // Signature valid — decode payload
        $payload = self::base64UrlDecode($tokenParts[1]);
        $data = json_decode($payload, true);

        if (isset($data['exp']) && $data['exp'] < time()) {
            return false; // Token expired
        }

        return $data;
    }
}
