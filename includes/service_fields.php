<?php
declare(strict_types=1);

/**
 * Curates a provider's raw "details" blob down to the per-service template in
 * data/service_result_fields.php: only the listed fields, in that order, with
 * the clean label, de-duplicated across the provider's alias keys.
 *
 * A service with no template returns its details unchanged, so free/local
 * lookups and the not-yet-templated list-heavy reports are unaffected.
 */

if (!function_exists('service_result_template')) {
function service_result_template(string $code): ?array
{
    static $map = null;
    if ($map === null) {
        $map = require __DIR__ . '/../data/service_result_fields.php';
    }
    return $map[$code] ?? null;
}
}

if (!function_exists('service_result_has_template')) {
function service_result_has_template(string $code): bool
{
    return service_result_template($code) !== null;
}
}

if (!function_exists('service_filter_details')) {
function service_filter_details(string $code, array $details): array
{
    $tpl = service_result_template($code);
    if ($tpl === null) {
        return $details; // no template -> show the raw response unchanged
    }

    // lower(trim(key)) => original key, so matching ignores case / padding.
    $norm = [];
    foreach ($details as $k => $v) {
        $norm[strtolower(trim((string) $k))] = $k;
    }

    $out  = [];
    $used = [];
    foreach ($tpl as $field) {
        $candidates = is_array($field) ? $field : [$field];
        $label = (string) ($candidates[0] ?? '');
        if ($label === '' || array_key_exists($label, $out)) {
            continue;
        }
        foreach ($candidates as $cand) {
            $nk = strtolower(trim((string) $cand));
            if ($nk === '' || !isset($norm[$nk]) || isset($used[$nk])) {
                continue;
            }
            $val = $details[$norm[$nk]];
            if (is_array($val)) {
                if ($val !== []) {           // repeating section (cases / warranty list)
                    $out[$label] = array_values($val);
                    $used[$nk]   = true;
                    break;
                }
            } elseif (is_scalar($val) && trim((string) $val) !== '') {
                $out[$label] = (string) $val;
                $used[$nk]   = true;
                break;
            }
        }
    }
    return $out;
}
}
