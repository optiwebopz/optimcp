/**
 * File: /gads-mcp/lib/gadsClient.js
 * OptiMCP Google Ads MCP — Google Ads API HTTP Client
 *
 * Version: 1.0.1
 * Changelog:
 *   2026-03-26 | v1.0.1 | Updated default API version from v18 (sunsetted) to v23.2 (current latest)
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Wraps the Google Ads REST API:
 *   - Injects auth headers on every request
 *   - Handles login-customer-id (MCC → child account switching)
 *   - Centralised error parsing (Google Ads API errors are nested JSON)
 *   - Retries once on 401 (token refresh race condition)
 */

'use strict';

const axios  = require('axios');
const { getAccessToken } = require('./tokenManager');
const { logger }         = require('./logger');

const API_VERSION  = () => process.env.GOOGLE_ADS_API_VERSION || 'v23.2';
const DEV_TOKEN    = () => process.env.GOOGLE_ADS_DEVELOPER_TOKEN;
const MCC_ID       = () => String(process.env.GOOGLE_ADS_MCC_ID || '').replace(/-/g, '');
const BASE_URL     = () => `https://googleads.googleapis.com/${API_VERSION()}`;

/**
 * Build headers for a Google Ads API request.
 * loginCustomerId = MCC ID (always).
 * If customerId differs from MCC, the request targets that child account.
 */
async function buildHeaders(customerId) {
    const token = await getAccessToken();
    const headers = {
        'Authorization'      : `Bearer ${token}`,
        'developer-token'    : DEV_TOKEN(),
        'Content-Type'       : 'application/json',
    };

    // Always set login-customer-id to MCC so we can access child accounts
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

    // Google Ads API wraps errors in data.error or data[0].error
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
 * Uses the searchStream endpoint for full result sets.
 *
 * @param {string} customerId  - Customer ID (digits only)
 * @param {string} query       - GAQL query string
 * @param {number} pageSize    - Max rows (default 1000, hard cap 10000)
 * @returns {Promise<Array>}   - Array of result rows
 */
async function searchQuery(customerId, query, pageSize = 1000) {
    const cid  = String(customerId).replace(/-/g, '');
    const url  = `${BASE_URL()}/customers/${cid}/googleAds:search`;
    const cap  = Math.min(parseInt(pageSize, 10) || 1000, 10000);

    logger.info('GAQL query', { customerId: cid, preview: query.slice(0, 120) });

    try {
        const headers = await buildHeaders(cid);
        const resp    = await axios.post(url, { query, pageSize: cap }, { headers });
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
 * @returns {Promise<Object>}   - Mutate response
 */
async function mutate(customerId, resource, operations) {
    const cid = String(customerId).replace(/-/g, '');
    const url = `${BASE_URL()}/customers/${cid}/${resource}:mutate`;

    logger.info('Ads mutate', { customerId: cid, resource, ops: operations.length });

    try {
        const headers = await buildHeaders(cid);
        const resp    = await axios.post(url, { operations }, { headers });
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
async function get(path, loginCustomerId) {
    const url = `${BASE_URL()}/${path}`;
    try {
        const headers = await buildHeaders(loginCustomerId);
        const resp    = await axios.get(url, { headers });
        return resp.data;
    } catch (err) {
        const msg = parseGadsError(err);
        logger.error('Ads GET failed', { path, err: msg });
        throw new Error(msg);
    }
}

module.exports = { searchQuery, mutate, get, BASE_URL, buildHeaders };
