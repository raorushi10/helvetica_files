<?php
/* ════════════════════════════════════════════════════════════
   HELVETICA — SUPER ADMIN PANEL
   Separate, higher-privilege login from the regular admin panel.
   ════════════════════════════════════════════════════════════ */
session_start();
require_once __DIR__ . '/maintenance-check.php';

define('SA_EMAIL', 'superadminrv@gmail.com');
// bcrypt hash of: Rush!@rao2010
define('SA_PASS_HASH', '$2y$10$P2PsUj/VU25SE5NnkhRbqulyFU9XZwOzhO3KNUxzvy1cCtUvTHWPa');

define('SA_DATA_DIR', __DIR__);
define('DB_FILE',   SA_DATA_DIR . '/helvetica_data.json');
define('SUBS_FILE', SA_DATA_DIR . '/helvetica_subscribers.json');
define('SA_LOG_FILE', SA_DATA_DIR . '/superadmin_log.json');

// ─── HELPERS ─────────────────────────────────────────────────
function hv_load_db_sa() {
    if (!file_exists(DB_FILE)) return ['users'=>[],'orders'=>[],'user_orders'=>[]];
    return json_decode(file_get_contents(DB_FILE), true) ?: ['users'=>[],'orders'=>[],'user_orders'=>[]];
}
function load_subscribers_sa() {
    if (!file_exists(SUBS_FILE)) return [];
    return json_decode(file_get_contents(SUBS_FILE), true) ?: [];
}
function sa_load_log() {
    if (!file_exists(SA_LOG_FILE)) return [];
    return json_decode(file_get_contents(SA_LOG_FILE), true) ?: [];
}
function sa_add_log($action) {
    $log = sa_load_log();
    array_unshift($log, ['action' => $action, 'at' => date('d M Y, h:i A')]);
    $log = array_slice($log, 0, 50);
    file_put_contents(SA_LOG_FILE, json_encode($log, JSON_PRETTY_PRINT));
}

// ─── AUTH ────────────────────────────────────────────────────
$sa_error = '';
$sa_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['sa_login'])) {
    $email = trim($_POST['sa_email'] ?? '');
    $pass  = (string)($_POST['sa_pass'] ?? '');
    if ($email === SA_EMAIL && password_verify($pass, SA_PASS_HASH)) {
        $_SESSION['sa_admin'] = true;
        sa_add_log('Super admin logged in');
    } else {
        $sa_error = 'Invalid credentials. Access denied.';
    }
}
if (isset($_GET['sa_logout'])) {
    if (!empty($_SESSION['sa_admin'])) sa_add_log('Super admin logged out');
    unset($_SESSION['sa_admin']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

// ─── SUPER ADMIN ACTIONS ─────────────────────────────────────
if (!empty($_SESSION['sa_admin'])) {

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['toggle_maintenance'])) {
        $settings = sa_load_settings();
        $settings['maintenance_mode'] = empty($settings['maintenance_mode']);
        sa_save_settings($settings);
        sa_add_log($settings['maintenance_mode'] ? 'Turned MAINTENANCE MODE ON' : 'Turned MAINTENANCE MODE OFF');
        $sa_msg = $settings['maintenance_mode'] ? 'Maintenance mode is now ON. The storefront is offline to visitors.' : 'Maintenance mode is now OFF. The storefront is live again.';
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['save_maintenance_copy'])) {
        $settings = sa_load_settings();
        $settings['maintenance_title']   = trim($_POST['maintenance_title'] ?? $settings['maintenance_title']);
        $settings['maintenance_message'] = trim($_POST['maintenance_message'] ?? $settings['maintenance_message']);
        sa_save_settings($settings);
        sa_add_log('Updated maintenance page text');
        $sa_msg = 'Maintenance page text updated.';
    }
}

$settings = sa_load_settings();

// ─── STATS (for overview only) ────────────────────────────────
$db      = hv_load_db_sa();
$orders  = $db['orders'] ?? [];
$users   = $db['users'] ?? [];
$subs    = load_subscribers_sa();
$total_revenue = 0;
foreach ($orders as $o) $total_revenue += (int) preg_replace('/[^0-9]/', '', $o['total'] ?? '0');

