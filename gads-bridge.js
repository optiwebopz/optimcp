// File: /gads-bridge.js
// OptiMCP Google Ads MCP — Claude Desktop Bridge Script
//
// Version: 1.1.0
// Changelog:
//   2026-04-10 | v1.1.0 | Fixed: MCP handshake handled locally (not forwarded to server)
//              |         | Fixed: Request body format changed to { tool, input }
//              |         | Fixed: Auth header changed to x-mcp-token (not Authorization: Bearer)
//   2026-04-01 | v1.0.0 | Initial release
//
// PURPOSE:
//   Claude Desktop only supports stdio MCP transport — it cannot connect directly
//   to HTTP MCP servers. This bridge script translates between the two:
//
//   Claude Desktop (stdio JSON-RPC 2.0) <-> This Bridge <-> OptiMCP HTTP Server
//
// HOW IT WORKS:
//   1. Claude Desktop launches this script via Node.js (configured in claude_desktop_config.json)
//   2. Bridge listens on stdin for MCP JSON-RPC 2.0 messages from Claude Desktop
//   3. On initialize: fetches tool list from server GET /mcp, returns MCP capabilities response
//   4. On tools/list: returns cached tool list (fetched at startup)
//   5. On tools/call: forwards to server as HTTP POST { tool, input } with x-mcp-token auth
//   6. Bridge writes JSON-RPC 2.0 responses back to stdout for Claude Desktop
//
// SETUP:
//   1. Update TOKEN below to match MCP_SECRET_TOKEN in your server .env file
//   2. Update URL if your server IP or port changes
//   3. Add to ~/Library/Application Support/Claude/claude_desktop_config.json (Mac):
//
//      {
//        "mcpServers": {
//          "gads-mcp": {
//            "command": "node",
//            "args": ["/Users/YOUR_USERNAME/Desktop/gads-bridge.js"]
//          }
//        }
//      }
//
//   4. Fully quit Claude Desktop (Cmd+Q) and reopen it
//   5. Click the hammer icon in a new chat — you should see 30 Google Ads tools
//
// IMPORTANT NOTES:
//   - The server expects { tool, input } in POST body — NOT MCP JSON-RPC format
//   - Auth header is x-mcp-token — NOT Authorization: Bearer
//   - MCP initialize/tools/list are handled locally by this bridge
//   - Only tools/call is forwarded to the server
//   - The access token auto-refreshes on the server — no action needed here

'use strict';
const http = require('http');

// ── Config ────────────────────────────────────────────────────────────────────
const URL   = 'http://161.35.124.61:3848/mcp';  // update if server IP changes
const TOKEN = 'YOUR_MCP_SECRET_TOKEN';            // must match MCP_SECRET_TOKEN in server .env

// ── State ─────────────────────────────────────────────────────────────────────
let buf   = '';
let tools = [];

// ── Helpers ───────────────────────────────────────────────────────────────────
function send(obj) {
  process.stdout.write(JSON.stringify(obj) + '\n');
}

// Fetch tool list from server GET /mcp (public endpoint, no auth required)
async function fetchTools() {
  return new Promise((resolve) => {
    const req = http.request(URL, {
      method: 'GET',
      headers: { 'x-mcp-token': TOKEN }
    }, (res) => {
      let data = '';
      res.on('data', c => data += c);
      res.on('end', () => {
        try {
          const json = JSON.parse(data);
          resolve(json.data?.tools || []);
        } catch {
          resolve([]);
        }
      });
    });
    req.on('error', () => resolve([]));
    req.end();
  });
}

// Forward tool call to server as HTTP POST { tool, input }
function callTool(name, args) {
  return new Promise((resolve) => {
    const body = JSON.stringify({ tool: name, input: args });
    const opts = {
      method: 'POST',
      headers: {
        'Content-Type':   'application/json',
        'x-mcp-token':    TOKEN,
        'Content-Length': Buffer.byteLength(body),
      },
    };

    const req = http.request(URL, opts, (res) => {
      let data = '';
      res.on('data', c => data += c);
      res.on('end', () => {
        try {
          const json = JSON.parse(data);
          if (json.data) {
            resolve({ content: [{ type: 'text', text: JSON.stringify(json.data, null, 2) }] });
          } else if (json.result) {
            resolve(json.result);
          } else {
            resolve({ content: [{ type: 'text', text: JSON.stringify(json, null, 2) }] });
          }
        } catch {
          resolve({ content: [{ type: 'text', text: data }] });
        }
      });
    });

    req.on('error', e => {
      resolve({ content: [{ type: 'text', text: 'Bridge error: ' + e.message }] });
    });

    req.write(body);
    req.end();
  });
}

// ── Main: handle MCP messages from Claude Desktop on stdin ────────────────────
process.stdin.setEncoding('utf8');
process.stdin.on('data', async chunk => {
  buf += chunk;
  let nl;
  while ((nl = buf.indexOf('\n')) !== -1) {
    const line = buf.slice(0, nl).trim();
    buf = buf.slice(nl + 1);
    if (!line) continue;

    let msg;
    try { msg = JSON.parse(line); } catch { continue; }

    const { id, method, params } = msg;

    if (method === 'initialize') {
      // Handle MCP handshake locally — fetch tool list from server
      tools = await fetchTools();
      send({
        jsonrpc: '2.0',
        id,
        result: {
          protocolVersion: '2025-11-25',
          capabilities: { tools: {} },
          serverInfo: { name: 'gads-mcp', version: '1.2.0' }
        }
      });

    } else if (method === 'notifications/initialized') {
      // No response needed for notifications

    } else if (method === 'tools/list') {
      send({
        jsonrpc: '2.0',
        id,
        result: {
          tools: tools.map(t => ({
            name:        t.name,
            description: t.description,
            inputSchema: t.inputSchema || { type: 'object', properties: {}, required: [] }
          }))
        }
      });

    } else if (method === 'tools/call') {
      const result = await callTool(params.name, params.arguments || {});
      send({ jsonrpc: '2.0', id, result });

    } else {
      send({ jsonrpc: '2.0', id, error: { code: -32601, message: 'Method not found' } });
    }
  }
});
