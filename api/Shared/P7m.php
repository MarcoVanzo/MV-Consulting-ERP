<?php
/**
 * P7m — estrae il documento contenuto in una busta firmata CAdES (.p7m, DER/BER).
 *
 * Legge la struttura ASN.1 senza verificare la firma: serve solo il contenuto (l'XML della
 * fattura). Gestisce il contenuto spezzato in blocchi (OCTET STRING costruita, anche a lunghezza
 * indefinita), che è il caso normale delle fatture scaricate dallo SDI.
 * Non richiede openssl_cms_verify (disponibile solo da PHP 8).
 */
declare(strict_types=1);

class P7m
{
    // OID 1.2.840.113549.1.7.1 (id-data), codificato DER
    private const OID_DATA = "\x06\x09\x2A\x86\x48\x86\xF7\x0D\x01\x07\x01";

    /**
     * XML di una fattura da un file .xml o firmato .xml.p7m (contenuto binario del file).
     * Stringa vuota se non si riesce a leggerlo.
     */
    public static function xmlDaFile(string $raw): string
    {
        if ($raw === '') return '';
        if (ltrim($raw)[0] === '<') return $raw;
        // Busta CAdES (p7m): a volte è a sua volta in base64
        if (preg_match('/^[A-Za-z0-9+\/=\r\n]+$/', $raw)) $raw = base64_decode($raw) ?: $raw;
        $xml = '';
        if (function_exists('openssl_cms_verify')) {
            // Si estrae solo il contenuto: la validità della firma non serve per registrare il documento
            $dir = sys_get_temp_dir();
            $in = tempnam($dir, 'p7m');
            $out = tempnam($dir, 'xml');
            file_put_contents($in, $raw);
            if (@openssl_cms_verify($in, OPENSSL_CMS_NOVERIFY | OPENSSL_CMS_NOSIGS | OPENSSL_CMS_BINARY, null, [], null, $out, null, null, OPENSSL_ENCODING_DER)) {
                $xml = (string)file_get_contents($out);
            }
            @unlink($in);
            @unlink($out);
        }
        // Riserva (busta che openssl non legge): estrazione diretta dalla struttura ASN.1
        if ($xml === '') $xml = (string)self::contenuto($raw);
        return $xml;
    }

    public static function contenuto(string $der): ?string
    {
        $pos = strpos($der, self::OID_DATA);
        if ($pos === false) return null;
        $pos += strlen(self::OID_DATA);
        try {
            // Dopo l'OID viene [0] EXPLICIT con l'OCTET STRING del contenuto
            $wrapper = self::header($der, $pos);
            if ($wrapper['tag'] !== 0xA0) return null;
            $out = '';
            self::octets($der, $wrapper['start'], $out);
            return $out !== '' ? $out : null;
        } catch (RuntimeException $e) {
            return null;
        }
    }

    /** Accoda a $out il contenuto dell'OCTET STRING che inizia in $pos; restituisce la posizione successiva. */
    private static function octets(string $d, int $pos, string &$out): int
    {
        $h = self::header($d, $pos);
        if ($h['tag'] === 0x04) {
            $out .= substr($d, $h['start'], $h['len']);
            return $h['start'] + $h['len'];
        }
        if ($h['tag'] !== 0x24) throw new RuntimeException('Contenuto non OCTET STRING');
        $p = $h['start'];
        $fine = $h['len'] === null ? null : $h['start'] + $h['len'];
        while (true) {
            if ($fine !== null && $p >= $fine) return $fine;
            if ($fine === null && substr($d, $p, 2) === "\x00\x00") return $p + 2;
            if ($p >= strlen($d)) throw new RuntimeException('Busta troncata');
            $p = self::octets($d, $p, $out);
        }
    }

    /** Tag e lunghezza dell'elemento in $pos (len null = lunghezza indefinita). */
    private static function header(string $d, int $pos): array
    {
        if ($pos + 2 > strlen($d)) throw new RuntimeException('Busta troncata');
        $tag = ord($d[$pos++]);
        $l = ord($d[$pos++]);
        if ($l === 0x80) {
            $len = null;
        } elseif ($l & 0x80) {
            $n = $l & 0x7F;
            if ($n > 4 || $pos + $n > strlen($d)) throw new RuntimeException('Lunghezza non valida');
            $len = 0;
            for ($i = 0; $i < $n; $i++) $len = ($len << 8) | ord($d[$pos++]);
        } else {
            $len = $l;
        }
        return ['tag' => $tag, 'len' => $len, 'start' => $pos];
    }
}
