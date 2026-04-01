/**
 * File: /gads-mcp/tools/campaignTools.js
 * OptiMCP Google Ads MCP — Campaign Management Tools
 *
 * Version: 1.0.1
 * Changelog:
 *   2026-03-26 | v1.0.1 | Removed stale API_VERSION local reference (now handled centrally in gadsClient.js)
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Tools:
 *   list_campaigns       — list campaigns with status and budget
 *   get_campaign         — get single campaign details
 *   create_campaign      — create new campaign (Search or Display)
 *   update_campaign      — update name, status, budget, bidding
 *   pause_campaign       — pause a campaign
 *   enable_campaign      — enable a paused campaign
 *   remove_campaign      — remove (delete) a campaign
 *   set_campaign_budget  — update daily budget
 */

'use strict';

const { searchQuery, mutate } = require('../lib/gadsClient');
const { logger }              = require('../lib/logger');

// ── list_campaigns ────────────────────────────────────────────────────────────

async function listCampaigns({ customer_id, status_filter, limit = 200 }) {
    if (!customer_id) throw new Error('customer_id is required');

    const statusWhere = status_filter
        ? `AND campaign.status = '${status_filter.toUpperCase()}'`
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
        id            : r.campaign.id,
        name          : r.campaign.name,
        status        : r.campaign.status,
        channel       : r.campaign.advertisingChannelType,
        bidding       : r.campaign.biddingStrategyType,
        start_date    : r.campaign.startDate,
        end_date      : r.campaign.endDate || null,
        budget_id     : r.campaignBudget?.id,
        budget_name   : r.campaignBudget?.name,
        daily_budget  : r.campaignBudget?.amountMicros
            ? (parseInt(r.campaignBudget.amountMicros, 10) / 1_000_000).toFixed(2)
            : null,
        resource_name : `customers/${customer_id}/campaigns/${r.campaign.id}`,
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
            campaign.target_cpa.target_cpa_micros,
            campaign.target_roas.target_roas,
            campaign.maximize_conversions.target_cpa_micros,
            campaign.start_date,
            campaign.end_date,
            campaign.tracking_url_template,
            campaign_budget.amount_micros,
            campaign_budget.delivery_method
        FROM campaign
        WHERE campaign.id = ${campaign_id}
        LIMIT 1
    `;

    const rows = await searchQuery(customer_id, query, 1);
    if (!rows.length) throw new Error(`Campaign ${campaign_id} not found`);

    const c = rows[0].campaign;
    const b = rows[0].campaignBudget;

    return {
        id            : c.id,
        name          : c.name,
        status        : c.status,
        channel       : c.advertisingChannelType,
        bidding       : c.biddingStrategyType,
        target_cpa    : c.targetCpa?.targetCpaMicros
            ? (parseInt(c.targetCpa.targetCpaMicros, 10) / 1_000_000).toFixed(2) : null,
        target_roas   : c.targetRoas?.targetRoas || null,
        start_date    : c.startDate,
        end_date      : c.endDate || null,
        tracking_url  : c.trackingUrlTemplate || null,
        daily_budget  : b?.amountMicros
            ? (parseInt(b.amountMicros, 10) / 1_000_000).toFixed(2) : null,
        delivery_method: b?.deliveryMethod || null,
        resource_name : `customers/${customer_id}/campaigns/${c.id}`,
    };
}

// ── create_campaign ───────────────────────────────────────────────────────────
// Creates a budget, then a campaign referencing that budget.

async function createCampaign({
    customer_id,
    name,
    daily_budget,
    channel_type    = 'SEARCH',
    bidding_strategy = 'MANUAL_CPC',
    status          = 'PAUSED',
    start_date,
    end_date,
    target_cpa,
    target_roas,
}) {
    if (!customer_id)  throw new Error('customer_id is required');
    if (!name)         throw new Error('name is required');
    if (!daily_budget) throw new Error('daily_budget is required (e.g. 10.00)');

    const cid = String(customer_id).replace(/-/g, '');

    // Step 1: Create the budget
    const budgetOp = {
        create: {
            name         : `${name} Budget`,
            amountMicros : Math.round(parseFloat(daily_budget) * 1_000_000),
            deliveryMethod: 'STANDARD',
        }
    };

    const budgetResp = await mutate(cid, 'campaignBudgets', [budgetOp]);
    const budgetResource = budgetResp.results?.[0]?.resourceName;

    if (!budgetResource) throw new Error('Failed to create campaign budget');
    logger.info('Budget created', { resource: budgetResource });

    // Step 2: Build campaign object
    const today = new Date().toISOString().split('T')[0].replace(/-/g, '');
    const campObj = {
        name,
        status          : status.toUpperCase(),
        advertisingChannelType: channel_type.toUpperCase(),
        campaignBudget  : budgetResource,
        startDate       : start_date ? start_date.replace(/-/g, '') : today,
        networkSettings : {
            targetGoogleSearch       : true,
            targetSearchNetwork      : true,
            targetContentNetwork     : false,
        },
    };

    if (end_date) campObj.endDate = end_date.replace(/-/g, '');

    // Bidding strategy
    switch (bidding_strategy.toUpperCase()) {
        case 'MANUAL_CPC':
            campObj.manualCpc = { enhancedCpcEnabled: false };
            break;
        case 'MAXIMIZE_CONVERSIONS':
            campObj.maximizeConversions = target_cpa
                ? { targetCpaMicros: Math.round(parseFloat(target_cpa) * 1_000_000) }
                : {};
            break;
        case 'MAXIMIZE_CONVERSION_VALUE':
            campObj.maximizeConversionValue = target_roas
                ? { targetRoas: parseFloat(target_roas) }
                : {};
            break;
        case 'TARGET_CPA':
            campObj.targetCpa = { targetCpaMicros: Math.round(parseFloat(target_cpa || 0) * 1_000_000) };
            break;
        case 'TARGET_ROAS':
            campObj.targetRoas = { targetRoas: parseFloat(target_roas || 0) };
            break;
        default:
            campObj.manualCpc = { enhancedCpcEnabled: false };
    }

    const campResp = await mutate(cid, 'campaigns', [{ create: campObj }]);
    const campResource = campResp.results?.[0]?.resourceName;

    logger.info('Campaign created', { resource: campResource });

    return {
        created       : true,
        campaign_resource: campResource,
        budget_resource  : budgetResource,
        name,
        status,
        daily_budget  : parseFloat(daily_budget).toFixed(2),
        channel_type,
        bidding_strategy,
    };
}

// ── update_campaign ───────────────────────────────────────────────────────────

async function updateCampaign({ customer_id, campaign_id, name, status }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!campaign_id) throw new Error('campaign_id is required');

    const cid      = String(customer_id).replace(/-/g, '');
    const resource = `customers/${cid}/campaigns/${campaign_id}`;
    const updateFields  = { resourceName: resource };
    const updateMask    = [];

    if (name)   { updateFields.name   = name;                    updateMask.push('name'); }
    if (status) { updateFields.status = status.toUpperCase();    updateMask.push('status'); }

    if (!updateMask.length) throw new Error('Provide at least one field to update: name, status');

    const op = { update: updateFields, updateMask: updateMask.join(',') };
    const resp = await mutate(cid, 'campaigns', [op]);

    logger.info('Campaign updated', { campaign_id, fields: updateMask });
    return { updated: true, campaign_id, fields_updated: updateMask, resource };
}

// ── pause_campaign ────────────────────────────────────────────────────────────

async function pauseCampaign({ customer_id, campaign_id }) {
    return updateCampaign({ customer_id, campaign_id, status: 'PAUSED' });
}

// ── enable_campaign ───────────────────────────────────────────────────────────

async function enableCampaign({ customer_id, campaign_id }) {
    return updateCampaign({ customer_id, campaign_id, status: 'ENABLED' });
}

// ── remove_campaign ───────────────────────────────────────────────────────────

async function removeCampaign({ customer_id, campaign_id }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!campaign_id) throw new Error('campaign_id is required');

    const cid      = String(customer_id).replace(/-/g, '');
    const resource = `customers/${cid}/campaigns/${campaign_id}`;
    const resp     = await mutate(cid, 'campaigns', [{ remove: resource }]);

    logger.info('Campaign removed', { campaign_id });
    return { removed: true, campaign_id, resource };
}

// ── set_campaign_budget ───────────────────────────────────────────────────────

async function setCampaignBudget({ customer_id, campaign_id, daily_budget }) {
    if (!customer_id)  throw new Error('customer_id is required');
    if (!campaign_id)  throw new Error('campaign_id is required');
    if (!daily_budget) throw new Error('daily_budget is required');

    const cid = String(customer_id).replace(/-/g, '');

    // First, find the budget resource name for this campaign
    const query = `
        SELECT campaign_budget.id, campaign_budget.resource_name
        FROM campaign
        WHERE campaign.id = ${campaign_id}
        LIMIT 1
    `;
    const rows = await searchQuery(cid, query, 1);
    if (!rows.length) throw new Error(`Campaign ${campaign_id} not found`);

    const budgetResource = rows[0].campaignBudget?.resourceName;
    if (!budgetResource) throw new Error('Could not find budget for this campaign');

    const op = {
        update    : {
            resourceName : budgetResource,
            amountMicros : Math.round(parseFloat(daily_budget) * 1_000_000),
        },
        updateMask: 'amount_micros',
    };

    await mutate(cid, 'campaignBudgets', [op]);
    logger.info('Budget updated', { campaign_id, daily_budget });

    return {
        updated       : true,
        campaign_id,
        new_daily_budget: parseFloat(daily_budget).toFixed(2),
        budget_resource : budgetResource,
    };
}

module.exports = {
    listCampaigns,
    getCampaign,
    createCampaign,
    updateCampaign,
    pauseCampaign,
    enableCampaign,
    removeCampaign,
    setCampaignBudget,
};
