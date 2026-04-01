/**
 * File: /gads-mcp/lib/auth.js
 * OptiMCP Google Ads MCP — Authentication Middleware
 * Version: 1.0.0
 * Changelog:
 *   2026-03-26 | v1.0.0 | Initial release
 */
'use strict';
const crypto     = require('crypto');
const { logger } = require('./logger');

function auth(req, res, next) {
    const token    = req.headers['x-mcp-token'] || '';
    const expected = process.env.MCP_SECRET_TOKEN || '';

    if (!token || !expected) {
        logger.warn('Auth failed: missing token', { ip: req.ip });
        return res.status(401).json({ ok: false, error: 'Unauthorized' });
    }
    try {
        const a = Buffer.from(token.padEnd(expected.length));
        const b = Buffer.from(expected);
        if (a.length !== b.length || !crypto.timingSafeEqual(a, b)) {
            logger.warn('Auth failed: invalid token', { ip: req.ip });
            return res.status(401).json({ ok: false, error: 'Unauthorized' });
        }
    } catch {
        return res.status(401).json({ ok: false, error: 'Unauthorized' });
    }
    next();
}

module.exports = { auth };
