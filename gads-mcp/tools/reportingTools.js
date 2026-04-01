/**
 * File: /gads-mcp/tools/reportingTools.js
 * OptiMCP Google Ads MCP — Reporting & Performance Tools
 *
 * Version: 1.0.0
 * Changelog:
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Tools:
 *   get_campaign_report    — clicks, impressions, cost, conversions by campaign
 *   get_ad_group_report    — same metrics by ad group
 *   get_keyword_report     — keyword-level performance
 *   get_ad_report          — ad-level performance
 *   get_search_terms       — search terms report
 *   get_account_summary    — account-level totals for a date range
 *
 * Date range presets: TODAY, YESTERDAY, LAST_7_DAYS, LAST_14_DAYS,
 *   LAST_30_DAYS, THIS_MONTH, LAST_MONTH, THIS_YEAR, LAST_YEAR
 * Or pass custom: { start: 'YYYY-MM-DD', end: 'YYYY-MM-DD' }
 */

'use strict';

const { searchQuery } = require('../lib/gadsClient');

// ── Date range helper ─────────────────────────────────────────────────────────

function dateClause(date_range) {
    const presets = [
        'TODAY','YESTERDAY','LAST_7_DAYS','LAST_14_DAYS','LAST_30_DAYS',
        'THIS_MONTH','LAST_MONTH','THIS_YEAR','LAST_YEAR','ALL_TIME',
    ];

    if (!date_range || date_range === 'LAST_30_DAYS') {
        return 'segments.date DURING LAST_30_DAYS';
    }

    if (presets.includes(date_range.toUpperCase())) {
        return `segments.date DURING ${date_range.toUpperCase()}`;
    }

    // Custom range: { start, end }
    if (typeof date_range === 'object' && date_range.start && date_range.end) {
        const s = date_range.start.replace(/-/g, '');
        const e = date_range.end.replace(/-/g, '');
        return `segments.date BETWEEN '${s}' AND '${e}'`;
    }

    return 'segments.date DURING LAST_30_DAYS';
}

// ── Metric formatter ──────────────────────────────────────────────────────────

function fmtMetrics(m) {
    if (!m) return {};
    const cost = m.costMicros ? (parseInt(m.costMicros, 10) / 1_000_000).toFixed(2) : '0.00';
    const cpc  = m.averageCpc ? (parseInt(m.averageCpc, 10) / 1_000_000).toFixed(4) : '0.0000';
    const ctr  = m.ctr != null ? (parseFloat(m.ctr) * 100).toFixed(2) + '%' : '0.00%';
    return {
        impressions  : parseInt(m.impressions || 0, 10),
        clicks       : parseInt(m.clicks || 0, 10),
        cost         : parseFloat(cost),
        ctr,
        avg_cpc      : parseFloat(cpc),
        conversions  : parseFloat(m.conversions || 0).toFixed(2),
        conv_value   : parseFloat(m.conversionsValue || 0).toFixed(2),
        cost_per_conv: m.costPerConversion
            ? (parseInt(m.costPerConversion, 10) / 1_000_000).toFixed(2)
            : null,
    };
}

// ── get_account_summary ───────────────────────────────────────────────────────

async function getAccountSummary({ customer_id, date_range = 'LAST_30_DAYS' }) {
    if (!customer_id) throw new Error('customer_id is required');

    const query = `
        SELECT
            metrics.impressions,
            metrics.clicks,
            metrics.cost_micros,
            metrics.ctr,
            metrics.average_cpc,
            metrics.conversions,
            metrics.conversions_value,
            metrics.cost_per_conversion,
            customer.currency_code
        FROM customer
        WHERE ${dateClause(date_range)}
        LIMIT 1
    `;

    const rows = await searchQuery(customer_id, query, 1);
    if (!rows.length) return { customer_id, date_range, metrics: {} };

    return {
        customer_id,
        date_range,
        currency: rows[0].customer?.currencyCode,
        metrics : fmtMetrics(rows[0].metrics),
    };
}

// ── get_campaign_report ───────────────────────────────────────────────────────

