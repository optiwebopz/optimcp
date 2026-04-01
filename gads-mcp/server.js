/**
 * File: /gads-mcp/server.js
 * OptiMCP — Google Ads MCP Server
 *
 * Version: 1.1.0
 * Changelog:
 *   2026-04-01 | v1.1.0 | Permission controls — per-tool enable/disable from dashboard
 *              |         | Blocked tools return 403 with clear message to Claude
 *   2026-03-26 | v1.0.3 | GET /mcp manifest made public (Claude Code health check compat)
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * File structure:
 *   server.js                  ← this file
 *   .env                       ← credentials (copy from .env.example)
 *   auth/oauth-setup.js        ← run once: npm run auth
 *   lib/tokenManager.js        ← OAuth token auto-refresh
 *   lib/gadsClient.js          ← Google Ads API HTTP client
 *   lib/auth.js                ← MCP token auth middleware
 *   lib/logger.js              ← file logger
 *   tools/accountTools.js      ← list_accounts, get_account, run_gaql
 *   tools/reportingTools.js    ← performance reports
 *   tools/campaignTools.js     ← create/update/pause/remove campaigns
 *   tools/adGroupTools.js      ← create/update/pause/remove ad groups
 *   tools/adTools.js           ← create RSA, update ad status
 *   tools/keywordTools.js      ← add/update/remove keywords + negatives
 *
 * Port: 3848 (set PORT in .env to override)
 * Auth: X-MCP-Token header (set MCP_SECRET_TOKEN in .env)
 */

'use strict';

require('dotenv').config();

const express     = require('express');
const path        = require('path');
const rateLimit   = require('express-rate-limit');
const { auth }    = require('./lib/auth');
const { logger }  = require('./lib/logger');
const { isAllowed, getBlockedMessage } = require('./lib/permissions');

// ── Tool imports ──────────────────────────────────────────────────────────────
const acct    = require('./tools/accountTools');
const report  = require('./tools/reportingTools');
const camp    = require('./tools/campaignTools');
const adGrp   = require('./tools/adGroupTools');
const ads     = require('./tools/adTools');
const kw      = require('./tools/keywordTools');
const dashboard = require('./dashboard/routes');

const app  = express();
const PORT = parseInt(process.env.PORT || '3848', 10);

// ── Middleware ────────────────────────────────────────────────────────────────
app.use(express.json({ limit: '512kb' }));

app.use((req, res, next) => {
    res.setHeader('X-Content-Type-Options', 'nosniff');
    res.setHeader('X-Frame-Options', 'DENY');
    res.setHeader('Referrer-Policy', 'no-referrer');
    next();
});

app.use(rateLimit({
    windowMs: parseInt(process.env.MCP_RATE_WINDOW_MS || '60000', 10),
    max     : parseInt(process.env.MCP_RATE_LIMIT     || '60',    10),
    handler : (req, res) => {
        logger.warn('Rate limit exceeded', { ip: req.ip });
        res.status(429).json({ ok: false, error: 'Too many requests' });
    },
}));

// ── Tool manifest ─────────────────────────────────────────────────────────────
const MANIFEST = {
    name   : 'OptiMCP-GoogleAds',
    version: '1.0.3',
    tools  : [
        // Account
        { name: 'list_accounts',         description: 'List all client accounts under the MCC' },
        { name: 'get_account',           description: 'Get details for a specific customer account' },
        { name: 'run_gaql',              description: 'Run a raw GAQL query against any account' },
        // Reporting
        { name: 'get_account_summary',   description: 'Account-level performance totals for a date range' },
        { name: 'get_campaign_report',   description: 'Campaign performance: clicks, impressions, cost, conversions' },
        { name: 'get_ad_group_report',   description: 'Ad group performance metrics' },
        { name: 'get_keyword_report',    description: 'Keyword-level performance and quality scores' },
        { name: 'get_ad_report',         description: 'Ad-level performance with headline/description preview' },
        { name: 'get_search_terms',      description: 'Search terms report — what people actually searched' },
        // Campaigns
        { name: 'list_campaigns',        description: 'List campaigns with status and budget' },
        { name: 'get_campaign',          description: 'Get full details of one campaign' },
        { name: 'create_campaign',       description: 'Create a new Search campaign with budget and bidding' },
        { name: 'update_campaign',       description: 'Update campaign name or status' },
        { name: 'pause_campaign',        description: 'Pause a campaign' },
        { name: 'enable_campaign',       description: 'Enable a paused campaign' },
        { name: 'remove_campaign',       description: 'Permanently remove a campaign' },
        { name: 'set_campaign_budget',   description: 'Update daily budget for a campaign' },
        // Ad Groups
        { name: 'list_ad_groups',        description: 'List ad groups (optionally filtered by campaign)' },
        { name: 'create_ad_group',       description: 'Create a new ad group in a campaign' },
        { name: 'update_ad_group',       description: 'Update ad group name, status, or CPC bid' },
        { name: 'pause_ad_group',        description: 'Pause an ad group' },
        { name: 'enable_ad_group',       description: 'Enable a paused ad group' },
        { name: 'remove_ad_group',       description: 'Remove an ad group' },
        // Ads
        { name: 'list_ads',              description: 'List ads with headlines, descriptions, and status' },
        { name: 'create_rsa',            description: 'Create a Responsive Search Ad (3–15 headlines, 2–4 descriptions)' },
        { name: 'update_ad_status',      description: 'Pause, enable, or remove an ad' },
        // Keywords
        { name: 'add_keywords',          description: 'Add keywords (BROAD/PHRASE/EXACT) to an ad group' },
        { name: 'update_keyword',        description: 'Update keyword status or CPC bid' },
        { name: 'remove_keyword',        description: 'Remove a keyword from an ad group' },
        { name: 'add_negative_keywords', description: 'Add negative keywords at campaign or ad group level' },
    ],
};

