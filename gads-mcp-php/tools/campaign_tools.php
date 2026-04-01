<?php
/**
 * File: /gads-mcp-php/tools/campaign_tools.php
 * OptiMCP Google Ads MCP PHP — Campaign Management Tools
 * Version: 1.0.0 | 2026-03-26
 * Tools: list_campaigns, get_campaign, create_campaign, update_campaign,
 *        pause_campaign, enable_campaign, remove_campaign, set_campaign_budget
 */
if (!defined('ABSPATH')) exit;

function gads_list_campaigns(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $sf     = $input['status_filter'] ?? '';
    $limit  = min((int)($input['limit'] ?? 200), GADS_MAX_ROWS);
    if (!$cid) throw new RuntimeException('customer_id is required');

    $statusWhere = $sf
        ? "AND campaign.status = '" . strtoupper($sf) . "'"
        : "AND campaign.status != 'REMOVED'";

    $rows = gads_search($cid, "
        SELECT
            campaign.id, campaign.name, campaign.status,
            campaign.advertising_channel_type, campaign.bidding_strategy_type,
            campaign.start_date, campaign.end_date,
            campaign_budget.id, campaign_budget.amount_micros
        FROM campaign
        WHERE {$statusWhere}
        ORDER BY campaign.name
    ", $limit);

    $campaigns = array_map(function($r) use ($cid) {
        $c = $r['campaign']       ?? [];
        $b = $r['campaignBudget'] ?? [];
        return [
            'id'          => $c['id'],
            'name'        => $c['name'],
            'status'      => $c['status'],
            'channel'     => $c['advertisingChannelType'] ?? null,
            'bidding'     => $c['biddingStrategyType']    ?? null,
            'start_date'  => $c['startDate']              ?? null,
            'end_date'    => $c['endDate']                ?? null,
            'daily_budget'=> gads_from_micros($b['amountMicros'] ?? null),
            'budget_id'   => $b['id']                    ?? null,
            'resource'    => "customers/{$cid}/campaigns/{$c['id']}",
        ];
    }, $rows);

    return ['customer_id'=>$cid,'count'=>count($campaigns),'campaigns'=>$campaigns];
}

function gads_get_campaign(array $input): array {
    $cid   = gads_cid($input['customer_id'] ?? '');
    $campId= (int)($input['campaign_id'] ?? 0);
    if (!$cid || !$campId) throw new RuntimeException('customer_id and campaign_id are required');

    $rows = gads_search($cid, "
        SELECT
            campaign.id, campaign.name, campaign.status,
            campaign.advertising_channel_type, campaign.bidding_strategy_type,
            campaign.target_cpa.target_cpa_micros, campaign.target_roas.target_roas,
            campaign.start_date, campaign.end_date, campaign.tracking_url_template,
            campaign_budget.amount_micros, campaign_budget.delivery_method
        FROM campaign WHERE campaign.id = {$campId} LIMIT 1
    ", 1);

    if (empty($rows)) throw new RuntimeException("Campaign {$campId} not found");
    $c = $rows[0]['campaign']       ?? [];
    $b = $rows[0]['campaignBudget'] ?? [];

    return [
        'id'          => $c['id'],
        'name'        => $c['name'],
        'status'      => $c['status'],
        'channel'     => $c['advertisingChannelType'] ?? null,
        'bidding'     => $c['biddingStrategyType']    ?? null,
        'target_cpa'  => gads_from_micros($c['targetCpa']['targetCpaMicros'] ?? null),
        'target_roas' => $c['targetRoas']['targetRoas']                       ?? null,
        'start_date'  => $c['startDate']                                      ?? null,
        'end_date'    => $c['endDate']                                        ?? null,
        'tracking_url'=> $c['trackingUrlTemplate']                            ?? null,
        'daily_budget'=> gads_from_micros($b['amountMicros'] ?? null),
        'delivery'    => $b['deliveryMethod']                                 ?? null,
        'resource'    => "customers/{$cid}/campaigns/{$c['id']}",
    ];
}

function gads_create_campaign(array $input): array {
    $cid     = gads_cid($input['customer_id'] ?? '');
    $name    = trim($input['name']         ?? '');
    $budget  = (float)($input['daily_budget'] ?? 0);
    $channel = strtoupper($input['channel_type']      ?? 'SEARCH');
    $bidding = strtoupper($input['bidding_strategy']  ?? 'MANUAL_CPC');
    $status  = strtoupper($input['status']            ?? 'PAUSED');
    $today   = date('Ymd');

    if (!$cid)   throw new RuntimeException('customer_id is required');
    if (!$name)  throw new RuntimeException('name is required');
    if (!$budget)throw new RuntimeException('daily_budget is required');

    // Step 1: Create budget
    $budgetOp  = ['create' => ['name' => "{$name} Budget", 'amountMicros' => gads_to_micros($budget), 'deliveryMethod' => 'STANDARD']];
    $budgetResp= gads_mutate($cid, 'campaignBudgets', [$budgetOp]);
    $budgetRes = $budgetResp['results'][0]['resourceName'] ?? null;
    if (!$budgetRes) throw new RuntimeException('Failed to create budget');

    // Step 2: Build campaign
    $camp = [
        'name'                   => $name,
        'status'                 => $status,
        'advertisingChannelType' => $channel,
        'campaignBudget'         => $budgetRes,
        'startDate'              => $input['start_date'] ? str_replace('-', '', $input['start_date']) : $today,
        'networkSettings'        => ['targetGoogleSearch'=>true,'targetSearchNetwork'=>true,'targetContentNetwork'=>false],
    ];

    if (!empty($input['end_date'])) $camp['endDate'] = str_replace('-', '', $input['end_date']);

    switch ($bidding) {
        case 'MAXIMIZE_CONVERSIONS':
            $camp['maximizeConversions'] = !empty($input['target_cpa'])
                ? ['targetCpaMicros' => gads_to_micros((float)$input['target_cpa'])] : (object)[];
            break;
        case 'MAXIMIZE_CONVERSION_VALUE':
            $camp['maximizeConversionValue'] = !empty($input['target_roas'])
                ? ['targetRoas' => (float)$input['target_roas']] : (object)[];
            break;
        case 'TARGET_CPA':
            $camp['targetCpa'] = ['targetCpaMicros' => gads_to_micros((float)($input['target_cpa'] ?? 0))];
            break;
        case 'TARGET_ROAS':
            $camp['targetRoas'] = ['targetRoas' => (float)($input['target_roas'] ?? 0)];
            break;
        default:
            $camp['manualCpc'] = ['enhancedCpcEnabled' => false];
    }

    $campResp  = gads_mutate($cid, 'campaigns', [['create' => $camp]]);
    $campRes   = $campResp['results'][0]['resourceName'] ?? null;

    mcp_log('info', 'Campaign created', ['resource' => $campRes]);
    return [
        'created'           => true,
        'campaign_resource' => $campRes,
        'budget_resource'   => $budgetRes,
        'name'              => $name,
        'status'            => $status,
        'daily_budget'      => number_format($budget, 2, '.', ''),
        'channel'           => $channel,
        'bidding'           => $bidding,
    ];
}

function gads_update_campaign(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = (int)($input['campaign_id'] ?? 0);
    if (!$cid || !$campId) throw new RuntimeException('customer_id and campaign_id are required');

    $resource = "customers/{$cid}/campaigns/{$campId}";
    $fields   = ['resourceName' => $resource];
    $mask     = [];

    if (!empty($input['name']))   { $fields['name']   = $input['name'];                    $mask[] = 'name'; }
    if (!empty($input['status'])) { $fields['status']  = strtoupper($input['status']);     $mask[] = 'status'; }

    if (empty($mask)) throw new RuntimeException('Provide at least one field: name, status');

    gads_mutate($cid, 'campaigns', [['update' => $fields, 'updateMask' => implode(',', $mask)]]);
    mcp_log('info', 'Campaign updated', ['campaign_id' => $campId, 'fields' => $mask]);
    return ['updated' => true, 'campaign_id' => $campId, 'fields_updated' => $mask];
}

function gads_pause_campaign(array $input): array {
    return gads_update_campaign(array_merge($input, ['status' => 'PAUSED']));
}

function gads_enable_campaign(array $input): array {
    return gads_update_campaign(array_merge($input, ['status' => 'ENABLED']));
}

function gads_remove_campaign(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = (int)($input['campaign_id'] ?? 0);
    if (!$cid || !$campId) throw new RuntimeException('customer_id and campaign_id are required');

    $resource = "customers/{$cid}/campaigns/{$campId}";
    gads_mutate($cid, 'campaigns', [['remove' => $resource]]);
    mcp_log('info', 'Campaign removed', ['campaign_id' => $campId]);
    return ['removed' => true, 'campaign_id' => $campId];
}

function gads_set_campaign_budget(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = (int)($input['campaign_id']   ?? 0);
    $budget = (float)($input['daily_budget'] ?? 0);
    if (!$cid || !$campId || !$budget) throw new RuntimeException('customer_id, campaign_id, and daily_budget are required');

    $rows = gads_search($cid, "
        SELECT campaign_budget.id, campaign_budget.resource_name
        FROM campaign WHERE campaign.id = {$campId} LIMIT 1
    ", 1);

    if (empty($rows)) throw new RuntimeException("Campaign {$campId} not found");
    $budgetRes = $rows[0]['campaignBudget']['resourceName'] ?? null;
    if (!$budgetRes) throw new RuntimeException('Budget resource not found');

    gads_mutate($cid, 'campaignBudgets', [[
        'update'     => ['resourceName' => $budgetRes, 'amountMicros' => gads_to_micros($budget)],
        'updateMask' => 'amount_micros',
    ]]);

    mcp_log('info', 'Budget updated', ['campaign_id' => $campId, 'budget' => $budget]);
    return ['updated' => true, 'campaign_id' => $campId, 'new_daily_budget' => number_format($budget, 2, '.', '')];
}