async function getCampaignReport({ customer_id, date_range = 'LAST_30_DAYS', limit = 200 }) {
    if (!customer_id) throw new Error('customer_id is required');

    const query = `
        SELECT
            campaign.id,
            campaign.name,
            campaign.status,
            campaign.advertising_channel_type,
            campaign.bidding_strategy_type,
            campaign_budget.amount_micros,
            metrics.impressions,
            metrics.clicks,
            metrics.cost_micros,
            metrics.ctr,
            metrics.average_cpc,
            metrics.conversions,
            metrics.conversions_value,
            metrics.cost_per_conversion
        FROM campaign
        WHERE campaign.status != 'REMOVED'
          AND ${dateClause(date_range)}
        ORDER BY metrics.cost_micros DESC
        LIMIT ${Math.min(parseInt(limit, 10) || 200, 1000)}
    `;

    const rows = await searchQuery(customer_id, query, limit);

    const campaigns = rows.map(r => ({
        id            : r.campaign.id,
        name          : r.campaign.name,
        status        : r.campaign.status,
        channel_type  : r.campaign.advertisingChannelType,
        bidding       : r.campaign.biddingStrategyType,
        daily_budget  : r.campaignBudget?.amountMicros
            ? (parseInt(r.campaignBudget.amountMicros, 10) / 1_000_000).toFixed(2)
            : null,
        metrics       : fmtMetrics(r.metrics),
    }));

    return { customer_id, date_range, count: campaigns.length, campaigns };
}

// ── get_ad_group_report ───────────────────────────────────────────────────────

async function getAdGroupReport({ customer_id, campaign_id, date_range = 'LAST_30_DAYS', limit = 500 }) {
    if (!customer_id) throw new Error('customer_id is required');

    const campFilter = campaign_id
        ? `AND campaign.id = ${campaign_id}` : '';

    const query = `
        SELECT
            ad_group.id,
            ad_group.name,
            ad_group.status,
            ad_group.type,
            campaign.id,
            campaign.name,
            metrics.impressions,
            metrics.clicks,
            metrics.cost_micros,
            metrics.ctr,
            metrics.average_cpc,
            metrics.conversions
        FROM ad_group
        WHERE ad_group.status != 'REMOVED'
          ${campFilter}
          AND ${dateClause(date_range)}
        ORDER BY metrics.cost_micros DESC
        LIMIT ${Math.min(parseInt(limit, 10) || 500, 2000)}
    `;

    const rows = await searchQuery(customer_id, query, limit);

    const adGroups = rows.map(r => ({
        id          : r.adGroup.id,
        name        : r.adGroup.name,
        status      : r.adGroup.status,
        type        : r.adGroup.type,
        campaign_id : r.campaign.id,
        campaign    : r.campaign.name,
        metrics     : fmtMetrics(r.metrics),
    }));

    return { customer_id, date_range, count: adGroups.length, ad_groups: adGroups };
}

// ── get_keyword_report ────────────────────────────────────────────────────────

async function getKeywordReport({ customer_id, campaign_id, ad_group_id, date_range = 'LAST_30_DAYS', limit = 500 }) {
    if (!customer_id) throw new Error('customer_id is required');

    const filters = [
        campaign_id  ? `AND campaign.id = ${campaign_id}`   : '',
        ad_group_id  ? `AND ad_group.id = ${ad_group_id}`   : '',
    ].filter(Boolean).join(' ');

    const query = `
        SELECT
            ad_group_criterion.keyword.text,
            ad_group_criterion.keyword.match_type,
            ad_group_criterion.status,
            ad_group_criterion.quality_info.quality_score,
            ad_group_criterion.final_urls,
            ad_group_criterion.criterion_id,
            ad_group.id,
            ad_group.name,
            campaign.id,
            campaign.name,
            metrics.impressions,
            metrics.clicks,
            metrics.cost_micros,
            metrics.ctr,
            metrics.average_cpc,
            metrics.conversions,
            metrics.average_position
        FROM keyword_view
        WHERE ad_group_criterion.type = 'KEYWORD'
          AND ad_group_criterion.status != 'REMOVED'
          ${filters}
          AND ${dateClause(date_range)}
        ORDER BY metrics.cost_micros DESC
        LIMIT ${Math.min(parseInt(limit, 10) || 500, 2000)}
    `;

    const rows = await searchQuery(customer_id, query, limit);

    const keywords = rows.map(r => ({
        id          : r.adGroupCriterion?.criterionId,
        keyword     : r.adGroupCriterion?.keyword?.text,
        match_type  : r.adGroupCriterion?.keyword?.matchType,
        status      : r.adGroupCriterion?.status,
        quality_score: r.adGroupCriterion?.qualityInfo?.qualityScore ?? null,
        ad_group_id : r.adGroup?.id,
        ad_group    : r.adGroup?.name,
        campaign_id : r.campaign?.id,
        campaign    : r.campaign?.name,
        metrics     : fmtMetrics(r.metrics),
    }));

    return { customer_id, date_range, count: keywords.length, keywords };
}

