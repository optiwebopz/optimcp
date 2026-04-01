<?php
/**
 * File: /gads-mcp-php/lib/auth.php
 * OptiMCP Google Ads MCP PHP — Auth + Rate Limiting
 *
 * Version: 1.2.0
 * Changelog:
 *   2026-04-01 | v1.2.0 | SECURITY/STABILITY FIX: Rate limiter now prunes stale
 *              |         | files (1-in-20 chance per request) to prevent unlimited
 *              |         | file accumulation on disk. Prevents inode/disk exhaustion
 *              |         | on shared hosting when attacker rotates through many IPs.
 *   2026-03-26 | v1.1.0 | Added getallheaders() for LiteSpeed/Hostinger compatibility
 *              |         | Checks Authorization: Bearer AND X-MCP-Token headers
 *              |         | Logs token length on invalid token for diagnostics
 *   2026-03-26 | v1.0.0 | Initial release
 */
if (!defined('ABSPATH')) exit;

function mcp_authenticate(): bool {
    $token = '';

    // getallheaders() works on LiteSpeed where $_SERVER strips custom headers
    if (function_exists('getallheaders')) {
        foreach (getallheaders() as $name => $value) {
            $lower = strtolower($name);
            if ($lower === 'x-mcp-token') {
                $token = trim($value);
                break;
            }
            if ($lower === 'authorization') {
                $token = trim(preg_replace('/^Bearer\s+/i', '', $value));
                break;
            }
        }
    }

    // Fallback: $_SERVER (works on Apache/nginx, not always on LiteSpeed)
    if (empty($token)) {
        $token = $_SERVER['HTTP_X_MCP_TOKEN']
            ?? $_SERVER['HTTP_AUTHORIZATION']
            ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
            ?? '';
        $token = trim(preg_replace('/^Bearer\s+/i', '', $token));
    }

    if (empty($token)) {
        mcp_log('warn', 'Auth failed: no token provided', ['ip' => mcp_client_ip()]);
        return false;
    }

    if (!hash_equals(MCP_SECRET_TOKEN, $token)) {
        mcp_log('warn', 'Auth failed: invalid token', ['ip' => mcp_client_ip(), 'len' => strlen($token)]);
        return false;
    }

    if (!mcp_check_rate()) {
        mcp_log('warn', 'Rate limit exceeded', ['ip' => mcp_client_ip()]);
        mcp_error(429, 'Too many requests');
    }

    return true;
}

function mcp_check_rate(): bool {
    $ip   = preg_replace('/[^a-f0-9:.]/', '', mcp_client_ip());
    $dir  = rtrim(MCP_RATE_STORE_PATH, '/') . '/';
    $file = $dir . md5($ip) . '.json';
    $now  = time();

    if (!is_dir($dir)) @mkdir($dir, 0750, true);

    // FIXED: Prune stale rate files (run ~1-in-20 requests) to prevent
    // unlimited file accumulation when attackers rotate through many IPs.
    if (rand(1, 20) === 1) {
        $cutoff = $now - MCP_RATE_WINDOW_SECS;
        foreach (glob($dir . '*.json') ?: [] as $f) {
            if (@filemtime($f) < $cutoff) {
                @unlink($f);
            }
        }
    }

    $data = ['count' => 0, 'start' => $now];
    if (file_exists($file)) {
        $raw = @file_get_contents($file);
        if ($raw) {
            $s = json_decode($raw, true);
            if (is_array($s) && ($now - $s['start']) < MCP_RATE_WINDOW_SECS) {
                $data = $s;
            }
        }
    }
    $data['count']++;
    @file_put_contents($file, json_encode($data), LOCK_EX);
    return $data['count'] <= MCP_RATE_LIMIT;
}

function mcp_client_ip(): string {
    foreach (['HTTP_CF_CONNECTING_IP', 'HTTP_X_FORWARDED_FOR', 'REMOTE_ADDR'] as $k) {
        if (!empty($_SERVER[$k])) return explode(',', $_SERVER[$k])[0];
    }
    return '0.0.0.0';
}