// ── Tool dispatcher ───────────────────────────────────────────────────────────
const TOOLS = {
    // Account
    list_accounts         : (i) => acct.listAccounts(i),
    get_account           : (i) => acct.getAccount(i),
    run_gaql              : (i) => acct.runGaql(i),
    // Reporting
    get_account_summary   : (i) => report.getAccountSummary(i),
    get_campaign_report   : (i) => report.getCampaignReport(i),
    get_ad_group_report   : (i) => report.getAdGroupReport(i),
    get_keyword_report    : (i) => report.getKeywordReport(i),
    get_ad_report         : (i) => report.getAdReport(i),
    get_search_terms      : (i) => report.getSearchTerms(i),
    // Campaigns
    list_campaigns        : (i) => camp.listCampaigns(i),
    get_campaign          : (i) => camp.getCampaign(i),
    create_campaign       : (i) => camp.createCampaign(i),
    update_campaign       : (i) => camp.updateCampaign(i),
    pause_campaign        : (i) => camp.pauseCampaign(i),
    enable_campaign       : (i) => camp.enableCampaign(i),
    remove_campaign       : (i) => camp.removeCampaign(i),
    set_campaign_budget   : (i) => camp.setCampaignBudget(i),
    // Ad Groups
    list_ad_groups        : (i) => adGrp.listAdGroups(i),
    create_ad_group       : (i) => adGrp.createAdGroup(i),
    update_ad_group       : (i) => adGrp.updateAdGroup(i),
    pause_ad_group        : (i) => adGrp.pauseAdGroup(i),
    enable_ad_group       : (i) => adGrp.enableAdGroup(i),
    remove_ad_group       : (i) => adGrp.removeAdGroup(i),
    // Ads
    list_ads              : (i) => ads.listAds(i),
    create_rsa            : (i) => ads.createRsa(i),
    update_ad_status      : (i) => ads.updateAdStatus(i),
    // Keywords
    add_keywords          : (i) => kw.addKeywords(i),
    update_keyword        : (i) => kw.updateKeyword(i),
    remove_keyword        : (i) => kw.removeKeyword(i),
    add_negative_keywords : (i) => kw.addNegativeKeywords(i),
};

// ── Routes ────────────────────────────────────────────────────────────────────

// GET /mcp — public manifest (no auth — Claude Code health check compatibility)
app.get('/mcp', (req, res) => {
    const perms    = dashboard.loadPermissions();
    const disabled = Object.entries(dashboard.TOOL_PERMISSIONS)
        .filter(([, group]) => !perms[group])
        .map(([tool]) => tool);
    res.json({ ok: true, data: { ...MANIFEST, permissions: { groups: perms, disabled_tools: disabled } } });
});

app.options('/mcp', (req, res) => {
    res.setHeader('Access-Control-Allow-Origin', '*');
    res.setHeader('Access-Control-Allow-Headers', 'Content-Type, X-MCP-Token');
    res.setHeader('Access-Control-Allow-Methods', 'POST, GET');
    res.sendStatus(200);
});

app.post('/mcp', auth, async (req, res) => {
    const { tool, input = {} } = req.body;

    if (!tool) return res.status(400).json({ ok: false, error: 'Missing tool name' });

    // ── Permission check BEFORE dispatch ────────────────────────────────────
    if (!isAllowed(tool)) {
        logger.warn(`Blocked tool call: ${tool} (disabled in permissions)`);
        return res.status(403).json({ ok: false, error: getBlockedMessage(tool), blocked: true });
    }

    const handler = TOOLS[tool];
    if (!handler) return res.status(404).json({ ok: false, error: `Unknown tool: ${tool}` });

    // ── Permission check ──────────────────────────────────────────────────────
    const permission = dashboard.checkToolPermission(tool);
    if (!permission.allowed) {
        logger.warn(`Tool blocked by permission`, { tool, group: permission.group });
        return res.status(403).json({ ok: false, error: permission.message });
    }

    logger.info(`Tool called: ${tool}`, { keys: Object.keys(input) });

    try {
        const result = await handler(input);
        return res.json({ ok: true, data: result });
    } catch (err) {
        logger.error('Tool failed', { tool, err: err.message });
        return res.status(500).json({ ok: false, error: 'Tool execution failed' });
    }
});

app.get('/health', (req, res) => {
    res.json({ ok: true, service: 'OptiMCP-GoogleAds', version: '1.0.3' });
});

// ── Dashboard ─────────────────────────────────────────────────────────────────
app.use('/dashboard', express.static(path.join(__dirname, 'dashboard')));
app.use('/dashboard', dashboard);

app.use((req, res) => res.status(404).json({ ok: false, error: 'Not found' }));

// ── Start ─────────────────────────────────────────────────────────────────────
app.listen(PORT, '127.0.0.1', () => {
    logger.info(`OptiMCP Google Ads server v1.0.3 started on port ${PORT}`);
    logger.info(`Google Ads API version: ${process.env.GOOGLE_ADS_API_VERSION || 'v23.2'} (latest)`);
    logger.info(`MCC ID: ${process.env.GOOGLE_ADS_MCC_ID}`);
    logger.info(`Dashboard: https://yourdomain.com/dashboard (PIN: ${process.env.DASHBOARD_PIN ? 'set' : 'NOT SET — dashboard disabled'})`);
});

process.on('SIGTERM', () => { logger.info('Shutting down'); process.exit(0); });
process.on('uncaughtException',  (e) => logger.error('Uncaught exception',  { err: e.message }));
process.on('unhandledRejection', (r) => logger.error('Unhandled rejection', { reason: String(r) }));
