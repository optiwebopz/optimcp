/**
 * File: /gads-mcp/tools/adGroupTools.js
 * OptiMCP Google Ads MCP — Ad Group Management Tools
 *
 * Version: 1.0.0
 * Changelog:
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Tools:
 *   list_ad_groups     — list ad groups (optionally filtered by campaign)
 *   create_ad_group    — create a new ad group in a campaign
 *   update_ad_group    — update name, status, cpc_bid
 *   pause_ad_group     — pause
 *   enable_ad_group    — enable
 *   remove_ad_group    — remove
 */

'use strict';

const { searchQuery, mutate } = require('../lib/gadsClient');
const { logger }              = require('../lib/logger');

// ── list_ad_groups ────────────────────────────────────────────────────────────

async function listAdGroups({ customer_id, campaign_id, status_filter, limit = 500 }) {
    if (!customer_id) throw new Error('customer_id is required');

    const campFilter   = campaign_id   ? `AND campaign.id = ${campaign_id}`              : '';
    const statusFilter = status_filter ? `AND ad_group.status = '${status_filter.toUpperCase()}'`
                                       : "AND ad_group.status != 'REMOVED'";

    const query = `
        SELECT
            ad_group.id,
            ad_group.name,
            ad_group.status,
            ad_group.type,
            ad_group.cpc_bid_micros,
            ad_group.target_cpa_micros,
            campaign.id,
            campaign.name
        FROM ad_group
        WHERE ${statusFilter}
          ${campFilter}
        ORDER BY ad_group.name
        LIMIT ${Math.min(parseInt(limit, 10) || 500, 2000)}
    `;

    const rows = await searchQuery(customer_id, query, limit);

    const adGroups = rows.map(r => ({
        id          : r.adGroup.id,
        name        : r.adGroup.name,
        status      : r.adGroup.status,
        type        : r.adGroup.type,
        cpc_bid     : r.adGroup.cpcBidMicros
            ? (parseInt(r.adGroup.cpcBidMicros, 10) / 1_000_000).toFixed(4) : null,
        target_cpa  : r.adGroup.targetCpaMicros
            ? (parseInt(r.adGroup.targetCpaMicros, 10) / 1_000_000).toFixed(2) : null,
        campaign_id : r.campaign.id,
        campaign    : r.campaign.name,
        resource_name: `customers/${customer_id}/adGroups/${r.adGroup.id}`,
    }));

    return { customer_id, count: adGroups.length, ad_groups: adGroups };
}

// ── create_ad_group ───────────────────────────────────────────────────────────

async function createAdGroup({ customer_id, campaign_id, name, cpc_bid, status = 'ENABLED', type = 'SEARCH_STANDARD' }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!campaign_id) throw new Error('campaign_id is required');
    if (!name)        throw new Error('name is required');

    const cid      = String(customer_id).replace(/-/g, '');
    const campRes  = `customers/${cid}/campaigns/${campaign_id}`;

    const adGroupObj = {
        name    : name,
        status  : status.toUpperCase(),
        type    : type.toUpperCase(),
        campaign: campRes,
    };

    if (cpc_bid) {
        adGroupObj.cpcBidMicros = Math.round(parseFloat(cpc_bid) * 1_000_000);
    }

    const resp     = await mutate(cid, 'adGroups', [{ create: adGroupObj }]);
    const resource = resp.results?.[0]?.resourceName;

    logger.info('Ad group created', { resource, campaign_id });
    return { created: true, resource_name: resource, name, campaign_id, status, cpc_bid: cpc_bid || null };
}

// ── update_ad_group ───────────────────────────────────────────────────────────

async function updateAdGroup({ customer_id, ad_group_id, name, status, cpc_bid }) {
    if (!customer_id)  throw new Error('customer_id is required');
    if (!ad_group_id)  throw new Error('ad_group_id is required');

    const cid      = String(customer_id).replace(/-/g, '');
    const resource = `customers/${cid}/adGroups/${ad_group_id}`;
    const fields   = { resourceName: resource };
    const mask     = [];

    if (name)    { fields.name   = name;                mask.push('name'); }
    if (status)  { fields.status = status.toUpperCase(); mask.push('status'); }
    if (cpc_bid) { fields.cpcBidMicros = Math.round(parseFloat(cpc_bid) * 1_000_000); mask.push('cpc_bid_micros'); }

    if (!mask.length) throw new Error('Provide at least one field: name, status, cpc_bid');

    await mutate(cid, 'adGroups', [{ update: fields, updateMask: mask.join(',') }]);
    logger.info('Ad group updated', { ad_group_id, fields: mask });

    return { updated: true, ad_group_id, fields_updated: mask };
}

async function pauseAdGroup({ customer_id, ad_group_id }) {
    return updateAdGroup({ customer_id, ad_group_id, status: 'PAUSED' });
}

async function enableAdGroup({ customer_id, ad_group_id }) {
    return updateAdGroup({ customer_id, ad_group_id, status: 'ENABLED' });
}

async function removeAdGroup({ customer_id, ad_group_id }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!ad_group_id) throw new Error('ad_group_id is required');

    const cid      = String(customer_id).replace(/-/g, '');
    const resource = `customers/${cid}/adGroups/${ad_group_id}`;
    await mutate(cid, 'adGroups', [{ remove: resource }]);

    logger.info('Ad group removed', { ad_group_id });
    return { removed: true, ad_group_id };
}

module.exports = { listAdGroups, createAdGroup, updateAdGroup, pauseAdGroup, enableAdGroup, removeAdGroup };
