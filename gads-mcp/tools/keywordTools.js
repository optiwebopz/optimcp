/**
 * File: /gads-mcp/tools/keywordTools.js
 * OptiMCP Google Ads MCP — Keyword Management Tools
 *
 * Version: 1.0.0
 * Changelog:
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Tools:
 *   add_keywords      — add one or many keywords to an ad group
 *   update_keyword    — update status or cpc_bid of a keyword
 *   remove_keyword    — remove a keyword
 *   add_negative_keywords — add negative keywords at campaign or ad group level
 */

'use strict';

const { searchQuery, mutate } = require('../lib/gadsClient');
const { logger }              = require('../lib/logger');

const VALID_MATCH_TYPES = ['BROAD', 'PHRASE', 'EXACT'];

// ── add_keywords ──────────────────────────────────────────────────────────────
/**
 * @param {string}   customer_id
 * @param {string}   ad_group_id
 * @param {Array}    keywords      - Array of { text, match_type, cpc_bid? }
 *                                  match_type: BROAD | PHRASE | EXACT
 * @param {string}   [status]      - ENABLED | PAUSED (default ENABLED)
 */
async function addKeywords({ customer_id, ad_group_id, keywords, status = 'ENABLED' }) {
    if (!customer_id)    throw new Error('customer_id is required');
    if (!ad_group_id)    throw new Error('ad_group_id is required');
    if (!keywords?.length) throw new Error('keywords array is required');

    const cid        = String(customer_id).replace(/-/g, '');
    const adGroupRes = `customers/${cid}/adGroups/${ad_group_id}`;

    const operations = keywords.map((kw, i) => {
        const text       = typeof kw === 'string' ? kw : kw.text;
        const matchType  = (typeof kw === 'string' ? 'BROAD' : kw.match_type || 'BROAD').toUpperCase();
        const cpcBid     = typeof kw === 'object' ? kw.cpc_bid : null;

        if (!text) throw new Error(`Keyword at index ${i} missing text`);
        if (!VALID_MATCH_TYPES.includes(matchType)) {
            throw new Error(`Invalid match_type "${matchType}" at index ${i}. Use: BROAD, PHRASE, EXACT`);
        }

        const criterion = {
            adGroup: adGroupRes,
            status : status.toUpperCase(),
            keyword: { text, matchType },
        };

        if (cpcBid) criterion.cpcBidMicros = Math.round(parseFloat(cpcBid) * 1_000_000);

        return { create: criterion };
    });

    const resp = await mutate(cid, 'adGroupCriteria', operations);
    const resources = (resp.results || []).map(r => r.resourceName);

    logger.info('Keywords added', { ad_group_id, count: resources.length });
    return {
        added          : true,
        count          : resources.length,
        ad_group_id,
        resource_names : resources,
    };
}

// ── update_keyword ────────────────────────────────────────────────────────────

async function updateKeyword({ customer_id, ad_group_id, criterion_id, status, cpc_bid }) {
    if (!customer_id)   throw new Error('customer_id is required');
    if (!ad_group_id)   throw new Error('ad_group_id is required');
    if (!criterion_id)  throw new Error('criterion_id is required');

    const cid      = String(customer_id).replace(/-/g, '');
    const resource = `customers/${cid}/adGroupCriteria/${ad_group_id}~${criterion_id}`;
    const fields   = { resourceName: resource };
    const mask     = [];

    if (status)  { fields.status = status.toUpperCase(); mask.push('status'); }
    if (cpc_bid) { fields.cpcBidMicros = Math.round(parseFloat(cpc_bid) * 1_000_000); mask.push('cpc_bid_micros'); }

    if (!mask.length) throw new Error('Provide at least one field: status, cpc_bid');

    await mutate(cid, 'adGroupCriteria', [{ update: fields, updateMask: mask.join(',') }]);
    logger.info('Keyword updated', { criterion_id, fields: mask });

    return { updated: true, criterion_id, ad_group_id, fields_updated: mask };
}

// ── remove_keyword ────────────────────────────────────────────────────────────

async function removeKeyword({ customer_id, ad_group_id, criterion_id }) {
    if (!customer_id)  throw new Error('customer_id is required');
    if (!ad_group_id)  throw new Error('ad_group_id is required');
    if (!criterion_id) throw new Error('criterion_id is required');

    const cid      = String(customer_id).replace(/-/g, '');
    const resource = `customers/${cid}/adGroupCriteria/${ad_group_id}~${criterion_id}`;

    await mutate(cid, 'adGroupCriteria', [{ remove: resource }]);
    logger.info('Keyword removed', { criterion_id, ad_group_id });

    return { removed: true, criterion_id, ad_group_id };
}

// ── add_negative_keywords ─────────────────────────────────────────────────────
/**
 * Add negative keywords at campaign level (shared) or ad group level.
 * @param {string}   customer_id
 * @param {string}   [campaign_id]   — set for campaign-level negatives
 * @param {string}   [ad_group_id]   — set for ad-group-level negatives
 * @param {Array}    keywords        — Array of { text, match_type } or plain strings
 */
async function addNegativeKeywords({ customer_id, campaign_id, ad_group_id, keywords }) {
    if (!customer_id)      throw new Error('customer_id is required');
    if (!campaign_id && !ad_group_id) throw new Error('Either campaign_id or ad_group_id is required');
    if (!keywords?.length) throw new Error('keywords array is required');

    const cid = String(customer_id).replace(/-/g, '');

    if (ad_group_id) {
        // Ad group negatives
        const adGroupRes = `customers/${cid}/adGroups/${ad_group_id}`;
        const ops = keywords.map(kw => {
            const text      = typeof kw === 'string' ? kw : kw.text;
            const matchType = (typeof kw === 'string' ? 'BROAD' : kw.match_type || 'BROAD').toUpperCase();
            return { create: { adGroup: adGroupRes, negative: true, keyword: { text, matchType } } };
        });
        const resp = await mutate(cid, 'adGroupCriteria', ops);
        logger.info('Negative keywords added (ad group)', { ad_group_id, count: ops.length });
        return { added: true, level: 'ad_group', ad_group_id, count: ops.length };
    } else {
        // Campaign negatives
        const campRes = `customers/${cid}/campaigns/${campaign_id}`;
        const ops = keywords.map(kw => {
            const text      = typeof kw === 'string' ? kw : kw.text;
            const matchType = (typeof kw === 'string' ? 'BROAD' : kw.match_type || 'EXACT').toUpperCase();
            return { create: { campaign: campRes, keyword: { text, matchType } } };
        });
        const resp = await mutate(cid, 'campaignCriteria', ops);
        logger.info('Negative keywords added (campaign)', { campaign_id, count: ops.length });
        return { added: true, level: 'campaign', campaign_id, count: ops.length };
    }
}

module.exports = { addKeywords, updateKeyword, removeKeyword, addNegativeKeywords };
