// File: /gads-mcp/tools/campaignTools.js
// OptiMCP Google Ads MCP — Campaign Management Tools
//
// Version: 1.1.0
// Changelog:
//   2026-04-01 | v1.1.0 | SECURITY FIX: status_filter now whitelisted to
//              |         | ENABLED/PAUSED/REMOVED only. Previously the raw value was
//              |         | interpolated directly into the GAQL query string.
//   2026-03-26 | v1.0.1 | Removed stale API_VERSION local reference
//   2026-03-26 | v1.0.0 | Initial release
//
// Tools:
//   list_campaigns       — list campaigns with status and budget
//   get_campaign         — get single campaign details
//   create_campaign      — create new campaign (Search or Display)
//   update_campaign      — update name, status, budget, bidding
//   pause_campaign       — pause a campaign
//   enable_campaign      — enable a paused campaign
//   remove_campaign      — remove (delete) a campaign
//   set_campaign_budget  — update daily budget

'use strict';

const { searchQuery, mutate } = require('../lib/gadsClient');
const { logger }              = require('../lib/logger');

// Allowed status values — whitelist only, never interpolate raw input into GAQL
const VALID_STATUSES = new Set(['ENABLED', 'PAUSED', 'REMOVED']);

// ── list_campaigns ────────────────────────────────────────────────────────────

async function listCampaigns({ customer_id, status_filter, limit = 200 }) {
    if (!customer_id) throw new Error('customer_id is required');

    // FIXED: Whitelist status_filter
    const sf = (status_filter || '').toUpperCase().trim();
    const statusWhere = VALID_STATUSES.has(sf)
        ? `AND campaign.status = '${sf}'`
        : "AND campaign.status != 'REMOVED'";

    const query = `
        SELECT
            campaign.id,
            campaign.name,
            campaign.status,
            campaign.advertising_channel_type,
            campaign.bidding_strategy_type,
            campaign.start_date,
            campaign.end_date,
            campaign_budget.id,
            campaign_budget.name,
            campaign_budget.amount_micros,
            campaign_budget.delivery_method
        FROM campaign
        WHERE ${statusWhere}
        ORDER BY campaign.name
        LIMIT ${Math.min(parseInt(limit, 10) || 200, 1000)}
    `;

    const rows = await searchQuery(customer_id, query, limit);

    const campaigns = rows.map(r => ({
        id          : r.campaign.id,
        name        : r.campaign.name,
        status      : r.campaign.status,
        channel     : r.campaign.advertisingChannelType,
        bidding     : r.campaign.biddingStrategyType,
        start_date  : r.campaign.startDate,
        end_date    : r.campaign.endDate || null,
        budget_id   : r.campaignBudget?.id,
        budget_name : r.campaignBudget?.name,
        daily_budget: r.campaignBudget?.amountMicros
            ? (parseInt(r.campaignBudget.amountMicros, 10) / 1_000_000).toFixed(2)
            : null,
        delivery    : r.campaignBudget?.deliveryMethod || null,
        resource_name: `customers/${String(customer_id).replace(/-/g, '')}/campaigns/${r.campaign.id}`,
    }));

    return { customer_id, count: campaigns.length, campaigns };
}

// ── get_campaign ──────────────────────────────────────────────────────────────

async function getCampaign({ customer_id, campaign_id }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!campaign_id) throw new Error('campaign_id is required');

    const query = `
        SELECT
            campaign.id,
            campaign.name,
            campaign.status,
            campaign.advertising_channel_type,
            campaign.bidding_strategy_type,
            campaign.start_date,
            campaign.end_date,
            campaign.tracking_url_template,
            campaign_budget.amount_micros,
            campaign_budget.delivery_method
        FROM campaign
        WHERE campaign.id = ${parseInt(campaign_id, 10)}
        LIMIT 1
    `;

    const rows = await searchQuery(customer_id, query, 1);
    if (!rows.length) throw new Error(`Campaign ${campaign_id} not found`);

    const r = rows[0];
    return {
        id          : r.campaign.id,
        name        : r.campaign.name,
        status      : r.campaign.status,
        channel     : r.campaign.advertisingChannelType,
        bidding     : r.campaign.biddingStrategyType,
        start_date  : r.campaign.startDate,
        end_date    : r.campaign.endDate || null,
        tracking_url: r.campaign.trackingUrlTemplate || null,
        daily_budget: r.campaignBudget?.amountMicros
            ? (parseInt(r.campaignBudget.amountMicros, 10) / 1_000_000).toFixed(2)
            : null,
        delivery    : r.campaignBudget?.deliveryMethod || null,
    };
}

// ── create_campaign ───────────────────────────────────────────────────────────

