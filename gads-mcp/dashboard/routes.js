// File: /gads-mcp/dashboard/routes.js
// OptiMCP Google Ads MCP — Dashboard API Routes
//
// Version: 1.2.0
// Changelog:
//   2026-04-01 | v1.2.0 | SECURITY FIX: dashAuth — replaced broken padEnd timingSafeEqual
//              |         | with correct same-length-only comparison + minimum PIN length guard.
//              |         | token-peek now returns redacted token (never exposes full secret).
//              |         | rotate-token now uses atomic tmp→rename write to prevent .env
//              |         | corruption on crash mid-write.
//   2026-03-26 | v1.0.1 | Permission controls added
//   2026-03-26 | v1.0.0 | Initial release
//
// Mounted at /dashboard by server.js
// All routes require X-Dashboard-Pin header matching DASHBOARD_PIN in .env
//
// Routes:
//   POST /dashboard/api/auth           Verify PIN
//   POST /dashboard/api/config         Server config (version, MCC ID)
//   POST /dashboard/api/oauth-status   Token validity + expiry countdown
//   POST /dashboard/api/test-connection Force token refresh + live API ping
//   POST /dashboard/api/accounts       List MCC child accounts
//   POST /dashboard/api/log            Last 100 tool call log entries
//   POST /dashboard/api/stats          24h call counts + uptime
//   POST /dashboard/api/token-peek     Reveal redacted MCP secret token
//   POST /dashboard/api/rotate-token   Write new token to .env + restart
//   GET  /dashboard                    Serve dashboard HTML

'use strict';

const express = require('express');
const path    = require('path');
const fs      = require('fs');
const crypto  = require('crypto');
const { logger }       = require('../lib/logger');
const { getAccessToken, getStatus: getTokenStatus } = require('../lib/tokenManager');
const { searchQuery }  = require('../lib/gadsClient');
const { WRITE_TOOLS, loadPermissions: readPerms, savePermissions: writePerms } = require('../lib/permissions');

const router = express.Router();

// ── Permission groups (kept here for manifest + server.js checkToolPermission) ─
const TOOL_PERMISSIONS = {
    create_campaign      : 'campaign_write',
    update_campaign      : 'campaign_write',
    pause_campaign       : 'campaign_write',
    enable_campaign      : 'campaign_write',
    remove_campaign      : 'campaign_write',
    set_campaign_budget  : 'campaign_write',
    create_ad_group      : 'adgroup_write',
    update_ad_group      : 'adgroup_write',
    pause_ad_group       : 'adgroup_write',
    enable_ad_group      : 'adgroup_write',
    remove_ad_group      : 'adgroup_write',
    create_rsa           : 'ad_write',
    update_ad_status     : 'ad_write',
    add_keywords         : 'keyword_write',
    update_keyword       : 'keyword_write',
    remove_keyword       : 'keyword_write',
    add_negative_keywords: 'keyword_write',
};

const DEFAULT_PERMISSIONS = {
    campaign_write: true,
    adgroup_write : true,
    ad_write      : true,
    keyword_write : true,
};

function permissionsPath() {
    const logDir = path.dirname(process.env.MCP_LOG_PATH || path.join(__dirname, '../logs/gads-mcp.log'));
    return path.join(logDir, 'permissions.json');
}

function loadPermissions() {
    try {
        const raw = fs.readFileSync(permissionsPath(), 'utf8');
        return { ...DEFAULT_PERMISSIONS, ...JSON.parse(raw) };
    } catch {
        return { ...DEFAULT_PERMISSIONS };
    }
}

function savePermissions(perms) {
    try {
        const p   = permissionsPath();
        const dir = path.dirname(p);
        if (!fs.existsSync(dir)) fs.mkdirSync(dir, { recursive: true });
        const tmp = p + '.tmp.' + Date.now();
        fs.writeFileSync(tmp, JSON.stringify({ ...perms, updated_at: new Date().toISOString() }, null, 2));
        fs.renameSync(tmp, p); // atomic
        return true;
    } catch (e) {
        logger.error('Failed to save permissions', { err: e.message });
        return false;
    }
}

// ── Dashboard PIN auth ────────────────────────────────────────────────────────
// FIXED: same-length-only timingSafeEqual, minimum PIN length guard,
//        explicit rejection of empty values before any comparison.

