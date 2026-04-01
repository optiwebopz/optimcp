/**
 * File: /gads-mcp/dashboard/routes.js
 * OptiMCP Google Ads MCP — Dashboard API Routes
 *
 * Version: 1.0.1
 * Changelog:
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Mounted at /dashboard by server.js
 * All routes require X-Dashboard-Pin header matching DASHBOARD_PIN in .env
 *
 * Routes:
 *   POST /dashboard/api/auth           Verify PIN
 *   POST /dashboard/api/config         Server config (version, MCC ID)
 *   POST /dashboard/api/oauth-status   Token validity + expiry countdown
 *   POST /dashboard/api/test-connection Force token refresh + live API ping
 *   POST /dashboard/api/accounts       List MCC child accounts
 *   POST /dashboard/api/log            Last 100 tool call log entries
 *   POST /dashboard/api/stats          24h call counts + uptime
 *   POST /dashboard/api/token-peek     Reveal current MCP secret token
 *   POST /dashboard/api/rotate-token   Write new token to .env + restart
 *   GET  /dashboard                    Serve dashboard HTML
 */

'use strict';

const express = require('express');
const path    = require('path');
const fs      = require('fs');
const crypto  = require('crypto');
const { logger }       = require('../lib/logger');
const { getAccessToken, getStatus: getTokenStatus } = require('../lib/tokenManager');
const { searchQuery }  = require('../lib/gadsClient');

const router = express.Router();

// ── Dashboard PIN auth ────────────────────────────────────────────────────────

function dashAuth(req, res, next) {
    const pin      = req.headers['x-dashboard-pin'] || '';
    const expected = process.env.DASHBOARD_PIN || '';

    if (!expected) {
        return res.status(503).json({ ok: false, error: 'DASHBOARD_PIN not configured in .env' });
    }

    if (!pin) {
        return res.status(401).json({ ok: false, error: 'Unauthorized' });
    }

    try {
        const a = Buffer.from(pin.padEnd(Math.max(pin.length, expected.length)));
        const b = Buffer.from(expected.padEnd(Math.max(pin.length, expected.length)));
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
            api_version : process.env.GOOGLE_ADS_API_VERSION || 'v23.2',
            mcc_id      : process.env.GOOGLE_ADS_MCC_ID || 'not set',
            port        : process.env.PORT || '3848',
            server_version: '1.0.1',
        }
    });
});

// ── API: OAuth status ─────────────────────────────────────────────────────────

// Access token cache from tokenManager (read via shared module state)
router.post('/api/oauth-status', dashAuth, async (req, res) => {
    try {
        // Trigger a token fetch (uses cache if valid)
        await getAccessToken();

        // Read expiry from tokenManager's module-level state via a status export
        const status = getTokenStatus();

        res.json({
            ok  : true,
            data: {
                valid            : status.valid,
                expires_in_seconds: status.expiresInSeconds,
                last_refresh     : status.lastRefresh,
                mcc_id           : process.env.GOOGLE_ADS_MCC_ID || 'not set',
            }
        });
    } catch (err) {
        res.json({
            ok  : false,
            data: { valid: false },
            error: 'Token unavailable — check refresh token in .env',
        });
    }
});

// ── API: test connection ──────────────────────────────────────────────────────

router.post('/api/test-connection', dashAuth, async (req, res) => {
    try {
        const mccId = String(process.env.GOOGLE_ADS_MCC_ID || '').replace(/-/g, '');
        if (!mccId) throw new Error('GOOGLE_ADS_MCC_ID not set');
        // Run a minimal GAQL query to verify the connection end-to-end
        await searchQuery(mccId, 'SELECT customer.id FROM customer LIMIT 1', 1);
        logger.info('Dashboard: connection test passed');
        res.json({ ok: true, data: { connected: true } });
    } catch (err) {
        logger.warn('Dashboard: connection test failed', { err: err.message });
        res.json({ ok: false, error: err.message });
    }
});

// ── API: accounts ─────────────────────────────────────────────────────────────

