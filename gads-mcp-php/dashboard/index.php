<?php
/**
 * File: /gads-mcp-php/dashboard/index.php
 * OptiMCP Google Ads MCP PHP — Web Dashboard
 *
 * Version: 2.1.0
 * Changelog:
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

session_start();

// ── PIN auth ──────────────────────────────────────────────────────────────────
$pin = DASHBOARD_PIN;
if (empty($pin)) { http_response_code(503); die('Dashboard disabled.'); }
$sessionKey = 'gads_dash_' . md5($pin);
$loggedIn   = !empty($_SESSION[$sessionKey]);
$error      = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['pin'])) {
    if (hash_equals($pin, trim($_POST['pin']))) {
        $_SESSION[$sessionKey] = true;
        header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
    }
    $error = 'Incorrect PIN.';
}
if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?')); exit;
}

// ── AJAX endpoints (must be logged in) ───────────────────────────────────────
if ($loggedIn && isset($_GET['ajax'])) {
    header('Content-Type: application/json');
    $action = $_GET['ajax'];
    $cid    = preg_replace('/[^0-9]/', '', $_GET['cid'] ?? '');
    $campId = preg_replace('/[^0-9]/', '', $_GET['camp_id'] ?? '');
    $agId   = preg_replace('/[^0-9]/', '', $_GET['ag_id'] ?? '');

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

// ── Token rotation ────────────────────────────────────────────────────────────
$actionMsg = $actionType = '';
if ($loggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && !empty($_POST['new_token'])) {
    $t = trim($_POST['new_token']);
    if (strlen($t) >= 32) {
        $dir = ABSPATH . 'logs/';
        if (!is_dir($dir)) @mkdir($dir, 0750, true);
        @file_put_contents($dir . '.pending_token.txt', $t, LOCK_EX);
        $actionMsg = 'Token saved to <code>logs/.pending_token.txt</code>. Copy into config.php as MCP_SECRET_TOKEN and re-upload.';
        $actionType = 'ok';
    } else {
        $actionMsg = 'Token must be at least 32 characters.';
        $actionType = 'err';
    }
}

// ── Data fetch ────────────────────────────────────────────────────────────────
$tokenStatus = []; $accounts = []; $logEntries = []; $stats = [];
$apiVer      = GOOGLE_ADS_API_VERSION;
$supported   = ['v20','v21','v22','v23','v23.1','v23.2'];
$sunset      = ['v20'=>'Jun 2026','v21'=>'Aug 2026','v22'=>'Oct 2026','v23'=>'Feb 2027','v23.1'=>'Feb 2027','v23.2'=>'Feb 2027'];

if ($loggedIn) {
    $tokenStatus = gads_token_status();

    try {
        $ar       = gads_list_accounts([]);
        $accounts = $ar['accounts'] ?? [];
    } catch (Throwable $e) {
        $accountsError = $e->getMessage();
    }

    $logPath = MCP_LOG_PATH;
    if (file_exists($logPath)) {
        $lines  = array_reverse(array_filter(explode("\n", file_get_contents($logPath))));
        $cutoff = time() - 86400;
        foreach ($lines as $line) {
            if (!$line) continue;
            if (!preg_match('/^\[([^\]]+)\] \[([^\]]+)\] (.+)$/', $line, $m)) continue;
            [, $ts, , $rest] = $m;
            if (!str_contains($rest, 'Tool called:') && !str_contains($rest, 'Tool execution failed')) continue;
            $ok   = str_contains($rest, 'Tool called:');
            $tool = '—'; $cid = '—';
            if (preg_match('/Tool called: (\S+)/', $rest, $tm)) $tool = $tm[1];
            if (preg_match('/"tool":"([^"]+)"/', $rest, $tm2)) $tool = $tm2[1];
            if (preg_match('/"customer_id[s]?":"?(\d+)"?/', $rest, $cm)) $cid = $cm[1];
            $logEntries[] = ['time' => substr($ts, 11, 8), 'tool' => $tool, 'cid' => $cid, 'ok' => $ok];
            try {
                $lts = (new DateTime($ts))->getTimestamp();
                if ($lts >= $cutoff) {
                    if ($ok) { $stats['total_24h'] = ($stats['total_24h'] ?? 0) + 1; $stats['tools'][$tool] = ($stats['tools'][$tool] ?? 0) + 1; }
                    else $stats['errors_24h'] = ($stats['errors_24h'] ?? 0) + 1;
                }
            } catch (Throwable) {}
            if (count($logEntries) >= 50) break;
        }
        arsort($stats['tools'] ?? []);
        $stats['top_tool'] = array_key_first($stats['tools'] ?? []) ?? '—';
    }
}

function h(string $s): string { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Google Ads MCP · Dashboard</title>
<style>
*{box-sizing:border-box;margin:0;padding:0}
body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',sans-serif;background:#F7F8FA;color:#1A1D23;font-size:14px;line-height:1.6;min-height:100vh}
@media(prefers-color-scheme:dark){
  body{background:#0F1117;color:#F1F3F6}
  .card,.topbar,.login-card,.sel{background:#1A1D23!important;border-color:#2D3139!important;color:#F1F3F6!important}
  .stat,.prompt-box,.prompt-item{background:#0F1117!important;border-color:#2D3139!important}
  input,select,textarea{background:#0F1117!important;color:#F1F3F6!important;border-color:#2D3139!important}
  .vok{background:#0D2318!important;color:#5DCAA5!important}
  .vwarn{background:#2A1A00!important;color:#EF9F27!important}
  .action-ok{background:#0D2318!important;color:#5DCAA5!important}
  .action-err{background:#2A0A0A!important;color:#F09595!important}
  .camp-row:hover{background:#2D3139!important}
  .prompt-item:hover{background:#1A1D23!important}
}
.topbar{background:#fff;border-bottom:1px solid #E2E5EA;padding:0 24px;display:flex;align-items:center;justify-content:space-between;height:52px;position:sticky;top:0;z-index:10}
.brand{font-size:12px;font-weight:600;color:#1A56A0;letter-spacing:.07em;text-transform:uppercase}
.topbar-r{display:flex;align-items:center;gap:12px}
.ver-pill{font-size:11px;font-family:'Courier New',monospace;background:#F4F5F6;border:1px solid #E2E5EA;border-radius:20px;padding:3px 10px;color:#6B7280}
.btn-out{font-size:12px;color:#6B7280;background:none;border:1px solid #D1D5DB;border-radius:6px;padding:4px 10px;cursor:pointer;text-decoration:none}
.main{max-width:1200px;margin:0 auto;padding:24px;display:grid;grid-template-columns:1fr 1fr;gap:18px}
@media(max-width:740px){.main{grid-template-columns:1fr}}
.card{background:#fff;border:1px solid #E2E5EA;border-radius:12px;padding:18px}
.card.full{grid-column:1/-1}
.ct{font-size:11px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:.07em;margin-bottom:14px;display:flex;align-items:center;justify-content:space-between}
.badge{display:inline-flex;align-items:center;gap:5px;padding:3px 9px;border-radius:20px;font-size:11px;font-weight:500}
.badge::before{content:'';width:6px;height:6px;border-radius:50%;background:currentColor;opacity:.65}
.ok{background:#EAF5EE;color:#1B7A3E}.warn{background:#FEF3CD;color:#92500A}.err{background:#FEE2E2;color:#9B1C1C}.gray{background:#F4F5F6;color:#6B7280;border:1px solid #E2E5EA}.paused{background:#E0E7FF;color:#3730A3}
.row{display:flex;align-items:center;justify-content:space-between;padding:8px 0;border-bottom:1px solid #E2E5EA;font-size:13px}
.row:last-child{border-bottom:none}
.rl{color:#6B7280}.rv{font-weight:500;text-align:right;font-size:13px}
.mono{font-family:'Courier New',monospace;font-size:11px}
.stat-grid{display:grid;grid-template-columns:1fr 1fr;gap:12px}
.stat{background:#F7F8FA;border-radius:8px;padding:12px}
.sl{font-size:11px;color:#6B7280;margin-bottom:4px}.sv{font-size:20px;font-weight:600}
.vok{background:#EAF5EE;color:#1B7A3E;border-radius:8px;padding:10px 12px;font-size:13px;display:flex;gap:8px;margin-bottom:12px}
.vwarn{background:#FEF3CD;color:#92500A;border-radius:8px;padding:10px 12px;font-size:13px;display:flex;gap:8px;margin-bottom:12px}
.token-box{font-family:'Courier New',monospace;font-size:11px;background:#F4F5F6;border:1px solid #E2E5EA;border-radius:6px;padding:9px 11px;word-break:break-all;color:#6B7280;margin-bottom:10px}
.btn-row{display:flex;gap:8px;flex-wrap:wrap}
.btn-s{padding:7px 14px;border:1px solid #D1D5DB;border-radius:7px;background:none;color:#1A1D23;font-size:12px;font-weight:500;cursor:pointer}
.btn-p{padding:7px 14px;border:1px solid #1A56A0;border-radius:7px;background:#1A56A0;color:#fff;font-size:12px;font-weight:500;cursor:pointer}
.action-ok{background:#EAF5EE;color:#1B7A3E;border-radius:6px;padding:9px 12px;font-size:13px;margin-top:10px}
.action-err{background:#FEE2E2;color:#9B1C1C;border-radius:6px;padding:9px 12px;font-size:13px;margin-top:10px}
/* Log */
.log-hdr,.log-row{display:grid;grid-template-columns:80px 150px 1fr 60px;gap:8px;font-size:12px;padding:7px 8px;align-items:center}
.log-hdr{font-size:11px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:.05em;border-bottom:2px solid #E2E5EA;background:#F7F8FA;border-radius:6px 6px 0 0}
.log-row{border-bottom:1px solid #E2E5EA}.log-row:last-child{border-bottom:none}
.lt{color:#6B7280;font-family:'Courier New',monospace}.lto{font-family:'Courier New',monospace;color:#1A56A0;font-weight:500;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
/* Accounts */
.acct{display:flex;align-items:center;justify-content:space-between;padding:10px 0;border-bottom:1px solid #E2E5EA;cursor:pointer;transition:background .1s;padding:10px 8px;border-radius:6px;margin:-2px 0}
.acct:last-child{border-bottom:none}.acct:hover{background:#F7F8FA}
.an{font-size:13px;font-weight:500}.aid{font-size:11px;color:#6B7280;font-family:'Courier New',monospace;margin-top:2px}
.am{text-align:right;font-size:11px;color:#6B7280}
/* Campaign table */
.camp-table{width:100%;border-collapse:collapse;font-size:12px;margin-top:8px}
.camp-table th{text-align:left;font-size:11px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:.05em;padding:6px 8px;border-bottom:2px solid #E2E5EA;background:#F7F8FA}
.camp-table td{padding:8px;border-bottom:1px solid #E2E5EA;vertical-align:middle}
.camp-row{cursor:pointer;transition:background .15s}.camp-row:hover{background:#F0F4FF}
.camp-row:last-child td{border-bottom:none}
.camp-name{font-weight:500;color:#1A1D23;font-size:13px}.camp-id{font-family:'Courier New',monospace;font-size:10px;color:#9CA3AF;margin-top:1px}
/* Prompt helper */
.sel{width:100%;padding:9px 12px;border:1px solid #E2E5EA;border-radius:8px;background:#fff;color:#1A1D23;font-size:13px;margin-bottom:12px;cursor:pointer}
.prompt-box{background:#F7F8FA;border:1px solid #E2E5EA;border-radius:8px;padding:0;overflow:hidden}
.prompt-item{padding:12px 14px;border-bottom:1px solid #E2E5EA;cursor:pointer;transition:background .1s;display:flex;align-items:flex-start;justify-content:space-between;gap:12px}
.prompt-item:last-child{border-bottom:none}.prompt-item:hover{background:#EEF3FF}
.prompt-text{font-size:13px;color:#1A1D23;line-height:1.5;flex:1}
.prompt-copy{font-size:11px;font-weight:500;color:#1A56A0;white-space:nowrap;padding:3px 8px;border:1px solid #BFDBFE;border-radius:5px;background:#EFF6FF;flex-shrink:0;cursor:pointer}
.prompt-copy:hover{background:#DBEAFE}
.prompt-section{font-size:11px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:.06em;padding:10px 14px 6px;background:#F4F5F6;border-bottom:1px solid #E2E5EA}
.spinner{width:20px;height:20px;border:2px solid #E2E5EA;border-top-color:#1A56A0;border-radius:50%;animation:spin .7s linear infinite;margin:20px auto}
@keyframes spin{to{transform:rotate(360deg)}}
.empty-state{text-align:center;padding:24px;color:#6B7280;font-size:13px}
/* Permission tiles */
.perm-tile{border:1px solid #E2E5EA;border-radius:10px;padding:14px 16px;transition:border-color .2s}
.perm-tile.disabled{border-color:#FCA5A5;background:#FFF5F5}
.perm-header{display:flex;align-items:center;justify-content:space-between;gap:12px}
.perm-name{font-size:14px;font-weight:600;color:#1A1D23;margin-bottom:3px}
.perm-tools{font-size:11px;color:#6B7280;font-family:'Courier New',monospace}
/* Toggle switch */
.toggle{position:relative;display:inline-block;width:44px;height:24px;flex-shrink:0}
.toggle input{opacity:0;width:0;height:0}
.slider{position:absolute;cursor:pointer;top:0;left:0;right:0;bottom:0;background:#D1D5DB;border-radius:24px;transition:.2s}
.slider:before{position:absolute;content:"";height:18px;width:18px;left:3px;bottom:3px;background:#fff;border-radius:50%;transition:.2s}
.toggle input:checked + .slider{background:#1A56A0}
.toggle input:checked + .slider:before{transform:translateX(20px)}
@media(prefers-color-scheme:dark){
  .perm-tile{border-color:#2D3139;background:#1A1D23}
  .perm-tile.disabled{border-color:#7F1D1D;background:#2A0A0A}
  .perm-name{color:#F1F3F6}
  .slider{background:#4B5563}
}
/* Ring */
.ring-wrap{display:flex;align-items:center;gap:10px}
.ring-info{font-size:11px;color:#6B7280;text-align:right}
/* Login */
#login-screen{display:flex;align-items:center;justify-content:center;min-height:100vh;padding:24px}
.login-card{background:#fff;border:1px solid #E2E5EA;border-radius:12px;padding:40px;width:100%;max-width:360px;text-align:center}
.login-logo{font-size:12px;font-weight:600;color:#1A56A0;letter-spacing:.08em;text-transform:uppercase;margin-bottom:8px}
.login-title{font-size:22px;font-weight:600;margin-bottom:24px}
.login-card input[type=password]{width:100%;padding:10px 14px;border:1px solid #E2E5EA;border-radius:8px;font-size:15px;margin-bottom:12px;outline:none;text-align:center;letter-spacing:.15em}
.btn-primary{width:100%;padding:11px;background:#1A56A0;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:500;cursor:pointer}
.login-err{font-size:13px;color:#9B1C1C;margin-top:10px}
</style>
</head>
<body>
<?php if (!$loggedIn): ?>
<div id="login-screen">
  <div class="login-card">
    <div class="login-logo">Google Ads MCP</div>
    <div class="login-title">Dashboard</div>
    <form method="POST">
      <input type="password" name="pin" placeholder="••••••••" maxlength="64" autocomplete="off" required autofocus>
      <button type="submit" class="btn-primary">Sign in</button>
    </form>
    <?php if ($error): ?><div class="login-err"><?= h($error) ?></div><?php endif ?>
  </div>
</div>
<?php exit; endif; ?>

<div class="topbar">
  <div class="brand">Google Ads MCP · Dashboard</div>
  <div class="topbar-r">
    <span class="ver-pill">API <?= h($apiVer) ?></span>
    <a class="btn-out" href="?logout=1">Sign out</a>
  </div>
</div>

<div class="main">

  <!-- OAuth -->
  <div class="card">
    <div class="ct">OAuth connection</div>
    <?php
    $valid    = $tokenStatus['valid'] ?? false;
    $expSec   = $tokenStatus['expires_in_seconds'] ?? null;
    $refreshed= $tokenStatus['refreshed_at'] ?? 'Not yet';
    $pct      = $expSec !== null ? min(100, round($expSec / 3600 * 100)) : 0;
    $circ     = 2 * pi() * 16;
    $offset   = $circ - ($pct / 100 * $circ);
    $ringColor= $pct > 30 ? '#1B7A3E' : ($pct > 10 ? '#92500A' : '#9B1C1C');
    $expMin   = $expSec !== null ? floor($expSec / 60) : null;
    ?>
    <div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:14px">
      <span class="badge <?= $valid?'ok':'err' ?>"><?= $valid?'Connected':'Disconnected' ?></span>
      <?php if ($expMin !== null): ?>
      <div class="ring-wrap">
        <div class="ring-info">Expires in<br><strong><?= $expMin ?>m</strong></div>
        <svg width="40" height="40" viewBox="0 0 40 40">
          <circle cx="20" cy="20" r="16" fill="none" stroke="#E2E5EA" stroke-width="3.5"/>
          <circle cx="20" cy="20" r="16" fill="none" stroke="<?= $ringColor ?>" stroke-width="3.5"
            stroke-linecap="round" stroke-dasharray="<?= round($circ,2) ?>" stroke-dashoffset="<?= round($offset,2) ?>" transform="rotate(-90 20 20)"/>
          <text x="20" y="21" font-size="9" font-weight="600" fill="<?= $ringColor ?>" text-anchor="middle" dominant-baseline="central"><?= $pct ?>%</text>
        </svg>
      </div>
      <?php endif ?>
    </div>
    <div class="row"><span class="rl">MCC account</span><span class="rv mono"><?= h(GOOGLE_ADS_MCC_ID) ?></span></div>
    <div class="row"><span class="rl">Last refreshed</span><span class="rv"><?= h($refreshed) ?></span></div>
    <div class="row"><span class="rl">Auto-refresh</span><span class="rv"><span class="badge ok">Per-request</span></span></div>
    <div class="row"><span class="rl">Runtime</span><span class="rv">PHP <?= PHP_MAJOR_VERSION.'.'.PHP_MINOR_VERSION ?></span></div>
  </div>

  <!-- Stats -->
  <div class="card">
    <div class="ct">Tool call stats (last 24h)</div>
    <div class="stat-grid" style="margin-bottom:14px">
      <div class="stat"><div class="sl">Total calls</div><div class="sv"><?= (int)($stats['total_24h']??0) ?></div></div>
      <div class="stat"><div class="sl">Errors</div><div class="sv" style="color:<?= ($stats['errors_24h']??0)>0?'#9B1C1C':'inherit' ?>"><?= (int)($stats['errors_24h']??0) ?></div></div>
      <div class="stat"><div class="sl">Most used tool</div><div class="sv" style="font-size:12px;font-family:'Courier New',monospace"><?= h($stats['top_tool']??'—') ?></div></div>
      <div class="stat"><div class="sl">PHP memory</div><div class="sv" style="font-size:16px"><?= round(memory_get_peak_usage(true)/1048576,1) ?>MB</div></div>
    </div>
    <?php $isLatest=$apiVer==='v23.2'; $sunsetDate=$sunset[$apiVer]??'Unknown'; ?>
    <?php if ($isLatest): ?>
    <div class="vok"><span>✓</span><span>API <strong><?= h($apiVer) ?></strong> — current latest.</span></div>
    <?php else: ?>
    <div class="vwarn"><span>!</span><span>API <strong><?= h($apiVer) ?></strong> — not latest. Update to v23.2. Sunset: <?= h($sunsetDate) ?></span></div>
    <?php endif ?>
  </div>

  <!-- ── Tool Permissions ── -->
  <div class="card full" id="perms-card">
    <div class="ct">
      <span>Permission controls — enable/disable write tools</span>
      <span id="perms-saved" style="font-size:11px;color:#1B7A3E;display:none">✓ Saved</span>
    </div>
    <p style="font-size:13px;color:#6B7280;margin-bottom:6px">
      <strong>Read tools are always on</strong> — list, get, and report tools cannot be disabled. 
      Toggle individual write operations below. When disabled, Claude receives a clear message 
      telling it that tool is turned off and to ask you to enable it in the dashboard.
    </p>
    <div style="font-size:12px;padding:10px 12px;background:#EFF6FF;border-radius:8px;border-left:3px solid #1A56A0;margin-bottom:18px;color:#1A56A0;line-height:1.6">
      💡 <strong>Tip:</strong> For a read-only session, disable all groups. To safely review before acting, 
      leave reads on and enable writes only when you're ready to make changes.
    </div>
    <div id="perms-loading" style="text-align:center;padding:20px;color:#6B7280;font-size:13px">Loading permissions…</div>
    <div id="perms-grid" style="display:none"></div>
  </div>

  <!-- ── PROMPT HELPER — full width ── -->
  <div class="card full">
    <div class="ct">
      <span>Prompt helper — browse your accounts &amp; generate Claude prompts</span>
    </div>
    <p style="font-size:13px;color:#6B7280;margin-bottom:16px">Select an account, then browse campaigns, ad groups, and ads by name. Click any prompt to copy it — paste straight into Claude.</p>

    <?php if (empty($accounts)): ?>
      <div class="empty-state">No accounts found. Check your MCC credentials in config.php.</div>
    <?php else: ?>

    <!-- Account selector -->
    <label style="font-size:12px;font-weight:600;color:#6B7280;display:block;margin-bottom:6px">1. Select account</label>
    <select class="sel" id="acct-sel" onchange="loadCampaigns(this.value, this.options[this.selectedIndex].text)">
      <option value="">— choose an account —</option>
      <?php foreach ($accounts as $a): ?>
      <option value="<?= h((string)$a['id']) ?>"
              data-name="<?= h($a['name']) ?>"
              data-currency="<?= h($a['currency']??'') ?>">
        <?= h($a['name']) ?> (<?= h((string)$a['id']) ?>) · <?= h($a['currency']??'') ?>
      </option>
      <?php endforeach ?>
    </select>

    <!-- Campaign selector -->
    <div id="camp-section" style="display:none">
      <label style="font-size:12px;font-weight:600;color:#6B7280;display:block;margin-bottom:6px">2. Select campaign</label>
      <select class="sel" id="camp-sel" onchange="loadAdGroups(this.value, this.options[this.selectedIndex].text)">
        <option value="">— choose a campaign —</option>
      </select>
    </div>

    <!-- Ad group selector -->
    <div id="ag-section" style="display:none">
      <label style="font-size:12px;font-weight:600;color:#6B7280;display:block;margin-bottom:6px">3. Select ad group</label>
      <select class="sel" id="ag-sel" onchange="loadAds(this.value, this.options[this.selectedIndex].text)">
        <option value="">— choose an ad group —</option>
      </select>
    </div>

    <!-- Loading spinner -->
    <div id="spinner" style="display:none"><div class="spinner"></div></div>

    <!-- Prompts panel -->
    <div id="prompts-panel" style="display:none;margin-top:16px">
      <label style="font-size:12px;font-weight:600;color:#6B7280;display:block;margin-bottom:8px">Ready-to-use Claude prompts — click to copy</label>
      <div class="prompt-box" id="prompts-list"></div>
    </div>

    <!-- Campaign table -->
    <div id="camp-table-wrap" style="display:none;margin-top:20px">
      <div style="font-size:12px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:.06em;margin-bottom:8px">Campaigns in this account (last 30 days)</div>
      <div style="overflow-x:auto">
        <table class="camp-table">
          <thead><tr>
            <th>Campaign name</th><th>Status</th><th>Budget/day</th>
            <th>Impressions</th><th>Clicks</th><th>Cost</th><th>CTR</th><th>Conv.</th>
          </tr></thead>
          <tbody id="camp-tbody"></tbody>
        </table>
      </div>
    </div>

    <?php endif ?>
  </div>

  <!-- MCC accounts list -->
  <div class="card full">
    <div class="ct">MCC client accounts (<?= count($accounts) ?>)</div>
    <?php foreach ($accounts as $a): ?>
    <div class="acct" onclick="selectAccount('<?= h((string)$a['id']) ?>','<?= h(addslashes($a['name'])) ?>')">
      <div>
        <div class="an"><?= h($a['name']) ?></div>
        <div class="aid"><?= h((string)$a['id']) ?></div>
      </div>
      <div class="am">
        <span class="badge <?= $a['status']==='ENABLED'?'ok':'gray' ?>"><?= h($a['status']) ?></span>
        <div style="margin-top:3px"><?= h($a['currency']??'') ?> · <?= h($a['timezone']??'') ?></div>
      </div>
    </div>
    <?php endforeach ?>
  </div>

  <!-- Token rotation -->
  <div class="card">
    <div class="ct">MCP secret token</div>
    <div class="token-box"><?= h(substr(MCP_SECRET_TOKEN, 0, 8)) ?>••••••••••••••••••••••••••••••••••••••••••••••••<?= h(substr(MCP_SECRET_TOKEN, -4)) ?></div>
    <form method="POST" style="margin-top:10px">
      <div style="display:flex;gap:8px;margin-bottom:10px">
        <input type="text" name="new_token" id="new_token" placeholder="Paste or generate new 64-char token"
          style="flex:1;padding:9px 12px;border:1px solid #E2E5EA;border-radius:7px;font-size:12px;font-family:'Courier New',monospace;color:#1A1D23;background:#fff">
      </div>
      <div class="btn-row">
        <button type="button" class="btn-s" onclick="genToken()">Generate random</button>
        <button type="submit" class="btn-p">Save pending</button>
      </div>
    </form>
    <?php if ($actionMsg): ?>
    <div class="action-<?= h($actionType) ?>" style="margin-top:10px"><?= $actionMsg ?></div>
    <?php endif ?>
    <p style="font-size:11px;color:#6B7280;margin-top:10px;line-height:1.6">
      Copy the pending token into <code style="font-family:monospace">config.php</code> as MCP_SECRET_TOKEN and re-upload. Update your Claude config with the new token.
    </p>
  </div>

  <!-- Log -->
  <div class="card">
    <div class="ct">Recent tool calls (last 50)</div>
    <?php if (empty($logEntries)): ?>
      <p style="font-size:13px;color:#6B7280">No tool calls logged yet.</p>
    <?php else: ?>
    <div class="log-hdr"><span>Time</span><span>Tool</span><span>Account</span><span>Status</span></div>
    <?php foreach ($logEntries as $e): ?>
    <div class="log-row">
      <span class="lt"><?= h($e['time']) ?></span>
      <span class="lto"><?= h($e['tool']) ?></span>
      <span class="mono" style="font-size:11px;color:#6B7280"><?= h($e['cid']) ?></span>
      <span><span class="badge <?= $e['ok']?'ok':'err' ?>"><?= $e['ok']?'OK':'Err' ?></span></span>
    </div>
    <?php endforeach ?>
    <?php endif ?>
  </div>

</div><!-- /main -->

<script>
const BASE = location.pathname.replace(/\/$/, '');
let selAcctId = '', selAcctName = '', selCampId = '', selCampName = '', selAgId = '', selAgName = '';

function api(params) {
  return fetch(BASE + '?' + new URLSearchParams({ajax: '', ...params}))
    .then(r => r.json());
}

function spin(on) {
  document.getElementById('spinner').style.display = on ? 'block' : 'none';
}

function selectAccount(id, name) {
  document.getElementById('acct-sel').value = id;
  loadCampaigns(id, name);
}

async function loadCampaigns(cid, acctName) {
  selAcctId = cid; selAcctName = acctName;
  selCampId = selCampName = selAgId = selAgName = '';
  document.getElementById('camp-section').style.display = 'none';
  document.getElementById('ag-section').style.display = 'none';
  document.getElementById('camp-table-wrap').style.display = 'none';
  document.getElementById('prompts-panel').style.display = 'none';
  if (!cid) return;

  spin(true);
  const [camps, report] = await Promise.all([
    api({ajax: 'campaigns', cid}),
    api({ajax: 'campaign_report', cid})
  ]);
  spin(false);

  // Populate campaign dropdown
  const campSel = document.getElementById('camp-sel');
  campSel.innerHTML = '<option value="">— choose a campaign —</option>';
  (camps.data || []).forEach(c => {
    const opt = document.createElement('option');
    opt.value = c.id;
    opt.textContent = c.name + (c.status !== 'ENABLED' ? ' [' + c.status + ']' : '');
    campSel.appendChild(opt);
  });
  document.getElementById('camp-section').style.display = 'block';

  // Build campaign table with metrics
  const reportMap = {};
  (report.data || []).forEach(c => { reportMap[c.id] = c; });
  const tbody = document.getElementById('camp-tbody');
  tbody.innerHTML = '';
  (camps.data || []).forEach(c => {
    const m = reportMap[c.id]?.metrics || {};
    const statusClass = c.status === 'ENABLED' ? 'ok' : c.status === 'PAUSED' ? 'paused' : 'gray';
    const tr = document.createElement('tr');
    tr.className = 'camp-row';
    tr.innerHTML = `
      <td><div class="camp-name">${esc(c.name)}</div><div class="camp-id">${esc(String(c.id))}</div></td>
      <td><span class="badge ${statusClass}">${esc(c.status)}</span></td>
      <td style="font-size:12px">${c.daily_budget ? '$'+parseFloat(c.daily_budget).toFixed(2) : '—'}</td>
      <td style="font-size:12px">${fmt(m.impressions)}</td>
      <td style="font-size:12px">${fmt(m.clicks)}</td>
      <td style="font-size:12px">${m.cost ? '$'+parseFloat(m.cost).toFixed(2) : '—'}</td>
      <td style="font-size:12px">${m.ctr || '—'}</td>
      <td style="font-size:12px">${m.conversions || '—'}</td>`;
    tr.onclick = () => {
      document.getElementById('camp-sel').value = c.id;
      loadAdGroups(c.id, c.name);
    };
    tbody.appendChild(tr);
  });
  document.getElementById('camp-table-wrap').style.display = 'block';

  showAccountPrompts();
}

async function loadAdGroups(campId, campName) {
  selCampId = campId; selCampName = campName;
  selAgId = selAgName = '';
  document.getElementById('ag-section').style.display = 'none';
  document.getElementById('prompts-panel').style.display = 'none';
  if (!campId || !selAcctId) return;

  spin(true);
  const r = await api({ajax: 'adgroups', cid: selAcctId, camp_id: campId});
  spin(false);

  const agSel = document.getElementById('ag-sel');
  agSel.innerHTML = '<option value="">— choose an ad group —</option>';
  (r.data || []).forEach(ag => {
    const opt = document.createElement('option');
    opt.value = ag.id;
    opt.textContent = ag.name + (ag.status !== 'ENABLED' ? ' [' + ag.status + ']' : '');
    agSel.appendChild(opt);
  });
  document.getElementById('ag-section').style.display = 'block';

  showCampaignPrompts(r.data || []);
}

async function loadAds(agId, agName) {
  selAgId = agId; selAgName = agName;
  if (!agId || !selAcctId) return;

  spin(true);
  const r = await api({ajax: 'ads', cid: selAcctId, ag_id: agId});
  spin(false);

  showAdGroupPrompts(r.data || []);
}

function showAccountPrompts() {
  const acct = selAcctName;
  const cid  = selAcctId;
  const prompts = [
    { section: 'Account overview', items: [
      `Show me a performance summary for "${acct}" for the last 30 days`,
      `Get the campaign report for account ${cid} for this month`,
      `Show me all campaigns in "${acct}" with their status and daily budget`,
      `What are the top 5 campaigns by cost in "${acct}" for last 30 days?`,
      `Show me the search terms report for "${acct}" this month`,
      `List all paused campaigns in "${acct}"`,
    ]},
    { section: 'Quick actions', items: [
      `List all campaigns in "${acct}" so I can decide which ones to pause`,
      `Show me keyword performance for "${acct}" for last 7 days`,
      `Get the ad report for "${acct}" for this month`,
    ]},
  ];
  renderPrompts(prompts);
}

function showCampaignPrompts(adGroups) {
  const acct = selAcctName;
  const camp = selCampName;
  const agList = adGroups.slice(0,5).map(ag => '"' + ag.name + '"').join(', ');
  const prompts = [
    { section: 'Campaign: ' + camp, items: [
      `Show me performance for campaign "${camp}" in "${acct}" for last 30 days`,
      `Pause campaign "${camp}" in "${acct}"`,
      `Enable campaign "${camp}" in "${acct}"`,
      `Set the daily budget for campaign "${camp}" in "${acct}" to $50`,
      `Show me all ad groups in campaign "${camp}"`,
      `Show me the keyword report for campaign "${camp}" this month`,
      `Show me the search terms report for campaign "${camp}" this month`,
      `Show me all ads in campaign "${camp}"`,
    ]},
    ...(adGroups.length ? [{ section: 'Ad groups in this campaign', items: [
      `Show me performance for ad group "${adGroups[0]?.name}" in campaign "${camp}"`,
      `Pause ad group "${adGroups[0]?.name}" in "${acct}"`,
      `Add keywords to ad group "${adGroups[0]?.name}" in campaign "${camp}": [list your keywords here]`,
      `Show me all ads in ad group "${adGroups[0]?.name}"`,
    ]}] : []),
  ];
  renderPrompts(prompts);
}

function showAdGroupPrompts(ads) {
  const acct = selAcctName;
  const camp = selCampName;
  const ag   = selAgName;
  const adList = ads.slice(0,3).map(a => a.headlines?.[0] || a.name || 'Ad').join(', ');
  const prompts = [
    { section: 'Ad group: ' + ag, items: [
      `Show me performance for ad group "${ag}" in campaign "${camp}"`,
      `Pause ad group "${ag}" in "${acct}"`,
      `Enable ad group "${ag}" in "${acct}"`,
      `Add these keywords to ad group "${ag}" in campaign "${camp}": [list your keywords here]`,
      `Add negative keywords to ad group "${ag}": [list negatives here]`,
      `Show me the keyword report for ad group "${ag}" this month`,
    ]},
    { section: 'Ads in this ad group' + (ads.length ? ' (' + ads.length + ' ads)' : ''), items: [
      `Show me all ads in ad group "${ag}" in campaign "${camp}"`,
      `Pause all ads in ad group "${ag}" in "${acct}"`,
      ...(ads.length ? [
        `Show me performance for ads in ad group "${ag}" for last 30 days`,
        `Create a new Responsive Search Ad in ad group "${ag}" in campaign "${camp}" with final URL [your URL], headlines: [h1], [h2], [h3], descriptions: [d1], [d2]`,
      ] : []),
    ]},
  ];
  renderPrompts(prompts);
}

function renderPrompts(sections) {
  const list = document.getElementById('prompts-list');
  list.innerHTML = '';
  sections.forEach(sec => {
    const sh = document.createElement('div');
    sh.className = 'prompt-section';
    sh.textContent = sec.section;
    list.appendChild(sh);
    sec.items.forEach(text => {
      const row = document.createElement('div');
      row.className = 'prompt-item';
      row.innerHTML = `<span class="prompt-text">${esc(text)}</span><button class="prompt-copy" onclick="copyPrompt(this,'${esc(text).replace(/'/g,"\\'")}')">Copy</button>`;
      list.appendChild(row);
    });
  });
  document.getElementById('prompts-panel').style.display = 'block';
}

function copyPrompt(btn, text) {
  navigator.clipboard.writeText(text).then(() => {
    btn.textContent = '✓ Copied';
    btn.style.background = '#EAF5EE';
    btn.style.color = '#1B7A3E';
    btn.style.borderColor = '#A7F3D0';
    setTimeout(() => {
      btn.textContent = 'Copy';
      btn.style.background = btn.style.color = btn.style.borderColor = '';
    }, 2000);
  });
}

function esc(s) {
  return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;');
}
function fmt(n) {
  if (n === undefined || n === null) return '—';
  return Number(n).toLocaleString();
}
// ── Permissions ───────────────────────────────────────────────────────────────
let _permsData = {};
let _permsTools = {};

const GROUP_ORDER = ['Campaigns','Ad Groups','Ads','Keywords'];

async function loadPermissions() {
  const r = await fetch(BASE + '?ajax=get_permissions');
  const d = await r.json();
  if (!d.ok) return;
  _permsData  = d.data || {};
  _permsTools = d.tools || {};
  renderPermissionsGrid();
  document.getElementById('perms-loading').style.display = 'none';
  document.getElementById('perms-grid').style.display = 'block';
}

function renderPermissionsGrid() {
  // Group tools by category
  const groups = {};
  Object.entries(_permsTools).forEach(([toolName, info]) => {
    const g = info.group || 'Other';
    if (!groups[g]) groups[g] = [];
    groups[g].push({ toolName, ...info });
  });

  const grid = document.getElementById('perms-grid');
  grid.style.display = 'grid';
  grid.style.gridTemplateColumns = 'repeat(auto-fill,minmax(260px,1fr))';
  grid.style.gap = '14px';
  grid.innerHTML = '';

  GROUP_ORDER.forEach(groupName => {
    if (!groups[groupName]) return;
    const col = document.createElement('div');
    col.innerHTML = `<div style="font-size:11px;font-weight:600;color:#6B7280;text-transform:uppercase;letter-spacing:.07em;margin-bottom:8px;padding-bottom:6px;border-bottom:2px solid #E2E5EA">${esc(groupName)}</div>`;
    groups[groupName].forEach(({ toolName, label, danger }) => {
      const enabled = _permsData[toolName] !== false;
      const dangerStyle = danger ? 'color:#9B1C1C' : '';
      const row = document.createElement('div');
      row.id = 'prow-' + toolName;
      row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:8px 10px;border-radius:7px;margin-bottom:4px;background:' + (enabled ? '#F7F8FA' : '#FEE2E2') + ';transition:background .2s';
      row.innerHTML = `
        <div>
          <div style="font-size:13px;font-weight:500;${dangerStyle}">${esc(label)}${danger ? ' ⚠️' : ''}</div>
          <div style="font-size:10px;font-family:'Courier New',monospace;color:#9CA3AF;margin-top:1px">${esc(toolName)}</div>
        </div>
        <label class="toggle" style="flex-shrink:0;margin-left:12px">
          <input type="checkbox" ${enabled ? 'checked' : ''} onchange="savePermission('${toolName}',this.checked)">
          <span class="slider"></span>
        </label>`;
      col.appendChild(row);
    });
    grid.appendChild(col);
  });

  // Always-on readonly tools notice
  const notice = document.createElement('div');
  notice.style.cssText = 'grid-column:1/-1;padding:10px 14px;background:#EFF6FF;border-radius:8px;border-left:3px solid #1A56A0;font-size:12px;color:#1A56A0;line-height:1.6;margin-top:4px';
  notice.innerHTML = '🔒 <strong>Always enabled (read-only):</strong> list_accounts, get_account, run_gaql, get_account_summary, get_campaign_report, get_ad_group_report, get_keyword_report, get_ad_report, get_search_terms, list_campaigns, get_campaign, list_ad_groups, list_ads<br><strong>When a tool is disabled:</strong> Claude receives a message saying the tool is turned off and to ask you to enable it in the dashboard.';
  grid.appendChild(notice);
}

async function savePermission(toolName, enabled) {
  const row = document.getElementById('prow-' + toolName);
  if (row) row.style.background = enabled ? '#F7F8FA' : '#FEE2E2';
  _permsData[toolName] = enabled;

  const r = await fetch(BASE + '?ajax=save_permissions', {
    method: 'POST',
    headers: { 'Content-Type': 'application/json' },
    body: JSON.stringify({ [toolName]: enabled })
  });
  const d = await r.json();
  if (d.ok) {
    const saved = document.getElementById('perms-saved');
    saved.style.display = 'inline';
    setTimeout(() => { saved.style.display = 'none'; }, 2500);
  }
}

loadPermissions();

function genToken() {
  const arr = new Uint8Array(32);
  crypto.getRandomValues(arr);
  document.getElementById('new_token').value = Array.from(arr).map(b=>b.toString(16).padStart(2,'0')).join('');
}
</script>
</body>
</html>
