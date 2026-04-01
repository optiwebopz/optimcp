<?php
/**
 * File: /gads-mcp-php/tools/campaign_tools.php
 * OptiMCP Google Ads MCP PHP — Campaign Management Tools
 *
 * Version: 1.1.0
 * Changelog:
 *   2026-04-01 | v1.1.0 | SECURITY FIX: status_filter now whitelisted to
 *              |         | ENABLED/PAUSED/REMOVED only. Previously the raw
 *              |         | value was interpolated directly into the GAQL query string.
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Tools: list_campaigns, get_campaign, create_campaign, update_campaign,
 *        pause_campaign, enable_campaign, remove_campaign, set_campaign_budget
 */
if (!defined('ABSPATH')) exit;

// Allowed status values for GAQL — never interpolate user input directly
const CAMPAIGN_VALID_STATUSES = ['ENABLED', 'PAUSED', 'REMOVED'];

function gads_list_campaigns(array $input): array {
    $cid   = gads_cid($input['customer_id'] ?? '');
    $limit = min((int)($input['limit'] ?? 200), GADS_MAX_ROWS);
    if (!$cid) throw new RuntimeException('customer_id is required');

    // FIXED: Whitelist status_filter — never interpolate raw user input into GAQL
    $sfRaw = strtoupper(trim($input['status_filter'] ?? ''));
    if ($sfRaw !== '' && !in_array($sfRaw, CAMPAIGN_VALID_STATUSES, true)) {
        $sfRaw = ''; // silently ignore invalid values
    }
    $statusWhere = $sfRaw !== ''
        ? "AND campaign.status = '{$sfRaw}'"
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

    return ['customer_id' => $cid, 'count' => count($campaigns), 'campaigns' => $campaigns];
}