function dashAuth(req, res, next) {
    const pin      = (req.headers['x-dashboard-pin'] || '').trim();
    const expected = (process.env.DASHBOARD_PIN || '').trim();

    // DASHBOARD_PIN must be set and at least 6 chars
    if (!expected || expected.length < 6) {
        return res.status(503).json({ ok: false, error: 'DASHBOARD_PIN not configured in .env' });
    }

    if (!pin) {
        return res.status(401).json({ ok: false, error: 'Unauthorized' });
    }

    // Different lengths = instant reject (no byte comparison — timing-safe)
    if (pin.length !== expected.length) {
        return res.status(401).json({ ok: false, error: 'Unauthorized' });
    }

    try {
        const a = Buffer.from(pin);
        const b = Buffer.from(expected);
        if (!crypto.timingSafeEqual(a, b)) {
            return res.status(401).json({ ok: false, error: 'Unauthorized' });
        }
    } catch {
        return res.status(401).json({ ok: false, error: 'Unauthorized' });
    }

    next();
}

// ── Serve dashboard HTML ──────────────────────────────────────────────────────

router.get('/', (req, res) => {
    res.sendFile(path.join(__dirname, 'index.html'));
});

// ── API: auth check ───────────────────────────────────────────────────────────

router.post('/api/auth', dashAuth, (req, res) => {
    res.json({ ok: true });
});

// ── API: server config ────────────────────────────────────────────────────────

router.post('/api/config', dashAuth, (req, res) => {
    res.json({
        ok  : true,
        data: {
            api_version   : process.env.GOOGLE_ADS_API_VERSION || 'v23.2',
            mcc_id        : process.env.GOOGLE_ADS_MCC_ID || 'not set',
            port          : process.env.PORT || '3848',
            server_version: '1.2.0',
        },
    });
});

// ── API: OAuth status ─────────────────────────────────────────────────────────

router.post('/api/oauth-status', dashAuth, async (req, res) => {
    try {
        await getAccessToken();
        const status = getTokenStatus();
        res.json({
            ok  : true,
            data: {
                valid             : status.valid,
                expires_in_seconds: status.expiresInSeconds,
                last_refresh      : status.lastRefresh,
                mcc_id            : process.env.GOOGLE_ADS_MCC_ID || 'not set',
            },
        });
    } catch (err) {
        res.json({ ok: false, error: err.message });
    }
});

// ── API: test connection ──────────────────────────────────────────────────────

router.post('/api/test-connection', dashAuth, async (req, res) => {
    try {
        await getAccessToken();
        const mccId = String(process.env.GOOGLE_ADS_MCC_ID || '').replace(/-/g, '');
        if (!mccId) return res.json({ ok: false, error: 'GOOGLE_ADS_MCC_ID not set' });

        const rows = await searchQuery(mccId, `
            SELECT customer_client.id, customer_client.descriptive_name
            FROM customer_client
            WHERE customer_client.manager = false
            LIMIT 1
        `, 1);

        res.json({ ok: true, data: { connected: true, sample_account: rows[0]?.customerClient?.descriptiveName || null } });
    } catch (err) {
        res.json({ ok: false, error: err.message });
    }
});

// ── API: accounts ─────────────────────────────────────────────────────────────

router.post('/api/accounts', dashAuth, async (req, res) => {
    try {
        const mccId = String(process.env.GOOGLE_ADS_MCC_ID || '').replace(/-/g, '');
        if (!mccId) return res.json({ ok: false, error: 'GOOGLE_ADS_MCC_ID not set' });

        const rows = await searchQuery(mccId, `
            SELECT
                customer_client.id,
                customer_client.descriptive_name,
                customer_client.currency_code,
                customer_client.time_zone,
                customer_client.status,
                customer_client.manager
            FROM customer_client
            WHERE customer_client.manager = false
            ORDER BY customer_client.descriptive_name
            LIMIT 500
        `, 500);

        const accounts = rows.map(r => ({
            id      : r.customerClient.id,
            name    : r.customerClient.descriptiveName,
            currency: r.customerClient.currencyCode,
            timezone: r.customerClient.timeZone,
            status  : r.customerClient.status,
            manager : r.customerClient.manager,
        }));

        res.json({ ok: true, data: { accounts, count: accounts.length, mcc_id: mccId } });
    } catch (err) {
        res.json({ ok: false, error: err.message });
    }
});

// ── API: log ──────────────────────────────────────────────────────────────────

