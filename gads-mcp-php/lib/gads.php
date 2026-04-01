<?php
/**
 * File: /gads-mcp-php/lib/gads.php
 * OptiMCP Google Ads MCP PHP — Google Ads REST API v23.2 Client
 *
 * Version: 1.0.0
 * Changelog:
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Wraps Google Ads REST API calls:
 *   gads_search($customerId, $gaql, $limit)  — GAQL query → array of rows
 *   gads_mutate($customerId, $resource, $ops) — create/update/remove resources
 *   gads_get($path)                           — GET request (e.g. accessible customers)
 *
 * All requests:
 *   - Auto-inject Bearer token (via gads_get_access_token())
 *   - Set login-customer-id to MCC for child account access
 *   - Parse Google Ads API error responses into readable messages
 */

if (!defined('ABSPATH')) exit;

// ── Base URL ──────────────────────────────────────────────────────────────────
function gads_base_url(): string {
    return 'https://googleads.googleapis.com/' . GOOGLE_ADS_API_VERSION;
}

// ── Build common headers ──────────────────────────────────────────────────────
function gads_headers(): array {
    $token = gads_get_access_token();
    $mcc   = preg_replace('/[^0-9]/', '', GOOGLE_ADS_MCC_ID);
    $hdrs  = [
        'Authorization: Bearer ' . $token,
        'developer-token: ' . GOOGLE_ADS_DEV_TOKEN,
        'Content-Type: application/json',
    ];
    if ($mcc) $hdrs[] = 'login-customer-id: ' . $mcc;
    return $hdrs;
}

// ── GAQL search ───────────────────────────────────────────────────────────────
/**
 * Execute a GAQL query and return result rows.
 *
 * @param string $customerId  Customer ID (digits only)
 * @param string $gaql        GAQL query string
 * @param int    $limit       Max rows (hard-capped at GADS_MAX_ROWS)
 * @return array
 * @throws RuntimeException
 */
function gads_search(string $customerId, string $gaql, int $limit = 0): array {
    $cid   = preg_replace('/[^0-9]/', '', $customerId);
    $limit = $limit > 0 ? min($limit, GADS_MAX_ROWS) : GADS_DEFAULT_ROWS;

    // Auto-inject LIMIT if not present
    if (!preg_match('/\bLIMIT\b/i', $gaql)) {
        $gaql = rtrim($gaql, '; ') . " LIMIT {$limit}";
    }

    $url     = gads_base_url() . "/customers/{$cid}/googleAds:search";
    $payload = json_encode(['query' => $gaql, 'pageSize' => $limit]);

    $response = gads_curl_post($url, $payload);
    return $response['results'] ?? [];
}

// ── Mutate ────────────────────────────────────────────────────────────────────
/**
 * Run a mutate operation (create / update / remove).
 *
 * @param string $customerId  Customer ID (digits only)
 * @param string $resource    Resource name e.g. 'campaigns', 'adGroups'
 * @param array  $operations  Array of operation objects
 * @return array
 * @throws RuntimeException
 */
function gads_mutate(string $customerId, string $resource, array $operations): array {
    $cid     = preg_replace('/[^0-9]/', '', $customerId);
    $url     = gads_base_url() . "/customers/{$cid}/{$resource}:mutate";
    $payload = json_encode(['operations' => $operations]);
    return gads_curl_post($url, $payload);
}

// ── GET request ───────────────────────────────────────────────────────────────
function gads_get_request(string $path): array {
    $url = gads_base_url() . '/' . ltrim($path, '/');
    $ch  = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => gads_headers(),
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException("cURL error: {$err}");

    $json = json_decode($body, true);
    if ($code >= 400) throw new RuntimeException(gads_parse_error($json, $code));

    return $json ?? [];
}

// ── cURL POST helper ──────────────────────────────────────────────────────────
function gads_curl_post(string $url, string $payload): array {
    $ch = curl_init($url);

    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $payload,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_HTTPHEADER     => gads_headers(),
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false) throw new RuntimeException("cURL error: {$err}");

    $json = json_decode($body, true);
    if ($code >= 400) throw new RuntimeException(gads_parse_error($json, $code));

    return $json ?? [];
}

