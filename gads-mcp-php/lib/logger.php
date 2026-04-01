<?php
/**
 * File: /gads-mcp-php/lib/logger.php
 * OptiMCP Google Ads MCP PHP — File Logger
 * Version: 1.0.0 | 2026-03-26
 */
if (!defined('ABSPATH')) exit;

function mcp_log(string $level, string $message, array $ctx = []): void {
    $levels = ['debug'=>0,'info'=>1,'warn'=>2,'error'=>3];
    if (($levels[$level]??1) < ($levels[MCP_LOG_LEVEL]??1)) return;

    $dir = dirname(MCP_LOG_PATH);
    if (!is_dir($dir)) @mkdir($dir, 0750, true);

    $c    = $ctx ? ' ' . json_encode($ctx, JSON_UNESCAPED_UNICODE) : '';
    $line = sprintf("[%s] [%s] %s%s\n", date('Y-m-d\TH:i:s'), strtoupper($level), $message, $c);
    @file_put_contents(MCP_LOG_PATH, $line, FILE_APPEND | LOCK_EX);
}
