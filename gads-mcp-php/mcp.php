<?php
/**
 * File: /gads-mcp-php/mcp.php
 * OptiMCP Google Ads MCP Server — PHP Edition
 *
 * Version: 1.2.0
 * Changelog:
 *   2026-04-01 | v1.2.0 | Permission controls — write tools can be disabled from dashboard
 *              |         | Blocked tools return 403 with clear message instead of executing
 *   2026-03-26 | v1.1.0 | LiteSpeed auth fix, GET public, error_reporting(0)
 *   2026-03-26 | v1.0.0 | Initial release
 */

error_reporting(0);
ini_set('display_errors', 0);

if (!defined('ABSPATH')) define('ABSPATH', __DIR__ . '/');

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/lib/logger.php';
require_once __DIR__ . '/lib/response.php';
require_once __DIR__ . '/lib/auth.php';
require_once __DIR__ . '/lib/permissions.php';
require_once __DIR__ . '/lib/oauth.php';
require_once __DIR__ . '/lib/gads.php';
require_once __DIR__ . '/tools/account_tools.php';
require_once __DIR__ . '/tools/reporting_tools.php';
require_once __DIR__ . '/tools/campaign_tools.php';
require_once __DIR__ . '/tools/adgroup_tools.php';
require_once __DIR__ . '/tools/ad_tools.php';
require_once __DIR__ . '/tools/keyword_tools.php';

header('Content-Type: application/json; charset=utf-8');
header('X-Content-Type-Options: nosniff');
header('X-Frame-Options: DENY');
header('Referrer-Policy: no-referrer');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    header('Access-Control-Allow-Origin: *');
    header('Access-Control-Allow-Headers: Content-Type, X-MCP-Token, Authorization');
    header('Access-Control-Allow-Methods: POST, GET');
    http_response_code(200);
    exit;
}

// GET — public manifest
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    mcp_success(mcp_tool_manifest());
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    mcp_error(405, 'Method not allowed');
}

if (!mcp_authenticate()) {
    mcp_error(401, 'Unauthorized');
}

$raw   = file_get_contents('php://input');
$body  = json_decode($raw, true) ?? [];
$tool  = trim($body['tool']  ?? '');
$input = $body['input']      ?? [];

if (empty($tool)) mcp_error(400, 'Missing tool name');

// ── Permission check BEFORE dispatch ─────────────────────────────────────────
if (!perm_check($tool)) {
    mcp_log('warn', "Blocked tool call: {$tool} (disabled in permissions)", ['ip' => $_SERVER['REMOTE_ADDR'] ?? '']);
    perm_error_response($tool);
}

mcp_log('info', "Tool called: {$tool}", ['input_keys' => array_keys($input)]);

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
    if (!isset($TOOLS[$tool])) throw new InvalidArgumentException("Unknown tool: {$tool}");
    $fn     = $TOOLS[$tool];
    $result = $fn($input);
    mcp_success($result);
} catch (InvalidArgumentException) {
    mcp_error(404, 'Unknown tool');
} catch (Throwable $e) {
    mcp_log('error', 'Tool execution failed', ['tool' => $tool, 'err' => $e->getMessage()]);
    mcp_error(500, 'Tool execution failed');
}
