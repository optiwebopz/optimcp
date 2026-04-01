// File: /gads-mcp/lib/gadsClient.js
// OptiMCP Google Ads MCP — Google Ads API HTTP Client
//
// Version: 1.1.0
// Changelog:
//   2026-04-01 | v1.1.0 | SECURITY/STABILITY FIX: Added 30s timeout to all axios
//              |         | requests (searchQuery, mutate, get) to prevent hanging
//              |         | requests from blocking the event loop indefinitely.
//   2026-03-26 | v1.0.1 | Updated default API version to v23.2 (current latest)
//   2026-03-26 | v1.0.0 | Initial release
//
// Wraps the Google Ads REST API:
//   - Injects auth headers on every request
//   - Handles login-customer-id (MCC → child account switching)
//   - Centralised error parsing (Google Ads API errors are nested JSON)
//   - 30s timeout on all outbound requests

'use strict';

const axios  = require('axios');
const { getAccessToken } = require('./tokenManager');
const { logger }         = require('./logger');

const API_VERSION = () => process.env.GOOGLE_ADS_API_VERSION || 'v23.2';
const DEV_TOKEN   = () => process.env.GOOGLE_ADS_DEVELOPER_TOKEN;
const MCC_ID      = () => String(process.env.GOOGLE_ADS_MCC_ID || '').replace(/-/g, '');
const BASE_URL    = () => `https://googleads.googleapis.com/${API_VERSION()}`;

// All outbound requests time out after 30 seconds
const REQUEST_TIMEOUT_MS = 30_000;

/**
 * Build headers for a Google Ads API request.
 * loginCustomerId = MCC ID (always).
 */
async function buildHeaders(customerId) {
    const token = await getAccessToken();
    const headers = {
        'Authorization'  : `Bearer ${token}`,
        'developer-token': DEV_TOKEN(),
        'Content-Type'   : 'application/json',
    };

    const mcc = MCC_ID();
    if (mcc) headers['login-customer-id'] = mcc;

    return headers;
}

/**
 * Parse Google Ads API error response into a readable message.
 */
function parseGadsError(err) {
    const data = err.response?.data;
    if (!data) return err.message;

    const errObj = data.error || (Array.isArray(data) && data[0]?.error);
    if (errObj) {
        const details = errObj.details?.[0]?.errors?.[0];
        const msg     = details
            ? `${details.errorCode ? JSON.stringify(details.errorCode) : ''} ${details.message || ''}`
            : errObj.message;
        return `Google Ads API error ${errObj.code}: ${msg}`.trim();
    }

    return JSON.stringify(data).slice(0, 300);
}

/**
 * Execute a GAQL search query against a customer account.
 *
 * @param {string} customerId  - Customer ID (digits only)
 * @param {string} query       - GAQL query string
 * @param {number} pageSize    - Max rows (default 1000, hard cap 10000)
 * @returns {Promise<Array>}
 */
async function searchQuery(customerId, query, pageSize = 1000) {
    const cid = String(customerId).replace(/-/g, '');
    const url = `${BASE_URL()}/customers/${cid}/googleAds:search`;
    const cap = Math.min(parseInt(pageSize, 10) || 1000, 10000);

    logger.info('GAQL query', { customerId: cid, preview: query.slice(0, 120) });

    try {
        const headers = await buildHeaders(cid);
        const resp    = await axios.post(
            url,
            { query, pageSize: cap },
            { headers, timeout: REQUEST_TIMEOUT_MS }
        );
        return resp.data.results || [];
    } catch (err) {
        const msg = parseGadsError(err);
        logger.error('GAQL search failed', { customerId: cid, err: msg });
        throw new Error(msg);
    }
}

/**
 * Generic mutate call — create, update, or remove resources.
 *
 * @param {string} customerId   - Customer ID (digits only)
 * @param {string} resource     - Resource name e.g. 'campaigns', 'adGroups'
 * @param {Array}  operations   - Array of operation objects
 * @returns {Promise<Object>}
 */
async function mutate(customerId, resource, operations) {
    const cid = String(customerId).replace(/-/g, '');
    const url = `${BASE_URL()}/customers/${cid}/${resource}:mutate`;

    logger.info('Ads mutate', { customerId: cid, resource, ops: operations.length });

    try {
        const headers = await buildHeaders(cid);
        const resp    = await axios.post(
            url,
            { operations },
            { headers, timeout: REQUEST_TIMEOUT_MS }
        );
        return resp.data;
    } catch (err) {
        const msg = parseGadsError(err);
        logger.error('Ads mutate failed', { customerId: cid, resource, err: msg });
        throw new Error(msg);
    }
}

/**
 * GET request for resource list endpoints (e.g. accessible customers).
 */
async function get(resourcePath, loginCustomerId) {
    const url = `${BASE_URL()}/${resourcePath}`;
    try {
        const headers = await buildHeaders(loginCustomerId);
        const resp    = await axios.get(url, { headers, timeout: REQUEST_TIMEOUT_MS });
        return resp.data;
    } catch (err) {
        const msg = parseGadsError(err);
        logger.error('Ads GET failed', { path: resourcePath, err: msg });
        throw new Error(msg);
    }
}

module.exports = { searchQuery, mutate, get, BASE_URL, buildHeaders };
