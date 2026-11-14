<?php
declare(strict_types=1);

/**
 * Report real provider latency per service, from the timing breakdowns that
 * api/services/use.php persists into service_usages.output._timing.
 *
 * Use this to decide, with data, which services are slow enough to move to an
 * async (DHRU) flow. The number that matters is TTFB (time-to-first-byte) -
 * that's how long the upstream provider actually took to answer; everything
 * else (dns/connect/tls) is connection setup, and our own DB work isn't even
 * in here because it's sub-millisecond.
 *
 *   php scripts/provider-latency.php           # last 7 days
 *   php scripts/provider-latency.php --days 30
 *   php scripts/provider-latency.php --since 2026-06-03   # since the unblock
 *
 * NOTE: only SUCCESS rows carry a timing block. Rows from before this script
 * shipped won't have one and are skipped (shown as "no timing data").
 */

require __DIR__ . '/../includes/db.php';

$days  = 7;
$since = null;
for ($i = 1; $i < $argc; $i++) {
    if ($argv[$i] === '--days' && isset($argv[$i + 1])) { $days = max(1, (int) $argv[++$i]); }
    elseif ($argv[$i] === '--since' && isset($argv[$i + 1])) { $since = $argv[++$i]; }
}

$pdo = db();
if ($since !== null) {
    $stmt = $pdo->prepare(
        "SELECT service_code, output FROM service_usages
         WHERE status = 'SUCCESS' AND cost > 0 AND created_at >= ?"
    );
    $stmt->execute([$since]);
    $windowLabel = "since {$since}";
} else {
    $stmt = $pdo->prepare(
        "SELECT service_code, output FROM service_usages
         WHERE status = 'SUCCESS' AND cost > 0 AND created_at >= NOW() - INTERVAL ? DAY"
    );
    $stmt->execute([$days]);
    $windowLabel = "last {$days} day(s)";
}

// Collect per-service arrays of total + ttfb (ms).
$byService = [];   // code => ['total' => [], 'ttfb' => []]
$noTiming  = 0;
$withTiming = 0;
foreach ($stmt->fetchAll() as $row) {
    $code = (string) $row['service_code'];
    $out  = json_decode((string) $row['output'], true);
    $t    = is_array($out) ? ($out['_timing'] ?? null) : null;
    if (!is_array($t) || !isset($t['total_ms'])) { $noTiming++; continue; }
    $withTiming++;
    $byService[$code]['total'][] = (float) $t['total_ms'];
    $byService[$code]['ttfb'][]  = (float) ($t['ttfb_ms'] ?? $t['total_ms']);
}

if (!$byService) {
    echo "No timing data yet for {$windowLabel}.\n";
    echo "(Timing is recorded on paid SUCCESS lookups made after this feature shipped.)\n";
    if ($noTiming > 0) echo "Skipped {$noTiming} older row(s) without a timing block.\n";
    exit(0);
}

function pct(array $a, float $p): float {
    sort($a);
    $n = count($a);
    if ($n === 0) return 0.0;
    if ($n === 1) return $a[0];
    $rank = ($p / 100) * ($n - 1);
    $lo = (int) floor($rank); $hi = (int) ceil($rank);
    if ($lo === $hi) return $a[$lo];
    return $a[$lo] + ($a[$hi] - $a[$lo]) * ($rank - $lo);
}
function fmt(float $ms): string {
    return $ms >= 1000 ? sprintf('%.1fs', $ms / 1000) : sprintf('%dms', (int) round($ms));
}

// Rank by p95 of total, slowest first.
$rows = [];
foreach ($byService as $code => $d) {
    $rows[] = [
        'code'   => $code,
        'n'      => count($d['total']),
        'p50'    => pct($d['total'], 50),
        'p95'    => pct($d['total'], 95),
        'avg'    => array_sum($d['total']) / count($d['total']),
        'max'    => max($d['total']),
        'ttfb95' => pct($d['ttfb'], 95),
    ];
}
usort($rows, fn($a, $b) => $b['p95'] <=> $a['p95']);

echo "Provider latency — {$windowLabel}  ({$withTiming} timed lookups";
echo $noTiming > 0 ? ", {$noTiming} older rows skipped)\n" : ")\n";
echo str_repeat('=', 92) . "\n";
printf("%-28s %5s %9s %9s %9s %9s %10s\n", 'service', 'n', 'p50', 'p95', 'avg', 'max', 'ttfb p95');
echo str_repeat('-', 92) . "\n";
foreach ($rows as $r) {
    $flag = $r['p95'] >= 8000 ? '  <- ASYNC' : ($r['p95'] >= 4000 ? '  <- slow' : '');
    printf(
        "%-28s %5d %9s %9s %9s %9s %10s%s\n",
        substr($r['code'], 0, 28), $r['n'],
        fmt($r['p50']), fmt($r['p95']), fmt($r['avg']), fmt($r['max']), fmt($r['ttfb95']), $flag
    );
}
echo str_repeat('-', 92) . "\n";
echo "Guide: p95 >= 8s  -> strong async candidate (user shouldn't wait synchronously)\n";
echo "       p95 4-8s   -> borderline; async improves UX but sync is tolerable\n";
echo "       ttfb p95 is the upstream provider's own response time - the part we can't optimize in code.\n";
