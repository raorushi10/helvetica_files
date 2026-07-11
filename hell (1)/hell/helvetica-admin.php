<?php
/* HELVETICA ADMIN PANEL */
session_start();

define('ADMIN_EMAIL', 'teamhelvetica0@gmail.com');
define('ADMIN_PASS',  'helvetica@team');
define('DB_FILE',     __DIR__ . '/helvetica_data.json');
define('SUBS_FILE',   __DIR__ . '/helvetica_subscribers.json');

// ─── SMTP (Brevo) ────────────────────────────────────────────
define('SMTP_HOST', 'smtp-relay.brevo.com');
define('SMTP_PORT', 587);
define('SMTP_USER', 'teamhelvetica0@gmail.com');
define('SMTP_PASS', 'xsmtpsib-795574bd85f0c910a96b8179d5212ccd89deca0a990fdd9a978befc9b7f6d174-wc3ZShvkZmARasgz');
define('SMTP_FROM', 'teamhelvetica0@gmail.com');
define('SMTP_NAME', 'HELVETICA Store');

// ─── HELPERS ─────────────────────────────────────────────────
function hv_load_db() {
    if (!file_exists(DB_FILE)) return ['users'=>[],'orders'=>[],'user_orders'=>[]];
    return json_decode(file_get_contents(DB_FILE), true) ?: ['users'=>[],'orders'=>[],'user_orders'=>[]];
}
function hv_save_db($db) {
    file_put_contents(DB_FILE, json_encode($db, JSON_PRETTY_PRINT));
}
function load_subscribers() {
    if (!file_exists(SUBS_FILE)) return [];
    return json_decode(file_get_contents(SUBS_FILE), true) ?: [];
}
function save_subscribers($subs) {
    file_put_contents(SUBS_FILE, json_encode($subs, JSON_PRETTY_PRINT));
}

function send_mail_admin($to, $subject, $html_body, $reply_to = '') {
    $log = __DIR__ . '/mail_debug.log';
    if (!class_exists('PHPMailer\PHPMailer\PHPMailer') && !class_exists('PHPMailer')) {
        if (defined('ABSPATH')) {
            foreach ([
                ABSPATH.'wp-includes/PHPMailer/PHPMailer.php',
                ABSPATH.'wp-includes/PHPMailer/SMTP.php',
                ABSPATH.'wp-includes/PHPMailer/Exception.php',
                ABSPATH.'wp-includes/class-phpmailer.php',
                ABSPATH.'wp-includes/class-smtp.php',
            ] as $p) { if (file_exists($p)) require_once $p; }
        }
    }
    $cls = class_exists('PHPMailer\PHPMailer\PHPMailer') ? 'PHPMailer\PHPMailer\PHPMailer'
         : (class_exists('PHPMailer') ? 'PHPMailer' : null);
    if (!$cls) {
        file_put_contents($log, date('Y-m-d H:i:s')." PHPMailer not found\n", FILE_APPEND);
        return false;
    }
    try {
        $mail = new $cls(true);
        $mail->isSMTP();
        $mail->Host       = SMTP_HOST;
        $mail->SMTPAuth   = true;
        $mail->Username   = SMTP_USER;
        $mail->Password   = SMTP_PASS;
        $mail->SMTPSecure = defined('PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS')
                            ? PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS : 'tls';
        $mail->Port       = SMTP_PORT;
        $mail->CharSet    = 'UTF-8';
        $mail->setFrom(SMTP_FROM, SMTP_NAME);
        $mail->addAddress($to);
        if ($reply_to) $mail->addReplyTo($reply_to);
        $mail->isHTML(true);
        $mail->Subject = $subject;
        $mail->Body    = $html_body;
        $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','</p>','</tr>'],"\n",$html_body));
        $sent = $mail->send();
        file_put_contents($log, date('Y-m-d H:i:s')."  SENT to $to\n", FILE_APPEND);
        return $sent;
    } catch (Exception $e) {
        $err = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
        file_put_contents($log, date('Y-m-d H:i:s')."  FAIL to $to — $err\n", FILE_APPEND);
        $hdrs = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: ".SMTP_NAME." <".SMTP_FROM.">\r\n";
        if ($reply_to) $hdrs .= "Reply-To: $reply_to\r\n";
        return @mail($to, $subject, $html_body, $hdrs);
    }
}

function build_admin_email_html($subject, $body_html) {
    return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head>
<body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
<table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0;">
<tr><td align="center">
<table width="580" cellpadding="0" cellspacing="0" style="background:#fff;max-width:580px;">
  <tr><td style="background:#0c1a2b;padding:24px 32px;">
    <h1 style="margin:0;font-size:24px;font-weight:800;color:#b6ff3b;letter-spacing:-1px;">HELVETICA</h1>
    <p style="margin:4px 0 0;color:rgba(255,255,255,0.45);font-size:11px;letter-spacing:2px;text-transform:uppercase;">Official Communication</p>
  </td></tr>
  <tr><td style="padding:32px;">'.$body_html.'</td></tr>
  <tr><td style="background:#0c1a2b;padding:18px 32px;text-align:center;">
    <p style="margin:0;font-size:11px;color:rgba(255,255,255,0.35);">© 2026 HELVETICA Fashion Private Limited. All Rights Reserved.</p>
  </td></tr>
</table></td></tr></table></body></html>';
}

// ─── AUTH ─────────────────────────────────────────────────────
$admin_error = '';
$admin_msg   = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['admin_login'])) {
    if ($_POST['admin_email'] === ADMIN_EMAIL && $_POST['admin_pass'] === ADMIN_PASS) {
        $_SESSION['hv_admin'] = true;
    } else {
        $admin_error = 'Invalid credentials. Access denied.';
    }
}
if (isset($_GET['admin_logout'])) {
    unset($_SESSION['hv_admin']);
    header('Location: ' . strtok($_SERVER['REQUEST_URI'],'?'));
    exit;
}

// ─── ADMIN ACTIONS ────────────────────────────────────────────
if (!empty($_SESSION['hv_admin'])) {

    // Update order status
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['update_order_status'])) {
        $db = hv_load_db();
        $oid = $_POST['order_id'] ?? '';
        $ns  = $_POST['new_status'] ?? '';
        foreach ($db['orders'] as &$o) {
            if ($o['order_id'] === $oid) { $o['status'] = $ns; break; }
        } unset($o);
        hv_save_db($db);
        $admin_msg = "Order $oid status updated to $ns.";
    }

    // Delete order
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_order'])) {
        $db = hv_load_db();
        $oid = $_POST['order_id'] ?? '';
        $db['orders'] = array_values(array_filter($db['orders'], fn($o) => $o['order_id'] !== $oid));
        hv_save_db($db);
        $admin_msg = "Order $oid deleted.";
    }

    // Delete user
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['delete_user'])) {
        $db  = hv_load_db();
        $ue  = $_POST['user_email'] ?? '';
        unset($db['users'][$ue]);
        unset($db['user_orders'][$ue]);
        hv_save_db($db);
        $admin_msg = "User $ue deleted.";
    }

    // Mail single customer
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mail_customer'])) {
        $to      = trim($_POST['mail_to'] ?? '');
        $subj    = trim($_POST['mail_subject'] ?? '');
        $body_t  = trim($_POST['mail_body'] ?? '');
        if ($to && $subj && $body_t) {
            $bhtml = '<p style="font-size:14px;color:#444;line-height:1.8;">'.nl2br(htmlspecialchars($body_t)).'</p>';
            $html  = build_admin_email_html($subj, $bhtml);
            $ok    = send_mail_admin($to, $subj, $html);
            $admin_msg = $ok ? "Email sent to $to." : "Failed to send to $to. Check SMTP.";
        } else { $admin_msg = 'Please fill all fields.'; }
    }

    // Mail all customers
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['mail_all'])) {
        $subj   = trim($_POST['blast_subject'] ?? '');
        $body_t = trim($_POST['blast_body'] ?? '');
        if ($subj && $body_t) {
            $db  = hv_load_db();
            $ems = array_keys($db['users'] ?? []);
            $subs = load_subscribers();
            foreach ($subs as $s) { if (!in_array($s['email'], $ems)) $ems[] = $s['email']; }
            $ems = array_unique(array_filter($ems));
            $cnt = 0;
            foreach ($ems as $em) {
                $bhtml = '<p style="font-size:14px;color:#444;line-height:1.8;">'.nl2br(htmlspecialchars($body_t)).'</p>';
                $html  = build_admin_email_html($subj, $bhtml);
                if (send_mail_admin($em, $subj, $html)) $cnt++;
            }
            $admin_msg = "Blast sent to $cnt recipients.";
        } else { $admin_msg = 'Please fill subject and body.'; }
    }

    // Add subscriber manually
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['add_subscriber'])) {
        $em   = strtolower(trim($_POST['sub_email'] ?? ''));
        if (filter_var($em, FILTER_VALIDATE_EMAIL)) {
            $subs = load_subscribers();
            $exists = false;
            foreach ($subs as $s) { if ($s['email'] === $em) { $exists = true; break; } }
            if (!$exists) {
                $subs[] = ['email'=>$em,'subscribed_at'=>date('d M Y, h:i A'),'source'=>'manual'];
                save_subscribers($subs);
                $admin_msg = "Subscriber $em added.";
            } else { $admin_msg = "$em already subscribed."; }
        } else { $admin_msg = 'Invalid email.'; }
    }

    // Remove subscriber
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['remove_subscriber'])) {
        $em   = trim($_POST['sub_email'] ?? '');
        $subs = load_subscribers();
        $subs = array_values(array_filter($subs, fn($s) => $s['email'] !== $em));
        save_subscribers($subs);
        $admin_msg = "Subscriber $em removed.";
    }
}

