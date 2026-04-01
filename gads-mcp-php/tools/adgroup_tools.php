<?php
/**
 * File: /gads-mcp-php/tools/adgroup_tools.php
 * OptiMCP Google Ads MCP PHP — Ad Group Tools
 * Version: 1.0.0 | 2026-03-26
 * Tools: list_ad_groups, create_ad_group, update_ad_group,
 *        pause_ad_group, enable_ad_group, remove_ad_group
 */
if (!defined('ABSPATH')) exit;

function gads_list_ad_groups(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = $input['campaign_id']   ?? '';
    $sf     = $input['status_filter'] ?? '';
    $limit  = min((int)($input['limit'] ?? 500), GADS_MAX_ROWS);
    if (!$cid) throw new RuntimeException('customer_id is required');

    $statusWhere = $sf ? "AND ad_group.status = '" . strtoupper($sf) . "'" : "AND ad_group.status != 'REMOVED'";
    $campFilter  = $campId ? "AND campaign.id = " . (int)$campId : '';

    $rows = gads_search($cid, "
        SELECT
            ad_group.id, ad_group.name, ad_group.status, ad_group.type,
            ad_group.cpc_bid_micros, ad_group.target_cpa_micros,
            campaign.id, campaign.name
        FROM ad_group
        WHERE {$statusWhere} {$campFilter}
        ORDER BY ad_group.name
    ", $limit);

    $groups = array_map(function($r) use ($cid) {
        $g = $r['adGroup'] ?? [];
        return [
            'id'          => $g['id'],
            'name'        => $g['name'],
            'status'      => $g['status'],
            'type'        => $g['type']           ?? null,
            'cpc_bid'     => gads_from_micros($g['cpcBidMicros']    ?? null),
            'target_cpa'  => gads_from_micros($g['targetCpaMicros'] ?? null),
            'campaign_id' => $r['campaign']['id']   ?? null,
            'campaign'    => $r['campaign']['name'] ?? null,
            'resource'    => "customers/{$cid}/adGroups/{$g['id']}",
        ];
    }, $rows);

    return ['customer_id'=>$cid,'count'=>count($groups),'ad_groups'=>$groups];
}

function gads_create_ad_group(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = (int)($input['campaign_id'] ?? 0);
    $name   = trim($input['name'] ?? '');
    $status = strtoupper($input['status'] ?? 'ENABLED');
    $type   = strtoupper($input['type']   ?? 'SEARCH_STANDARD');
    $cpcBid = (float)($input['cpc_bid']   ?? 0);
    if (!$cid || !$campId || !$name) throw new RuntimeException('customer_id, campaign_id, and name are required');

    $obj = [
        'name'     => $name,
        'status'   => $status,
        'type'     => $type,
        'campaign' => "customers/{$cid}/campaigns/{$campId}",
    ];
    if ($cpcBid > 0) $obj['cpcBidMicros'] = gads_to_micros($cpcBid);

    $resp = gads_mutate($cid, 'adGroups', [['create' => $obj]]);
    $res  = $resp['results'][0]['resourceName'] ?? null;
    mcp_log('info', 'Ad group created', ['resource' => $res]);
    return ['created'=>true,'resource_name'=>$res,'name'=>$name,'campaign_id'=>$campId,'status'=>$status];
}

function gads_update_ad_group(array $input): array {
    $cid   = gads_cid($input['customer_id'] ?? '');
    $agId  = (int)($input['ad_group_id'] ?? 0);
    if (!$cid || !$agId) throw new RuntimeException('customer_id and ad_group_id are required');

    $resource = "customers/{$cid}/adGroups/{$agId}";
    $fields   = ['resourceName' => $resource];
    $mask     = [];

    if (!empty($input['name']))    { $fields['name']          = $input['name'];               $mask[] = 'name'; }
    if (!empty($input['status']))  { $fields['status']         = strtoupper($input['status']); $mask[] = 'status'; }
    if (!empty($input['cpc_bid'])) { $fields['cpcBidMicros']   = gads_to_micros((float)$input['cpc_bid']); $mask[] = 'cpc_bid_micros'; }

    if (empty($mask)) throw new RuntimeException('Provide at least one field: name, status, cpc_bid');

    gads_mutate($cid, 'adGroups', [['update' => $fields, 'updateMask' => implode(',', $mask)]]);
    return ['updated' => true, 'ad_group_id' => $agId, 'fields_updated' => $mask];
}

function gads_pause_ad_group(array $input): array  { return gads_update_ad_group(array_merge($input, ['status' => 'PAUSED'])); }
function gads_enable_ad_group(array $input): array { return gads_update_ad_group(array_merge($input, ['status' => 'ENABLED'])); }

function gads_remove_ad_group(array $input): array {
    $cid  = gads_cid($input['customer_id'] ?? '');
    $agId = (int)($input['ad_group_id']    ?? 0);
    if (!$cid || !$agId) throw new RuntimeException('customer_id and ad_group_id are required');
    gads_mutate($cid, 'adGroups', [['remove' => "customers/{$cid}/adGroups/{$agId}"]]);
    return ['removed' => true, 'ad_group_id' => $agId];
}