router.post('/api/accounts', dashAuth, async (req, res) => {
    try {
        const mccId = String(process.env.GOOGLE_ADS_MCC_ID || '').replace(/-/g, '');
        const query = `
            SELECT
                customer_client.id,
                customer_client.descriptive_name,
                customer_client.currency_code,
                customer_client.time_zone,
                customer_client.status,
                customer_client.manager
            FROM customer_client
            WHERE customer_client.level <= 1
              AND customer_client.status = 'ENABLED'
            ORDER BY customer_client.descriptive_name
            LIMIT 200
        `;
        const rows = await searchQuery(mccId, query, 200);
        const accounts = rows.map(r => ({
            id      : r.customerClient.id,
            name    : r.customerClient.descriptiveName || '(unnamed)',
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
            // Parse log line: [timestamp] [LEVEL] message {context}
            const m = line.match(/^\[([^\]]+)\] \[([^\]]+)\] (.+)$/);
            if (!m) continue;
            const [, time, level, rest] = m;

            // Only show tool call lines
            if (!rest.startsWith('Tool called:') && !rest.startsWith('Tool failed')) continue;

            let tool = '—', customerId = '—', ok = true;

            if (rest.startsWith('Tool called:')) {
                const toolMatch = rest.match(/Tool called: (\S+)/);
                if (toolMatch) tool = toolMatch[1];
                ok = true;
            } else if (rest.startsWith('Tool failed')) {
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
    } catch (err) {
        res.json({ ok: false, error: 'Could not read log file' });
    }
});

// ── API: stats ────────────────────────────────────────────────────────────────

router.post('/api/stats', dashAuth, (req, res) => {
    const logPath = process.env.MCP_LOG_PATH || path.join(__dirname, '../logs/gads-mcp.log');
    const uptimeSec = Math.floor(process.uptime());
    const h = Math.floor(uptimeSec / 3600);
    const m = Math.floor((uptimeSec % 3600) / 60);
    const uptime = h > 0 ? `${h}h ${m}m` : `${m}m`;

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
            const m = line.match(/^\[([^\]]+)\]/);
            if (!m) continue;
            try {
                const ts = new Date(m[1]).getTime();
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

        const topTool = Object.entries(toolCounts).sort((a,b) => b[1]-a[1])[0]?.[0] || '—';
        res.json({ ok: true, data: { total_24h: total, errors_24h: errors, top_tool: topTool, uptime } });
    } catch {
        res.json({ ok: true, data: { total_24h: 0, errors_24h: 0, top_tool: '—', uptime } });
    }
});

// ── API: token peek ───────────────────────────────────────────────────────────

router.post('/api/token-peek', dashAuth, (req, res) => {
    const token = process.env.MCP_SECRET_TOKEN || '';
    if (!token) return res.json({ ok: false, error: 'MCP_SECRET_TOKEN not set' });
    res.json({ ok: true, data: { token } });
});

// ── API: rotate token ─────────────────────────────────────────────────────────

router.post('/api/rotate-token', dashAuth, (req, res) => {
    const { new_token } = req.body;

    if (!new_token || new_token.length < 32) {
        return res.json({ ok: false, error: 'Token must be at least 32 characters' });
    }

    const envPath = path.join(__dirname, '../.env');

    try {
        if (!fs.existsSync(envPath)) {
            return res.json({ ok: false, error: '.env file not found at ' + envPath });
        }

        let content = fs.readFileSync(envPath, 'utf8');

        if (content.includes('MCP_SECRET_TOKEN=')) {
            content = content.replace(/^MCP_SECRET_TOKEN=.*/m, `MCP_SECRET_TOKEN=${new_token}`);
        } else {
            content += `\nMCP_SECRET_TOKEN=${new_token}`;
        }

        fs.writeFileSync(envPath, content, 'utf8');
        logger.info('Dashboard: MCP secret token rotated via dashboard');

        // Schedule a process restart after response is sent
        setTimeout(() => {
            logger.info('Dashboard: restarting process for token rotation');
            process.exit(0); // PM2 will restart automatically
        }, 1500);

        res.json({ ok: true, data: { rotated: true } });

    } catch (err) {
        logger.error('Dashboard: token rotation failed', { err: err.message });
        res.json({ ok: false, error: 'Failed to write .env: ' + err.message });
    }
});

// ── API: campaigns (Prompt Helper) ────────────────────────────────────────────

router.post('/api/campaigns', dashAuth, async (req, res) => {
    const { customer_id } = req.body;
    if (!customer_id) return res.json({ ok: false, error: 'customer_id required' });
    const cid = String(customer_id).replace(/[^0-9]/g, '');
    try {
        const query = `
            SELECT campaign.id, campaign.name, campaign.status,
                   campaign.bidding_strategy_type, campaign_budget.amount_micros
            FROM campaign
            WHERE campaign.status != 'REMOVED'
            ORDER BY campaign.name LIMIT 200`;
        const rows = await searchQuery(cid, query, 200);
        const campaigns = rows.map(r => ({
            id          : r.campaign.id,
            name        : r.campaign.name,
            status      : r.campaign.status,
            bidding     : r.campaign.biddingStrategyType,
            daily_budget: r.campaignBudget?.amountMicros
                ? (parseInt(r.campaignBudget.amountMicros) / 1000000).toFixed(2) : null,
        }));
        res.json({ ok: true, data: campaigns });
    } catch (err) {
        res.json({ ok: false, error: err.message });
    }
});

// ── API: campaign report (Prompt Helper metrics) ───────────────────────────────

router.post('/api/campaign-report', dashAuth, async (req, res) => {
    const { customer_id } = req.body;
    if (!customer_id) return res.json({ ok: false, error: 'customer_id required' });
    const cid = String(customer_id).replace(/[^0-9]/g, '');
    try {
        const query = `
            SELECT campaign.id, campaign.name,
                   metrics.impressions, metrics.clicks, metrics.cost_micros,
                   metrics.ctr, metrics.conversions
            FROM campaign
            WHERE campaign.status != 'REMOVED'
              AND segments.date DURING LAST_30_DAYS
            ORDER BY metrics.cost_micros DESC LIMIT 20`;
        const rows = await searchQuery(cid, query, 20);
        const data = rows.map(r => ({
            id         : r.campaign.id,
            name       : r.campaign.name,
            impressions: r.metrics.impressions || 0,
            clicks     : r.metrics.clicks || 0,
            cost       : r.metrics.costMicros ? (parseInt(r.metrics.costMicros) / 1000000).toFixed(2) : '0.00',
            ctr        : r.metrics.ctr ? (parseFloat(r.metrics.ctr) * 100).toFixed(2) + '%' : '0.00%',
            conversions: r.metrics.conversions ? parseFloat(r.metrics.conversions).toFixed(1) : '0',
        }));
        res.json({ ok: true, data });
    } catch (err) {
        res.json({ ok: false, error: err.message });
    }
});

// ── API: ad groups (Prompt Helper) ────────────────────────────────────────────

router.post('/api/adgroups', dashAuth, async (req, res) => {
    const { customer_id, campaign_id } = req.body;
    if (!customer_id || !campaign_id) return res.json({ ok: false, error: 'customer_id and campaign_id required' });
    const cid   = String(customer_id).replace(/[^0-9]/g, '');
    const campId= String(campaign_id).replace(/[^0-9]/g, '');
    try {
        const query = `
            SELECT ad_group.id, ad_group.name, ad_group.status, ad_group.cpc_bid_micros
            FROM ad_group
            WHERE campaign.id = ${campId} AND ad_group.status != 'REMOVED'
            ORDER BY ad_group.name LIMIT 200`;
        const rows = await searchQuery(cid, query, 200);
        const adGroups = rows.map(r => ({
            id     : r.adGroup.id,
            name   : r.adGroup.name,
            status : r.adGroup.status,
            cpc_bid: r.adGroup.cpcBidMicros
                ? (parseInt(r.adGroup.cpcBidMicros) / 1000000).toFixed(2) : null,
        }));
        res.json({ ok: true, data: adGroups });
    } catch (err) {
        res.json({ ok: false, error: err.message });
    }
});

// ── API: ads (Prompt Helper) ──────────────────────────────────────────────────

router.post('/api/ads', dashAuth, async (req, res) => {
    const { customer_id, ad_group_id } = req.body;
    if (!customer_id || !ad_group_id) return res.json({ ok: false, error: 'customer_id and ad_group_id required' });
    const cid  = String(customer_id).replace(/[^0-9]/g, '');
    const agId = String(ad_group_id).replace(/[^0-9]/g, '');
    try {
        const query = `
            SELECT ad_group_ad.ad.id, ad_group_ad.ad.type, ad_group_ad.status,
                   ad_group_ad.ad.final_urls,
                   ad_group_ad.ad.responsive_search_ad.headlines,
                   ad_group_ad.ad.responsive_search_ad.descriptions
            FROM ad_group_ad
            WHERE ad_group.id = ${agId} AND ad_group_ad.status != 'REMOVED'
            LIMIT 50`;
        const rows = await searchQuery(cid, query, 50);
        const ads = rows.map(r => {
            const ad  = r.adGroupAd?.ad || {};
            const rsa = ad.responsiveSearchAd || {};
            return {
                id          : ad.id,
                type        : ad.type,
                status      : r.adGroupAd?.status,
                final_urls  : ad.finalUrls || [],
                headlines   : (rsa.headlines || []).map(h => h.text).slice(0, 3),
                descriptions: (rsa.descriptions || []).map(d => d.text).slice(0, 2),
            };
        });
        res.json({ ok: true, data: ads });
    } catch (err) {
        res.json({ ok: false, error: err.message });
    }
});

// getTokenStatus is imported directly from tokenManager as an alias above

module.exports = router;
