<?php
/**
 * File: /gads-mcp-php/tools/keyword_tools.php
 * OptiMCP Google Ads MCP PHP — Keyword Tools
 * Version: 1.0.0 | 2026-03-26
 * Tools: add_keywords, update_keyword, remove_keyword, add_negative_keywords
 */
if (!defined('ABSPATH')) exit;

function gads_add_keywords(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $agId   = (int)($input['ad_group_id'] ?? 0);
    $kws    = $input['keywords'] ?? [];
    $status = strtoupper($input['status'] ?? 'ENABLED');
    $valid  = ['BROAD','PHRASE','EXACT'];

    if (!$cid || !$agId)    throw new RuntimeException('customer_id and ad_group_id are required');
    if (empty($kws))        throw new RuntimeException('keywords array is required');

    $agRes = "customers/{$cid}/adGroups/{$agId}";
    $ops   = [];
    foreach ($kws as $i => $kw) {
        $text      = is_string($kw) ? $kw : ($kw['text'] ?? '');
        $matchType = is_string($kw) ? 'BROAD' : strtoupper($kw['match_type'] ?? 'BROAD');
        $cpcBid    = is_array($kw) && !empty($kw['cpc_bid']) ? (float)$kw['cpc_bid'] : null;

        if (!$text)                        throw new RuntimeException("Keyword at index {$i} missing text");
        if (!in_array($matchType, $valid)) throw new RuntimeException("Invalid match_type '{$matchType}' at index {$i}");

        $criterion = ['adGroup' => $agRes, 'status' => $status, 'keyword' => ['text' => $text, 'matchType' => $matchType]];
        if ($cpcBid) $criterion['cpcBidMicros'] = gads_to_micros($cpcBid);
        $ops[] = ['create' => $criterion];
    }

    $resp = gads_mutate($cid, 'adGroupCriteria', $ops);
    $resources = array_column($resp['results'] ?? [], 'resourceName');
    mcp_log('info', 'Keywords added', ['count' => count($resources), 'ad_group_id' => $agId]);
    return ['added' => true, 'count' => count($resources), 'ad_group_id' => $agId, 'resource_names' => $resources];
}

function gads_update_keyword(array $input): array {
    $cid   = gads_cid($input['customer_id']  ?? '');
    $agId  = (int)($input['ad_group_id']     ?? 0);
    $crId  = (int)($input['criterion_id']    ?? 0);
    if (!$cid || !$agId || !$crId) throw new RuntimeException('customer_id, ad_group_id, and criterion_id are required');

    $resource = "customers/{$cid}/adGroupCriteria/{$agId}~{$crId}";
    $fields   = ['resourceName' => $resource];
    $mask     = [];

    if (!empty($input['status']))  { $fields['status']       = strtoupper($input['status']);        $mask[] = 'status'; }
    if (!empty($input['cpc_bid'])) { $fields['cpcBidMicros'] = gads_to_micros((float)$input['cpc_bid']); $mask[] = 'cpc_bid_micros'; }

    if (empty($mask)) throw new RuntimeException('Provide at least one field: status, cpc_bid');

    gads_mutate($cid, 'adGroupCriteria', [['update' => $fields, 'updateMask' => implode(',', $mask)]]);
    return ['updated' => true, 'criterion_id' => $crId, 'fields_updated' => $mask];
}

function gads_remove_keyword(array $input): array {
    $cid  = gads_cid($input['customer_id']  ?? '');
    $agId = (int)($input['ad_group_id']     ?? 0);
    $crId = (int)($input['criterion_id']    ?? 0);
    if (!$cid || !$agId || !$crId) throw new RuntimeException('customer_id, ad_group_id, and criterion_id are required');

    gads_mutate($cid, 'adGroupCriteria', [['remove' => "customers/{$cid}/adGroupCriteria/{$agId}~{$crId}"]]);
    return ['removed' => true, 'criterion_id' => $crId];
}

function gads_add_negative_keywords(array $input): array {
    $cid    = gads_cid($input['customer_id']  ?? '');
    $campId = (int)($input['campaign_id']      ?? 0);
    $agId   = (int)($input['ad_group_id']      ?? 0);
    $kws    = $input['keywords'] ?? [];

    if (!$cid)                        throw new RuntimeException('customer_id is required');
    if (!$campId && !$agId)           throw new RuntimeException('Either campaign_id or ad_group_id is required');
    if (empty($kws))                  throw new RuntimeException('keywords array is required');

    if ($agId) {
        $agRes = "customers/{$cid}/adGroups/{$agId}";
        $ops   = array_map(fn($kw) => ['create' => [
            'adGroup'  => $agRes,
            'negative' => true,
            'keyword'  => ['text' => is_string($kw) ? $kw : $kw['text'], 'matchType' => is_string($kw) ? 'BROAD' : strtoupper($kw['match_type'] ?? 'BROAD')],
        ]], $kws);
        gads_mutate($cid, 'adGroupCriteria', $ops);
        return ['added' => true, 'level' => 'ad_group', 'ad_group_id' => $agId, 'count' => count($ops)];
    }

    $campRes = "customers/{$cid}/campaigns/{$campId}";
    $ops     = array_map(fn($kw) => ['create' => [
        'campaign' => $campRes,
        'keyword'  => ['text' => is_string($kw) ? $kw : $kw['text'], 'matchType' => is_string($kw) ? 'EXACT' : strtoupper($kw['match_type'] ?? 'EXACT')],
    ]], $kws);
    gads_mutate($cid, 'campaignCriteria', $ops);
    return ['added' => true, 'level' => 'campaign', 'campaign_id' => $campId, 'count' => count($ops)];
}
