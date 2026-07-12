<?php
/* ════════════════════════════════════════════════════════════
   MAINTENANCE GATE  —  shared by index.php and superadmin.php
   Include this file, then call sa_maintenance_gate() as early
   as possible (right after session_start()).
   ════════════════════════════════════════════════════════════ */

define('SA_SETTINGS_FILE', __DIR__ . '/superadmin_settings.json');

// Secret preview key — lets the owner browse the live site while
// maintenance mode is on, without logging into the super admin panel.
// CHANGE THIS to your own secret string.
define('SA_PREVIEW_KEY', 'rv-preview-9f2c7a');

function sa_default_settings() {
    return [
        'maintenance_mode'    => false,
        'maintenance_title'   => "We'll be right back",
        'maintenance_message' => "Our site is currently down for scheduled maintenance. Please check back shortly.",
        'updated_at'          => date('d M Y, h:i A'),
    ];
}

function sa_load_settings() {
    if (!file_exists(SA_SETTINGS_FILE)) return sa_default_settings();
    $data = json_decode(file_get_contents(SA_SETTINGS_FILE), true);
    if (!is_array($data)) return sa_default_settings();
    return array_merge(sa_default_settings(), $data);
}

function sa_save_settings($settings) {
    $settings['updated_at'] = date('d M Y, h:i A');
    file_put_contents(SA_SETTINGS_FILE, json_encode($settings, JSON_PRETTY_PRINT));
    return $settings;
}

/**
 * Call this right after session_start() on any public-facing page.
 * Renders the maintenance page and halts execution if maintenance
 * mode is on and the current visitor has no bypass.
 */
function sa_maintenance_gate() {
    // Preview link: ?sa_preview=SECRET  -> grants this browser a bypass and
    // redirects to a clean URL so the key isn't left sitting in the address bar.
    if (isset($_GET['sa_preview']) && hash_equals(SA_PREVIEW_KEY, (string)$_GET['sa_preview'])) {
        $_SESSION['sa_preview'] = true;
        $clean = strtok($_SERVER['REQUEST_URI'], '?');
        header('Location: ' . $clean);
        exit;
    }

    // Bypass for the super admin (logged in) or anyone with a granted preview.
    if (!empty($_SESSION['sa_admin']) || !empty($_SESSION['sa_preview'])) return;

    $settings = sa_load_settings();
    if (empty($settings['maintenance_mode'])) return;

    http_response_code(503);
    header('Retry-After: 3600');
    ?>
<!DOCTYPE html>
<html lang="en">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title><?php echo htmlspecialchars($settings['maintenance_title']); ?></title>
<link href="https://fonts.googleapis.com/css2?family=Space+Grotesk:wght@400;500;700&family=Syne:wght@700;800&family=Space+Mono:wght@400;700&display=swap" rel="stylesheet">
<style>
*{box-sizing:border-box;margin:0;padding:0;}
html{font-size:62.5%;}
body{min-height:100vh;display:flex;align-items:center;justify-content:center;background:linear-gradient(135deg,#0c1a2b 0%,#1a2d4a 50%,#0c1a2b 100%);font-family:'Space Grotesk',sans-serif;position:relative;overflow:hidden;padding:2rem;}
body::before{content:'';position:absolute;inset:0;background-image:linear-gradient(rgba(182,255,59,0.04) 1px,transparent 1px),linear-gradient(90deg,rgba(182,255,59,0.04) 1px,transparent 1px);background-size:40px 40px;}
.box{position:relative;z-index:2;max-width:520px;text-align:center;color:#fff;}
.icon{font-size:5rem;margin-bottom:2rem;}
h1{font-family:'Syne',sans-serif;font-size:3rem;font-weight:800;margin-bottom:1.6rem;color:#b6ff3b;letter-spacing:-.02em;}
p{font-size:1.5rem;line-height:1.7;color:rgba(255,255,255,.7);}
.foot{margin-top:3rem;font-size:1.1rem;letter-spacing:.15em;text-transform:uppercase;color:rgba(255,255,255,.3);font-family:'Space Mono',monospace;}
</style>
</head>
<body>
  <div class="box">
    <div class="icon">🛠️</div>
    <h1><?php echo htmlspecialchars($settings['maintenance_title']); ?></h1>
    <p><?php echo nl2br(htmlspecialchars($settings['maintenance_message'])); ?></p>
    <div class="foot">Site under maintenance</div>
  </div>
</body>
</html>
    <?php
    exit;
}