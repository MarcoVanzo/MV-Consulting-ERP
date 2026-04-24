<?php
declare(strict_types=1);

class Security
{
    public static function generateTempPassword(int $length = 14): string
    {
        $chars_lower = 'abcdefghjkmnpqrstuvwxyz';
        $chars_upper = 'ABCDEFGHJKLMNPQRSTUVWXYZ';
        $chars_digit = '23456789';
        $chars_special = '!@#$%^&*()';
        $all_chars = $chars_lower . $chars_upper . $chars_digit . $chars_special;

        try {
            $pass = $chars_lower[random_int(0, strlen($chars_lower) - 1)]
                . $chars_upper[random_int(0, strlen($chars_upper) - 1)]
                . $chars_digit[random_int(0, strlen($chars_digit) - 1)]
                . $chars_special[random_int(0, strlen($chars_special) - 1)];
            for ($i = 4; $i < $length; $i++) {
                $pass .= $all_chars[random_int(0, strlen($all_chars) - 1)];
            }
            
            $passArr = str_split($pass);
            for ($i = count($passArr) - 1; $i > 0; $i--) {
                $j = random_int(0, $i);
                [$passArr[$i], $passArr[$j]] = [$passArr[$j], $passArr[$i]];
            }
            return implode('', $passArr);
        } catch (\Exception $e) {
            return substr(str_shuffle($all_chars), 0, $length);
        }
    }

    public static function validatePasswordComplexity(string $password): bool
    {
        return strlen($password) >= 12 &&
            preg_match('/[A-Z]/', $password) &&
            preg_match('/[a-z]/', $password) &&
            preg_match('/[0-9]/', $password) &&
            preg_match('/[^A-Za-z0-9]/', $password);
    }
}
