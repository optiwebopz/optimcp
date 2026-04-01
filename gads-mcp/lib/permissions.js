/**
 * File: /gads-mcp/lib/permissions.js
 * OptiMCP Google Ads MCP Node.js — Tool Permission Manager
 *
 * Version: 1.0.0
 * Changelog:
 *   2026-04-01 | v1.0.0 | Initial release — per-tool enable/disable controls
 */

'use strict';

const fs   = require('fs');
const path = require('path');

const PERMS_PATH = path.join(__dirname, '../logs/.permissions.json');

// Read-only tools — always enabled, cannot be disabled
const READONLY_TOOLS = new Set([
    'list_accounts','get_account','run_gaql',
    'get_account_summary','get_campaign_report','get_ad_group_report',
    'get_keyword_report','get_ad_report','get_search_terms',
    'list_campaigns','get_campaign',
    'list_ad_groups',
    'list_ads',
]);

// Write tools — toggleable per tool
const WRITE_TOOLS = {
    create_campaign      : { label: 'Create campaign',       group: 'Campaigns', danger: false },
    update_campaign      : { label: 'Update campaign',       group: 'Campaigns', danger: false },
    pause_campaign       : { label: 'Pause campaign',        group: 'Campaigns', danger: false },
    enable_campaign      : { label: 'Enable campaign',       group: 'Campaigns', danger: false },
    set_campaign_budget  : { label: 'Change budget',         group: 'Campaigns', danger: false },
    remove_campaign      : { label: 'Remove campaign',       group: 'Campaigns', danger: true  },
    create_ad_group      : { label: 'Create ad group',       group: 'Ad Groups', danger: false },
    update_ad_group      : { label: 'Update ad group',       group: 'Ad Groups', danger: false },
    pause_ad_group       : { label: 'Pause ad group',        group: 'Ad Groups', danger: false },
    enable_ad_group      : { label: 'Enable ad group',       group: 'Ad Groups', danger: false },
    remove_ad_group      : { label: 'Remove ad group',       group: 'Ad Groups', danger: true  },
    create_rsa           : { label: 'Create RSA ad',         group: 'Ads',       danger: false },
    update_ad_status     : { label: 'Update ad status',      group: 'Ads',       danger: false },
    add_keywords         : { label: 'Add keywords',          group: 'Keywords',  danger: false },
    update_keyword       : { label: 'Update keyword',        group: 'Keywords',  danger: false },
    remove_keyword       : { label: 'Remove keyword',        group: 'Keywords',  danger: true  },
    add_negative_keywords: { label: 'Add negative keywords', group: 'Keywords',  danger: false },
};

function getDefaults() {
    const d = {};
    Object.keys(WRITE_TOOLS).forEach(t => { d[t] = true; });
    return d;
}

function loadPermissions() {
    try {
        if (!fs.existsSync(PERMS_PATH)) return getDefaults();
        const raw  = fs.readFileSync(PERMS_PATH, 'utf8');
        const data = JSON.parse(raw);
        return { ...getDefaults(), ...data };
    } catch {
        return getDefaults();
    }
}

function savePermissions(perms) {
    try {
        const dir = path.dirname(PERMS_PATH);
        if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
        fs.writeFileSync(PERMS_PATH, JSON.stringify(perms, null, 2), 'utf8');
        return true;
    } catch {
        return false;
    }
}

function isAllowed(toolName) {
    if (READONLY_TOOLS.has(toolName)) return true;
    if (!WRITE_TOOLS[toolName]) return true; // unknown — let dispatcher handle
    const perms = loadPermissions();
    return perms[toolName] !== false;
}

function getBlockedMessage(toolName) {
    const info  = WRITE_TOOLS[toolName] || {};
    const label = info.label || toolName;
    return `Tool '${toolName}' (${label}) is currently disabled. Enable it in the dashboard under Permission Controls before asking Claude to use it.`;
}

module.exports = { READONLY_TOOLS, WRITE_TOOLS, loadPermissions, savePermissions, isAllowed, getBlockedMessage };