// ─── NEWSLETTER SUBSCRIBE ENDPOINT ──────────────────────────
// Called by main site JS via fetch POST with action=subscribe
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'subscribe') {
    $em = strtolower(trim($_POST['email'] ?? ''));
    $r  = ['ok'=>false,'msg'=>''];
    if (!filter_var($em, FILTER_VALIDATE_EMAIL)) {
        $r['msg'] = 'Invalid email.';
    } else {
        $subs = load_subscribers();
        $exists = false;
        foreach ($subs as $s) { if ($s['email'] === $em) { $exists = true; break; } }
        if ($exists) {
            $r['ok'] = true; $r['msg'] = 'Already subscribed!';
        } else {
            $subs[] = ['email'=>$em,'subscribed_at'=>date('d M Y, h:i A'),'source'=>'website'];
            save_subscribers($subs);
            $r['ok'] = true; $r['msg'] = 'Subscribed! Thank you.';
        }
    }
    header('Content-Type: application/json');
    echo json_encode($r);
    exit;
}

// ─── LOAD DATA FOR DISPLAY ────────────────────────────────────
$db       = hv_load_db();
$orders   = array_reverse($db['orders'] ?? []);
$users    = $db['users'] ?? [];
$subs     = load_subscribers();
$tab      = $_GET['tab'] ?? 'dashboard';

// ─── STATS ────────────────────────────────────────────────────
$total_orders    = count($orders);
$total_revenue   = 0;
$pending_orders  = 0;
$confirmed_count = 0;
$shipped_count   = 0;
$delivered_count = 0;
$cod_count       = 0;
$upi_count       = 0;
$today_orders    = 0;
$today_revenue   = 0;
$today_str       = date('d M Y');

foreach ($orders as $o) {
    $amt = (int) preg_replace('/[^0-9]/', '', $o['total'] ?? '0');
    $total_revenue += $amt;
    $s = $o['status'] ?? 'Confirmed';
    if ($s === 'Confirmed')        $confirmed_count++;
    if ($s === 'Processing')       $pending_orders++;
    if (in_array($s, ['Dispatched','In Transit','Out for Delivery'])) $shipped_count++;
    if ($s === 'Delivered')        $delivered_count++;
    if (($o['payment']??'') === 'COD') $cod_count++;
    if (($o['payment']??'') === 'UPI') $upi_count++;
    if (strpos($o['created_at']??'', $today_str) !== false) {
        $today_orders++;
        $today_revenue += $amt;
    }
}
$total_users = count($users);
$total_subs  = count($subs);

// Last 7 days chart data
$days_chart = [];
for ($i = 6; $i >= 0; $i--) {
    $d = date('d M Y', strtotime("-$i days"));
    $days_chart[$d] = ['orders'=>0,'revenue'=>0];
}
foreach ($orders as $o) {
    foreach ($days_chart as $d => &$v) {
        if (strpos($o['created_at']??'', $d) !== false) {
            $v['orders']++;
            $v['revenue'] += (int) preg_replace('/[^0-9]/', '', $o['total']??'0');
        }
    } unset($v);
}

// Product popularity from orders
$product_count = [];
foreach ($orders as $o) {
    $lines = explode("\n", trim($o['items']??''));
    foreach ($lines as $line) {
        $line = trim($line);
        if (!$line) continue;
        // Extract product name before " x"
        if (preg_match('/^(.+?)\s+x\d+/', $line, $m)) {
            $pname = trim($m[1]);
            $product_count[$pname] = ($product_count[$pname]??0) + 1;
        }
    }
}
arsort($product_count);
$top_products = array_slice($product_count, 0, 5, true);