async function createCampaign({ customer_id, name, daily_budget, status = 'PAUSED', channel_type = 'SEARCH', bidding_strategy = 'MANUAL_CPC', start_date }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!name)        throw new Error('name is required');
    if (!daily_budget || parseFloat(daily_budget) <= 0) throw new Error('daily_budget must be greater than 0');

    const cid         = String(customer_id).replace(/-/g, '');
    const amountMicros = Math.round(parseFloat(daily_budget) * 1_000_000);

    // Create budget first
    const budgetResp = await mutate(cid, 'campaignBudgets', [{
        create: {
            name          : `${name} Budget`,
            amountMicros,
            deliveryMethod: 'STANDARD',
        },
    }]);

    const budgetRes = budgetResp.results?.[0]?.resourceName;
    if (!budgetRes) throw new Error('Failed to create campaign budget');

    const campaignObj = {
        name                  : name,
        status                : status.toUpperCase(),
        campaignBudget        : budgetRes,
        advertisingChannelType: channel_type.toUpperCase(),
        biddingStrategyType   : bidding_strategy.toUpperCase(),
        startDate             : start_date || new Date().toISOString().slice(0, 10).replace(/-/g, ''),
    };

    const resp = await mutate(cid, 'campaigns', [{ create: campaignObj }]);
    const res  = resp.results?.[0]?.resourceName;

    logger.info('Campaign created', { resource: res, name });
    return { created: true, resource_name: res, name, status: status.toUpperCase(), daily_budget: parseFloat(daily_budget) };
}

// ── update_campaign ───────────────────────────────────────────────────────────

async function updateCampaign({ customer_id, campaign_id, name, status }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!campaign_id) throw new Error('campaign_id is required');

    const cid    = String(customer_id).replace(/-/g, '');
    const res    = `customers/${cid}/campaigns/${campaign_id}`;
    const update = { resourceName: res };
    const mask   = [];

    if (name)   { update.name   = name;                 mask.push('name'); }
    if (status) { update.status = status.toUpperCase(); mask.push('status'); }

    if (!mask.length) throw new Error('Provide at least one field to update: name, status');

    await mutate(cid, 'campaigns', [{ update, updateMask: mask.join(',') }]);
    logger.info('Campaign updated', { campaign_id, fields: mask });
    return { updated: true, campaign_id, fields: mask };
}

// ── pause_campaign ────────────────────────────────────────────────────────────

async function pauseCampaign({ customer_id, campaign_id }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!campaign_id) throw new Error('campaign_id is required');

    const cid = String(customer_id).replace(/-/g, '');
    const res = `customers/${cid}/campaigns/${campaign_id}`;
    await mutate(cid, 'campaigns', [{ update: { resourceName: res, status: 'PAUSED' }, updateMask: 'status' }]);
    logger.info('Campaign paused', { campaign_id });
    return { paused: true, campaign_id };
}

// ── enable_campaign ───────────────────────────────────────────────────────────

async function enableCampaign({ customer_id, campaign_id }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!campaign_id) throw new Error('campaign_id is required');

    const cid = String(customer_id).replace(/-/g, '');
    const res = `customers/${cid}/campaigns/${campaign_id}`;
    await mutate(cid, 'campaigns', [{ update: { resourceName: res, status: 'ENABLED' }, updateMask: 'status' }]);
    logger.info('Campaign enabled', { campaign_id });
    return { enabled: true, campaign_id };
}

// ── remove_campaign ───────────────────────────────────────────────────────────

async function removeCampaign({ customer_id, campaign_id }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!campaign_id) throw new Error('campaign_id is required');

    const cid = String(customer_id).replace(/-/g, '');
    const res = `customers/${cid}/campaigns/${campaign_id}`;
    await mutate(cid, 'campaigns', [{ remove: res }]);
    logger.info('Campaign removed', { campaign_id });
    return { removed: true, campaign_id };
}

// ── set_campaign_budget ───────────────────────────────────────────────────────

async function setCampaignBudget({ customer_id, budget_id, daily_budget }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!budget_id)   throw new Error('budget_id is required');
    if (!daily_budget || parseFloat(daily_budget) <= 0) throw new Error('daily_budget must be greater than 0');

    const cid          = String(customer_id).replace(/-/g, '');
    const res          = `customers/${cid}/campaignBudgets/${budget_id}`;
    const amountMicros = Math.round(parseFloat(daily_budget) * 1_000_000);

    await mutate(cid, 'campaignBudgets', [{
        update    : { resourceName: res, amountMicros },
        updateMask: 'amount_micros',
    }]);

    logger.info('Campaign budget updated', { budget_id, daily_budget });
    return { updated: true, budget_id, daily_budget: parseFloat(daily_budget) };
}

module.exports = { listCampaigns, getCampaign, createCampaign, updateCampaign, pauseCampaign, enableCampaign, removeCampaign, setCampaignBudget };
