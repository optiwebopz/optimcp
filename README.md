# OptiMCP — Self-hosted MCP Servers for Claude

> Connect Claude to your WordPress site, MySQL database, and Google Ads account.
> Works on Hostinger, SiteGround, and DigitalOcean. No third-party relay. No data leaves your servers.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-blue)](https://php.net)
[![Node.js](https://img.shields.io/badge/Node.js-18%2B-green)](https://nodejs.org)
[![Google Ads API](https://img.shields.io/badge/Google%20Ads%20API-v23.2-orange)](https://developers.google.com/google-ads/api/docs/start)

**OptiMCP** is a suite of self-hosted **MCP (Model Context Protocol) servers** built by [Opti Webopz](https://optiwebopz.com). Give Claude direct, secure access to your hosting infrastructure and Google Ads accounts — all running on your own servers.

---

## ✨ What Claude can do with OptiMCP

- **Read, write, and manage files** on your WordPress hosting
- **Query your MySQL / WordPress database** — analyze WooCommerce orders, check options, run custom reports
- **Execute PHP in WordPress context** — run WP_Query, get_option, update_post_meta directly via Claude
- **Manage Google Ads campaigns** — pause, create, budget, report across all MCC accounts via API v23.2
- **Browse campaigns by name** — built-in dashboard Prompt Helper generates ready-to-copy Claude prompts

---

## 📦 What's included

| Server | Platform | Runtime | Tools |
|--------|----------|---------|-------|
| **OptiMCP Files+DB** | Hostinger / SiteGround | PHP 8.0+ | 9 tools |
| **OptiMCP Files+DB** | DigitalOcean | Node.js 18+ | 9 tools |
| **Google Ads MCP** | Hostinger / SiteGround | PHP 8.0+ | 30 tools |
| **Google Ads MCP** | DigitalOcean | Node.js 18+ | 30 tools |

All servers include a **PIN-protected web dashboard** with OAuth status, campaign browser, Prompt Helper, and token rotation.

---

## 🚀 Quick start — Hostinger / SiteGround (PHP)

1. Upload `optimcp-hostinger/` contents to `public_html/mcp/`
2. Create `logs/` and `logs/rate/` folders (chmod 755)
3. Edit `config.php` — set `MCP_SECRET_TOKEN`, `DASHBOARD_PIN`, `MCP_ALLOWED_ROOT`, DB credentials
4. Visit `https://yourdomain.com/mcp/mcp.php` — should return tool manifest JSON
5. Add to Claude Desktop config:
```json
{
  "mcpServers": {
    "optimcp": {
      "transport": {
        "type": "http",
        "url": "https://yourdomain.com/mcp/mcp.php",
        "headers": { "X-MCP-Token": "YOUR_64_CHAR_TOKEN" }
      }
    }
  }
}
```

---

## 🚀 Quick start — DigitalOcean (Node.js)
```bash
scp -r optimcp-digitalocean/ root@YOUR_IP:/root/optimcp
ssh root@YOUR_IP
cd /root/optimcp
chmod +x setup-digitalocean.sh && ./setup-digitalocean.sh
cp .env.example .env && nano .env
pm2 start ecosystem.config.js && pm2 save && pm2 startup
```

---

## 📊 Google Ads MCP — 30 tools across 6 categories

| Category | Tools |
|----------|-------|
| Account | `list_accounts` `get_account` `run_gaql` |
| Reporting | `get_account_summary` `get_campaign_report` `get_ad_group_report` `get_keyword_report` `get_ad_report` `get_search_terms` |
| Campaigns | `list_campaigns` `get_campaign` `create_campaign` `update_campaign` `pause_campaign` `enable_campaign` `remove_campaign` `set_campaign_budget` |
| Ad Groups | `list_ad_groups` `create_ad_group` `update_ad_group` `pause_ad_group` `enable_ad_group` `remove_ad_group` |
| Ads | `list_ads` `create_rsa` `update_ad_status` |
| Keywords | `add_keywords` `update_keyword` `remove_keyword` `add_negative_keywords` |

Supports Google Ads API **v23.2** (current latest as of March 2026).
All campaign/ad creation defaults to **PAUSED** — nothing spends until explicitly enabled.

---

## 🖥️ Dashboard — Prompt Helper

The built-in dashboard lets you browse your Google Ads accounts by name — no IDs needed.

**Select account → campaigns load with 30-day metrics → select campaign → ad groups appear → copy Claude prompt**

Example generated prompts:
- *"Pause campaign "Summer Sale 2026" in "My Client Account""*
- *"Show me the search terms report for campaign "Brand UK" this month"*
- *"Add keywords to ad group "Exact Match - London" in campaign "Brand UK""*

---

## 🔒 Security

| Control | Implementation |
|---------|----------------|
| Token auth | `getallheaders()` on PHP — bypasses LiteSpeed header stripping on Hostinger |
| Header support | `X-MCP-Token` and `Authorization: Bearer` both accepted |
| GET is public | Tool manifest requires no token (Claude Code health check) — POST requires auth |
| File sandbox | All paths checked against `MCP_ALLOWED_ROOT` via `realpath()` |
| SQL injection | PDO prepared statements on all queries |
| Rate limiting | 120 req/60s PHP · 60 req/60s Node.js |
| Error handling | `error_reporting(0)` prevents PHP warnings corrupting JSON |
| Dashboard | Separate PIN, session-based (PHP) or header-based (Node.js), timing-safe comparison |

---

## 📁 Repository structure
```
optimcp/
├── optimcp-hostinger/     # OptiMCP Files+DB — PHP (Hostinger/SiteGround)
├── optimcp-digitalocean/  # OptiMCP Files+DB — Node.js (DigitalOcean)
├── gads-mcp-php/          # Google Ads MCP — PHP (Hostinger/SiteGround)
├── gads-mcp/              # Google Ads MCP — Node.js (DigitalOcean)
└── docs/
    └── optimcp-bridge.js  # Windows Claude Code bridge script
```

---

## 🪟 Windows Claude Code Bridge

Claude Code on Windows requires a bridge script due to a header-passing limitation in the HTTP transport. The bridge implements MCP JSON-RPC locally via stdio and forwards all calls to your PHP server over HTTPS.
```json
{
  "mcpServers": {
    "optimcp": {
      "command": "node",
      "args": ["C:\\Users\\YourName\\optimcp-bridge.js"]
    }
  }
}
```

See `docs/optimcp-bridge.js` — update the `TOKEN` and `HOSTNAME` constants before use.

---

## 🏗️ Built by

**[Opti Webopz](https://optiwebopz.com)** — Full-service digital agency specializing in WordPress, WooCommerce, and AI-powered automation.

**Contributors:**
- Muhammad Zumair Qureshi
- M. Shaheer Mustafa

---

## 📄 License

MIT — free to use, modify, and distribute. See [LICENSE](LICENSE).

---

*Built for the [Claude MCP ecosystem](https://www.anthropic.com/claude) by Opti Webopz.*
