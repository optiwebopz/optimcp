<?php
/**
 * File: /gads-mcp-php/lib/permissions.php
 * OptiMCP Google Ads MCP PHP — Tool Permission Manager
 *
 * Version: 1.0.0
 * Changelog:
 *   2026-04-01 | v1.0.0 | Initial release — per-tool enable/disable controls
 *
 * Permissions stored in logs/.permissions.json (not web-accessible)
 * Read tools are always enabled and cannot be disabled.
 * Write/destructive tools can be toggled from the dashboard.
 */

if (!defined('ABSPATH')) exit;

// ── Tool categories ───────────────────────────────────────────────────────────

// These are always ON — read-only, no side effects
const PERM_READONLY_TOOLS = [
    'list_accounts', 'get_account', 'run_gaql',
    'get_account_summary', 'get_campaign_report', 'get_ad_group_report',
    'get_keyword_report', 'get_ad_report', 'get_search_terms',
    'list_campaigns', 'get_campaign',
    'list_ad_groups',
    'list_ads',
];

// These can be toggled — write/mutate operations
const PERM_WRITE_TOOLS = [
    // Campaigns
    'create_campaign'    => ['label' => 'Create campaign',      'group' => 'Campaigns', 'danger' => false],
    'update_campaign'    => ['label' => 'Update campaign',      'group' => 'Campaigns', 'danger' => false],
    'pause_campaign'     => ['label' => 'Pause campaign',       'group' => 'Campaigns', 'danger' => false],
    'enable_campaign'    => ['label' => 'Enable campaign',      'group' => 'Campaigns', 'danger' => false],
    'set_campaign_budget'=> ['label' => 'Change budget',        'group' => 'Campaigns', 'danger' => false],
    'remove_campaign'    => ['label' => 'Remove campaign',      'group' => 'Campaigns', 'danger' => true],
    // Ad groups
    'create_ad_group'    => ['label' => 'Create ad group',      'group' => 'Ad Groups', 'danger' => false],
    'update_ad_group'    => ['label' => 'Update ad group',      'group' => 'Ad Groups', 'danger' => false],
    'pause_ad_group'     => ['label' => 'Pause ad group',       'group' => 'Ad Groups', 'danger' => false],
    'enable_ad_group'    => ['label' => 'Enable ad group',      'group' => 'Ad Groups', 'danger' => false],
    'remove_ad_group'    => ['label' => 'Remove ad group',      'group' => 'Ad Groups', 'danger' => true],
    // Ads
    'create_rsa'         => ['label' => 'Create RSA ad',        'group' => 'Ads',       'danger' => false],
    'update_ad_status'   => ['label' => 'Update ad status',     'group' => 'Ads',       'danger' => false],
    // Keywords
    'add_keywords'       => ['label' => 'Add keywords',         'group' => 'Keywords',  'danger' => false],
    'update_keyword'     => ['label' => 'Update keyword',       'group' => 'Keywords',  'danger' => false],
    'remove_keyword'     => ['label' => 'Remove keyword',       'group' => 'Keywords',  'danger' => true],
    'add_negative_keywords'=> ['label' => 'Add negative keywords','group' => 'Keywords','danger' => false],
];

// ── Load permissions from file ────────────────────────────────────────────────

function perm_load(): array {
    $path = ABSPATH . 'logs/.permissions.json';
    if (!file_exists($path)) {
        return perm_defaults();
    }
    $raw  = @file_get_contents($path);
    $data = $raw ? json_decode($raw, true) : null;
    if (!is_array($data)) return perm_defaults();
    // Merge with defaults to handle new tools added in future versions
    return array_merge(perm_defaults(), $data);
}

function perm_defaults(): array {
    // All write tools default to ENABLED — admin can disable what they don't want
    $defaults = [];
    foreach (array_keys(PERM_WRITE_TOOLS) as $tool) {
        $defaults[$tool] = true;
    }
    return $defaults;
}

function perm_save(array $perms): bool {
    $dir  = ABSPATH . 'logs/';
    $path = $dir . '.permissions.json';
    if (!is_dir($dir)) @mkdir($dir, 0750, true);
    return @file_put_contents($path, json_encode($perms, JSON_PRETTY_PRINT), LOCK_EX) !== false;
}

// ── Check if a tool is allowed ────────────────────────────────────────────────

function perm_check(string $tool): bool {
    // Read-only tools are always allowed
    if (in_array($tool, PERM_READONLY_TOOLS)) return true;
    // Write tools: check permissions file
    if (!isset(PERM_WRITE_TOOLS[$tool])) return true; // unknown tool — let dispatcher handle
    $perms = perm_load();
    return $perms[$tool] ?? true;
}

function perm_error_response(string $tool): void {
    $info  = PERM_WRITE_TOOLS[$tool] ?? ['label' => $tool];
    $label = $info['label'] ?? $tool;
    mcp_error(403, "Tool '{$tool}' ({$label}) is currently disabled. Enable it in the dashboard under Permission Controls before asking Claude to use it.");
}
