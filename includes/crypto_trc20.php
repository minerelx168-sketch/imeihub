<?php
declare(strict_types=1);

/**
 * Verify an incoming USDT TRC20 transfer on the TRON chain.
 *
 * Strategy: ask TronGrid for the tx info, locate the Transfer event log on the
 * USDT contract, confirm the recipient is our receive address, then read the
 * amount and current confirmations. We never trust the user-supplied amount;
 * the credit equals what the chain actually delivered.
 *
 *   $r = crypto_trc20_verify($txid);
 *   // $r = ['ok'=>true, 'amount_usd'=>'10.50', 'token'=>'USDT',
 *   //       'confirmations'=>5, 'to'=>'T....', 'error'=>null]
 *   // or  ['ok'=>false, 'error'=>'...', 'confirmations'=>n]
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bignum.php';

if (!function_exists('crypto_trc20_verify')) {
function crypto_trc20_verify(string $txid): array
{
    $cfg = (require __DIR__ . '/config.php')['crypto']['trc20'];

    $txid = strtolower(trim($txid));
    if (!preg_match('/^[0-9a-f]{64}$/', $txid)) {
        return ['ok' => false, 'error' => 'TxID format does not look like a TRON transaction (expected 64 hex chars).'];
    }
    $receiveAddr = (string) $cfg['address'];
    if ($receiveAddr === '') {
        return ['ok' => false, 'error' => 'TRC20 receive address is not configured (set CRYPTO_TRC20_ADDRESS).'];
    }
    try {
        $receiveHex = crypto_trc20_base58_to_hex($receiveAddr);
    } catch (Throwable $e) {
        return ['ok' => false, 'error' => 'Configured TRC20 address is invalid: ' . $e->getMessage()];
    }
    // strip the leading 0x41 byte that Tron prepends to mainnet addresses;
    // the log topic stores the EVM-style 20-byte form (40 hex chars).
    $receiveEvm = strtolower(substr($receiveHex, 2));

    // 1. Fetch the transaction info.
    [$tx, $err] = crypto_trc20_post($cfg, '/wallet/gettransactioninfobyid', ['value' => $txid]);
    if ($err) return ['ok' => false, 'error' => 'Could not reach TronGrid: ' . $err];
    if (!is_array($tx) || empty($tx)) {
        return ['ok' => false, 'error' => 'Transaction not found on TRON yet. If you just sent it, wait ~30s and try again.'];
    }
    $receiptResult = $tx['receipt']['result'] ?? '';
    if ($receiptResult !== '' && $receiptResult !== 'SUCCESS') {
        return ['ok' => false, 'error' => 'Transaction failed on chain (status: ' . $receiptResult . ').'];
    }

    // 2. Locate the Transfer log on the USDT contract that targets our address.
    $transferSig    = 'ddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    $usdtContract   = strtolower((string) $cfg['usdt_contract_hex']);
    $matchedAmount  = null;
    $matchedToHex   = null;
    foreach ((array) ($tx['log'] ?? []) as $log) {
        $addr = strtolower((string) ($log['address'] ?? ''));
        if ($addr !== $usdtContract) continue;
        $topics = $log['topics'] ?? [];
        if (count($topics) < 3 || strtolower($topics[0]) !== $transferSig) continue;
        // topic[2] is a 64-hex-char padded address; the address is the last 40 chars.
        $toHex = strtolower(substr((string) $topics[2], -40));
        if ($toHex !== $receiveEvm) continue;
        $matchedToHex  = $toHex;
        $matchedAmount = (string) ($log['data'] ?? '0');
        break;
    }
    if ($matchedToHex === null) {
        return ['ok' => false, 'error' => 'This transaction does not contain a USDT (TRC20) transfer to our receive address.'];
    }

    // 3. Decode the amount (32-byte hex → uint256), divide by 10^6.
    $rawDec    = bn_from_hex($matchedAmount);
    $amountUsd = bn_div_pow10($rawDec, 6);
    if (!bn_is_positive_decimal($amountUsd)) {
        return ['ok' => false, 'error' => 'Transfer amount was zero.'];
    }

    // 4. Confirmations: current block - this tx's block.
    [$now, $nowErr] = crypto_trc20_post($cfg, '/wallet/getnowblock', []);
    $currentBlock   = (int) ($now['block_header']['raw_data']['number'] ?? 0);
    $txBlock        = (int) ($tx['blockNumber'] ?? 0);
    $confirmations  = $currentBlock > 0 && $txBlock > 0 ? max(0, $currentBlock - $txBlock) : 0;
    $minConf        = (int) $cfg['min_confirmations'];
    if ($confirmations < $minConf) {
        return [
            'ok'            => false,
            'pending'       => true,
            'confirmations' => $confirmations,
            'min_required'  => $minConf,
            'amount_usd'    => $amountUsd,
            'error'         => "Waiting for confirmations ({$confirmations}/{$minConf}). Please retry in a few seconds.",
        ];
    }

    return [
        'ok'            => true,
        'amount_usd'    => $amountUsd,
        'token'         => 'USDT',
        'confirmations' => $confirmations,
        'to'            => $receiveAddr,
        'error'         => null,
    ];
}
}

if (!function_exists('crypto_trc20_post')) {
function crypto_trc20_post(array $cfg, string $path, array $body): array
{
    $url     = $cfg['trongrid_url'] . $path;
    $headers = ['Content-Type: application/json', 'Accept: application/json'];
    if (!empty($cfg['trongrid_key'])) {
        $headers[] = 'TRON-PRO-API-KEY: ' . $cfg['trongrid_key'];
    }
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($body, JSON_UNESCAPED_SLASHES),
        CURLOPT_HTTPHEADER     => $headers,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
    ]);
    $raw  = curl_exec($ch);
    $err  = curl_error($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    if ($raw === false || $err) {
        return [null, $err ?: 'curl failure'];
    }
    if ($code >= 500) return [null, "HTTP $code"];
    $decoded = json_decode((string) $raw, true);
    if (!is_array($decoded)) return [null, 'invalid JSON from TronGrid'];
    return [$decoded, null];
}
}

if (!function_exists('crypto_trc20_base58_to_hex')) {
/**
 * Decode a Tron base58check address (T…) to its 21-byte hex form. Throws on a
 * bad checksum. Pure-PHP arithmetic via includes/bignum.php so we don't
 * depend on gmp / bcmath. Tron reuses Bitcoin's base58check (alphabet +
 * double-SHA256 checksum), so this is the standard algorithm.
 */