router.post('/api/log', dashAuth, (req, res) => {
    const logPath = process.env.MCP_LOG_PATH || path.join(__dirname, '../logs/gads-mcp.log');

    try {
        if (!fs.existsSync(logPath)) {
            return res.json({ ok: true, data: { entries: [] } });
        }

        const raw     = fs.readFileSync(logPath, 'utf8');
        const lines   = raw.trim().split('\n').filter(Boolean).reverse().slice(0, 200);
        const entries = [];

        for (const line of lines) {
            const m = line.match(/^\[([^\]]+)\] \[([^\]]+)\] (.+)$/);
            if (!m) continue;
            const [, time, , rest] = m;

            if (!rest.startsWith('Tool called:') && !rest.startsWith('Tool failed')) continue;

            let tool = '—', customerId = '—', ok = true;

            if (rest.startsWith('Tool called:')) {
                const toolMatch = rest.match(/Tool called: (\S+)/);
                if (toolMatch) tool = toolMatch[1];
            } else {
                const toolMatch = rest.match(/"tool":"([^"]+)"/);
                if (toolMatch) tool = toolMatch[1];
                ok = false;
            }

            const cidMatch = rest.match(/"customer_id":"(\d+)"/);
            if (cidMatch) customerId = cidMatch[1];

            entries.push({ time: time.split('T')[1]?.split('.')[0] || time, tool, customer_id: customerId, ok });
            if (entries.length >= 100) break;
        }

        res.json({ ok: true, data: { entries } });
    } catch {
        res.json({ ok: false, error: 'Could not read log file' });
    }
});

// ── API: stats ────────────────────────────────────────────────────────────────

router.post('/api/stats', dashAuth, (req, res) => {
    const logPath   = process.env.MCP_LOG_PATH || path.join(__dirname, '../logs/gads-mcp.log');
    const uptimeSec = Math.floor(process.uptime());
    const h         = Math.floor(uptimeSec / 3600);
    const m         = Math.floor((uptimeSec % 3600) / 60);
    const uptime    = h > 0 ? `${h}h ${m}m` : `${m}m`;

    try {
        if (!fs.existsSync(logPath)) {
            return res.json({ ok: true, data: { total_24h: 0, errors_24h: 0, top_tool: '—', uptime } });
        }

        const raw    = fs.readFileSync(logPath, 'utf8');
        const lines  = raw.trim().split('\n').filter(Boolean);
        const cutoff = Date.now() - 24 * 60 * 60 * 1000;
        const toolCounts = {};
        let total = 0, errors = 0;

        for (const line of lines) {
            const match = line.match(/^\[([^\]]+)\]/);
            if (!match) continue;
            try {
                const ts = new Date(match[1]).getTime();
                if (ts < cutoff) continue;
            } catch { continue; }

            if (line.includes('Tool called:')) {
                total++;
                const tm = line.match(/Tool called: (\S+)/);
                if (tm) toolCounts[tm[1]] = (toolCounts[tm[1]] || 0) + 1;
            } else if (line.includes('Tool failed')) {
                errors++;
            }
        }

        const topTool = Object.entries(toolCounts).sort((a, b) => b[1] - a[1])[0]?.[0] || '—';
        res.json({ ok: true, data: { total_24h: total, errors_24h: errors, top_tool: topTool, uptime } });
    } catch {
        res.json({ ok: true, data: { total_24h: 0, errors_24h: 0, top_tool: '—', uptime } });
    }
});

// ── API: token peek — FIXED: returns redacted token only, never full secret ──

router.post('/api/token-peek', dashAuth, (req, res) => {
    const token = (process.env.MCP_SECRET_TOKEN || '').trim();
    if (!token) return res.json({ ok: false, error: 'MCP_SECRET_TOKEN not set' });

    // Redact: show first 6 + last 4 chars with ●●●● in the middle
    const redacted = token.length > 12
        ? token.slice(0, 6) + '••••••••' + token.slice(-4)
        : '••••••••••••';

    res.json({ ok: true, data: { token: redacted, length: token.length } });
});

// ── API: rotate token — FIXED: atomic tmp→rename to prevent .env corruption ──

router.post('/api/rotate-token', dashAuth, (req, res) => {
    const { new_token } = req.body;

    if (!new_token || typeof new_token !== 'string' || new_token.trim().length < 32) {
        return res.json({ ok: false, error: 'Token must be at least 32 characters' });
    }

    const safeToken = new_token.trim();
    const envPath   = path.join(__dirname, '../.env');

    try {
        if (!fs.existsSync(envPath)) {
            return res.json({ ok: false, error: '.env file not found at ' + envPath });
        }

        let content = fs.readFileSync(envPath, 'utf8');

        if (content.includes('MCP_SECRET_TOKEN=')) {
            content = content.replace(/^MCP_SECRET_TOKEN=.*/m, `MCP_SECRET_TOKEN=${safeToken}`);
        } else {
            content += `\nMCP_SECRET_TOKEN=${safeToken}`;
        }

        // Atomic write: write to tmp first, then rename — prevents corruption on crash
        const tmp = envPath + '.tmp.' + Date.now();
        fs.writeFileSync(tmp, content, 'utf8');
        fs.renameSync(tmp, envPath);

        logger.info('Dashboard: MCP secret token rotated via dashboard');

        // PM2 will auto-restart after process.exit(0)
        setTimeout(() => {
            logger.info('Dashboard: restarting process for token rotation');
            process.exit(0);
        }, 1500);

        res.json({ ok: true, data: { rotated: true } });

    } catch (err) {
        logger.error('Dashboard: token rotation failed', { err: err.message });
        res.json({ ok: false, error: 'Failed to write .env' });
    }
});

