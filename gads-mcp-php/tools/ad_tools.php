<?php
/**
 * File: /gads-mcp-php/tools/ad_tools.php
 * OptiMCP Google Ads MCP PHP — Ad Tools
 * Version: 1.0.0 | 2026-03-26
 * Tools: list_ads, create_rsa, update_ad_status
 */
if (!defined('ABSPATH')) exit;

function gads_list_ads(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = $input['campaign_id']  ?? '';
    $agId   = $input['ad_group_id']  ?? '';
    $limit  = min((int)($input['limit'] ?? 200), GADS_MAX_ROWS);
    if (!$cid) throw new RuntimeException('customer_id is required');

    $f = '';
    if ($campId) $f .= " AND campaign.id = " . (int)$campId;
    if ($agId)   $f .= " AND ad_group.id = " . (int)$agId;

    $rows = gads_search($cid, "
        SELECT
            ad_group_ad.ad.id, ad_group_ad.ad.name, ad_group_ad.ad.type,
            ad_group_ad.status, ad_group_ad.ad.final_urls,
            ad_group_ad.ad.responsive_search_ad.headlines,
            ad_group_ad.ad.responsive_search_ad.descriptions,
            ad_group.id, ad_group.name, campaign.id, campaign.name
        FROM ad_group_ad
        WHERE ad_group_ad.status != 'REMOVED' {$f}
        ORDER BY ad_group_ad.ad.id
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
            'headlines'   => array_map(fn($h)=>['text'=>$h['text'],'pinned'=>$h['pinnedField']??null], $rsa['headlines']??[]),
            'descriptions'=> array_map(fn($d)=>['text'=>$d['text'],'pinned'=>$d['pinnedField']??null], $rsa['descriptions']??[]),
            'ad_group_id' => $r['adGroup']['id']   ?? null,
            'ad_group'    => $r['adGroup']['name'] ?? null,
            'campaign_id' => $r['campaign']['id']  ?? null,
            'campaign'    => $r['campaign']['name']?? null,
        ];
    }, $rows);

    return ['customer_id'=>$cid,'count'=>count($ads),'ads'=>$ads];
}

function gads_create_rsa(array $input): array {
    $cid       = gads_cid($input['customer_id'] ?? '');
    $agId      = (int)($input['ad_group_id'] ?? 0);
    $finalUrls = $input['final_urls']    ?? [];
    $headlines = $input['headlines']     ?? [];
    $descs     = $input['descriptions']  ?? [];
    $status    = strtoupper($input['status'] ?? 'PAUSED');
    $path1     = substr($input['path1'] ?? '', 0, 15);
    $path2     = substr($input['path2'] ?? '', 0, 15);

    if (!$cid || !$agId)           throw new RuntimeException('customer_id and ad_group_id are required');
    if (empty($finalUrls))         throw new RuntimeException('final_urls is required');
    if (count($headlines) < 3)     throw new RuntimeException('At least 3 headlines required');
    if (count($descs) < 2)         throw new RuntimeException('At least 2 descriptions required');

    // Validate lengths
    foreach ($headlines as $i => $h) {
        $text = is_string($h) ? $h : ($h['text'] ?? '');
        if (strlen($text) > 30) throw new RuntimeException("Headline " . ($i+1) . " exceeds 30 chars");
    }
    foreach ($descs as $i => $d) {
        $text = is_string($d) ? $d : ($d['text'] ?? '');
        if (strlen($text) > 90) throw new RuntimeException("Description " . ($i+1) . " exceeds 90 chars");
    }

    $rsaObj = [
        'headlines'    => array_map(function($h) {
            $item = ['text' => is_string($h) ? $h : $h['text']];
            if (is_array($h) && !empty($h['pin'])) $item['pinnedField'] = $h['pin'];
            return $item;
        }, $headlines),
        'descriptions' => array_map(function($d) {
            $item = ['text' => is_string($d) ? $d : $d['text']];
            if (is_array($d) && !empty($d['pin'])) $item['pinnedField'] = $d['pin'];
            return $item;
        }, $descs),
    ];

    if ($path1) $rsaObj['path1'] = $path1;
    if ($path2) $rsaObj['path2'] = $path2;

    $op = ['create' => [
        'ad'      => ['finalUrls' => $finalUrls, 'responsiveSearchAd' => $rsaObj],
        'adGroup' => "customers/{$cid}/adGroups/{$agId}",
        'status'  => $status,
    ]];

    $resp = gads_mutate($cid, 'adGroupAds', [$op]);
    $res  = $resp['results'][0]['resourceName'] ?? null;
    mcp_log('info', 'RSA created', ['resource' => $res]);
    return ['created'=>true,'resource_name'=>$res,'ad_group_id'=>$agId,'status'=>$status,'headline_count'=>count($headlines),'description_count'=>count($descs)];
}

function gads_update_ad_status(array $input): array {
    $cid   = gads_cid($input['customer_id'] ?? '');
    $agId  = (int)($input['ad_group_id'] ?? 0);
    $adId  = (int)($input['ad_id']       ?? 0);
    $status= strtoupper($input['status'] ?? '');

    if (!$cid || !$agId || !$adId || !$status) {
        throw new RuntimeException('customer_id, ad_group_id, ad_id, and status are required');
    }

    $resource = "customers/{$cid}/adGroupAds/{$agId}~{$adId}";

    if ($status === 'REMOVED') {
        gads_mutate($cid, 'adGroupAds', [['remove' => $resource]]);
        return ['removed' => true, 'ad_id' => $adId];
    }

    gads_mutate($cid, 'adGroupAds', [[
        'update'     => ['resourceName' => $resource, 'status' => $status],
        'updateMask' => 'status',
    ]]);
    return ['updated' => true, 'ad_id' => $adId, 'status' => $status];
}
