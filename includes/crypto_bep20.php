<?php
declare(strict_types=1);

/**
 * Verify an incoming USDT/USDC (BEP-20) transfer on BNB Smart Chain.
 *
 * Strategy: ask BscScan for the receipt, locate the Transfer event log on the
 * USDT or USDC contract, confirm the recipient is our receive address, then
 * read the amount and current confirmations. The credit equals what the chain
 * actually delivered; we never trust user-supplied amounts.
 *
 *   $r = crypto_bep20_verify($txid);
 *   // $r = ['ok'=>true, 'amount_usd'=>'10.50', 'token'=>'USDT'|'USDC',
 *   //       'confirmations'=>5, 'to'=>'0x...', 'error'=>null]
 *   // or  ['ok'=>false, 'error'=>'...', 'confirmations'=>n]
 */

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/bignum.php';

if (!function_exists('crypto_bep20_verify')) {
function crypto_bep20_verify(string $txid): array
{
    $cfg = (require __DIR__ . '/config.php')['crypto']['bep20'];

    $txid = strtolower(trim($txid));
    if (!preg_match('/^0x[0-9a-f]{64}$/', $txid)) {
        return ['ok' => false, 'error' => 'TxID format does not look like a BSC transaction (expected 0x + 64 hex chars).'];
    }
    $receiveAddr = strtolower((string) $cfg['address']);
    if ($receiveAddr === '' || !preg_match('/^0x[0-9a-f]{40}$/', $receiveAddr)) {
        return ['ok' => false, 'error' => 'BEP-20 receive address is not configured (set CRYPTO_BEP20_ADDRESS).'];
    }
    if (empty($cfg['bscscan_key'])) {
        return ['ok' => false, 'error' => 'BscScan API key is not configured (set BSCSCAN_API_KEY).'];
    }

    // 1. Fetch the receipt.
    [$resp, $err] = crypto_bep20_get($cfg, [
        'module' => 'proxy',
        'action' => 'eth_getTransactionReceipt',
        'txhash' => $txid,
    ]);
    if ($err) return ['ok' => false, 'error' => 'Could not reach BscScan: ' . $err];
    $receipt = $resp['result'] ?? null;
    if (!is_array($receipt)) {
        return ['ok' => false, 'error' => 'Transaction not found on BSC yet. If you just sent it, wait ~30s and try again.'];
    }
    if (strtolower((string) ($receipt['status'] ?? '')) !== '0x1') {
        return ['ok' => false, 'error' => 'Transaction failed on chain.'];
    }

    // 2. Locate the Transfer log on USDT or USDC, targeting our address.
    $transferSig  = '0xddf252ad1be2c89b69c2b068fc378daa952ba7f163c4a11628f55a4df523b3ef';
    $tokens       = [
        strtolower((string) $cfg['usdt_contract']) => 'USDT',
        strtolower((string) $cfg['usdc_contract']) => 'USDC',
    ];
    $receivePad   = '0x' . str_repeat('0', 24) . substr($receiveAddr, 2);
    $matchedToken = null;
    $matchedAmt   = null;
    foreach ((array) ($receipt['logs'] ?? []) as $log) {
        $addr = strtolower((string) ($log['address'] ?? ''));
        if (!isset($tokens[$addr])) continue;
        $topics = $log['topics'] ?? [];
        if (count($topics) < 3 || strtolower((string) $topics[0]) !== $transferSig) continue;
        if (strtolower((string) $topics[2]) !== $receivePad) continue;
        $matchedToken = $tokens[$addr];
        $matchedAmt   = (string) ($log['data'] ?? '0x0');
        break;
    }
    if ($matchedToken === null) {
        return ['ok' => false, 'error' => 'This transaction does not contain a USDT/USDC (BEP-20) transfer to our receive address.'];
    }

    // 3. Decode amount (32-byte hex → uint256), divide by 10^18 (both tokens use 18 decimals on BSC).
    $rawDec    = bn_from_hex($matchedAmt);
    $amountUsd = bn_div_pow10($rawDec, 18);
    if (!bn_is_positive_decimal($amountUsd)) {
        return ['ok' => false, 'error' => 'Transfer amount was zero.'];
    }

    // 4. Confirmations.
    [$nowResp, $nowErr] = crypto_bep20_get($cfg, [
        'module' => 'proxy',
        'action' => 'eth_blockNumber',
    ]);
    $currentBlock  = is_string($nowResp['result'] ?? null) ? (int) hexdec($nowResp['result']) : 0;
    $txBlock       = is_string($receipt['blockNumber'] ?? null) ? (int) hexdec($receipt['blockNumber']) : 0;
    $confirmations = $currentBlock > 0 && $txBlock > 0 ? max(0, $currentBlock - $txBlock) : 0;
    $minConf       = (int) $cfg['min_confirmations'];
    if ($confirmations < $minConf) {
        return [
            'ok'            => false,
            'pending'       => true,
            'confirmations' => $confirmations,
            'min_required'  => $minConf,
            'amount_usd'    => $amountUsd,
            'token'         => $matchedToken,
            'error'         => "Waiting for confirmations ({$confirmations}/{$minConf}). Please retry in a few seconds.",
        ];
    }

    return [
        'ok'            => true,
        'amount_usd'    => $amountUsd,
        'token'         => $matchedToken,
        'confirmations' => $confirmations,
        'to'            => $cfg['address'],
        'error'         => null,
    ];
}
}

if (!function_exists('crypto_bep20_get')) {
function crypto_bep20_get(array $cfg, array $params): array
{
    $params['apikey'] = $cfg['bscscan_key'];
    $url = $cfg['bscscan_url'] . '?' . http_build_query($params);
    $ch  = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 12,
        CURLOPT_HTTPHEADER     => ['Accept: application/json'],
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
    if (!is_array($decoded)) return [null, 'invalid JSON from BscScan'];
    return [$decoded, null];
}
}