// ── get_ad_report ─────────────────────────────────────────────────────────────

async function getAdReport({ customer_id, campaign_id, ad_group_id, date_range = 'LAST_30_DAYS', limit = 200 }) {
    if (!customer_id) throw new Error('customer_id is required');

    const filters = [
        campaign_id ? `AND campaign.id = ${campaign_id}`  : '',
        ad_group_id ? `AND ad_group.id = ${ad_group_id}`  : '',
    ].filter(Boolean).join(' ');

    const query = `
        SELECT
            ad_group_ad.ad.id,
            ad_group_ad.ad.name,
            ad_group_ad.ad.type,
            ad_group_ad.status,
            ad_group_ad.ad.final_urls,
            ad_group_ad.ad.responsive_search_ad.headlines,
            ad_group_ad.ad.responsive_search_ad.descriptions,
            ad_group_ad.ad.expanded_text_ad.headline_part1,
            ad_group_ad.ad.expanded_text_ad.headline_part2,
            ad_group_ad.ad.expanded_text_ad.description,
            ad_group.id,
            ad_group.name,
            campaign.id,
            campaign.name,
            metrics.impressions,
            metrics.clicks,
            metrics.cost_micros,
            metrics.ctr,
            metrics.conversions
        FROM ad_group_ad
        WHERE ad_group_ad.status != 'REMOVED'
          ${filters}
          AND ${dateClause(date_range)}
        ORDER BY metrics.impressions DESC
        LIMIT ${Math.min(parseInt(limit, 10) || 200, 1000)}
    `;

    const rows = await searchQuery(customer_id, query, limit);

    const ads = rows.map(r => {
        const ad  = r.adGroupAd?.ad || {};
        const rsa = ad.responsiveSearchAd || {};
        const eta = ad.expandedTextAd    || {};

        return {
            id          : ad.id,
            name        : ad.name,
            type        : ad.type,
            status      : r.adGroupAd?.status,
            final_urls  : ad.finalUrls || [],
            headlines   : rsa.headlines?.map(h => h.text) || [eta.headlinePart1, eta.headlinePart2].filter(Boolean),
            descriptions: rsa.descriptions?.map(d => d.text) || [eta.description].filter(Boolean),
            ad_group_id : r.adGroup?.id,
            ad_group    : r.adGroup?.name,
            campaign_id : r.campaign?.id,
            campaign    : r.campaign?.name,
            metrics     : fmtMetrics(r.metrics),
        };
    });

    return { customer_id, date_range, count: ads.length, ads };
}

// ── get_search_terms ──────────────────────────────────────────────────────────

async function getSearchTerms({ customer_id, campaign_id, date_range = 'LAST_30_DAYS', limit = 500 }) {
    if (!customer_id) throw new Error('customer_id is required');

    const campFilter = campaign_id ? `AND campaign.id = ${campaign_id}` : '';

    const query = `
        SELECT
            search_term_view.search_term,
            search_term_view.status,
            campaign.id,
            campaign.name,
            ad_group.id,
            ad_group.name,
            metrics.impressions,
            metrics.clicks,
            metrics.cost_micros,
            metrics.ctr,
            metrics.conversions
        FROM search_term_view
        WHERE ${dateClause(date_range)}
          ${campFilter}
        ORDER BY metrics.clicks DESC
        LIMIT ${Math.min(parseInt(limit, 10) || 500, 2000)}
    `;

    const rows = await searchQuery(customer_id, query, limit);

    const terms = rows.map(r => ({
        search_term : r.searchTermView?.searchTerm,
        status      : r.searchTermView?.status,
        campaign_id : r.campaign?.id,
        campaign    : r.campaign?.name,
        ad_group_id : r.adGroup?.id,
        ad_group    : r.adGroup?.name,
        metrics     : fmtMetrics(r.metrics),
    }));

    return { customer_id, date_range, count: terms.length, search_terms: terms };
}

module.exports = {
    getAccountSummary,
    getCampaignReport,
    getAdGroupReport,
    getKeywordReport,
    getAdReport,
    getSearchTerms,
};