$preview_url = strtok($_SERVER['REQUEST_URI'], '?');
$preview_url = preg_replace('#/superadmin\.php$#', '/', $preview_url);
$preview_link = $preview_url . '?sa_preview=' . SA_PREVIEW_KEY;

$log = sa_load_log();
?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>Super Admin — HELVETICA</title>
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&family=Syne:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
:root{
  --accent:#ff3b3b;
  --accent2:#ffb020;
  --dark:#0c1a2b;
  --surface:#f8f9fa;
  --border:rgba(12,26,43,0.1);
  --text:#1f1f1f;
  --muted:rgba(31,31,31,0.5);
  --font:'Space Grotesk',sans-serif;
  --mono:'Space Mono',monospace;
  --syne:'Syne',sans-serif;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{font-size:62.5%;}
body{font-family:var(--font);font-size:1.4rem;background:#f1f3f6;color:var(--text);min-height:100vh;}
a{text-decoration:none;color:inherit;}
button{cursor:pointer;border:none;font-family:inherit;}
input,textarea{font-family:inherit;}

/* login */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#1a0c0c 0%,#2a1414 50%,#1a0c0c 100%);position:relative;overflow:hidden;}
.login-wrap::before{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(255,59,59,0.05) 1px,transparent 1px),linear-gradient(90deg,rgba(255,59,59,0.05) 1px,transparent 1px);background-size:40px 40px;}
.login-box{background:#fff;width:100%;max-width:420px;padding:4rem;position:relative;z-index:2;box-shadow:0 40px 80px rgba(0,0,0,.5);margin:2rem;}
.login-logo{font-family:var(--syne);font-size:2.6rem;font-weight:800;color:var(--dark);letter-spacing:-.05em;}
.login-logo span{color:var(--accent);}
.login-sub{font-size:1.15rem;color:var(--muted);letter-spacing:.12em;text-transform:uppercase;margin:.6rem 0 3rem;font-family:var(--mono);}
.login-group{margin-bottom:1.6rem;}
.login-group label{display:block;font-size:1.05rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.6rem;}
.login-group input{width:100%;border:2px solid rgba(31,31,31,.15);padding:1.2rem 1.4rem;font-size:1.4rem;outline:none;transition:border .2s;}
.login-group input:focus{border-color:var(--accent);}
.login-err{background:#fff0f0;border:1.5px solid #e00;color:#c00;padding:1rem 1.4rem;font-size:1.2rem;margin-bottom:1.6rem;}
.login-btn{width:100%;background:var(--dark);color:var(--accent2);font-size:1.3rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;padding:1.5rem;transition:all .3s;}
.login-btn:hover{background:var(--accent);color:#fff;}

/* layout */
.topbar{background:var(--dark);padding:1.8rem 3.2rem;display:flex;align-items:center;justify-content:space-between;}
.brand{font-family:var(--syne);font-size:2rem;font-weight:800;color:#fff;letter-spacing:-.03em;}
.brand span{color:var(--accent);}
.brand small{display:block;font-size:1rem;color:rgba(255,255,255,.4);letter-spacing:.15em;text-transform:uppercase;font-family:var(--mono);font-weight:700;margin-top:.2rem;}
.logout-link{color:rgba(255,255,255,.6);font-size:1.2rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;border:1.5px solid rgba(255,255,255,.2);padding:.9rem 1.6rem;transition:all .2s;}
.logout-link:hover{background:#fff;color:var(--dark);}

.content{max-width:1100px;margin:0 auto;padding:3.2rem 2.4rem;}
.msg-banner{background:#f0fff0;border:1.5px solid #4caf50;color:#1b5e20;padding:1.4rem 1.8rem;font-size:1.3rem;margin-bottom:2.4rem;}

.status-card{background:#fff;border:1.5px solid var(--border);padding:3rem;margin-bottom:2.4rem;display:flex;align-items:center;justify-content:space-between;gap:2rem;flex-wrap:wrap;}
.status-card.on{border-color:var(--accent);background:#fff6f6;}
.status-left{display:flex;align-items:center;gap:2rem;}
.status-dot{width:1.6rem;height:1.6rem;border-radius:50%;background:#4caf50;flex-shrink:0;}
.status-card.on .status-dot{background:var(--accent);box-shadow:0 0 0 6px rgba(255,59,59,.15);}
.status-text h2{font-family:var(--syne);font-size:2rem;font-weight:700;margin-bottom:.4rem;}
.status-text p{color:var(--muted);font-size:1.3rem;}

.switch{position:relative;display:inline-block;width:6.4rem;height:3.4rem;flex-shrink:0;}
.switch input{opacity:0;width:0;height:0;}
.slider{position:absolute;cursor:pointer;inset:0;background:#4caf50;transition:.3s;border-radius:999px;}
.slider::before{content:'';position:absolute;height:2.6rem;width:2.6rem;left:.4rem;bottom:.4rem;background:#fff;transition:.3s;border-radius:50%;}
.switch input:checked + .slider{background:var(--accent);}
.switch input:checked + .slider::before{transform:translateX(3rem);}

.cards-2{display:grid;grid-template-columns:2fr 1fr;gap:2rem;margin-bottom:2.4rem;}
@media(max-width:800px){.cards-2{grid-template-columns:1fr;}}
.card{background:#fff;border:1.5px solid var(--border);padding:2.6rem;}
.card h3{font-family:var(--syne);font-size:1.7rem;font-weight:700;margin-bottom:2rem;}
.form-group{margin-bottom:1.6rem;}
.form-group label{display:block;font-size:1.05rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.6rem;font-family:var(--mono);}
.form-group input,.form-group textarea{width:100%;border:1.5px solid var(--border);padding:1.1rem 1.4rem;font-size:1.3rem;outline:none;resize:vertical;}
.form-group input:focus,.form-group textarea:focus{border-color:var(--dark);}
.form-submit{background:var(--dark);color:#fff;font-size:1.2rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:1.2rem 2rem;transition:all .2s;}
.form-submit:hover{background:var(--accent);}

.stat-row{display:flex;justify-content:space-between;padding:1rem 0;border-bottom:1px dashed var(--border);font-size:1.3rem;}
.stat-row:last-child{border-bottom:none;}
.stat-row strong{font-family:var(--mono);}

.preview-box{background:var(--surface);border:1.5px dashed var(--border);padding:1.6rem;font-size:1.2rem;font-family:var(--mono);word-break:break-all;margin-top:1rem;color:var(--dark);}
.copy-btn{margin-top:1rem;background:var(--dark);color:#fff;font-size:1.1rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:.9rem 1.6rem;}
.copy-btn:hover{background:var(--accent);}

.quick-links{display:flex;flex-direction:column;gap:1rem;}
.quick-links a{display:block;text-align:center;padding:1.3rem;font-size:1.2rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;background:var(--surface);border:1.5px solid var(--border);transition:all .2s;}
.quick-links a:hover{background:var(--dark);color:#fff;}

.log-list{display:flex;flex-direction:column;gap:.2rem;max-height:280px;overflow-y:auto;}
.log-item{display:flex;justify-content:space-between;padding:1rem 0;border-bottom:1px solid var(--border);font-size:1.2rem;}
.log-item:last-child{border-bottom:none;}
.log-item span:first-child{color:var(--text);}
.log-item span:last-child{color:var(--muted);font-family:var(--mono);font-size:1.05rem;white-space:nowrap;margin-left:1rem;}
</style>
</head>
<body>

<?php if (empty($_SESSION['sa_admin'])): ?>

  <div class="login-wrap">
    <div class="login-box">
      <div class="login-logo">HELVETICA<span>.</span></div>
      <div class="login-sub">Super Admin Access</div>
      <?php if ($sa_error): ?><div class="login-err"><?php echo htmlspecialchars($sa_error); ?></div><?php endif; ?>
      <form method="POST">
        <input type="hidden" name="sa_login" value="1">
        <div class="login-group">
          <label>Email</label>
          <input type="email" name="sa_email" required autofocus>
        </div>
        <div class="login-group">
          <label>Password</label>
          <input type="password" name="sa_pass" required>
        </div>
        <button type="submit" class="login-btn">Log In</button>
      </form>
    </div>
  </div>

<?php else: ?>

  <div class="topbar">
    <div class="brand">HELVETICA<span>.</span><small>Super Admin</small></div>
    <a href="?sa_logout=1" class="logout-link">Logout</a>
  </div>

  <div class="content">

    <?php if ($sa_msg): ?><div class="msg-banner"><?php echo htmlspecialchars($sa_msg); ?></div><?php endif; ?>

    <!-- MAINTENANCE TOGGLE -->
    <div class="status-card <?php echo !empty($settings['maintenance_mode']) ? 'on' : ''; ?>">
      <div class="status-left">
        <div class="status-dot"></div>
        <div class="status-text">
          <h2><?php echo !empty($settings['maintenance_mode']) ? 'Maintenance Mode: ON' : 'Site is Live'; ?></h2>
          <p><?php echo !empty($settings['maintenance_mode']) ? 'Visitors see the maintenance page. Last changed ' . htmlspecialchars($settings['updated_at']) . '.' : 'Storefront is publicly accessible as normal.'; ?></p>
        </div>
      </div>
      <form method="POST" onsubmit="return confirm('<?php echo !empty($settings['maintenance_mode']) ? 'Turn maintenance mode OFF and bring the site back online?' : 'Turn maintenance mode ON? Visitors will not be able to use the site until you turn it off.'; ?>');">
        <input type="hidden" name="toggle_maintenance" value="1">
        <label class="switch">
          <input type="checkbox" onchange="this.form.submit()" <?php echo !empty($settings['maintenance_mode']) ? 'checked' : ''; ?>>
          <span class="slider"></span>
        </label>
      </form>
    </div>

    <div class="cards-2">
      <div class="card">
        <h3>Maintenance Page Text</h3>
        <form method="POST">
          <input type="hidden" name="save_maintenance_copy" value="1">
          <div class="form-group">
            <label>Title</label>
            <input type="text" name="maintenance_title" value="<?php echo htmlspecialchars($settings['maintenance_title']); ?>">
          </div>
          <div class="form-group">
            <label>Message</label>
            <textarea name="maintenance_message" rows="4"><?php echo htmlspecialchars($settings['maintenance_message']); ?></textarea>
          </div>
          <button type="submit" class="form-submit">Save Text</button>
        </form>

        <h3 style="margin-top:2.8rem;">Preview Link (bypass maintenance)</h3>
        <p style="color:var(--muted);font-size:1.2rem;">Use this link to browse the live storefront yourself while maintenance mode is ON — visitors without it still see the maintenance page.</p>
        <div class="preview-box" id="previewLink"><?php echo htmlspecialchars($preview_link); ?></div>
        <button class="copy-btn" onclick="navigator.clipboard.writeText(document.getElementById('previewLink').textContent);this.textContent='Copied!';setTimeout(()=>this.textContent='Copy Link',1500);">Copy Link</button>
      </div>

      <div>
        <div class="card" style="margin-bottom:2.4rem;">
          <h3>Store Snapshot</h3>
          <div class="stat-row"><span>Total Orders</span><strong><?php echo count($orders); ?></strong></div>
          <div class="stat-row"><span>Total Customers</span><strong><?php echo count($users); ?></strong></div>
          <div class="stat-row"><span>Subscribers</span><strong><?php echo count($subs); ?></strong></div>
          <div class="stat-row"><span>Total Revenue</span><strong>₹<?php echo number_format($total_revenue); ?></strong></div>
        </div>

        <div class="card">
          <h3>Quick Links</h3>
          <div class="quick-links">
            <a href="/" target="_blank">View Storefront ↗</a>
            <a href="helvetica-admin.php" target="_blank">Regular Admin Panel ↗</a>
          </div>
        </div>
      </div>
    </div>

    <div class="card">
      <h3>Activity Log</h3>
      <div class="log-list">
        <?php if ($log): foreach ($log as $l): ?>
          <div class="log-item"><span><?php echo htmlspecialchars($l['action']); ?></span><span><?php echo htmlspecialchars($l['at']); ?></span></div>
        <?php endforeach; else: ?>
          <p style="color:var(--muted);">No activity yet.</p>
        <?php endif; ?>
      </div>
    </div>

  </div>

<?php endif; ?>
</body>
</html>