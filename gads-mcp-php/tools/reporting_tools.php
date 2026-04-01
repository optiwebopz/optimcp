<?php
/**
 * File: /gads-mcp-php/tools/reporting_tools.php
 * OptiMCP Google Ads MCP PHP — Reporting Tools
 * Version: 1.0.0 | 2026-03-26
 * Tools: get_account_summary, get_campaign_report, get_ad_group_report,
 *        get_keyword_report, get_ad_report, get_search_terms
 */
if (!defined('ABSPATH')) exit;

function gads_get_account_summary(array $input): array {
    $cid   = gads_cid($input['customer_id'] ?? '');
    $dr    = $input['date_range'] ?? 'LAST_30_DAYS';
    if (!$cid) throw new RuntimeException('customer_id is required');

    $rows = gads_search($cid, "
        SELECT
            metrics.impressions, metrics.clicks, metrics.cost_micros,
            metrics.ctr, metrics.average_cpc, metrics.conversions,
            metrics.conversions_value, metrics.cost_per_conversion,
            customer.currency_code
        FROM customer
        WHERE " . gads_date_clause($dr) . " LIMIT 1
    ", 1);

    if (empty($rows)) return ['customer_id'=>$cid,'date_range'=>$dr,'metrics'=>[]];
    return [
        'customer_id' => $cid,
        'date_range'  => $dr,
        'currency'    => $rows[0]['customer']['currencyCode'] ?? null,
        'metrics'     => gads_fmt_metrics($rows[0]['metrics'] ?? []),
    ];
}

function gads_get_campaign_report(array $input): array {
    $cid   = gads_cid($input['customer_id'] ?? '');
    $dr    = $input['date_range'] ?? 'LAST_30_DAYS';
    $limit = min((int)($input['limit'] ?? 200), GADS_MAX_ROWS);
    if (!$cid) throw new RuntimeException('customer_id is required');

    $rows = gads_search($cid, "
        SELECT
            campaign.id, campaign.name, campaign.status,
            campaign.advertising_channel_type, campaign.bidding_strategy_type,
            campaign_budget.amount_micros,
            metrics.impressions, metrics.clicks, metrics.cost_micros,
            metrics.ctr, metrics.average_cpc, metrics.conversions,
            metrics.conversions_value, metrics.cost_per_conversion
        FROM campaign
        WHERE campaign.status != 'REMOVED' AND " . gads_date_clause($dr) . "
        ORDER BY metrics.cost_micros DESC
    ", $limit);

    $campaigns = array_map(function($r) {
        $c = $r['campaign'] ?? [];
        $b = $r['campaignBudget'] ?? [];
        return [
            'id'          => $c['id'],
            'name'        => $c['name'],
            'status'      => $c['status'],
            'channel'     => $c['advertisingChannelType'] ?? null,
            'bidding'     => $c['biddingStrategyType']    ?? null,
            'daily_budget'=> gads_from_micros($b['amountMicros'] ?? null),
            'metrics'     => gads_fmt_metrics($r['metrics'] ?? []),
        ];
    }, $rows);

    return ['customer_id'=>$cid,'date_range'=>$dr,'count'=>count($campaigns),'campaigns'=>$campaigns];
}

function gads_get_ad_group_report(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = $input['campaign_id'] ?? '';
    $dr     = $input['date_range'] ?? 'LAST_30_DAYS';
    $limit  = min((int)($input['limit'] ?? 500), GADS_MAX_ROWS);
    if (!$cid) throw new RuntimeException('customer_id is required');

    $cf = $campId ? "AND campaign.id = " . (int)$campId : '';
    $rows = gads_search($cid, "
        SELECT
            ad_group.id, ad_group.name, ad_group.status, ad_group.type,
            campaign.id, campaign.name,
            metrics.impressions, metrics.clicks, metrics.cost_micros,
            metrics.ctr, metrics.average_cpc, metrics.conversions
        FROM ad_group
        WHERE ad_group.status != 'REMOVED' {$cf} AND " . gads_date_clause($dr) . "
        ORDER BY metrics.cost_micros DESC
    ", $limit);

    $adGroups = array_map(function($r) {
        return [
            'id'         => $r['adGroup']['id'],
            'name'       => $r['adGroup']['name'],
            'status'     => $r['adGroup']['status'],
            'type'       => $r['adGroup']['type'] ?? null,
            'campaign_id'=> $r['campaign']['id']  ?? null,
            'campaign'   => $r['campaign']['name'] ?? null,
            'metrics'    => gads_fmt_metrics($r['metrics'] ?? []),
        ];
    }, $rows);

    return ['customer_id'=>$cid,'date_range'=>$dr,'count'=>count($adGroups),'ad_groups'=>$adGroups];
}

function gads_get_keyword_report(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = $input['campaign_id']  ?? '';
    $agId   = $input['ad_group_id']  ?? '';
    $dr     = $input['date_range']   ?? 'LAST_30_DAYS';
    $limit  = min((int)($input['limit'] ?? 500), GADS_MAX_ROWS);
    if (!$cid) throw new RuntimeException('customer_id is required');

    $filters = [];
    if ($campId) $filters[] = "AND campaign.id = " . (int)$campId;
    if ($agId)   $filters[] = "AND ad_group.id = " . (int)$agId;
    $f = implode(' ', $filters);

    $rows = gads_search($cid, "
        SELECT
            ad_group_criterion.keyword.text, ad_group_criterion.keyword.match_type,
            ad_group_criterion.status, ad_group_criterion.quality_info.quality_score,
            ad_group_criterion.criterion_id,
            ad_group.id, ad_group.name, campaign.id, campaign.name,
            metrics.impressions, metrics.clicks, metrics.cost_micros,
            metrics.ctr, metrics.average_cpc, metrics.conversions
        FROM keyword_view
        WHERE ad_group_criterion.type = 'KEYWORD'
          AND ad_group_criterion.status != 'REMOVED' {$f}
          AND " . gads_date_clause($dr) . "
        ORDER BY metrics.cost_micros DESC
    ", $limit);

    $keywords = array_map(function($r) {
        $c = $r['adGroupCriterion'] ?? [];
        return [
            'id'           => $c['criterionId'] ?? null,
            'keyword'      => $c['keyword']['text']      ?? null,
            'match_type'   => $c['keyword']['matchType'] ?? null,
            'status'       => $c['status']               ?? null,
            'quality_score'=> $c['qualityInfo']['qualityScore'] ?? null,
            'ad_group_id'  => $r['adGroup']['id']   ?? null,
            'ad_group'     => $r['adGroup']['name'] ?? null,
            'campaign_id'  => $r['campaign']['id']  ?? null,
            'campaign'     => $r['campaign']['name']?? null,
            'metrics'      => gads_fmt_metrics($r['metrics'] ?? []),
        ];
    }, $rows);

    return ['customer_id'=>$cid,'date_range'=>$dr,'count'=>count($keywords),'keywords'=>$keywords];
}

function gads_get_ad_report(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = $input['campaign_id']  ?? '';
    $agId   = $input['ad_group_id']  ?? '';
    $dr     = $input['date_range']   ?? 'LAST_30_DAYS';
    $limit  = min((int)($input['limit'] ?? 200), GADS_MAX_ROWS);
    if (!$cid) throw new RuntimeException('customer_id is required');

    $filters = [];
    if ($campId) $filters[] = "AND campaign.id = " . (int)$campId;
    if ($agId)   $filters[] = "AND ad_group.id = " . (int)$agId;
    $f = implode(' ', $filters);

    $rows = gads_search($cid, "
        SELECT
            ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.ad.type,
            ad_group_ad.status, ad_group_ad.ad.final_urls,
            ad_group_ad.ad.responsive_search_ad.headlines,
            ad_group_ad.ad.responsive_search_ad.descriptions,
            ad_group.id, ad_group.name, campaign.id, campaign.name,
            metrics.impressions, metrics.clicks, metrics.cost_micros,
            metrics.ctr, metrics.conversions
        FROM ad_group_ad
        WHERE ad_group_ad.status != 'REMOVED' {$f}
          AND " . gads_date_clause($dr) . "
        ORDER BY metrics.impressions DESC
    ", $limit);

    $ads = array_map(function($r) {
        $ad  = $r['adGroupAd']['ad'] ?? [];
        $rsa = $ad['responsiveSearchAd'] ?? [];
        return [
            'id'          => $ad['id']   ?? null,
            'name'        => $ad['name'] ?? null,
            'type'        => $ad['type'] ?? null,
            'status'      => $r['adGroupAd']['status'] ?? null,
            'final_urls'  => $ad['finalUrls'] ?? [],
            'headlines'   => array_column($rsa['headlines']    ?? [], 'text'),
            'descriptions'=> array_column($rsa['descriptions'] ?? [], 'text'),
            'ad_group_id' => $r['adGroup']['id']   ?? null,
            'ad_group'    => $r['adGroup']['name'] ?? null,
            'campaign_id' => $r['campaign']['id']  ?? null,
            'campaign'    => $r['campaign']['name']?? null,
            'metrics'     => gads_fmt_metrics($r['metrics'] ?? []),
        ];
    }, $rows);

    return ['customer_id'=>$cid,'date_range'=>$dr,'count'=>count($ads),'ads'=>$ads];
}

function gads_get_search_terms(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = $input['campaign_id'] ?? '';
    $dr     = $input['date_range']  ?? 'LAST_30_DAYS';
    $limit  = min((int)($input['limit'] ?? 500), GADS_MAX_ROWS);
    if (!$cid) throw new RuntimeException('customer_id is required');

    $cf = $campId ? "AND campaign.id = " . (int)$campId : '';
    $rows = gads_search($cid, "
        SELECT
            search_term_view.search_term, search_term_view.status,
            campaign.id, campaign.name, ad_group.id, ad_group.name,
            metrics.impressions, metrics.clicks, metrics.cost_micros,
            metrics.ctr, metrics.conversions
        FROM search_term_view
        WHERE " . gads_date_clause($dr) . " {$cf}
        ORDER BY metrics.clicks DESC
    ", $limit);

    $terms = array_map(function($r) {
        return [
            'search_term' => $r['searchTermView']['searchTerm'] ?? null,
            'status'      => $r['searchTermView']['status']     ?? null,
            'campaign_id' => $r['campaign']['id']   ?? null,
            'campaign'    => $r['campaign']['name'] ?? null,
            'ad_group_id' => $r['adGroup']['id']   ?? null,
            'ad_group'    => $r['adGroup']['name'] ?? null,
            'metrics'     => gads_fmt_metrics($r['metrics'] ?? []),
        ];
    }, $rows);

    return ['customer_id'=>$cid,'date_range'=>$dr,'count'=>count($terms),'search_terms'=>$terms];
}
