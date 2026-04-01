<?php
/**
 * File: /gads-mcp-php/tools/account_tools.php
 * OptiMCP Google Ads MCP PHP — Account Tools
 * Version: 1.0.0 | 2026-03-26
 * Tools: list_accounts, get_account, run_gaql
 */
if (!defined('ABSPATH')) exit;

function gads_list_accounts(array $input): array {
    $mcc            = gads_cid(GOOGLE_ADS_MCC_ID);
    $includeHidden  = (bool)($input['include_hidden'] ?? false);
    $statusFilter   = $includeHidden ? '' : "AND customer_client.status = 'ENABLED'";

    $gaql = "
        SELECT
            customer_client.id,
            customer_client.descriptive_name,
            customer_client.currency_code,
            customer_client.time_zone,
            customer_client.status,
            customer_client.manager,
            customer_client.level
        FROM customer_client
        WHERE customer_client.level <= 1
        {$statusFilter}
        ORDER BY customer_client.descriptive_name
        LIMIT 500
    ";

    $rows = gads_search($mcc, $gaql, 500);

    $accounts = array_map(function($r) {
        $c = $r['customerClient'] ?? [];
        return [
            'id'       => $c['id']              ?? null,
            'name'     => $c['descriptiveName'] ?? '(unnamed)',
            'currency' => $c['currencyCode']    ?? null,
            'timezone' => $c['timeZone']        ?? null,
            'status'   => $c['status']          ?? null,
            'manager'  => $c['manager']         ?? false,
            'level'    => $c['level']           ?? null,
        ];
    }, $rows);

    return ['mcc_id' => GOOGLE_ADS_MCC_ID, 'count' => count($accounts), 'accounts' => $accounts];
}

function gads_get_account(array $input): array {
    $cid = gads_cid($input['customer_id'] ?? '');
    if (!$cid) throw new RuntimeException('customer_id is required');

    $rows = gads_search($cid, "
        SELECT
            customer.id, customer.descriptive_name, customer.currency_code,
            customer.time_zone, customer.status, customer.auto_tagging_enabled
        FROM customer LIMIT 1
    ", 1);

    if (empty($rows)) throw new RuntimeException("No account found for: {$cid}");
    $c = $rows[0]['customer'];

    return [
        'id'          => $c['id'],
        'name'        => $c['descriptiveName'],
        'currency'    => $c['currencyCode'],
        'timezone'    => $c['timeZone'],
        'status'      => $c['status'],
        'auto_tagging'=> $c['autoTaggingEnabled'] ?? false,
    ];
}

function gads_run_gaql(array $input): array {
    $cid   = gads_cid($input['customer_id'] ?? '');
    $gaql  = trim($input['query'] ?? '');
    $limit = (int)($input['page_size'] ?? GADS_DEFAULT_ROWS);

    if (!$cid)  throw new RuntimeException('customer_id is required');
    if (!$gaql) throw new RuntimeException('query is required');

    $rows = gads_search($cid, $gaql, $limit);
    return ['row_count' => count($rows), 'rows' => $rows, 'capped_at' => $limit];
}
