/**
 * File: /gads-mcp/lib/tokenManager.js
 * OptiMCP Google Ads MCP — OAuth Token Manager
 *
 * Version: 1.0.0
 * Changelog:
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Manages access token lifecycle:
 *   - Caches access token in memory
 *   - Auto-refreshes when expired (or within 5 min of expiry)
 *   - Uses refresh token from .env — never expires
 *   - All callers await getAccessToken() — always gets a valid token
 */

'use strict';

const https  = require('https');
const { logger } = require('./logger');

const CLIENT_ID     = () => process.env.GOOGLE_CLIENT_ID;
const CLIENT_SECRET = () => process.env.GOOGLE_CLIENT_SECRET;
const REFRESH_TOKEN = () => process.env.GOOGLE_REFRESH_TOKEN;

// In-memory token cache
let _accessToken  = null;
let _expiresAt    = 0; // unix ms

/**
 * Returns a valid access token, refreshing if needed.
 * @returns {Promise<string>}
 */
async function getAccessToken() {
    const now = Date.now();

    // Return cached token if still valid with 5-min buffer
    if (_accessToken && _expiresAt - now > 5 * 60 * 1000) {
        return _accessToken;
    }

    logger.info('Refreshing Google access token');
    const tokens = await refreshAccessToken();
    _accessToken = tokens.access_token;
    // expires_in is in seconds
    _expiresAt   = now + (tokens.expires_in * 1000);
    logger.info('Access token refreshed', { expires_in_sec: tokens.expires_in });
    return _accessToken;
}

/**
 * Exchange refresh token for a new access token via Google OAuth endpoint.
 */
function refreshAccessToken() {
    return new Promise((resolve, reject) => {
        const postData = new URLSearchParams({
            client_id    : CLIENT_ID(),
            client_secret: CLIENT_SECRET(),
            refresh_token: REFRESH_TOKEN(),
            grant_type   : 'refresh_token',
        }).toString();

        const options = {
            hostname: 'oauth2.googleapis.com',
            path    : '/token',
            method  : 'POST',
            headers : {
                'Content-Type'  : 'application/x-www-form-urlencoded',
                'Content-Length': Buffer.byteLength(postData),
            },
        };

        const req = https.request(options, (res) => {
            let body = '';
            res.on('data', c => body += c);
            res.on('end', () => {
                let json;
                try { json = JSON.parse(body); } catch {
                    return reject(new Error('Token parse failed'));
                }
                if (json.error) {
                    logger.error('Token refresh failed', { error: json.error });
                    return reject(new Error(`Token refresh: ${json.error} — ${json.error_description || ''}`));
                }
                resolve(json);
            });
        });

        req.on('error', (e) => {
            logger.error('Token refresh request error', { err: e.message });
            reject(e);
        });
        req.write(postData);
        req.end();
    });
}

/**
 * Returns the current token status without triggering a refresh.
 * Used by the dashboard to show expiry countdown.
 */
function getStatus() {
    const now = Date.now();
    const valid = !!(_accessToken && _expiresAt - now > 0);
    const expiresInSeconds = valid ? Math.floor((_expiresAt - now) / 1000) : null;
    return {
        valid,
        expiresInSeconds,
        lastRefresh: _expiresAt
            ? new Date(_expiresAt - 3600 * 1000).toLocaleTimeString()
            : null,
    };
}

module.exports = { getAccessToken, getStatus };
