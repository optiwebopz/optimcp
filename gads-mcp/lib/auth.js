// File: /gads-mcp/lib/auth.js
// OptiMCP Google Ads MCP — Authentication Middleware
//
// Version: 1.1.0
// Changelog:
//   2026-04-01 | v1.1.0 | SECURITY FIX: Replaced broken padEnd timingSafeEqual with
//              |         | correct same-length-only comparison. Reject empty tokens
//              |         | explicitly before any buffer comparison. Prevents empty-token
//              |         | bypass and fixes padding logic that could mask timing leaks.
//   2026-03-26 | v1.0.0 | Initial release

'use strict';

const crypto     = require('crypto');
const { logger } = require('./logger');

function auth(req, res, next) {
    const token    = (req.headers['x-mcp-token'] || '').trim();
    const expected = (process.env.MCP_SECRET_TOKEN || '').trim();

    // Reject immediately if either side is empty — never compare empty strings
    if (!token || !expected) {
        logger.warn('Auth failed: missing token', { ip: req.ip });
        return res.status(401).json({ ok: false, error: 'Unauthorized' });
    }

    // Different lengths = instant reject (timing-safe: no byte comparison performed)
    if (token.length !== expected.length) {
        logger.warn('Auth failed: invalid token', { ip: req.ip });
        return res.status(401).json({ ok: false, error: 'Unauthorized' });
    }

    try {
        const a = Buffer.from(token);
        const b = Buffer.from(expected);
        if (!crypto.timingSafeEqual(a, b)) {
            logger.warn('Auth failed: invalid token', { ip: req.ip });
            return res.status(401).json({ ok: false, error: 'Unauthorized' });
        }
    } catch {
        return res.status(401).json({ ok: false, error: 'Unauthorized' });
    }

    next();
}

module.exports = { auth };
