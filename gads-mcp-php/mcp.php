<?php
/**
 * File: /gads-mcp-php/mcp.php
 * OptiMCP Google Ads MCP Server — PHP Edition
 * For Hostinger, SiteGround, and any PHP 8.0+ shared hosting
 *
 * Version: 1.1.0
 * Changelog:
 *   2026-03-26 | v1.1.0 | error_reporting(0) prevents PHP warnings corrupting JSON
 *              |         | GET manifest is now public (Claude Code health check compat)
 *              |         | Authorization: Bearer header support (LiteSpeed compat)
 *              |         | Added Authorization header to CORS allow list
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Dependencies:
 *   config.php          — all credentials and settings
 *   lib/auth.php        — MCP token authentication + rate limiting
 *   lib/oauth.php       — Google OAuth 2.0 token management (file cache)
 *   lib/gads.php        — Google Ads REST API v23.2 client (cURL)
 *   lib/response.php    — JSON response helpers + tool manifest
 *   lib/logger.php      — file logger
 *   tools/*.php         — 6 tool files (account, reporting, campaign, adgroup, ad, keyword)
 *
 * @security GET manifest public — POST tool calls require valid auth token
 * @security No credentials ever returned to Claude
 * @security All Google Ads mutations default to PAUSED status
 */

error_reporting(0);
ini_set('display_errors', 0);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/logger.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/oauth.php';
require_once __DIR__ . '/lib/gads.php';
require_once __DIR__ . '/tools/account_tools.php';
require_once __DIR__ . '/tools/reporting_tools.php';
require_once __DIR__ . '/tools/campaign_tools.php';
require_once __DIR__ . '/tools/adgroup_tools.php';
require_once __DIR__ . '/tools/ad_tools.php';
require_once __DIR__ . '/tools/keyword_tools.php';

// ── Security headers ──────────────────────────────────────────────────────────
header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

// ── CORS preflight ────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, X-MCP-Token, Authorization');
    header('Access-Control-Allow-Methods: POST, GET');
    http_response_code(200);
    exit;
}

// ── GET — public manifest (Claude Code health check, no auth required) ────────
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mcp_success(mcp_tool_manifest());
    exit;
}

// ── Method guard ──────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mcp_error(405, 'Method not allowed');
}

// ── Auth (POST only) ──────────────────────────────────────────────────────────
if (!mcp_authenticate()) {
    mcp_error(401, 'Unauthorized');
}

// ── Parse POST body ───────────────────────────────────────────────────────────
$raw  = file_get_contents('php://input');
$body = json_decode($raw, true) ?? [];
$tool = trim($body['tool'] ?? '');
$input= $body['input'] ?? [];

if (empty($tool)) mcp_error(400, 'Missing tool name');

mcp_log('info', "Tool called: {$tool}", ['input_keys' => array_keys($input)]);

// ── Tool dispatcher ───────────────────────────────────────────────────────────
$TOOLS = [
    'list_accounts'         => 'gads_list_accounts',
    'get_account'           => 'gads_get_account',
    'run_gaql'              => 'gads_run_gaql',
    'get_account_summary'   => 'gads_get_account_summary',
    'get_campaign_report'   => 'gads_get_campaign_report',
    'get_ad_group_report'   => 'gads_get_ad_group_report',
    'get_keyword_report'    => 'gads_get_keyword_report',
    'get_ad_report'         => 'gads_get_ad_report',
    'get_search_terms'      => 'gads_get_search_terms',
    'list_campaigns'        => 'gads_list_campaigns',
    'get_campaign'          => 'gads_get_campaign',
    'create_campaign'       => 'gads_create_campaign',
    'update_campaign'       => 'gads_update_campaign',
    'pause_campaign'        => 'gads_pause_campaign',
    'enable_campaign'       => 'gads_enable_campaign',
    'remove_campaign'       => 'gads_remove_campaign',
    'set_campaign_budget'   => 'gads_set_campaign_budget',
    'list_ad_groups'        => 'gads_list_ad_groups',
    'create_ad_group'       => 'gads_create_ad_group',
    'update_ad_group'       => 'gads_update_ad_group',
    'pause_ad_group'        => 'gads_pause_ad_group',
    'enable_ad_group'       => 'gads_enable_ad_group',
    'remove_ad_group'       => 'gads_remove_ad_group',
    'list_ads'              => 'gads_list_ads',
    'create_rsa'            => 'gads_create_rsa',
    'update_ad_status'      => 'gads_update_ad_status',
    'add_keywords'          => 'gads_add_keywords',
    'update_keyword'        => 'gads_update_keyword',
    'remove_keyword'        => 'gads_remove_keyword',
    'add_negative_keywords' => 'gads_add_negative_keywords',
];

try {
    if (!isset($TOOLS[$tool])) {
        throw new InvalidArgumentException("Unknown tool: {$tool}");
    }
    $fn     = $TOOLS[$tool];
    $result = $fn($input);
    mcp_success($result);
} catch (InvalidArgumentException) {
    mcp_error(404, 'Unknown tool');
} catch (Throwable $e) {
    mcp_log('error', 'Tool execution failed', ['tool' => $tool, 'err' => $e->getMessage()]);
    mcp_error(500, 'Tool execution failed');
}