// ── Error parser ──────────────────────────────────────────────────────────────
function gads_parse_error(?array $json, int $code): string {
    if (!$json) return "Google Ads API error {$code}";

    $errObj = $json['error'] ?? (is_array($json) ? ($json[0]['error'] ?? null) : null);
    if (!$errObj) return "Google Ads API error {$code}: " . json_encode($json);

    $details = $errObj['details'][0]['errors'][0] ?? null;
    $msg     = $details
        ? (json_encode($details['errorCode'] ?? '') . ' ' . ($details['message'] ?? ''))
        : ($errObj['message'] ?? json_encode($errObj));

    return "Google Ads API {$code}: " . trim($msg);
}

// ── Resource name helpers ─────────────────────────────────────────────────────
function gads_cid(string $id): string {
    return preg_replace('/[^0-9]/', '', $id);
}

function gads_campaign_resource(string $cid, string $campaignId): string {
    return "customers/{$cid}/campaigns/{$campaignId}";
}

function gads_ad_group_resource(string $cid, string $adGroupId): string {
    return "customers/{$cid}/adGroups/{$adGroupId}";
}

function gads_budget_resource(string $cid, string $budgetId): string {
    return "customers/{$cid}/campaignBudgets/{$budgetId}";
}

// ── Micros conversion ─────────────────────────────────────────────────────────
function gads_to_micros(float $amount): int {
    return (int)round($amount * 1_000_000);
}

function gads_from_micros(?string $micros): ?string {
    if ($micros === null || $micros === '') return null;
    return number_format((int)$micros / 1_000_000, 6, '.', '');
}

// ── Metric formatter ──────────────────────────────────────────────────────────
function gads_fmt_metrics(?array $m): array {
    if (!$m) return [];
    $cost = isset($m['costMicros']) ? number_format((int)$m['costMicros'] / 1_000_000, 2, '.', '') : '0.00';
    $cpc  = isset($m['averageCpc']) ? number_format((int)$m['averageCpc'] / 1_000_000, 4, '.', '') : '0.0000';
    $ctr  = isset($m['ctr'])        ? number_format((float)$m['ctr'] * 100, 2, '.', '') . '%' : '0.00%';
    return [
        'impressions'   => (int)($m['impressions']      ?? 0),
        'clicks'        => (int)($m['clicks']           ?? 0),
        'cost'          => (float)$cost,
        'ctr'           => $ctr,
        'avg_cpc'       => (float)$cpc,
        'conversions'   => number_format((float)($m['conversions']      ?? 0), 2, '.', ''),
        'conv_value'    => number_format((float)($m['conversionsValue'] ?? 0), 2, '.', ''),
        'cost_per_conv' => isset($m['costPerConversion'])
            ? number_format((int)$m['costPerConversion'] / 1_000_000, 2, '.', '')
            : null,
    ];
}

// ── Date range builder ────────────────────────────────────────────────────────
function gads_date_clause(mixed $dateRange): string {
    $presets = [
        'TODAY','YESTERDAY','LAST_7_DAYS','LAST_14_DAYS','LAST_30_DAYS',
        'THIS_MONTH','LAST_MONTH','THIS_YEAR','LAST_YEAR','ALL_TIME',
    ];

    if (empty($dateRange) || $dateRange === 'LAST_30_DAYS') {
        return 'segments.date DURING LAST_30_DAYS';
    }

    if (is_string($dateRange) && in_array(strtoupper($dateRange), $presets)) {
        return 'segments.date DURING ' . strtoupper($dateRange);
    }

    if (is_array($dateRange) && !empty($dateRange['start']) && !empty($dateRange['end'])) {
        $s = preg_replace('/[^0-9]/', '', $dateRange['start']);
        $e = preg_replace('/[^0-9]/', '', $dateRange['end']);
        return "segments.date BETWEEN '{$s}' AND '{$e}'";
    }

    return 'segments.date DURING LAST_30_DAYS';
}