?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>HELVETICA Admin Panel</title>
<link rel="icon" type="image/png" href="/wp-content/themes/fevicon.png">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@300;400;500;600;700;800;900&family=Space+Grotesk:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&family=Syne:wght@400;500;700;800&display=swap" rel="stylesheet">
<style>
:root {
  --accent: #b6ff3b;
  --dark: #0c1a2b;
  --pink: #e445ff;
  --electric: #4d5bff;
  --surface: #f8f9fa;
  --border: rgba(12,26,43,0.1);
  --text: #1f1f1f;
  --muted: rgba(31,31,31,0.5);
  --font: 'Space Grotesk', sans-serif;
  --mono: 'Space Mono', monospace;
  --syne: 'Syne', sans-serif;
  --sidebar-w: 260px;
}
*,*::before,*::after{box-sizing:border-box;margin:0;padding:0;}
html{font-size:62.5%;scroll-behavior:smooth;}
body{font-family:var(--font);font-size:1.4rem;background:#f1f3f6;color:var(--text);min-height:100vh;overflow-x:hidden;}
a{text-decoration:none;color:inherit;}
ul{list-style:none;}
button{cursor:pointer;border:none;font-family:inherit;}
input,select,textarea{font-family:inherit;}

/* ─── LOGIN PAGE ─── */
.login-wrap{min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0c1a2b 0%,#1a2d4a 50%,#0c1a2b 100%);position:relative;overflow:hidden;}
.login-wrap::before{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(182,255,59,0.04) 1px,transparent 1px),linear-gradient(90deg,rgba(182,255,59,0.04) 1px,transparent 1px);background-size:40px 40px;}
.login-box{background:#fff;width:100%;max-width:420px;padding:4rem;position:relative;z-index:2;box-shadow:0 40px 80px rgba(0,0,0,0.4);}
.login-logo{font-family:var(--syne);font-size:3rem;font-weight:800;color:var(--dark);letter-spacing:-0.05em;margin-bottom:.4rem;}
.login-sub{font-size:1.2rem;color:var(--muted);letter-spacing:.12em;text-transform:uppercase;margin-bottom:3rem;font-family:var(--mono);}
.login-group{margin-bottom:1.6rem;}
.login-group label{display:block;font-size:1.05rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.6rem;}
.login-group input{width:100%;border:2px solid rgba(31,31,31,.15);padding:1.2rem 1.4rem;font-size:1.4rem;outline:none;transition:border .2s;background:#fff;}
.login-group input:focus{border-color:var(--dark);}
.login-err{background:#fff0f0;border:1.5px solid #e00;color:#c00;padding:1rem 1.4rem;font-size:1.2rem;margin-bottom:1.6rem;}
.login-btn{width:100%;background:var(--dark);color:var(--accent);font-size:1.3rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;padding:1.5rem;border:none;cursor:pointer;transition:all .3s;}
.login-btn:hover{background:var(--accent);color:var(--dark);}
.login-back{display:block;margin-top:1.6rem;text-align:center;font-size:1.2rem;color:var(--muted);}
.login-back a{color:var(--dark);font-weight:700;}

/* ─── LAYOUT ─── */
.admin-wrap{display:flex;min-height:100vh;}

/* ─── SIDEBAR ─── */
.sidebar{width:var(--sidebar-w);background:var(--dark);display:flex;flex-direction:column;position:fixed;top:0;left:0;height:100vh;z-index:100;overflow-y:auto;}
.sidebar-logo{padding:2.4rem 2.4rem 2rem;border-bottom:1px solid rgba(255,255,255,.08);}
.sidebar-logo .brand{font-family:var(--syne);font-size:2rem;font-weight:800;color:var(--accent);letter-spacing:-0.04em;}
.sidebar-logo .badge{display:inline-block;background:var(--accent);color:var(--dark);font-size:.9rem;font-weight:800;letter-spacing:.1em;padding:.3rem .7rem;margin-left:.8rem;font-family:var(--mono);text-transform:uppercase;vertical-align:middle;}
.sidebar-logo p{font-size:1.05rem;color:rgba(255,255,255,.35);letter-spacing:.12em;text-transform:uppercase;font-family:var(--mono);margin-top:.4rem;}
.sidebar-nav{flex:1;padding:1.6rem 0;}
.sidebar-section{padding:.8rem 2rem .4rem;font-size:.95rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;color:rgba(255,255,255,.25);font-family:var(--mono);}
.sidebar-link{display:flex;align-items:center;gap:1.2rem;padding:1.1rem 2rem;font-size:1.25rem;font-weight:500;color:rgba(255,255,255,.6);transition:all .2s;cursor:pointer;text-decoration:none;position:relative;}
.sidebar-link:hover{color:#fff;background:rgba(255,255,255,.05);}
.sidebar-link.active{color:#fff;background:rgba(182,255,59,.12);}
.sidebar-link.active::before{content:'';position:absolute;left:0;top:0;bottom:0;width:3px;background:var(--accent);}
.sidebar-link .icon{width:2rem;height:2rem;flex-shrink:0;opacity:.7;}
.sidebar-link.active .icon,.sidebar-link:hover .icon{opacity:1;}
.sidebar-link .badge-count{margin-left:auto;background:var(--accent);color:var(--dark);font-size:.95rem;font-weight:800;padding:.2rem .6rem;border-radius:999px;font-family:var(--mono);}
.sidebar-footer{padding:1.6rem 2rem;border-top:1px solid rgba(255,255,255,.08);}
.sidebar-footer a{display:flex;align-items:center;gap:1rem;color:rgba(255,255,255,.45);font-size:1.1rem;transition:color .2s;}
.sidebar-footer a:hover{color:#fff;}

/* ─── MAIN ─── */
.main{margin-left:var(--sidebar-w);flex:1;display:flex;flex-direction:column;min-height:100vh;}
.topbar{background:#fff;border-bottom:1.5px solid var(--border);padding:1.4rem 3.2rem;display:flex;align-items:center;justify-content:space-between;position:sticky;top:0;z-index:50;}
.topbar-title{font-family:var(--syne);font-size:2rem;font-weight:700;color:var(--dark);}
.topbar-right{display:flex;align-items:center;gap:1.6rem;}
.topbar-admin{display:flex;align-items:center;gap:.8rem;font-size:1.3rem;font-weight:600;}
.topbar-avatar{width:3.6rem;height:3.6rem;background:var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.4rem;font-weight:800;color:var(--dark);}
.content{padding:2.8rem 3.2rem;flex:1;}

/* ─── MSG BANNER ─── */
.msg-banner{background:#f0fff0;border:1.5px solid #4caf50;color:#1b5e20;padding:1.2rem 1.8rem;font-size:1.3rem;margin-bottom:2rem;display:flex;align-items:center;gap:1rem;border-radius:0;}
.msg-banner.err{background:#fff0f0;border-color:#e00;color:#c00;}

/* ─── STAT CARDS ─── */
.stats-grid{display:grid;grid-template-columns:repeat(4,1fr);gap:2rem;margin-bottom:3rem;}
.stat-card{background:#fff;border:1.5px solid var(--border);padding:2rem 2.4rem;position:relative;overflow:hidden;transition:transform .2s,box-shadow .2s;}
.stat-card:hover{transform:translateY(-2px);box-shadow:0 8px 24px rgba(0,0,0,.08);}
.stat-card::before{content:'';position:absolute;top:0;left:0;width:4px;height:100%;background:var(--accent);}
.stat-card.blue::before{background:var(--electric);}
.stat-card.pink::before{background:var(--pink);}
.stat-card.green::before{background:#4caf50;}
.stat-label{font-size:1.05rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.8rem;font-family:var(--mono);}
.stat-value{font-family:var(--syne);font-size:3.2rem;font-weight:800;color:var(--dark);line-height:1;}
.stat-sub{font-size:1.1rem;color:var(--muted);margin-top:.5rem;}
.stat-icon{position:absolute;right:1.8rem;top:50%;transform:translateY(-50%);opacity:.08;font-size:5rem;}

/* ─── SECTION HEADER ─── */
.section-head{display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;}
.section-head h2{font-family:var(--syne);font-size:2.2rem;font-weight:700;color:var(--dark);}

/* ─── TABLES ─── */
.table-wrap{background:#fff;border:1.5px solid var(--border);overflow:hidden;}
.table-toolbar{padding:1.6rem 2rem;border-bottom:1.5px solid var(--border);display:flex;align-items:center;gap:1.2rem;flex-wrap:wrap;}
.search-field{flex:1;min-width:220px;border:1.5px solid var(--border);padding:1rem 1.4rem;font-size:1.3rem;outline:none;transition:border .2s;}
.search-field:focus{border-color:var(--dark);}
.filter-select{border:1.5px solid var(--border);padding:1rem 1.2rem;font-size:1.3rem;outline:none;background:#fff;cursor:pointer;transition:border .2s;}
.filter-select:focus{border-color:var(--dark);}
table{width:100%;border-collapse:collapse;}
th{background:var(--dark);color:rgba(255,255,255,.7);font-size:1.05rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:1.2rem 1.6rem;text-align:left;white-space:nowrap;font-family:var(--mono);}
td{padding:1.4rem 1.6rem;border-bottom:1px solid var(--border);font-size:1.3rem;vertical-align:middle;}
tr:last-child td{border-bottom:none;}
tr:hover td{background:rgba(182,255,59,.04);}

/* ─── STATUS BADGES ─── */
.badge{display:inline-block;font-size:1rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:.35rem .9rem;font-family:var(--mono);}
.badge-confirmed{background:#e8f5e9;color:#2e7d32;}
.badge-processing{background:#fff8e1;color:#f57f17;}
.badge-dispatched{background:#e3f2fd;color:#1565c0;}
.badge-transit{background:#ede7f6;color:#4527a0;}
.badge-out{background:#fce4ec;color:#c62828;}
.badge-delivered{background:#0c1a2b;color:#b6ff3b;}
.badge-upi{background:#e8f5e9;color:#2e7d32;}
.badge-cod{background:#fff3e0;color:#e65100;}

/* ─── ACTION BUTTONS ─── */
.btn{display:inline-flex;align-items:center;gap:.6rem;padding:.8rem 1.4rem;font-size:1.15rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;transition:all .2s;cursor:pointer;border:none;font-family:var(--mono);}
.btn-dark{background:var(--dark);color:var(--accent);}
.btn-dark:hover{background:var(--accent);color:var(--dark);}
.btn-accent{background:var(--accent);color:var(--dark);}
.btn-accent:hover{background:var(--dark);color:var(--accent);}
.btn-red{background:#fff0f0;color:#c00;border:1.5px solid #f5c6c6;}
.btn-red:hover{background:#c00;color:#fff;}
.btn-blue{background:#e3f2fd;color:#1565c0;border:1.5px solid #bbdefb;}
.btn-blue:hover{background:#1565c0;color:#fff;}
.btn-sm{padding:.5rem 1rem;font-size:1rem;}

/* ─── PAGINATION ─── */
.pagination{display:flex;align-items:center;gap:.6rem;padding:1.6rem 2rem;border-top:1.5px solid var(--border);}
.pg-btn{width:3.4rem;height:3.4rem;border:1.5px solid var(--border);background:#fff;font-size:1.3rem;font-weight:700;display:flex;align-items:center;justify-content:center;cursor:pointer;transition:all .2s;font-family:var(--mono);}
.pg-btn.active,.pg-btn:hover{background:var(--dark);color:var(--accent);border-color:var(--dark);}
.pg-info{font-size:1.2rem;color:var(--muted);margin-left:auto;font-family:var(--mono);}

/* ─── CHARTS ─── */
.charts-grid{display:grid;grid-template-columns:2fr 1fr;gap:2rem;margin-bottom:3rem;}
.chart-card{background:#fff;border:1.5px solid var(--border);padding:2.4rem;}
.chart-card h3{font-family:var(--syne);font-size:1.6rem;font-weight:700;margin-bottom:2rem;color:var(--dark);}
.bar-chart{display:flex;align-items:flex-end;gap:.8rem;height:14rem;}
.bar-col{flex:1;display:flex;flex-direction:column;align-items:center;gap:.6rem;}
.bar-fill{width:100%;background:var(--accent);min-height:2px;transition:height .5s ease;position:relative;cursor:pointer;}
.bar-fill:hover{background:var(--dark);}
.bar-fill::before{content:attr(data-tip);position:absolute;bottom:calc(100% + 4px);left:50%;transform:translateX(-50%);background:var(--dark);color:#fff;font-size:.9rem;padding:.3rem .7rem;white-space:nowrap;opacity:0;transition:opacity .2s;pointer-events:none;font-family:var(--mono);}
.bar-fill:hover::before{opacity:1;}
.bar-label{font-size:.9rem;color:var(--muted);text-align:center;font-family:var(--mono);white-space:nowrap;}
.donut-wrap{display:flex;flex-direction:column;align-items:center;justify-content:center;gap:1.6rem;}
.donut-legend{width:100%;display:flex;flex-direction:column;gap:.8rem;}
.legend-row{display:flex;align-items:center;gap:.8rem;font-size:1.2rem;}
.legend-dot{width:1.2rem;height:1.2rem;border-radius:50%;flex-shrink:0;}

/* ─── MODAL ─── */
.modal-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.6);z-index:500;align-items:center;justify-content:center;padding:2rem;}
.modal-overlay.open{display:flex;}
.modal-box{background:#fff;width:100%;max-width:560px;max-height:90vh;overflow-y:auto;position:relative;animation:mdIn .3s ease;}
@keyframes mdIn{from{opacity:0;transform:translateY(20px)}to{opacity:1;transform:translateY(0)}}
.modal-head{background:var(--dark);padding:2rem 2.4rem;display:flex;align-items:center;justify-content:space-between;}
.modal-head h3{font-family:var(--syne);font-size:1.8rem;font-weight:700;color:var(--accent);}
.modal-close{background:none;border:none;color:rgba(255,255,255,.5);font-size:2.4rem;line-height:1;cursor:pointer;}
.modal-close:hover{color:#fff;}
.modal-body{padding:2.4rem;}
.form-group{margin-bottom:1.6rem;}
.form-group label{display:block;font-size:1.05rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.6rem;font-family:var(--mono);}
.form-group input,.form-group select,.form-group textarea{width:100%;border:1.5px solid var(--border);padding:1.1rem 1.4rem;font-size:1.3rem;outline:none;transition:border .2s;background:#fff;resize:vertical;}
.form-group input:focus,.form-group select:focus,.form-group textarea:focus{border-color:var(--dark);}
.form-submit{width:100%;background:var(--dark);color:var(--accent);font-size:1.3rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;padding:1.4rem;border:none;cursor:pointer;margin-top:.8rem;transition:all .3s;font-family:var(--mono);}
.form-submit:hover{background:var(--accent);color:var(--dark);}

/* ─── ORDER DETAIL SIDEBAR ─── */
.order-detail-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.5);z-index:400;}
.order-detail-overlay.open{display:block;}
.order-detail-drawer{position:fixed;top:0;right:-520px;width:500px;max-width:100vw;height:100vh;background:#fff;z-index:500;overflow-y:auto;transition:right .35s cubic-bezier(.4,0,.2,1);box-shadow:-4px 0 40px rgba(0,0,0,.15);display:flex;flex-direction:column;}
.order-detail-drawer.open{right:0;}
.od-head{background:var(--dark);padding:2rem 2.4rem;display:flex;align-items:center;justify-content:space-between;}
.od-head h3{font-family:var(--syne);font-size:1.8rem;font-weight:700;color:var(--accent);}
.od-close{background:none;border:none;color:rgba(255,255,255,.5);font-size:2.4rem;cursor:pointer;line-height:1;}
.od-close:hover{color:#fff;}
.od-body{padding:2.4rem;flex:1;}
.od-section{margin-bottom:2rem;}
.od-section h4{font-size:1.05rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:var(--muted);margin-bottom:1rem;padding-bottom:.6rem;border-bottom:1.5px solid var(--border);font-family:var(--mono);}
.od-row{display:flex;justify-content:space-between;padding:.6rem 0;border-bottom:1px dashed var(--border);font-size:1.3rem;}
.od-row:last-child{border-bottom:none;}
.od-row strong{color:var(--dark);font-weight:700;}
.od-total{display:flex;justify-content:space-between;padding:1rem 0;font-size:1.5rem;font-weight:800;border-top:2px solid var(--dark);margin-top:.5rem;}
.od-items{background:var(--surface);padding:1.4rem;font-size:1.2rem;line-height:1.9;white-space:pre-line;color:var(--text);}
.status-form{display:flex;gap:1rem;align-items:center;margin-top:1.2rem;}
.status-form select{flex:1;border:1.5px solid var(--border);padding:1rem 1.2rem;font-size:1.3rem;outline:none;background:#fff;}
.status-form button{padding:1rem 2rem;background:var(--dark);color:var(--accent);font-size:1.2rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;border:none;cursor:pointer;transition:all .2s;font-family:var(--mono);}
.status-form button:hover{background:var(--accent);color:var(--dark);}

/* ─── QUICK STATS BAR ─── */
.quick-stats{display:flex;gap:1.5rem;margin-bottom:3rem;flex-wrap:wrap;}
.qs-item{background:#fff;border:1.5px solid var(--border);padding:1.2rem 2rem;display:flex;align-items:center;gap:1rem;flex:1;min-width:160px;}
.qs-icon{width:3.6rem;height:3.6rem;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.6rem;flex-shrink:0;}
.qs-info label{font-size:1rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);display:block;font-family:var(--mono);}
.qs-info strong{font-size:1.6rem;font-weight:800;font-family:var(--syne);}

/* ─── CUSTOMER CARD ─── */
.customer-grid{display:grid;grid-template-columns:repeat(auto-fill,minmax(300px,1fr));gap:1.6rem;}
.customer-card{background:#fff;border:1.5px solid var(--border);padding:2rem;transition:border .2s;}
.customer-card:hover{border-color:var(--dark);}
.cc-top{display:flex;align-items:center;gap:1.2rem;margin-bottom:1.4rem;}
.cc-avatar{width:4.4rem;height:4.4rem;background:var(--accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1.8rem;font-weight:800;color:var(--dark);flex-shrink:0;font-family:var(--syne);}
.cc-name{font-size:1.4rem;font-weight:700;color:var(--dark);}
.cc-email{font-size:1.1rem;color:var(--muted);}
.cc-meta{display:flex;gap:1rem;flex-wrap:wrap;}
.cc-tag{font-size:1rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:.4rem .9rem;background:var(--surface);color:var(--muted);font-family:var(--mono);}
.cc-actions{display:flex;gap:.8rem;margin-top:1.4rem;}

/* ─── SUBSCRIBER TABLE ─── */
.sub-source{font-size:1rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;padding:.3rem .8rem;font-family:var(--mono);}
.sub-website{background:#e3f2fd;color:#1565c0;}
.sub-manual{background:#f3e5f5;color:#6a1b9a;}

/* ─── RECENT ACTIVITY ─── */
.activity-list{display:flex;flex-direction:column;gap:0;}
.activity-item{display:flex;align-items:flex-start;gap:1.4rem;padding:1.4rem 0;border-bottom:1px solid var(--border);}
.activity-item:last-child{border-bottom:none;}
.activity-dot{width:1rem;height:1rem;border-radius:50%;flex-shrink:0;margin-top:.4rem;}
.activity-content{flex:1;}
.activity-title{font-size:1.3rem;font-weight:600;}
.activity-time{font-size:1.1rem;color:var(--muted);margin-top:.2rem;font-family:var(--mono);}

/* ─── EMPTY STATE ─── */
.empty-state{text-align:center;padding:6rem 2rem;color:var(--muted);}
.empty-state p{font-size:1.5rem;margin-top:1rem;}

/* ─── CARD GRID ─── */
.cards-2{display:grid;grid-template-columns:1fr 1fr;gap:2rem;}

/* ─── RESPONSIVE ─── */
@media(max-width:1200px){.stats-grid{grid-template-columns:repeat(2,1fr);}}
@media(max-width:900px){
  .sidebar{transform:translateX(-100%);transition:transform .3s;}
  .sidebar.open{transform:translateX(0);}
  .main{margin-left:0;}
  .content{padding:2rem 1.6rem;}
  .topbar{padding:1.4rem 2rem;}
  .charts-grid,.cards-2{grid-template-columns:1fr;}
  .stats-grid{grid-template-columns:repeat(2,1fr);}
}
@media(max-width:600px){
  .stats-grid{grid-template-columns:1fr;}
  table{font-size:1.2rem;}
  th,td{padding:1rem;}
  .customer-grid{grid-template-columns:1fr;}
}

/* ─── TOOLTIPS / MISC ─── */
.no-wrap{white-space:nowrap;}
.text-right{text-align:right;}
.mt-1{margin-top:1rem;}
.mt-2{margin-top:2rem;}
.gap-1{gap:1rem;}
.flex{display:flex;align-items:center;}
.flex-col{display:flex;flex-direction:column;}
.w-full{width:100%;}
</style>
</head>
<body>

<?php if (!isset($_SESSION['hv_admin'])): ?>
<!-- ═══════════════════════════════════════ LOGIN ═══════════════════════════════════════ -->
<div class="login-wrap">
  <div class="login-box">
    <div class="login-logo">HELVETICA</div>
    <div class="login-sub">Admin Control Panel</div>
    <?php if ($admin_error): ?>
    <div class="login-err"><?php echo htmlspecialchars($admin_error); ?></div>
    <?php endif; ?>
    <form method="POST">
      <input type="hidden" name="admin_login" value="1">
      <div class="login-group">
        <label>Admin Email</label>
        <input type="email" name="admin_email" placeholder="admin@helvetica.com" required autocomplete="username">
      </div>
      <div class="login-group">
        <label>Password</label>
        <input type="password" name="admin_pass" placeholder="••••••••••••" required autocomplete="current-password">
      </div>
      <button type="submit" class="login-btn">Access Admin →</button>
    </form>
    <a href="/" class="login-back">← Back to Store</a>
  </div>
</div>

<?php else: ?>
<!-- ═══════════════════════════════════════ ADMIN ═══════════════════════════════════════ -->
<div class="admin-wrap">

<!-- SIDEBAR -->
<aside class="sidebar" id="sidebar">
  <div class="sidebar-logo">
    <div><span class="brand">HELVETICA</span><span class="badge">Admin</span></div>
    <p>Control Panel</p>
  </div>
  <nav class="sidebar-nav">
    <div class="sidebar-section">Overview</div>
    <a href="?tab=dashboard" class="sidebar-link <?php echo $tab==='dashboard'?'active':''; ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>
      Dashboard
    </a>
    <div class="sidebar-section">Commerce</div>
    <a href="?tab=orders" class="sidebar-link <?php echo $tab==='orders'?'active':''; ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4zM3 6h18M16 10a4 4 0 01-8 0"/></svg>
      Orders
      <?php if($total_orders>0): ?><span class="badge-count"><?php echo $total_orders; ?></span><?php endif; ?>
    </a>
    <a href="?tab=customers" class="sidebar-link <?php echo $tab==='customers'?'active':''; ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 00-3-3.87M16 3.13a4 4 0 010 7.75"/></svg>
      Customers
      <?php if($total_users>0): ?><span class="badge-count"><?php echo $total_users; ?></span><?php endif; ?>
    </a>
    <div class="sidebar-section">Marketing</div>
    <a href="?tab=subscribers" class="sidebar-link <?php echo $tab==='subscribers'?'active':''; ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
      Subscribers
      <?php if($total_subs>0): ?><span class="badge-count"><?php echo $total_subs; ?></span><?php endif; ?>
    </a>
    <a href="?tab=email" class="sidebar-link <?php echo $tab==='email'?'active':''; ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 2L11 13M22 2L15 22 11 13 2 9l20-7z"/></svg>
      Send Emails
    </a>
    <div class="sidebar-section">System</div>
    <a href="?tab=settings" class="sidebar-link <?php echo $tab==='settings'?'active':''; ?>">
      <svg class="icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 00.33 1.82l.06.06a2 2 0 010 2.83 2 2 0 01-2.83 0l-.06-.06a1.65 1.65 0 00-1.82-.33 1.65 1.65 0 00-1 1.51V21a2 2 0 01-4 0v-.09A1.65 1.65 0 009 19.4a1.65 1.65 0 00-1.82.33l-.06.06a2 2 0 01-2.83 0 2 2 0 010-2.83l.06-.06A1.65 1.65 0 004.68 15a1.65 1.65 0 00-1.51-1H3a2 2 0 010-4h.09A1.65 1.65 0 004.6 9a1.65 1.65 0 00-.33-1.82l-.06-.06a2 2 0 010-2.83 2 2 0 012.83 0l.06.06A1.65 1.65 0 009 4.68a1.65 1.65 0 001-1.51V3a2 2 0 014 0v.09a1.65 1.65 0 001 1.51 1.65 1.65 0 001.82-.33l.06-.06a2 2 0 012.83 0 2 2 0 010 2.83l-.06.06A1.65 1.65 0 0019.4 9a1.65 1.65 0 001.51 1H21a2 2 0 010 4h-.09a1.65 1.65 0 00-1.51 1z"/></svg>
      Settings
    </a>
  </nav>
  <div class="sidebar-footer">
    <a href="/" target="_blank">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 13v6a2 2 0 01-2 2H5a2 2 0 01-2-2V8a2 2 0 012-2h6"/><polyline points="15,3 21,3 21,9"/><line x1="10" y1="14" x2="21" y2="3"/></svg>
      View Store
    </a>
    <a href="?admin_logout=1" style="margin-top:.8rem;color:rgba(255,100,100,.5);">
      <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 01-2-2V5a2 2 0 012-2h4M16 17l5-5-5-5M21 12H9"/></svg>
      Logout
    </a>
  </div>
</aside>

<!-- MAIN -->
<main class="main">
  <!-- TOPBAR -->
  <div class="topbar">
    <div style="display:flex;align-items:center;gap:1.4rem;">
      <button id="sidebar-toggle" style="display:none;background:none;border:none;cursor:pointer;padding:.4rem;" aria-label="Toggle sidebar">
        <svg width="22" height="18" viewBox="0 0 24 18" fill="none"><rect width="24" height="2" fill="#1f1f1f"/><rect y="8" width="24" height="2" fill="#1f1f1f"/><rect y="16" width="24" height="2" fill="#1f1f1f"/></svg>
      </button>
      <div class="topbar-title">
        <?php
        $titles = ['dashboard'=>'Dashboard','orders'=>'Orders','customers'=>'Customers','subscribers'=>'Subscribers','email'=>'Email Center','settings'=>'Settings'];
        echo $titles[$tab] ?? 'Dashboard';
        ?>
      </div>
    </div>
    <div class="topbar-right">
      <div style="font-size:1.2rem;color:var(--muted);font-family:var(--mono);"><?php echo date('D, d M Y'); ?></div>
      <div class="topbar-admin">
        <div class="topbar-avatar">A</div>
        <span>Admin</span>
      </div>
      <a href="?admin_logout=1" class="btn btn-dark btn-sm">Logout</a>
    </div>
  </div>

  <!-- CONTENT -->
  <div class="content">

  <?php if ($admin_msg): ?>
  <div class="msg-banner"><?php echo htmlspecialchars($admin_msg); ?></div>
  <?php endif; ?>

  <!-- ════════════════ DASHBOARD ════════════════ -->
  <?php if ($tab === 'dashboard'): ?>

  <div class="stats-grid">
    <div class="stat-card">
      <div class="stat-label">Total Revenue</div>
      <div class="stat-value">₹<?php echo number_format($total_revenue); ?></div>
      <div class="stat-sub">All time earnings</div>
      <div class="stat-icon">₹</div>
    </div>
    <div class="stat-card blue">
      <div class="stat-label">Total Orders</div>
      <div class="stat-value"><?php echo $total_orders; ?></div>
      <div class="stat-sub"><?php echo $today_orders; ?> today</div>
      <div class="stat-icon">📦</div>
    </div>
    <div class="stat-card pink">
      <div class="stat-label">Customers</div>
      <div class="stat-value"><?php echo $total_users; ?></div>
      <div class="stat-sub"><?php echo $total_subs; ?> subscribers</div>
      <div class="stat-icon">👤</div>
    </div>
    <div class="stat-card green">
      <div class="stat-label">Today Revenue</div>
      <div class="stat-value">₹<?php echo number_format($today_revenue); ?></div>
      <div class="stat-sub"><?php echo $today_orders; ?> orders today</div>
      <div class="stat-icon">📈</div>
    </div>
  </div>

  <!-- Quick stats bar -->
  <div class="quick-stats">
    <div class="qs-item">
      <div class="qs-icon" style="background:#e8f5e9;"><span>✅</span></div>
      <div class="qs-info"><label>Confirmed</label><strong><?php echo $confirmed_count; ?></strong></div>
    </div>
    <div class="qs-item">
      <div class="qs-icon" style="background:#fff8e1;"><span>⚙️</span></div>
      <div class="qs-info"><label>Processing</label><strong><?php echo $pending_orders; ?></strong></div>
    </div>
    <div class="qs-item">
      <div class="qs-icon" style="background:#e3f2fd;"><span>🚚</span></div>
      <div class="qs-info"><label>Shipped</label><strong><?php echo $shipped_count; ?></strong></div>
    </div>
    <div class="qs-item">
      <div class="qs-icon" style="background:#0c1a2b;"><span style="filter:brightness(2);">📬</span></div>
      <div class="qs-info" style="color:#fff;"><label style="color:rgba(255,255,255,.5);">Delivered</label><strong style="color:#b6ff3b;"><?php echo $delivered_count; ?></strong></div>
    </div>
    <div class="qs-item">
      <div class="qs-icon" style="background:#fff3e0;"><span>💵</span></div>
      <div class="qs-info"><label>COD</label><strong><?php echo $cod_count; ?></strong></div>
    </div>
    <div class="qs-item">
      <div class="qs-icon" style="background:#e8f5e9;"><span>📲</span></div>
      <div class="qs-info"><label>UPI</label><strong><?php echo $upi_count; ?></strong></div>
    </div>
  </div>

  <!-- Charts -->
  <div class="charts-grid">
    <div class="chart-card">
      <h3>Orders Last 7 Days</h3>
      <?php
      $max_orders = max(array_column($days_chart, 'orders'), 1);
      $max_rev    = max(array_column($days_chart, 'revenue'), 1);
      ?>
      <div class="bar-chart">
        <?php foreach ($days_chart as $day => $v): 
          $h = $max_orders > 0 ? round(($v['orders']/$max_orders)*100) : 0;
          $d_label = date('d M', strtotime($day));
        ?>
        <div class="bar-col">
          <div style="flex:1;display:flex;align-items:flex-end;width:100%;">
            <div class="bar-fill" style="height:<?php echo max($h,2); ?>%;" data-tip="<?php echo $v['orders']; ?> orders | ₹<?php echo number_format($v['revenue']); ?>"></div>
          </div>
          <div class="bar-label"><?php echo $d_label; ?></div>
        </div>
        <?php endforeach; ?>
      </div>
    </div>
    <div class="chart-card">
      <h3>Payment Split</h3>
      <div class="donut-wrap">
        <?php
        $cod_pct = $total_orders > 0 ? round($cod_count/$total_orders*100) : 0;
        $upi_pct = $total_orders > 0 ? round($upi_count/$total_orders*100) : 0;
        $cod_deg = round($cod_pct * 3.6);
        ?>
        <svg viewBox="0 0 120 120" style="width:14rem;height:14rem;">
          <circle cx="60" cy="60" r="50" fill="none" stroke="#f0f0f0" stroke-width="18"/>
          <?php if ($total_orders > 0): ?>
          <circle cx="60" cy="60" r="50" fill="none" stroke="#b6ff3b" stroke-width="18"
            stroke-dasharray="<?php echo round($upi_pct*3.14159); ?> 314.159"
            stroke-dashoffset="0" transform="rotate(-90 60 60)"/>
          <circle cx="60" cy="60" r="50" fill="none" stroke="#0c1a2b" stroke-width="18"
            stroke-dasharray="<?php echo round($cod_pct*3.14159); ?> 314.159"
            stroke-dashoffset="-<?php echo round($upi_pct*3.14159); ?>" transform="rotate(-90 60 60)"/>
          <?php endif; ?>
          <text x="60" y="56" text-anchor="middle" font-size="14" font-weight="800" fill="#0c1a2b" font-family="Syne,sans-serif"><?php echo $total_orders; ?></text>
          <text x="60" y="70" text-anchor="middle" font-size="8" fill="#999" font-family="Space Mono,monospace">ORDERS</text>
        </svg>
        <div class="donut-legend">
          <div class="legend-row"><div class="legend-dot" style="background:#0c1a2b;"></div><span>COD — <?php echo $cod_count; ?> orders (<?php echo $cod_pct; ?>%)</span></div>
          <div class="legend-row"><div class="legend-dot" style="background:#b6ff3b;"></div><span>UPI — <?php echo $upi_count; ?> orders (<?php echo $upi_pct; ?>%)</span></div>
        </div>
      </div>
    </div>
  </div>

  <!-- Bottom row: recent orders + top products -->
  <div class="cards-2">
    <div class="table-wrap">
      <div class="table-toolbar">
        <span style="font-family:var(--syne);font-size:1.6rem;font-weight:700;">Recent Orders</span>
        <a href="?tab=orders" class="btn btn-dark btn-sm" style="margin-left:auto;">View All</a>
      </div>
      <table>
        <thead><tr><th>Order ID</th><th>Customer</th><th>Total</th><th>Status</th></tr></thead>
        <tbody>
          <?php foreach (array_slice($orders, 0, 8) as $o): 
            $s = $o['status']??'Confirmed';
            $sc = strtolower(str_replace([' ','/',],['','-'],$s));
          ?>
          <tr>
            <td><span style="font-family:var(--mono);font-weight:700;"><?php echo htmlspecialchars($o['order_id']); ?></span></td>
            <td><?php echo htmlspecialchars($o['name']); ?></td>
            <td><strong><?php echo htmlspecialchars($o['total']); ?></strong></td>
            <td><span class="badge badge-<?php echo $sc; ?>"><?php echo htmlspecialchars($s); ?></span></td>
          </tr>
          <?php endforeach; ?>
          <?php if (empty($orders)): ?>
          <tr><td colspan="4" style="text-align:center;color:var(--muted);padding:3rem;">No orders yet.</td></tr>
          <?php endif; ?>
        </tbody>
      </table>
    </div>
    <div class="chart-card" style="padding:0;">
      <div class="table-toolbar" style="border-bottom:1.5px solid var(--border);">
        <span style="font-family:var(--syne);font-size:1.6rem;font-weight:700;">Top Products</span>
      </div>
      <div style="padding:1.6rem 2rem;">
        <?php if (empty($top_products)): ?>
        <p style="color:var(--muted);font-size:1.3rem;">No order data yet.</p>
        <?php else: ?>
        <?php $rank=1; foreach ($top_products as $pname => $cnt): 
          $pct = max(10, round($cnt/max(array_values($top_products))*100));
        ?>
        <div style="margin-bottom:1.6rem;">
          <div style="display:flex;justify-content:space-between;margin-bottom:.5rem;">
            <span style="font-size:1.2rem;font-weight:600;"><?php echo $rank++; ?>. <?php echo htmlspecialchars($pname); ?></span>
            <span style="font-family:var(--mono);font-size:1.1rem;color:var(--muted);"><?php echo $cnt; ?> orders</span>
          </div>
          <div style="height:.6rem;background:#f0f0f0;">
            <div style="height:100%;width:<?php echo $pct; ?>%;background:var(--accent);transition:width .6s;"></div>
          </div>
        </div>
        <?php endforeach; ?>
        <?php endif; ?>
      </div>
    </div>
  </div>

  <!-- ════════════════ ORDERS ════════════════ -->
  <?php elseif ($tab === 'orders'): ?>

  <div class="section-head">
    <h2>All Orders <span style="font-size:1.6rem;color:var(--muted);font-weight:500;">(<?php echo $total_orders; ?>)</span></h2>
    <div class="flex gap-1">
      <a href="?tab=orders&export=csv" class="btn btn-accent btn-sm">Export CSV</a>
    </div>
  </div>

  <?php
  // CSV Export
  if (isset($_GET['export']) && $_GET['export'] === 'csv') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="helvetica-orders-'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Order ID','Customer','Email','Phone','Address','Items','Total','Payment','Status','Date']);
    foreach ($orders as $o) {
      fputcsv($out, [
        $o['order_id']??'', $o['name']??'', $o['email']??'', $o['phone']??'',
        $o['address']??'', str_replace("\n",' | ',$o['items']??''),
        $o['total']??'', $o['payment']??'', $o['status']??'', $o['created_at']??''
      ]);
    }
    fclose($out);
    exit;
  }
  ?>

  <div class="table-wrap">
    <div class="table-toolbar">
      <input class="search-field" type="text" id="order-search" placeholder="Search orders, customers, IDs...">
      <select class="filter-select" id="status-filter">
        <option value="">All Statuses</option>
        <option>Confirmed</option>
        <option>Processing</option>
        <option>Dispatched</option>
        <option>In Transit</option>
        <option>Out for Delivery</option>
        <option>Delivered</option>
      </select>
      <select class="filter-select" id="payment-filter">
        <option value="">All Payments</option>
        <option>COD</option>
        <option>UPI</option>
      </select>
    </div>
    <div style="overflow-x:auto;">
    <table id="orders-table">
      <thead>
        <tr>
          <th>Order ID</th>
          <th>Customer</th>
          <th>Contact</th>
          <th>Items</th>
          <th>Total</th>
          <th>Payment</th>
          <th>Status</th>
          <th>Date</th>
          <th>Actions</th>
        </tr>
      </thead>
      <tbody>
        <?php if (empty($orders)): ?>
        <tr><td colspan="9" class="empty-state"><p>No orders placed yet.</p></td></tr>
        <?php endif; ?>
        <?php foreach ($orders as $o):
          $s  = $o['status'] ?? 'Confirmed';
          $sc = strtolower(str_replace([' ','/',],['','-'],$s));
          $pay = $o['payment'] ?? 'COD';
        ?>
        <tr data-search="<?php echo strtolower($o['order_id'].' '.$o['name'].' '.($o['email']??'')); ?>" data-status="<?php echo htmlspecialchars($s); ?>" data-payment="<?php echo htmlspecialchars($pay); ?>">
          <td><span style="font-family:var(--mono);font-size:1.1rem;font-weight:700;"><?php echo htmlspecialchars($o['order_id']); ?></span></td>
          <td>
            <div style="font-weight:600;"><?php echo htmlspecialchars($o['name']); ?></div>
            <div style="font-size:1.1rem;color:var(--muted);"><?php echo htmlspecialchars($o['address']??''); ?></div>
          </td>
          <td>
            <div style="font-size:1.15rem;"><?php echo htmlspecialchars($o['email']??''); ?></div>
            <div style="font-size:1.1rem;color:var(--muted);"><?php echo htmlspecialchars($o['phone']??''); ?></div>
          </td>
          <td style="max-width:200px;">
            <div style="font-size:1.1rem;color:var(--muted);overflow:hidden;text-overflow:ellipsis;white-space:nowrap;max-width:180px;" title="<?php echo htmlspecialchars($o['items']??''); ?>">
              <?php echo htmlspecialchars(str_replace("\n",' | ', $o['items']??'')); ?>
            </div>
          </td>
          <td><strong style="font-family:var(--mono);"><?php echo htmlspecialchars($o['total']??''); ?></strong></td>
          <td><span class="badge badge-<?php echo strtolower($pay); ?>"><?php echo htmlspecialchars($pay); ?></span></td>
          <td><span class="badge badge-<?php echo $sc; ?>"><?php echo htmlspecialchars($s); ?></span></td>
          <td style="font-size:1.1rem;color:var(--muted);white-space:nowrap;"><?php echo htmlspecialchars($o['created_at']??''); ?></td>
          <td class="no-wrap">
            <button class="btn btn-blue btn-sm" onclick="openOrderDetail(<?php echo htmlspecialchars(json_encode($o)); ?>)">Detail</button>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    </div>
  </div>

  <!-- Order Detail Drawer -->
  <div class="order-detail-overlay" id="odOverlay"></div>
  <div class="order-detail-drawer" id="odDrawer">
    <div class="od-head">
      <h3 id="od-id">Order Detail</h3>
      <button class="od-close" onclick="closeOrderDetail()">×</button>
    </div>
    <div class="od-body">
      <div class="od-section">
        <h4>Order Info</h4>
        <div class="od-row"><span>Status</span><strong id="od-status"></strong></div>
        <div class="od-row"><span>Payment</span><strong id="od-payment"></strong></div>
        <div class="od-row"><span>Date</span><strong id="od-date"></strong></div>
        <div class="od-row"><span>Total</span><strong id="od-total"></strong></div>
      </div>
      <div class="od-section">
        <h4>Customer</h4>
        <div class="od-row"><span>Name</span><strong id="od-name"></strong></div>
        <div class="od-row"><span>Email</span><strong id="od-email"></strong></div>
        <div class="od-row"><span>Phone</span><strong id="od-phone"></strong></div>
        <div class="od-row"><span>Address</span><strong id="od-address"></strong></div>
      </div>
      <div class="od-section">
        <h4>Items Ordered</h4>
        <div class="od-items" id="od-items"></div>
      </div>
      <div class="od-section">
        <h4>Update Status</h4>
        <form method="POST">
          <input type="hidden" name="update_order_status" value="1">
          <input type="hidden" name="order_id" id="od-form-id">
          <div class="status-form">
            <select name="new_status" id="od-status-select">
              <option>Confirmed</option>
              <option>Processing</option>
              <option>Dispatched</option>
              <option>In Transit</option>
              <option>Out for Delivery</option>
              <option>Delivered</option>
            </select>
            <button type="submit">Update</button>
          </div>
        </form>
      </div>
      <div class="od-section" style="margin-top:2rem;">
        <h4>Actions</h4>
        <div style="display:flex;gap:1rem;flex-wrap:wrap;">
          <button class="btn btn-blue" onclick="document.getElementById('mailCustomerEmail').value=document.getElementById('od-email').textContent;closeOrderDetail();document.querySelector('[href=\'?tab=email\']').click();">Email Customer</button>
          <form method="POST" onsubmit="return confirm('Delete this order permanently?')">
            <input type="hidden" name="delete_order" value="1">
            <input type="hidden" name="order_id" id="od-del-id">
            <button type="submit" class="btn btn-red">Delete Order</button>
          </form>
        </div>
      </div>
    </div>
  </div>

  <!-- ════════════════ CUSTOMERS ════════════════ -->
  <?php elseif ($tab === 'customers'): ?>

  <div class="section-head">
    <h2>Customers <span style="font-size:1.6rem;color:var(--muted);font-weight:500;">(<?php echo $total_users; ?>)</span></h2>
  </div>

  <?php if (empty($users)): ?>
  <div class="empty-state">
    <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1" opacity=".3"><path d="M17 21v-2a4 4 0 00-4-4H5a4 4 0 00-4 4v2"/><circle cx="9" cy="7" r="4"/></svg>
    <p>No registered customers yet.</p>
  </div>
  <?php else: ?>
  <div style="margin-bottom:2rem;">
    <input class="search-field" type="text" id="customer-search" placeholder="Search customers by name or email..." style="max-width:400px;">
  </div>
  <div class="customer-grid" id="customer-grid">
    <?php foreach ($users as $ue => $u):
      // Count orders for this customer
      $uorders = 0; $urevenue = 0;
      $linked = $db['user_orders'][$ue] ?? [];
      foreach ($orders as $o) {
        if (in_array($o['order_id'], $linked)) {
          $uorders++;
          $urevenue += (int) preg_replace('/[^0-9]/', '', $o['total']??'0');
        }
      }
    ?>
    <div class="customer-card" data-search="<?php echo strtolower($u['name'].' '.$ue); ?>">
      <div class="cc-top">
        <div class="cc-avatar"><?php echo strtoupper(substr($u['name'],0,1)); ?></div>
        <div>
          <div class="cc-name"><?php echo htmlspecialchars($u['name']); ?></div>
          <div class="cc-email"><?php echo htmlspecialchars($ue); ?></div>
        </div>
      </div>
      <div class="cc-meta">
        <span class="cc-tag">📦 <?php echo $uorders; ?> orders</span>
        <span class="cc-tag">₹<?php echo number_format($urevenue); ?> spent</span>
        <span class="cc-tag">🗓 <?php echo htmlspecialchars(substr($u['created']??'',0,10)); ?></span>
      </div>
      <div class="cc-actions">
        <button class="btn btn-blue btn-sm" onclick="document.getElementById('mailCustomerEmail').value='<?php echo htmlspecialchars($ue); ?>';window.location='?tab=email&to=<?php echo urlencode($ue); ?>';">Email</button>
        <form method="POST" onsubmit="return confirm('Delete this customer account?')">
          <input type="hidden" name="delete_user" value="1">
          <input type="hidden" name="user_email" value="<?php echo htmlspecialchars($ue); ?>">
          <button type="submit" class="btn btn-red btn-sm">Delete</button>
        </form>
      </div>
    </div>
    <?php endforeach; ?>
  </div>
  <?php endif; ?>

  <!-- ════════════════ SUBSCRIBERS ════════════════ -->
  <?php elseif ($tab === 'subscribers'): ?>

  <div class="section-head">
    <h2>Newsletter Subscribers <span style="font-size:1.6rem;color:var(--muted);font-weight:500;">(<?php echo $total_subs; ?>)</span></h2>
    <div class="flex gap-1">
      <button class="btn btn-accent btn-sm" onclick="document.getElementById('addSubModal').classList.add('open')">+ Add Subscriber</button>
      <a href="?tab=subscribers&export=csv" class="btn btn-dark btn-sm">Export CSV</a>
    </div>
  </div>

  <?php
  if (isset($_GET['export']) && $_GET['export'] === 'csv' && $tab === 'subscribers') {
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="helvetica-subscribers-'.date('Y-m-d').'.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Email','Source','Subscribed At']);
    foreach ($subs as $s) fputcsv($out, [$s['email'], $s['source']??'', $s['subscribed_at']??'']);
    fclose($out);
    exit;
  }
  ?>

  <!-- Stats bar -->
  <div class="quick-stats" style="margin-bottom:2rem;">
    <?php
    $ws = count(array_filter($subs, fn($s) => ($s['source']??'') === 'website'));
    $ms = count(array_filter($subs, fn($s) => ($s['source']??'') === 'manual'));
    ?>
    <div class="qs-item"><div class="qs-icon" style="background:#e3f2fd;"><span>🌐</span></div><div class="qs-info"><label>Website</label><strong><?php echo $ws; ?></strong></div></div>
    <div class="qs-item"><div class="qs-icon" style="background:#f3e5f5;"><span>✍️</span></div><div class="qs-info"><label>Manual</label><strong><?php echo $ms; ?></strong></div></div>
    <div class="qs-item"><div class="qs-icon" style="background:#e8f5e9;"><span>📧</span></div><div class="qs-info"><label>Total</label><strong><?php echo $total_subs; ?></strong></div></div>
  </div>

  <?php if (empty($subs)): ?>
  <div class="empty-state"><p>No subscribers yet. Share your store!</p></div>
  <?php else: ?>
  <div class="table-wrap">
    <div class="table-toolbar">
      <input class="search-field" type="text" id="sub-search" placeholder="Search subscribers...">
    </div>
    <table id="subs-table">
      <thead><tr><th>#</th><th>Email</th><th>Source</th><th>Subscribed At</th><th>Actions</th></tr></thead>
      <tbody>
        <?php foreach ($subs as $i => $s): ?>
        <tr data-search="<?php echo strtolower($s['email']); ?>">
          <td style="color:var(--muted);font-family:var(--mono);"><?php echo $i+1; ?></td>
          <td><strong><?php echo htmlspecialchars($s['email']); ?></strong></td>
          <td><span class="sub-source sub-<?php echo htmlspecialchars($s['source']??'manual'); ?>"><?php echo htmlspecialchars($s['source']??'manual'); ?></span></td>
          <td style="color:var(--muted);font-size:1.2rem;"><?php echo htmlspecialchars($s['subscribed_at']??''); ?></td>
          <td>
            <div style="display:flex;gap:.6rem;">
              <button class="btn btn-blue btn-sm" onclick="window.location='?tab=email&to=<?php echo urlencode($s['email']); ?>'">Email</button>
              <form method="POST" onsubmit="return confirm('Remove this subscriber?')" style="display:inline;">
                <input type="hidden" name="remove_subscriber" value="1">
                <input type="hidden" name="sub_email" value="<?php echo htmlspecialchars($s['email']); ?>">
                <button type="submit" class="btn btn-red btn-sm">Remove</button>
              </form>
            </div>
          </td>
        </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
  </div>
  <?php endif; ?>

  <!-- Add Subscriber Modal -->
  <div class="modal-overlay" id="addSubModal">
    <div class="modal-box">
      <div class="modal-head"><h3>Add Subscriber</h3><button class="modal-close" onclick="document.getElementById('addSubModal').classList.remove('open')">×</button></div>
      <div class="modal-body">
        <form method="POST">
          <input type="hidden" name="add_subscriber" value="1">
          <div class="form-group"><label>Email Address</label><input type="email" name="sub_email" placeholder="customer@email.com" required></div>
          <button type="submit" class="form-submit">Add Subscriber →</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ════════════════ EMAIL CENTER ════════════════ -->
  <?php elseif ($tab === 'email'): ?>

  <?php $pre_to = urldecode($_GET['to'] ?? ''); ?>

  <div class="section-head"><h2>Email Center</h2></div>

  <div class="cards-2">
    <!-- Single Customer Email -->
    <div>
      <div class="chart-card">
        <h3 style="margin-bottom:2rem;">✉️ Email a Customer</h3>
        <?php
        // All unique emails: users + subscribers
        $all_emails = array_unique(array_merge(
          array_keys($users),
          array_map(fn($s) => $s['email'], $subs)
        ));
        sort($all_emails);
        ?>
        <form method="POST">
          <input type="hidden" name="mail_customer" value="1">
          <div class="form-group">
            <label>To</label>
            <select name="mail_to" id="mailCustomerEmail" style="width:100%;border:1.5px solid var(--border);padding:1.1rem 1.4rem;font-size:1.3rem;outline:none;background:#fff;">
              <?php foreach ($all_emails as $em): ?>
              <option value="<?php echo htmlspecialchars($em); ?>" <?php echo $pre_to===$em?'selected':''; ?>><?php echo htmlspecialchars($em); ?></option>
              <?php endforeach; ?>
            </select>
          </div>
          <div class="form-group"><label>Subject</label><input type="text" name="mail_subject" placeholder="Subject line..." required></div>
          <div class="form-group"><label>Message</label><textarea name="mail_body" rows="8" placeholder="Write your message here..." required style="resize:vertical;"></textarea></div>
          <button type="submit" class="form-submit">Send Email →</button>
        </form>
      </div>
    </div>

    <!-- Bulk Email -->
    <div>
      <div class="chart-card" style="margin-bottom:2rem;">
        <h3 style="margin-bottom:.5rem;">📢 Email All Customers</h3>
        <p style="font-size:1.2rem;color:var(--muted);margin-bottom:2rem;">Sends to all <?php echo count(array_unique(array_merge(array_keys($users), array_map(fn($s)=>$s['email'],$subs)))); ?> registered customers & subscribers.</p>
        <form method="POST">
          <input type="hidden" name="mail_all" value="1">
          <div class="form-group"><label>Subject</label><input type="text" name="blast_subject" placeholder="e.g. New Arrivals at HELVETICA!" required></div>
          <div class="form-group"><label>Message</label><textarea name="blast_body" rows="8" placeholder="Write your broadcast message..." required style="resize:vertical;"></textarea></div>
          <button type="submit" class="form-submit" onclick="return confirm('Send blast to ALL customers & subscribers?')">Send to All →</button>
        </form>
      </div>

      <!-- Email List Preview -->
      <div class="chart-card">
        <h3 style="margin-bottom:1.4rem;">📋 Recipient List</h3>
        <div style="max-height:20rem;overflow-y:auto;border:1.5px solid var(--border);padding:1.2rem;">
          <?php foreach ($all_emails as $em): ?>
          <div style="font-size:1.2rem;padding:.5rem 0;border-bottom:1px solid var(--border);color:var(--muted);"><?php echo htmlspecialchars($em); ?></div>
          <?php endforeach; ?>
          <?php if (empty($all_emails)): ?>
          <p style="color:var(--muted);font-size:1.3rem;">No customers or subscribers yet.</p>
          <?php endif; ?>
        </div>
        <p style="font-size:1.1rem;color:var(--muted);margin-top:1rem;font-family:var(--mono);"><?php echo count($all_emails); ?> total recipients</p>
      </div>
    </div>
  </div>

  <!-- ════════════════ SETTINGS ════════════════ -->
  <?php elseif ($tab === 'settings'): ?>

  <div class="section-head"><h2>Settings & Info</h2></div>

  <div class="cards-2">
    <div class="chart-card">
      <h3 style="margin-bottom:2rem;">⚙️ Store Configuration</h3>
      <div style="display:flex;flex-direction:column;gap:1.4rem;">
        <div style="padding:1.4rem;background:var(--surface);border:1.5px solid var(--border);">
          <div style="font-size:1rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem;font-family:var(--mono);">Owner Email</div>
          <div style="font-size:1.4rem;font-weight:600;"><?php echo ADMIN_EMAIL; ?></div>
        </div>
        <div style="padding:1.4rem;background:var(--surface);border:1.5px solid var(--border);">
          <div style="font-size:1rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem;font-family:var(--mono);">UPI ID</div>
          <div style="font-size:1.4rem;font-weight:600;font-family:var(--mono);">8490986234@ptaxis</div>
        </div>
        <div style="padding:1.4rem;background:var(--surface);border:1.5px solid var(--border);">
          <div style="font-size:1rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem;font-family:var(--mono);">SMTP Host (Brevo)</div>
          <div style="font-size:1.4rem;font-weight:600;font-family:var(--mono);"><?php echo SMTP_HOST; ?>:<?php echo SMTP_PORT; ?></div>
        </div>
        <div style="padding:1.4rem;background:var(--surface);border:1.5px solid var(--border);">
          <div style="font-size:1rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;color:var(--muted);margin-bottom:.4rem;font-family:var(--mono);">Data Files</div>
          <div style="font-size:1.2rem;">
            <div style="margin-bottom:.4rem;">📄 Orders DB: <code><?php echo basename(DB_FILE); ?></code> (<?php echo file_exists(DB_FILE)?round(filesize(DB_FILE)/1024,1).'KB':'Not created'; ?>)</div>
            <div>📧 Subscribers: <code><?php echo basename(SUBS_FILE); ?></code> (<?php echo file_exists(SUBS_FILE)?round(filesize(SUBS_FILE)/1024,1).'KB':'Not created'; ?>)</div>
          </div>
        </div>
      </div>
    </div>

    <div>
      <div class="chart-card" style="margin-bottom:2rem;">
        <h3 style="margin-bottom:2rem;">📊 Database Summary</h3>
        <div style="display:flex;flex-direction:column;gap:1rem;">
          <div class="od-row"><span>Total Orders</span><strong><?php echo $total_orders; ?></strong></div>
          <div class="od-row"><span>Total Customers</span><strong><?php echo $total_users; ?></strong></div>
          <div class="od-row"><span>Newsletter Subscribers</span><strong><?php echo $total_subs; ?></strong></div>
          <div class="od-row"><span>Total Revenue</span><strong style="color:var(--dark);">₹<?php echo number_format($total_revenue); ?></strong></div>
          <div class="od-row"><span>COD Orders</span><strong><?php echo $cod_count; ?></strong></div>
          <div class="od-row"><span>UPI Orders</span><strong><?php echo $upi_count; ?></strong></div>
          <div class="od-row"><span>Delivered Orders</span><strong style="color:#2e7d32;"><?php echo $delivered_count; ?></strong></div>
        </div>
      </div>

      <div class="chart-card">
        <h3 style="margin-bottom:2rem;">🔗 Quick Links</h3>
        <div style="display:flex;flex-direction:column;gap:1rem;">
          <a href="/" target="_blank" class="btn btn-accent" style="justify-content:center;">View Storefront ↗</a>
          <a href="?tab=orders&export=csv" class="btn btn-dark" style="justify-content:center;">Export Orders CSV</a>
          <a href="?tab=subscribers&export=csv" class="btn btn-dark" style="justify-content:center;">Export Subscribers CSV</a>
          <a href="?admin_logout=1" class="btn btn-red" style="justify-content:center;border:none;">Logout Admin</a>
        </div>
      </div>
    </div>
  </div>

  <?php endif; ?>
  </div><!-- /content -->
</main>
</div><!-- /admin-wrap -->

<!-- ─── JAVASCRIPT ─── -->
<script>
// Sidebar toggle (mobile)
const sidebarToggle = document.getElementById('sidebar-toggle');
const sidebar = document.getElementById('sidebar');
if (sidebarToggle) {
  sidebarToggle.style.display = window.innerWidth <= 900 ? 'block' : 'none';
  sidebarToggle.addEventListener('click', () => sidebar.classList.toggle('open'));
  window.addEventListener('resize', () => {
    sidebarToggle.style.display = window.innerWidth <= 900 ? 'block' : 'none';
    if (window.innerWidth > 900) sidebar.classList.remove('open');
  });
}

// Order search + filter
const orderSearch = document.getElementById('order-search');
const statusFilter = document.getElementById('status-filter');
const paymentFilter = document.getElementById('payment-filter');

function filterOrders() {
  const q = (orderSearch?.value||'').toLowerCase();
  const sf = statusFilter?.value||'';
  const pf = paymentFilter?.value||'';
  document.querySelectorAll('#orders-table tbody tr').forEach(row => {
    const search_ok = !q || (row.dataset.search||'').includes(q);
    const status_ok = !sf || row.dataset.status === sf;
    const pay_ok = !pf || row.dataset.payment === pf;
    row.style.display = search_ok && status_ok && pay_ok ? '' : 'none';
  });
}
orderSearch?.addEventListener('input', filterOrders);
statusFilter?.addEventListener('change', filterOrders);
paymentFilter?.addEventListener('change', filterOrders);

// Customer search
document.getElementById('customer-search')?.addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('.customer-card').forEach(c => {
    c.style.display = !q || (c.dataset.search||'').includes(q) ? '' : 'none';
  });
});

// Subscriber search
document.getElementById('sub-search')?.addEventListener('input', function() {
  const q = this.value.toLowerCase();
  document.querySelectorAll('#subs-table tbody tr').forEach(r => {
    r.style.display = !q || (r.dataset.search||'').includes(q) ? '' : 'none';
  });
});

// Order detail drawer
window.openOrderDetail = function(o) {
  document.getElementById('od-id').textContent    = o.order_id || '';
  document.getElementById('od-status').textContent = o.status  || '';
  document.getElementById('od-payment').textContent= o.payment || '';
  document.getElementById('od-date').textContent   = o.created_at || '';
  document.getElementById('od-total').textContent  = o.total   || '';
  document.getElementById('od-name').textContent   = o.name    || '';
  document.getElementById('od-email').textContent  = o.email   || '';
  document.getElementById('od-phone').textContent  = o.phone   || '';
  document.getElementById('od-address').textContent= o.address || '';
  document.getElementById('od-items').textContent  = o.items   || '';
  document.getElementById('od-form-id').value      = o.order_id|| '';
  document.getElementById('od-del-id').value       = o.order_id|| '';
  const sel = document.getElementById('od-status-select');
  if (sel) sel.value = o.status || 'Confirmed';
  document.getElementById('odOverlay').classList.add('open');
  document.getElementById('odDrawer').classList.add('open');
  document.body.style.overflow = 'hidden';
};
window.closeOrderDetail = function() {
  document.getElementById('odOverlay').classList.remove('open');
  document.getElementById('odDrawer').classList.remove('open');
  document.body.style.overflow = '';
};
document.getElementById('odOverlay')?.addEventListener('click', closeOrderDetail);

// Close modals on overlay click
document.querySelectorAll('.modal-overlay').forEach(m => {
  m.addEventListener('click', e => { if (e.target === m) m.classList.remove('open'); });
});

// Auto-dismiss msg banner
const msgBanner = document.querySelector('.msg-banner');
if (msgBanner) { setTimeout(() => { msgBanner.style.opacity='0'; msgBanner.style.transition='opacity .5s'; setTimeout(()=>msgBanner.remove(),500); }, 5000); }

// Bar chart animation on load
document.querySelectorAll('.bar-fill').forEach(b => {
  const h = b.style.height;
  b.style.height = '0%';
  setTimeout(() => { b.style.height = h; }, 100);
});
</script>

<?php endif; // admin session ?>
</body>
</html>