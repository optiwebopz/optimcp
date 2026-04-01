/**
 * File: /gads-mcp/tools/adTools.js
 * OptiMCP Google Ads MCP — Ad Management Tools
 *
 * Version: 1.0.0
 * Changelog:
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Tools:
 *   list_ads          — list ads in a customer / campaign / ad group
 *   create_rsa        — create a Responsive Search Ad
 *   update_ad_status  — pause / enable / remove an ad
 *
 * RSA limits (Google enforces these):
 *   headlines:    3–15,  max 30 chars each
 *   descriptions: 2–4,   max 90 chars each
 */

'use strict';

const { searchQuery, mutate } = require('../lib/gadsClient');
const { logger }              = require('../lib/logger');

// ── list_ads ──────────────────────────────────────────────────────────────────

async function listAds({ customer_id, campaign_id, ad_group_id, limit = 200 }) {
    if (!customer_id) throw new Error('customer_id is required');

    const campFilter    = campaign_id  ? `AND campaign.id = ${campaign_id}`   : '';
    const adGroupFilter = ad_group_id  ? `AND ad_group.id = ${ad_group_id}`   : '';

    const query = `
        SELECT
            ad_group_ad.ad.id,
            ad_group_ad.ad.name,
            ad_group_ad.ad.type,
            ad_group_ad.status,
            ad_group_ad.ad.final_urls,
            ad_group_ad.ad.responsive_search_ad.headlines,
            ad_group_ad.ad.responsive_search_ad.descriptions,
            ad_group.id,
            ad_group.name,
            campaign.id,
            campaign.name
        FROM ad_group_ad
        WHERE ad_group_ad.status != 'REMOVED'
          ${campFilter}
          ${adGroupFilter}
        ORDER BY ad_group_ad.ad.id
        LIMIT ${Math.min(parseInt(limit, 10) || 200, 1000)}
    `;

    const rows = await searchQuery(customer_id, query, limit);

    const ads = rows.map(r => {
        const ad  = r.adGroupAd?.ad || {};
        const rsa = ad.responsiveSearchAd || {};
        return {
            id          : ad.id,
            name        : ad.name,
            type        : ad.type,
            status      : r.adGroupAd?.status,
            final_urls  : ad.finalUrls || [],
            headlines   : (rsa.headlines   || []).map(h => ({ text: h.text, pinned: h.pinnedField || null })),
            descriptions: (rsa.descriptions || []).map(d => ({ text: d.text, pinned: d.pinnedField || null })),
            ad_group_id : r.adGroup?.id,
            ad_group    : r.adGroup?.name,
            campaign_id : r.campaign?.id,
            campaign    : r.campaign?.name,
            resource    : `customers/${customer_id}/adGroupAds/${r.adGroup?.id}~${ad.id}`,
        };
    });

    return { customer_id, count: ads.length, ads };
}

// ── create_rsa ────────────────────────────────────────────────────────────────
/**
 * @param {string}   customer_id
 * @param {string}   ad_group_id
 * @param {string[]} final_urls       - Array of landing page URLs (at least 1)
 * @param {Array}    headlines        - 3–15 items. Each: string OR { text, pin: 'HEADLINE_1'|'HEADLINE_2'|'HEADLINE_3' }
 * @param {Array}    descriptions     - 2–4 items. Each: string OR { text, pin: 'DESCRIPTION_1'|'DESCRIPTION_2' }
 * @param {string}   [status]         - ENABLED | PAUSED (default PAUSED for review)
 * @param {string}   [path1]          - Display path 1 (max 15 chars)
 * @param {string}   [path2]          - Display path 2 (max 15 chars)
 */
async function createRsa({
    customer_id,
    ad_group_id,
    final_urls,
    headlines,
    descriptions,
    status  = 'PAUSED',
    path1,
    path2,
}) {
    if (!customer_id)  throw new Error('customer_id is required');
    if (!ad_group_id)  throw new Error('ad_group_id is required');
    if (!final_urls?.length) throw new Error('final_urls is required (array with at least 1 URL)');
    if (!headlines?.length || headlines.length < 3) throw new Error('At least 3 headlines required');
    if (!descriptions?.length || descriptions.length < 2) throw new Error('At least 2 descriptions required');

    // Validate limits
    headlines.forEach((h, i) => {
        const text = typeof h === 'string' ? h : h.text;
        if (!text || text.length > 30) throw new Error(`Headline ${i + 1} must be 1–30 chars (got: "${text}")`);
    });
    descriptions.forEach((d, i) => {
        const text = typeof d === 'string' ? d : d.text;
        if (!text || text.length > 90) throw new Error(`Description ${i + 1} must be 1–90 chars (got: "${text}")`);
    });

    const cid         = String(customer_id).replace(/-/g, '');
    const adGroupRes  = `customers/${cid}/adGroups/${ad_group_id}`;

    const rsaObj = {
        headlines   : headlines.map(h => {
            const item = { text: typeof h === 'string' ? h : h.text };
            if (h.pin) item.pinnedField = h.pin;
            return item;
        }),
        descriptions: descriptions.map(d => {
            const item = { text: typeof d === 'string' ? d : d.text };
            if (d.pin) item.pinnedField = d.pin;
            return item;
        }),
    };

    if (path1) rsaObj.path1 = path1.slice(0, 15);
    if (path2) rsaObj.path2 = path2.slice(0, 15);

    const adObj = {
        finalUrls          : final_urls,
        responsiveSearchAd : rsaObj,
    };

    const op = {
        create: {
            ad       : adObj,
            adGroup  : adGroupRes,
            status   : status.toUpperCase(),
        }
    };

    const resp     = await mutate(cid, 'adGroupAds', [op]);
    const resource = resp.results?.[0]?.resourceName;

    logger.info('RSA created', { resource, ad_group_id });

    return {
        created    : true,
        resource_name: resource,
        ad_group_id,
        status,
        headline_count    : headlines.length,
        description_count : descriptions.length,
        final_urls,
    };
}

// ── update_ad_status ──────────────────────────────────────────────────────────

async function updateAdStatus({ customer_id, ad_group_id, ad_id, status }) {
    if (!customer_id) throw new Error('customer_id is required');
    if (!ad_group_id) throw new Error('ad_group_id is required');
    if (!ad_id)       throw new Error('ad_id is required');
    if (!status)      throw new Error('status is required: ENABLED | PAUSED | REMOVED');

    const cid      = String(customer_id).replace(/-/g, '');
    const resource = `customers/${cid}/adGroupAds/${ad_group_id}~${ad_id}`;

    if (status.toUpperCase() === 'REMOVED') {
        await mutate(cid, 'adGroupAds', [{ remove: resource }]);
        logger.info('Ad removed', { ad_id, ad_group_id });
        return { removed: true, ad_id, ad_group_id };
    }

    const op = {
        update    : { resourceName: resource, status: status.toUpperCase() },
        updateMask: 'status',
    };

    await mutate(cid, 'adGroupAds', [op]);
    logger.info('Ad status updated', { ad_id, status });
    return { updated: true, ad_id, ad_group_id, status: status.toUpperCase() };
}

module.exports = { listAds, createRsa, updateAdStatus };
