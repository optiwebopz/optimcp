/**
 * File: /gads-mcp/auth/oauth-setup.js
 * OptiMCP Google Ads MCP — One-time OAuth Setup Script
 *
 * Version: 1.0.0
 * Changelog:
 *   2026-03-26 | v1.0.0 | Initial release
 *
 * Run ONCE to generate your refresh token:
 *   node auth/oauth-setup.js
 *
 * Prerequisites:
 *   1. Google Cloud project created
 *   2. Google Ads API enabled in the project
 *   3. OAuth 2.0 credentials created (type: Desktop App)
 *   4. GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET in .env
 *
 * What this does:
 *   1. Generates an authorization URL
 *   2. You visit it, grant access, get a code
 *   3. You paste the code here
 *   4. Script exchanges it for tokens and prints the refresh token
 *   5. You paste the refresh token into .env as GOOGLE_REFRESH_TOKEN
 */

'use strict';

require('dotenv').config({ path: require('path').join(__dirname, '../.env') });

const https    = require('https');
const readline = require('readline');

const CLIENT_ID     = process.env.GOOGLE_CLIENT_ID;
const CLIENT_SECRET = process.env.GOOGLE_CLIENT_SECRET;
const REDIRECT_URI  = 'urn:ietf:wg:oauth:2.0:oob'; // Desktop app flow — no redirect server needed

if (!CLIENT_ID || !CLIENT_SECRET ||
    CLIENT_ID === 'YOUR_OAUTH_CLIENT_ID.apps.googleusercontent.com') {
    console.error('\n❌  GOOGLE_CLIENT_ID and GOOGLE_CLIENT_SECRET must be set in .env first.\n');
    process.exit(1);
}

const SCOPES = [
    'https://www.googleapis.com/auth/adwords',
].join(' ');

const authUrl = `https://accounts.google.com/o/oauth2/auth?` +
    `client_id=${encodeURIComponent(CLIENT_ID)}` +
    `&redirect_uri=${encodeURIComponent(REDIRECT_URI)}` +
    `&response_type=code` +
    `&scope=${encodeURIComponent(SCOPES)}` +
    `&access_type=offline` +
    `&prompt=consent`;

const rl = readline.createInterface({ input: process.stdin, output: process.stdout });

console.log('\n══════════════════════════════════════════════════════');
console.log(' OptiMCP Google Ads — OAuth Setup');
console.log('══════════════════════════════════════════════════════\n');
console.log('Step 1: Open this URL in your browser:\n');
console.log(authUrl);
console.log('\nStep 2: Sign in with your Google Ads Manager account.');
console.log('Step 3: Grant access. Google will show you a code.\n');

rl.question('Step 4: Paste the code here and press Enter:\n> ', (code) => {
    code = code.trim();
    if (!code) {
        console.error('No code provided.');
        process.exit(1);
    }

    const postData = new URLSearchParams({
        code,
        client_id    : CLIENT_ID,
        client_secret: CLIENT_SECRET,
        redirect_uri : REDIRECT_URI,
        grant_type   : 'authorization_code',
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
        res.on('data', chunk => body += chunk);
        res.on('end', () => {
            let json;
            try { json = JSON.parse(body); } catch {
                console.error('Failed to parse response:', body);
                process.exit(1);
            }

            if (json.error) {
                console.error('\n❌  Token exchange failed:', json.error, json.error_description || '');
                process.exit(1);
            }

            console.log('\n══════════════════════════════════════════════════════');
            console.log(' ✅  Success! Copy this into your .env file:');
            console.log('══════════════════════════════════════════════════════\n');
            console.log(`GOOGLE_REFRESH_TOKEN=${json.refresh_token}\n`);
            console.log('Access token (for testing only — expires in 1hr):');
            console.log(json.access_token);
            console.log('\n══════════════════════════════════════════════════════\n');
            rl.close();
        });
    });

    req.on('error', (e) => {
        console.error('Request failed:', e.message);
        process.exit(1);
    });

    req.write(postData);
    req.end();
});
