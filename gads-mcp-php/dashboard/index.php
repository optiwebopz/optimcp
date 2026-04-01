<?php
/**
 * File: /gads-mcp-php/dashboard/index.php
 * OptiMCP Google Ads MCP PHP — Web Dashboard
 *
 * Version: 2.2.0
 * Changelog:
 *   2026-04-01 | v2.2.0 | SECURITY FIX: session_start() now sets HttpOnly, Secure,
 *              |         | SameSite=Strict, and strict_mode before starting session.
 *              |         | All AJAX $_GET/$_POST params re-validated after sanitization.
 *              |         | Log rotation added: log file auto-rotates at 5MB.
 *   2026-04-01 | v2.1.0 | Permission Controls panel — toggle write tools on/off from dashboard
 *              |         | Blocked tools return 403 with clear message to Claude
 *   2026-04-01 | v2.0.0 | Prompt Helper — browse accounts, campaigns, ad groups by name
 *   2026-03-26 | v1.0.0 | Initial release
 */

error_reporting(0);
ini_set('display_errors', 0);
define('ABSPATH', dirname(__DIR__) . '/');
require_once ABSPATH . 'config.php';
require_once ABSPATH . 'lib/logger.php';
require_once ABSPATH . 'lib/permissions.php';
require_once ABSPATH . 'lib/oauth.php';
require_once ABSPATH . 'lib/gads.php';
require_once ABSPATH . 'tools/account_tools.php';
require_once ABSPATH . 'tools/reporting_tools.php';
require_once ABSPATH . 'tools/campaign_tools.php';
require_once ABSPATH . 'tools/adgroup_tools.php';
require_once ABSPATH . 'tools/ad_tools.php';

// ── FIXED: Secure session configuration before session_start() ───────────────
ini_set('session.cookie_httponly', '1');
ini_set('session.cookie_secure',   '1');   // requires HTTPS — Hostinger/SiteGround always use HTTPS
ini_set('session.cookie_samesite', 'Strict');
ini_set('session.use_strict_mode', '1');
ini_set('session.gc_maxlifetime',  '3600'); // 1 hour
session_start();

// ── Escaping helper ───────────────────────────────────────────────────────────
function h(mixed $v): string {
    return htmlspecialchars((string)$v, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

// ── PIN auth ──────────────────────────────────────────────────────────────────
$pin = defined('DASHBOARD_PIN') ? DASHBOARD_PIN : '';
if (empty($pin)) { http_response_code(503); die(json_encode(['ok'=>false,'error'=>'Dashboard disabled.'])); }

$sessionKey = 'gads_dash_' . md5($pin);
$loggedIn   = !empty($_SESSION[$sessionKey]);
$error      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    if (hash_equals($pin, trim($_POST['pin']))) {
        $_SESSION[$sessionKey] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
        exit;
    }
    $error = 'Incorrect PIN.';
}

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ── AJAX endpoints (must be logged in) ───────────────────────────────────────
if ($loggedIn && isset($_GET['ajax'])) {
    header('Content-Type: application/json');

    $action = trim($_GET['ajax'] ?? '');
    $cid    = preg_replace('/[^0-9]/', '', $_GET['cid']     ?? '');
    $campId = preg_replace('/[^0-9]/', '', $_GET['camp_id'] ?? '');
    $agId   = preg_replace('/[^0-9]/', '', $_GET['ag_id']   ?? '');

    try {
        switch ($action) {

            case 'campaigns':
                if (!$cid) throw new RuntimeException('customer_id required');
                $r = gads_list_campaigns(['customer_id' => $cid, 'limit' => 200]);
                echo json_encode(['ok' => true, 'data' => $r['campaigns'] ?? []]);
                break;

            case 'adgroups':
                if (!$cid || !$campId) throw new RuntimeException('customer_id and campaign_id required');
                $r = gads_list_ad_groups(['customer_id' => $cid, 'campaign_id' => $campId, 'limit' => 200]);
                echo json_encode(['ok' => true, 'data' => $r['ad_groups'] ?? []]);
                break;

            case 'get_permissions':
                echo json_encode(['ok' => true, 'data' => perm_load(), 'tools' => PERM_WRITE_TOOLS]);
                break;

            case 'save_permissions':
                $body  = json_decode(file_get_contents('php://input'), true) ?? [];
                $perms = perm_load();
                foreach (array_keys(PERM_WRITE_TOOLS) as $toolKey) {
                    if (isset($body[$toolKey])) {
                        $perms[$toolKey] = (bool)$body[$toolKey];
                    }
                }
                $ok = perm_save($perms);
                mcp_log('info', 'Permissions updated via dashboard', $perms);
                echo json_encode(['ok' => $ok, 'data' => $perms]);
                break;

            case 'ads':
                if (!$cid || !$agId) throw new RuntimeException('customer_id and ad_group_id required');
                $r = gads_list_ads(['customer_id' => $cid, 'ad_group_id' => $agId, 'limit' => 50]);
                echo json_encode(['ok' => true, 'data' => $r['ads'] ?? []]);
                break;

            case 'campaign_report':
                if (!$cid) throw new RuntimeException('customer_id required');
                $r = gads_get_campaign_report(['customer_id' => $cid, 'date_range' => 'LAST_30_DAYS', 'limit' => 20]);
                echo json_encode(['ok' => true, 'data' => $r['campaigns'] ?? []]);
                break;

            default:
                echo json_encode(['ok' => false, 'error' => 'Unknown action']);
        }
    } catch (Throwable $e) {
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
    }
    exit;
}

// ── Token rotation (POST action, must be logged in) ───────────────────────────
$actionMsg  = '';
$actionType = '';

if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_token'])) {
    $t = trim($_POST['new_token']);
    if (strlen($t) >= 32) {
        $logDir = ABSPATH . 'logs/';
        if (!is_dir($logDir)) @mkdir($logDir, 0750, true);
        @file_put_contents($logDir . '.pending_token.txt', $t, LOCK_EX);
        $actionMsg  = 'Token saved to <code>logs/.pending_token.txt</code>. Copy into config.php as MCP_SECRET_TOKEN and re-upload.';
        $actionType = 'ok';
    } else {
        $actionMsg  = 'Token must be at least 32 characters.';
        $actionType = 'err';
    }
}

