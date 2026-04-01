# Google Ads MCP — Claude Integration for Google Ads API v23.2

> Control your entire Google Ads account through Claude — in plain English.
> Pause campaigns, pull reports, add keywords, create ads. With built-in permission controls so nothing runs without your approval.
> Self-hosted on your own server. No third-party relay. No data leaves your infrastructure.

[![License: MIT](https://img.shields.io/badge/License-MIT-blue.svg)](LICENSE)
[![PHP](https://img.shields.io/badge/PHP-8.0%2B-blue)](https://php.net)
[![Node.js](https://img.shields.io/badge/Node.js-18%2B-green)](https://nodejs.org)
[![Google Ads API](https://img.shields.io/badge/Google%20Ads%20API-v23.2-orange)](https://developers.google.com/google-ads/api/docs/start)
[![MCP](https://img.shields.io/badge/MCP-Model%20Context%20Protocol-purple)](https://modelcontextprotocol.io)

**Google Ads MCP** is a self-hosted [Model Context Protocol](https://modelcontextprotocol.io) server that gives Claude direct, secure access to the Google Ads API v23.2. Built by [Opti Webopz](https://optiwebopz.com).

---

## 💬 What you can say to Claude

Once connected, Claude understands natural language — no IDs required:

```
"Pause all campaigns spending over $50/day in My Client Account"
"Show me the performance of campaign Summer Sale for last 30 days"
"Add these 10 keywords to ad group Branded - Exact in campaign Brand UK"
"Create a new Search campaign called Winter Promo with a $30 daily budget"
"Show me search terms that triggered ads in campaign Google Shopping this month"
"What is the CTR on our top 5 campaigns this month?"
"Create a Responsive Search Ad in ad group Homepage with these headlines and descriptions"
```

The built-in **Prompt Helper dashboard** lets you browse accounts, campaigns, and ad groups by name — click any prompt to copy it straight into Claude. No IDs, no copy-pasting.

---

## 🔐 Permission Controls — New in v2.1.0

Before Claude executes any write operation, the server checks your permission settings. You control exactly what Claude is allowed to do — from the dashboard, with a single toggle per tool.

**How it works:**
- **Read tools are always on** — list, get, and all report tools cannot be disabled
- **Write tools are individually toggleable** — 17 write/mutate tools, each with its own on/off switch
- **Danger tools highlighted** — remove_campaign, remove_ad_group, remove_keyword shown in red
- **When a tool is disabled** — Claude receives a clear message: *"Tool 'remove_campaign' is currently disabled. Enable it in the dashboard under Permission Controls before asking Claude to use it."*
- **Claude will not attempt workarounds** — it will explain the tool is off and ask you to enable it

| Always on (read-only) | Toggleable (write) |
|-----------------------|--------------------|
| list_accounts, get_account, run_gaql | create_campaign, update_campaign |
| get_account_summary, get_campaign_report | pause_campaign, enable_campaign |
| get_ad_group_report, get_keyword_report | set_campaign_budget, **remove_campaign** ⚠️ |
| get_ad_report, get_search_terms | create_ad_group, update_ad_group |
| list_campaigns, get_campaign | pause_ad_group, enable_ad_group |
| list_ad_groups, list_ads | **remove_ad_group** ⚠️, create_rsa |
| | update_ad_status, add_keywords |
| | update_keyword, **remove_keyword** ⚠️ |
| | add_negative_keywords |

> ⚠️ Danger tools (remove operations) are highlighted in the dashboard and can be disabled independently.

---

## 📦 Two versions included

| Version | Deploy on | Runtime | Best for |
|---------|-----------|---------|----------|
| **gads-mcp-php/** | Hostinger / SiteGround | PHP 8.0+ | Shared hosting — no SSH needed |
| **gads-mcp/** | DigitalOcean / VPS | Node.js 18+ | Persistent server, live dashboard |

Both versions have **identical tools and identical permission controls** — 30 tools, 17 of which are individually toggleable.

---

## 🛠️ 30 Tools — Full Reference

### Account (3 tools — always on)
| Tool | What it does |
|------|-------------|
| `list_accounts` | List all client accounts under your MCC with currency, timezone, status |
| `get_account` | Get full details for a specific account |
| `run_gaql` | Execute any raw GAQL (Google Ads Query Language) query |

### Reporting (6 tools — always on)
| Tool | What it does |
|------|-------------|
| `get_account_summary` | Account-level totals — impressions, clicks, cost, CTR, conversions, ROAS |
| `get_campaign_report` | Campaign performance sorted by cost |
| `get_ad_group_report` | Ad group performance metrics |
| `get_keyword_report` | Keyword metrics with quality scores |
| `get_ad_report` | Ad performance with headline preview |
| `get_search_terms` | Actual search queries that triggered your ads |

### Campaigns (8 tools — 6 toggleable)
| Tool | Toggleable | What it does |
|------|-----------|-------------|
| `list_campaigns` | No | All campaigns with status, budget, bidding |
| `get_campaign` | No | Full campaign details |
| `create_campaign` | ✅ Yes | Create a Search campaign — defaults to PAUSED |
| `update_campaign` | ✅ Yes | Update campaign name or status |
| `pause_campaign` | ✅ Yes | Pause a live campaign immediately |
| `enable_campaign` | ✅ Yes | Enable a paused campaign |
| `set_campaign_budget` | ✅ Yes | Update daily budget |
| `remove_campaign` | ✅ Yes ⚠️ | Permanently remove a campaign |

### Ad Groups (6 tools — 5 toggleable)
| Tool | Toggleable | What it does |
|------|-----------|-------------|
| `list_ad_groups` | No | All ad groups with CPC bids |
| `create_ad_group` | ✅ Yes | Create a new ad group |
| `update_ad_group` | ✅ Yes | Update name, status, or CPC bid |
| `pause_ad_group` | ✅ Yes | Pause an ad group |
| `enable_ad_group` | ✅ Yes | Enable an ad group |
| `remove_ad_group` | ✅ Yes ⚠️ | Remove an ad group |

### Ads (3 tools — 2 toggleable)
| Tool | Toggleable | What it does |
|------|-----------|-------------|
| `list_ads` | No | All ads with headlines, descriptions, status |
| `create_rsa` | ✅ Yes | Create Responsive Search Ad — defaults to PAUSED |
| `update_ad_status` | ✅ Yes | Set ad to ENABLED, PAUSED, or REMOVED |

### Keywords (4 tools — all toggleable)
| Tool | Toggleable | What it does |
|------|-----------|-------------|
| `add_keywords` | ✅ Yes | Add BROAD / PHRASE / EXACT keywords |
| `update_keyword` | ✅ Yes | Update keyword status or CPC bid |
| `remove_keyword` | ✅ Yes ⚠️ | Remove a keyword |
| `add_negative_keywords` | ✅ Yes | Add negatives at campaign or ad group level |

> ⚠️ All campaign, ad group, and ad **creation defaults to PAUSED**. Nothing spends money until you explicitly enable it.

---

## 🖥️ Dashboard — What's inside

Both versions include a **PIN-protected web dashboard**:

| Panel | What it does |
|-------|-------------|
| OAuth connection | Token status, expiry ring, last refresh time |
| API version status | Running version vs latest, sunset date warning |
| **Permission controls** | Per-tool on/off toggles — 17 write tools, danger tools highlighted |
| **Prompt Helper** | Browse accounts → campaigns → ad groups by name, copy Claude prompts |
| Campaign table | 30-day metrics per campaign — impressions, clicks, cost, CTR, conversions |
| MCP token rotation | Generate and save new token |
| Tool call stats | 24h totals, error count, most-used tool |
| Recent log | Last 50 tool calls with timestamp and status |

---

## ⚙️ Prerequisites — Google Credentials

You need 5 credentials before deploying. Get them in this order:

### Step 1 — Google Cloud Project
1. Go to [console.cloud.google.com](https://console.cloud.google.com)
2. Create a new project — name it "Google Ads MCP" or similar
3. Go to **APIs & Services → Library** → search **Google Ads API** → click **Enable**

### Step 2 — OAuth 2.0 Credentials
1. Go to **APIs & Services → Credentials → Create Credentials → OAuth 2.0 Client ID**
2. If prompted for consent screen: User Type = External → fill App name → Save and Continue
3. Application type: **Web application**
4. Add Authorised redirect URI:
   - PHP version: `https://yourdomain.com/gads-mcp/auth/oauth_setup.php`
   - Node.js version: `http://localhost:8080/callback`
5. Click Create → copy **Client ID** and **Client Secret**

### Step 3 — Developer Token
1. Log into **Google Ads Manager (MCC) account**
2. Click the wrench icon (Tools) → **API Centre**
3. Copy your **Developer Token**
4. Apply for **Standard Access** if it shows Basic Access — Basic only works on test accounts

### Step 4 — MCC Account ID
- Shown top-right of Google Ads Manager (format: `123-456-7890`)
- Remove the dashes → `1234567890` — this is your `GOOGLE_ADS_MCC_ID`

### Step 5 — Refresh Token
Obtained by running the OAuth setup script — covered in each deployment section below.

---

## 🚀 Deployment — PHP (Hostinger / SiteGround)

### File structure
```
public_html/gads-mcp/
├── mcp.php                 ← MCP endpoint (GET public, POST requires auth)
├── config.php              ← Fill in your credentials here
├── .htaccess               ← Security + routing rules
├── auth/
│   └── oauth_setup.php     ← Run ONCE to get refresh token, then DELETE
├── lib/
│   ├── auth.php            ← Token auth — LiteSpeed/Hostinger compatible
│   ├── permissions.php     ← Per-tool permission manager
│   ├── oauth.php           ← File-cached OAuth token manager
│   ├── gads.php            ← Google Ads REST API v23.2 client
│   ├── response.php        ← JSON helpers + tool manifest
│   └── logger.php          ← File logger
├── tools/                  ← 6 tool files (all 30 tools)
├── dashboard/
│   └── index.php           ← Full dashboard with permission controls
└── logs/                   ← YOU MUST CREATE THIS + logs/rate/ (chmod 755)
    ├── .permissions.json   ← Auto-created on first permission save
    └── gads-mcp.log        ← Auto-created on first tool call
```

### Step-by-step

**1. Upload files**

In Hostinger hPanel → File Manager, create folder `public_html/gads-mcp/`. Upload all contents of `gads-mcp-php/` into it.

**2. Create log folders**

Inside `public_html/gads-mcp/`, create:
- `logs/` — set permissions to **755**
- `logs/rate/` — set permissions to **755**

**3. Get your refresh token**

In Google Cloud Console, add this Authorised Redirect URI:
```
https://yourdomain.com/gads-mcp/auth/oauth_setup.php
```

Visit in browser:
```
https://yourdomain.com/gads-mcp/auth/oauth_setup.php
```

Click **Authorise with Google** → sign in with your MCC account → copy the `GOOGLE_REFRESH_TOKEN` shown on screen.

> 🔴 **Delete `auth/oauth_setup.php` immediately after copying the token.** In File Manager: select it → Delete.

**4. Fill in config.php**

In File Manager, right-click `config.php` → Edit:

```php
define('MCP_SECRET_TOKEN',       'YOUR_64_CHAR_TOKEN');        // openssl rand -hex 32
define('DASHBOARD_PIN',          'YOUR_STRONG_PASSPHRASE');
define('GOOGLE_CLIENT_ID',       'xxxxx.apps.googleusercontent.com');
define('GOOGLE_CLIENT_SECRET',   'GOCSPX-xxxxx');
define('GOOGLE_REFRESH_TOKEN',   '1//xxxxxxxx');               // from step 3
define('GOOGLE_ADS_DEV_TOKEN',   'xxxxx');
define('GOOGLE_ADS_MCC_ID',      '1234567890');                // digits only, no dashes
define('GOOGLE_ADS_API_VERSION', 'v23.2');
```

**5. Test the endpoint**

Visit in browser — should return tool manifest JSON with 30 tools listed:
```
https://yourdomain.com/gads-mcp/mcp.php
```

**6. Open dashboard and configure permissions**
```
https://yourdomain.com/gads-mcp/dashboard/
```

Enter your `DASHBOARD_PIN`. Go to **Permission Controls** → toggle off any tools you don't want Claude to use. Changes save instantly.

---

## 🚀 Deployment — Node.js (DigitalOcean / VPS)

### Prerequisites
- Ubuntu 22.04 Droplet — minimum 512MB RAM ($6/mo Basic is fine)
- Domain or subdomain pointing to your Droplet IP
- SSH access

### Step-by-step

**1. Upload files to server**
```bash
scp -r gads-mcp/ root@YOUR_DROPLET_IP:/root/gads-mcp
```

**2. SSH in and run setup script**
```bash
ssh root@YOUR_DROPLET_IP
cd /root/gads-mcp
chmod +x setup-digitalocean.sh
./setup-digitalocean.sh
```

This installs Node.js 20, PM2, creates the `logs/` directory, and starts the server.

**3. Get your refresh token**
```bash
npm run auth
```

Open the URL it prints → sign in with your MCC Google account → paste the auth code back → copy the `GOOGLE_REFRESH_TOKEN` printed in terminal.

**4. Create and fill in .env**
```bash
cp .env.example .env
nano .env
```

```env
MCP_SECRET_TOKEN=YOUR_64_CHAR_TOKEN
DASHBOARD_PIN=YOUR_STRONG_PASSPHRASE
GOOGLE_CLIENT_ID=xxxxx.apps.googleusercontent.com
GOOGLE_CLIENT_SECRET=GOCSPX-xxxxx
GOOGLE_REFRESH_TOKEN=1//xxxxxxxx
GOOGLE_ADS_DEV_TOKEN=xxxxx
GOOGLE_ADS_MCC_ID=1234567890
GOOGLE_ADS_API_VERSION=v23.2
PORT=3848
MCP_RATE_LIMIT=60
```

**5. Start with PM2**
```bash
pm2 start ecosystem.config.js
pm2 save
pm2 startup
# Run the command it prints to enable auto-start on reboot
```

**6. Set up nginx + SSL**
```bash
cp nginx/gads-mcp.conf /etc/nginx/sites-available/
nano /etc/nginx/sites-available/gads-mcp.conf   # replace YOUR_DOMAIN with your domain
ln -s /etc/nginx/sites-available/gads-mcp.conf /etc/nginx/sites-enabled/
nginx -t && systemctl reload nginx
apt install certbot python3-certbot-nginx -y
certbot --nginx -d gads-mcp.yourdomain.com
```

**7. Test the endpoint**
```bash
curl https://gads-mcp.yourdomain.com/mcp
# Should return tool manifest JSON
```

**8. Open dashboard and configure permissions**
```
https://gads-mcp.yourdomain.com/dashboard
```

Enter your `DASHBOARD_PIN`. Go to **Permission Controls** → toggle write tools on or off per your needs.

---

## 🔗 Connecting Claude

### Claude Desktop (Mac / Windows)

Open config file:
- **Mac:** `~/Library/Application Support/Claude/claude_desktop_config.json`
- **Windows:** `%APPDATA%\Claude\claude_desktop_config.json`

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

For DigitalOcean, change URL to `https://gads-mcp.yourdomain.com/mcp`.

Save → quit Claude Desktop completely → reopen.

### Claude Code (Mac / Linux)
```bash
claude mcp add --transport http gads-mcp \
  https://yourdomain.com/gads-mcp/mcp.php \
  --header "Authorization: Bearer YOUR_MCP_SECRET_TOKEN"
```

### Claude Code (Windows — bridge required)

Claude Code on Windows has a known header issue with HTTP transport. Use the bridge script:

1. Use the bridge from the [OptiMCP bridge script](docs/optimcp-bridge.js) — update `TOKEN` and `HOSTNAME`
2. Add to `.claude.json`:

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

| Control | Implementation |
|---------|----------------|
| MCP auth | `X-MCP-Token` and `Authorization: Bearer` both accepted |
| LiteSpeed fix | `getallheaders()` bypasses Hostinger/LiteSpeed header stripping |
| GET public | Tool manifest requires no token (Claude Code health check compatible) |
| POST protected | All 30 tool calls require valid token |
| Permission controls | Per-tool enable/disable — 17 write tools individually controllable |
| Blocked tool response | Returns 403 with clear message to Claude, never silently fails |
| Rate limiting | 60 req/60s per IP (both versions) |
| Error handling | `error_reporting(0)` prevents PHP warnings corrupting JSON |
| Dashboard | Separate PIN, timing-safe comparison |
| Credentials | Never returned in tool responses — OAuth tokens stay server-side |
| PAUSED defaults | All create operations default to PAUSED — nothing spends accidentally |
| Danger tools | remove_campaign, remove_ad_group, remove_keyword highlighted in dashboard |

---

## 📋 Go-live checklist

**Credentials**
- [ ] All 5 Google credentials filled in — no placeholder values remain
- [ ] MCC ID digits only — no dashes (`123-456-7890` → `1234567890`)
- [ ] Developer token is **Standard Access** (Basic = test accounts only — will fail on real accounts)
- [ ] `GOOGLE_ADS_API_VERSION` = `v23.2`
- [ ] `MCP_SECRET_TOKEN` is 64 chars (`openssl rand -hex 32`)
- [ ] `DASHBOARD_PIN` set — dashboard shows PIN login screen

**Server**
- [ ] Dashboard OAuth panel shows **Connected** with token expiry countdown
- [ ] `oauth_setup.php` deleted from server immediately after use
- [ ] GET endpoint returns tool manifest with all 30 tools listed
- [ ] `logs/` and `logs/rate/` folders exist and are writable (PHP version)
- [ ] PM2 shows `gads-mcp` as **online** (Node.js version)

**Permissions**
- [ ] Dashboard Permission Controls panel loaded — all 17 write tools visible
- [ ] Danger tools (remove operations) reviewed — disable if not needed
- [ ] Test: ask Claude to "remove a campaign" with it disabled — should get blocked message

**Claude connection**
- [ ] `claude mcp list` shows ✓ Connected
- [ ] "List all accounts" returns your MCC client accounts in Claude
- [ ] Prompt Helper in dashboard shows your campaigns by name

---

## 📁 Repository structure

```
optimcp/
├── gads-mcp-php/              # Google Ads MCP — PHP (Hostinger / SiteGround)
│   ├── mcp.php                # Main endpoint
│   ├── config.php             # Credentials template
│   ├── lib/
│   │   ├── permissions.php    # Per-tool permission system
│   │   ├── auth.php           # LiteSpeed-compatible token auth
│   │   ├── oauth.php          # File-cached token manager
│   │   └── gads.php           # Google Ads REST API client
│   ├── tools/                 # 30 tools across 6 files
│   └── dashboard/index.php    # Full dashboard with permission controls
└── gads-mcp/                  # Google Ads MCP — Node.js (DigitalOcean)
    ├── server.js              # Main server (port 3848)
    ├── lib/
    │   ├── permissions.js     # Per-tool permission system
    │   └── ...
    ├── dashboard/
    │   ├── index.html         # Dashboard UI
    │   └── routes.js          # Dashboard API (permissions, prompt helper)
    └── tools/                 # 30 tools across 6 files
```

---

## 🏗️ Built by

**[Opti Webopz](https://optiwebopz.com)** — Full-service digital agency specializing in WordPress, WooCommerce, and AI-powered automation.

**Contributors:** Muhammad Zumair Qureshi · M. Shaheer Mustafa

---

## 📄 License

MIT — free to use, modify, and distribute. See [LICENSE](LICENSE).

---

*Part of the OptiMCP suite — self-hosted MCP servers for Claude. Google Ads MCP v2.1.0 · Node.js v1.2.0*