function crypto_trc20_base58_to_hex(string $b58): string
{
    $alphabet = '123456789ABCDEFGHJKLMNPQRSTUVWXYZabcdefghijkmnopqrstuvwxyz';
    $num = '0';
    $len = strlen($b58);
    for ($i = 0; $i < $len; $i++) {
        $pos = strpos($alphabet, $b58[$i]);
        if ($pos === false) {
            throw new RuntimeException('invalid base58 character');
        }
        $num = bn_add(bn_mul_small($num, 58), (string) $pos);
    }
    $bytes = bn_to_bytes($num);
    // Each leading '1' in the input represents a leading 0x00 byte.
    $leading = 0;
    for ($i = 0; $i < $len && $b58[$i] === '1'; $i++) $leading++;
    if ($leading > 0) $bytes = str_repeat("\x00", $leading) . $bytes;
    if (strlen($bytes) < 5) {
        throw new RuntimeException('decoded payload too short');
    }
    $payload  = substr($bytes, 0, -4);
    $checksum = substr($bytes, -4);
    $expected = substr(hash('sha256', hash('sha256', $payload, true), true), 0, 4);
    if (!hash_equals($checksum, $expected)) {
        throw new RuntimeException('bad base58check checksum');
    }
    return bin2hex($payload);
}
}