// ── Dashboard data ────────────────────────────────────────────────────────────
$accounts  = [];
$tokenInfo = [];
$stats     = [];
$apiVer    = defined('GOOGLE_ADS_API_VERSION') ? GOOGLE_ADS_API_VERSION : 'v23.2';

$sunset = [
    'v17'  => 'Feb 2025',
    'v18'  => 'Apr 2025',
    'v19'  => 'Jun 2025',
    'v20'  => 'Sep 2025',
    'v21'  => 'Dec 2025',
    'v22'  => 'Mar 2026',
    'v23'  => 'Jun 2026',
    'v23.2'=> 'Current',
];

if ($loggedIn) {
    try { $tokenInfo = gads_token_status(); } catch (Throwable) {}
    try {
        $mccId = preg_replace('/[^0-9]/', '', defined('GOOGLE_ADS_MCC_ID') ? GOOGLE_ADS_MCC_ID : '');
        if ($mccId) {
            $r        = gads_list_accounts(['customer_id' => $mccId]);
            $accounts = $r['accounts'] ?? [];
        }
    } catch (Throwable) {}

    // Stats from log file
    $logPath = ABSPATH . 'logs/gads-mcp.log';

    // FIXED: Rotate log if it exceeds 5MB to prevent disk exhaustion
    if (file_exists($logPath) && filesize($logPath) > 5 * 1024 * 1024) {
        @rename($logPath, $logPath . '.' . date('Ymd-His') . '.bak');
    }

    try {
        $cutoff = time() - 86400;
        $total  = 0;
        $errors = 0;
        $toolCounts = [];
        if (file_exists($logPath)) {
            $lines = file($logPath, FILE_IGNORE_NEW_LINES | FILE_SKIP_EMPTY_LINES);
            foreach (array_reverse($lines) as $line) {
                if (preg_match('/^\[([^\]]+)\]/', $line, $m)) {
                    try {
                        if (strtotime($m[1]) < $cutoff) continue;
                    } catch (Throwable) { continue; }
                }
                if (str_contains($line, 'Tool called:')) {
                    $total++;
                    if (preg_match('/Tool called: (\S+)/', $line, $tm)) {
                        $toolCounts[$tm[1]] = ($toolCounts[$tm[1]] ?? 0) + 1;
                    }
                } elseif (str_contains($line, 'Tool failed')) {
                    $errors++;
                }
            }
        }
        arsort($toolCounts);
        $stats = [
            'total_24h'  => $total,
            'errors_24h' => $errors,
            'top_tool'   => array_key_first($toolCounts) ?? '—',
        ];
    } catch (Throwable) {
        $stats = ['total_24h' => 0, 'errors_24h' => 0, 'top_tool' => '—'];
    }
}

