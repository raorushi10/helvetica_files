<?php
  /* Template Name: Custom Project Page */

  session_start();
  require_once __DIR__ . '/maintenance-check.php';
  sa_maintenance_gate();

  //  PAGE ROUTING 
  $current_page = isset($_GET['page']) ? trim($_GET['page']) : 'home';
  $valid_pages = ['home','upper-wear','tshirts','hoodies','jackets','shirts','lower-wear','jeans','shorts','trousers','joggers','accessories','caps','watches','bags','jewelry','shoes'];
  if (!in_array($current_page, $valid_pages)) $current_page = 'home';

  //  TRACK ORDER 
  $track_result = null;
  $track_error  = '';
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hv_track_order'])) {
      $track_id = strtoupper(trim($_POST['track_order_id'] ?? ''));
      if (!$track_id) {
          $track_error = 'Please enter your Order ID.';
      } else {
          $db = hv_load_db();
          $found = null;
          foreach (($db['orders'] ?? []) as $o) {
              if (strtoupper($o['order_id']) === $track_id) { $found = $o; break; }
          }
          if ($found) {
              $track_result = $found;
          } else {
              // Bluff: If it looks like an HV- order, show a fake in-transit status
              if (preg_match('/^HV-[A-Z0-9]{6,}$/', $track_id)) {
                  $track_result = [
                      'order_id'   => $track_id,
                      'name'       => 'Valued Customer',
                      'status'     => 'In Transit',
                      'created_at' => date('d M Y', strtotime('-3 days')),
                      'items'      => 'Your items are on the way.',
                      'total'      => '—',
                      'payment'    => '—',
                      'address'    => 'Your registered address',
                      '_bluff'     => true,
                  ];
              } else {
                  $track_error = 'No order found with that ID. Please check and try again.';
              }
          }
      }
  }

  //  CONFIG 
  define('OWNER_EMAIL',    'teamhelvetica0@gmail.com');
  define('OWNER_UPI',      '8490986234@ptaxis');
  define('OWNER_UPI_NAME', 'HELVETICA');
  define('DB_FILE',        __DIR__ . '/helvetica_data.json');

  //  HELPERS 
  function hv_load_db() {
      if (!file_exists(DB_FILE)) return ['users'=>[], 'orders'=>[]];
      return json_decode(file_get_contents(DB_FILE), true) ?: ['users'=>[], 'orders'=>[]];
  }
  function hv_save_db($db) {
      file_put_contents(DB_FILE, json_encode($db, JSON_PRETTY_PRINT));
  }
  function hv_order_id() {
      return 'HV-' . strtoupper(substr(md5(uniqid(rand(),true)),0,8));
  }

  //  INITIALISE VARIABLES 
  $order_success = false;
  $order_error   = '';
  $order_data    = [];
  $auth_error    = '';
  $auth_success  = '';

  $current_user  = isset($_SESSION['hv_user']) ? $_SESSION['hv_user'] : null;

  //  AUTH: SIGN UP 
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hv_signup'])) {
      $db   = hv_load_db();
      $name  = trim($_POST['signup_name']  ?? '');
      $email = strtolower(trim($_POST['signup_email'] ?? ''));
      $pass  = $_POST['signup_pass'] ?? '';
      if (!$name || !$email || !$pass) {
          $auth_error = 'Please fill all fields.';
      } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
          $auth_error = 'Invalid email address.';
      } elseif (isset($db['users'][$email])) {
          $auth_error = 'An account with this email already exists. Please log in.';
      } else {
          $db['users'][$email] = [
              'name'    => $name,
              'email'   => $email,
              'pass'    => password_hash($pass, PASSWORD_DEFAULT),
              'created' => date('Y-m-d H:i:s'),
          ];
          hv_save_db($db);
          $_SESSION['hv_user'] = ['name'=>$name, 'email'=>$email];
          $current_user = $_SESSION['hv_user'];
          $auth_success = 'Account created! Welcome, ' . htmlspecialchars($name) . '.';
      }
  }

  //  AUTH: LOG IN 
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['hv_login'])) {
      $db    = hv_load_db();
      $email = strtolower(trim($_POST['login_email'] ?? ''));
      $pass  = $_POST['login_pass'] ?? '';
      if (!$email || !$pass) {
          $auth_error = 'Please fill all fields.';
      } elseif (!isset($db['users'][$email])) {
          $auth_error = 'No account found with that email.';
      } elseif (!password_verify($pass, $db['users'][$email]['pass'])) {
          $auth_error = 'Incorrect password.';
      } else {
          $_SESSION['hv_user'] = ['name'=>$db['users'][$email]['name'], 'email'=>$email];
          $current_user = $_SESSION['hv_user'];
          $auth_success = 'Welcome back, ' . htmlspecialchars($db['users'][$email]['name']) . '!';
      }
  }

  //  AUTH: LOG OUT 
  if (isset($_GET['hv_logout'])) {
      unset($_SESSION['hv_user']);
      $current_user = null;
      header('Location: ' . strtok($_SERVER['REQUEST_URI'],'?'));
      exit;
  }

  //  ORDER PROCESSING 
  if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['helvetica_order'])) {
      $db = hv_load_db();

      $name    = trim($_POST['customer_name']    ?? '');
      $email   = trim($_POST['customer_email']   ?? '');
      $phone   = trim($_POST['customer_phone']   ?? '');
      $address = trim($_POST['customer_address'] ?? '');
      $city    = trim($_POST['customer_city']    ?? '');
      $pin     = trim($_POST['customer_pincode'] ?? '');
      $payment = trim($_POST['payment_method']   ?? 'COD');
      $items   = trim($_POST['order_items']      ?? '');
      $total   = trim($_POST['order_total']      ?? '');

      if (!$name || !$email || !$phone || !$address || !$city || !$pin) {
          $order_error = 'Please fill all required fields.';
      } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
          $order_error = 'Please enter a valid email address.';
      } elseif (!preg_match('/^\d{6}$/', $pin)) {
          $order_error = 'Please enter a valid 6-digit pincode.';
      } else {
          $order_id = !empty($_POST['order_id_override']) 
                      ? preg_replace('/[^A-Z0-9\-]/', '', strtoupper($_POST['order_id_override']))
                      : hv_order_id();
          $order_record = [
              'order_id'   => $order_id,
              'name'       => $name,
              'email'      => $email,
              'phone'      => $phone,
              'address'    => "$address, $city – $pin",
              'payment'    => $payment,
              'items'      => $items,
              'total'      => $total,
              'status'     => 'Confirmed',
              'created_at' => date('d M Y, h:i A'),
          ];

          // Save to DB
          if (!isset($db['orders'])) $db['orders'] = [];
          $db['orders'][] = $order_record;

          // Link order to user account if logged in
          if ($current_user) {
              $ue = $current_user['email'];
              if (!isset($db['user_orders'][$ue])) $db['user_orders'][$ue] = [];
              $db['user_orders'][$ue][] = $order_id;
          }
          hv_save_db($db);

          //  EMAIL SYSTEM 
          // Brevo (formerly Sendinblue) SMTP — free 300 emails/day
          // SMTP Login = the email you used to sign up on brevo.com
          // SMTP Key   = the key from Brevo dashboard → SMTP & API → Generate SMTP key
          if (!defined('SMTP_HOST')) define('SMTP_HOST', 'smtp-relay.brevo.com');
          if (!defined('SMTP_PORT')) define('SMTP_PORT', 587);
          if (!defined('SMTP_USER')) define('SMTP_USER', 'teamhelvetica0@gmail.com');
          if (!defined('SMTP_PASS')) define('SMTP_PASS', 'xsmtpsib-795574bd85f0c910a96b8179d5212ccd89deca0a990fdd9a978befc9b7f6d174-wc3ZShvkZmARasgz');
          if (!defined('SMTP_FROM')) define('SMTP_FROM', 'teamhelvetica0@gmail.com');
          if (!defined('SMTP_NAME')) define('SMTP_NAME', 'HELVETICA Store');
          //  HTML email builder 
          function hv_build_customer_email($name, $order_id, $items, $total, $payment, $address, $city, $pin, $phone) {
              $items_html = '';
              foreach (explode("\n", trim($items)) as $line) {
                  if (trim($line)) {
                      $items_html .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;font-size:13px;color:#444;">' . htmlspecialchars($line) . '</td></tr>';
                  }
              }
              return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0;">
  <tr><td align="center">
  <table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;max-width:580px;">
    <tr><td style="background:#0c1a2b;padding:28px 32px;">
      <h1 style="margin:0;font-family:\'Space Grotesk\',Arial,sans-serif;font-size:26px;color:#b6ff3b;letter-spacing:-1px;">HELVETICA</h1>
      <p style="margin:4px 0 0;color:rgba(255,255,255,0.5);font-size:12px;letter-spacing:2px;text-transform:uppercase;">Official Online Store</p>
    </td></tr>
    <tr><td style="padding:32px;">
      <div style="background:#b6ff3b;display:inline-block;padding:10px 20px;margin-bottom:20px;">
        <span style="font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#0c1a2b;"> ORDER CONFIRMED</span>
      </div>
      <h2 style="margin:0 0 8px;font-size:22px;color:#0c1a2b;">Hey ' . htmlspecialchars($name) . '!</h2>
      <p style="margin:0 0 24px;font-size:14px;color:#666;line-height:1.6;">Thank you for shopping with HELVETICA. Your order has been received and is being processed.</p>
      <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8f8;border:1px solid #eee;margin-bottom:24px;">
        <tr><td style="padding:14px 16px;border-bottom:1px solid #eee;">
          <span style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#999;">Order ID</span>
          <div style="font-size:16px;font-weight:800;color:#0c1a2b;margin-top:4px;">' . htmlspecialchars($order_id) . '</div>
        </td>
        <td style="padding:14px 16px;border-bottom:1px solid #eee;">
          <span style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#999;">Date</span>
          <div style="font-size:13px;color:#444;margin-top:4px;">' . date('d M Y, h:i A') . '</div>
        </td>
        <td style="padding:14px 16px;border-bottom:1px solid #eee;">
          <span style="font-size:11px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#999;">Payment</span>
          <div style="font-size:13px;color:#444;margin-top:4px;">' . htmlspecialchars($payment) . '</div>
        </td></tr>
      </table>
      <h3 style="margin:0 0 12px;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#999;">Items Ordered</h3>
      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eee;margin-bottom:8px;">' . $items_html . '</table>
      <table width="100%" cellpadding="0" cellspacing="0" style="margin-bottom:24px;">
        <tr><td style="padding:12px 0;border-top:2px solid #0c1a2b;text-align:right;">
          <span style="font-size:16px;font-weight:800;color:#0c1a2b;">Total: ' . htmlspecialchars($total) . '</span>
        </td></tr>
      </table>
      <h3 style="margin:0 0 10px;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#999;">Delivery Address</h3>
      <p style="margin:0 0 24px;font-size:13px;color:#444;line-height:1.7;background:#f8f8f8;padding:14px 16px;border-left:3px solid #b6ff3b;">' . htmlspecialchars($name) . '<br>' . htmlspecialchars($address) . ', ' . htmlspecialchars($city) . ' – ' . htmlspecialchars($pin) . '<br>Phone: ' . htmlspecialchars($phone) . '</p>
      <p style="font-size:13px;color:#888;line-height:1.6;">We\'ll notify you once your order is shipped. For any queries, reply to this email or WhatsApp us.</p>
    </td></tr>
    <tr><td style="background:#0c1a2b;padding:20px 32px;text-align:center;">
      <p style="margin:0;font-size:12px;color:rgba(255,255,255,0.4);">© 2026 HELVETICA Fashion Private Limited. All Rights Reserved.</p>
    </td></tr>
  </table>
  </td></tr></table></body></html>';
          }

          function hv_build_owner_email($name, $email, $phone, $order_id, $items, $total, $payment, $address, $city, $pin) {
              $items_html = '';
              foreach (explode("\n", trim($items)) as $line) {
                  if (trim($line)) {
                      $items_html .= '<tr><td style="padding:8px 12px;border-bottom:1px solid #f0f0f0;font-size:13px;color:#444;">' . htmlspecialchars($line) . '</td></tr>';
                  }
              }
              return '<!DOCTYPE html><html><head><meta charset="UTF-8"></head><body style="margin:0;padding:0;background:#f4f4f4;font-family:Arial,sans-serif;">
  <table width="100%" cellpadding="0" cellspacing="0" style="background:#f4f4f4;padding:30px 0;">
  <tr><td align="center">
  <table width="580" cellpadding="0" cellspacing="0" style="background:#ffffff;max-width:580px;">
    <tr><td style="background:#0c1a2b;padding:28px 32px;">
      <h1 style="margin:0;font-family:Arial,sans-serif;font-size:22px;color:#b6ff3b;"> NEW ORDER RECEIVED</h1>
      <p style="margin:4px 0 0;color:rgba(255,255,255,0.5);font-size:12px;">' . htmlspecialchars($order_id) . ' · ' . date('d M Y, h:i A') . '</p>
    </td></tr>
    <tr><td style="padding:28px 32px;">
      <h3 style="margin:0 0 12px;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#999;">Customer Details</h3>
      <table width="100%" cellpadding="0" cellspacing="0" style="background:#f8f8f8;border:1px solid #eee;margin-bottom:20px;">
        <tr><td style="padding:10px 14px;border-bottom:1px solid #eee;font-size:13px;"><strong>Name:</strong> ' . htmlspecialchars($name) . '</td></tr>
        <tr><td style="padding:10px 14px;border-bottom:1px solid #eee;font-size:13px;"><strong>Email:</strong> <a href="mailto:' . htmlspecialchars($email) . '" style="color:#0c1a2b;">' . htmlspecialchars($email) . '</a></td></tr>
        <tr><td style="padding:10px 14px;border-bottom:1px solid #eee;font-size:13px;"><strong>Phone:</strong> ' . htmlspecialchars($phone) . '</td></tr>
        <tr><td style="padding:10px 14px;font-size:13px;"><strong>Address:</strong> ' . htmlspecialchars($address) . ', ' . htmlspecialchars($city) . ' – ' . htmlspecialchars($pin) . '</td></tr>
      </table>
      <h3 style="margin:0 0 12px;font-size:13px;font-weight:700;letter-spacing:2px;text-transform:uppercase;color:#999;">Items Ordered</h3>
      <table width="100%" cellpadding="0" cellspacing="0" style="border:1px solid #eee;margin-bottom:8px;">' . $items_html . '</table>
      <p style="text-align:right;margin:8px 0 20px;font-size:16px;font-weight:800;color:#0c1a2b;">Total: ' . htmlspecialchars($total) . '</p>
      <p style="background:#f8f8f8;padding:12px 16px;border-left:3px solid #b6ff3b;font-size:13px;margin:0;"><strong>Payment:</strong> ' . htmlspecialchars($payment) . '</p>
    </td></tr>
    <tr><td style="background:#f4f4f4;padding:16px 32px;text-align:center;">
      <p style="margin:0;font-size:12px;color:#aaa;">HELVETICA Admin Notification</p>
    </td></tr>
  </table>
  </td></tr></table></body></html>';
          }

          //  Send via Brevo SMTP using WordPress bundled PHPMailer 
          function hv_send_mail($to, $subject, $html_body, $reply_to = '') {
              $log = __DIR__ . '/mail_debug.log';
              $sent = false;

              // Load PHPMailer — WordPress always has it bundled
              if (!class_exists('PHPMailer\PHPMailer\PHPMailer') && !class_exists('PHPMailer')) {
                  if (defined('ABSPATH')) {
                      // WordPress 5.5+ uses namespaced PHPMailer
                      $paths = [
                          ABSPATH . 'wp-includes/PHPMailer/PHPMailer.php',
                          ABSPATH . 'wp-includes/PHPMailer/SMTP.php',
                          ABSPATH . 'wp-includes/PHPMailer/Exception.php',
                          // Older WP
                          ABSPATH . 'wp-includes/class-phpmailer.php',
                          ABSPATH . 'wp-includes/class-smtp.php',
                      ];
                      foreach ($paths as $p) {
                          if (file_exists($p)) require_once $p;
                      }
                  }
              }

              // Pick the right class name
              if (class_exists('PHPMailer\PHPMailer\PHPMailer')) {
                  $cls = 'PHPMailer\PHPMailer\PHPMailer';
              } elseif (class_exists('PHPMailer')) {
                  $cls = 'PHPMailer';
              } else {
                  file_put_contents($log, date('Y-m-d H:i:s') . " ERROR: PHPMailer class not found\n", FILE_APPEND);
                  return false;
              }

              try {
                  $mail = new $cls(true); // true = throw exceptions
                  $mail->isSMTP();
                  $mail->Host       = SMTP_HOST;   // smtp-relay.brevo.com
                  $mail->SMTPAuth   = true;
                  $mail->Username   = SMTP_USER;   // your Brevo login email
                  $mail->Password   = SMTP_PASS;   // your Brevo SMTP key
                  $mail->SMTPSecure = PHPMailer\PHPMailer\PHPMailer::ENCRYPTION_STARTTLS;
                  $mail->Port       = SMTP_PORT;   // 587
                  $mail->CharSet    = 'UTF-8';

                  $mail->setFrom(SMTP_FROM, SMTP_NAME);
                  $mail->addAddress($to);
                  if ($reply_to) $mail->addReplyTo($reply_to);

                  $mail->isHTML(true);
                  $mail->Subject = $subject;
                  $mail->Body    = $html_body;
                  $mail->AltBody = strip_tags(str_replace(['<br>','<br/>','</tr>','</p>'], "\n", $html_body));

                  $sent = $mail->send();
                  file_put_contents($log, date('Y-m-d H:i:s') . "  SENT to $to — Subject: $subject\n", FILE_APPEND);

              } catch (Exception $e) {
                  $err = isset($mail) ? $mail->ErrorInfo : $e->getMessage();
                  file_put_contents($log, date('Y-m-d H:i:s') . "  FAILED to $to — $err\n", FILE_APPEND);

                  // Last resort: native mail() so order is never completely lost
                  $hdrs = "MIME-Version: 1.0\r\nContent-Type: text/html; charset=UTF-8\r\nFrom: " . SMTP_NAME . " <" . SMTP_FROM . ">\r\n";
                  if ($reply_to) $hdrs .= "Reply-To: $reply_to\r\n";
                  $sent = @mail($to, $subject, $html_body, $hdrs);
                  file_put_contents($log, date('Y-m-d H:i:s') . " fallback mail() to $to: " . ($sent?'OK':'ALSO FAILED') . "\n", FILE_APPEND);
              }

              return $sent;
          }

          //  Build HTML emails 
          $html_customer = hv_build_customer_email($name, $order_id, $items, $total, $payment, $address, $city, $pin, $phone);
          $html_owner    = hv_build_owner_email($name, $email, $phone, $order_id, $items, $total, $payment, $address, $city, $pin);

          //  Send to customer 
          hv_send_mail(
              $email,
              "Your HELVETICA Order Confirmed – $order_id",
              $html_customer,
              SMTP_FROM
          );

          //  Send to owner 
          hv_send_mail(
              OWNER_EMAIL,
              " NEW ORDER $order_id from $name",
              $html_owner,
              $email
          );

          $order_success = true;
          $order_data    = $order_record;
      }
  }

  //  Load user orders for account panel 
  $user_orders = [];
  if ($current_user) {
      $db = hv_load_db();
      $ue = $current_user['email'];
      $linked_ids = $db['user_orders'][$ue] ?? [];
      foreach ($db['orders'] as $o) {
          if (in_array($o['order_id'], $linked_ids)) {
              $user_orders[] = $o;
          }
      }
      $user_orders = array_reverse($user_orders);
  }
  ?>
  <!DOCTYPE html>
  <html class="no-js" lang="en">
  <head>
    <meta charset="utf-8">
    <meta http-equiv="X-UA-Compatible" content="IE=edge">
    <meta name="viewport" content="width=device-width,initial-scale=1">
    <title>HELVETICA - Official Online Store</title>
    <link rel="icon" type="image/png" href="/wp-content/themes/fevicon.png">
    <meta name="description" content="HELVETICA — Bold fashion for the fearless. Upper wear, lower wear, and accessories that make a statement.">
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link href="https://fonts.googleapis.com/css2?family=Montserrat:wght@100;300;400;500;600;700;800;900&family=Space+Grotesk:wght@300;400;500;600;700&family=Space+Mono:wght@400;700&family=Syne:wght@400;500;700;800&display=swap" rel="stylesheet">
    <style>
      /* ===== CSS VARIABLES ===== */
      :root {
        --font-body-family: Montserrat, sans-serif;
        --font-heading-family: 'Space Grotesk', sans-serif;
        --color-text: 31, 31, 31;
        --color-bg: 255, 255, 255;
        --color-accent: #b6ff3b;
        --color-accent-blue: #141418;
        --color-accent-pink: #e445ff;
        --color-dark: #0c1a2b;
        --page-width: 180rem;
        --transition: 0.3s ease;
      }

      *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }

      html { font-size: 62.5%; scroll-behavior: smooth; }

      body {
        font-family: var(--font-body-family);
        font-size: 1.4rem;
        color: rgb(var(--color-text));
        background: #fff;
        overflow-x: hidden;
      }

      a { text-decoration: none; color: inherit; }
      ul { list-style: none; }
      img { max-width: 100%; display: block; }
      button { cursor: pointer; border: none; background: none; font-family: inherit; }

      .visually-hidden {
        position: absolute;
        width: 1px;
        height: 1px;
        overflow: hidden;
        clip: rect(0,0,0,0);
        white-space: nowrap;
      }

      .page-width {
        max-width: 1600px;
        margin: 0 auto;
        padding: 0 2rem;
      }

      /* ===== ANNOUNCEMENT BAR ===== */
      .announcement-bar {
        background: var(--color-dark);
        color: var(--color-accent);
        padding: 1rem 2rem;
        text-align: center;
        font-size: 1.2rem;
        font-weight: 600;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        overflow: hidden;
        position: relative;
      }

      .announcement-track {
        display: flex;
        animation: marquee 30s linear infinite;
        width: max-content;
      }

      .announcement-track span {
        padding: 0 4rem;
        white-space: nowrap;
      }

      @keyframes marquee {
        0% { transform: translateX(0); }
        100% { transform: translateX(-50%); }
      }

      /* ===== HEADER ===== */
      .header-wrapper {
        position: sticky;
        top: 0;
        z-index: 100;
        background: #fff;
        border-bottom: 1px solid rgba(31,31,31,0.1);
        transition: box-shadow var(--transition);
      }

      .header-wrapper.scrolled {
        box-shadow: 0 2px 20px rgba(0,0,0,0.08);
      }

      .header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 1.6rem 4rem;
        max-width: 1600px;
        margin: 0 auto;
        position: relative;
      }

      /* Logo */
      .header__heading-link {
        display: flex;
        align-items: center;
      }

      .logo-svg {
        height: 2.8rem;
        width: auto;
      }

      /* Nav */
      .header__inline-menu { display: flex; }
      .header__inline-menu .list-menu {
        display: flex;
        align-items: center;
        gap: 3rem;
      }

      .header__menu-item {
        font-size: 1.3rem;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        color: var(--color-dark);
        padding: 0.5rem 0;
        position: relative;
        display: flex;
        align-items: center;
        gap: 0.5rem;
        transition: color var(--transition);
      }

      .header__menu-item:hover { color: var(--color-accent-blue); }

      .icon-caret {
        width: 1rem;
        height: 0.6rem;
        transition: transform var(--transition);
      }

      /* Mega Menu */
      .mega-menu {
        position: relative;
      }

      .mega-menu summary { list-style: none; cursor: pointer; }
      .mega-menu summary::-webkit-details-marker { display: none; }

      .mega-menu__content {
        display: none;
        position: absolute;
        top: calc(100% + 1rem);
        left: 50%;
        transform: translateX(-50%);
        background: #fff;
        border: 1px solid rgba(31,31,31,0.1);
        box-shadow: 0 20px 60px rgba(0,0,0,0.12);
        min-width: 700px;
        z-index: 200;
        padding: 3rem;
      }

      .mega-menu[open] .mega-menu__content { display: block; }
      .mega-menu[open] .icon-caret { transform: rotate(180deg); }

      .mega-menu-block {
        display: grid;
        grid-template-columns: 200px 1fr 1fr 160px;
        gap: 2rem;
        align-items: start;
      }

      .mega-menu-block .image { position: relative; overflow: hidden; }
      .mega-menu-block .image img {
        width: 100%;
        height: 280px;
        object-fit: cover;
        transition: transform 0.5s ease;
      }
      .mega-menu-block .image:hover img { transform: scale(1.05); }

      .mega-menu-block .column-row-image {
        display: flex;
        flex-direction: column;
        gap: 1.5rem;
      }

      .mega-menu-block .row-image { position: relative; overflow: hidden; }
      .mega-menu-block .row-image img {
        width: 100%;
        height: 125px;
        object-fit: cover;
        transition: transform 0.5s ease;
      }
      .mega-menu-block .row-image:hover img { transform: scale(1.05); }

      .mega-btn {
        position: absolute;
        bottom: 1rem;
        left: 1rem;
        background: var(--color-accent);
        color: var(--color-dark);
        font-size: 1.1rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        padding: 0.5rem 1rem;
        text-transform: uppercase;
      }

      .mega-menu__list { padding: 1rem 0; }
      .mega-menu__link {
        display: block;
        padding: 0.6rem 0;
        font-size: 1.2rem;
        font-weight: 500;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        color: var(--color-dark);
        border-bottom: 1px solid rgba(31,31,31,0.06);
        transition: color var(--transition);
      }
      .mega-menu__link:hover { color: var(--color-accent-blue); }

      /* Header Icons */
      .header__icons {
        display: flex;
        align-items: center;
        gap: 2rem;
      }

      .header__icon {
        display: flex;
        align-items: center;
        justify-content: center;
        position: relative;
        cursor: pointer;
      }

      .header__icon svg { width: 2.2rem; height: 2.2rem; }

      .cart-count-bubble {
        position: absolute;
        top: -0.8rem;
        right: -0.8rem;
        background: var(--color-accent);
        color: var(--color-dark);
        font-size: 1rem;
        font-weight: 800;
        width: 1.8rem;
        height: 1.8rem;
        border-radius: 50%;
        display: flex;
        align-items: center;
        justify-content: center;
      }

      /* Search Modal */
      .search-modal {
        display: none;
        position: fixed;
        top: 0; left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.5);
        z-index: 500;
        align-items: flex-start;
        justify-content: center;
        padding-top: 10rem;
      }
      .search-modal.active { display: flex; }
      .search-modal__form {
        background: #fff;
        width: 100%;
        max-width: 600px;
        padding: 2rem;
      }
      .search__input {
        width: 100%;
        border: none;
        border-bottom: 2px solid var(--color-dark);
        padding: 1rem 0;
        font-family: inherit;
        font-size: 1.8rem;
        outline: none;
      }

      /* Mobile Hamburger */
      .header__icon--menu { display: none; }

      /* ===== MOBILE DRAWER ===== */
      .menu-drawer-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 300;
      }
      .menu-drawer-overlay.active { display: block; }

      .menu-drawer {
        position: fixed;
        top: 0;
        left: -100%;
        width: 85%;
        max-width: 400px;
        height: 100%;
        background: #fff;
        z-index: 400;
        overflow-y: auto;
        transition: left 0.3s ease;
        display: flex;
        flex-direction: column;
      }
      .menu-drawer.active { left: 0; }

      .menu-drawer__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 2rem;
        border-bottom: 1px solid rgba(31,31,31,0.1);
      }

      .menu-drawer__close {
        width: 2.4rem;
        height: 2.4rem;
      }

      .menu-drawer__nav {
        padding: 2rem;
        flex: 1;
      }

      .menu-drawer__menu { border-top: 1px solid rgba(31,31,31,0.1); }

      .menu-drawer__menu-item {
        display: block;
        padding: 1.5rem 0;
        font-size: 1.4rem;
        font-weight: 600;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        border-bottom: 1px solid rgba(31,31,31,0.06);
      }

      .menu-drawer__tabs {
        display: flex;
        border-bottom: 2px solid var(--color-dark);
        margin-bottom: 2rem;
      }

      .drawer-tab {
        flex: 1;
        padding: 1rem;
        font-size: 1.2rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        text-align: center;
        cursor: pointer;
        border-bottom: 3px solid transparent;
        margin-bottom: -2px;
        transition: all var(--transition);
      }
      .drawer-tab.active { border-bottom-color: var(--color-accent-blue); color: var(--color-accent-blue); }

      .drawer-tab-content { display: none; }
      .drawer-tab-content.active { display: block; }

      .drawer-accordion__btn {
        display: flex;
        align-items: center;
        justify-content: space-between;
        width: 100%;
        padding: 1.5rem 0;
        font-size: 1.4rem;
        font-weight: 600;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        border-bottom: 1px solid rgba(31,31,31,0.06);
        background: none;
      }

      .drawer-accordion__btn svg { width: 1.6rem; height: 1.6rem; transition: transform var(--transition); }
      .drawer-accordion__btn.active svg.plus { display: none; }
      .drawer-accordion__btn.active svg.minus { display: block !important; }
      .drawer-accordion__btn svg.minus { display: none; }

      .drawer-submenu {
        display: none;
        padding: 1rem 0 1rem 1.5rem;
      }
      .drawer-submenu.active { display: block; }
      .drawer-submenu a {
        display: block;
        padding: 0.8rem 0;
        font-size: 1.3rem;
        color: var(--color-dark);
        border-bottom: 1px solid rgba(31,31,31,0.04);
      }

      .menu-drawer__social {
        padding: 2rem;
        border-top: 1px solid rgba(31,31,31,0.1);
        display: flex;
        gap: 1.5rem;
        align-items: center;
      }

      /* ===== HERO BANNER ===== */
      .hero-banner {
        position: relative;
        overflow: hidden;
        background: #111;
      }

      .hero-banner__media {
        width: 100%;
        padding-bottom: 39.0625%; /* 16:6.25 ratio */
        position: relative;
      }

      .hero-banner__media img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
      }

      .hero-banner__content {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
      }

      .hero-banner__box {
        text-align: center;
        padding: 3rem;
      }

      .hero-banner__heading {
        font-family: var(--font-heading-family);
        font-size: 5.5rem;
        font-weight: 700;
        color: #fff;
        line-height: 1.1;
        margin-bottom: 1rem;
        text-shadow: 0 2px 20px rgba(0,0,0,0.5);
        letter-spacing: -0.02em;
      }

      .hero-banner__text {
        font-size: 1.6rem;
        color: rgba(255,255,255,0.85);
        margin-bottom: 2.5rem;
        font-weight: 400;
        letter-spacing: 0.1em;
        text-transform: uppercase;
      }

      .hero-banner__buttons {
        display: flex;
        gap: 1.5rem;
        justify-content: center;
        flex-wrap: wrap;
      }

      .btn-primary {
        display: inline-block;
        background: var(--color-accent);
        color: var(--color-dark);
        font-size: 1.2rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        padding: 1.4rem 3.5rem;
        transition: all var(--transition);
      }

      .btn-primary:hover {
        background: var(--color-dark);
        color: var(--color-accent);
      }

      .btn-outline {
        display: inline-block;
        border: 2px solid #fff;
        color: #fff;
        font-size: 1.2rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        padding: 1.2rem 3.5rem;
        transition: all var(--transition);
      }
      .btn-outline:hover {
        background: #fff;
        color: var(--color-dark);
      }

      /* Gradient overlay on hero */
      .hero-banner__media::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(to right, rgba(0,0,0,0.45) 0%, rgba(0,0,0,0.2) 50%, rgba(0,0,0,0.35) 100%);
        z-index: 1;
      }
      .hero-banner__content {
        z-index: 2;
      }

      /* ===== COLLECTION SECTIONS ===== */
      .featured-collection {
        padding: 5rem 0 6rem;
        border-top: 1px solid rgba(31,31,31,0.08);
      }

      .collection__title {
        display: flex;
        align-items: center;
        justify-content: space-between;
        margin-bottom: 2.5rem;
        padding: 0 4rem;
        max-width: 1600px;
        margin-left: auto;
        margin-right: auto;
        gap: 1.5rem;
      }

      .collection__title .title {
        font-family: var(--font-heading-family);
        font-size: 3.2rem;
        font-weight: 700;
        letter-spacing: -0.02em;
        color: var(--color-dark);
      }

      .cta-btn-holder {
        display: flex;
        gap: 1rem;
      }

      .cta-btn-holder a {
        display: inline-block;
        border: 1.5px solid var(--color-dark);
        color: var(--color-dark);
        font-size: 1.1rem;
        font-weight: 700;
        letter-spacing: 0.12em;
        text-transform: uppercase;
        padding: 0.9rem 2rem;
        transition: all var(--transition);
      }

      .cta-btn-holder a:hover {
        background: var(--color-dark);
        color: var(--color-accent);
      }

      /* Product Grid */
      .product-grid {
        display: flex;
        overflow-x: auto;
        scrollbar-width: none;
        -webkit-overflow-scrolling: touch;
        padding: 0 4rem 2rem;
        max-width: 1600px;
        margin: 0 auto;
        gap: 1.6rem;
      }

      .product-grid::-webkit-scrollbar { display: none; }

      .grid__item {
        flex: 0 0 300px;
        width: 300px;
        min-width: 300px;
        border-right: none;
        padding-right: 1.6rem;
      }

      .grid__item:last-child { padding-right: 0; }

      .card-wrapper {
        position: relative;
        cursor: pointer;
        height: 100%;
      }

      .card {
        position: relative;
        overflow: hidden;
      }

      .card__inner {
        position: relative;
        overflow: hidden;
      }

      .card__inner .card__media {
        position: relative;
        overflow: hidden;
        background: #f0f0f0;
        isolation: isolate;
      }

      .card__inner .card__media::before {
        content: '';
        display: block;
        padding-bottom: 135%;
      }

      .card__inner .card__media img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        object-position: top center;
        transition: transform 0.6s ease;
      }

      .card-wrapper:hover .card__media img {
        transform: scale(1.04);
      }

      .card__information {
        padding: 1.8rem 1.6rem;
        border-top: 1px solid rgba(31,31,31,0.1);
      }

      .title-price {
        display: flex;
        align-items: flex-start;
        justify-content: space-between;
        gap: 1rem;
      }

      .card__heading {
        font-size: 1.3rem;
        font-weight: 600;
        line-height: 1.3;
        margin: 0;
        letter-spacing: 0.02em;
      }

      .card__heading span {
        display: block;
        font-size: 1.1rem;
        font-weight: 400;
        color: rgba(31,31,31,0.6);
        letter-spacing: 0.05em;
        text-transform: uppercase;
      }

      .price__container { text-align: right; }

      .price-item--regular {
        font-size: 1.3rem;
        font-weight: 700;
      }

      .mrp-price {
        font-size: 0.9rem;
        font-weight: 400;
        color: rgba(31,31,31,0.5);
        margin-left: 0.3rem;
      }

      .inc-tax {
        font-size: 0.9rem;
        color: rgba(31,31,31,0.5);
        margin-top: 0.2rem;
      }

      /* Quick Add button */
      .quick-add-overlay {
        position: absolute;
        bottom: 0;
        left: 0;
        right: 0;
        background: var(--color-accent);
        color: var(--color-dark);
        font-size: 1.1rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        padding: 1.2rem;
        text-align: center;
        transform: translateY(100%);
        transition: transform 0.3s ease;
      }

      .card-wrapper:hover .quick-add-overlay {
        transform: translateY(0);
      }

      /* ===== CART DRAWER ===== */
      .cart-drawer-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 600;
      }
      .cart-drawer-overlay.active { display: block; }

      .cart-drawer {
        position: fixed;
        top: 0;
        right: -420px;
        width: 420px;
        max-width: 100vw;
        height: 100%;
        background: #fff;
        z-index: 700;
        display: flex;
        flex-direction: column;
        transition: right 0.35s cubic-bezier(0.4,0,0.2,1);
        box-shadow: -4px 0 40px rgba(0,0,0,0.15);
      }
      .cart-drawer.active { right: 0; }

      .cart-drawer__header {
        display: flex;
        align-items: center;
        justify-content: space-between;
        padding: 2rem 2.4rem;
        border-bottom: 1px solid rgba(31,31,31,0.1);
      }
      .cart-drawer__title {
        font-family: var(--font-heading-family);
        font-size: 1.8rem;
        font-weight: 700;
        letter-spacing: 0.05em;
        text-transform: uppercase;
      }
      .cart-drawer__close {
        width: 3.2rem;
        height: 3.2rem;
        display: flex;
        align-items: center;
        justify-content: center;
        cursor: pointer;
        background: none;
        border: none;
        border-radius: 50%;
        transition: background 0.2s;
      }
      .cart-drawer__close:hover { background: rgba(31,31,31,0.07); }
      .cart-drawer__close svg { width: 2rem; height: 2rem; }

      .cart-drawer__body {
        flex: 1;
        overflow-y: auto;
        padding: 2rem 2.4rem;
      }

      .cart-drawer__empty {
        display: flex;
        flex-direction: column;
        align-items: center;
        justify-content: center;
        height: 100%;
        gap: 1.5rem;
        color: rgba(31,31,31,0.4);
      }
      .cart-drawer__empty svg { width: 5rem; height: 5rem; opacity: 0.3; }
      .cart-drawer__empty p { font-size: 1.4rem; font-weight: 500; letter-spacing: 0.05em; }

      .cart-item {
        display: flex;
        gap: 1.6rem;
        padding: 1.6rem 0;
        border-bottom: 1px solid rgba(31,31,31,0.08);
        animation: slideInItem 0.3s ease;
      }
      @keyframes slideInItem {
        from { opacity: 0; transform: translateX(20px); }
        to { opacity: 1; transform: translateX(0); }
      }
      .cart-item__img {
        width: 8rem;
        height: 9.6rem;
        object-fit: cover;
        flex-shrink: 0;
        background: #f5f5f5;
      }
      .cart-item__info { flex: 1; display: flex; flex-direction: column; gap: 0.6rem; }
      .cart-item__name { font-size: 1.3rem; font-weight: 600; line-height: 1.3; }
      .cart-item__variant { font-size: 1.1rem; color: rgba(31,31,31,0.5); text-transform: uppercase; letter-spacing: 0.05em; }
      .cart-item__price { font-size: 1.3rem; font-weight: 700; }

      .cart-item__qty {
        display: flex;
        align-items: center;
        gap: 0.8rem;
        margin-top: auto;
      }
      .cart-item__qty button {
        width: 2.6rem;
        height: 2.6rem;
        border: 1.5px solid rgba(31,31,31,0.2);
        background: none;
        font-size: 1.4rem;
        font-weight: 600;
        cursor: pointer;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: all 0.2s;
      }
      .cart-item__qty button:hover { background: var(--color-dark); color: #fff; border-color: var(--color-dark); }
      .cart-item__qty span { font-size: 1.3rem; font-weight: 600; min-width: 2rem; text-align: center; }
      .cart-item__remove {
        background: none;
        border: none;
        cursor: pointer;
        color: rgba(31,31,31,0.35);
        font-size: 1.8rem;
        line-height: 1;
        padding: 0;
        align-self: flex-start;
        transition: color 0.2s;
      }
      .cart-item__remove:hover { color: #e00; }

      .cart-drawer__footer {
        padding: 2rem 2.4rem;
        border-top: 1px solid rgba(31,31,31,0.1);
        background: #fff;
      }
      .cart-subtotal {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 1.6rem;
        font-size: 1.4rem;
        font-weight: 600;
      }
      .cart-subtotal__amount { font-size: 1.6rem; font-weight: 800; }
      .cart-checkout-btn {
        width: 100%;
        background: var(--color-dark);
        color: var(--color-accent);
        font-size: 1.3rem;
        font-weight: 700;
        letter-spacing: 0.15em;
        text-transform: uppercase;
        padding: 1.6rem;
        border: none;
        cursor: pointer;
        transition: all 0.3s;
      }
      .cart-checkout-btn:hover { background: var(--color-accent); color: var(--color-dark); }

      /* Quick add feedback */
      .quick-add-overlay.added {
        background: var(--color-dark) !important;
        color: var(--color-accent) !important;
      }

      /* NEW badge */
      .custom-badge {
        position: absolute;
        top: 1.5rem;
        left: 0;
        background: var(--color-accent-blue);
        color: #fff;
        font-size: 1.1rem;
        font-weight: 700;
        padding: 0.5rem 1.1rem;
        letter-spacing: 0.05em;
        text-transform: uppercase;
        z-index: 2;
      }

      .badge-sale {
        background: var(--color-accent-pink);
      }

      /* Slider arrows */
      .slider-arrows {
        display: flex;
        gap: 1rem;
        align-items: center;
      }

      .slide-arrow {
        width: 4rem;
        height: 4rem;
        border-radius: 50%;
        background: var(--color-dark);
        color: #fff;
        display: flex;
        align-items: center;
        justify-content: center;
        transition: background var(--transition);
        cursor: pointer;
      }

      .slide-arrow:hover { background: var(--color-accent-blue); }
      .slide-arrow svg { width: 1.8rem; height: 1.8rem; fill: #fff; }

      /* ===== SECONDARY BANNER ===== */
      .secondary-banner {
        position: relative;
        overflow: hidden;
        background: #111;
      }

      .secondary-banner__media {
        width: 100%;
        padding-bottom: 39.0625%;
        position: relative;
      }

      .secondary-banner__media img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
      }

      .secondary-banner__media::after {
        content: '';
        position: absolute;
        inset: 0;
        background: rgba(0,0,0,0.35);
      }

      .secondary-banner__content {
        position: absolute;
        inset: 0;
        display: flex;
        align-items: center;
        justify-content: center;
      }

      /* ===== FOOTER TOP ===== */
      .footer-top-section {
        background: var(--color-accent);
        padding: 5rem 4rem 4.5rem;
        overflow: hidden;
      }

      .ft-top-content-container { max-width: 1600px; margin: 0 auto; }

      .icons-area {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 3rem;
        margin-bottom: 2rem;
      }

      .icon-item img {
        width: 50px;
        height: 50px;
        object-fit: contain;
      }

      .footer-top-content h2 {
        font-family: var(--font-heading-family);
        font-size: 5.5rem;
        font-weight: 700;
        color: var(--color-dark);
        text-align: center;
        line-height: 1.1;
        letter-spacing: -0.02em;
      }

      .footer-top-content .hg-content {
        background: var(--color-accent-pink);
        color: var(--color-dark);
        padding: 0 0.5rem;
      }

      /* ===== TRUST ICONS ===== */
      .trust-icon-section {
        border-top: 1px solid var(--color-dark);
        border-bottom: 1px solid var(--color-dark);
        padding: 1rem 0;
      }

      .icons-with-text-wrapping {
        display: flex;
        align-items: center;
        justify-content: space-around;
        max-width: 1600px;
        margin: 0 auto;
        padding: 0.5rem 4rem;
        gap: 2rem;
      }

      .icons-text-part {
        display: flex;
        align-items: center;
        gap: 1.5rem;
        flex: 1;
        justify-content: center;
      }

      .icons-text-part img {
        width: 50px;
        height: auto;
        object-fit: contain;
      }

      .icons-text-part h4 {
        font-size: 1.3rem;
        font-weight: 700;
        letter-spacing: 0.05em;
      }

      .icons-text-part p {
        font-size: 1.1rem;
        color: rgba(31,31,31,0.6);
        margin-top: 0.2rem;
      }

      /* ===== FOOTER MAIN ===== */
      footer.footer {
        background: var(--color-dark);
        color: #fff;
        padding: 8rem 4rem 3.5rem;
      }

      .footer__content-top {
        max-width: 1600px;
        margin: 0 auto;
        display: grid;
        grid-template-columns: 1fr 1fr 1fr;
        gap: 5rem;
        padding-bottom: 5rem;
      }

      .footer-block__heading {
        font-family: var(--font-heading-family);
        font-size: 1.4rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        color: var(--color-accent);
        margin-bottom: 2rem;
        display: flex;
        align-items: center;
        gap: 1rem;
      }

      .footer-block__heading img {
        width: 3rem;
        height: auto;
        filter: invert(1) sepia(1) saturate(5) hue-rotate(15deg);
      }

      .contact-info-wrapper { display: flex; flex-direction: column; gap: 1.2rem; }
      .contact-info-wrapper a { color: rgba(255,255,255,0.7); font-size: 1.3rem; transition: color var(--transition); display: flex; align-items: center; gap: 0.8rem; }
      .contact-info-wrapper a:hover { color: var(--color-accent); }
      .contact-info-wrapper strong { color: #fff; }

      .border-bottom-footer {
        border-bottom: 1px solid rgba(255,255,255,0.1);
        margin: 2.5rem 0;
      }

      .list-social {
        display: flex;
        gap: 1.5rem;
      }

      .list-social__link {
        color: rgba(255,255,255,0.7);
        transition: color var(--transition);
      }
      .list-social__link:hover { color: var(--color-accent); }
      .list-social__link svg { width: 2rem; height: 2rem; }

      .list-unstyled { display: flex; flex-direction: column; gap: 0.8rem; }
      .list-unstyled a {
        color: rgba(255,255,255,0.7);
        font-size: 1.2rem;
        letter-spacing: 0.03em;
        transition: color var(--transition);
      }
      .list-unstyled a:hover { color: var(--color-accent); }

      .link-underline { position: relative; }
      .link-underline::after {
        content: '';
        position: absolute;
        bottom: -1px;
        left: 0;
        width: 0;
        height: 1px;
        background: var(--color-accent);
        transition: width var(--transition);
      }
      .list-unstyled a:hover .link-underline::after { width: 100%; }

      .footer__content-bottom {
        max-width: 1600px;
        margin: 0 auto;
        border-top: 1px solid rgba(255,255,255,0.1);
        padding-top: 2.5rem;
        text-align: center;
      }

      .footer__copyright {
        font-size: 1.1rem;
        color: rgba(255,255,255,0.4);
      }

      .footer__copyright a { color: rgba(255,255,255,0.4); }
      .footer__copyright a:hover { color: var(--color-accent); }

      /* ===== QUICK ADD CART DRAWER (Mobile) ===== */
      .q-atc-mob-model-overlay {
        display: none;
        position: fixed;
        inset: 0;
        background: rgba(0,0,0,0.5);
        z-index: 500;
      }
      .q-atc-mob-model-overlay.active { display: block; }

      .q-atc-mob-model-wrapper {
        position: fixed;
        bottom: -100%;
        left: 0;
        right: 0;
        background: #fff;
        border-radius: 2rem 2rem 0 0;
        z-index: 600;
        padding: 2rem;
        transition: bottom 0.3s ease;
      }
      .q-atc-mob-model-wrapper.active { bottom: 0; }

      /* ===== CONTACT US MODAL ===== */
      .contact-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:900;align-items:center;justify-content:center;padding:2rem;}
      .contact-overlay.open{display:flex;}
      .contact-box{background:#fff;width:100%;max-width:520px;position:relative;animation:pdIn .3s ease;}
      .contact-close{position:absolute;top:1.2rem;right:1.4rem;background:none;border:none;font-size:2.4rem;cursor:pointer;color:rgba(255,255,255,.6);z-index:3;line-height:1;}
      .contact-close:hover{color:#fff;}
      .contact-head{background:var(--color-dark);padding:2.4rem 3rem 2rem;}
      .contact-head h2{font-family:var(--font-heading-family);font-size:2.2rem;font-weight:700;color:var(--color-accent);}
      .contact-head p{color:rgba(255,255,255,.5);font-size:1.2rem;margin-top:.3rem;}
      .contact-body{padding:2.4rem 3rem;}
      .contact-info-item{display:flex;align-items:flex-start;gap:1.4rem;padding:1.4rem 0;border-bottom:1px solid rgba(31,31,31,.08);}
      .contact-info-item:last-child{border-bottom:none;}
      .contact-info-item svg{flex-shrink:0;margin-top:.2rem;}
      .contact-info-item h4{font-size:1.2rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.3rem;color:var(--color-dark);}
      .contact-info-item p,.contact-info-item a{font-size:1.3rem;color:rgba(31,31,31,.65);text-decoration:none;transition:color .2s;}
      .contact-info-item a:hover{color:var(--color-dark);}
      @media(max-width:600px){.contact-body{padding:2rem 1.8rem;}}

      /* ===== NEWSLETTER / FORM SECTION ===== */
      .newsletter-section {
        background: var(--color-dark);
        padding: 5rem 4rem;
      }

      .newsletter-section__inner {
        max-width: 600px;
        margin: 0 auto;
        text-align: center;
      }

      .newsletter-section h2 {
        font-family: var(--font-heading-family);
        font-size: 3.5rem;
        font-weight: 700;
        color: var(--color-accent);
        margin-bottom: 1rem;
        letter-spacing: -0.02em;
      }

      .newsletter-section p {
        color: rgba(255,255,255,0.7);
        font-size: 1.4rem;
        margin-bottom: 3rem;
      }

      .email-form {
        display: flex;
        border: 1.5px solid rgba(255,255,255,0.3);
      }

      .email-form input {
        flex: 1;
        background: none;
        border: none;
        padding: 1.4rem 2rem;
        color: #fff;
        font-family: inherit;
        font-size: 1.3rem;
        outline: none;
      }

      .email-form input::placeholder { color: rgba(255,255,255,0.4); }

      .email-form button {
        background: var(--color-accent);
        color: var(--color-dark);
        border: none;
        padding: 1.4rem 2.5rem;
        font-size: 1.1rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
        transition: background var(--transition);
      }
      .email-form button:hover { background: #fff; }

      /* ===== CATEGORIES BANNER (2-col) ===== */
      .categories-split {
        display: grid;
        grid-template-columns: 1fr 1fr;
      }

      .cat-panel {
        position: relative;
        overflow: hidden;
        cursor: pointer;
      }

      .cat-panel__media {
        padding-bottom: 80%;
        position: relative;
        overflow: hidden;
      }

      .cat-panel__media img {
        position: absolute;
        inset: 0;
        width: 100%;
        height: 100%;
        object-fit: cover;
        transition: transform 0.7s ease;
      }

      .cat-panel:hover .cat-panel__media img { transform: scale(1.05); }

      .cat-panel__media::after {
        content: '';
        position: absolute;
        inset: 0;
        background: linear-gradient(to top, rgba(0,0,0,0.5) 0%, rgba(0,0,0,0) 60%);
      }

      .cat-panel__content {
        position: absolute;
        bottom: 3rem;
        left: 3rem;
        z-index: 2;
      }

      .cat-panel__label {
        font-family: var(--font-heading-family);
        font-size: 3rem;
        font-weight: 700;
        color: #fff;
        letter-spacing: -0.02em;
        margin-bottom: 1rem;
      }

      /* ===== RESPONSIVE ===== */
      @media screen and (max-width: 990px) {
        .header { padding: 1.4rem 2rem; }
        .header__inline-menu { display: none; }
        .header__icon--menu { display: flex !important; }
        .hero-banner__heading { font-size: 3.5rem; }
        .collection__title { padding: 0 2rem; }
        .product-grid { padding: 0 2rem; }
        .grid__item { flex: 0 0 50%; }
        .footer__content-top { grid-template-columns: 1fr 1fr; gap: 3rem; padding: 0 2rem 4rem; }
        footer.footer { padding: 4rem 0 3rem; }
        .icons-with-text-wrapping { padding: 0.5rem 2rem; gap: 1rem; }
        .footer-top-section { padding: 4rem 2rem; }
        .footer-top-content h2 { font-size: 3.5rem; }
        .categories-split { grid-template-columns: 1fr; }
      }

      @media screen and (max-width: 749px) {
        html { font-size: 55%; }
        .hero-banner__media { padding-bottom: 150%; }
        .hero-banner__heading { font-size: 2.8rem; }
        .hero-banner__text { font-size: 1.4rem; }
        .grid__item { flex: 0 0 260px; width: 260px; min-width: 260px; }
        .footer__content-top { grid-template-columns: 1fr; gap: 0; }
        .footer-top-content h2 { font-size: 3rem; }
        .icons-area { display: none; }
        .icons-text-part { flex-direction: column; text-align: center; gap: 0.8rem; }
        .secondary-banner__media { padding-bottom: 150%; }
      }

      /* ===== HERO PLACEHOLDER GRADIENT ===== */
      .hero-gradient {
        background: linear-gradient(135deg, #1a1a2e 0%, #16213e 30%, #0f3460 70%, #533483 100%);
      }

      .hero-gradient-2 {
        background: linear-gradient(135deg, #1a1a1a 0%, #2d2d2d 50%, #1a1a1a 100%);
      }

      .cat-gradient-upper {
        background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
      }

      .cat-gradient-lower {
        background: linear-gradient(135deg, #f093fb 0%, #f5576c 100%);
      }

      .cat-gradient-acc {
        background: linear-gradient(135deg, #4facfe 0%, #00f2fe 100%);
      }

      /* ===== CHECKOUT MODAL ===== */
      .checkout-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:900;align-items:center;justify-content:center;padding:2rem;}
      .checkout-overlay.open{display:flex;}
      .checkout-box{background:#fff;width:100%;max-width:580px;max-height:90vh;overflow-y:auto;position:relative;animation:cmin .3s ease;}
      @keyframes cmin{from{opacity:0;transform:translateY(28px)}to{opacity:1;transform:translateY(0)}}
      .checkout-box__close{position:absolute;top:1.2rem;right:1.4rem;background:none;border:none;font-size:2.4rem;line-height:1;cursor:pointer;color:rgba(255,255,255,.7);z-index:3;}
      .checkout-box__close:hover{color:#fff;}
      .checkout-head{background:var(--color-dark);padding:2.4rem 3rem 2rem;}
      .checkout-head h2{font-family:var(--font-heading-family);font-size:2.2rem;font-weight:700;color:var(--color-accent);}
      .checkout-head p{color:rgba(255,255,255,.55);font-size:1.2rem;margin-top:.3rem;}
      .checkout-body{padding:2.4rem 3rem;}
      .co-summary{background:#f8f8f8;border:1px solid rgba(31,31,31,.08);padding:1.4rem;margin-bottom:2rem;font-size:1.2rem;}
      .co-summary h3{font-size:1.1rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:.8rem;}
      .co-row{display:flex;justify-content:space-between;padding:.45rem 0;border-bottom:1px dashed rgba(31,31,31,.1);}
      .co-total{display:flex;justify-content:space-between;padding:.7rem 0 0;font-weight:800;font-size:1.4rem;}
      .co-group{margin-bottom:1.6rem;}
      .co-group label{display:block;font-size:1.05rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.5rem;}
      .co-group input,.co-group select{width:100%;border:1.5px solid rgba(31,31,31,.2);padding:1.1rem 1.3rem;font-family:inherit;font-size:1.3rem;outline:none;transition:border .2s;background:#fff;}
      .co-group input:focus,.co-group select:focus{border-color:var(--color-dark);}
      .co-2col{display:grid;grid-template-columns:1fr 1fr;gap:1.4rem;}
      .pay-opts{display:flex;gap:1rem;margin-top:.5rem;}
      .pay-opt{flex:1;border:1.5px solid rgba(31,31,31,.2);padding:1.1rem;text-align:center;cursor:pointer;transition:all .2s;}
      .pay-opt.active{border-color:var(--color-dark);background:var(--color-dark);color:#fff;}
      .pay-opt .pi{font-size:1.8rem;display:block;margin-bottom:.3rem;}
      .pay-opt .pl{font-size:1.05rem;font-weight:700;letter-spacing:.05em;text-transform:uppercase;}
      .qr-box{display:none;background:#f0fff0;border:1.5px solid #4caf50;padding:1.8rem;text-align:center;margin-top:1.4rem;}
      .qr-box.show{display:block;}
      .qr-box h4{font-size:1.15rem;font-weight:700;text-transform:uppercase;letter-spacing:.08em;margin-bottom:.4rem;}
      .qr-box p{font-size:1.1rem;color:rgba(31,31,31,.65);margin-bottom:.8rem;}
      .qr-box img{margin:0 auto;border:4px solid #fff;box-shadow:0 2px 10px rgba(0,0,0,.1);}
      .upi-id{display:inline-block;background:#fff;border:1px solid rgba(31,31,31,.1);padding:.6rem 1.2rem;font-size:1.3rem;font-weight:700;margin-top:.8rem;}
      .co-err{background:#fff0f0;border:1px solid #e00;color:#c00;padding:.9rem 1.3rem;font-size:1.2rem;margin-bottom:1.4rem;}
      .co-submit{width:100%;background:var(--color-dark);color:var(--color-accent);font-family:inherit;font-size:1.3rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;padding:1.6rem;border:none;cursor:pointer;margin-top:1.8rem;transition:all .3s;}
      .co-submit:hover{background:var(--color-accent);color:var(--color-dark);}
      /* Success */
      .success-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:950;align-items:center;justify-content:center;padding:2rem;}
      .success-overlay.open{display:flex;}
      .success-box{background:#fff;width:100%;max-width:460px;padding:4rem 3rem;text-align:center;animation:cmin .3s ease;}
      .success-check{width:7rem;height:7rem;background:var(--color-accent);border-radius:50%;display:flex;align-items:center;justify-content:center;margin:0 auto 2rem;font-size:3rem;}
      .success-box h2{font-family:var(--font-heading-family);font-size:2.6rem;font-weight:700;color:var(--color-dark);margin-bottom:.7rem;}
      .success-box p{font-size:1.3rem;color:rgba(31,31,31,.6);margin-bottom:.4rem;}
      .success-id{display:inline-block;background:var(--color-dark);color:var(--color-accent);font-size:1.4rem;font-weight:800;letter-spacing:.1em;padding:.7rem 2rem;margin:1.4rem 0;}
      .success-btn{display:inline-block;background:var(--color-accent);color:var(--color-dark);font-size:1.2rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:1.2rem 3rem;border:none;cursor:pointer;margin-top:1.2rem;transition:all .3s;}
      .success-btn:hover{background:var(--color-dark);color:var(--color-accent);}
      @media(max-width:600px){.checkout-body{padding:2rem 1.8rem;}.co-2col{grid-template-columns:1fr;}.pay-opts{flex-direction:column;}}

      /* ===== PRODUCT DETAIL MODAL ===== */
      .pd-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:800;align-items:center;justify-content:center;padding:1.5rem;}
      .pd-overlay.open{display:flex;}
      .pd-box{background:#fff;width:100%;max-width:900px;max-height:92vh;overflow-y:auto;display:grid;grid-template-columns:1fr 1fr;position:relative;animation:pdIn .3s ease;}
      @keyframes pdIn{from{opacity:0;transform:scale(.96)}to{opacity:1;transform:scale(1)}}
      .pd-close{position:absolute;top:1.2rem;right:1.4rem;background:rgba(0,0,0,.06);border:none;width:3.2rem;height:3.2rem;border-radius:50%;font-size:1.8rem;line-height:1;cursor:pointer;z-index:2;display:flex;align-items:center;justify-content:center;transition:background .2s;}
      .pd-close:hover{background:rgba(0,0,0,.15);}
      .pd-gallery{position:relative;background:#f5f5f5;}
      .pd-gallery img{width:100%;height:100%;object-fit:cover;min-height:480px;}
      .pd-badge-wrap{position:absolute;top:1.5rem;left:0;z-index:1;display:flex;flex-direction:column;gap:.5rem;}
      .pd-info{padding:3rem 2.8rem;display:flex;flex-direction:column;gap:1.6rem;}
      .pd-category{font-size:1.1rem;font-weight:600;letter-spacing:.12em;text-transform:uppercase;color:rgba(31,31,31,.5);}
      .pd-name{font-family:'Space Grotesk',sans-serif;font-size:2.6rem;font-weight:700;color:var(--color-dark);line-height:1.15;letter-spacing:-.02em;}
      .pd-price-wrap{display:flex;align-items:center;gap:1rem;}
      .pd-price{font-size:2.2rem;font-weight:800;}
      .pd-price-old{font-size:1.4rem;text-decoration:line-through;color:rgba(31,31,31,.4);}
      .pd-tax{font-size:1.1rem;color:rgba(31,31,31,.45);}
      .pd-desc{font-size:1.3rem;color:rgba(31,31,31,.65);line-height:1.7;}
      .pd-section-label{font-size:1.1rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:.7rem;}
      .pd-sizes{display:flex;flex-wrap:wrap;gap:.8rem;}
      .pd-size{min-width:4.2rem;height:4.2rem;border:1.5px solid rgba(31,31,31,.25);font-size:1.2rem;font-weight:600;cursor:pointer;display:flex;align-items:center;justify-content:center;transition:all .2s;background:#fff;}
      .pd-size:hover{border-color:var(--color-dark);background:rgba(31,31,31,.05);}
      .pd-size.selected{background:var(--color-dark);color:#fff;border-color:var(--color-dark);}
      .pd-size.oos{opacity:.35;cursor:not-allowed;text-decoration:line-through;}
      .pd-colors{display:flex;gap:.8rem;flex-wrap:wrap;}
      .pd-color{width:3.2rem;height:3.2rem;border-radius:50%;border:2px solid transparent;cursor:pointer;transition:border .2s;}
      .pd-color.selected{border-color:var(--color-dark);outline:2px solid var(--color-dark);outline-offset:2px;}
      .pd-qty-row{display:flex;align-items:center;gap:1.2rem;}
      .pd-qty-row button{width:3.4rem;height:3.4rem;border:1.5px solid rgba(31,31,31,.2);font-size:1.6rem;font-weight:600;cursor:pointer;background:#fff;display:flex;align-items:center;justify-content:center;transition:all .2s;}
      .pd-qty-row button:hover{background:var(--color-dark);color:#fff;}
      .pd-qty-row span{font-size:1.5rem;font-weight:700;min-width:2.5rem;text-align:center;}
      .pd-atc-btn{width:100%;background:var(--color-dark);color:var(--color-accent);font-size:1.3rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;padding:1.6rem;border:none;cursor:pointer;transition:all .3s;margin-top:.4rem;}
      .pd-atc-btn:hover{background:var(--color-accent);color:var(--color-dark);}
      .pd-atc-btn.added{background:var(--color-accent);color:var(--color-dark);}
      .pd-features{display:flex;flex-direction:column;gap:.6rem;}
      .pd-feature{display:flex;align-items:center;gap:.8rem;font-size:1.2rem;color:rgba(31,31,31,.6);}
      .pd-feature::before{content:'';color:var(--color-dark);font-weight:700;font-size:1.1rem;}
      @media(max-width:700px){
        .pd-box{grid-template-columns:1fr;max-width:100%;max-height:95vh;}
        .pd-gallery img{min-height:280px;}
        .pd-info{padding:2rem 1.8rem;}
        .pd-name{font-size:2rem;}
      }

      /* ===== AUTH MODAL ===== */
      .auth-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:850;align-items:center;justify-content:center;padding:1.5rem;}
      .auth-overlay.open{display:flex;}
      .auth-box{background:#fff;width:100%;max-width:440px;position:relative;animation:pdIn .3s ease;overflow:hidden;}
      .auth-close{position:absolute;top:1.2rem;right:1.4rem;background:none;border:none;font-size:2.2rem;cursor:pointer;z-index:2;color:rgba(31,31,31,.4);}
      .auth-close:hover{color:#000;}
      .auth-tabs{display:flex;border-bottom:2px solid rgba(31,31,31,.1);}
      .auth-tab{flex:1;padding:1.4rem;font-size:1.2rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;text-align:center;cursor:pointer;background:none;border:none;border-bottom:3px solid transparent;margin-bottom:-2px;transition:all .2s;}
      .auth-tab.active{border-bottom-color:var(--color-dark);color:var(--color-dark);}
      .auth-panel{display:none;padding:2.4rem;}
      .auth-panel.active{display:block;}
      .auth-group{margin-bottom:1.4rem;}
      .auth-group label{display:block;font-size:1.05rem;font-weight:700;letter-spacing:.08em;text-transform:uppercase;margin-bottom:.5rem;color:rgba(31,31,31,.7);}
      .auth-group input{width:100%;border:1.5px solid rgba(31,31,31,.2);padding:1.1rem 1.3rem;font-family:inherit;font-size:1.3rem;outline:none;transition:border .2s;}
      .auth-group input:focus{border-color:var(--color-dark);}
      .auth-submit{width:100%;background:var(--color-dark);color:var(--color-accent);font-family:inherit;font-size:1.3rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;padding:1.5rem;border:none;cursor:pointer;margin-top:.5rem;transition:all .3s;}
      .auth-submit:hover{background:var(--color-accent);color:var(--color-dark);}
      .auth-msg{padding:1rem 1.4rem;font-size:1.2rem;margin-bottom:1.2rem;border:1px solid;}
      .auth-msg.error{background:#fff0f0;border-color:#e00;color:#c00;}
      .auth-msg.success{background:#f0fff0;border-color:#4caf50;color:#2d7a2d;}
      .auth-head{background:var(--color-dark);padding:2rem 2.4rem;}
      .auth-head h2{font-family:'Space Grotesk',sans-serif;font-size:2rem;font-weight:700;color:var(--color-accent);}
      .auth-head p{color:rgba(255,255,255,.5);font-size:1.2rem;margin-top:.2rem;}

      /* ===== ACCOUNT / ORDER HISTORY PANEL ===== */
      .account-overlay{display:none;position:fixed;inset:0;background:rgba(0,0,0,.65);z-index:850;align-items:flex-start;justify-content:flex-end;}
      .account-overlay.open{display:flex;}
      .account-drawer{background:#fff;width:480px;max-width:100vw;height:100vh;overflow-y:auto;display:flex;flex-direction:column;animation:slideFromRight .3s ease;}
      @keyframes slideFromRight{from{transform:translateX(100%)}to{transform:translateX(0)}}
      .account-drawer__header{background:var(--color-dark);padding:2.2rem 2.4rem;display:flex;align-items:center;justify-content:space-between;}
      .account-drawer__header h2{font-family:'Space Grotesk',sans-serif;font-size:1.8rem;font-weight:700;color:var(--color-accent);}
      .account-close{background:none;border:none;color:rgba(255,255,255,.6);font-size:2.2rem;cursor:pointer;line-height:1;}
      .account-close:hover{color:#fff;}
      .account-user-info{padding:2rem 2.4rem;border-bottom:1px solid rgba(31,31,31,.08);display:flex;align-items:center;gap:1.4rem;}
      .account-avatar{width:5rem;height:5rem;background:var(--color-accent);border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:2rem;font-weight:700;color:var(--color-dark);}
      .account-user-name{font-size:1.5rem;font-weight:700;}
      .account-user-email{font-size:1.2rem;color:rgba(31,31,31,.5);margin-top:.2rem;}
      .account-logout{margin-left:auto;background:none;border:1.5px solid rgba(31,31,31,.2);color:rgba(31,31,31,.6);font-size:1.1rem;font-weight:600;letter-spacing:.08em;text-transform:uppercase;padding:.6rem 1.2rem;cursor:pointer;transition:all .2s;text-decoration:none;display:inline-block;}
      .account-logout:hover{background:var(--color-dark);color:#fff;border-color:var(--color-dark);}
      .account-orders{padding:2rem 2.4rem;flex:1;}
      .account-orders h3{font-size:1.3rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;margin-bottom:1.6rem;color:var(--color-dark);}
      .order-card{border:1.5px solid rgba(31,31,31,.1);padding:1.6rem;margin-bottom:1.4rem;transition:border .2s;}
      .order-card:hover{border-color:var(--color-dark);}
      .order-card__top{display:flex;justify-content:space-between;align-items:flex-start;margin-bottom:1rem;}
      .order-card__id{font-size:1.3rem;font-weight:800;color:var(--color-dark);letter-spacing:.05em;}
      .order-status{font-size:1rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;background:var(--color-accent);color:var(--color-dark);padding:.3rem .8rem;}
      .order-card__date{font-size:1.1rem;color:rgba(31,31,31,.5);margin-bottom:.6rem;}
      .order-card__items{font-size:1.2rem;color:rgba(31,31,31,.65);line-height:1.6;margin-bottom:.6rem;white-space:pre-line;}
      .order-card__footer{display:flex;justify-content:space-between;align-items:center;border-top:1px solid rgba(31,31,31,.08);padding-top:.8rem;margin-top:.8rem;}
      .order-card__total{font-size:1.4rem;font-weight:800;}
      .order-card__pay{font-size:1.1rem;color:rgba(31,31,31,.5);text-transform:uppercase;letter-spacing:.06em;}
      .no-orders{text-align:center;padding:4rem 2rem;color:rgba(31,31,31,.4);}
      .no-orders svg{margin:0 auto 1.5rem;opacity:.3;}
      .no-orders p{font-size:1.4rem;font-weight:500;}

      /* Logged-in state for account icon */
      .acct-icon-loggedin .acct-dot{display:block!important;}
      .acct-dot{display:none;position:absolute;top:-.4rem;right:-.4rem;width:1rem;height:1rem;background:var(--color-accent);border-radius:50%;}
    </style>
    <style>
      :root {
        --zt-bg: #f6f0d9;
        --zt-surface: #fff8e8;
        --zt-paper: #fffdf7;
        --zt-ink: #101010;
        --zt-muted: rgba(16, 16, 16, 0.62);
        --zt-border: #101010;
        --zt-primary: #ffd84d;
        --zt-secondary: #87ea5f;
        --zt-electric: #4d5bff;
        --zt-pink: #ff4785;
        --zt-shadow: 6px 6px 0 #101010;
        --zt-shadow-sm: 4px 4px 0 #101010;
        --zt-radius: 2.6rem;
      }

      body.zt-theme {
        background:
          radial-gradient(circle at top left, rgba(255, 216, 77, 0.28), transparent 26%),
          radial-gradient(circle at 85% 12%, rgba(135, 234, 95, 0.24), transparent 24%),
          linear-gradient(180deg, #fff9e8 0%, #f7f0df 100%);
        color: var(--zt-ink);
      }

      body.zt-theme::before {
        content: "";
        position: fixed;
        inset: 0;
        pointer-events: none;
        background-image:
          linear-gradient(rgba(16,16,16,0.035) 1px, transparent 1px),
          linear-gradient(90deg, rgba(16,16,16,0.035) 1px, transparent 1px);
        background-size: 32px 32px;
        opacity: 0.45;
        z-index: -1;
      }

      body.zt-theme .announcement-bar {
        background: var(--zt-ink);
        color: var(--zt-secondary);
        border-bottom: 2px solid var(--zt-border);
        box-shadow: inset 0 -1px 0 rgba(255,255,255,0.1);
        font-family: 'Space Mono', monospace;
        font-size: 1.15rem;
        letter-spacing: 0.18em;
      }

      body.zt-theme .header-wrapper {
        background: rgba(255, 248, 232, 0.9);
        backdrop-filter: blur(18px);
        border-bottom: 2px solid var(--zt-border);
      }

      body.zt-theme .header-wrapper.scrolled { box-shadow: 0 16px 40px rgba(16,16,16,0.08); }
      body.zt-theme .header { padding: 1.8rem 3.2rem; }
      body.zt-theme .logo-svg text { font-family: 'Syne', sans-serif; font-weight: 800; letter-spacing: -1.5px; }

      body.zt-theme .header__menu-item,
      body.zt-theme .mega-menu__link,
      body.zt-theme .drawer-tab,
      body.zt-theme .menu-drawer__menu-item {
        font-family: 'Space Mono', monospace;
        font-size: 1.18rem;
        letter-spacing: 0.12em;
      }

      body.zt-theme .header__menu-item::after {
        content: "";
        position: absolute;
        left: 0;
        right: 0;
        bottom: -0.4rem;
        height: 3px;
        background: var(--zt-electric);
        transform: scaleX(0);
        transform-origin: left;
        transition: transform .24s ease;
      }

      body.zt-theme .header__menu-item:hover::after,
      body.zt-theme .mega-menu[open] .header__menu-item::after { transform: scaleX(1); }

      body.zt-theme .mega-menu__content,
      body.zt-theme .menu-drawer,
      body.zt-theme .search-modal__form,
      body.zt-theme .contact-box,
      body.zt-theme .auth-box,
      body.zt-theme .cart-drawer,
      body.zt-theme .account-drawer,
      body.zt-theme #trackOrderOverlay > div,
      body.zt-theme #coBox,
      body.zt-theme #orderSuccessBox,
      body.zt-theme #pdModalBox {
        background: var(--zt-paper) !important;
        border: 2px solid var(--zt-border) !important;
        border-radius: var(--zt-radius) !important;
        box-shadow: var(--zt-shadow);
      }

      body.zt-theme .hero-banner,
      body.zt-theme .secondary-banner {
        min-height: calc(100vh - 13rem);
        padding: 3rem;
        gap: 2.4rem;
        background: transparent;
      }

      body.zt-theme .hero-banner__media,
      body.zt-theme .secondary-banner__media {
        border: 2px solid var(--zt-border);
        border-radius: 3.4rem;
        overflow: hidden;
        box-shadow: var(--zt-shadow);
        background:
          radial-gradient(circle at 20% 20%, rgba(255,216,77,0.9), transparent 30%),
          radial-gradient(circle at 80% 20%, rgba(77,91,255,0.75), transparent 24%),
          radial-gradient(circle at 70% 80%, rgba(135,234,95,0.82), transparent 28%),
          linear-gradient(135deg, #111 0%, #2a2a2a 100%);
      }

      body.zt-theme .hero-banner__media::before,
      body.zt-theme .secondary-banner__media::before {
        content: "";
        position: absolute;
        inset: 1.4rem;
        border: 2px dashed rgba(255,255,255,0.28);
        border-radius: 2.6rem;
        z-index: 1;
        pointer-events: none;
      }

      body.zt-theme .hero-banner__media::after { background: linear-gradient(180deg, rgba(0,0,0,0.08), rgba(0,0,0,0.5)); }
      body.zt-theme .hero-banner__content,
      body.zt-theme .secondary-banner__content { align-items: stretch; }

      body.zt-theme .hero-banner__box {
        position: relative;
        max-width: 68rem;
        padding: 3.6rem 3.2rem;
        background: rgba(255, 253, 247, 0.82);
        border: 2px solid var(--zt-border);
        border-radius: 3rem;
        box-shadow: var(--zt-shadow);
        backdrop-filter: blur(12px);
      }

      body.zt-theme .hero-banner__eyebrow {
        display: inline-flex;
        align-items: center;
        gap: 0.8rem;
        margin-bottom: 1.8rem;
        padding: 0.8rem 1.4rem;
        border: 2px solid var(--zt-border);
        border-radius: 999px;
        background: var(--zt-secondary);
        color: var(--zt-ink);
        font-family: 'Space Mono', monospace;
        font-size: 1.15rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      body.zt-theme .hero-banner__heading,
      body.zt-theme .title,
      body.zt-theme .newsletter-section h2,
      body.zt-theme .footer-top-content h2 {
        font-family: 'Syne', sans-serif;
        color: var(--zt-ink);
        line-height: 0.94;
        letter-spacing: -0.05em;
      }

      body.zt-theme .hero-banner__heading { font-size: clamp(4.4rem, 7.6vw, 9rem); max-width: 8ch; }
      body.zt-theme .hero-banner__text,
      body.zt-theme .newsletter-section p,
      body.zt-theme .content-wrap p { color: var(--zt-muted); font-size: 1.6rem; line-height: 1.7; }

      body.zt-theme .hero-banner__buttons {
        display: flex;
        flex-wrap: wrap;
        gap: 1.2rem;
        margin-top: 2.6rem;
      }

      body.zt-theme .btn-primary,
      body.zt-theme .btn-outline,
      body.zt-theme .cta-btn-holder a,
      body.zt-theme .cta-btn-holder span,
      body.zt-theme .newsletter-section button,
      body.zt-theme .cart-checkout-btn,
      body.zt-theme .auth-submit {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        min-height: 5.4rem;
        padding: 1.4rem 2rem;
        border: 2px solid var(--zt-border);
        border-radius: 999px;
        box-shadow: var(--zt-shadow-sm);
        transition: transform .18s ease, box-shadow .18s ease, background .18s ease, color .18s ease;
        font-family: 'Space Mono', monospace;
        font-size: 1.2rem;
        font-weight: 700;
        letter-spacing: 0.1em;
        text-transform: uppercase;
      }

      body.zt-theme .btn-primary,
      body.zt-theme .newsletter-section button,
      body.zt-theme .cart-checkout-btn,
      body.zt-theme .auth-submit { background: var(--zt-primary); color: var(--zt-ink); }

      body.zt-theme .btn-outline,
      body.zt-theme .cta-btn-holder a,
      body.zt-theme .cta-btn-holder span { background: #fff; color: var(--zt-ink); }

      body.zt-theme .btn-primary:hover,
      body.zt-theme .btn-outline:hover,
      body.zt-theme .cta-btn-holder a:hover,
      body.zt-theme .newsletter-section button:hover,
      body.zt-theme .cart-checkout-btn:hover,
      body.zt-theme .auth-submit:hover {
        transform: translate(-2px, -2px);
        box-shadow: 8px 8px 0 #101010;
      }

      body.zt-theme .hero-banner__stats { display: flex; flex-wrap: wrap; gap: 1rem; margin-top: 2rem; }
      body.zt-theme .hero-stat {
        min-width: 12rem;
        padding: 1.2rem 1.4rem;
        background: #fff;
        border: 2px solid var(--zt-border);
        border-radius: 1.8rem;
        box-shadow: var(--zt-shadow-sm);
      }
      body.zt-theme .hero-stat strong { display: block; font-family: 'Syne', sans-serif; font-size: 2rem; line-height: 1; }
      body.zt-theme .hero-stat span {
        display: block;
        margin-top: 0.5rem;
        color: var(--zt-muted);
        font-family: 'Space Mono', monospace;
        font-size: 1.05rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
      }

      body.zt-theme .hero-orbit {
        position: absolute;
        z-index: 2;
        padding: 1rem 1.4rem;
        border: 2px solid rgba(16,16,16,0.8);
        border-radius: 999px;
        background: rgba(255,255,255,0.92);
        box-shadow: var(--zt-shadow-sm);
        color: var(--zt-ink);
        font-family: 'Space Mono', monospace;
        font-size: 1.05rem;
        font-weight: 700;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        animation: ztFloat 6s ease-in-out infinite;
      }

      body.zt-theme .orbit-a { top: 9%; left: 6%; }
      body.zt-theme .orbit-b { right: 7%; top: 16%; animation-delay: 1.4s; }
      body.zt-theme .orbit-c { left: 10%; bottom: 10%; animation-delay: 2.2s; }
      body.zt-theme .featured-collection,
      body.zt-theme .newsletter-section,
      body.zt-theme .footer-top-section,
      body.zt-theme .trust-icon-section,
      body.zt-theme .footer { position: relative; z-index: 2; }

      body.zt-theme .featured-collection { padding: 4rem 0 2rem; }
      body.zt-theme .collection__title {
        max-width: 1600px;
        margin: 0 auto 2.2rem;
        padding: 0 4rem;
        gap: 1.6rem;
        align-items: center;
      }

      body.zt-theme .title { font-size: clamp(3.8rem, 5.2vw, 6.6rem); }
      body.zt-theme .product-grid { gap: 2.2rem; padding: 0 4rem 1rem; }
      body.zt-theme .card-wrapper,
      body.zt-theme .grid__item { height: 100%; }

      body.zt-theme .card {
        height: 100%;
        border: 2px solid var(--zt-border);
        border-radius: 3rem;
        overflow: hidden;
        background: var(--zt-paper);
        box-shadow: var(--zt-shadow);
        transition: transform .22s ease, box-shadow .22s ease;
      }

      body.zt-theme .card-wrapper:hover .card {
        transform: translate(-4px, -4px) rotate(-0.8deg);
        box-shadow: 10px 10px 0 #101010;
      }

      body.zt-theme .card__inner { height: 100%; }
      body.zt-theme .card__media {
        overflow: hidden;
        border-bottom: 2px solid var(--zt-border);
        background: linear-gradient(140deg, rgba(255,216,77,0.28), rgba(135,234,95,0.22)), #efead7;
      }

      body.zt-theme .card__media img { transition: transform .45s ease, filter .35s ease; }
      body.zt-theme .card-wrapper:hover .card__media img { transform: scale(1.06); filter: saturate(1.08) contrast(1.02); }

      body.zt-theme .custom-badge {
        top: 1.4rem;
        left: 1.4rem;
        padding: 0.8rem 1.1rem;
        border: 2px solid var(--zt-border);
        border-radius: 999px;
        background: var(--zt-primary);
        color: var(--zt-ink);
        font-family: 'Space Mono', monospace;
        font-size: 1rem;
        letter-spacing: 0.1em;
        box-shadow: 3px 3px 0 #101010;
      }

      body.zt-theme .badge-sale { background: var(--zt-pink); color: #fff; }

      body.zt-theme .quick-add-overlay {
        right: 1.4rem;
        bottom: 1.4rem;
        left: auto;
        width: auto;
        padding: 1rem 1.4rem;
        background: var(--zt-electric);
        color: #fff;
        border: 2px solid var(--zt-border);
        border-radius: 999px;
        box-shadow: 3px 3px 0 #101010;
        font-family: 'Space Mono', monospace;
        font-size: 1rem;
        letter-spacing: 0.08em;
        text-transform: uppercase;
        transform: translateY(1rem);
        opacity: 0;
      }

      body.zt-theme .card-wrapper:hover .quick-add-overlay { transform: translateY(0); opacity: 1; }
      body.zt-theme .card__information {
        padding: 2rem 1.8rem 2.2rem;
        background: linear-gradient(180deg, rgba(255,255,255,0.85), rgba(255,248,232,0.96));
      }

      body.zt-theme .card__heading { font-family: 'Syne', sans-serif; font-size: 2rem; line-height: 1; letter-spacing: -0.04em; }
      body.zt-theme .card__heading span { color: var(--zt-electric); }
      body.zt-theme .price-item--regular { font-family: 'Space Mono', monospace; font-size: 1.45rem; font-weight: 700; color: var(--zt-ink); }
      body.zt-theme .mrp-price,
      body.zt-theme .inc-tax { color: var(--zt-muted); font-family: 'Space Mono', monospace; letter-spacing: 0.05em; }

      body.zt-theme .newsletter-section { padding: 8rem 2rem 4rem; }
      body.zt-theme .newsletter-section__inner {
        max-width: 1120px;
        background: linear-gradient(135deg, #111 0%, #1b1b1b 100%);
        border: 2px solid var(--zt-border);
        border-radius: 3.4rem;
        box-shadow: var(--zt-shadow);
        padding: 4.2rem 3rem;
      }

      body.zt-theme .newsletter-section h2,
      body.zt-theme .newsletter-section p { color: #fff8e8; }

      body.zt-theme .email-form input {
        background: #fff;
        border: 2px solid var(--zt-border);
        border-radius: 999px;
        min-height: 5.4rem;
        padding: 1.4rem 1.8rem;
        box-shadow: var(--zt-shadow-sm);
        font-family: 'Space Mono', monospace;
      }

      body.zt-theme .icons-area .icon-item,
      body.zt-theme .icons-text-part {
        background: var(--zt-paper);
        border: 2px solid var(--zt-border);
        border-radius: 2.2rem;
        box-shadow: var(--zt-shadow-sm);
      }

      body.zt-theme .icons-text-part { padding: 1.8rem; }
      body.zt-theme .footer { background: #111; border-top: 2px solid var(--zt-border); margin-top: 4rem; }

      body.zt-theme .footer-block__heading,
      body.zt-theme .footer a,
      body.zt-theme .footer p,
      body.zt-theme .footer small { color: #fff8e8; }

      body.zt-theme .footer-block__heading { font-family: 'Space Mono', monospace; letter-spacing: 0.1em; }
      body.zt-theme .footer .link-underline {
        background-image: linear-gradient(currentColor, currentColor);
        background-repeat: no-repeat;
        background-size: 0 2px;
        background-position: 0 100%;
        transition: background-size .2s ease;
      }

      body.zt-theme .footer a:hover .link-underline { background-size: 100% 2px; }
      body.zt-theme .zt-reveal { opacity: 0; transform: translateY(28px) rotate(0.001deg); transition: opacity .65s ease, transform .65s cubic-bezier(.2,.8,.2,1); }
      body.zt-theme .zt-reveal.is-visible { opacity: 1; transform: translateY(0) rotate(0.001deg); }

      @keyframes ztFloat {
        0%, 100% { transform: translateY(0); }
        50% { transform: translateY(-10px); }
      }

      @media (max-width: 989px) {
        body.zt-theme .header { padding: 1.4rem 2rem; }
        body.zt-theme .hero-banner,
        body.zt-theme .secondary-banner { padding: 2rem; }
        body.zt-theme .hero-banner__box { padding: 2.4rem 2rem; }
        body.zt-theme .hero-banner__heading { font-size: clamp(3.6rem, 13vw, 6rem); }
        body.zt-theme .collection__title,
        body.zt-theme .product-grid { padding-left: 2rem; padding-right: 2rem; }
        body.zt-theme .orbit-a,
        body.zt-theme .orbit-b,
        body.zt-theme .orbit-c { display: none; }
      }

      @media (max-width: 749px) {
        body.zt-theme .hero-banner,
        body.zt-theme .secondary-banner { min-height: auto; padding: 1.4rem; }
        body.zt-theme .hero-banner__media,
        body.zt-theme .secondary-banner__media,
        body.zt-theme .hero-banner__box,
        body.zt-theme .card,
        body.zt-theme .newsletter-section__inner { border-radius: 2.2rem; }
        body.zt-theme .hero-banner__buttons,
        body.zt-theme .hero-banner__stats { flex-direction: column; }
        body.zt-theme .btn-primary,
        body.zt-theme .btn-outline,
        body.zt-theme .newsletter-section button,
        body.zt-theme .cart-checkout-btn,
        body.zt-theme .auth-submit { width: 100%; }
      }
    </style>
    <!-- EmailJS -->
    <script src="https://cdn.jsdelivr.net/npm/@emailjs/browser@4/dist/email.min.js"></script>
    <script>
      emailjs.init('qZnOhCsMYITZNT4JE');
    </script>
  </head>
  <body class="zt-theme">

  <!-- ===== ANNOUNCEMENT BAR ===== -->
  <div class="announcement-bar" role="region" aria-label="Announcement">
    <div class="announcement-track">
      <span>FREE SHIPPING ON ORDERS ABOVE ₹999 </span>
      <span>NEW ARRIVALS: UPPER WEAR DROPS EVERY FRIDAY </span>
      <span>CASH ON DELIVERY AVAILABLE </span>
      <span>7-DAY FREE RETURNS & EXCHANGE </span>
      <span>FREE SHIPPING ON ORDERS ABOVE ₹999 </span>
      <span>NEW ARRIVALS: UPPER WEAR DROPS EVERY FRIDAY </span>
      <span>CASH ON DELIVERY AVAILABLE </span>
      <span>7-DAY FREE RETURNS & EXCHANGE </span>
    </div>
  </div>

  <!-- ===== HEADER ===== -->
  <div class="header-wrapper" id="header-wrapper">
    <header class="header">

      <!-- Mobile hamburger -->
      <button class="header__icon header__icon--menu" id="drawer-open-btn" aria-label="Open menu" style="display:none;">
        <svg width="24" height="18" viewBox="0 0 24 18" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect width="24" height="2" fill="#1f1f1f"/>
          <rect y="8" width="24" height="2" fill="#1f1f1f"/>
          <rect y="16" width="24" height="2" fill="#1f1f1f"/>
        </svg>
      </button>

      <!-- Logo -->
      <h1 class="header__heading">
        <a href="/" class="header__heading-link">
          <svg class="logo-svg" viewBox="0 0 300 40" fill="none" xmlns="http://www.w3.org/2000/svg">
            <text x="0" y="34" font-family="Space Grotesk, sans-serif" font-size="32" font-weight="700" fill="#1f1f1f" letter-spacing="-1">HELVETICA</text>
          </svg>
        </a>
      </h1>

      <!-- Desktop Nav -->
      <nav class="header__inline-menu">
        <ul class="list-menu">

          <!-- Upper Wear Mega Menu -->
          <li>
            <details class="mega-menu" id="mega1">
              <summary class="header__menu-item">
                <span>Upper Wear</span>
                <svg aria-hidden="true" focusable="false" class="icon icon-caret" viewBox="0 0 10 6">
                  <path fill-rule="evenodd" clip-rule="evenodd" d="M9.354.646a.5.5 0 00-.708 0L5 4.293 1.354.646a.5.5 0 00-.708.708l4 4a.5.5 0 00.708 0l4-4a.5.5 0 000-.708z" fill="currentColor"/>
                </svg>
              </summary>
              <div class="mega-menu__content" style="min-width:220px;">
                <ul class="mega-menu__list" style="padding:0.5rem 0;">
                  <li><a href="?page=upper-wear" class="mega-menu__link">New In</a></li>
                  <li><a href="?page=tshirts" class="mega-menu__link">T-Shirts</a></li>
                  <li><a href="?page=hoodies" class="mega-menu__link">Hoodies</a></li>
                  <li><a href="?page=jackets" class="mega-menu__link">Jackets</a></li>
                  <li><a href="?page=shirts" class="mega-menu__link">Shirts</a></li>
                </ul>
              </div>
            </details>
          </li>

          <!-- Lower Wear Mega Menu -->
          <li>
            <details class="mega-menu" id="mega2">
              <summary class="header__menu-item">
                <span>Lower Wear</span>
                <svg aria-hidden="true" focusable="false" class="icon icon-caret" viewBox="0 0 10 6">
                  <path fill-rule="evenodd" clip-rule="evenodd" d="M9.354.646a.5.5 0 00-.708 0L5 4.293 1.354.646a.5.5 0 00-.708.708l4 4a.5.5 0 00.708 0l4-4a.5.5 0 000-.708z" fill="currentColor"/>
                </svg>
              </summary>
              <div class="mega-menu__content" style="min-width:220px;">
                <ul class="mega-menu__list" style="padding:0.5rem 0;">
                  <li><a href="?page=lower-wear" class="mega-menu__link">New In</a></li>
                  <li><a href="?page=jeans" class="mega-menu__link">Jeans</a></li>
                  <li><a href="?page=shorts" class="mega-menu__link">Shorts</a></li>
                  <li><a href="?page=trousers" class="mega-menu__link">Trousers</a></li>
                  <li><a href="?page=joggers" class="mega-menu__link">Joggers</a></li>
                </ul>
              </div>
            </details>
          </li>

          <!-- Accessories -->
          <li>
            <details class="mega-menu" id="mega3">
              <summary class="header__menu-item">
                <span>Accessories</span>
                <svg aria-hidden="true" focusable="false" class="icon icon-caret" viewBox="0 0 10 6">
                  <path fill-rule="evenodd" clip-rule="evenodd" d="M9.354.646a.5.5 0 00-.708 0L5 4.293 1.354.646a.5.5 0 00-.708.708l4 4a.5.5 0 00.708 0l4-4a.5.5 0 000-.708z" fill="currentColor"/>
                </svg>
              </summary>
              <div class="mega-menu__content" style="min-width:220px;">
                <ul class="mega-menu__list" style="padding:0.5rem 0;">
                  <li><a href="?page=accessories" class="mega-menu__link">New In</a></li>
                  <li><a href="?page=caps" class="mega-menu__link">Caps & Hats</a></li>
                  <li><a href="?page=shoes" class="mega-menu__link">Shoes</a></li>
                  <li><a href="?page=bags" class="mega-menu__link">Bags</a></li>
                  <li><a href="?page=watches" class="mega-menu__link">Watches</a></li>
                  <li><a href="?page=jewelry" class="mega-menu__link">Jewelry</a></li>
                </ul>
              </div>
            </details>
          </li>

          <li>
            <a href="#about" class="header__menu-item"><span>About Us</span></a>
          </li>
          <li>
            <a href="#contact-us" class="header__menu-item" onclick="openContactUs();return false;"><span>Contact Us</span></a>
          </li>

        </ul>
      </nav>

      <!-- Icons -->
      <div class="header__icons">
        <!-- Search -->
        <button class="header__icon header__icon--search" id="search-btn" aria-label="Search">
          <svg class="icon icon-search" aria-hidden="true" focusable="false" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5" stroke-linecap="round" stroke-linejoin="round">
            <circle cx="10.5" cy="10.5" r="6.5"/>
            <line x1="15.5" y1="15.5" x2="22" y2="22"/>
          </svg>
        </button>

        <!-- Account -->
        <button class="header__icon" aria-label="Account">
          <svg width="22" height="24" viewBox="0 0 31 34" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M7.73438 8.69099C7.73438 12.9231 11.1929 16.382 15.4254 16.382C19.6575 16.382 23.1163 12.9234 23.1163 8.69099C23.1163 4.45887 19.6578 1 15.4254 1C11.1933 1.00032 7.73438 4.41345 7.73438 8.69099ZM21.2959 8.69099C21.2959 11.9222 18.6564 14.5614 15.4254 14.5614C12.1942 14.5614 9.55503 11.9219 9.55503 8.69099C9.55503 5.45973 12.1945 2.82058 15.4254 2.82058C18.6567 2.82058 21.2959 5.46006 21.2959 8.69099Z" fill="black" stroke="black" stroke-width="0.5"/>
            <path d="M1.91025 32.7645H28.9877C29.4883 32.7645 29.8978 32.355 29.8978 31.8545C29.8978 23.8905 23.4356 17.3828 15.4261 17.3828C7.41675 17.3825 1 23.8903 1 31.8545C1 32.3551 1.36411 32.7645 1.91008 32.7645H1.91025ZM15.4262 19.2486C22.0706 19.2486 27.5769 24.4365 28.032 30.9897H2.82041C3.27563 24.4364 8.78192 19.2486 15.4262 19.2486Z" fill="black" stroke="black" stroke-width="0.5"/>
          </svg>
        </button>

        <!-- Cart -->
        <a href="#" class="header__icon" id="cart-icon-bubble" aria-label="Cart">
          <svg width="36" height="30" viewBox="0 0 73 41" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M71.3461 0H1.34615C0.601569 0 0 0.706804 0 1.57692V11.0385C0 11.9086 0.601569 12.6154 1.34615 12.6154H2.69231V39.4231C2.69231 40.2932 3.29388 41 4.03846 41H68.6538C69.3984 41 70 40.2932 70 39.4231V12.6154H71.3461C72.0907 12.6154 72.6923 11.9086 72.6923 11.0385V1.57692C72.6923 0.706804 72.0907 0 71.3461 0ZM67.3077 37.8462H5.38462V12.6154H67.3077V37.8462ZM70 9.46154H2.69231V3.15385H70V9.46154Z" fill="#1F1F1F"/>
            <path d="M46.2002 32.1016H64.4002" stroke="#1F1F1F" stroke-width="4" stroke-linejoin="round"/>
          </svg>
          <div class="cart-count-bubble"><span>0</span></div>
        </a>
      </div>
    </header>
  </div>

  <!-- ===== MOBILE DRAWER ===== -->
  <div class="menu-drawer-overlay" id="drawer-overlay"></div>
  <div class="menu-drawer" id="menu-drawer">
    <div class="menu-drawer__header">
      <!-- Logo in drawer -->
      <svg viewBox="0 0 180 30" fill="none" style="height:2.4rem;">
        <text x="0" y="25" font-family="Space Grotesk, sans-serif" font-size="26" font-weight="700" fill="#1f1f1f">HELVETICA</text>
      </svg>
      <button id="drawer-close-btn" aria-label="Close menu">
        <svg class="menu-drawer__close" viewBox="0 0 24 24" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M18 6L6 18M6 6L18 18" stroke="#1f1f1f" stroke-width="2" stroke-linecap="round"/>
        </svg>
      </button>
    </div>

    <nav class="menu-drawer__nav">
      <div class="menu-drawer__tabs">
        <div class="drawer-tab active" data-tab="men">Upper Wear</div>
        <div class="drawer-tab" data-tab="women">Lower Wear</div>
        <div class="drawer-tab" data-tab="about">Accessories</div>
      </div>

      <div class="drawer-tab-content active" id="tab-men">
        <div class="menu-drawer__menu">
          <div>
            <button class="drawer-accordion__btn" onclick="toggleAccordion(this)">
              T-Shirts
              <svg class="plus accord_svg" width="20" height="20" viewBox="0 0 32 32"><path d="M16 0V32" stroke="black" stroke-width="2"/><path d="M32 16L0 16" stroke="black" stroke-width="2"/></svg>
              <svg class="minus accord_svg" width="20" height="2" viewBox="0 0 32 2" style="display:none;"><path d="M32 1L0 0.999999" stroke="black" stroke-width="2"/></svg>
            </button>
            <div class="drawer-submenu">
              <a href="?page=tshirts">Oversized Tees</a>
              <a href="?page=tshirts">Graphic Tees</a>
              <a href="?page=tshirts">Plain Tees</a>
            </div>
          </div>
          <a href="?page=hoodies" class="menu-drawer__menu-item">Hoodies</a>
          <a href="?page=jackets" class="menu-drawer__menu-item">Jackets</a>
          <a href="?page=shirts" class="menu-drawer__menu-item">Shirts</a>
          <a href="?page=upper-wear" class="menu-drawer__menu-item">New Arrivals</a>
        </div>
      </div>

      <div class="drawer-tab-content" id="tab-women">
        <div class="menu-drawer__menu">
          <a href="?page=jeans" class="menu-drawer__menu-item">Jeans</a>
          <a href="?page=shorts" class="menu-drawer__menu-item">Shorts</a>
          <a href="?page=trousers" class="menu-drawer__menu-item">Trousers</a>
          <a href="?page=joggers" class="menu-drawer__menu-item">Joggers</a>
          <a href="?page=lower-wear" class="menu-drawer__menu-item">New Arrivals</a>
        </div>
      </div>

      <div class="drawer-tab-content" id="tab-about">
        <div class="menu-drawer__menu">
          <a href="?page=caps" class="menu-drawer__menu-item">Caps & Hats</a>
          <a href="?page=shoes" class="menu-drawer__menu-item">Shoes</a>
          <a href="?page=bags" class="menu-drawer__menu-item">Bags</a>
          <a href="?page=watches" class="menu-drawer__menu-item">Watches</a>
          <a href="?page=jewelry" class="menu-drawer__menu-item">Jewelry</a>
          <a href="#about" class="menu-drawer__menu-item">About Us</a>
          <a href="#" onclick="openTrackOrder();return false;" class="menu-drawer__menu-item">Track Order</a>
          <a href="#" onclick="openContactUs();return false;" class="menu-drawer__menu-item">Contact Us</a>
        </div>
      </div>
    </nav>

    <div class="menu-drawer__social">
      <a href="https://www.instagram.com/" class="list-social__link" style="color:var(--color-dark);">
        <svg aria-hidden="true" focusable="false" class="icon icon-instagram" viewBox="0 0 18 18" style="width:2.2rem;height:2.2rem;">
          <path fill="currentColor" d="M8.77 1.58c2.34 0 2.62.01 3.54.05.86.04 1.32.18 1.63.3.41.17.7.35 1.01.66.3.3.5.6.65 1 .12.32.27.78.3 1.64.05.92.06 1.2.06 3.54s-.01 2.62-.05 3.54a4.79 4.79 0 01-.3 1.63c-.17.41-.35.7-.66 1.01-.3.3-.6.5-1.01.66-.31.12-.77.26-1.63.3-.92.04-1.2.05-3.54.05s-2.62 0-3.55-.05a4.79 4.79 0 01-1.62-.3c-.42-.16-.7-.35-1.01-.66-.31-.3-.5-.6-.66-1a4.87 4.87 0 01-.3-1.64c-.04-.92-.05-1.2-.05-3.54s0-2.62.05-3.54c.04-.86.18-1.32.3-1.63.16-.41.35-.7.66-1.01.3-.3.6-.5 1-.65.32-.12.78-.27 1.63-.3.93-.05 1.2-.06 3.55-.06zm0-1.58C6.39 0 6.09.01 5.15.05c-.93.04-1.57.2-2.13.4-.57.23-1.06.54-1.55 1.02C1 1.96.7 2.45.46 3.02c-.22.56-.37 1.2-.4 2.13C0 6.1 0 6.4 0 8.77s.01 2.68.05 3.61c.04.94.2 1.57.4 2.13.23.58.54 1.07 1.02 1.56.49.48.98.78 1.55 1.01.56.22 1.2.37 2.13.4.94.05 1.24.06 3.62.06 2.39 0 2.68-.01 3.62-.05.93-.04 1.57-.2 2.13-.41a4.27 4.27 0 001.55-1.01c.49-.49.79-.98 1.01-1.56.22-.55.37-1.19.41-2.13.04-.93.05-1.23.05-3.61 0-2.39 0-2.68-.05-3.62a6.47 6.47 0 00-.4-2.13 4.27 4.27 0 00-1.02-1.55A4.35 4.35 0 0014.52.46a6.43 6.43 0 00-2.13-.41A69 69 0 008.77 0z"/>
          <path fill="currentColor" d="M8.8 4a4.5 4.5 0 100 9 4.5 4.5 0 000-9zm0 7.43a2.92 2.92 0 110-5.85 2.92 2.92 0 010 5.85zM13.43 5a1.05 1.05 0 100-2.1 1.05 1.05 0 000 2.1z"/>
        </svg>
      </a>
    </div>
  </div>

  <!-- ===== SEARCH MODAL ===== -->
  <div class="search-modal" id="search-modal">
    <div class="search-modal__form">
      <div style="display:flex;align-items:center;gap:1rem;">
        <input type="search" class="search__input" placeholder="Search products, categories..." id="search-input" autocomplete="off">
        <button onclick="document.getElementById('search-modal').classList.remove('active');" style="font-size:2rem;color:#666;background:none;border:none;cursor:pointer;">×</button>
      </div>
      <div id="search-results" style="margin-top:1.5rem;max-height:60vh;overflow-y:auto;"></div>
    </div>
  </div>

  <!-- ===== TRACK ORDER MODAL ===== -->
  <div id="trackOrderOverlay" style="display:none;position:fixed;inset:0;background:rgba(0,0,0,0.6);z-index:900;align-items:center;justify-content:center;">
    <div style="background:#fff;max-width:520px;width:90%;padding:3.2rem;position:relative;max-height:90vh;overflow-y:auto;">
      <button onclick="closeTrackOrder()" style="position:absolute;top:1.5rem;right:1.5rem;font-size:2.4rem;line-height:1;color:#999;background:none;border:none;cursor:pointer;">×</button>
      <h2 style="font-family:'Space Grotesk',sans-serif;font-size:2.2rem;font-weight:800;margin-bottom:.6rem;">Track Your Order</h2>
      <p style="color:rgba(31,31,31,.5);font-size:1.3rem;margin-bottom:2.4rem;">Enter your Order ID from your confirmation email.</p>
      <div style="display:flex;gap:1rem;">
        <input type="text" id="trackInput" placeholder="e.g. HV-ABC12345" style="flex:1;border:2px solid #e0e0e0;padding:1.2rem 1.4rem;font-size:1.4rem;font-family:inherit;outline:none;transition:border .2s;" onfocus="this.style.borderColor='#0c1a2b'" onblur="this.style.borderColor='#e0e0e0'">
        <button onclick="doTrackOrder()" style="background:#0c1a2b;color:#b6ff3b;padding:1.2rem 2rem;font-size:1.3rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;border:none;cursor:pointer;white-space:nowrap;">Track</button>
      </div>
      <p id="trackErrorMsg" style="color:#e00;font-size:1.3rem;margin-top:1rem;display:none;">Please enter your Order ID.</p>
      <div id="trackResult" style="display:none;margin-top:2.4rem;border:1.5px solid #eee;padding:2rem;"></div>
    </div>
  </div>

  <?php
  // ===== PRODUCT CATALOGUE (used for category pages) =====
  $ALL_PRODUCTS = [
    // T-SHIRTS (tshirts)
    ['id'=>'ts1','name'=>'Essential Oversized Tee','sub'=>'tshirts','category'=>'T-Shirt','price'=>1299,'img'=>'/wp-content/themes/hell/media/imgi_349_f0aff27fb1be61447516bd12346fae11ce010501.jpg','badge'=>'NEW'],
    ['id'=>'ts2','name'=>'Classic Drop Tee','sub'=>'tshirts','category'=>'T-Shirt','price'=>1199,'img'=>'/wp-content/themes/hell/media/imgi_586_46888f878d5547c2f8c59de05ec960e213a1b52b.jpg','badge'=>''],
    ['id'=>'ts3','name'=>'Graphic Arch Tee','sub'=>'tshirts','category'=>'T-Shirt','price'=>1399,'img'=>'/wp-content/themes/hell/media/tshirt3.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/tshirt3.jpg
    ['id'=>'ts4','name'=>'Washed Black Tee','sub'=>'tshirts','category'=>'T-Shirt','price'=>1249,'img'=>'/wp-content/themes/hell/media/tshirt4.jpg','badge'=>'HOT'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/tshirt4.jpg
    ['id'=>'ts5','name'=>'Minimal Logo Tee','sub'=>'tshirts','category'=>'T-Shirt','price'=>1099,'img'=>'/wp-content/themes/hell/media/tshirt5.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/tshirt5.jpg
    // HOODIES
    ['id'=>'hd1','name'=>'Arch Logo Hoodie','sub'=>'hoodies','category'=>'Hoodie','price'=>2199,'img'=>'/wp-content/themes/hell/media/imgi_330_4a6ea369437d2ae71b13159a4dfe25022bebf2fd.jpg','badge'=>''],
    ['id'=>'hd2','name'=>'Helvetica Pullover Hoodie','sub'=>'hoodies','category'=>'Hoodie','price'=>2399,'img'=>'/wp-content/themes/hell/media/hoodie2.jpg','badge'=>'NEW'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/hoodie2.jpg
    ['id'=>'hd3','name'=>'Oversized Zip Hoodie','sub'=>'hoodies','category'=>'Hoodie','price'=>2699,'img'=>'/wp-content/themes/hell/media/hoodie3.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/hoodie3.jpg
    ['id'=>'hd4','name'=>'Washed Fleece Hoodie','sub'=>'hoodies','category'=>'Hoodie','price'=>2099,'img'=>'/wp-content/themes/hell/media/hoodie4.jpg','badge'=>'SALE'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/hoodie4.jpg
    ['id'=>'hd5','name'=>'Drop Shoulder Hoodie','sub'=>'hoodies','category'=>'Hoodie','price'=>2299,'img'=>'/wp-content/themes/hell/media/hoodie5.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/hoodie5.jpg
    // JACKETS
    ['id'=>'jk1','name'=>'Noir Bomber Jacket','sub'=>'jackets','category'=>'Jacket','price'=>3499,'img'=>'/wp-content/themes/hell/media/imgi_712_e2a4c45def96b2bbe5b1a730c919ac5d00fe8ae0.jpg','badge'=>'SALE'],
    ['id'=>'jk2','name'=>'Windbreaker Jacket','sub'=>'jackets','category'=>'Jacket','price'=>3299,'img'=>'/wp-content/themes/hell/media/jacket2.jpg','badge'=>'NEW'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/jacket2.jpg
    ['id'=>'jk3','name'=>'Tactical Shell Jacket','sub'=>'jackets','category'=>'Jacket','price'=>3899,'img'=>'/wp-content/themes/hell/media/jacket3.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/jacket3.jpg
    ['id'=>'jk4','name'=>'Fleece Overshirt','sub'=>'jackets','category'=>'Jacket','price'=>2799,'img'=>'/wp-content/themes/hell/media/jacket4.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/jacket4.jpg
    ['id'=>'jk5','name'=>'Denim Chore Coat','sub'=>'jackets','category'=>'Jacket','price'=>3199,'img'=>'/wp-content/themes/hell/media/jacket5.jpg','badge'=>'HOT'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/jacket5.jpg
    ['id'=>'jk6','name'=>'VR Utility Jacket','sub'=>'jackets','category'=>'Jacket','price'=>3599,'img'=>'/wp-content/themes/hell/media/jac.jpg','badge'=>'NEW','tryon'=>'https://wanna-clothes.ar.wanna.fashion/?mode=vto&showonboarding=3d&modelid=WNCLO01&startwithid=WNCLO01'],
    // SHIRTS
    ['id'=>'sh1','name'=>'Helvetica Oxford Shirt','sub'=>'shirts','category'=>'Shirt','price'=>1799,'img'=>'/wp-content/themes/hell/media/imgi_626_3f5c4f6198ab99addeb933613ab5261a650fff95.jpg','badge'=>'HOT'],
    ['id'=>'sh2','name'=>'Relaxed Linen Shirt','sub'=>'shirts','category'=>'Shirt','price'=>1999,'img'=>'/wp-content/themes/hell/media/shirt2.jpg','badge'=>'NEW'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/shirt2.jpg
    ['id'=>'sh3','name'=>'Cuban Collar Shirt','sub'=>'shirts','category'=>'Shirt','price'=>1699,'img'=>'/wp-content/themes/hell/media/shirt3.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/shirt3.jpg
    ['id'=>'sh4','name'=>'Graphic Print Shirt','sub'=>'shirts','category'=>'Shirt','price'=>1899,'img'=>'/wp-content/themes/hell/media/shirt4.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/shirt4.jpg
    ['id'=>'sh5','name'=>'Minimal Logo Shirt','sub'=>'shirts','category'=>'Shirt','price'=>1599,'img'=>'/wp-content/themes/hell/media/shirt5.jpg','badge'=>'SALE'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/shirt5.jpg
    // JEANS
    ['id'=>'jn1','name'=>'Indigo Slim Jeans','sub'=>'jeans','category'=>'Jeans','price'=>2199,'img'=>'/wp-content/themes/hell/media/bottom-wear3.jpg','badge'=>'NEW'],
    ['id'=>'jn2','name'=>'Black Skinny Jeans','sub'=>'jeans','category'=>'Jeans','price'=>2099,'img'=>'/wp-content/themes/hell/media/jeans2.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/jeans2.jpg
    ['id'=>'jn3','name'=>'Distressed Straight Jeans','sub'=>'jeans','category'=>'Jeans','price'=>2399,'img'=>'/wp-content/themes/hell/media/jeans3.jpg','badge'=>'HOT'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/jeans3.jpg
    ['id'=>'jn4','name'=>'Light Wash Baggy Jeans','sub'=>'jeans','category'=>'Jeans','price'=>2299,'img'=>'/wp-content/themes/hell/media/jeans4.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/jeans4.jpg
    ['id'=>'jn5','name'=>'Relaxed Fit Jeans','sub'=>'jeans','category'=>'Jeans','price'=>2149,'img'=>'/wp-content/themes/hell/media/jeans5.jpg','badge'=>'SALE'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/jeans5.jpg
    // SHORTS
    ['id'=>'st1','name'=>'Cargo Shorts','sub'=>'shorts','category'=>'Shorts','price'=>1499,'img'=>'/wp-content/themes/hell/media/bottom-wear5.jpg','badge'=>''],
    ['id'=>'st2','name'=>'Athletic Shorts','sub'=>'shorts','category'=>'Shorts','price'=>1299,'img'=>'/wp-content/themes/hell/media/shorts2.jpg','badge'=>'NEW'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/shorts2.jpg
    ['id'=>'st3','name'=>'Bermuda Shorts','sub'=>'shorts','category'=>'Shorts','price'=>1399,'img'=>'/wp-content/themes/hell/media/shorts3.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/shorts3.jpg
    ['id'=>'st4','name'=>'Denim Shorts','sub'=>'shorts','category'=>'Shorts','price'=>1599,'img'=>'/wp-content/themes/hell/media/shorts4.jpg','badge'=>'HOT'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/shorts4.jpg
    ['id'=>'st5','name'=>'Linen Shorts','sub'=>'shorts','category'=>'Shorts','price'=>1249,'img'=>'/wp-content/themes/hell/media/shorts5.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/shorts5.jpg
    // TROUSERS
    ['id'=>'tr1','name'=>'Tech Trousers','sub'=>'trousers','category'=>'Trousers','price'=>1899,'img'=>'/wp-content/themes/hell/media/bottom-wear1.jpg','badge'=>'SALE'],
    ['id'=>'tr2','name'=>'Wide Leg Pants','sub'=>'trousers','category'=>'Trousers','price'=>2299,'img'=>'/wp-content/themes/hell/media/bottom-wear4.jpg','badge'=>'TRENDING'],
    ['id'=>'tr3','name'=>'Chino Trousers','sub'=>'trousers','category'=>'Trousers','price'=>1999,'img'=>'/wp-content/themes/hell/media/trousers3.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/trousers3.jpg
    ['id'=>'tr4','name'=>'Pleated Trousers','sub'=>'trousers','category'=>'Trousers','price'=>2199,'img'=>'/wp-content/themes/hell/media/trousers4.jpg','badge'=>'NEW'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/trousers4.jpg
    ['id'=>'tr5','name'=>'Linen Trousers','sub'=>'trousers','category'=>'Trousers','price'=>1799,'img'=>'/wp-content/themes/hell/media/trousers5.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/trousers5.jpg
    // JOGGERS
    ['id'=>'jg1','name'=>'Flex Joggers','sub'=>'joggers','category'=>'Joggers','price'=>1699,'img'=>'/wp-content/themes/hell/media/bottom-wear2.jpg','badge'=>''],
    ['id'=>'jg2','name'=>'Tech Joggers','sub'=>'joggers','category'=>'Joggers','price'=>1899,'img'=>'/wp-content/themes/hell/media/joggers2.jpg','badge'=>'NEW'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/joggers2.jpg
    ['id'=>'jg3','name'=>'Slim Joggers','sub'=>'joggers','category'=>'Joggers','price'=>1599,'img'=>'/wp-content/themes/hell/media/joggers3.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/joggers3.jpg
    ['id'=>'jg4','name'=>'Washed Sweatpants','sub'=>'joggers','category'=>'Joggers','price'=>1799,'img'=>'/wp-content/themes/hell/media/joggers4.jpg','badge'=>'HOT'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/joggers4.jpg
    ['id'=>'jg5','name'=>'Cargo Joggers','sub'=>'joggers','category'=>'Joggers','price'=>1999,'img'=>'/wp-content/themes/hell/media/joggers5.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/joggers5.jpg
    // CAPS
    ['id'=>'cp1','name'=>'H-Logo Cap','sub'=>'caps','category'=>'Cap','price'=>799,'img'=>'/wp-content/themes/hell/media/ImgHunt_Urbanmonkey_20260302_evolve-24lst213-blk-808114.jpeg','badge'=>'NEW','tryon'=>'https://www.kivicube.com/face-scenes/CYNIBj0g0vfcvtWxe0iN7YrUbZds9l7s'],
    ['id'=>'cp2','name'=>'Helvetica Dad Hat','sub'=>'caps','category'=>'Cap','price'=>699,'img'=>'/wp-content/themes/hell/media/cap2.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/cap2.jpg
    ['id'=>'cp3','name'=>'Arch Snapback','sub'=>'caps','category'=>'Cap','price'=>1899,'img'=>'/wp-content/themes/hell/media/cap3.jpg','badge'=>'HOT'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/cap3.jpg
    ['id'=>'cp4','name'=>'Bucket Hat','sub'=>'caps','category'=>'Cap','price'=>749,'img'=>'/wp-content/themes/hell/media/cap4.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/cap4.jpg
    ['id'=>'cp5','name'=>'5-Panel Camp Cap','sub'=>'caps','category'=>'Cap','price'=>649,'img'=>'/wp-content/themes/hell/media/cap5.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/cap5.jpg
    // WATCHES
    ['id'=>'wt1','name'=>'Street Watch','sub'=>'watches','category'=>'Watch','price'=>3299,'img'=>'/wp-content/themes/hell/media/ImgHunt_Rolex_20260302_professional-watches-cosmograph-daytona-myth-push-watch-first_cosmograph_daytona_1963.jpeg','badge'=>'','tryon'=>'https://www.shopar.ai/collection/watches?product=66618cf59d3fb1edda45d3ba&mode=ar'],
    ['id'=>'wt2','name'=>'Minimal Leather Watch','sub'=>'watches','category'=>'Watch','price'=>2999,'img'=>'/wp-content/themes/hell/media/watch2.jpg','badge'=>'NEW'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/watch2.jpg
    ['id'=>'wt3','name'=>'Digital Sport Watch','sub'=>'watches','category'=>'Watch','price'=>2499,'img'=>'/wp-content/themes/hell/media/watch3.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/watch3.jpg
    ['id'=>'wt4','name'=>'Classic Steel Watch','sub'=>'watches','category'=>'Watch','price'=>3599,'img'=>'/wp-content/themes/hell/media/watch4.jpg','badge'=>'HOT'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/watch4.jpg
    ['id'=>'wt5','name'=>'Chronograph Watch','sub'=>'watches','category'=>'Watch','price'=>4299,'img'=>'/wp-content/themes/hell/media/watch5.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/watch5.jpg
    // BAGS
    ['id'=>'bg1','name'=>'Tactical Backpack','sub'=>'bags','category'=>'Bag','price'=>2999,'img'=>'/wp-content/themes/hell/media/ImgHunt_Safaribags_20260306_02copy_e1ac5ffd-2768-4ff6-9d9a-f2a075e4a5b6.jpeg','badge'=>'HOT'],
    ['id'=>'bg2','name'=>'Mini Sling Bag','sub'=>'bags','category'=>'Bag','price'=>999,'img'=>'/wp-content/themes/hell/media/Sling Medium Borsttas _ Gorpcore.jpeg','badge'=>'SALE'],
    ['id'=>'bg3','name'=>'Tote Bag','sub'=>'bags','category'=>'Bag','price'=>1299,'img'=>'/wp-content/themes/hell/media/bag3.jpg','badge'=>'NEW'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/bag3.jpg
    ['id'=>'bg4','name'=>'Crossbody Bag','sub'=>'bags','category'=>'Bag','price'=>1599,'img'=>'/wp-content/themes/hell/media/bag4.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/bag4.jpg
    ['id'=>'bg5','name'=>'Duffel Bag','sub'=>'bags','category'=>'Bag','price'=>2199,'img'=>'/wp-content/themes/hell/media/bag5.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/bag5.jpg
    // JEWELRY
    ['id'=>'jw1','name'=>'Chain Necklace','sub'=>'jewelry','category'=>'Jewelry','price'=>1499,'img'=>'/wp-content/themes/hell/media/Mens Necklace - Mini Lapis Lazuli Silver Pendant Necklace For Men - Lapis Necklace , Mens Jewelry, Silver Chain Pendant - By Twistedpendant.jpeg','badge'=>''],
    ['id'=>'jw2','name'=>'Chunky Ring','sub'=>'jewelry','category'=>'Jewelry','price'=>899,'img'=>'/wp-content/themes/hell/media/jewelry2.jpg','badge'=>'NEW'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/jewelry2.jpg
    ['id'=>'jw3','name'=>'Cuban Link Bracelet','sub'=>'jewelry','category'=>'Jewelry','price'=>1299,'img'=>'/wp-content/themes/hell/media/jewelry3.jpg','badge'=>'HOT'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/jewelry3.jpg
    ['id'=>'jw4','name'=>'Stud Earrings','sub'=>'jewelry','category'=>'Jewelry','price'=>699,'img'=>'/wp-content/themes/hell/media/jewelry4.jpg','badge'=>'','tryon'=>'https://www.kivicube.com/face-scenes/1OHeNorFVxZQZGv6zlSayL7nID7RYeFG'],  // TODO: add image to /htdocs/wp-content/themes/hell/media/jewelry4.jpg
    ['id'=>'jw5','name'=>'H-Logo Pendant','sub'=>'jewelry','category'=>'Jewelry','price'=>1199,'img'=>'/wp-content/themes/hell/media/jewelry5.jpg','badge'=>''],  // TODO: add image to /htdocs/wp-content/themes/hell/media/jewelry5.jpg
    // SHOES
    ['id'=>'sw1','name'=>'Loewe Cloudtilt Sneaker','sub'=>'shoes','category'=>'Shoe','price'=>79999,'img'=>'/wp-content/themes/hell/media/shoe-loewe-cloudtilt-neon-yellow.jpg','badge'=>'NEW','tryon'=>'https://wanna-shoes.ar.wanna.fashion/?modelid=L929282X15-7586%2CXXW08J0HL60SHAB999%2C3W2S0HQ5VNWN71%2CWNSHOE001%2CWNSHOE002&startwithid=XXW08J0HL60SHAB999&mode=vto'],
    ['id'=>'sw2','name'=>'Tods Kate Loafers','sub'=>'shoes','category'=>'Shoe','price'=>68999,'img'=>'/wp-content/themes/hell/media/shoe-tods-kate-loafers-black.jpg','badge'=>'HOT','tryon'=>'https://wanna-shoes.ar.wanna.fashion/?modelid=L929282X15-7586%2CXXW08J0HL60SHAB999%2C3W2S0HQ5VNWN71%2CWNSHOE001%2CWNSHOE002&startwithid=XXW08J0HL60SHAB999&mode=vto'],
  ];

  // Category page metadata
  $category_meta = [
    'upper-wear'  => ['title'=>'Upper Wear','desc'=>'Tees, hoodies, jackets & shirts for the bold.','subs'=>['tshirts','hoodies','jackets','shirts'],'gradient'=>'linear-gradient(135deg,#667eea,#764ba2)','emoji'=>''],
    'tshirts'     => ['title'=>'T-Shirts','desc'=>'Premium oversized & graphic tees.','parent'=>'upper-wear','gradient'=>'linear-gradient(135deg,#43e97b,#38f9d7)','emoji'=>''],
    'hoodies'     => ['title'=>'Hoodies','desc'=>'Heavyweight fleece for cold days.','parent'=>'upper-wear','gradient'=>'linear-gradient(135deg,#a18cd1,#fbc2eb)','emoji'=>''],
    'jackets'     => ['title'=>'Jackets','desc'=>'Bombers, windbreakers & more.','parent'=>'upper-wear','gradient'=>'linear-gradient(135deg,#fa709a,#fee140)','emoji'=>''],
    'shirts'      => ['title'=>'Shirts','desc'=>'Oxford, linen & printed shirts.','parent'=>'upper-wear','gradient'=>'linear-gradient(135deg,#fccb90,#d57eeb)','emoji'=>''],
    'lower-wear'  => ['title'=>'Lower Wear','desc'=>'Jeans, shorts, trousers & joggers.','subs'=>['jeans','shorts','trousers','joggers'],'gradient'=>'linear-gradient(135deg,#f093fb,#f5576c)','emoji'=>''],
    'jeans'       => ['title'=>'Jeans','desc'=>'Slim, baggy & distressed denim.','parent'=>'lower-wear','gradient'=>'linear-gradient(135deg,#4facfe,#00f2fe)','emoji'=>''],
    'shorts'      => ['title'=>'Shorts','desc'=>'Cargo, athletic & denim shorts.','parent'=>'lower-wear','gradient'=>'linear-gradient(135deg,#30cfd0,#667eea)','emoji'=>''],
    'trousers'    => ['title'=>'Trousers','desc'=>'Tech, chino & wide leg trousers.','parent'=>'lower-wear','gradient'=>'linear-gradient(135deg,#f6d365,#fda085)','emoji'=>''],
    'joggers'     => ['title'=>'Joggers','desc'=>'Fleece & tech joggers.','parent'=>'lower-wear','gradient'=>'linear-gradient(135deg,#89f7fe,#66a6ff)','emoji'=>''],
    'accessories' => ['title'=>'Accessories','desc'=>'Caps, watches, bags, jewelry & shoes.','subs'=>['caps','watches','bags','jewelry','shoes'],'gradient'=>'linear-gradient(135deg,#4facfe,#00f2fe)','emoji'=>''],
    'caps'        => ['title'=>'Caps & Hats','desc'=>'Snapbacks, dad hats & bucket hats.','parent'=>'accessories','gradient'=>'linear-gradient(135deg,#f7971e,#ffd200)','emoji'=>''],
    'watches'     => ['title'=>'Watches','desc'=>'Street & minimal timepieces.','parent'=>'accessories','gradient'=>'linear-gradient(135deg,#eb3349,#f45c43)','emoji'=>'⌚'],
    'bags'        => ['title'=>'Bags','desc'=>'Backpacks, slings & totes.','parent'=>'accessories','gradient'=>'linear-gradient(135deg,#11998e,#38ef7d)','emoji'=>''],
    'jewelry'     => ['title'=>'Jewelry','desc'=>'Chains, rings & pendants.','parent'=>'accessories','gradient'=>'linear-gradient(135deg,#c471f5,#fa71cd)','emoji'=>''],
    'shoes'       => ['title'=>'Shoes','desc'=>'Sneakers, loafers, flats & VR-ready footwear.','parent'=>'accessories','gradient'=>'linear-gradient(135deg,#f6d365,#fda085)','emoji'=>''],
  ];

  function render_product_card($p, $pid) {
    $badge_html = $p['badge'] ? '<span class="custom-badge'.($p['badge']==='SALE'?' badge-sale':'').'">'.htmlspecialchars($p['badge']).'</span>' : '';
    $name_parts = explode(' ', $p['name'], -1);
    $last_word  = explode(' ', $p['name']);
    $last_word  = end($last_word);
    $first_part = rtrim(substr($p['name'], 0, strlen($p['name'])-strlen($last_word)));
    return '<div class="grid__item hp-featured-collection-slide">
      <div class="card-wrapper" data-product-id="'.htmlspecialchars($pid).'" style="cursor:pointer;">
        <div class="card">
          <div class="card__inner">
            <div class="card__media">
              <img src="'.htmlspecialchars($p['img']).'" alt="'.htmlspecialchars($p['name']).'" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;" onerror="this.style.background=\'#f0f0f0\';this.style.objectFit=\'none\';">
              '.$badge_html.'
              <div class="quick-add-overlay">Quick Add</div>
            </div>
            <div class="card__information">
              <div class="title-price">
                <h3 class="card__heading">
                  <a href="?page='.htmlspecialchars($p['sub']).'" class="full-unstyled-link">
                    '.htmlspecialchars($first_part).'<span>'.htmlspecialchars($last_word).'</span>
                  </a>
                </h3>
                <div class="price__container">
                  <span class="price-item--regular">₹ '.number_format($p['price']).'<span class="mrp-price">MRP</span></span>
                  <p class="inc-tax">incl. taxes</p>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>
    </div>';
  }

  if ($current_page !== 'home'):
    $meta = $category_meta[$current_page] ?? ['title'=>ucfirst($current_page),'desc'=>'','gradient'=>'linear-gradient(135deg,#667eea,#764ba2)','emoji'=>''];
    $is_parent = isset($meta['subs']);
    // Filter products for this page
    if ($is_parent) {
      $page_products = array_filter($ALL_PRODUCTS, fn($p) => in_array($p['sub'], $meta['subs']));
    } else {
      $page_products = array_filter($ALL_PRODUCTS, fn($p) => $p['sub'] === $current_page);
    }
  ?>

  <!-- ===== CATEGORY PAGE HEADER ===== -->
  <section>
    <div class="secondary-banner" style="min-height:28rem;">
      <div class="secondary-banner__media" style="background:<?php echo $meta['gradient']; ?>;">
        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
          <div style="font-size:10rem;opacity:.15;"><?php echo $meta['emoji']; ?></div>
        </div>
      </div>
      <div class="secondary-banner__content">
        <div class="hero-banner__box">
          <div class="hero-banner__eyebrow">Fresh Edit</div>
          <?php if(isset($meta['parent'])): ?>
          <p style="font-size:1.2rem;letter-spacing:.15em;text-transform:uppercase;color:rgba(255,255,255,.6);margin-bottom:.8rem;"><a href="?page=<?php echo $meta['parent']; ?>" style="color:rgba(255,255,255,.6);"><?php echo htmlspecialchars($category_meta[$meta['parent']]['title']); ?></a> › <?php echo htmlspecialchars($meta['title']); ?></p>
          <?php endif; ?>
          <h2 class="hero-banner__heading"><?php echo htmlspecialchars($meta['title']); ?></h2>
          <div class="hero-banner__text"><p><?php echo htmlspecialchars($meta['desc']); ?></p></div>
        </div>
      </div>
    </div>
  </section>

  <?php if($is_parent): ?>
  <!-- Sub-category tiles for parent pages -->
  <section style="padding:5rem 4rem;max-width:1600px;margin:0 auto;">
    <div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(22rem,1fr));gap:2rem;margin-bottom:4rem;">
      <?php foreach($meta['subs'] as $sub): $sm=$category_meta[$sub]??[]; ?>
      <a href="?page=<?php echo $sub; ?>" style="display:block;text-decoration:none;">
        <div style="background:<?php echo $sm['gradient']??'#eee'; ?>;padding:4rem 2rem;text-align:center;transition:transform .2s;border-radius:0;" onmouseover="this.style.transform='translateY(-4px)'" onmouseout="this.style.transform=''">
          <div style="font-family:'Space Grotesk',sans-serif;font-size:1.6rem;font-weight:800;color:#fff;letter-spacing:.05em;text-transform:uppercase;"><?php echo htmlspecialchars($sm['title']); ?></div>
        </div>
      </a>
      <?php endforeach; ?>
    </div>
  </section>
  <?php endif; ?>

  <!-- ===== PRODUCTS GRID ===== -->
  <section class="featured-collection" style="padding-top:2rem;" id="cat-products">
    <div class="collection__title">
      <div><h2 class="title"><?php echo htmlspecialchars($meta['title']); ?></h2></div>
      <div class="cta-btn-holder"><span><?php echo count($page_products); ?> Products</span></div>
    </div>
    <div class="product-grid" style="display:grid;grid-template-columns:repeat(auto-fill,minmax(26rem,1fr));overflow:visible;cursor:default;gap:2.4rem;padding:0 4rem 4rem;">
      <?php
      $idx = 0;
      foreach($page_products as $p):
        $pid_key = 'cat_'.$p['id'];
        echo render_product_card($p, $pid_key);
        $idx++;
      endforeach;
      if($idx===0): ?>
      <div style="grid-column:1/-1;text-align:center;padding:6rem 2rem;color:#999;">
        <p style="font-size:1.6rem;">Products coming soon. Check back later!</p>
      </div>
      <?php endif; ?>
    </div>
  </section>

  <?php else: // HOME PAGE ?>

  <!-- ===== HERO BANNER ===== -->
  <section>
    <div class="hero-banner">
      <div class="hero-banner__media hero-gradient">
        <video src="/wp-content/themes/hell/media/From Main Klickpin CF- [251001] - 70v6lPQjP.mp4" autoplay loop muted playsinline style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;filter:blur(3px);transform:scale(1.04);"></video>
        <div class="hero-orbit orbit-a">Verified Drops</div>
        <div class="hero-orbit orbit-b">Streetwear Finds</div>
        <div class="hero-orbit orbit-c">COD Available</div>
      </div>
      <div class="hero-banner__content">
        <div class="hero-banner__box">
          <div class="hero-banner__eyebrow">Curated Thrift Energy</div>
          <h2 class="hero-banner__heading">Wear What<br>You Mean</h2>
          <div class="hero-banner__text"><p>New Season Collection — Upper Wear Essentials</p></div>
          <div class="hero-banner__buttons">
            <a href="?page=upper-wear" class="btn-primary">Shop Upper Wear</a>
            <a href="?page=lower-wear" class="btn-outline">Explore All</a>
          </div>
          <div class="hero-banner__stats">
            <div class="hero-stat"><strong>50+</strong><span>Weekly finds</span></div>
            <div class="hero-stat"><strong>Top rated</strong><span>Fast support</span></div>
            <div class="hero-stat"><strong>India-wide</strong><span>Shipping ready</span></div>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== UPPER WEAR SECTION ===== -->
  <section class="featured-collection" id="upper-wear">
    <div class="collection__title">
      <div>
        <h2 class="title">Upper Wear</h2>
      </div>
      <div class="cta-btn-holder">
        <a href="?page=upper-wear">Shop All Upper Wear</a>
      </div>
        <div class="slider-arrows">
          <button class="slide-arrow" data-dir="prev" aria-label="Previous">
            <svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
          </button>
          <button class="slide-arrow" data-dir="next" aria-label="Next">
            <svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
          </button>
        </div>
    </div>

    <div class="product-grid" id="upper-grid">

      <!-- Product 1 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/imgi_586_46888f878d5547c2f8c59de05ec960e213a1b52b.jpg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <span class="custom-badge">NEW</span>
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=tshirts" class="full-unstyled-link">
                      Classic Drop<span>Oversized Tee</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 1299<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 2 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/imgi_712_e2a4c45def96b2bbe5b1a730c919ac5d00fe8ae0.jpg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <span class="custom-badge badge-sale">SALE</span>
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=jackets" class="full-unstyled-link">
                      Noir Bomber<span>Jacket</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular" style="color:#ff5271;">₹ 3499</span>
                    <span style="text-decoration:line-through;font-size:1.1rem;color:rgba(31,31,31,0.4);display:block;">₹ 4499</span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 3 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/imgi_349_f0aff27fb1be61447516bd12346fae11ce010501.jpg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=hoodies" class="full-unstyled-link">
                      Arch Logo<span>Hoodie</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 2199<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 4 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/imgi_626_3f5c4f6198ab99addeb933613ab5261a650fff95.jpg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <span class="custom-badge">HOT</span>
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=shirts" class="full-unstyled-link">
                      Helvetica Oxford<span>Shirt</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 1799<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 5 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/imgi_330_4a6ea369437d2ae71b13159a4dfe25022bebf2fd.jpg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=upper-wear" class="full-unstyled-link">
                      Urban Zip<span>Sweatshirt</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 2499<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 6: VR Utility Jacket -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/jac.jpg" alt="VR Utility Jacket" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <span class="custom-badge">NEW</span>
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=jackets" class="full-unstyled-link">
                      VR Utility<span>Jacket</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 3599<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </section>

  <!-- ===== SECONDARY BANNER (between sections) ===== -->
  <section>
    <div class="secondary-banner">
      <div class="secondary-banner__media hero-gradient-2">
        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">
          <div style="text-align:center;padding:2rem;z-index:2;position:relative;">
            <p style="font-family:'Space Grotesk',sans-serif;font-size:5rem;font-weight:900;color:rgba(255,255,255,0.05);letter-spacing:-0.04em;position:absolute;inset:0;display:flex;align-items:center;justify-content:center;">STYLE</p>
          </div>
        </div>
      </div>
      <div class="secondary-banner__content">
        <div class="hero-banner__box">
          <h2 class="hero-banner__heading">Define Your<br>Bottom Half</h2>
          <div class="hero-banner__text"><p>Helvetica Lower Wear — Cut For The Streets</p></div>
          <div class="hero-banner__buttons">
            <a href="?page=lower-wear" class="btn-primary">Shop Lower Wear</a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== LOWER WEAR SECTION ===== -->
  <section class="featured-collection" id="lower-wear">
    <div class="collection__title">
      <div>
        <h2 class="title">Lower Wear</h2>
      </div>
      <div class="cta-btn-holder">
        <a href="?page=lower-wear">Shop All Lower Wear</a>
      </div>
        <div class="slider-arrows">
          <button class="slide-arrow" data-dir="prev" aria-label="Previous">
            <svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
          </button>
          <button class="slide-arrow" data-dir="next" aria-label="Next">
            <svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
          </button>
        </div>
    </div>

    <div class="product-grid">

      <!-- Product 1 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/bottom-wear3.jpg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <span class="custom-badge">NEW</span>
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=jeans" class="full-unstyled-link">
                      Indigo Slim<span>Jeans</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 2199<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 2 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/bottom-wear5.jpg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=shorts" class="full-unstyled-link">
                      Cargo<span>Shorts</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 1499<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 3 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/bottom-wear1.jpg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <span class="custom-badge badge-sale">SALE</span>
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=trousers" class="full-unstyled-link">
                      Tech<span>Trousers</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular" style="color:#ff5271;">₹ 1899</span>
                    <span style="text-decoration:line-through;font-size:1.1rem;color:rgba(31,31,31,0.4);display:block;">₹ 2599</span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 4 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/bottom-wear2.jpg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=joggers" class="full-unstyled-link">
                      Flex<span>Joggers</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 1699<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 5 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/bottom-wear4.jpg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <span class="custom-badge">TRENDING</span>
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=trousers" class="full-unstyled-link">
                      Wide Leg<span>Pants</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 2299<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </section>

  <!-- ===== SECONDARY BANNER 2 ===== -->
  <section>
    <div class="secondary-banner">
      <div class="secondary-banner__media" style="background:linear-gradient(135deg,#4facfe,#00f2fe);">
        <div style="position:absolute;inset:0;display:flex;align-items:center;justify-content:center;font-size:8rem;opacity:.15;"></div>
      </div>
      <div class="secondary-banner__content">
        <div class="hero-banner__box">
          <h2 class="hero-banner__heading">Complete<br>The Look</h2>
          <div class="hero-banner__text"><p>Helvetica Accessories — The Finishing Touch</p></div>
          <div class="hero-banner__buttons">
            <a href="?page=accessories" class="btn-primary">Shop Accessories</a>
          </div>
        </div>
      </div>
    </div>
  </section>

  <!-- ===== ACCESSORIES SECTION ===== -->
  <section class="featured-collection" id="accessories">
    <div class="collection__title">
      <div>
        <h2 class="title">Accessories</h2>
      </div>
      <div class="cta-btn-holder">
        <a href="?page=accessories">Shop All Accessories</a>
      </div>
        <div class="slider-arrows">
          <button class="slide-arrow" data-dir="prev" aria-label="Previous">
            <svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
          </button>
          <button class="slide-arrow" data-dir="next" aria-label="Next">
            <svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
          </button>
        </div>
    </div>

    <div class="product-grid">

      <!-- Product 1 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/ImgHunt_Urbanmonkey_20260302_evolve-24lst213-blk-808114.jpeg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <span class="custom-badge">NEW</span>
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=caps" class="full-unstyled-link">
                      H-Logo Cap<span>Snapback</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 799<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 2 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/ImgHunt_Rolex_20260302_professional-watches-cosmograph-daytona-myth-push-watch-first_cosmograph_daytona_1963.jpeg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=watches" class="full-unstyled-link">
                      Street Watch<span>Timepiece</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 3299<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 3 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/ImgHunt_Safaribags_20260306_02copy_e1ac5ffd-2768-4ff6-9d9a-f2a075e4a5b6.jpeg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <span class="custom-badge">HOT</span>
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=bags" class="full-unstyled-link">
                      Tactical Backpack<span>Carry Gear</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 2999<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 4 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/Mens Necklace - Mini Lapis Lazuli Silver Pendant Necklace For Men - Lapis Necklace , Mens Jewelry, Silver Chain Pendant - By Twistedpendant.jpeg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=jewelry" class="full-unstyled-link">
                      Chain Necklace<span>Jewelry</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 1499<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <!-- Product 5 -->
      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/Sling Medium Borsttas _ Gorpcore.jpeg" alt="Product" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <span class="custom-badge badge-sale">SALE</span>
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=bags" class="full-unstyled-link">
                      Mini Sling Bag<span>Essentials</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular" style="color:#ff5271;">₹ 999</span>
                    <span style="text-decoration:line-through;font-size:1.1rem;color:rgba(31,31,31,0.4);display:block;">₹ 1299</span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

    </div>
  </section>

  <!-- ===== SHOES SECTION ===== -->
  <section class="featured-collection" id="shoes">
    <div class="collection__title">
      <div>
        <h2 class="title">Shoes</h2>
      </div>
      <div class="cta-btn-holder">
        <a href="?page=shoes">Shop All Shoes</a>
      </div>
        <div class="slider-arrows">
          <button class="slide-arrow" data-dir="prev" aria-label="Previous">
            <svg viewBox="0 0 24 24"><path d="M15 18l-6-6 6-6" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
          </button>
          <button class="slide-arrow" data-dir="next" aria-label="Next">
            <svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6" stroke="#fff" stroke-width="2" stroke-linecap="round" fill="none"/></svg>
          </button>
        </div>
    </div>

    <div class="product-grid">

      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/shoe-loewe-cloudtilt-neon-yellow.jpg" alt="Loewe Cloudtilt Sneaker" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <span class="custom-badge">NEW</span>
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=shoes" class="full-unstyled-link">
                      Loewe Cloudtilt<span>Sneaker</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 79999<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      <div class="grid__item hp-featured-collection-slide">
        <div class="card-wrapper">
          <div class="card">
            <div class="card__inner">
              <div class="card__media">
                <img src="/wp-content/themes/hell/media/shoe-tods-kate-loafers-black.jpg" alt="Tods Kate Loafers" style="position:absolute;inset:0;width:100%;height:100%;object-fit:cover;">
                <span class="custom-badge">HOT</span>
                <div class="quick-add-overlay">Quick Add</div>
              </div>
              <div class="card__information">
                <div class="title-price">
                  <h3 class="card__heading">
                    <a href="?page=shoes" class="full-unstyled-link">
                      Tods Kate<span>Loafers</span>
                    </a>
                  </h3>
                  <div class="price__container">
                    <span class="price-item--regular">₹ 68999<span class="mrp-price">MRP</span></span>
                    <p class="inc-tax">incl. taxes</p>
                  </div>
                </div>
              </div>
            </div>
          </div>
        </div>
      </div>

      
        </div>
      </div>

      
        </div>
      </div>

      
        </div>
      </div>

    </div>
  </section>

  <?php endif; // end home/category page check ?>
  <!-- ===== NEWSLETTER ===== -->
  <section class="newsletter-section">
    <div class="newsletter-section__inner">
      <h2>Stay In The Know</h2>
      <p>New drops, exclusive offers, and style guides — directly to your inbox.</p>
      <div class="email-form">
        <input type="email" placeholder="Enter your email address">
        <button type="button">Subscribe</button>
      </div>
    </div>
  </section>

  <!-- ===== FOOTER TOP ===== -->
  <div class="footer-top-section">
    <div class="ft-top-content-container">
      <div class="icons-area">
        <div class="icon-item">
          <svg width="50" height="50" viewBox="0 0 50 50" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M25 5L30 15L41 16.5L33 24.3L34.9 35.3L25 30L15.1 35.3L17 24.3L9 16.5L20 15L25 5Z" fill="#1f1f1f"/>
          </svg>
        </div>
        <div class="icon-item">
          <svg width="50" height="50" viewBox="0 0 50 50" fill="none" xmlns="http://www.w3.org/2000/svg">
            <circle cx="25" cy="25" r="20" stroke="#1f1f1f" stroke-width="3" fill="none"/>
            <path d="M15 25L22 32L35 18" stroke="#1f1f1f" stroke-width="3" stroke-linecap="round" stroke-linejoin="round"/>
          </svg>
        </div>
        <div class="icon-item">
          <svg width="50" height="50" viewBox="0 0 50 50" fill="none" xmlns="http://www.w3.org/2000/svg">
            <path d="M10 15H40C41.1 15 42 15.9 42 17V37C42 38.1 41.1 39 40 39H10C8.9 39 8 38.1 8 37V17C8 15.9 8.9 15 10 15Z" stroke="#1f1f1f" stroke-width="2.5" fill="none"/>
            <path d="M8 20L25 30L42 20" stroke="#1f1f1f" stroke-width="2.5"/>
          </svg>
        </div>
      </div>

      <div class="footer-top-content">
        <h2>
          <span class="hg-content">GREAT FASHION</span>
          WILL TAKE YOU TO GREAT BADDIES
        </h2>
      </div>
    </div>
  </div>

  <!-- ===== TRUST ICONS ===== -->
  <div class="trust-icon-section">
    <div class="icons-with-text-wrapping">
      <div class="icons-text-part">
        <svg width="60" height="60" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M50 10L70 30H60V60H40V30H30L50 10Z" fill="#1f1f1f"/>
          <rect x="25" y="65" width="50" height="8" rx="2" fill="#1f1f1f"/>
          <rect x="30" y="78" width="40" height="8" rx="2" fill="#1f1f1f"/>
        </svg>
        <div class="content-wrap">
          <h4>Free Shipping</h4>
          <p>Free shipping on all orders above ₹999.</p>
        </div>
      </div>
      <div class="icons-text-part">
        <svg width="60" height="60" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
          <rect x="15" y="25" width="70" height="55" rx="5" stroke="#1f1f1f" stroke-width="5" fill="none"/>
          <path d="M35 25V20C35 15 40 10 50 10C60 10 65 15 65 20V25" stroke="#1f1f1f" stroke-width="5" fill="none"/>
          <path d="M35 52L45 62L65 42" stroke="#1f1f1f" stroke-width="5" stroke-linecap="round"/>
        </svg>
        <div class="content-wrap">
          <h4>Cash on Delivery</h4>
          <p>Pay when you receive your order.</p>
        </div>
      </div>
      <div class="icons-text-part">
        <svg width="60" height="60" viewBox="0 0 100 100" fill="none" xmlns="http://www.w3.org/2000/svg">
          <path d="M20 50C20 33 33 20 50 20" stroke="#1f1f1f" stroke-width="5" stroke-linecap="round" fill="none"/>
          <path d="M80 50C80 67 67 80 50 80" stroke="#1f1f1f" stroke-width="5" stroke-linecap="round" fill="none"/>
          <path d="M10 50L20 40L30 50" stroke="#1f1f1f" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
          <path d="M90 50L80 60L70 50" stroke="#1f1f1f" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" fill="none"/>
        </svg>
        <div class="content-wrap">
          <h4>Easy Returns</h4>
          <p>Free 7-day return and exchange.</p>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== MAIN FOOTER ===== -->
  <footer class="footer" id="about">
    <div class="footer__content-top">

      <!-- Get In Touch -->
      <div class="footer-block">
        <h2 class="footer-block__heading">
          <svg width="24" height="18" viewBox="0 0 24 18" fill="none" style="flex-shrink:0;">
            <path d="M2 2H22C22.6 2 23 2.4 23 3V15C23 15.6 22.6 16 22 16H2C1.4 16 1 15.6 1 15V3C1 2.4 1.4 2 2 2Z" stroke="rgba(236,235,11,0.8)" stroke-width="1.5" fill="none"/>
            <path d="M1 3L12 10L23 3" stroke="rgba(236,235,11,0.8)" stroke-width="1.5"/>
          </svg>
          GET IN TOUCH
        </h2>
        <div class="contact-info-wrapper">
          <a href="https://wa.me/918490986234" target="_blank">
            <svg width="20" height="20" viewBox="0 0 55 55" fill="none" xmlns="http://www.w3.org/2000/svg">
              <path d="M0 55L4.7 40.8C-4.6 25.1 4 4.7 21.9 0.7C41.8-3.8 59.3 14.2 54.1 33.8C49.6 50.6 30.2 58.7 14.9 50.4L0 55Z" fill="rgba(236,235,11,0.6)"/>
            </svg>
            <span><strong>WhatsApp</strong>: +91 8490986234</span>
          </a>
          <a href="/cdn-cgi/l/email-protection#a1c9c4cdcdcee1c9c4cdd7c4d5c8c2c08fc8cf">
            <strong>Support</strong>: <span><span class="__cf_email__" data-cfemail="cea6aba2a2a18ea6aba2b8abbaa7adafe0a7a0">teamhelvetica0@gmail.com</span></span>
          </a>
          <a href="/cdn-cgi/l/email-protection#30535f5c5c51527058555c4655445953511e595e">
            <strong>Collaborations</strong>: <span><span class="__cf_email__" data-cfemail="12717d7e7e7370527a777e6477667b71733c7b7c">teamhelvetica0@gmail.com</span></span>
          </a>
          <a href="#">
            <strong>Careers</strong>: <span>Apply Here</span>
          </a>
        </div>

        <div class="border-bottom-footer"></div>

        <h2 class="footer-block__heading">
          <svg width="18" height="22" viewBox="0 0 18 22" fill="none" style="flex-shrink:0;">
            <path d="M9 1C5.7 1 3 3.7 3 7C3 12 9 21 9 21C9 21 15 12 15 7C15 3.7 12.3 1 9 1Z" stroke="rgba(236,235,11,0.8)" stroke-width="1.5" fill="none"/>
            <circle cx="9" cy="7" r="2.5" stroke="rgba(236,235,11,0.8)" stroke-width="1.5" fill="none"/>
          </svg>
          REACH US
        </h2>
        <p style="color:rgba(255,255,255,0.5);font-size:1.2rem;line-height:1.7;">
          67th Floor, Helvetica House,<br>
          Ahmedabad,<br>
          Gujarat 382225
        </p>
      </div>

      <!-- Social & About -->
      <div class="footer-block">
        <h2 class="footer-block__heading">
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none" style="flex-shrink:0;">
            <circle cx="10" cy="10" r="9" stroke="rgba(236,235,11,0.8)" stroke-width="1.5" fill="none"/>
            <circle cx="10" cy="8" r="3" stroke="rgba(236,235,11,0.8)" stroke-width="1.5" fill="none"/>
            <path d="M4 18C4 14.7 6.7 12 10 12C13.3 12 16 14.7 16 18" stroke="rgba(236,235,11,0.8)" stroke-width="1.5" fill="none"/>
          </svg>
          SOCIAL
        </h2>
        <ul class="list-social">
          <li class="list-social__item">
            <a href="https://www.instagram.com/" class="list-social__link">
              <svg aria-hidden="true" focusable="false" class="icon icon-instagram" viewBox="0 0 18 18">
                <path fill="currentColor" d="M8.77 1.58c2.34 0 2.62.01 3.54.05.86.04 1.32.18 1.63.3.41.17.7.35 1.01.66.3.3.5.6.65 1 .12.32.27.78.3 1.64.05.92.06 1.2.06 3.54s-.01 2.62-.05 3.54a4.79 4.79 0 01-.3 1.63c-.17.41-.35.7-.66 1.01-.3.3-.6.5-1.01.66-.31.12-.77.26-1.63.3-.92.04-1.2.05-3.54.05s-2.62 0-3.55-.05a4.79 4.79 0 01-1.62-.3c-.42-.16-.7-.35-1.01-.66-.31-.3-.5-.6-.66-1a4.87 4.87 0 01-.3-1.64c-.04-.92-.05-1.2-.05-3.54s0-2.62.05-3.54c.04-.86.18-1.32.3-1.63.16-.41.35-.7.66-1.01.3-.3.6-.5 1-.65.32-.12.78-.27 1.63-.3.93-.05 1.2-.06 3.55-.06zm0-1.58C6.39 0 6.09.01 5.15.05c-.93.04-1.57.2-2.13.4-.57.23-1.06.54-1.55 1.02C1 1.96.7 2.45.46 3.02c-.22.56-.37 1.2-.4 2.13C0 6.1 0 6.4 0 8.77s.01 2.68.05 3.61c.04.94.2 1.57.4 2.13.23.58.54 1.07 1.02 1.56.49.48.98.78 1.55 1.01.56.22 1.2.37 2.13.4.94.05 1.24.06 3.62.06 2.39 0 2.68-.01 3.62-.05.93-.04 1.57-.2 2.13-.41a4.27 4.27 0 001.55-1.01c.49-.49.79-.98 1.01-1.56.22-.55.37-1.19.41-2.13.04-.93.05-1.23.05-3.61 0-2.39 0-2.68-.05-3.62a6.47 6.47 0 00-.4-2.13 4.27 4.27 0 00-1.02-1.55A4.35 4.35 0 0014.52.46a6.43 6.43 0 00-2.13-.41A69 69 0 008.77 0z"/>
                <path fill="currentColor" d="M8.8 4a4.5 4.5 0 100 9 4.5 4.5 0 000-9zm0 7.43a2.92 2.92 0 110-5.85 2.92 2.92 0 010 5.85zM13.43 5a1.05 1.05 0 100-2.1 1.05 1.05 0 000 2.1z"/>
              </svg>
              <span class="visually-hidden">Instagram</span>
            </a>
          </li>
          <li class="list-social__item">
            <a href="https://www.linkedin.com/" class="list-social__link">
              <svg xmlns="http://www.w3.org/2000/svg" width="20" height="20" viewBox="0 0 20 20">
                <path fill-rule="evenodd" d="M20 20h-4v-6.999c0-1.92-.847-2.991-2.366-2.991-1.653 0-2.634 1.116-2.634 2.991V20H7V7h4v1.462s1.255-2.202 4.083-2.202C17.912 6.26 20 7.986 20 11.558V20zM2.442 4.921A2.451 2.451 0 010 2.46 2.451 2.451 0 012.442 0a2.451 2.451 0 012.441 2.46 2.45 2.45 0 01-2.441 2.461zM0 20h5V7H0v13z" fill="currentColor"/>
              </svg>
            </a>
          </li>
        </ul>

        <div class="border-bottom-footer"></div>

        <h2 class="footer-block__heading" style="margin-top:2rem;">
          ABOUT US
        </h2>
        <ul class="list-unstyled">
          <li><a href="#about"><span class="link-underline">About Helvetica</span></a></li>
          <li><a href="#about"><span class="link-underline">Our Story</span></a></li>
          <li><a href="#about"><span class="link-underline">Craftsmanship</span></a></li>
          <li><a href="#about"><span class="link-underline">Sustainability</span></a></li>
        </ul>
      </div>

      <!-- Quick Links -->
      <div class="footer-block">
        <h2 class="footer-block__heading">
          <svg width="20" height="20" viewBox="0 0 20 20" fill="none" style="flex-shrink:0;">
            <path d="M3 5H17M3 10H17M3 15H12" stroke="rgba(236,235,11,0.8)" stroke-width="1.5" stroke-linecap="round"/>
          </svg>
          QUICK LINKS
        </h2>
        <ul class="list-unstyled">
          <li><a href="#"><span class="link-underline">Home</span></a></li>
          <li><a href="#upper-wear"><span class="link-underline">Upper Wear</span></a></li>
          <li><a href="#lower-wear"><span class="link-underline">Lower Wear</span></a></li>
          <li><a href="#accessories"><span class="link-underline">Accessories</span></a></li>
          <li><a href="#"><span class="link-underline">Track Your Order</span></a></li>
          <li><a href="#"><span class="link-underline">Contact Us</span></a></li>
        </ul>
      </div>

    </div>

    <div class="footer__content-bottom">
      <div class="footer__copyright">
        <small>© 2026, <a href="/">HELVETICA Fashion Private Limited. All Rights Reserved.</a></small><br>
          <small><a href="https://www.youtube.com/watch?v=pFptt7Cargc">-RAO RUSHI H.-</a></small>
      </div>
    </div>
  </footer>

  <!-- ===== CONTACT US MODAL ===== -->
  <div class="contact-overlay" id="contactOverlay">
    <div class="contact-box">
      <button class="contact-close" onclick="closeContactUs()">×</button>
      <div class="contact-head">
        <h2>Contact Us</h2>
          <p>We'd love to hear from you. Reach out anytime.</p>
      </div>
      <div class="contact-body">
        <div class="contact-info-item">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0c1a2b" stroke-width="2" stroke-linecap="round"><path d="M22 16.92v3a2 2 0 01-2.18 2 19.79 19.79 0 01-8.63-3.07A19.5 19.5 0 013.95 12 19.79 19.79 0 01.88 3.4 2 2 0 012.87 1.23h3a2 2 0 012 1.72c.127.96.361 1.903.7 2.81a2 2 0 01-.45 2.11L7.09 8.91a16 16 0 006 6l1.06-1.13a2 2 0 012.11-.45c.907.339 1.85.573 2.81.7A2 2 0 0121 16.92z"/></svg>
          <div>
            <h4>WhatsApp / Phone</h4>
            <a href="https://wa.me/917574994277" target="_blank">+91 8490986234</a>
          </div>
        </div>
        <div class="contact-info-item">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0c1a2b" stroke-width="2" stroke-linecap="round"><path d="M4 4h16c1.1 0 2 .9 2 2v12c0 1.1-.9 2-2 2H4c-1.1 0-2-.9-2-2V6c0-1.1.9-2 2-2z"/><polyline points="22,6 12,13 2,6"/></svg>
          <div>
            <h4>Email</h4>
            <a href="mailto:teamhelvetica0@gmail.com">teamhelvetica0@gmail.com</a>
          </div>
        </div>
        <div class="contact-info-item">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0c1a2b" stroke-width="2" stroke-linecap="round"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0118 0z"/><circle cx="12" cy="10" r="3"/></svg>
          <div>
            <h4>Address</h4>
            <p>67th Floor, Helvetica House,<br>Ahmedabad, Gujarat 382225</p>
          </div>
        </div>
        <div class="contact-info-item">
          <svg width="22" height="22" viewBox="0 0 24 24" fill="none" stroke="#0c1a2b" stroke-width="2" stroke-linecap="round"><circle cx="12" cy="12" r="10"/><polyline points="12,6 12,12 16,14"/></svg>
          <div>
            <h4>Business Hours</h4>
            <p>Mon – Sat: 10:00 AM – 7:00 PM IST</p>
          </div>
        </div>
        <div style="margin-top:2rem;">
          <a href="https://wa.me/918490986234" target="_blank" style="display:block;width:100%;background:var(--color-dark);color:var(--color-accent);font-family:inherit;font-size:1.3rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;padding:1.5rem;text-align:center;text-decoration:none;transition:all .3s;" onmouseover="this.style.background='var(--color-accent)';this.style.color='var(--color-dark)';" onmouseout="this.style.background='var(--color-dark)';this.style.color='var(--color-accent)';">Chat on WhatsApp</a>
        </div>
      </div>
    </div>
  </div>

  <!-- ===== CART DRAWER ===== -->
  <div class="cart-drawer-overlay" id="cart-drawer-overlay"></div>
  <div class="cart-drawer" id="cart-drawer" role="dialog" aria-label="Shopping cart">
    <div class="cart-drawer__header">
      <span class="cart-drawer__title">Your Cart</span>
      <button class="cart-drawer__close" id="cart-drawer-close" aria-label="Close cart">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round">
          <path d="M18 6L6 18M6 6l12 12"/>
        </svg>
      </button>
    </div>
    <div class="cart-drawer__body" id="cart-drawer-body">
      <div class="cart-drawer__empty" id="cart-empty-state">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
          <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4zM3 6h18M16 10a4 4 0 01-8 0"/>
        </svg>
        <p>Your cart is empty</p>
      </div>
      <div id="cart-items-list"></div>
    </div>
    <div class="cart-drawer__footer" id="cart-drawer-footer" style="display:none;">
      <div class="cart-subtotal">
        <span>Subtotal</span>
        <span class="cart-subtotal__amount" id="cart-subtotal-amount">₹ 0</span>
      </div>
      <button class="cart-checkout-btn" id="checkoutBtn" onclick="document.getElementById('coOverlay').style.display='flex';document.body.style.overflow='hidden';">Proceed to Checkout</button>
    </div>
  </div>

  <!-- ===== AUTH MODAL ===== -->
  <div class="auth-overlay" id="authOverlay">
    <div class="auth-box">
      <button class="auth-close" onclick="closeAuth()">×</button>
      <div class="auth-head">
        <h2>HELVETICA Account</h2>
        <p>Sign in or create an account to track your orders.</p>
      </div>
      <div class="auth-tabs">
        <button class="auth-tab active" onclick="switchAuthTab('login',this)">Log In</button>
        <button class="auth-tab" onclick="switchAuthTab('signup',this)">Sign Up</button>
      </div>

      <?php if($auth_error): ?>
        <div class="auth-msg error" style="margin:1.2rem 2.4rem 0;"><?php echo htmlspecialchars($auth_error); ?></div>
      <?php endif; ?>
      <?php if($auth_success): ?>
        <div class="auth-msg success" style="margin:1.2rem 2.4rem 0;"><?php echo htmlspecialchars($auth_success); ?></div>
      <?php endif; ?>

      <!-- LOGIN PANEL -->
      <div class="auth-panel active" id="auth-login">
        <form method="POST">
          <input type="hidden" name="hv_login" value="1">
          <div class="auth-group">
            <label>Email</label>
            <input type="email" name="login_email" placeholder="your@email.com" required value="<?php echo htmlspecialchars($_POST['login_email']??'');?>">
          </div>
          <div class="auth-group">
            <label>Password</label>
            <input type="password" name="login_pass" placeholder="Your password" required>
          </div>
          <button type="submit" class="auth-submit">Log In →</button>
        </form>
      </div>

      <!-- SIGNUP PANEL -->
      <div class="auth-panel" id="auth-signup">
        <form method="POST">
          <input type="hidden" name="hv_signup" value="1">
          <div class="auth-group">
            <label>Full Name</label>
            <input type="text" name="signup_name" placeholder="Your name" required>
          </div>
          <div class="auth-group">
            <label>Email</label>
            <input type="email" name="signup_email" placeholder="your@email.com" required>
          </div>
          <div class="auth-group">
            <label>Password</label>
            <input type="password" name="signup_pass" placeholder="Create a password" required minlength="6">
          </div>
          <button type="submit" class="auth-submit">Create Account →</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ===== ACCOUNT / ORDER HISTORY DRAWER ===== -->
  <div class="account-overlay" id="accountOverlay">
    <div class="account-drawer">
      <div class="account-drawer__header">
        <h2>My Account</h2>
        <button class="account-close" onclick="closeAccount()">×</button>
      </div>
      <?php if($current_user): ?>
      <div class="account-user-info">
        <div class="account-avatar"><?php echo strtoupper(substr($current_user['name'],0,1)); ?></div>
        <div>
          <div class="account-user-name"><?php echo htmlspecialchars($current_user['name']); ?></div>
          <div class="account-user-email"><?php echo htmlspecialchars($current_user['email']); ?></div>
        </div>
        <a href="?hv_logout=1" class="account-logout">Logout</a>
      </div>
      <div class="account-orders">
        <div style="margin-bottom:1.8rem;">
          <button onclick="closeAccount();openTrackOrder();" style="width:100%;background:var(--color-dark);color:var(--color-accent);font-family:inherit;font-size:1.2rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;padding:1.2rem;border:none;cursor:pointer;display:flex;align-items:center;justify-content:center;gap:.8rem;transition:all .3s;" onmouseover="this.style.background='var(--color-accent)';this.style.color='var(--color-dark)';" onmouseout="this.style.background='var(--color-dark)';this.style.color='var(--color-accent)';">
            <svg width="18" height="18" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round"><circle cx="11" cy="11" r="8"/><line x1="21" y1="21" x2="16.65" y2="16.65"/></svg>
            Track Your Order
          </button>
        </div>
        <h3>Order History</h3>
        <?php if(empty($user_orders)): ?>
          <div class="no-orders">
            <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1">
              <path d="M6 2L3 6v14a2 2 0 002 2h14a2 2 0 002-2V6l-3-4zM3 6h18M16 10a4 4 0 01-8 0"/>
            </svg>
            <p>No orders yet. Start shopping!</p>
          </div>
        <?php else: ?>
          <?php foreach($user_orders as $o): ?>
          <div class="order-card">
            <div class="order-card__top">
              <span class="order-card__id"><?php echo htmlspecialchars($o['order_id']); ?></span>
              <span class="order-status"><?php echo htmlspecialchars($o['status']); ?></span>
            </div>
            <div class="order-card__date"><?php echo htmlspecialchars($o['created_at']); ?></div>
            <div class="order-card__items"><?php echo nl2br(htmlspecialchars($o['items'])); ?></div>
            <div class="order-card__footer">
              <span class="order-card__total"><?php echo htmlspecialchars($o['total']); ?></span>
              <span class="order-card__pay"><?php echo htmlspecialchars($o['payment']); ?></span>
            </div>
          </div>
          <?php endforeach; ?>
        <?php endif; ?>
      </div>
      <?php else: ?>
      <div style="padding:3rem 2.4rem;text-align:center;">
        <p style="font-size:1.4rem;color:rgba(31,31,31,.6);margin-bottom:2rem;">Sign in to view your orders.</p>
        <button onclick="closeAccount();openAuth('login');" class="pd-atc-btn" style="max-width:260px;margin:0 auto 1.4rem;">Log In / Sign Up</button>
        <button onclick="closeAccount();openTrackOrder();" style="display:block;width:260px;margin:0 auto;background:none;border:1.5px solid rgba(31,31,31,.2);color:rgba(31,31,31,.6);font-family:inherit;font-size:1.2rem;font-weight:700;letter-spacing:.1em;text-transform:uppercase;padding:1.1rem;cursor:pointer;transition:all .2s;" onmouseover="this.style.background='var(--color-dark)';this.style.color='#fff';" onmouseout="this.style.background='none';this.style.color='rgba(31,31,31,.6)';">Track Your Order</button>
      </div>
      <?php endif; ?>
    </div>
  </div>

  <!-- ===== PRODUCT DETAIL MODAL ===== -->
  <div class="pd-overlay" id="pdOverlay">
    <div class="pd-box" id="pdBox">
      <button class="pd-close" onclick="closePD()">×</button>
      <div class="pd-gallery">
        <img id="pd-img" src="" alt="">
        <div class="pd-badge-wrap" id="pd-badges"></div>
      </div>
      <div class="pd-info">
        <div class="pd-category" id="pd-category"></div>
        <div class="pd-name" id="pd-name"></div>
        <div class="pd-price-wrap">
          <div class="pd-price" id="pd-price"></div>
          <div class="pd-price-old" id="pd-price-old" style="display:none;"></div>
        </div>
        <div class="pd-tax">Incl. of all taxes</div>
        <div class="pd-desc" id="pd-desc"></div>

        <!-- Sizes -->
        <div id="pd-size-block">
          <div class="pd-section-label">Select Size</div>
          <div class="pd-sizes" id="pd-sizes"></div>
        </div>

        <!-- Qty -->
        <div>
          <div class="pd-section-label">Quantity</div>
          <div class="pd-qty-row">
            <button onclick="pdQty(-1)">−</button>
            <span id="pd-qty">1</span>
            <button onclick="pdQty(1)">+</button>
          </div>
        </div>

        <button class="pd-atc-btn" id="pd-atc-btn" onclick="pdAddToCart()">Add to Cart →</button>

        <div class="pd-features" id="pd-features"></div>
      </div>
    </div>
  </div>
  <!-- ===== CHECKOUT MODAL ===== -->
  <div class="checkout-overlay" id="coOverlay">
    <div class="checkout-box">
      <button class="checkout-box__close" onclick="closeCO()">×</button>
      <div class="checkout-head">
        <h2>Complete Your Order</h2>
        <p>Fill in your details below to place your order.</p>
      </div>
      <div class="checkout-body">
        <div class="co-summary">
          <h3>Order Summary</h3>
          <div id="coItems"></div>
          <div class="co-total"><span>Total</span><span id="coTotal">₹ 0</span></div>
        </div>
        <?php if($order_error): ?><div class="co-err"><?php echo $order_error; ?></div><?php endif; ?>
        <form method="POST" id="coForm">
          <input type="hidden" name="helvetica_order" value="1">
          <input type="hidden" name="order_items" id="coItemsInput">
          <input type="hidden" name="order_total" id="coTotalInput">
          <input type="hidden" name="payment_method" id="coPayInput" value="COD">
          <div class="co-group"><label>Full Name *</label><input type="text" name="customer_name" placeholder="Your full name" required value="<?php echo htmlspecialchars($_POST['customer_name']??'');?>"></div>
          <div class="co-2col">
            <div class="co-group"><label>Email *</label><input type="email" name="customer_email" placeholder="you@email.com" required value="<?php echo htmlspecialchars($_POST['customer_email']??'');?>"></div>
            <div class="co-group"><label>Phone *</label><input type="tel" name="customer_phone" placeholder="+91 00000 00000" required value="<?php echo htmlspecialchars($_POST['customer_phone']??'');?>"></div>
          </div>
          <div class="co-group"><label>Address *</label><input type="text" name="customer_address" placeholder="House / Street / Area" required value="<?php echo htmlspecialchars($_POST['customer_address']??'');?>"></div>
          <div class="co-2col">
            <div class="co-group"><label>City *</label><input type="text" name="customer_city" placeholder="City" required value="<?php echo htmlspecialchars($_POST['customer_city']??'');?>"></div>
            <div class="co-group"><label>Pincode *</label><input type="text" name="customer_pincode" placeholder="Pincode" required maxlength="6" value="<?php echo htmlspecialchars($_POST['customer_pincode']??'');?>"></div>
          </div>
          <div class="co-group">
            <label>Payment Method</label>
            <div class="pay-opts">
              <div class="pay-opt active" id="pCOD" onclick="setPay('COD')"><span class="pi"></span><span class="pl">Cash on Delivery</span></div>
              <div class="pay-opt" id="pUPI" onclick="setPay('UPI')"><span class="pi"></span><span class="pl">UPI / QR Pay</span></div>
            </div>
          </div>
          <div class="qr-box" id="qrBox">
            <h4>Scan & Pay via UPI</h4>
            <p>Scan with GPay, PhonePe, Paytm or any UPI app</p>
            <img id="qrImg" src="" alt="UPI QR" width="210" height="210">
            <div><div class="upi-id">8490986234@ptaxis</div></div>
            <p style="margin-top:.8rem;font-size:1.05rem;color:rgba(31,31,31,.5);">Take a screenshot after payment to +918490986234, after that only your order will be confirmed.</p>
          </div>
          <button type="button" class="co-submit" id="placeOrderBtn" onclick="helveticaPlaceOrder()">Place Order →</button>
        </form>
      </div>
    </div>
  </div>

  <!-- ===== ORDER SUCCESS ===== -->
  <div class="success-overlay <?php echo $order_success?'open':''; ?>" id="successOverlay">
    <div class="success-box">
      <div class="success-check"></div>
      <h2>Order Placed!</h2>
      <p>Thank you for shopping with <strong>HELVETICA</strong>.</p>
      <p>Confirmation sent to your email.</p>
      <div class="success-id" id="successId"><?php echo $order_success ? htmlspecialchars($order_data['order_id']) : '—'; ?></div>
      <p style="font-size:1.1rem;color:rgba(31,31,31,.5);">We'll notify you once your order is shipped.</p><br>
      <button class="success-btn" onclick="closeSuccess()">Continue Shopping</button>
    </div>
  </div>

  <!-- ===== JAVASCRIPT ===== -->
  <script data-cfasync="false" src="/cdn-cgi/scripts/5c5dd728/cloudflare-static/email-decode.min.js"></script><script>
    document.querySelectorAll('section, .footer-top-section, .trust-icon-section, .footer, .product-grid .grid__item').forEach(function(node) {
      node.classList.add('zt-reveal');
    });

    if ('IntersectionObserver' in window) {
      const revealObserver = new IntersectionObserver(function(entries) {
        entries.forEach(function(entry) {
          if (entry.isIntersecting) {
            entry.target.classList.add('is-visible');
            revealObserver.unobserve(entry.target);
          }
        });
      }, { threshold: 0.12, rootMargin: '0px 0px -30px 0px' });

      document.querySelectorAll('.zt-reveal').forEach(function(node) {
        revealObserver.observe(node);
      });
    } else {
      document.querySelectorAll('.zt-reveal').forEach(function(node) {
        node.classList.add('is-visible');
      });
    }

    // Sticky header shadow
    const headerWrapper = document.getElementById('header-wrapper');
    window.addEventListener('scroll', () => {
      if (window.scrollY > 10) {
        headerWrapper.classList.add('scrolled');
      } else {
        headerWrapper.classList.remove('scrolled');
      }
    });

    // Mobile drawer
    const drawerOpenBtn = document.getElementById('drawer-open-btn');
    const drawerCloseBtn = document.getElementById('drawer-close-btn');
    const drawer = document.getElementById('menu-drawer');
    const drawerOverlay = document.getElementById('drawer-overlay');

    function openDrawer() {
      drawer.classList.add('active');
      drawerOverlay.classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    function closeDrawer() {
      drawer.classList.remove('active');
      drawerOverlay.classList.remove('active');
      document.body.style.overflow = '';
    }

    if (drawerOpenBtn) drawerOpenBtn.addEventListener('click', openDrawer);
    if (drawerCloseBtn) drawerCloseBtn.addEventListener('click', closeDrawer);
    if (drawerOverlay) drawerOverlay.addEventListener('click', closeDrawer);

    // Show mobile hamburger on small screens
    function checkWidth() {
      if (window.innerWidth <= 990) {
        if (drawerOpenBtn) drawerOpenBtn.style.display = 'flex';
      } else {
        if (drawerOpenBtn) drawerOpenBtn.style.display = 'none';
        closeDrawer();
      }
    }
    checkWidth();
    window.addEventListener('resize', checkWidth);

    // Drawer tabs
    document.querySelectorAll('.drawer-tab').forEach(tab => {
      tab.addEventListener('click', () => {
        const target = tab.dataset.tab;
        document.querySelectorAll('.drawer-tab').forEach(t => t.classList.remove('active'));
        document.querySelectorAll('.drawer-tab-content').forEach(c => c.classList.remove('active'));
        tab.classList.add('active');
        document.getElementById('tab-' + target)?.classList.add('active');
      });
    });

    // Drawer accordion
    function toggleAccordion(btn) {
      btn.classList.toggle('active');
      const submenu = btn.nextElementSibling;
      if (submenu) submenu.classList.toggle('active');
    }

    // ===== SEARCH FUNCTIONALITY =====
    const searchBtn = document.getElementById('search-btn');
    const searchModal = document.getElementById('search-modal');
    const searchInput = document.getElementById('search-input');
    const searchResults = document.getElementById('search-results');

    // Full searchable catalogue — products + categories
    const SEARCH_CATALOGUE = [
      // Products
      {type:'product',name:'Essential Oversized Tee',category:'T-Shirt',sub:'tshirts',price:'₹1,299',url:'?page=tshirts',img:'/wp-content/themes/hell/media/imgi_349_f0aff27fb1be61447516bd12346fae11ce010501.jpg'},
      {type:'product',name:'Classic Drop Oversized Tee',category:'T-Shirt',sub:'tshirts',price:'₹1,199',url:'?page=tshirts',img:'/wp-content/themes/hell/media/imgi_586_46888f878d5547c2f8c59de05ec960e213a1b52b.jpg'},
      {type:'product',name:'Arch Logo Hoodie',category:'Hoodie',sub:'hoodies',price:'₹2,199',url:'?page=hoodies',img:'/wp-content/themes/hell/media/imgi_330_4a6ea369437d2ae71b13159a4dfe25022bebf2fd.jpg'},
      {type:'product',name:'Helvetica Oxford Shirt',category:'Shirt',sub:'shirts',price:'₹1,799',url:'?page=shirts',img:'/wp-content/themes/hell/media/imgi_626_3f5c4f6198ab99addeb933613ab5261a650fff95.jpg'},
      {type:'product',name:'Urban Zip Sweatshirt',category:'Sweatshirt',sub:'upper-wear',price:'₹2,499',url:'?page=upper-wear',img:'/wp-content/themes/hell/media/imgi_330_4a6ea369437d2ae71b13159a4dfe25022bebf2fd.jpg'},
      {type:'product',name:'Noir Bomber Jacket',category:'Jacket',sub:'jackets',price:'₹3,499',url:'?page=jackets',img:'/wp-content/themes/hell/media/imgi_712_e2a4c45def96b2bbe5b1a730c919ac5d00fe8ae0.jpg'},
      {type:'product',name:'Indigo Slim Jeans',category:'Jeans',sub:'jeans',price:'₹2,199',url:'?page=jeans',img:'/wp-content/themes/hell/media/bottom-wear3.jpg'},
      {type:'product',name:'Cargo Shorts',category:'Shorts',sub:'shorts',price:'₹1,499',url:'?page=shorts',img:'/wp-content/themes/hell/media/bottom-wear5.jpg'},
      {type:'product',name:'Tech Trousers',category:'Trousers',sub:'trousers',price:'₹1,899',url:'?page=trousers',img:'/wp-content/themes/hell/media/bottom-wear1.jpg'},
      {type:'product',name:'Flex Joggers',category:'Joggers',sub:'joggers',price:'₹1,699',url:'?page=joggers',img:'/wp-content/themes/hell/media/bottom-wear2.jpg'},
      {type:'product',name:'Wide Leg Pants',category:'Trousers',sub:'trousers',price:'₹2,299',url:'?page=trousers',img:'/wp-content/themes/hell/media/bottom-wear4.jpg'},
      {type:'product',name:'H-Logo Cap',category:'Cap',sub:'caps',price:'₹799',url:'?page=caps',img:'/wp-content/themes/hell/media/ImgHunt_Urbanmonkey_20260302_evolve-24lst213-blk-808114.jpeg'},
      {type:'product',name:'Street Watch',category:'Watch',sub:'watches',price:'₹3,299',url:'?page=watches',img:'/wp-content/themes/hell/media/ImgHunt_Rolex_20260302_professional-watches-cosmograph-daytona-myth-push-watch-first_cosmograph_daytona_1963.jpeg'},
      {type:'product',name:'Tactical Backpack',category:'Bag',sub:'bags',price:'₹2,999',url:'?page=bags',img:'/wp-content/themes/hell/media/ImgHunt_Safaribags_20260306_02copy_e1ac5ffd-2768-4ff6-9d9a-f2a075e4a5b6.jpeg'},
      {type:'product',name:'Chain Necklace',category:'Jewelry',sub:'jewelry',price:'₹1,499',url:'?page=jewelry',img:"/wp-content/themes/hell/media/Mens Necklace - Mini Lapis Lazuli Silver Pendant Necklace For Men - Lapis Necklace , Mens Jewelry, Silver Chain Pendant - By Twistedpendant.jpeg"},
      {type:'product',name:'Mini Sling Bag',category:'Bag',sub:'bags',price:'₹999',url:'?page=bags',img:"/wp-content/themes/hell/media/Sling Medium Borsttas _ Gorpcore.jpeg"},
      {type:'product',name:'Graphic Arch Tee',category:'T-Shirt',sub:'tshirts',price:'₹1,399',url:'?page=tshirts',img:''},
      {type:'product',name:'Washed Black Tee',category:'T-Shirt',sub:'tshirts',price:'₹1,249',url:'?page=tshirts',img:''},
      {type:'product',name:'Minimal Logo Tee',category:'T-Shirt',sub:'tshirts',price:'₹1,099',url:'?page=tshirts',img:''},
      {type:'product',name:'Helvetica Pullover Hoodie',category:'Hoodie',sub:'hoodies',price:'₹2,399',url:'?page=hoodies',img:''},
      {type:'product',name:'Oversized Zip Hoodie',category:'Hoodie',sub:'hoodies',price:'₹2,699',url:'?page=hoodies',img:''},
      {type:'product',name:'Washed Fleece Hoodie',category:'Hoodie',sub:'hoodies',price:'₹2,099',url:'?page=hoodies',img:''},
      {type:'product',name:'Drop Shoulder Hoodie',category:'Hoodie',sub:'hoodies',price:'₹2,299',url:'?page=hoodies',img:''},
      {type:'product',name:'Windbreaker Jacket',category:'Jacket',sub:'jackets',price:'₹3,299',url:'?page=jackets',img:''},
      {type:'product',name:'Tactical Shell Jacket',category:'Jacket',sub:'jackets',price:'₹3,899',url:'?page=jackets',img:''},
      {type:'product',name:'Fleece Overshirt',category:'Jacket',sub:'jackets',price:'₹2,799',url:'?page=jackets',img:''},
      {type:'product',name:'Denim Chore Coat',category:'Jacket',sub:'jackets',price:'₹3,199',url:'?page=jackets',img:''},
      {type:'product',name:'VR Utility Jacket',category:'Jacket',sub:'jackets',price:'₹3,599',url:'?page=jackets',img:'/wp-content/themes/hell/media/jac.jpg'},
      {type:'product',name:'Relaxed Linen Shirt',category:'Shirt',sub:'shirts',price:'₹1,999',url:'?page=shirts',img:''},
      {type:'product',name:'Cuban Collar Shirt',category:'Shirt',sub:'shirts',price:'₹1,699',url:'?page=shirts',img:''},
      {type:'product',name:'Black Skinny Jeans',category:'Jeans',sub:'jeans',price:'₹2,099',url:'?page=jeans',img:''},
      {type:'product',name:'Distressed Straight Jeans',category:'Jeans',sub:'jeans',price:'₹2,399',url:'?page=jeans',img:''},
      {type:'product',name:'Light Wash Baggy Jeans',category:'Jeans',sub:'jeans',price:'₹2,299',url:'?page=jeans',img:''},
      {type:'product',name:'Athletic Shorts',category:'Shorts',sub:'shorts',price:'₹1,299',url:'?page=shorts',img:''},
      {type:'product',name:'Bermuda Shorts',category:'Shorts',sub:'shorts',price:'₹1,399',url:'?page=shorts',img:''},
      {type:'product',name:'Chino Trousers',category:'Trousers',sub:'trousers',price:'₹1,999',url:'?page=trousers',img:''},
      {type:'product',name:'Pleated Trousers',category:'Trousers',sub:'trousers',price:'₹2,199',url:'?page=trousers',img:''},
      {type:'product',name:'Tech Joggers',category:'Joggers',sub:'joggers',price:'₹1,899',url:'?page=joggers',img:''},
      {type:'product',name:'Slim Joggers',category:'Joggers',sub:'joggers',price:'₹1,599',url:'?page=joggers',img:''},
      {type:'product',name:'Cargo Joggers',category:'Joggers',sub:'joggers',price:'₹1,999',url:'?page=joggers',img:''},
      {type:'product',name:'Helvetica Dad Hat',category:'Cap',sub:'caps',price:'₹699',url:'?page=caps',img:''},
      {type:'product',name:'Arch Snapback',category:'Cap',sub:'caps',price:'₹849',url:'?page=caps',img:''},
      {type:'product',name:'Bucket Hat',category:'Cap',sub:'caps',price:'₹749',url:'?page=caps',img:''},
      {type:'product',name:'Minimal Leather Watch',category:'Watch',sub:'watches',price:'₹2,999',url:'?page=watches',img:''},
      {type:'product',name:'Digital Sport Watch',category:'Watch',sub:'watches',price:'₹2,499',url:'?page=watches',img:''},
      {type:'product',name:'Chronograph Watch',category:'Watch',sub:'watches',price:'₹4,299',url:'?page=watches',img:''},
      {type:'product',name:'Tote Bag',category:'Bag',sub:'bags',price:'₹1,299',url:'?page=bags',img:''},
      {type:'product',name:'Crossbody Bag',category:'Bag',sub:'bags',price:'₹1,599',url:'?page=bags',img:''},
      {type:'product',name:'Duffel Bag',category:'Bag',sub:'bags',price:'₹2,199',url:'?page=bags',img:''},
      {type:'product',name:'Chunky Ring',category:'Jewelry',sub:'jewelry',price:'₹899',url:'?page=jewelry',img:''},
      {type:'product',name:'Cuban Link Bracelet',category:'Jewelry',sub:'jewelry',price:'₹1,299',url:'?page=jewelry',img:''},
      {type:'product',name:'Stud Earrings',category:'Jewelry',sub:'jewelry',price:'₹699',url:'?page=jewelry',img:''},
      {type:'product',name:'H-Logo Pendant',category:'Jewelry',sub:'jewelry',price:'₹1,199',url:'?page=jewelry',img:''},
      {type:'product',name:'Loewe Cloudtilt Sneaker',category:'Shoe',sub:'shoes',price:'₹79,999',url:'?page=shoes',img:'/wp-content/themes/hell/media/shoe-loewe-cloudtilt-neon-yellow.jpg'},
      {type:'product',name:'Tods Kate Loafers',category:'Shoe',sub:'shoes',price:'₹68,999',url:'?page=shoes',img:'/wp-content/themes/hell/media/shoe-tods-kate-loafers-black.jpg'},
      // Categories
      {type:'category',name:'Upper Wear',desc:'Tees, hoodies, jackets & shirts',url:'?page=upper-wear',emoji:''},
      {type:'category',name:'T-Shirts',desc:'Premium oversized & graphic tees',url:'?page=tshirts',emoji:''},
      {type:'category',name:'Hoodies',desc:'Heavyweight fleece for cold days',url:'?page=hoodies',emoji:''},
      {type:'category',name:'Jackets',desc:'Bombers, windbreakers & more',url:'?page=jackets',emoji:''},
      {type:'category',name:'Shirts',desc:'Oxford, linen & printed shirts',url:'?page=shirts',emoji:''},
      {type:'category',name:'Lower Wear',desc:'Jeans, shorts, trousers & joggers',url:'?page=lower-wear',emoji:''},
      {type:'category',name:'Jeans',desc:'Slim, baggy & distressed denim',url:'?page=jeans',emoji:''},
      {type:'category',name:'Shorts',desc:'Cargo, athletic & denim shorts',url:'?page=shorts',emoji:''},
      {type:'category',name:'Trousers',desc:'Tech, chino & wide leg trousers',url:'?page=trousers',emoji:''},
      {type:'category',name:'Joggers',desc:'Fleece & tech joggers',url:'?page=joggers',emoji:''},
      {type:'category',name:'Accessories',desc:'Caps, watches, bags, jewelry & shoes',url:'?page=accessories',emoji:''},
      {type:'category',name:'Caps & Hats',desc:'Snapbacks, dad hats & bucket hats',url:'?page=caps',emoji:''},
      {type:'category',name:'Watches',desc:'Street & minimal timepieces',url:'?page=watches',emoji:'⌚'},
      {type:'category',name:'Bags',desc:'Backpacks, slings & totes',url:'?page=bags',emoji:''},
      {type:'category',name:'Jewelry',desc:'Chains, rings & pendants',url:'?page=jewelry',emoji:''},
      {type:'category',name:'Shoes',desc:'Sneakers, loafers, flats & VR-ready footwear',url:'?page=shoes',emoji:''},
    ];

    function doSearch(q) {
      if (!q || q.length < 2) { searchResults.innerHTML = ''; return; }
      const ql = q.toLowerCase();
      const matches = SEARCH_CATALOGUE.filter(item =>
        item.name.toLowerCase().includes(ql) ||
        (item.category||'').toLowerCase().includes(ql) ||
        (item.desc||'').toLowerCase().includes(ql) ||
        (item.sub||'').toLowerCase().includes(ql)
      ).slice(0, 12);

      if (!matches.length) {
        searchResults.innerHTML = '<p style="color:#999;font-size:1.3rem;padding:1rem 0;">No results found for "' + q + '"</p>';
        return;
      }

      const cats = matches.filter(m=>m.type==='category');
      const prods = matches.filter(m=>m.type==='product');

      let html = '';
      if (cats.length) {
        html += '<div style="font-size:1.05rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#999;margin-bottom:.8rem;">Categories</div>';
        cats.forEach(c => {
          html += `<a href="${c.url}" style="display:flex;align-items:center;gap:1.2rem;padding:1rem 0;border-bottom:1px solid #f0f0f0;text-decoration:none;color:#1f1f1f;" onclick="document.getElementById('search-modal').classList.remove('active')">
            <span style="font-size:2.4rem;width:4rem;text-align:center;">${c.emoji}</span>
            <div>
              <div style="font-size:1.4rem;font-weight:700;">${c.name}</div>
              <div style="font-size:1.1rem;color:#999;">${c.desc}</div>
            </div>
            <svg style="margin-left:auto;flex-shrink:0;" width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#ccc" stroke-width="2"><path d="M9 18l6-6-6-6"/></svg>
          </a>`;
        });
      }
      if (prods.length) {
        if (cats.length) html += '<div style="margin-top:1.2rem;"></div>';
        html += '<div style="font-size:1.05rem;font-weight:700;letter-spacing:.12em;text-transform:uppercase;color:#999;margin-bottom:.8rem;">Products</div>';
        prods.forEach(p => {
          const imgHtml = p.img
            ? `<img src="${p.img}" style="width:5rem;height:5rem;object-fit:cover;flex-shrink:0;background:#f0f0f0;" onerror="this.style.display='none'">`
            : `<div style="width:5rem;height:5rem;background:#f0f0f0;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:1.8rem;"></div>`;
          html += `<a href="${p.url}" style="display:flex;align-items:center;gap:1.2rem;padding:.8rem 0;border-bottom:1px solid #f0f0f0;text-decoration:none;color:#1f1f1f;" onclick="document.getElementById('search-modal').classList.remove('active')">
            ${imgHtml}
            <div style="flex:1;">
              <div style="font-size:1.3rem;font-weight:600;">${p.name}</div>
              <div style="font-size:1.1rem;color:#999;">${p.category}</div>
            </div>
            <div style="font-size:1.3rem;font-weight:700;color:#0c1a2b;flex-shrink:0;">${p.price}</div>
          </a>`;
        });
      }
      searchResults.innerHTML = html;
    }

    if (searchBtn) {
      searchBtn.addEventListener('click', () => {
        searchModal.classList.add('active');
        searchInput?.focus();
      });
    }
    if (searchInput) {
      searchInput.addEventListener('input', () => doSearch(searchInput.value.trim()));
      searchInput.addEventListener('keydown', (e) => {
        if (e.key === 'Enter') {
          const first = searchResults.querySelector('a');
          if (first) { window.location.href = first.href; }
        }
      });
    }
    if (searchModal) {
      searchModal.addEventListener('click', (e) => {
        if (e.target === searchModal) searchModal.classList.remove('active');
      });
      document.addEventListener('keydown', (e) => {
        if (e.key === 'Escape') searchModal.classList.remove('active');
      });
    }

    // ===== CONTACT US MODAL =====
    window.openContactUs = function() {
      document.getElementById('contactOverlay').classList.add('open');
      document.body.style.overflow = 'hidden';
    };
    window.closeContactUs = function() {
      document.getElementById('contactOverlay').classList.remove('open');
      document.body.style.overflow = '';
    };
    document.getElementById('contactOverlay')?.addEventListener('click', function(e){
      if (e.target === this) closeContactUs();
    });

    // ===== TRACK ORDER MODAL =====
    window.openTrackOrder = function() {
      const overlay = document.getElementById('trackOrderOverlay');
      if (overlay) {
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
        // Reset state
        document.getElementById('trackResult').style.display = 'none';
        document.getElementById('trackErrorMsg').style.display = 'none';
        const inp = document.getElementById('trackInput');
        if (inp) { inp.value = ''; inp.style.borderColor = '#e0e0e0'; }
      }
    };
    window.closeTrackOrder = function() {
      const overlay = document.getElementById('trackOrderOverlay');
      if (overlay) {
        overlay.style.display = 'none';
        document.body.style.overflow = '';
      }
    };
    window.doTrackOrder = function() {
      const inp = document.getElementById('trackInput');
      const errMsg = document.getElementById('trackErrorMsg');
      const resultBox = document.getElementById('trackResult');
      const orderId = (inp ? inp.value.trim().toUpperCase() : '');

      errMsg.style.display = 'none';
      resultBox.style.display = 'none';

      if (!orderId) {
        errMsg.style.display = 'block';
        return;
      }

      // Derive a consistent "age score" from the order ID characters (same ID = same result)
      // Higher score = older order = further along in delivery
      var ageScore = 0;
      for (var ci = 0; ci < orderId.length; ci++) {
        ageScore += orderId.charCodeAt(ci);
      }
      // Use last 2 chars of ID to get a 0-99 range
      var ageSeed = ageScore % 100;

      // Map age seed to a status stage:
      // 0-14  -> Confirmed (brand new order, just placed)
      // 15-29 -> Processing (being picked & packed)
      // 30-49 -> Dispatched (handed to courier)
      // 50-69 -> In Transit (on the way)
      // 70-84 -> Out for Delivery (arriving today/tomorrow)
      // 85-99 -> Delivered (already delivered)
      const stages = [
        { status: 'Confirmed',        step: 1, etaDelta: 6,  msg: 'Your order has been confirmed and is being prepared for dispatch.' },
        { status: 'Processing',       step: 2, etaDelta: 5,  msg: 'Your order is being picked and packed at our warehouse.' },
        { status: 'Dispatched',       step: 3, etaDelta: 4,  msg: 'Your order has been handed over to our delivery partner.' },
        { status: 'In Transit',       step: 4, etaDelta: 2,  msg: 'Your package is on its way and will arrive soon.' },
        { status: 'Out for Delivery', step: 5, etaDelta: 0,  msg: 'Your package is out for delivery today. Please be available.' },
        { status: 'Delivered',        step: 6, etaDelta: -2, msg: 'Your order has been delivered. We hope you love it!' },
      ];

      var stageIdx = ageSeed < 15 ? 0
                  : ageSeed < 30 ? 1
                  : ageSeed < 50 ? 2
                  : ageSeed < 70 ? 3
                  : ageSeed < 85 ? 4
                  : 5;

      var stage = stages[stageIdx];
      var status = stage.status;
      var currentStep = stage.step;

      // Estimated delivery date
      var etaDate = new Date();
      etaDate.setDate(etaDate.getDate() + stage.etaDelta);
      var etaStr = etaDate.toLocaleDateString('en-IN', { day:'2-digit', month:'short', year:'numeric' });
      var etaLine = stage.status === 'Delivered'
        ? 'Delivered on: <strong>' + etaStr + '</strong> &mdash; ' + stage.msg
        : 'Estimated Delivery: <strong>' + etaStr + '</strong> &mdash; ' + stage.msg;

      var statusColor = status === 'Confirmed' ? '#b6ff3b'
                      : status === 'Delivered' ? '#0c1a2b'
                      : '#ff9900';
      var statusTextColor = status === 'Delivered' ? '#b6ff3b' : '#0c1a2b';

      const steps = ['Order Placed','Confirmed','Processing','Dispatched','In Transit','Out for Delivery','Delivered'];
      const stepMap = {'Confirmed':1,'Processing':2,'Dispatched':3,'In Transit':4,'Out for Delivery':5,'Delivered':6};

      let stepsHtml = '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:2rem;overflow-x:auto;gap:.5rem;">';
      steps.forEach(function(step, si) {
        const active = si <= currentStep;
        const isCurrent = si === currentStep;
        stepsHtml += '<div style="display:flex;flex-direction:column;align-items:center;flex:1;min-width:4rem;">';
        stepsHtml += '<div style="width:2.8rem;height:2.8rem;border-radius:50%;display:flex;align-items:center;justify-content:center;font-size:1rem;font-weight:800;background:' + (active?'#0c1a2b':'#eee') + ';color:' + (active?'#b6ff3b':'#999') + ';border:2px solid ' + (active?'#0c1a2b':'#ddd') + ';">' + (active ? '' : (si+1)) + '</div>';
        stepsHtml += '<div style="font-size:.85rem;color:' + (active?'#0c1a2b':'#bbb') + ';font-weight:' + (isCurrent?'800':'500') + ';text-align:center;margin-top:.4rem;line-height:1.3;">' + step + '</div>';
        stepsHtml += '</div>';
        if (si < steps.length - 1) {
          stepsHtml += '<div style="flex:1;height:2px;background:' + (si < currentStep ? '#0c1a2b' : '#eee') + ';min-width:.8rem;margin-bottom:2rem;"></div>';
        }
      });
      stepsHtml += '</div>';

      resultBox.innerHTML =
        '<div style="display:flex;align-items:center;justify-content:space-between;margin-bottom:1.6rem;">' +
          '<div>' +
            '<div style="font-size:1.1rem;color:#999;letter-spacing:.1em;text-transform:uppercase;font-weight:700;">Order ID</div>' +
            '<div style="font-size:1.8rem;font-weight:800;color:#0c1a2b;">' + orderId + '</div>' +
          '</div>' +
          '<div style="background:' + statusColor + ';color:' + statusTextColor + ';padding:.6rem 1.4rem;font-size:1.2rem;font-weight:800;letter-spacing:.08em;text-transform:uppercase;">' + status + '</div>' +
        '</div>' +
        stepsHtml +
        '<div style="font-size:1.2rem;color:#666;line-height:1.8;">' +
          '<div style="margin-top:1rem;padding:1rem;background:#fff9e6;border-left:3px solid #ff9900;">' +
            etaLine +
          '</div>' +
        '</div>';

      resultBox.style.display = 'block';
    };

    // Allow Enter key to trigger track
    document.getElementById('trackInput')?.addEventListener('keydown', function(e) {
      if (e.key === 'Enter') doTrackOrder();
    });

    document.getElementById('trackOrderOverlay')?.addEventListener('click', function(e){
      if (e.target === this) closeTrackOrder();
    });

    // Close mega menus when clicking outside
    document.addEventListener('click', (e) => {
      document.querySelectorAll('.mega-menu[open]').forEach(menu => {
        if (!menu.contains(e.target)) menu.removeAttribute('open');
      });
    });

    // Newsletter form
    document.querySelector('.email-form button')?.addEventListener('click', function() {
      const input = this.parentElement.querySelector('input');
      if (input.value && input.value.includes('@')) {
        this.textContent = 'Subscribed! ';
        this.style.background = '#1f1f1f';
        input.value = '';
        input.placeholder = 'Thank you!';
        setTimeout(() => {
          this.textContent = 'Subscribe';
          this.style.background = '';
          input.placeholder = 'Enter your email address';
        }, 3000);
      }
    });

    // Slide arrow scroll for product grids
    document.querySelectorAll('.slide-arrow').forEach(arrow => {
      arrow.addEventListener('click', () => {
        const section = arrow.closest('.featured-collection');
        const grid = section?.querySelector('.product-grid');
        if (!grid) return;
        const dir = arrow.dataset.dir === 'prev' ? -1 : 1;
        grid.scrollBy({ left: dir * grid.offsetWidth * 0.75, behavior: 'smooth' });
      });
    });

    // ===== CART SYSTEM =====
    const cartState = { items: [] };

    const cartDrawer = document.getElementById('cart-drawer');
    const cartDrawerOverlay = document.getElementById('cart-drawer-overlay');
    const cartDrawerClose = document.getElementById('cart-drawer-close');
    const cartItemsList = document.getElementById('cart-items-list');
    const cartEmptyState = document.getElementById('cart-empty-state');
    const cartDrawerFooter = document.getElementById('cart-drawer-footer');
    const cartSubtotalAmount = document.getElementById('cart-subtotal-amount');

    function openCartDrawer() {
      cartDrawer.classList.add('active');
      cartDrawerOverlay.classList.add('active');
      document.body.style.overflow = 'hidden';
    }

    function closeCartDrawer() {
      cartDrawer.classList.remove('active');
      cartDrawerOverlay.classList.remove('active');
      document.body.style.overflow = '';
    }

    if (cartDrawerClose) cartDrawerClose.addEventListener('click', closeCartDrawer);
    if (cartDrawerOverlay) cartDrawerOverlay.addEventListener('click', closeCartDrawer);

    // Open cart when clicking cart icon
    document.querySelector('.header__icon[aria-label="Cart"]') && 
      document.querySelector('.header__icon[aria-label="Cart"]').addEventListener('click', openCartDrawer);
    // Also attach to any cart link
    document.querySelectorAll('a[href="#cart"], .header__icon').forEach(el => {
      if (el.querySelector('.cart-count-bubble')) {
        el.style.cursor = 'pointer';
        el.addEventListener('click', (e) => { e.preventDefault(); openCartDrawer(); });
      }
    });

    function updateCartUI() {
      const cartBubble = document.querySelector('.cart-count-bubble span');
      const totalQty = cartState.items.reduce((s, i) => s + i.qty, 0);
      const totalPrice = cartState.items.reduce((s, i) => s + i.price * i.qty, 0);

      if (cartBubble) cartBubble.textContent = totalQty;

      // Update subtotal
      if (cartSubtotalAmount) cartSubtotalAmount.textContent = '₹ ' + totalPrice.toLocaleString('en-IN');

      // Show/hide empty state and footer
      if (cartState.items.length === 0) {
        cartEmptyState.style.display = 'flex';
        cartDrawerFooter.style.display = 'none';
      } else {
        cartEmptyState.style.display = 'none';
        cartDrawerFooter.style.display = 'block';
      }

      // Render items
      cartItemsList.innerHTML = '';
      cartState.items.forEach((item, idx) => {
        const div = document.createElement('div');
        div.className = 'cart-item';
        div.innerHTML = `
          <img class="cart-item__img" src="${item.img}" alt="${item.name}" onerror="this.style.background='#eee';this.src=''">
          <div class="cart-item__info">
            <div class="cart-item__name">${item.name}</div>
            <div class="cart-item__variant">${item.variant}</div>
            <div class="cart-item__price">₹ ${(item.price * item.qty).toLocaleString('en-IN')}</div>
            <div class="cart-item__qty">
              <button onclick="changeQty(${idx}, -1)">−</button>
              <span>${item.qty}</span>
              <button onclick="changeQty(${idx}, 1)">+</button>
            </div>
          </div>
          <button class="cart-item__remove" onclick="removeItem(${idx})" aria-label="Remove">×</button>
        `;
        cartItemsList.appendChild(div);
      });
    }

    window.changeQty = function(idx, delta) {
      cartState.items[idx].qty += delta;
      if (cartState.items[idx].qty <= 0) cartState.items.splice(idx, 1);
      updateCartUI();
    };

    window.removeItem = function(idx) {
      cartState.items.splice(idx, 1);
      updateCartUI();
    };

    function addToCart(btn) {
      const card = btn.closest('.card-wrapper') || btn.closest('.grid__item');
      const nameEl = card ? card.querySelector('.card__heading a') : null;
      const variantEl = card ? card.querySelector('.card__heading span') : null;
      const priceEl = card ? card.querySelector('.price-item--regular') : null;
      const imgEl = card ? card.querySelector('img') : null;

      const rawName = nameEl ? nameEl.childNodes[0].textContent.trim() : 'Product';
      const variant = variantEl ? variantEl.textContent.trim() : '';
      const priceText = priceEl ? priceEl.childNodes[0].textContent.replace(/[^0-9]/g, '') : '0';
      const price = parseInt(priceText) || 0;
      const imgSrc = imgEl ? imgEl.src : '';

      // Check if already in cart
      const existing = cartState.items.find(i => i.name === rawName && i.variant === variant);
      if (existing) {
        existing.qty++;
      } else {
        cartState.items.push({ name: rawName, variant, price, img: imgSrc, qty: 1 });
      }

      // Visual feedback on button
      btn.classList.add('added');
      btn.textContent = ' Added!';
      setTimeout(() => {
        btn.classList.remove('added');
        btn.textContent = 'Quick Add';
      }, 1500);

      // Animate cart bubble
      const cartBubble = document.querySelector('.cart-count-bubble');
      if (cartBubble) {
        cartBubble.style.transform = 'scale(1.5)';
        setTimeout(() => { cartBubble.style.transform = ''; }, 300);
      }

      updateCartUI();
      openCartDrawer();
    }

    // ===== QUICK ADD — properly separated from drag =====
    document.querySelectorAll('.quick-add-overlay').forEach(btn => {
      btn.addEventListener('click', (e) => {
        e.stopPropagation();
        addToCart(btn);
      });
    });

    // Click, hold and drag scroll for product grids
    document.querySelectorAll('.product-grid').forEach(grid => {
      let isDragging = false;
      let startX, startScrollLeft, dragMoved;

      grid.addEventListener('mousedown', (e) => {
        // Don't initiate drag if clicking quick-add
        if (e.target.closest('.quick-add-overlay')) return;
        isDragging = true;
        dragMoved = false;
        grid.style.cursor = 'grabbing';
        startX = e.clientX;
        startScrollLeft = grid.scrollLeft;
      });

      window.addEventListener('mousemove', (e) => {
        if (!isDragging) return;
        const dx = e.clientX - startX;
        if (Math.abs(dx) > 5) dragMoved = true;
        grid.scrollLeft = startScrollLeft - dx;
      });

      window.addEventListener('mouseup', () => {
        isDragging = false;
        grid.style.cursor = 'grab';
      });
    });

    document.querySelectorAll('.product-grid').forEach(g => g.style.cursor = 'grab');

    // Animate elements on scroll
    const observer = new IntersectionObserver((entries) => {
      entries.forEach(entry => {
        if (entry.isIntersecting) {
          entry.target.style.opacity = '1';
          entry.target.style.transform = 'translateY(0)';
        }
      });
    }, { threshold: 0.1 });

    document.querySelectorAll('.featured-collection, .secondary-banner, .footer-top-section').forEach(el => {
      el.style.opacity = '0';
      el.style.transform = 'translateY(30px)';
      el.style.transition = 'opacity 0.6s ease, transform 0.6s ease';
      observer.observe(el);
    });

    // ===== CHECKOUT =====
    (function() {
      var UPI = '8490986234@ptaxis'; // correct UPI

      window.openCO = function() {
        var overlay = document.getElementById('coOverlay');
        if (!overlay) { alert('Checkout not found'); return; }
        if (!cartState || cartState.items.length === 0) { alert('Your cart is empty!'); return; }
        var el = document.getElementById('coItems');
        var tot = 0;
        el.innerHTML = '';
        var txt = '';
        cartState.items.forEach(function(item) {
          var sub = item.price * item.qty;
          tot += sub;
          var r = document.createElement('div');
          r.className = 'co-row';
          r.innerHTML = '<span>' + item.name + (item.variant ? ' (' + item.variant + ')' : '') + ' x' + item.qty + '</span><span>&#8377; ' + sub.toLocaleString('en-IN') + '</span>';
          el.appendChild(r);
          txt += item.name + (item.variant ? ' (' + item.variant + ')' : '') + ' x' + item.qty + ' = Rs.' + sub + '\n';
        });
        document.getElementById('coTotal').textContent = '\u20b9 ' + tot.toLocaleString('en-IN');
        document.getElementById('coItemsInput').value = txt;
        document.getElementById('coTotalInput').value = '\u20b9 ' + tot.toLocaleString('en-IN');
        document.getElementById('coPayInput').value = 'COD';
        setPay('COD');
        genQR(tot);
        overlay.style.display = 'flex';
        document.body.style.overflow = 'hidden';
      };

      window.closeCO = function() {
        document.getElementById('coOverlay').style.display = 'none';
        document.body.style.overflow = '';
      };

      window.setPay = function(m) {
        document.getElementById('pCOD').classList.toggle('active', m === 'COD');
        document.getElementById('pUPI').classList.toggle('active', m === 'UPI');
        document.getElementById('coPayInput').value = m;
        document.getElementById('qrBox').classList.toggle('show', m === 'UPI');
      };

      function genQR(tot) {
        // Overridden below by new_scripts.html — this stub keeps openCO working
        var s = 'upi://pay?pa=' + encodeURIComponent(UPI) + '&pn=HELVETICA&am=' + tot + '&cu=INR&tn=HELVETICA+Order';
        document.getElementById('qrImg').src = 'https://api.qrserver.com/v1/create-qr-code/?data=' + encodeURIComponent(s) + '&size=220x220&margin=10&format=png';
        document.querySelector('.upi-id').textContent = UPI;
      }
      // closeSuccess defined once in new_scripts block below

      // close on overlay click
      document.getElementById('coOverlay').addEventListener('click', function(e) {
        if (e.target === this) closeCO();
      });

      // wire the checkout button directly (script is at bottom of body, DOM already ready)
      var _btn = document.getElementById('checkoutBtn');
      if (_btn) {
        _btn.onclick = function() { closeCartDrawer(); setTimeout(openCO, 300); };
      }
      // also catch any .cart-checkout-btn in case id missing
      document.querySelectorAll('.cart-checkout-btn').forEach(function(b) {
        b.onclick = function() { closeCartDrawer(); setTimeout(openCO, 300); };
      });

      <?php if($order_success): ?>
      document.getElementById('successOverlay').classList.add('open');
      document.body.style.overflow = 'hidden';
      <?php endif; ?>
    })();

  </script>

  <script>
  // ===== EMAILJS ORDER SYSTEM =====
  const EJS_SERVICE  = 'service_98esj1l';
  const EJS_TEMPLATE = 'template_5eowfnw';
  const EJS_KEY      = 'qZnOhCsMYITZNT4JE';
  const OWNER_MAIL   = 'teamhelvetica0@gmail.com';

  function helveticaGenerateOrderId() {
    return 'HV-' + Math.random().toString(36).substring(2,6).toUpperCase() +
          Math.random().toString(36).substring(2,6).toUpperCase();
  }

  window.helveticaPlaceOrder = function() {
    //  Grab form fields 
    const name    = document.querySelector('[name="customer_name"]')?.value.trim();
    const email   = document.querySelector('[name="customer_email"]')?.value.trim();
    const phone   = document.querySelector('[name="customer_phone"]')?.value.trim();
    const address = document.querySelector('[name="customer_address"]')?.value.trim();
    const city    = document.querySelector('[name="customer_city"]')?.value.trim();
    const pincode = document.querySelector('[name="customer_pincode"]')?.value.trim();
    const payment = document.getElementById('coPayInput')?.value || 'COD';
    const items   = document.getElementById('coItemsInput')?.value || '';
    const total   = document.getElementById('coTotal')?.textContent || '';

    //  Validate 
    if (!name || !email || !phone || !address || !city || !pincode) {
      showCoError('Please fill in all required fields.'); return;
    }
    if (!/^[^\s@]+@[^\s@]+\.[^\s@]+$/.test(email)) {
      showCoError('Please enter a valid email address.'); return;
    }
    if (!/^\d{6}$/.test(pincode)) {
      showCoError('Please enter a valid 6-digit pincode.'); return;
    }

    const orderId  = helveticaGenerateOrderId();
    const dateStr  = new Date().toLocaleString('en-IN', {
      day:'2-digit', month:'short', year:'numeric',
      hour:'2-digit', minute:'2-digit', hour12:true
    });

    //  Build message bodies 
    const customerMsg =
  `Hi ${name}!

  Thank you for shopping with HELVETICA 


  ORDER CONFIRMED

  Order ID  : ${orderId}
  Date      : ${dateStr}
  Payment   : ${payment}

  ITEMS ORDERED:
  ${items}
  TOTAL: ${total}

  DELIVERY TO:
  ${name}
  ${address}, ${city} – ${pincode}
  Phone: ${phone}

  We'll notify you once your order is shipped.
  For any queries reply to this email.

  – Team HELVETICA`;

    const ownerMsg =
  ` NEW ORDER RECEIVED


  Order ID  : ${orderId}
  Date      : ${dateStr}


  CUSTOMER DETAILS:
  Name    : ${name}
  Email   : ${email}
  Phone   : ${phone}
  Address : ${address}, ${city} – ${pincode}

  ITEMS:
  ${items}
  Total   : ${total}

  Payment : ${payment}`;

    //  Disable button & show loading 
    const btn = document.getElementById('placeOrderBtn');
    btn.disabled = true;
    btn.textContent = 'Sending...';
    clearCoError();

    //  Send email to CUSTOMER 
    emailjs.send(EJS_SERVICE, EJS_TEMPLATE, {
      to_email : email,
      to_name  : name,
      subject  : `Your HELVETICA Order Confirmed – ${orderId}`,
      message  : customerMsg,
      reply_to : OWNER_MAIL,
    }, EJS_KEY)
    .then(function() {
      //  Send email to OWNER 
      return emailjs.send(EJS_SERVICE, EJS_TEMPLATE, {
        to_email : OWNER_MAIL,
        to_name  : 'HELVETICA Team',
        subject  : ` NEW ORDER ${orderId} from ${name}`,
        message  : ownerMsg,
        reply_to : email,
      }, EJS_KEY);
    })
    .then(function() {
      //  Save order to PHP backend (for order history) 
      const formData = new FormData();
      formData.append('helvetica_order', '1');
      formData.append('order_items',      items);
      formData.append('order_total',      total);
      formData.append('payment_method',   payment);
      formData.append('customer_name',    name);
      formData.append('customer_email',   email);
      formData.append('customer_phone',   phone);
      formData.append('customer_address', address);
      formData.append('customer_city',    city);
      formData.append('customer_pincode', pincode);
      formData.append('order_id_override', orderId);
      fetch(window.location.href, { method:'POST', body: formData })
        .catch(function(){});  // silent — order history is bonus

      //  Show success popup 
      closeCO();
      document.getElementById('successId').textContent = orderId;
      document.getElementById('successOverlay').classList.add('open');
      document.body.style.overflow = 'hidden';
      if (cartState) { cartState.items = []; updateCartUI(); }

      btn.disabled = false;
      btn.textContent = 'Place Order →';
    })
    .catch(function(err) {
      console.error('EmailJS error:', err);
      btn.disabled = false;
      btn.textContent = 'Place Order →';
      showCoError('Could not send email. Please try again or contact us on WhatsApp.');
    });
  };

  function showCoError(msg) {
    let el = document.getElementById('coErrMsg');
    if (!el) {
      el = document.createElement('div');
      el.id = 'coErrMsg';
      el.className = 'co-err';
      document.getElementById('placeOrderBtn').insertAdjacentElement('beforebegin', el);
    }
    el.textContent = msg;
    el.style.display = 'block';
  }
  function clearCoError() {
    const el = document.getElementById('coErrMsg');
    if (el) el.style.display = 'none';
  }
  </script>

  </body>
  </html>
  <script>
  // ===== PRODUCT CATALOGUE DATA =====
  const PRODUCTS = [
    // UPPER WEAR — T-SHIRTS
    { id:'ts1', name:'Essential Oversized Tee', category:'T-Shirt', price:1299, img:'/wp-content/themes/hell/media/imgi_349_f0aff27fb1be61447516bd12346fae11ce010501.jpg', sizes:['XS','S','M','L','XL','XXL'], colors:['#1f1f1f','#ffffff','#b6ff3b'], desc:'Dropped shoulders, relaxed fit. Premium 280 GSM cotton that gets better with every wash.', features:['Premium 280 GSM cotton','Pre-washed & shrink-resistant','Dropped shoulder silhouette','Unisex fit'], badge:'NEW' },
    { id:'ts2', name:'Classic Drop Tee', category:'T-Shirt', price:1199, img:'/wp-content/themes/hell/media/imgi_586_46888f878d5547c2f8c59de05ec960e213a1b52b.jpg', sizes:['XS','S','M','L','XL','XXL'], colors:['#1f1f1f','#ffffff'], desc:'A timeless drop-shoulder tee in soft ringspun cotton. The perfect everyday essential.', features:['Ringspun cotton','Drop shoulder','Pre-shrunk','Unisex fit'] },
    { id:'ts3', name:'Graphic Arch Tee', category:'T-Shirt', price:1399, img:'/wp-content/themes/hell/media/tshirt3.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#b6ff3b'], desc:'Bold arch graphic print on 260 GSM cotton. Statement piece for every outfit.', features:['260 GSM cotton','Screen printed graphic','Oversize fit','Pre-washed'] },
    { id:'ts4', name:'Washed Black Tee', category:'T-Shirt', price:1249, img:'/wp-content/themes/hell/media/tshirt4.jpg', sizes:['XS','S','M','L','XL','XXL'], colors:['#1f1f1f'], desc:'Garment-washed black tee for that lived-in look. Effortlessly cool.', features:['Garment-washed finish','240 GSM cotton','Relaxed fit','Faded detail'], badge:'HOT' },
    { id:'ts5', name:'Minimal Logo Tee', category:'T-Shirt', price:1099, img:'/wp-content/themes/hell/media/tshirt5.jpg', sizes:['XS','S','M','L','XL','XXL'], colors:['#ffffff','#1f1f1f','#b6ff3b'], desc:'Clean minimal Helvetica logo tee. Soft, breathable and versatile.', features:['220 GSM combed cotton','Minimal logo embroidery','Regular fit','Machine washable'] },
    // HOODIES
    { id:'hd1', name:'Arch Logo Hoodie', category:'Hoodie', price:2199, img:'/wp-content/themes/hell/media/imgi_330_4a6ea369437d2ae71b13159a4dfe25022bebf2fd.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#e445ff'], desc:'Heavyweight 450 GSM fleece hoodie with kangaroo pocket and ribbed cuffs.', features:['450 GSM heavyweight fleece','Kangaroo pocket','Brushed interior','Helvetica arch embroidery'] },
    { id:'hd2', name:'Helvetica Pullover Hoodie', category:'Hoodie', price:2399, img:'/wp-content/themes/hell/media/hoodie2.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#ffffff'], desc:'Classic pullover hoodie in plush 400 GSM fleece. Warm, cozy and bold.', features:['400 GSM fleece','Pullover style','Ribbed cuffs & hem','Hidden drawstring'], badge:'NEW' },
    { id:'hd3', name:'Oversized Zip Hoodie', category:'Hoodie', price:2699, img:'/wp-content/themes/hell/media/hoodie3.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#533483'], desc:'Oversized zip-through hoodie. Ultimate streetwear layer.', features:['Full-zip closure','Oversized silhouette','Side pockets','Helvetica zip pull'] },
    { id:'hd4', name:'Washed Fleece Hoodie', category:'Hoodie', price:2099, img:'/wp-content/themes/hell/media/hoodie4.jpg', sizes:['S','M','L','XL','XXL'], colors:['#8b7355','#1f1f1f'], desc:'Garment-washed fleece hoodie for that vintage streetwear feel.', features:['Washed fleece','Vintage finish','Boxy fit','Tonal drawstring'], badge:'SALE' },
    { id:'hd5', name:'Drop Shoulder Hoodie', category:'Hoodie', price:2299, img:'/wp-content/themes/hell/media/hoodie5.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#b6ff3b'], desc:'Drop-shoulder construction with thick French terry. Relaxed and comfortable.', features:['French terry','Drop shoulder','Kangaroo pocket','Relaxed fit'] },
    // JACKETS
    { id:'jk1', name:'Noir Bomber Jacket', category:'Jacket', price:3499, oldPrice:4499, img:'/wp-content/themes/hell/media/imgi_712_e2a4c45def96b2bbe5b1a730c919ac5d00fe8ae0.jpg', sizes:['S','M','L','XL'], colors:['#1f1f1f'], desc:'Classic bomber silhouette in technical nylon. Timeless style for the streets.', features:['Technical nylon shell','Ribbed cuffs & collar','YKK zipper','Satin lining'], badge:'SALE' },
    { id:'jk2', name:'Windbreaker Jacket', category:'Jacket', price:3299, img:'/wp-content/themes/hell/media/jacket2.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#4facfe'], desc:'Lightweight windbreaker with packable design. Beats the weather in style.', features:['Water-resistant shell','Packable hood','Zip pockets','Helvetica reflective logo'], badge:'NEW' },
    { id:'jk3', name:'Tactical Shell Jacket', category:'Jacket', price:3899, img:'/wp-content/themes/hell/media/jacket3.jpg', sizes:['S','M','L','XL'], colors:['#1f1f1f','#556b2f'], desc:'Multi-pocket tactical shell jacket. Functional and fearless.', features:['Multi-pocket design','Water-resistant','Removable hood','Articulated sleeves'] },
    { id:'jk4', name:'Fleece Overshirt', category:'Jacket', price:2799, img:'/wp-content/themes/hell/media/jacket4.jpg', sizes:['S','M','L','XL','XXL'], colors:['#8b7355','#1f1f1f'], desc:'Soft sherpa fleece overshirt. Layer it or wear it solo.', features:['Sherpa fleece','Button-front','Chest pockets','Relaxed fit'] },
    { id:'jk5', name:'Denim Chore Coat', category:'Jacket', price:3199, img:'/wp-content/themes/hell/media/jacket5.jpg', sizes:['S','M','L','XL'], colors:['#1a3a6e','#1f1f1f'], desc:'Classic chore coat in rigid denim. Built to last, styled to stand out.', features:['12 oz rigid denim','Brass buttons','Chest & side pockets','Boxy fit'], badge:'HOT' },
    { id:'jk6', name:'VR Utility Jacket', category:'Jacket', price:3599, img:'/wp-content/themes/hell/media/jac.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#556b2f'], desc:'Utility-led jacket added for virtual try-on. Clean structure with an everyday streetwear shape.', features:['VR try-on enabled','Utility pocket layout','Structured fit','Layer-ready shell'], badge:'NEW', tryon:'https://wanna-clothes.ar.wanna.fashion/?mode=vto&showonboarding=3d&modelid=WNCLO01&startwithid=WNCLO01' },
    // SHIRTS
    { id:'sh1', name:'Helvetica Oxford Shirt', category:'Shirt', price:1799, img:'/wp-content/themes/hell/media/imgi_626_3f5c4f6198ab99addeb933613ab5261a650fff95.jpg', sizes:['S','M','L','XL'], colors:['#ffffff','#1f1f1f','#4facfe'], desc:'A clean silhouette in breathable Oxford cotton.', features:['100% Oxford cotton','Relaxed fit','Button-down collar','Machine washable'], badge:'HOT' },
    { id:'sh2', name:'Relaxed Linen Shirt', category:'Shirt', price:1999, img:'/wp-content/themes/hell/media/shirt2.jpg', sizes:['S','M','L','XL','XXL'], colors:['#ffffff','#f6d365'], desc:'Breathable linen shirt for warm days. Easy, breezy style.', features:['100% linen','Relaxed fit','Chest pocket','Coconut shell buttons'], badge:'NEW' },
    { id:'sh3', name:'Cuban Collar Shirt', category:'Shirt', price:1699, img:'/wp-content/themes/hell/media/shirt3.jpg', sizes:['S','M','L','XL'], colors:['#1f1f1f','#ffffff','#e445ff'], desc:'Retro-inspired Cuban collar short-sleeve shirt.', features:['Viscose blend','Camp collar','Relaxed silhouette','Tonal buttons'] },
    { id:'sh4', name:'Graphic Print Shirt', category:'Shirt', price:1899, img:'/wp-content/themes/hell/media/shirt4.jpg', sizes:['S','M','L','XL'], colors:['#1f1f1f'], desc:'All-over printed shirt with bold Helvetica graphics.', features:['Cotton twill','All-over print','Regular fit','Button-front'] },
    { id:'sh5', name:'Minimal Logo Shirt', category:'Shirt', price:1599, img:'/wp-content/themes/hell/media/shirt5.jpg', sizes:['S','M','L','XL','XXL'], colors:['#ffffff','#1f1f1f'], desc:'Clean minimal shirt with embroidered logo. Versatile everyday wear.', features:['Cotton poplin','Embroidered logo','Slim fit','Machine washable'], badge:'SALE' },
    // JEANS
    { id:'jn1', name:'Indigo Slim Jeans', category:'Jeans', price:2199, img:'/wp-content/themes/hell/media/bottom-wear3.jpg', sizes:['28','30','32','34','36'], colors:['#1a3a6e','#1f1f1f'], desc:'Slim-fit denim with just the right amount of stretch.', features:['98% cotton 2% elastane','Slim tapered fit','Indigo wash','5-pocket construction'], badge:'NEW' },
    { id:'jn2', name:'Black Skinny Jeans', category:'Jeans', price:2099, img:'/wp-content/themes/hell/media/jeans2.jpg', sizes:['28','30','32','34','36'], colors:['#1f1f1f'], desc:'Sleek black skinny jeans. A wardrobe staple for every season.', features:['Stretch denim','Skinny fit','Coated black finish','5-pocket'] },
    { id:'jn3', name:'Distressed Straight Jeans', category:'Jeans', price:2399, img:'/wp-content/themes/hell/media/jeans3.jpg', sizes:['28','30','32','34','36'], colors:['#1a3a6e'], desc:'Hand-distressed straight cut denim. Worn-in look, built-in attitude.', features:['Distressed details','Straight leg','Heavy wash','Copper hardware'], badge:'HOT' },
    { id:'jn4', name:'Light Wash Baggy Jeans', category:'Jeans', price:2299, img:'/wp-content/themes/hell/media/jeans4.jpg', sizes:['28','30','32','34','36'], colors:['#aacde8'], desc:'Relaxed baggy fit in light wash denim. The silhouette of the season.', features:['100% cotton denim','Baggy wide fit','Light stone wash','5-pocket'] },
    { id:'jn5', name:'Relaxed Fit Jeans', category:'Jeans', price:2149, img:'/wp-content/themes/hell/media/jeans5.jpg', sizes:['28','30','32','34','36'], colors:['#1a3a6e','#1f1f1f'], desc:'Easy relaxed fit denim for all-day comfort and style.', features:['Stretch cotton blend','Relaxed straight','Mid rise','YKK zip fly'], badge:'SALE' },
    // SHORTS
    { id:'st1', name:'Cargo Shorts', category:'Shorts', price:1499, img:'/wp-content/themes/hell/media/bottom-wear5.jpg', sizes:['S','M','L','XL','XXL'], colors:['#556b2f','#1f1f1f','#8b7355'], desc:'Functional cargo shorts with six pockets.', features:['6-pocket utility design','Mid-thigh length','Drawstring waistband','Ripstop cotton blend'] },
    { id:'st2', name:'Athletic Shorts', category:'Shorts', price:1299, img:'/wp-content/themes/hell/media/shorts2.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#b6ff3b'], desc:'Lightweight athletic shorts built for movement and style.', features:['Quick-dry fabric','Mesh lining','Elastic waistband','Side pockets'], badge:'NEW' },
    { id:'st3', name:'Bermuda Shorts', category:'Shorts', price:1399, img:'/wp-content/themes/hell/media/shorts3.jpg', sizes:['S','M','L','XL'], colors:['#1f1f1f','#8b7355'], desc:'Classic bermuda length in ripstop cotton. Comfortable and cool.', features:['Ripstop cotton','Bermuda length','Button fly','Side & back pockets'] },
    { id:'st4', name:'Denim Shorts', category:'Shorts', price:1599, img:'/wp-content/themes/hell/media/shorts4.jpg', sizes:['28','30','32','34'], colors:['#1a3a6e','#1f1f1f'], desc:'Cut-off denim shorts with raw hem. Summer essential.', features:['100% cotton denim','Raw cut hem','5-pocket','Button fly'], badge:'HOT' },
    { id:'st5', name:'Linen Shorts', category:'Shorts', price:1249, img:'/wp-content/themes/hell/media/shorts5.jpg', sizes:['S','M','L','XL','XXL'], colors:['#f6d365','#ffffff','#1f1f1f'], desc:'Breathable linen shorts for warm weather.', features:['100% linen','Elastic waistband','Side pockets','Relaxed fit'] },
    // TROUSERS
    { id:'tr1', name:'Tech Trousers', category:'Trousers', price:1899, oldPrice:2599, img:'/wp-content/themes/hell/media/bottom-wear1.jpg', sizes:['S','M','L','XL'], colors:['#1f1f1f','#2d4a6e'], desc:'High-performance tech trousers with moisture-wicking fabric.', features:['Moisture-wicking fabric','Zip ankle','Hidden waistband pocket','Slim tapered leg'], badge:'SALE' },
    { id:'tr2', name:'Wide Leg Pants', category:'Trousers', price:2299, img:'/wp-content/themes/hell/media/bottom-wear4.jpg', sizes:['S','M','L','XL'], colors:['#1f1f1f','#c0a080'], desc:'Bold wide-leg silhouette in woven twill. Make a statement from the waist down.', features:['Woven twill fabric','Wide-leg cut','Flat front design','Concealed zipper fly'], badge:'TRENDING' },
    { id:'tr3', name:'Chino Trousers', category:'Trousers', price:1999, img:'/wp-content/themes/hell/media/trousers3.jpg', sizes:['S','M','L','XL','XXL'], colors:['#c0a080','#1f1f1f','#2d4a6e'], desc:'Classic chino trousers with a slim tapered cut.', features:['Chino cotton twill','Slim tapered','Flat front','Side pockets'] },
    { id:'tr4', name:'Pleated Trousers', category:'Trousers', price:2199, img:'/wp-content/themes/hell/media/trousers4.jpg', sizes:['S','M','L','XL'], colors:['#1f1f1f','#8b7355'], desc:'Elegant pleated trousers for elevated streetwear looks.', features:['Poly-viscose blend','Double pleat','Wide leg','Zip fly'], badge:'NEW' },
    { id:'tr5', name:'Linen Trousers', category:'Trousers', price:1799, img:'/wp-content/themes/hell/media/trousers5.jpg', sizes:['S','M','L','XL','XXL'], colors:['#f6d365','#ffffff','#1f1f1f'], desc:'Lightweight linen trousers for warm days and cool evenings.', features:['100% linen','Elastic waistband','Side pockets','Relaxed fit'] },
    // JOGGERS
    { id:'jg1', name:'Flex Joggers', category:'Joggers', price:1699, img:'/wp-content/themes/hell/media/bottom-wear2.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#e445ff','#b6ff3b'], desc:'Super-soft 320 GSM loopback fleece joggers. Your new off-duty essential.', features:['320 GSM loopback fleece','Elasticated waistband','Side & back pockets','Relaxed tapered fit'] },
    { id:'jg2', name:'Tech Joggers', category:'Joggers', price:1899, img:'/wp-content/themes/hell/media/joggers2.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#2d4a6e'], desc:'Performance tech joggers that go from gym to street.', features:['Stretch tech fabric','Zip pockets','Tapered leg','Moisture-wicking'], badge:'NEW' },
    { id:'jg3', name:'Slim Joggers', category:'Joggers', price:1599, img:'/wp-content/themes/hell/media/joggers3.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#ffffff'], desc:'Slim-fit joggers in French terry. Clean and minimal.', features:['French terry','Slim tapered','Elastic waistband','Side pockets'] },
    { id:'jg4', name:'Washed Sweatpants', category:'Joggers', price:1799, img:'/wp-content/themes/hell/media/joggers4.jpg', sizes:['S','M','L','XL','XXL'], colors:['#8b7355','#1f1f1f'], desc:'Garment-washed sweatpants. Soft, broken-in comfort.', features:['Washed fleece','Relaxed fit','Ribbed ankle','Helvetica print'], badge:'HOT' },
    { id:'jg5', name:'Cargo Joggers', category:'Joggers', price:1999, img:'/wp-content/themes/hell/media/joggers5.jpg', sizes:['S','M','L','XL','XXL'], colors:['#556b2f','#1f1f1f'], desc:'Utility cargo joggers with side pockets for maximum functionality.', features:['Ripstop cotton','Cargo pockets','Elastic waistband','Tapered fit'] },
    // CAPS
    { id:'cp1', name:'H-Logo Cap', category:'Cap', price:799, img:'/wp-content/themes/hell/media/ImgHunt_Urbanmonkey_20260302_evolve-24lst213-blk-808114.jpeg', sizes:['One Size'], colors:['#1f1f1f','#ffffff','#b6ff3b'], desc:'Structured 6-panel snapback with embroidered H-logo on the front panel.', features:['6-panel structured cap','Flat brim','Snap closure','One size fits most'], badge:'NEW' },
    { id:'cp2', name:'Helvetica Dad Hat', category:'Cap', price:699, img:'/wp-content/themes/hell/media/cap2.jpg', sizes:['One Size'], colors:['#1f1f1f','#ffffff','#8b7355'], desc:'Unstructured dad hat with curved brim and adjustable strap.', features:['Unstructured','Curved brim','Adjustable strap','Helvetica embroidery'] },
    { id:'cp3', name:'Arch Snapback', category:'Cap', price:849, img:'/wp-content/themes/hell/media/cap3.jpg', sizes:['One Size'], colors:['#1f1f1f','#b6ff3b'], desc:'6-panel snapback with Arch logo embroidery. Statement headwear.', features:['6-panel','Snapback closure','Flat brim','Arch logo front'], badge:'HOT' },
    { id:'cp4', name:'Bucket Hat', category:'Cap', price:749, img:'/wp-content/themes/hell/media/cap4.jpg', sizes:['S/M','L/XL'], colors:['#1f1f1f','#ffffff','#556b2f'], desc:'Classic bucket hat. Perfect for every season.', features:['100% cotton','Wide brim','Interior sweatband','One size fits most'] },
    { id:'cp5', name:'5-Panel Camp Cap', category:'Cap', price:649, img:'/wp-content/themes/hell/media/cap5.jpg', sizes:['One Size'], colors:['#1f1f1f','#8b7355'], desc:'Minimal 5-panel camp cap for understated style.', features:['5-panel construction','Curved brim','Adjustable back','Minimal logo'] },
    // WATCHES
    { id:'wt1', name:'Street Watch', category:'Watch', price:3299, img:'/wp-content/themes/hell/media/ImgHunt_Rolex_20260302_professional-watches-cosmograph-daytona-myth-push-watch-first_cosmograph_daytona_1963.jpeg', sizes:['One Size'], colors:['#c0c0c0','#ffd700','#1f1f1f'], desc:'Minimalist street watch with stainless steel case and scratch-resistant glass.', features:['Stainless steel case','Scratch-resistant glass','Water resistant 30m','Japanese quartz movement'] },
    { id:'wt2', name:'Minimal Leather Watch', category:'Watch', price:2999, img:'/wp-content/themes/hell/media/watch2.jpg', sizes:['One Size'], colors:['#c0a080','#1f1f1f'], desc:'Clean minimal watch with genuine leather strap. Understated and elegant.', features:['Genuine leather strap','Mineral glass','Water resistant','Quartz movement'], badge:'NEW' },
    { id:'wt3', name:'Digital Sport Watch', category:'Watch', price:2499, img:'/wp-content/themes/hell/media/watch3.jpg', sizes:['One Size'], colors:['#1f1f1f','#b6ff3b'], desc:'Feature-packed digital sport watch. Built for the streets.', features:['Digital display','Stopwatch','Water resistant 50m','Silicone strap'] },
    { id:'wt4', name:'Classic Steel Watch', category:'Watch', price:3599, img:'/wp-content/themes/hell/media/watch4.jpg', sizes:['One Size'], colors:['#c0c0c0'], desc:'Premium stainless steel bracelet watch. Timeless and bold.', features:['Stainless steel bracelet','Sapphire crystal','100m water resistant','Swiss movement'], badge:'HOT' },
    { id:'wt5', name:'Chronograph Watch', category:'Watch', price:4299, img:'/wp-content/themes/hell/media/watch5.jpg', sizes:['One Size'], colors:['#1f1f1f','#c0c0c0'], desc:'Chronograph watch with three sub-dials. Precision for the fearless.', features:['Chronograph function','Stainless case','50m water resistance','Quartz movement'] },
    // BAGS
    { id:'bg1', name:'Tactical Backpack', category:'Bag', price:2999, img:'/wp-content/themes/hell/media/ImgHunt_Safaribags_20260306_02copy_e1ac5ffd-2768-4ff6-9d9a-f2a075e4a5b6.jpeg', sizes:['One Size'], colors:['#1f1f1f','#556b2f'], desc:'30L tactical backpack with padded straps and laptop sleeve. Built for the city.', features:['30L capacity','Padded laptop sleeve','Water-resistant exterior','Ergonomic straps'], badge:'HOT' },
    { id:'bg2', name:'Mini Sling Bag', category:'Bag', price:999, oldPrice:1299, img:'/wp-content/themes/hell/media/Sling Medium Borsttas _ Gorpcore.jpeg', sizes:['One Size'], colors:['#1f1f1f','#8b7355'], desc:'Compact sling bag for your essentials. Front zip pocket, adjustable strap.', features:['Water-resistant nylon','Adjustable strap','Front zip pocket','YKK zippers'], badge:'SALE' },
    { id:'bg3', name:'Tote Bag', category:'Bag', price:1299, img:'/wp-content/themes/hell/media/bag3.jpg', sizes:['One Size'], colors:['#1f1f1f','#ffffff'], desc:'Heavy canvas tote bag with Helvetica branding. Carry everything in style.', features:['12 oz canvas','Helvetica screen print','Inner pocket','Reinforced handles'], badge:'NEW' },
    { id:'bg4', name:'Crossbody Bag', category:'Bag', price:1599, img:'/wp-content/themes/hell/media/bag4.jpg', sizes:['One Size'], colors:['#1f1f1f','#8b7355'], desc:'Compact crossbody bag for everyday essentials.', features:['Pebbled nylon','Magnetic snap','Adjustable strap','Card slots'] },
    { id:'bg5', name:'Duffel Bag', category:'Bag', price:2199, img:'/wp-content/themes/hell/media/bag5.jpg', sizes:['One Size'], colors:['#1f1f1f'], desc:'Weekend duffel bag for the travelling streetwear enthusiast.', features:['40L capacity','Shoe compartment','Padded handles','Water-resistant'] },
    // JEWELRY
    { id:'jw1', name:'Chain Necklace', category:'Jewelry', price:1499, img:'/wp-content/themes/hell/media/Mens Necklace - Mini Lapis Lazuli Silver Pendant Necklace For Men - Lapis Necklace , Mens Jewelry, Silver Chain Pendant - By Twistedpendant.jpeg', sizes:['One Size'], colors:['#c0c0c0','#ffd700'], desc:'Sterling silver Cuban link chain with H-logo pendant. Bold, minimal, iconic.', features:['Sterling silver plated','Cuban link chain','H-logo pendant','Anti-tarnish coating'] },
    { id:'jw2', name:'Chunky Ring', category:'Jewelry', price:899, img:'/wp-content/themes/hell/media/jewelry2.jpg', sizes:['6','7','8','9','10'], colors:['#c0c0c0','#ffd700'], desc:'Bold chunky ring in silver or gold plating. Statement piece for every hand.', features:['Silver/gold plated','Chunky design','Hypoallergenic','Anti-tarnish'], badge:'NEW' },
    { id:'jw3', name:'Cuban Link Bracelet', category:'Jewelry', price:1299, img:'/wp-content/themes/hell/media/jewelry3.jpg', sizes:['One Size'], colors:['#c0c0c0','#ffd700'], desc:'Heavy Cuban link bracelet. Stack it or wear it solo.', features:['Cuban link design','Silver plated','Lobster clasp','Anti-tarnish coating'], badge:'HOT' },
    { id:'jw4', name:'Stud Earrings', category:'Jewelry', price:699, img:'/wp-content/themes/hell/media/jewelry4.jpg', sizes:['One Size'], colors:['#c0c0c0','#ffd700'], desc:'Minimal stud earrings with Helvetica H-logo. Subtle flex.', features:['Surgical steel post','H-logo face','Secure butterfly back','Hypoallergenic'] },
    { id:'jw5', name:'H-Logo Pendant', category:'Jewelry', price:1199, img:'/wp-content/themes/hell/media/jewelry5.jpg', sizes:['One Size'], colors:['#c0c0c0','#ffd700'], desc:'H-Logo pendant on a fine rolo chain. Clean and iconic.', features:['H-logo pendant','Rolo chain included','Silver/gold plated','Anti-tarnish'] },
    // SHOES
    { id:'sw1', name:'Loewe Cloudtilt Sneaker', category:'Shoe', price:79999, img:'/wp-content/themes/hell/media/shoe-loewe-cloudtilt-neon-yellow.jpg', sizes:['36','37','38','39','40'], colors:['#efff00'], desc:'Neon yellow Loewe x On Cloudtilt sneaker from the WANNA footwear try-on set.', features:['WANNA VR try-on enabled','Cloudtilt sole shape','Performance mesh upper','Statement neon finish'], badge:'NEW', tryon:'https://wanna-shoes.ar.wanna.fashion/?modelid=L929282X15-7586%2CXXW08J0HL60SHAB999%2C3W2S0HQ5VNWN71%2CWNSHOE001%2CWNSHOE002&startwithid=XXW08J0HL60SHAB999&mode=vto' },
    { id:'sw2', name:'Tods Kate Loafers', category:'Shoe', price:68999, img:'/wp-content/themes/hell/media/shoe-tods-kate-loafers-black.jpg', sizes:['36','37','38','39','40'], colors:['#111111'], desc:'Black brushed-leather Tods Kate loafers with signature metal hardware.', features:['WANNA VR try-on enabled','Lug sole profile','Brushed leather upper','Metal chain detail'], badge:'HOT', tryon:'https://wanna-shoes.ar.wanna.fashion/?modelid=L929282X15-7586%2CXXW08J0HL60SHAB999%2C3W2S0HQ5VNWN71%2CWNSHOE001%2CWNSHOE002&startwithid=XXW08J0HL60SHAB999&mode=vto' },

    // Keep legacy ids for backward compat with home page cards
    { id:'uw1', name:'Classic Drop Oversized Tee', category:'T-Shirt', price:1299, img:'/wp-content/themes/hell/media/imgi_586_46888f878d5547c2f8c59de05ec960e213a1b52b.jpg', sizes:['XS','S','M','L','XL','XXL'], colors:['#1f1f1f','#ffffff','#b6ff3b'], desc:'Dropped shoulders, relaxed fit. Premium 280 GSM cotton that gets better with every wash.', features:['Premium 280 GSM cotton','Pre-washed & shrink-resistant','Dropped shoulder silhouette','Unisex fit'] },
    { id:'uw2', name:'Noir Bomber Jacket', category:'Jacket', price:3499, oldPrice:4499, img:'/wp-content/themes/hell/media/imgi_712_e2a4c45def96b2bbe5b1a730c919ac5d00fe8ae0.jpg', sizes:['S','M','L','XL'], colors:['#1f1f1f'], desc:'Classic bomber silhouette in technical nylon. Timeless style for the streets.', features:['Technical nylon shell','Ribbed cuffs & collar','YKK zipper','Satin lining'], badge:'SALE' },
    { id:'uw3', name:'Arch Logo Hoodie', category:'Hoodie', price:2199, img:'/wp-content/themes/hell/media/imgi_349_f0aff27fb1be61447516bd12346fae11ce010501.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#e445ff'], desc:'Heavyweight 450 GSM fleece hoodie with kangaroo pocket and ribbed cuffs.', features:['450 GSM heavyweight fleece','Kangaroo pocket','Brushed interior','Helvetica arch embroidery'] },
    { id:'uw4', name:'Helvetica Oxford Shirt', category:'Shirt', price:1799, img:'/wp-content/themes/hell/media/imgi_626_3f5c4f6198ab99addeb933613ab5261a650fff95.jpg', sizes:['S','M','L','XL'], colors:['#ffffff','#1f1f1f','#4facfe'], desc:'A clean silhouette in breathable Oxford cotton.', features:['100% Oxford cotton','Relaxed fit','Button-down collar','Machine washable'], badge:'HOT' },
    { id:'lw1', name:'Indigo Slim Jeans', category:'Jeans', price:2199, img:'/wp-content/themes/hell/media/bottom-wear3.jpg', sizes:['28','30','32','34','36'], colors:['#1a3a6e','#1f1f1f'], desc:'Slim-fit denim with just the right amount of stretch.', features:['98% cotton 2% elastane','Slim tapered fit','Indigo wash','5-pocket construction'], badge:'NEW' },
    { id:'lw2', name:'Cargo Shorts', category:'Shorts', price:1499, img:'/wp-content/themes/hell/media/bottom-wear5.jpg', sizes:['S','M','L','XL','XXL'], colors:['#556b2f','#1f1f1f','#8b7355'], desc:'Functional cargo shorts with six pockets.', features:['6-pocket utility design','Mid-thigh length','Drawstring waistband','Ripstop cotton blend'] },
    { id:'lw3', name:'Tech Trousers', category:'Trousers', price:1899, oldPrice:2599, img:'/wp-content/themes/hell/media/bottom-wear1.jpg', sizes:['S','M','L','XL'], colors:['#1f1f1f','#2d4a6e'], desc:'High-performance tech trousers with moisture-wicking fabric.', features:['Moisture-wicking fabric','Zip ankle','Hidden waistband pocket','Slim tapered leg'], badge:'SALE' },
    { id:'lw4', name:'Flex Joggers', category:'Joggers', price:1699, img:'/wp-content/themes/hell/media/bottom-wear2.jpg', sizes:['S','M','L','XL','XXL'], colors:['#1f1f1f','#e445ff','#b6ff3b'], desc:'Super-soft 320 GSM loopback fleece joggers.', features:['320 GSM loopback fleece','Elasticated waistband','Side & back pockets','Relaxed tapered fit'] },
    { id:'lw5', name:'Wide Leg Pants', category:'Trousers', price:2299, img:'/wp-content/themes/hell/media/bottom-wear4.jpg', sizes:['S','M','L','XL'], colors:['#1f1f1f','#c0a080'], desc:'Bold wide-leg silhouette in woven twill.', features:['Woven twill fabric','Wide-leg cut','Flat front design','Concealed zipper fly'], badge:'TRENDING' },
    { id:'ac1', name:'H-Logo Cap', category:'Cap', price:799, img:'/wp-content/themes/hell/media/ImgHunt_Urbanmonkey_20260302_evolve-24lst213-blk-808114.jpeg', sizes:['One Size'], colors:['#1f1f1f','#ffffff','#b6ff3b'], desc:'Structured 6-panel snapback with embroidered H-logo.', features:['6-panel structured cap','Flat brim','Snap closure','One size fits most'], badge:'NEW' },
    { id:'ac2', name:'Street Watch', category:'Watch', price:3299, img:'/wp-content/themes/hell/media/ImgHunt_Rolex_20260302_professional-watches-cosmograph-daytona-myth-push-watch-first_cosmograph_daytona_1963.jpeg', sizes:['One Size'], colors:['#c0c0c0','#ffd700','#1f1f1f'], desc:'Minimalist street watch with stainless steel case.', features:['Stainless steel case','Scratch-resistant glass','Water resistant 30m','Japanese quartz movement'] },
    { id:'ac3', name:'Tactical Backpack', category:'Bag', price:2999, img:'/wp-content/themes/hell/media/ImgHunt_Safaribags_20260306_02copy_e1ac5ffd-2768-4ff6-9d9a-f2a075e4a5b6.jpeg', sizes:['One Size'], colors:['#1f1f1f','#556b2f'], desc:'30L tactical backpack with padded straps and laptop sleeve.', features:['30L capacity','Padded laptop sleeve','Water-resistant exterior','Ergonomic straps'], badge:'HOT' },
    { id:'ac4', name:'Chain Necklace', category:'Jewelry', price:1499, img:'/wp-content/themes/hell/media/Mens Necklace - Mini Lapis Lazuli Silver Pendant Necklace For Men - Lapis Necklace , Mens Jewelry, Silver Chain Pendant - By Twistedpendant.jpeg', sizes:['One Size'], colors:['#c0c0c0','#ffd700'], desc:'Sterling silver Cuban link chain with H-logo pendant.', features:['Sterling silver plated','Cuban link chain','H-logo pendant','Anti-tarnish coating'] },
    { id:'ac5', name:'Mini Sling Bag', category:'Bag', price:999, oldPrice:1299, img:'/wp-content/themes/hell/media/Sling Medium Borsttas _ Gorpcore.jpeg', sizes:['One Size'], colors:['#1f1f1f','#8b7355'], desc:'Compact sling bag for your essentials.', features:['Water-resistant nylon','Adjustable strap','Front zip pocket','YKK zippers'], badge:'SALE' },
  ];

  // Map card index per section to product ids
  const CARD_PRODUCT_MAP = {};
  (function buildMap(){
    document.querySelectorAll('.grid__item').forEach(function(item, idx){
      item.setAttribute('data-prod-idx', idx);
    });
  })();

  // ===== OPEN PRODUCT DETAIL =====
  let pdCurrentProduct = null;
  let pdSelectedSize = null;
  let pdSelectedColor = null;
  let pdQtyVal = 1;

  function openPD(prodId) {
    const p = PRODUCTS.find(x => x.id === prodId);
    if (!p) return;
    pdCurrentProduct = p;
    window.pdCurrentProduct = p; // expose for VR try-on system
    pdSelectedSize = null;
    pdSelectedColor = p.colors ? p.colors[0] : null;
    pdQtyVal = 1;

    document.getElementById('pd-img').src = p.img;
    document.getElementById('pd-img').alt = p.name;
    document.getElementById('pd-category').textContent = p.category;
    document.getElementById('pd-name').textContent = p.name;
    document.getElementById('pd-price').textContent = '₹ ' + p.price.toLocaleString('en-IN');
    document.getElementById('pd-desc').textContent = p.desc;

    const oldEl = document.getElementById('pd-price-old');
    if (p.oldPrice) {
      oldEl.style.display = '';
      oldEl.textContent = '₹ ' + p.oldPrice.toLocaleString('en-IN');
      document.getElementById('pd-price').style.color = '#ff5271';
    } else {
      oldEl.style.display = 'none';
      document.getElementById('pd-price').style.color = '';
    }

    // Badge
    const badgeWrap = document.getElementById('pd-badges');
    badgeWrap.innerHTML = '';
    if (p.badge) {
      const b = document.createElement('span');
      b.className = 'custom-badge' + (p.badge === 'SALE' ? ' badge-sale' : '');
      b.textContent = p.badge;
      badgeWrap.appendChild(b);
    }

    // Sizes
    const sizesEl = document.getElementById('pd-sizes');
    sizesEl.innerHTML = '';
    (p.sizes || []).forEach(function(s){
      const btn = document.createElement('button');
      btn.className = 'pd-size';
      btn.textContent = s;
      btn.onclick = function(){
        document.querySelectorAll('.pd-size').forEach(b=>b.classList.remove('selected'));
        btn.classList.add('selected');
        pdSelectedSize = s;
      };
      sizesEl.appendChild(btn);
    });
    document.getElementById('pd-size-block').style.display = p.sizes && p.sizes.length ? '' : 'none';

    // Colors — hidden per site design (color selection removed)
    pdSelectedColor = p.colors ? p.colors[0] : null;

    // Qty
    document.getElementById('pd-qty').textContent = 1;

    // Features
    const featEl = document.getElementById('pd-features');
    featEl.innerHTML = '';
    (p.features || []).forEach(function(f){
      const d = document.createElement('div');
      d.className = 'pd-feature';
      d.textContent = f;
      featEl.appendChild(d);
    });

    const btn = document.getElementById('pd-atc-btn');
    btn.textContent = 'Add to Cart →';
    btn.classList.remove('added');

    document.getElementById('pdOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
  }

  function closePD(){
    document.getElementById('pdOverlay').classList.remove('open');
    document.body.style.overflow = '';
  }

  document.getElementById('pdOverlay').addEventListener('click', function(e){
    if (e.target === this) closePD();
  });

  window.pdQty = function(delta){
    pdQtyVal = Math.max(1, Math.min(10, pdQtyVal + delta));
    document.getElementById('pd-qty').textContent = pdQtyVal;
  };

  window.pdAddToCart = function(){
    const p = pdCurrentProduct;
    if (!p) return;

    // For apparel, require size selection (if sizes exist and not 'One Size')
    if (p.sizes && p.sizes.length > 0 && p.sizes[0] !== 'One Size' && !pdSelectedSize) {
      const sizesEl = document.getElementById('pd-sizes');
      sizesEl.style.outline = '2px solid #e00';
      sizesEl.title = 'Please select a size';
      setTimeout(()=>{ sizesEl.style.outline=''; }, 2000);
      return;
    }

    const variant = pdSelectedSize || 'One Size';
    const existing = cartState.items.find(i => i.name === p.name && i.variant === variant);
    if (existing) {
      existing.qty += pdQtyVal;
    } else {
      cartState.items.push({
        name: p.name,
        variant: variant,
        price: p.price,
        img: p.img,
        qty: pdQtyVal
      });
    }

    const btn = document.getElementById('pd-atc-btn');
    btn.textContent = ' Added to Cart!';
    btn.classList.add('added');
    setTimeout(function(){
      btn.textContent = 'Add to Cart →';
      btn.classList.remove('added');
    }, 1800);

    updateCartUI();

    // Animate cart bubble
    const cartBubble = document.querySelector('.cart-count-bubble');
    if (cartBubble) {
      cartBubble.style.transform = 'scale(1.5)';
      setTimeout(function(){ cartBubble.style.transform = ''; }, 300);
    }
  };

  // ===== WIRE PRODUCT CARDS TO OPEN MODAL =====
  (function(){
    // Home page section mapping
    const sectionProductIds = {
      'upper-wear': ['uw1','uw2','uw3','uw4','uw4','jk6'],
      'lower-wear': ['lw1','lw2','lw3','lw4','lw5'],
      'accessories': ['ac1','ac2','ac3','ac4','ac5'],
      'shoes': ['sw1','sw2'],
    };
    // Map section ids to their product grids (home page)
    ['upper-wear','lower-wear','accessories','shoes'].forEach(function(sectionId){
      const section = document.getElementById(sectionId);
      if (!section) return;
      const cards = section.querySelectorAll('.card-wrapper');
      const ids = sectionProductIds[sectionId];
      if (!ids) return;
      cards.forEach(function(card, i){
        const pid = ids[i] || ids[ids.length-1];
        card.setAttribute('data-product-id', pid);
        card.style.cursor = 'pointer';
        card.querySelector('.card__inner')?.addEventListener('click', function(e){
          if (e.target.closest('.quick-add-overlay')) return;
          openPD(pid);
        });
      });
    });

    // Category page: wire all [data-product-id^="cat_"] cards
    document.querySelectorAll('.card-wrapper[data-product-id^="cat_"]').forEach(function(card){
      const catId = card.getAttribute('data-product-id').replace('cat_','');
      card.style.cursor = 'pointer';
      card.querySelector('.card__inner')?.addEventListener('click', function(e){
        if (e.target.closest('.quick-add-overlay')) return;
        openPD(catId);
      });
      // Wire quick-add on category pages too
      card.querySelector('.quick-add-overlay')?.addEventListener('click', function(e){
        e.stopPropagation();
        openPD(catId);
      });
    });

    // Wire quick-add buttons on home page
    document.querySelectorAll('.quick-add-overlay').forEach(function(btn){
      // Skip those already wired above for category pages
      const card = btn.closest('.card-wrapper');
      if (card && card.getAttribute('data-product-id') && card.getAttribute('data-product-id').startsWith('cat_')) return;
      btn.addEventListener('click', function(e){
        e.stopPropagation();
        const pid = card?.getAttribute('data-product-id');
        if (pid) openPD(pid);
      });
    });
  })();

  // ===== AUTH MODAL =====
  function openAuth(tab) {
    document.getElementById('authOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
    if (tab) {
      const tabBtns = document.querySelectorAll('.auth-tab');
      const panels = document.querySelectorAll('.auth-panel');
      tabBtns.forEach(b=>b.classList.remove('active'));
      panels.forEach(p=>p.classList.remove('active'));
      if (tab === 'signup') {
        tabBtns[1]?.classList.add('active');
        document.getElementById('auth-signup')?.classList.add('active');
      } else {
        tabBtns[0]?.classList.add('active');
        document.getElementById('auth-login')?.classList.add('active');
      }
    }
  }
  function closeAuth(){
    document.getElementById('authOverlay').classList.remove('open');
    document.body.style.overflow = '';
  }
  window.switchAuthTab = function(tab, btn){
    document.querySelectorAll('.auth-tab').forEach(b=>b.classList.remove('active'));
    document.querySelectorAll('.auth-panel').forEach(p=>p.classList.remove('active'));
    btn.classList.add('active');
    document.getElementById('auth-' + tab)?.classList.add('active');
  };
  document.getElementById('authOverlay')?.addEventListener('click', function(e){
    if (e.target === this) closeAuth();
  });

  // ===== ACCOUNT DRAWER =====
  function openAccount(){
    document.getElementById('accountOverlay').classList.add('open');
    document.body.style.overflow = 'hidden';
  }
  function closeAccount(){
    document.getElementById('accountOverlay').classList.remove('open');
    document.body.style.overflow = '';
  }
  document.getElementById('accountOverlay')?.addEventListener('click', function(e){
    if (e.target === this) closeAccount();
  });

  // Wire account icon
  (function(){
    const acctBtn = document.querySelector('.header__icon[aria-label="Account"]');
    if (acctBtn) {
      acctBtn.addEventListener('click', function(e){
        e.preventDefault();
        <?php if($current_user): ?>
        openAccount();
        <?php else: ?>
        openAuth('login');
        <?php endif; ?>
      });
    }
    <?php if($current_user): ?>
    // Show logged-in dot
    if (acctBtn) {
      const dot = document.createElement('span');
      dot.className = 'acct-dot';
      dot.style.cssText = 'position:absolute;top:-.4rem;right:-.4rem;width:.9rem;height:.9rem;background:var(--color-accent);border-radius:50%;display:block;';
      acctBtn.style.position = 'relative';
      acctBtn.appendChild(dot);
    }
    <?php endif; ?>
  })();

  // Auto-open auth if there was a login/signup error
  <?php if($auth_error): ?>
  (function(){
    var wasSignup = <?php echo isset($_POST['hv_signup']) ? 'true' : 'false'; ?>;
    openAuth(wasSignup ? 'signup' : 'login');
  })();
  <?php endif; ?>

  // Auto-open account if just logged in / signed up successfully
  <?php if($auth_success && $current_user): ?>
  (function(){
    setTimeout(function(){ openAccount(); }, 400);
  })();
  <?php endif; ?>

  // ===== UPDATED QR GENERATOR — uses correct UPI ID =====
  (function() {
    var UPI_ID   = '<?php echo OWNER_UPI; ?>';
    var UPI_NAME = '<?php echo OWNER_UPI_NAME; ?>';

    // Override genQR globally
    window.genQR = function(tot) {
      var upiStr = 'upi://pay?pa=' + encodeURIComponent(UPI_ID) +
                  '&pn=' + encodeURIComponent(UPI_NAME) +
                  '&am=' + tot +
                  '&cu=INR' +
                  '&tn=' + encodeURIComponent(UPI_NAME + ' Order');
      var qrUrl = 'https://api.qrserver.com/v1/create-qr-code/?data=' +
                  encodeURIComponent(upiStr) +
                  '&size=220x220&margin=10&format=png';
      document.getElementById('qrImg').src = qrUrl;
      document.querySelector('.upi-id').textContent = UPI_ID;
    };
  })();

  // ===== ORDER HISTORY LINK FROM SUCCESS POPUP =====
  window.closeSuccess = function() {
    document.getElementById('successOverlay').classList.remove('open');
    document.body.style.overflow = '';
    if (cartState) { cartState.items = []; updateCartUI(); }
  };
  </script>
  <!-- ===== VIRTUAL TRY-ON BUTTON ===== -->
  <script>
  // ===== VR TRY-ON SYSTEM =====
  (function() {
    var PRODUCT_TRYON_LINKS = {
      // Shoes
      'sw1': 'https://wanna-shoes.ar.wanna.fashion/?modelid=L929282X15-7586%2CXXW08J0HL60SHAB999%2C3W2S0HQ5VNWN71%2CWNSHOE001%2CWNSHOE002&startwithid=XXW08J0HL60SHAB999&mode=vto',
      'sw2': 'https://wanna-shoes.ar.wanna.fashion/?modelid=L929282X15-7586%2CXXW08J0HL60SHAB999%2C3W2S0HQ5VNWN71%2CWNSHOE001%2CWNSHOE002&startwithid=XXW08J0HL60SHAB999&mode=vto',
      // VR Utility Jacket
      'jk6': 'https://wanna-clothes.ar.wanna.fashion/?mode=vto&showonboarding=3d&modelid=WNCLO01&startwithid=WNCLO01',
      // Other products
      'cp1': 'https://www.kivicube.com/face-scenes/CYNIBj0g0vfcvtWxe0iN7YrUbZds9l7s',
      'wt1': 'https://www.shopar.ai/collection/watches?product=66618cf59d3fb1edda45d3ba&mode=ar',
      'jw4': 'https://www.kivicube.com/face-scenes/1OHeNorFVxZQZGv6zlSayL7nID7RYeFG'
    };

    function getTryonLink() {
      // Check 1: Product's own tryon property
      if (window.pdCurrentProduct && window.pdCurrentProduct.tryon) {
        return window.pdCurrentProduct.tryon;
      }
      // Check 2: Hardcoded links by product ID
      if (window.pdCurrentProduct && window.pdCurrentProduct.id && PRODUCT_TRYON_LINKS[window.pdCurrentProduct.id]) {
        return PRODUCT_TRYON_LINKS[window.pdCurrentProduct.id];
      }
      // Check 3: Category-based fallback
      var catEl = document.getElementById('pd-category');
      var nameEl = document.getElementById('pd-name');
      if (!catEl) return null;
      var cat = (catEl.textContent || '').trim().toLowerCase();
      var name = nameEl ? (nameEl.textContent || '').trim().toLowerCase() : '';
      if (cat === 'cap') return PRODUCT_TRYON_LINKS['cp1'];
      if (cat === 'watch') return PRODUCT_TRYON_LINKS['wt1'];
      if (cat === 'jewelry' && name.indexOf('earring') !== -1) return PRODUCT_TRYON_LINKS['jw4'];
      return null;
    }

    function removeTryonBtn() {
      var old = document.getElementById('hv-tryon-btn');
      if (old) old.remove();
    }

    function injectTryonBtn() {
      removeTryonBtn();
      var link = getTryonLink();
      if (!link) return;
      var atcBtn = document.getElementById('pd-atc-btn');
      if (!atcBtn) return;
      var btn = document.createElement('a');
      btn.id = 'hv-tryon-btn';
      btn.href = link;
      btn.target = '_blank';
      btn.rel = 'noopener noreferrer';
      btn.innerHTML = '🕸 &nbsp;Virtual Try-On';
      btn.style.cssText = 'display:flex;align-items:center;justify-content:center;width:100%;margin-top:1rem;padding:1.5rem;background:transparent;color:#0c1a2b;border:2.5px solid #0c1a2b;font-family:inherit;font-size:1.3rem;font-weight:700;letter-spacing:.15em;text-transform:uppercase;text-decoration:none;cursor:pointer;box-sizing:border-box;transition:background .2s,color .2s;';
      btn.onmouseenter = function(){ this.style.background='#b6ff3b'; this.style.borderColor='#b6ff3b'; };
      btn.onmouseleave = function(){ this.style.background='transparent'; this.style.borderColor='#0c1a2b'; };
      atcBtn.insertAdjacentElement('afterend', btn);
    }

    // Hook into openPD by overriding it
    var _origOpenPD = window.openPD;
    window.openPD = function(prodId) {
      _origOpenPD(prodId);
      // Inject tryon button after modal opens
      setTimeout(injectTryonBtn, 100);
    };

    // Also hook into closePD to clean up
    var _origClosePD = window.closePD;
    window.closePD = function() {
      removeTryonBtn();
      _origClosePD();
    };

    // Existing MutationObserver as backup
    var overlay = document.getElementById('pdOverlay');
    if (overlay) {
      new MutationObserver(function(mutations) {
        mutations.forEach(function(m) {
          if (m.attributeName !== 'class') return;
          if (overlay.classList.contains('open')) {
            setTimeout(injectTryonBtn, 200);
          } else {
            removeTryonBtn();
          }
        });
      }).observe(overlay, { attributes: true });
    }
  })();
  </script>