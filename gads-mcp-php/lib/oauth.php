<?php
/**
 * File: /gads-mcp-php/lib/oauth.php
 * OptiMCP Google Ads MCP PHP — OAuth 2.0 Token Manager
 *
 * Version: 1.0.0
 * Changelog:
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * File-based token cache instead of in-memory (no persistent process on shared hosting).
 * Checks cache on every request. Refreshes only if expired or within 5-min buffer.
 * Cache file is JSON: { access_token, expires_at (unix timestamp) }
 */

if (!defined('ABSPATH')) exit;

/**
 * Get a valid access token.
 * Reads from file cache first; refreshes via Google OAuth if expired.
 *
 * @throws RuntimeException on failure
 * @return string Valid access token
 */
function gads_get_access_token(): string {
    $cachePath = TOKEN_CACHE_PATH;
    $now       = time();
    $buffer    = 300; // 5-minute safety buffer

    // ── Read cache ────────────────────────────────────────────────────────────
    if (file_exists($cachePath)) {
        $raw = @file_get_contents($cachePath);
        if ($raw !== false) {
            $cache = json_decode($raw, true);
            if (
                is_array($cache)
                && !empty($cache['access_token'])
                && isset($cache['expires_at'])
                && ($cache['expires_at'] - $now) > $buffer
            ) {
                return $cache['access_token'];
            }
        }
    }

    // ── Refresh token ─────────────────────────────────────────────────────────
    mcp_log('info', 'OAuth: refreshing access token');
    $token = gads_refresh_access_token();

    // ── Write cache ───────────────────────────────────────────────────────────
    $cacheDir = dirname($cachePath);
    if (!is_dir($cacheDir)) {
        @mkdir($cacheDir, 0750, true);
    }

    $cacheData = json_encode([
        'access_token' => $token['access_token'],
        'expires_at'   => $now + (int)($token['expires_in'] ?? 3600),
        'refreshed_at' => date('Y-m-d H:i:s'),
    ]);

    @file_put_contents($cachePath, $cacheData, LOCK_EX);

    mcp_log('info', 'OAuth: token refreshed', ['expires_in' => $token['expires_in'] ?? 3600]);

    return $token['access_token'];
}

/**
 * Exchange refresh token for a new access token via Google's token endpoint.
 *
 * @throws RuntimeException
 */
function gads_refresh_access_token(): array {
    $postFields = http_build_query([
        'client_id'     => GOOGLE_CLIENT_ID,
        'client_secret' => GOOGLE_CLIENT_SECRET,
        'refresh_token' => GOOGLE_REFRESH_TOKEN,
        'grant_type'    => 'refresh_token',
    ]);

    $ch = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => $postFields,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_CONNECTTIMEOUT => CURL_CONNECT_TIMEOUT,
        CURLOPT_TIMEOUT        => CURL_TIMEOUT,
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_SSL_VERIFYHOST => 2,
    ]);

    $body = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    if ($body === false || !empty($err)) {
        throw new RuntimeException("Token refresh cURL error: {$err}");
    }

    $json = json_decode($body, true);

    if (isset($json['error'])) {
        throw new RuntimeException("Token refresh failed: {$json['error']} — {$json['error_description']}");
    }

    if (empty($json['access_token'])) {
        throw new RuntimeException('Token refresh returned no access_token');
    }

    return $json;
}

/**
 * Read token cache status for the dashboard.
 */
function gads_token_status(): array {
    $cachePath = TOKEN_CACHE_PATH;
    $now       = time();

    if (!file_exists($cachePath)) {
        return ['valid' => false, 'expires_in_seconds' => null, 'refreshed_at' => null];
    }

    $raw   = @file_get_contents($cachePath);
    $cache = $raw ? json_decode($raw, true) : null;

    if (!is_array($cache) || empty($cache['access_token'])) {
        return ['valid' => false, 'expires_in_seconds' => null, 'refreshed_at' => null];
    }

    $expiresIn = ($cache['expires_at'] ?? 0) - $now;

    return [
        'valid'             => $expiresIn > 0,
        'expires_in_seconds'=> max(0, $expiresIn),
        'refreshed_at'      => $cache['refreshed_at'] ?? null,
    ];
}