?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>OptiMCP Dashboard</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#F4F5F6;color:#1A1D23;min-height:100vh}
a{color:#1A56A0;text-decoration:none}
code{font-family:'Courier New',monospace;font-size:.9em;background:#F1F3F5;padding:1px 5px;border-radius:4px}

/* Login */
.login-wrap{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:20px}
.login-box{background:#fff;border-radius:12px;box-shadow:0 1px 4px rgba(0,0,0,.1);padding:32px;width:100%;max-width:360px}
.login-logo{font-size:22px;font-weight:700;color:#1A56A0;margin-bottom:6px}
.login-sub{font-size:13px;color:#6B7280;margin-bottom:24px}
.login-box input[type=password]{width:100%;padding:10px 12px;border:1px solid #D1D5DB;border-radius:8px;font-size:14px;outline:none;transition:border .15s}
.login-box input[type=password]:focus{border-color:#1A56A0}
.login-box button{width:100%;margin-top:12px;padding:10px;background:#1A56A0;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer}
.login-box button:hover{background:#144281}
.login-err{margin-top:10px;font-size:13px;color:#9B1C1C;text-align:center}

/* Layout */
.topbar{background:#1A1D23;color:#fff;padding:0 24px;height:52px;display:flex;align-items:center;justify-content:space-between}
.topbar-logo{font-size:16px;font-weight:600;color:#fff}
.topbar-right{font-size:12px;color:#9CA3AF}
.topbar-right a{color:#6B9FDE;margin-left:16px}
.container{max-width:1100px;margin:24px auto;padding:0 16px;display:grid;grid-template-columns:1fr 1fr;gap:16px}
.card{background:#fff;border-radius:10px;box-shadow:0 1px 3px rgba(0,0,0,.08);overflow:hidden}
.card.full{grid-column:1/-1}
.ct{font-size:13px;font-weight:600;color:#374151;padding:14px 16px;border-bottom:1px solid #F0F0F0;display:flex;align-items:center;justify-content:space-between}
.cv{padding:16px}

/* Stats */
.stats{display:grid;grid-template-columns:repeat(4,1fr);gap:0;border-bottom:1px solid #F0F0F0}
.stat{padding:14px 16px;border-right:1px solid #F0F0F0}
.stat:last-child{border-right:none}
.sl{font-size:11px;color:#9CA3AF;margin-bottom:4px;text-transform:uppercase;letter-spacing:.05em}
.sv{font-size:20px;font-weight:600;color:#1A1D23}

/* Status rows */
.row{display:flex;align-items:center;justify-content:space-between;padding:10px 16px;border-bottom:1px solid #F9FAFB;font-size:13px}
.row:last-child{border-bottom:none}
.rl{color:#6B7280}
.rv{font-weight:500;color:#1A1D23}
.badge-ok{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:500;background:#D1FAE5;color:#065F46}
.badge-err{display:inline-flex;align-items:center;gap:4px;padding:3px 10px;border-radius:12px;font-size:11px;font-weight:500;background:#FEE2E2;color:#991B1B}
.vok{display:flex;align-items:center;gap:8px;padding:10px 16px;font-size:12px;color:#065F46;background:#ECFDF5;border-top:1px solid #A7F3D0}
.vwarn{display:flex;align-items:center;gap:8px;padding:10px 16px;font-size:12px;color:#92400E;background:#FFFBEB;border-top:1px solid #FCD34D}

/* Toggle */
.toggle{position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#D1D5DB;border-radius:24px;transition:.2s}
.slider:before{position:absolute;content:"";height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s}
.toggle input:checked + .slider{background:#1A56A0}
.toggle input:checked + .slider:before{transform:translateX(20px)}

/* Prompts */
.prompt-list{background:#F7F8FA;border:1px solid #E2E5EA;border-radius:8px;padding:0;overflow:hidden}
.prompt-item{padding:12px 14px;border-bottom:1px solid #E2E5EA;cursor:pointer;transition:background .1s;display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.prompt-item:last-child{border-bottom:none}
.prompt-item:hover{background:#EEF3FF}
.prompt-text{font-size:13px;color:#1A1D23;line-height:1.5;flex:1}
.prompt-copy{font-size:11px;font-weight:500;color:#1A56A0;white-space:nowrap;padding:3px 8px;border:1px solid #BFDBFE;border-radius:5px;background:#EFF6FF;flex-shrink:0;cursor:pointer}
.prompt-copy:hover{background:#DBEAFE}
.prompt-section{font-size:11px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:.06em;padding:10px 14px 6px;background:#F4F5F6;border-bottom:1px solid #E2E5EA}
.spinner{width:20px;height:20px;border:2px solid #E2E5EA;border-top-color:#1A56A0;border-radius:50%;animation:spin .7s linear infinite;margin:20px auto}
@keyframes spin{to{transform:rotate(360deg)}}
.empty-state{text-align:center;padding:24px;color:#6B7280;font-size:13px}

/* Token */
.token-input{display:flex;gap:8px;padding:12px 16px}
.token-input input{flex:1;padding:8px 10px;border:1px solid #D1D5DB;border-radius:7px;font-size:12px;font-family:'Courier New',monospace}
.token-input button{padding:8px 12px;background:#1A56A0;color:#fff;border:none;border-radius:7px;font-size:12px;cursor:pointer;white-space:nowrap}
.token-input button:hover{background:#144281}
.token-msg{padding:0 16px 12px;font-size:12px}
.token-msg.ok{color:#065F46}
.token-msg.err{color:#991B1B}

/* Responsive */
@media(max-width:700px){
  .container{grid-template-columns:1fr}
  .card.full{grid-column:1}
  .stats{grid-template-columns:1fr 1fr}
}
select{padding:7px 10px;border:1px solid #D1D5DB;border-radius:7px;font-size:13px;outline:none;background:#fff;cursor:pointer}
select:focus{border-color:#1A56A0}
</style>
</head>
<body>

<?php if (!$loggedIn): ?>
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">OptiMCP</div>
    <div class="login-sub">Google Ads MCP — Dashboard</div>
    <form method="post">
      <input type="password" name="pin" placeholder="Enter dashboard PIN" autofocus autocomplete="current-password">
      <button type="submit">Sign in</button>
    </form>
    <?php if ($error): ?><div class="login-err"><?= h($error) ?></div><?php endif ?>
  </div>
</div>
<?php else: ?>

<div class="topbar">
  <span class="topbar-logo">⚡ OptiMCP Dashboard</span>
  <span class="topbar-right">
    v2.2.0 &nbsp;·&nbsp; <?= h($apiVer) ?>
    <a href="?logout=1">Sign out</a>
  </span>
</div>

<div class="container">

  <!-- ── Status card ── -->
  <div class="card">
    <div class="ct">OAuth &amp; connection status</div>
    <div class="row">
      <span class="rl">Token status</span>
      <span class="rv">
        <?php if (!empty($tokenInfo['valid'])): ?>
          <span class="badge-ok">✓ Valid (<?= (int)($tokenInfo['expires_in_seconds'] ?? 0) ?>s)</span>
        <?php else: ?>
          <span class="badge-err">✕ Expired / not refreshed</span>
        <?php endif ?>
      </span>
    </div>
    <div class="row">
      <span class="rl">Last refresh</span>
      <span class="rv"><?= h($tokenInfo['refreshed_at'] ?? '—') ?></span>
    </div>
    <div class="row">
      <span class="rl">MCC ID</span>
      <span class="rv"><?= h(defined('GOOGLE_ADS_MCC_ID') ? GOOGLE_ADS_MCC_ID : '—') ?></span>
    </div>
    <div class="row">
      <span class="rl">API version</span>
      <span class="rv"><?= h($apiVer) ?></span>
    </div>
    <?php $isLatest = ($apiVer === 'v23.2'); ?>
    <?php if ($isLatest): ?>
    <div class="vok"><span>✓</span> API <strong><?= h($apiVer) ?></strong> — current latest.</div>
    <?php else: ?>
    <div class="vwarn"><span>!</span> API <strong><?= h($apiVer) ?></strong> is not the latest. Update to v23.2 in config.php.</div>
    <?php endif ?>
  </div>

  <!-- ── Stats card ── -->
  <div class="card">
    <div class="ct">24h stats</div>
    <div class="stats">
      <div class="stat">
        <div class="sl">Tool calls</div>
        <div class="sv"><?= (int)($stats['total_24h'] ?? 0) ?></div>
      </div>
      <div class="stat">
        <div class="sl">Errors</div>
        <div class="sv" style="<?= ($stats['errors_24h'] ?? 0) > 0 ? 'color:#9B1C1C' : '' ?>"><?= (int)($stats['errors_24h'] ?? 0) ?></div>
      </div>
      <div class="stat">
        <div class="sl">Top tool</div>
        <div class="sv" style="font-size:12px;font-family:'Courier New',monospace"><?= h($stats['top_tool'] ?? '—') ?></div>
      </div>
      <div class="stat">
        <div class="sl">PHP memory</div>
        <div class="sv" style="font-size:16px"><?= round(memory_get_peak_usage(true) / 1048576, 1) ?>MB</div>
      </div>
    </div>
  </div>

  <!-- ── Token rotation ── -->
  <div class="card">
    <div class="ct">Rotate MCP secret token</div>
    <form method="post" style="margin:0">
      <div class="token-input">
        <input type="text" name="new_token" id="new_token" placeholder="New token (min 32 chars)" autocomplete="off">
        <button type="button" onclick="genToken()">Generate</button>
        <button type="submit">Save</button>
      </div>
      <?php if ($actionMsg): ?>
      <div class="token-msg <?= h($actionType) ?>"><?= $actionMsg ?></div>
      <?php endif ?>
    </form>
    <div style="padding:0 16px 12px;font-size:11px;color:#9CA3AF">
      PHP version: token must be updated manually in config.php after saving.
    </div>
  </div>

  <!-- ── Accounts ── -->
  <div class="card">
    <div class="ct">Client accounts (<?= count($accounts) ?>)</div>
    <?php if (empty($accounts)): ?>
      <div class="empty-state">No accounts found. Check MCC credentials in config.php.</div>
    <?php else: ?>
      <div style="max-height:260px;overflow-y:auto">
        <?php foreach ($accounts as $acc): ?>
          <div class="row">
            <span class="rl"><?= h($acc['name'] ?? '—') ?></span>
            <span class="rv" style="font-size:11px;font-family:'Courier New',monospace"><?= h($acc['id'] ?? '') ?></span>
          </div>
        <?php endforeach ?>
      </div>
    <?php endif ?>
  </div>

  <!-- ── Permission controls ── -->
  <div class="card full" id="perms-card">
    <div class="ct">
      <span>Permission controls — enable/disable write tools</span>
      <span id="perms-saved" style="font-size:11px;color:#065F46;display:none">✓ Saved</span>
    </div>
    <div style="padding:12px 16px;font-size:13px;color:#6B7280;border-bottom:1px solid #F0F0F0">
      <strong>Read tools are always on.</strong> Toggle individual write operations below.
      When disabled, Claude receives a clear message and asks you to re-enable from the dashboard.
    </div>
    <div id="perms-loading" style="text-align:center;padding:20px;color:#6B7280;font-size:13px">Loading permissions…</div>
    <div id="perms-grid" style="display:none;padding:16px;display:none;grid-template-columns:repeat(auto-fill,minmax(260px,1fr));gap:14px"></div>
  </div>

  <!-- ── Prompt Helper ── -->
  <div class="card full">
    <div class="ct">Prompt helper — browse accounts &amp; generate Claude prompts</div>
    <div style="padding:12px 16px;font-size:13px;color:#6B7280;border-bottom:1px solid #F0F0F0">
      Select an account, then browse campaigns and ad groups by name. Click any prompt to copy it into Claude.
    </div>

    <?php if (empty($accounts)): ?>
      <div class="empty-state">No accounts found.</div>
    <?php else: ?>

    <div style="padding:16px;display:flex;flex-direction:column;gap:14px">

      <div>
        <label style="font-size:12px;font-weight:600;color:#6B7280;display:block;margin-bottom:6px">1. Select account</label>
        <select id="acc-sel" onchange="loadCampaigns()">
          <option value="">— Choose an account —</option>
          <?php foreach ($accounts as $acc): ?>
            <option value="<?= h($acc['id']) ?>"><?= h($acc['name']) ?> (<?= h($acc['id']) ?>)</option>
          <?php endforeach ?>
        </select>
      </div>

      <div id="camp-wrap" style="display:none">
        <label style="font-size:12px;font-weight:600;color:#6B7280;display:block;margin-bottom:6px">2. Select campaign</label>
        <select id="camp-sel" onchange="loadAdGroups()">
          <option value="">— Loading… —</option>
        </select>
      </div>

      <div id="ag-wrap" style="display:none">
        <label style="font-size:12px;font-weight:600;color:#6B7280;display:block;margin-bottom:6px">3. Select ad group</label>
        <select id="ag-sel" onchange="renderPrompts()">
          <option value="">— Loading… —</option>
        </select>
      </div>

      <div id="prompt-list-wrap" style="display:none">
        <label style="font-size:12px;font-weight:600;color:#6B7280;display:block;margin-bottom:6px">4. Copy a prompt into Claude</label>
        <div id="prompt-list" class="prompt-list"></div>
      </div>

    </div>

    <?php endif ?>
  </div>

</div><!-- /container -->

<script>
const BASE = '<?= strtok($_SERVER['REQUEST_URI'], '?') ?>';
let _sel = { cid:'', cname:'', campId:'', campName:'', agId:'', agName:'' };
let _permsData = {}, _permsTools = {};
const GROUP_ORDER = ['Campaigns','Ad Groups','Ads','Keywords'];

function esc(s){ return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;'); }
function genToken(){
  const a=new Uint8Array(32);crypto.getRandomValues(a);
  document.getElementById('new_token').value=Array.from(a).map(b=>b.toString(16).padStart(2,'0')).join('');
}

async function loadCampaigns(){
  const sel=document.getElementById('acc-sel');
  _sel.cid=sel.value;
  _sel.cname=sel.options[sel.selectedIndex]?.text||'';
  document.getElementById('camp-wrap').style.display=_sel.cid?'block':'none';
  document.getElementById('ag-wrap').style.display='none';
  document.getElementById('prompt-list-wrap').style.display='none';
  if(!_sel.cid) return;
  const campSel=document.getElementById('camp-sel');
  campSel.innerHTML='<option>Loading…</option>';
  const r=await fetch(`${BASE}?ajax=campaigns&cid=${_sel.cid}`).then(r=>r.json()).catch(()=>({ok:false}));
  campSel.innerHTML='<option value="">— Choose a campaign —</option>';
  (r.data||[]).forEach(c=>{ const o=document.createElement('option');o.value=c.id;o.textContent=`${c.name} (${c.status})`;campSel.appendChild(o); });
}

async function loadAdGroups(){
  const sel=document.getElementById('camp-sel');
  _sel.campId=sel.value;
  _sel.campName=sel.options[sel.selectedIndex]?.text||'';
  document.getElementById('ag-wrap').style.display=_sel.campId?'block':'none';
  document.getElementById('prompt-list-wrap').style.display='none';
  if(!_sel.campId) return;
  const agSel=document.getElementById('ag-sel');
  agSel.innerHTML='<option>Loading…</option>';
  const r=await fetch(`${BASE}?ajax=adgroups&cid=${_sel.cid}&camp_id=${_sel.campId}`).then(r=>r.json()).catch(()=>({ok:false}));
  agSel.innerHTML='<option value="">— All ad groups (use campaign only) —</option>';
  (r.data||[]).forEach(ag=>{ const o=document.createElement('option');o.value=ag.id;o.textContent=ag.name;agSel.appendChild(o); });
  renderPrompts();
}

function renderPrompts(){
  const sel=document.getElementById('ag-sel');
  _sel.agId=sel.value;
  _sel.agName=sel.options[sel.selectedIndex]?.text||'';
  const wrap=document.getElementById('prompt-list-wrap');
  const list=document.getElementById('prompt-list');
  wrap.style.display='block';
  const cname=_sel.campName.replace(/ \(.*\)$/,'');
  const agname=_sel.agName;
  const cid=_sel.cid;
  const prompts=[
    {section:'Reports',items:[
      `Show me the performance of campaign "${cname}" for the last 30 days`,
      `What is the CTR on campaign "${cname}" this month?`,
      `Get the keyword report for account ${cid} for last 30 days`,
      `Show search terms that triggered ads in campaign "${cname}" this month`,
    ]},
    {section:'Campaign actions',items:[
      `Pause campaign "${cname}" in account ${cid}`,
      `What is the daily budget for campaign "${cname}" in account ${cid}?`,
      `Update the daily budget for campaign "${cname}" in account ${cid} to $50`,
    ]},
    ...(agname ? [{section:`Ad group: ${agname}`,items:[
      `List all keywords in ad group "${agname}" in account ${cid}`,
      `Pause ad group "${agname}" in account ${cid}`,
      `Add the keyword "example keyword" as EXACT match to ad group "${agname}" in account ${cid}`,
    ]}] : []),
  ];
  list.innerHTML='';
  prompts.forEach(({section,items})=>{
    const sh=document.createElement('div');sh.className='prompt-section';sh.textContent=section;list.appendChild(sh);
    items.forEach(text=>{
      const row=document.createElement('div');row.className='prompt-item';
      row.innerHTML=`<span class="prompt-text">${esc(text)}</span><button class="prompt-copy" onclick="copyPrompt(this,'${esc(text)}')">Copy</button>`;
      list.appendChild(row);
    });
  });
}

function copyPrompt(btn,text){
  navigator.clipboard.writeText(text).then(()=>{
    btn.textContent='✓ Copied';btn.style.background='#EAF5EE';btn.style.color='#065F46';btn.style.borderColor='#A7F3D0';
    setTimeout(()=>{btn.textContent='Copy';btn.style.background=btn.style.color=btn.style.borderColor='';},2000);
  });
}

async function loadPermissions(){
  const r=await fetch(`${BASE}?ajax=get_permissions`).then(r=>r.json()).catch(()=>({ok:false}));
  if(!r.ok) return;
  _permsData=r.data||{};_permsTools=r.tools||{};
  renderPermsGrid();
  document.getElementById('perms-loading').style.display='none';
  const grid=document.getElementById('perms-grid');grid.style.display='grid';
}

function renderPermsGrid(){
  const grid=document.getElementById('perms-grid');grid.innerHTML='';
  const groups={};
  Object.entries(_permsTools).forEach(([toolName,info])=>{
    const g=info.group||'Other';if(!groups[g])groups[g]=[];groups[g].push({toolName,...info});
  });
  GROUP_ORDER.forEach(groupName=>{
    if(!groups[groupName])return;
    const col=document.createElement('div');
    col.innerHTML=`<div style="font-size:11px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px;padding-bottom:6px;border-bottom:2px solid #E2E5EA">${esc(groupName)}</div>`;
    groups[groupName].forEach(({toolName,label,danger})=>{
      const enabled=_permsData[toolName]!==false;
      const row=document.createElement('div');row.id='prow-'+toolName;
      row.style.cssText='display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:7px;margin-bottom:4px;background:'+(enabled?'#F7F8FA':'#FEE2E2')+';transition:background .2s';
      row.innerHTML=`<div><div style="font-size:13px;font-weight:500;${danger?'color:#9B1C1C':''}">${esc(label)}${danger?' ⚠️':''}</div><div style="font-size:10px;font-family:'Courier New',monospace;color:#9CA3AF;margin-top:1px">${esc(toolName)}</div></div><label class="toggle" style="flex-shrink:0;margin-left:12px"><input type="checkbox" ${enabled?'checked':''} onchange="savePerm('${toolName}',this.checked)"><span class="slider"></span></label>`;
      col.appendChild(row);
    });
    grid.appendChild(col);
  });
  const notice=document.createElement('div');
  notice.style.cssText='grid-column:1/-1;padding:10px 14px;background:#EFF6FF;border-radius:8px;border-left:3px solid #1A56A0;font-size:12px;color:#1A56A0;line-height:1.6;margin-top:4px';
  notice.innerHTML='🔒 <strong>Always enabled (read-only):</strong> list_accounts, get_account, run_gaql, get_account_summary, get_campaign_report, get_ad_group_report, get_keyword_report, get_ad_report, get_search_terms, list_campaigns, get_campaign, list_ad_groups, list_ads';
  grid.appendChild(notice);
}

async function savePerm(toolName,enabled){
  const row=document.getElementById('prow-'+toolName);
  if(row)row.style.background=enabled?'#F7F8FA':'#FEE2E2';
  _permsData[toolName]=enabled;
  const r=await fetch(`${BASE}?ajax=save_permissions`,{method:'POST',headers:{'Content-Type':'application/json'},body:JSON.stringify({[toolName]:enabled})}).then(r=>r.json()).catch(()=>({ok:false}));
  if(r.ok){const s=document.getElementById('perms-saved');s.style.display='inline';setTimeout(()=>{s.style.display='none';},2500);}
}

loadPermissions();
</script>

<?php endif ?>
</body>
</html>
