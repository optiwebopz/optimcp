<?php
/**
 * File: /gads-mcp-php/lib/response.php
 * OptiMCP Google Ads MCP PHP — Response Helpers
 * Version: 1.0.0 | 2026-03-26
 */
if (!defined('ABSPATH')) exit;

function mcp_success(mixed $data): void {
    http_response_code(200);
    echo json_encode(['ok' => true, 'data' => $data], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function mcp_error(int $code, string $message): void {
    http_response_code($code);
    echo json_encode(['ok' => false, 'error' => $message], JSON_UNESCAPED_UNICODE);
    exit;
}

function mcp_tool_manifest(): array {
    return [
        'name'    => 'OptiMCP-GoogleAds-PHP',
        'version' => '1.0.0',
        'runtime' => 'PHP ' . PHP_MAJOR_VERSION . '.' . PHP_MINOR_VERSION,
        'api'     => GOOGLE_ADS_API_VERSION,
        'tools'   => [
            ['name'=>'list_accounts',         'description'=>'List all client accounts under MCC'],
            ['name'=>'get_account',           'description'=>'Get account details by customer_id'],
            ['name'=>'run_gaql',              'description'=>'Run any raw GAQL query'],
            ['name'=>'get_account_summary',   'description'=>'Account-level performance totals'],
            ['name'=>'get_campaign_report',   'description'=>'Campaign performance sorted by cost'],
            ['name'=>'get_ad_group_report',   'description'=>'Ad group performance metrics'],
            ['name'=>'get_keyword_report',    'description'=>'Keyword metrics and quality scores'],
            ['name'=>'get_ad_report',         'description'=>'Ad performance with headline preview'],
            ['name'=>'get_search_terms',      'description'=>'Search terms report'],
            ['name'=>'list_campaigns',        'description'=>'List campaigns with status and budget'],
            ['name'=>'get_campaign',          'description'=>'Get full campaign details'],
            ['name'=>'create_campaign',       'description'=>'Create a new Search campaign (defaults PAUSED)'],
            ['name'=>'update_campaign',       'description'=>'Update campaign name or status'],
            ['name'=>'pause_campaign',        'description'=>'Pause a live campaign'],
            ['name'=>'enable_campaign',       'description'=>'Enable a paused campaign'],
            ['name'=>'remove_campaign',       'description'=>'Permanently remove a campaign'],
            ['name'=>'set_campaign_budget',   'description'=>'Update campaign daily budget'],
            ['name'=>'list_ad_groups',        'description'=>'List ad groups'],
            ['name'=>'create_ad_group',       'description'=>'Create a new ad group'],
            ['name'=>'update_ad_group',       'description'=>'Update ad group name, status, or bid'],
            ['name'=>'pause_ad_group',        'description'=>'Pause an ad group'],
            ['name'=>'enable_ad_group',       'description'=>'Enable an ad group'],
            ['name'=>'remove_ad_group',       'description'=>'Remove an ad group'],
            ['name'=>'list_ads',              'description'=>'List ads with headlines and status'],
            ['name'=>'create_rsa',            'description'=>'Create Responsive Search Ad (defaults PAUSED)'],
            ['name'=>'update_ad_status',      'description'=>'Pause, enable, or remove an ad'],
            ['name'=>'add_keywords',          'description'=>'Add BROAD/PHRASE/EXACT keywords to ad group'],
            ['name'=>'update_keyword',        'description'=>'Update keyword status or CPC bid'],
            ['name'=>'remove_keyword',        'description'=>'Remove a keyword'],
            ['name'=>'add_negative_keywords', 'description'=>'Add negatives at campaign or ad group level'],
        ],
    ];
}
