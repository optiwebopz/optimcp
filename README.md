# Google Ads MCP — Claude Integration for Google Ads API v23.2

> Control your entire Google Ads account through Claude.
> Pause campaigns, pull reports, add keywords, create ads — all in plain English.
> Self-hosted on your own server. No third-party relay. No data leaves your infrastructure.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-blue)](https://php.net)
[![Node.js](https://img.shields.io/badge/Node.js-18%2B-green)](https://nodejs.org)
[![Google Ads API](https://img.shields.io/badge/Google%20Ads%20API-v23.2-orange)](https://developers.google.com/google-ads/api/docs/start)
[![MCP](https://img.shields.io/badge/MCP-Model%20Context%20Protocol-purple)](https://www.anthropic.com)

**Google Ads MCP** is a self-hosted [Model Context Protocol](https://modelcontextprotocol.io) server that gives Claude direct access to the Google Ads API v23.2. Built by [Opti WebOpz](https://optiwebopz.com).

---

## 💬 What you can say to Claude

Once connected, Claude understands natural language — no IDs required:
```
"Pause all campaigns in My Client Account that are spending over $50/day"
"Show me the performance of campaign Summer Sale for last 30 days"
"Add these 10 keywords to ad group Branded - Exact in campaign Brand UK"
"Create a new Search campaign called Winter Promo with a $30 daily budget"
"Show me search terms that triggered ads in campaign Google Shopping this month"
"What is the CTR on our top 5 campaigns this month?"
"Create a Responsive Search Ad in ad group Homepage with these headlines and descriptions"
"Add these negative keywords to campaign Brand UK"
```

The built-in **Prompt Helper dashboard** lets you browse accounts, campaigns, and ad groups by name — click to copy ready-made prompts.

---

## 📦 Two versions included

| Version | Deploy on | Runtime | Best for |
|---------|-----------|---------|----------|
| **gads-mcp-php/** | Hostinger / SiteGround | PHP 8.0+ | Shared hosting — no SSH needed |
| **gads-mcp/** | DigitalOcean / VPS | Node.js 18+ | Persistent server, live dashboard |

Both versions have **identical tools** — 30 tools across 6 categories. Choose based on your hosting.

---

## 🛠️ 30 Tools — Full Reference

### Account (3 tools)
| Tool | What it does |
|------|-------------|
| `list_accounts` | List all client accounts under your MCC with currency, timezone, status |
| `get_account` | Get full details for a specific account |
| `run_gaql` | Execute any raw GAQL (Google Ads Query Language) query |

### Reporting (6 tools)
| Tool | What it does |
|------|-------------|
| `get_account_summary` | Account-level totals — impressions, clicks, cost, CTR, conversions, ROAS |
| `get_campaign_report` | Campaign performance sorted by cost |
| `get_ad_group_report` | Ad group performance metrics |
| `get_keyword_report` | Keyword metrics with quality scores |
| `get_ad_report` | Ad performance with headline preview |
| `get_search_terms` | Actual search queries that triggered your ads, sorted by clicks |

### Campaigns (8 tools)
| Tool | What it does |
|------|-------------|
| `list_campaigns` | All campaigns with status, budget, bidding strategy |
| `get_campaign` | Full campaign details including target CPA / ROAS |
| `create_campaign` | Create a Search campaign — defaults to PAUSED |
| `update_campaign` | Update campaign name or status |
| `pause_campaign` | Pause a live campaign immediately |
| `enable_campaign` | Enable a paused campaign |
| `remove_campaign` | Permanently remove a campaign |
| `set_campaign_budget` | Update daily budget |

### Ad Groups (6 tools)
| Tool | What it does |
|------|-------------|
| `list_ad_groups` | All ad groups with CPC bids and campaign context |
| `create_ad_group` | Create a new ad group in a campaign |
| `update_ad_group` | Update name, status, or default CPC bid |
| `pause_ad_group` | Pause an ad group |
| `enable_ad_group` | Enable an ad group |
| `remove_ad_group` | Remove an ad group |

### Ads (3 tools)
| Tool | What it does |
|------|-------------|
| `list_ads` | All ads with headlines, descriptions, and status |
| `create_rsa` | Create Responsive Search Ad — defaults to PAUSED |
| `update_ad_status` | Set ad to ENABLED, PAUSED, or REMOVED |

### Keywords (4 tools)
| Tool | What it does |
|------|-------------|
| `add_keywords` | Add BROAD / PHRASE / EXACT keywords with optional CPC bids |
| `update_keyword` | Update keyword status or CPC bid |
| `remove_keyword` | Remove a keyword |
| `add_negative_keywords` | Add negatives at campaign or ad group level |

> ⚠️ All campaign, ad group, and ad **creation defaults to PAUSED**. Nothing spends money until you explicitly enable it.

---

## 🖥️ Dashboard — Prompt Helper

Both versions include a **PIN-protected web dashboard** at `/dashboard/`.

**How the Prompt Helper works:**
1. Select an account from the dropdown — campaigns load automatically with 30-day metrics
2. Click a campaign — ad groups appear
3. Select an ad group — ready-to-copy Claude prompts appear for everything in it
4. Click **Copy** — paste straight into Claude

No IDs. No copy-pasting account numbers. Just names.

---

## ⚙️ Prerequisites — Google Credentials

Before deploying either version, you need these 5 credentials from Google:

### Step 1 — Google Cloud Project
1. Go to [console.cloud.google.com](https://console.cloud.google.com)
2. Create a new project (e.g. "Google Ads MCP")
3. Go to **APIs & Services → Library** → search **Google Ads API** → Enable it

### Step 2 — OAuth 2.0 Credentials
1. Go to **APIs & Services → Credentials → Create Credentials → OAuth 2.0 Client ID**
2. Application type: **Web application**
3. Add Authorised redirect URI:
   - PHP: `https://yourdomain.com/gads-mcp/auth/oauth_setup.php`
   - Node.js: `http://localhost:8080/callback`
4. Click Create → copy **Client ID** and **Client Secret**

### Step 3 — Developer Token
1. Log into your **Google Ads Manager (MCC) account**
2. Click the wrench icon → **API Centre**
3. Copy your **Developer Token**
4. Apply for **Standard Access** if it shows Basic Access (Basic = test accounts only)

### Step 4 — MCC Account ID
- Shown in top right of Google Ads Manager
- Format: `123-456-7890` → remove dashes → `1234567890`

### Step 5 — Refresh Token
Obtained by running the OAuth setup script — covered in each deployment section below.

---

## 🚀 Deployment — PHP (Hostinger / SiteGround)

### File structure after upload
```
public_html/gads-mcp/
├── mcp.php                 ← MCP endpoint
├── config.php              ← Fill in your credentials
├── .htaccess               ← Security + routing
├── auth/
│   └── oauth_setup.php     ← Run once to get refresh token, then DELETE
├── lib/
│   ├── auth.php            ← Token auth (LiteSpeed compatible)
│   ├── oauth.php           ← File-cached token manager
│   ├── gads.php            ← Google Ads REST client
│   ├── response.php        ← JSON helpers
│   └── logger.php
├── tools/                  ← All 30 tools
├── dashboard/
│   └── index.php           ← Web dashboard
└── logs/                   ← Create this + logs/rate/ (chmod 755)
```

### Step-by-step

**1. Upload files**

Upload everything inside `gads-mcp-php/` to `public_html/gads-mcp/` via hPanel File Manager.

**2. Create log folders**

In File Manager, inside `public_html/gads-mcp/` create:
- `logs/` — set permissions to 755
- `logs/rate/` — set permissions to 755

**3. Get your refresh token**

In Google Cloud Console, add this as an Authorised Redirect URI:
```
https://yourdomain.com/gads-mcp/auth/oauth_setup.php
```

Then visit in your browser:
```
https://yourdomain.com/gads-mcp/auth/oauth_setup.php
```

Click **Authorise with Google** → sign in with your MCC account → copy the `GOOGLE_REFRESH_TOKEN` shown on screen.

> 🔴 **Delete `auth/oauth_setup.php` immediately after** — it must not remain accessible.

**4. Fill in config.php**

Open `config.php` in File Manager → Edit:
```php
define('MCP_SECRET_TOKEN',      'your-64-char-random-token');   // openssl rand -hex 32
define('DASHBOARD_PIN',         'your-strong-passphrase');
define('GOOGLE_CLIENT_ID',      'your-client-id.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET',  'your-client-secret');
define('GOOGLE_REFRESH_TOKEN',  'your-refresh-token-from-step-3');
define('GOOGLE_ADS_DEV_TOKEN',  'your-developer-token');
define('GOOGLE_ADS_MCC_ID',     '1234567890');                   // digits only, no dashes
define('GOOGLE_ADS_API_VERSION', 'v23.2');
```

**5. Test the endpoint**

Visit in browser — should return tool manifest JSON:
```
https://yourdomain.com/gads-mcp/mcp.php
```

**6. Open the dashboard**
```
https://yourdomain.com/gads-mcp/dashboard/
```

---

## 🚀 Deployment — Node.js (DigitalOcean / VPS)

### Prerequisites
- Ubuntu 22.04 Droplet (minimum 512MB RAM — $6/mo Basic)
- Domain or subdomain pointing to the Droplet IP
- SSH access

### Step-by-step

**1. Upload to server**
```bash
scp -r gads-mcp/ root@YOUR_IP:/root/gads-mcp
```

**2. SSH in and run setup**
```bash
ssh root@YOUR_IP
cd /root/gads-mcp
chmod +x setup-digitalocean.sh
./setup-digitalocean.sh
```

This installs Node.js 20, PM2, creates log directories, and starts the server.

**3. Get your refresh token**
```bash
npm run auth
```

Open the printed URL in your browser → sign in with your MCC account → paste the code back → copy the `GOOGLE_REFRESH_TOKEN` printed in terminal.

**4. Fill in .env**
```bash
cp .env.example .env
nano .env
```
```env
MCP_SECRET_TOKEN=your-64-char-random-token
DASHBOARD_PIN=your-strong-passphrase
GOOGLE_CLIENT_ID=your-client-id
GOOGLE_CLIENT_SECRET=your-client-secret
GOOGLE_REFRESH_TOKEN=your-refresh-token
GOOGLE_ADS_DEV_TOKEN=your-developer-token
GOOGLE_ADS_MCC_ID=1234567890
GOOGLE_ADS_API_VERSION=v23.2
PORT=3848
```

**5. Start with PM2**
```bash
pm2 start ecosystem.config.js
pm2 save
pm2 startup
# Run the command it prints
```

**6. Set up nginx + SSL**
```bash
cp nginx/gads-mcp.conf /etc/nginx/sites-available/
nano /etc/nginx/sites-available/gads-mcp.conf   # replace YOUR_DOMAIN
ln -s /etc/nginx/sites-available/gads-mcp.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
certbot --nginx -d gads-mcp.yourdomain.com
```

**7. Test**
```bash
curl https://gads-mcp.yourdomain.com/mcp
```

**8. Open dashboard**
```
https://gads-mcp.yourdomain.com/dashboard
```

---

## 🔗 Connecting Claude

### Claude Desktop (Mac / Windows)

Open `~/Library/Application Support/Claude/claude_desktop_config.json` (Mac) or `%APPDATA%\Claude\claude_desktop_config.json` (Windows):
```json
{
  "mcpServers": {
    "gads-mcp": {
      "transport": {
        "type": "http",
        "url": "https://yourdomain.com/gads-mcp/mcp.php",
        "headers": {
          "X-MCP-Token": "YOUR_MCP_SECRET_TOKEN"
        }
      }
    }
  }
}
```

For DigitalOcean change the URL to `https://gads-mcp.yourdomain.com/mcp`.

Save → quit Claude Desktop completely → reopen.

### Claude Code (Mac / Linux)
```bash
claude mcp add --transport http gads-mcp \
  https://yourdomain.com/gads-mcp/mcp.php \
  --header "Authorization: Bearer YOUR_MCP_SECRET_TOKEN"
```

### Claude Code (Windows — bridge required)

Claude Code on Windows has a header issue with HTTP transport. Use the stdio bridge:

1. Download `docs/optimcp-bridge.js` → save to `C:\Users\YourName\gads-bridge.js`
2. Update `TOKEN` and `HOSTNAME` at the top of the file
3. Add to `.claude.json`:
```json
{
  "mcpServers": {
    "gads-mcp": {
      "command": "node",
      "args": ["C:\\Users\\YourName\\gads-bridge.js"]
    }
  }
}
```

---

## 🔒 Security

| Control | Detail |
|---------|--------|
| Auth | `X-MCP-Token` and `Authorization: Bearer` both supported |
| LiteSpeed fix | `getallheaders()` bypasses Hostinger header stripping |
| GET is public | Tool manifest requires no token (Claude Code health check) |
| POST protected | All 30 tool calls require valid token |
| File-safe | No file system access — Google Ads API only |
| Rate limiting | 60 req/60s per IP on both versions |
| Error handling | `error_reporting(0)` prevents PHP warnings corrupting JSON |
| Dashboard | Separate PIN, timing-safe comparison, session-based (PHP) |
| Credentials | Never returned in tool responses — Google tokens stay server-side |
| PAUSED defaults | All create operations default to PAUSED — nothing spends accidentally |

---

## 📋 Go-live checklist

- [ ] All 5 Google credentials filled in — no placeholder values
- [ ] MCC ID is digits only — no dashes (`123-456-7890` → `1234567890`)
- [ ] Developer token is **Standard Access** (Basic = test accounts only)
- [ ] `GOOGLE_ADS_API_VERSION` = `v23.2`
- [ ] `MCP_SECRET_TOKEN` is 64 chars (`openssl rand -hex 32`)
- [ ] `DASHBOARD_PIN` is set — dashboard loads and shows PIN screen
- [ ] Dashboard OAuth panel shows **Connected** with token expiry
- [ ] `oauth_setup.php` deleted from server
- [ ] GET endpoint test returns tool manifest with 30 tools
- [ ] `claude mcp list` shows ✓ Connected
- [ ] "List all accounts" works in Claude

---

## 🏗️ Built by

**[Opti WebOpz](https://optiwebopz.com)** — Full-service digital agency specializing in WordPress, WooCommerce, and AI-powered automation.

**Contributor:** Muhammad Shaheer Mustafa

---

## 📄 License

MIT — free to use, modify, and distribute. See [LICENSE](LICENSE).

---

*Part of the OptiMCP suite — self-hosted MCP servers for Claude.*
