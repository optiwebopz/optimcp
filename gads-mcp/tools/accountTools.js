/**
 * File: /gads-mcp/tools/accountTools.js
 * OptiMCP Google Ads MCP — Account / MCC Tools
 *
 * Version: 1.0.0
 * Changelog:
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Tools:
 *   list_accounts     — list all client accounts under the MCC
 *   get_account       — get details for a specific customer ID
 *   run_gaql          — run any raw GAQL query (power user escape hatch)
 */

'use strict';

const { searchQuery, get } = require('../lib/gadsClient');
const MCC_ID = () => String(process.env.GOOGLE_ADS_MCC_ID || '').replace(/-/g, '');

// ── list_accounts ─────────────────────────────────────────────────────────────

async function listAccounts({ include_hidden = false } = {}) {
    const query = `
        SELECT
            customer_client.client_customer,
            customer_client.descriptive_name,
            customer_client.currency_code,
            customer_client.time_zone,
            customer_client.status,
            customer_client.manager,
            customer_client.level,
            customer_client.id
        FROM customer_client
        WHERE customer_client.level <= 1
        ${!include_hidden ? "AND customer_client.status = 'ENABLED'" : ''}
        ORDER BY customer_client.descriptive_name
    `;

    const rows = await searchQuery(MCC_ID(), query, 500);

    const accounts = rows.map(r => {
        const c = r.customerClient;
        return {
            id          : c.id,
            name        : c.descriptiveName || '(unnamed)',
            currency    : c.currencyCode,
            timezone    : c.timeZone,
            status      : c.status,
            is_manager  : c.manager,
            level       : c.level,
            resource    : c.clientCustomer,
        };
    });

    return { accounts, count: accounts.length, mcc_id: MCC_ID() };
}

// ── get_account ───────────────────────────────────────────────────────────────

async function getAccount({ customer_id }) {
    if (!customer_id) throw new Error('customer_id is required');

    const query = `
        SELECT
            customer.id,
            customer.descriptive_name,
            customer.currency_code,
            customer.time_zone,
            customer.status,
            customer.auto_tagging_enabled,
            customer.tracking_url_template
        FROM customer
        LIMIT 1
    `;

    const rows = await searchQuery(customer_id, query, 1);
    if (!rows.length) throw new Error(`No account found for customer_id: ${customer_id}`);

    const c = rows[0].customer;
    return {
        id              : c.id,
        name            : c.descriptiveName,
        currency        : c.currencyCode,
        timezone        : c.timeZone,
        status          : c.status,
        auto_tagging    : c.autoTaggingEnabled,
        tracking_template: c.trackingUrlTemplate || null,
    };
}

// ── run_gaql ──────────────────────────────────────────────────────────────────

async function runGaql({ customer_id, query, page_size = 1000 }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!query)       throw new Error('query is required');

    const rows = await searchQuery(customer_id, query, page_size);
    return { row_count: rows.length, rows, capped_at: page_size };
}

module.exports = { listAccounts, getAccount, runGaql };
