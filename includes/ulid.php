<?php
declare(strict_types=1);

/**
 * Minimal ULID generator (Crockford base32, 48-bit time + 80-bit random).
 * 26 chars, monotonic-ish (timestamp ordered), URL-safe.
 *
 * https://github.com/ulid/spec
 */

if (!function_exists('ulid')) {
function ulid(): string
{
    static $alphabet = '0123456789ABCDEFGHJKMNPQRSTVWXYZ';

    $time = (int) (microtime(true) * 1000); // 48-bit ms timestamp
    $timeChars = '';
    for ($i = 9; $i >= 0; $i--) {
        $timeChars = $alphabet[$time & 31] . $timeChars;
        $time >>= 5;
    }

    $rand = random_bytes(10); // 80 bits
    // Pack the 10 bytes into 16 base32 chars.
    $bits = '';
    foreach (str_split($rand) as $b) {
        $bits .= str_pad(decbin(ord($b)), 8, '0', STR_PAD_LEFT);
    }
    $randChars = '';
    for ($i = 0; $i < 16; $i++) {
        $chunk = substr($bits, $i * 5, 5);
        $randChars .= $alphabet[bindec($chunk)];
    }

    return $timeChars . $randChars;
}
}
