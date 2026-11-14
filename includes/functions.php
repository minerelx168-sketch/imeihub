<?php
declare(strict_types=1);

/**
 * Validates a 15-digit IMEI using the Luhn checksum.
 */
function imei_is_valid(string $imei): bool
{
    $imei = preg_replace('/\D+/', '', $imei) ?? '';
    if (strlen($imei) !== 15) {
        return false;
    }
    $sum = 0;
    for ($i = 0; $i < 15; $i++) {
        $digit = (int) $imei[$i];
        if ($i % 2 === 1) {
            $digit *= 2;
            if ($digit > 9) {
                $digit -= 9;
            }
        }
        $sum += $digit;
    }
    return $sum % 10 === 0;
}

function imei_normalize(string $imei): string
{
    return preg_replace('/\D+/', '', $imei) ?? '';
}

/**
 * The TAC is the first 8 digits of the IMEI and identifies brand+model.
 */
function imei_tac(string $imei): string
{
    return substr(imei_normalize($imei), 0, 8);
}

function client_ip(): string
{
    // Trust ONLY CF-Connecting-IP (Cloudflare overwrites it; the client
    // cannot forge it through CF) then the real socket peer. We deliberately
    // do NOT trust X-Forwarded-For: its first hop is client-controlled, so
    // honouring it lets anyone mint a fresh rate-limit bucket per request.
    // Lock the origin to Cloudflare's IP ranges at the firewall so a direct
    // hit can't spoof CF-Connecting-IP either.
    foreach (['HTTP_CF_CONNECTING_IP', 'REMOTE_ADDR'] as $h) {
        if (!empty($_SERVER[$h])) {
            $ip = trim((string) $_SERVER[$h]);
            if (filter_var($ip, FILTER_VALIDATE_IP)) {
                return $ip;
            }
        }
    }
    return '0.0.0.0';
}

/**
 * Returns true if the caller may proceed, false if the rate limit has been hit.
 * Window is 60 seconds, limit is $maxHits per window.
 */
function rate_limit_allow(string $ip, int $maxHits = 10): bool
{
    try {
        $pdo = db();
    } catch (Throwable $e) {
        // If DB is unavailable, don't block lookups (degrade gracefully).
        return true;
    }
    $packed = @inet_pton($ip) ?: inet_pton('0.0.0.0');
    $now = time();

    $stmt = $pdo->prepare('SELECT UNIX_TIMESTAMP(window_start) AS ws, hits FROM imei_rate_limit WHERE ip = ?');
    $stmt->execute([$packed]);
    $row = $stmt->fetch();

    if (!$row || ($now - (int) $row['ws']) > 60) {
        $pdo->prepare(
            'REPLACE INTO imei_rate_limit (ip, window_start, hits) VALUES (?, NOW(), 1)'
        )->execute([$packed]);
        return true;
    }

    if ((int) $row['hits'] >= $maxHits) {
        return false;
    }

    $pdo->prepare('UPDATE imei_rate_limit SET hits = hits + 1 WHERE ip = ?')->execute([$packed]);
    return true;
}

/**
 * Returns a cached lookup row if we've checked this IMEI in the last $ttlMinutes.
 */
function lookup_get_cached(string $imei, int $ttlMinutes = 60 * 24): ?array
{
    try {
        $pdo = db();
    } catch (Throwable $e) {
        return null;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM imei_lookups
         WHERE imei = ? AND status = "success"
           AND created_at > (NOW() - INTERVAL ? MINUTE)
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$imei, $ttlMinutes]);
    $row = $stmt->fetch();
    return $row ?: null;
}

function lookup_save(array $data): void
{
    try {
        $pdo = db();
    } catch (Throwable $e) {
        return;
    }
    $stmt = $pdo->prepare(
        'INSERT INTO imei_lookups
            (imei, tac, provider, service_id, status, brand, model, raw_response)
         VALUES
            (:imei, :tac, :provider, :service_id, :status, :brand, :model, :raw)'
    );
    $stmt->execute([
        ':imei'       => $data['imei'],
        ':tac'        => $data['tac'],
        ':provider'   => $data['provider'],
        ':service_id' => $data['service_id'] ?? null,
        ':status'     => $data['status'],
        ':brand'      => $data['brand'] ?? null,
        ':model'      => $data['model'] ?? null,
        ':raw'        => $data['raw_response'] ?? null,
    ]);
}
