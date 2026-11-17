<?php
declare(strict_types=1);

/**
 * Tiny string-only arbitrary-precision integer helpers.
 *
 * Only what the crypto verifiers need: convert a uint256 hex from a TRC20/BEP20
 * Transfer log to a decimal string, divide that by 10^decimals to get a human
 * USD amount, and do the base58 conversion that decodes a TRON address.
 * Implemented in plain PHP so we don't depend on bcmath or gmp (neither is
 * compiled into our Docker image).
 */

if (!function_exists('bn_add')) {
function bn_add(string $a, string $b): string
{
    $a = ltrim($a, '0'); if ($a === '') $a = '0';
    $b = ltrim($b, '0'); if ($b === '') $b = '0';
    $i = strlen($a) - 1; $j = strlen($b) - 1;
    $carry = 0; $out = '';
    while ($i >= 0 || $j >= 0 || $carry) {
        $da = $i >= 0 ? (int) $a[$i--] : 0;
        $db = $j >= 0 ? (int) $b[$j--] : 0;
        $s  = $da + $db + $carry;
        $out = (string) ($s % 10) . $out;
        $carry = intdiv($s, 10);
    }
    return $out === '' ? '0' : $out;
}
}

if (!function_exists('bn_mul_small')) {
function bn_mul_small(string $a, int $n): string
{
    if ($n < 0 || $n > 0x3fffffff) {
        throw new RuntimeException('bn_mul_small multiplier out of range');
    }
    $a = ltrim($a, '0');
    if ($a === '' || $n === 0) return '0';
    $out = ''; $carry = 0;
    for ($i = strlen($a) - 1; $i >= 0; $i--) {
        $s = ((int) $a[$i]) * $n + $carry;
        $out = (string) ($s % 10) . $out;
        $carry = intdiv($s, 10);
    }
    while ($carry > 0) {
        $out = (string) ($carry % 10) . $out;
        $carry = intdiv($carry, 10);
    }
    return $out;
}
}

if (!function_exists('bn_from_hex')) {
function bn_from_hex(string $hex): string
{
    $hex = strtolower(trim($hex));
    if (str_starts_with($hex, '0x')) $hex = substr($hex, 2);
    $hex = ltrim($hex, '0');
    if ($hex === '') return '0';
    $dec = '0';
    $len = strlen($hex);
    for ($i = 0; $i < $len; $i++) {
        $c = $hex[$i];
        if ($c >= '0' && $c <= '9')      $d = ord($c) - 48;
        elseif ($c >= 'a' && $c <= 'f')  $d = ord($c) - 87;
        else throw new RuntimeException('bn_from_hex: invalid hex char');
        $dec = bn_add(bn_mul_small($dec, 16), (string) $d);
    }
    return $dec;
}
}

if (!function_exists('bn_div_pow10')) {
/**
 * Return $a / 10^$exp as a normalized decimal string ("10.5", "0.000001", "0").
 * Used to turn raw uint256 token amounts into a USD-style fixed-point number.
 */
function bn_div_pow10(string $a, int $exp): string
{
    $a = ltrim($a, '0'); if ($a === '') $a = '0';
    if ($exp <= 0) return $a;
    $len = strlen($a);
    if ($len <= $exp) {
        $frac = rtrim(str_repeat('0', $exp - $len) . $a, '0');
        return $frac === '' ? '0' : '0.' . $frac;
    }
    $int  = substr($a, 0, $len - $exp);
    $frac = rtrim(substr($a, $len - $exp), '0');
    return $frac === '' ? $int : $int . '.' . $frac;
}
}

if (!function_exists('bn_div_mod_small')) {
function bn_div_mod_small(string $a, int $n): array
{
    if ($n <= 0 || $n > 0x3fffffff) {
        throw new RuntimeException('bn_div_mod_small divisor out of range');
    }
    $a = ltrim($a, '0');
    if ($a === '') return ['0', 0];
    $rem = 0; $out = '';
    $len = strlen($a);
    for ($i = 0; $i < $len; $i++) {
        $cur  = $rem * 10 + (int) $a[$i];
        $out .= (string) intdiv($cur, $n);
        $rem  = $cur % $n;
    }
    $out = ltrim($out, '0');
    return [$out === '' ? '0' : $out, $rem];
}
}

if (!function_exists('bn_to_bytes')) {
/**
 * Convert a non-negative decimal string to its big-endian byte representation.
 * Used by the TRC20 base58check decoder to recover the raw 25-byte payload.
 */
function bn_to_bytes(string $dec): string
{
    $bytes = '';
    while ($dec !== '0' && $dec !== '') {
        [$dec, $r] = bn_div_mod_small($dec, 256);
        $bytes = chr($r) . $bytes;
    }
    return $bytes;
}
}

if (!function_exists('bn_is_zero')) {
function bn_is_zero(string $a): bool
{
    $a = ltrim($a, '0');
    return $a === '' || $a === '.';
}
}

if (!function_exists('bn_is_positive_decimal')) {
/**
 * True if a normalized decimal string (output of bn_div_pow10) is > 0.
 * Allows "0.5", "10", "10.5"; rejects "0", "0.0", "".
 */
function bn_is_positive_decimal(string $s): bool
{
    if ($s === '' || $s === '0') return false;
    if (str_contains($s, '.')) {
        [$i, $f] = explode('.', $s, 2);
        return ltrim($i, '0') !== '' || rtrim($f, '0') !== '';
    }
    return ltrim($s, '0') !== '';
}
}
