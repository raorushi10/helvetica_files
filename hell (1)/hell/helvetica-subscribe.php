<?php
/**
 * HELVETICA NEWSLETTER SUBSCRIBE ENDPOINT
 * 
 * This file handles AJAX newsletter subscriptions from the main site.
 * Place it at the same level as your main theme file.
 * 
 * Called by the "Stay In The Know" section JS on the main site.
 * 
 * Usage: POST to this file with { email: "..." } or form data with action=subscribe
 */

define('SUBS_FILE', __DIR__ . '/helvetica_subscribers.json');

header('Content-Type: application/json');
header('Access-Control-Allow-Origin: *');

function load_subscribers() {
    if (!file_exists(SUBS_FILE)) return [];
    return json_decode(file_get_contents(SUBS_FILE), true) ?: [];
}
function save_subscribers($subs) {
    file_put_contents(SUBS_FILE, json_encode($subs, JSON_PRETTY_PRINT));
}

$r = ['ok' => false, 'msg' => ''];

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    $r['msg'] = 'Method not allowed.';
    echo json_encode($r); exit;
}

$em = strtolower(trim($_POST['email'] ?? (json_decode(file_get_contents('php://input'), true)['email'] ?? '')));

if (!$em || !filter_var($em, FILTER_VALIDATE_EMAIL)) {
    $r['msg'] = 'Please enter a valid email address.';
    echo json_encode($r); exit;
}

$subs = load_subscribers();
foreach ($subs as $s) {
    if ($s['email'] === $em) {
        $r['ok'] = true;
        $r['msg'] = "You're already on the list!";
        echo json_encode($r); exit;
    }
}

$subs[] = [
    'email'          => $em,
    'subscribed_at'  => date('d M Y, h:i A'),
    'source'         => 'website',
    'ip'             => $_SERVER['REMOTE_ADDR'] ?? '',
];
save_subscribers($subs);

$r['ok']  = true;
$r['msg'] = 'Subscribed! Welcome to HELVETICA.';
echo json_encode($r);