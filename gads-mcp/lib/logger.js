/**
 * File: /gads-mcp/lib/logger.js
 * OptiMCP Google Ads MCP — Logger
 * Version: 1.0.0
 * Changelog:
 *   2026-03-26 | v1.0.0 | Initial release
 */
'use strict';
const fs   = require('fs');
const path = require('path');

const LOG_PATH  = () => process.env.MCP_LOG_PATH  || path.join(__dirname, '../logs/gads-mcp.log');
const LOG_LEVEL = () => process.env.MCP_LOG_LEVEL || 'info';
const LEVELS    = { debug: 0, info: 1, warn: 2, error: 3 };

function ensureDir(p) {
    const d = path.dirname(p);
    if (!fs.existsSync(d)) fs.mkdirSync(d, { recursive: true, mode: 0o750 });
}

function log(level, message, context = {}) {
    if ((LEVELS[level] ?? 1) < (LEVELS[LOG_LEVEL()] ?? 1)) return;
    const ctx  = Object.keys(context).length ? ' ' + JSON.stringify(context) : '';
    const line = `[${new Date().toISOString()}] [${level.toUpperCase()}] ${message}${ctx}\n`;
    process.stdout.write(line);
    try { ensureDir(LOG_PATH()); fs.appendFileSync(LOG_PATH(), line); } catch {}
}

const logger = {
    debug: (m, c) => log('debug', m, c),
    info : (m, c) => log('info',  m, c),
    warn : (m, c) => log('warn',  m, c),
    error: (m, c) => log('error', m, c),
};
module.exports = { logger };