// ── API: permissions ──────────────────────────────────────────────────────────

router.post('/api/permissions', dashAuth, (req, res) => {
    const perms = readPerms();
    res.json({ ok: true, data: perms, tools: WRITE_TOOLS });
});

router.post('/api/permissions/save', dashAuth, (req, res) => {
    const updates = req.body || {};
    const perms   = readPerms();
    Object.keys(WRITE_TOOLS).forEach(toolName => {
        if (typeof updates[toolName] === 'boolean') {
            perms[toolName] = updates[toolName];
        }
    });
    const ok = writePerms(perms);
    logger.info('Permissions updated via dashboard', { perms });
    res.json({ ok, data: perms });
});

// ── API: campaigns (Prompt Helper) ────────────────────────────────────────────

router.post('/api/campaigns', dashAuth, async (req, res) => {
    const customer_id = String(req.body?.customer_id || '').replace(/[^0-9]/g, '');
    if (!customer_id) return res.json({ ok: false, error: 'customer_id required' });

    try {
        const query = `
            SELECT campaign.id, campaign.name, campaign.status,
                   campaign.bidding_strategy_type, campaign_budget.amount_micros
            FROM campaign
            WHERE campaign.status != 'REMOVED'
            ORDER BY campaign.name LIMIT 200`;
        const rows = await searchQuery(customer_id, query, 200);
        const campaigns = rows.map(r => ({
            id          : r.campaign.id,
            name        : r.campaign.name,
            status      : r.campaign.status,
            bidding     : r.campaign.biddingStrategyType,
            daily_budget: r.campaignBudget?.amountMicros
                ? (parseInt(r.campaignBudget.amountMicros, 10) / 1_000_000).toFixed(2)
                : null,
        }));
        res.json({ ok: true, data: campaigns });
    } catch (err) {
        res.json({ ok: false, error: err.message });
    }
});

// ── API: ad groups (Prompt Helper) ───────────────────────────────────────────

router.post('/api/adgroups', dashAuth, async (req, res) => {
    const customer_id  = String(req.body?.customer_id  || '').replace(/[^0-9]/g, '');
    const campaign_id  = String(req.body?.campaign_id  || '').replace(/[^0-9]/g, '');
    if (!customer_id || !campaign_id) return res.json({ ok: false, error: 'customer_id and campaign_id required' });

    try {
        const query = `
            SELECT ad_group.id, ad_group.name, ad_group.status
            FROM ad_group
            WHERE campaign.id = ${campaign_id}
              AND ad_group.status != 'REMOVED'
            ORDER BY ad_group.name LIMIT 200`;
        const rows = await searchQuery(customer_id, query, 200);
        const adGroups = rows.map(r => ({
            id    : r.adGroup.id,
            name  : r.adGroup.name,
            status: r.adGroup.status,
        }));
        res.json({ ok: true, data: adGroups });
    } catch (err) {
        res.json({ ok: false, error: err.message });
    }
});

// ── Expose helpers for server.js ──────────────────────────────────────────────

router.checkToolPermission = function(tool) {
    if (!TOOL_PERMISSIONS[tool]) return { allowed: true };
    const group = TOOL_PERMISSIONS[tool];
    const perms = loadPermissions();
    if (!perms[group]) {
        const labels = {
            campaign_write: 'Campaign write operations',
            adgroup_write : 'Ad group write operations',
            ad_write      : 'Ad write operations',
            keyword_write : 'Keyword write operations',
        };
        return {
            allowed: false,
            group,
            message: `${labels[group] || group} are currently disabled in the dashboard. Enable them at /dashboard/ before running write operations.`,
        };
    }
    return { allowed: true, group };
};

router.loadPermissions  = loadPermissions;
router.TOOL_PERMISSIONS = TOOL_PERMISSIONS;

module.exports = router;