function gads_get_campaign(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = (int)($input['campaign_id'] ?? 0);
    if (!$cid || !$campId) throw new RuntimeException('customer_id and campaign_id are required');

    $rows = gads_search($cid, "
        SELECT
            campaign.id, campaign.name, campaign.status,
            campaign.advertising_channel_type, campaign.bidding_strategy_type,
            campaign.start_date, campaign.end_date, campaign.tracking_url_template,
            campaign_budget.amount_micros, campaign_budget.delivery_method
        FROM campaign WHERE campaign.id = {$campId} LIMIT 1
    ", 1);

    if (empty($rows)) throw new RuntimeException("Campaign {$campId} not found");
    $c = $rows[0]['campaign']       ?? [];
    $b = $rows[0]['campaignBudget'] ?? [];
    return [
        'id'           => $c['id'],
        'name'         => $c['name'],
        'status'       => $c['status'],
        'channel'      => $c['advertisingChannelType'] ?? null,
        'bidding'      => $c['biddingStrategyType']    ?? null,
        'start_date'   => $c['startDate']              ?? null,
        'end_date'     => $c['endDate']                ?? null,
        'tracking_url' => $c['trackingUrlTemplate']    ?? null,
        'daily_budget' => gads_from_micros($b['amountMicros'] ?? null),
        'delivery'     => $b['deliveryMethod']         ?? null,
        'resource'     => "customers/{$cid}/campaigns/{$c['id']}",
    ];
}

function gads_create_campaign(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $name   = trim($input['name']         ?? '');
    $budget = (float)($input['daily_budget'] ?? 0);
    $status = strtoupper($input['status'] ?? 'PAUSED');

    if (!$cid || !$name) throw new RuntimeException('customer_id and name are required');
    if ($budget <= 0)     throw new RuntimeException('daily_budget must be greater than 0');

    // Create budget first
    $budgetResp = gads_mutate($cid, 'campaignBudgets', [[
        'create' => [
            'name'           => "{$name} Budget",
            'amountMicros'   => gads_to_micros($budget),
            'deliveryMethod' => 'STANDARD',
        ],
    ]]);
    $budgetRes = $budgetResp['results'][0]['resourceName'] ?? null;
    if (!$budgetRes) throw new RuntimeException('Failed to create campaign budget');

    $campaignObj = [
        'name'                   => $name,
        'status'                 => $status,
        'campaignBudget'         => $budgetRes,
        'advertisingChannelType' => strtoupper($input['channel_type'] ?? 'SEARCH'),
        'biddingStrategyType'    => strtoupper($input['bidding_strategy'] ?? 'MANUAL_CPC'),
        'startDate'              => $input['start_date'] ?? date('Ymd'),
    ];

    if (!empty($input['end_date'])) {
        $campaignObj['endDate'] = $input['end_date'];
    }

    $resp = gads_mutate($cid, 'campaigns', [['create' => $campaignObj]]);
    $res  = $resp['results'][0]['resourceName'] ?? null;
    mcp_log('info', 'Campaign created', ['resource' => $res, 'name' => $name]);
    return ['created' => true, 'resource_name' => $res, 'name' => $name, 'status' => $status, 'daily_budget' => $budget];
}

function gads_update_campaign(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = (int)($input['campaign_id'] ?? 0);
    if (!$cid || !$campId) throw new RuntimeException('customer_id and campaign_id are required');

    $res    = "customers/{$cid}/campaigns/{$campId}";
    $update = ['resourceName' => $res];
    $mask   = [];

    if (!empty($input['name']))   { $update['name']   = trim($input['name']); $mask[] = 'name'; }
    if (!empty($input['status'])) { $update['status'] = strtoupper($input['status']); $mask[] = 'status'; }

    if (empty($mask)) throw new RuntimeException('Provide at least one field to update: name, status');

    gads_mutate($cid, 'campaigns', [['update' => $update, 'updateMask' => implode(',', $mask)]]);
    mcp_log('info', 'Campaign updated', ['campaign_id' => $campId, 'fields' => $mask]);
    return ['updated' => true, 'campaign_id' => $campId, 'fields' => $mask];
}

function gads_pause_campaign(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = (int)($input['campaign_id'] ?? 0);
    if (!$cid || !$campId) throw new RuntimeException('customer_id and campaign_id are required');

    $res = "customers/{$cid}/campaigns/{$campId}";
    gads_mutate($cid, 'campaigns', [['update' => ['resourceName' => $res, 'status' => 'PAUSED'], 'updateMask' => 'status']]);
    mcp_log('info', 'Campaign paused', ['campaign_id' => $campId]);
    return ['paused' => true, 'campaign_id' => $campId];
}

function gads_enable_campaign(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = (int)($input['campaign_id'] ?? 0);
    if (!$cid || !$campId) throw new RuntimeException('customer_id and campaign_id are required');

    $res = "customers/{$cid}/campaigns/{$campId}";
    gads_mutate($cid, 'campaigns', [['update' => ['resourceName' => $res, 'status' => 'ENABLED'], 'updateMask' => 'status']]);
    mcp_log('info', 'Campaign enabled', ['campaign_id' => $campId]);
    return ['enabled' => true, 'campaign_id' => $campId];
}

function gads_remove_campaign(array $input): array {
    $cid    = gads_cid($input['customer_id'] ?? '');
    $campId = (int)($input['campaign_id'] ?? 0);
    if (!$cid || !$campId) throw new RuntimeException('customer_id and campaign_id are required');

    $res = "customers/{$cid}/campaigns/{$campId}";
    gads_mutate($cid, 'campaigns', [['remove' => $res]]);
    mcp_log('info', 'Campaign removed', ['campaign_id' => $campId]);
    return ['removed' => true, 'campaign_id' => $campId];
}

function gads_set_campaign_budget(array $input): array {
    $cid      = gads_cid($input['customer_id'] ?? '');
    $budgetId = (int)($input['budget_id']    ?? 0);
    $amount   = (float)($input['daily_budget'] ?? 0);
    if (!$cid || !$budgetId) throw new RuntimeException('customer_id and budget_id are required');
    if ($amount <= 0)         throw new RuntimeException('daily_budget must be greater than 0');

    $res = "customers/{$cid}/campaignBudgets/{$budgetId}";
    gads_mutate($cid, 'campaignBudgets', [[
        'update'     => ['resourceName' => $res, 'amountMicros' => gads_to_micros($amount)],
        'updateMask' => 'amount_micros',
    ]]);
    mcp_log('info', 'Campaign budget updated', ['budget_id' => $budgetId, 'amount' => $amount]);
    return ['updated' => true, 'budget_id' => $budgetId, 'daily_budget' => $amount];
}
