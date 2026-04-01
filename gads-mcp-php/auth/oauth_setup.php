<?php
/**
 * File: /gads-mcp-php/auth/oauth_setup.php
 * OptiMCP Google Ads MCP PHP — One-time OAuth Setup
 *
 * Version: 1.0.0 | 2026-03-26
 *
 * Run this ONCE via browser or CLI to generate your refresh token.
 *
 * Via browser: https://yourdomain.com/gads-mcp/auth/oauth_setup.php
 * Via CLI:     php auth/oauth_setup.php
 *
 * Prerequisites:
 *   1. GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET set in config.php
 *   2. OAuth redirect URI set to this file's URL in Google Cloud Console
 *
 * DELETE THIS FILE AFTER USE — it is a security risk to leave it accessible.
 */

define('ABSPATH', dirname(__DIR__) . '/');
require_once ABSPATH . 'config.php';

$isCli     = php_sapi_name() === 'cli';
$clientId  = GOOGLE_CLIENT_ID;
$clientSec = GOOGLE_CLIENT_SECRET;
$scope     = 'https://www.googleapis.com/auth/adwords';
$redirectUri = (isset($_SERVER['HTTPS']) ? 'https' : 'http') . '://' . ($_SERVER['HTTP_HOST'] ?? 'localhost') . $_SERVER['REQUEST_URI'];

// Strip code/state from redirect URI
$redirectUri = strtok($redirectUri, '?');

// ── Step 2: Exchange code for tokens ──────────────────────────────────────────
if (isset($_GET['code'])) {
    $code = $_GET['code'];
    $ch   = curl_init('https://oauth2.googleapis.com/token');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_POSTFIELDS     => http_build_query([
            'code'          => $code,
            'client_id'     => $clientId,
            'client_secret' => $clientSec,
            'redirect_uri'  => $redirectUri,
            'grant_type'    => 'authorization_code',
        ]),
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $body = curl_exec($ch);
    $code2 = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    curl_close($ch);
    $json = json_decode($body, true);

    if (!empty($json['error'])) {
        echo "<h2 style='color:red'>Error: {$json['error']}</h2><p>{$json['error_description']}</p>";
        exit;
    }

    $refresh = $json['refresh_token'] ?? '';
    echo <<<HTML
<!DOCTYPE html><html><head><title>OAuth Setup — Success</title>
<style>body{font-family:system-ui;max-width:700px;margin:40px auto;padding:0 20px}
code{display:block;background:#f4f4f4;padding:16px;border-radius:6px;word-break:break-all;font-size:13px}
.ok{background:#eaf5ee;color:#1b7a3e;padding:12px 16px;border-radius:6px;margin-bottom:16px}
</style></head><body>
<div class="ok">✓ OAuth authorisation successful!</div>
<h3>Copy this into config.php as GOOGLE_REFRESH_TOKEN:</h3>
<code>{$refresh}</code>
<p style="color:#e55;margin-top:24px"><strong>⚠ Delete this file immediately after copying the token.</strong></p>
<p>Path to delete: <code>{$_SERVER['SCRIPT_FILENAME']}</code></p>
</body></html>
HTML;
    exit;
}

// ── Step 1: Redirect to Google ────────────────────────────────────────────────
if (isset($_GET['start']) || $isCli) {
    $authUrl = 'https://accounts.google.com/o/oauth2/auth?' . http_build_query([
        'client_id'     => $clientId,
        'redirect_uri'  => $redirectUri,
        'response_type' => 'code',
        'scope'         => $scope,
        'access_type'   => 'offline',
        'prompt'        => 'consent',
    ]);

    if ($isCli) {
        echo "\nOptiMCP — Google Ads OAuth Setup\n";
        echo "=================================\n\n";
        echo "Open this URL in your browser:\n\n";
        echo $authUrl . "\n\n";
        echo "Sign in with your Google Ads Manager account,\n";
        echo "grant access, then copy the code from the URL\n";
        echo "and append it to this script's URL as ?code=YOUR_CODE\n\n";
    } else {
        header('Location: ' . $authUrl);
    }
    exit;
}

// ── Default: show start page ──────────────────────────────────────────────────
echo <<<HTML
<!DOCTYPE html><html><head><title>OAuth Setup</title>
<style>body{font-family:system-ui;max-width:600px;margin:80px auto;padding:0 20px;text-align:center}
.btn{display:inline-block;background:#1a56a0;color:#fff;padding:14px 32px;border-radius:8px;text-decoration:none;font-size:15px;font-weight:500}
.warn{background:#fff4e0;color:#92500a;padding:12px 16px;border-radius:6px;margin-top:24px;font-size:13px;text-align:left}
</style></head><body>
<h2>OptiMCP — Google OAuth Setup</h2>
<p>Click below to authorise with your Google Ads Manager account.</p>
<a class="btn" href="?start=1">Authorise with Google</a>
<div class="warn">
    <strong>After completing setup:</strong><br>
    1. Copy the refresh token into config.php as GOOGLE_REFRESH_TOKEN<br>
    2. Delete this file immediately — it should not remain web-accessible
</div>
</body></html>
HTML;
