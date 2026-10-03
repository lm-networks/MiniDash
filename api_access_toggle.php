<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
// Włączanie / wyłączanie obiektów z Settings → Objects (kontrola dostępu) z dashboardu.
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

ob_start();
header('Content-Type: application/json');

function access_reply(array $r, int $code = 200): void
{
    ob_clean();
    http_response_code($code);
    echo json_encode($r);
    exit;
}

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) access_reply(['success' => false, 'message' => 'Unauthorized'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') access_reply(['success' => false, 'message' => 'Method not allowed'], 405);
// To zmienia konfigurację sieci — wymagamy tokenu CSRF z nagłówka.
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) access_reply(['success' => false, 'message' => 'CSRF'], 403);

$data    = json_decode(file_get_contents('php://input'), true) ?: [];
$id      = (string)($data['id'] ?? '');
$enabled = (bool)($data['enabled'] ?? false);

$res = set_access_object_enabled($id, $enabled);
if (!$res['ok']) access_reply(['success' => false, 'message' => $res['error'] ?? 'error'], 502);

// Ślad w dzienniku zdarzeń (dzwonek), bez wysyłki na Telegram.
try {
    $who = $_SESSION['username'] ?? 'admin';
    $msg = ($enabled ? __('access.log_on') : __('access.log_off')) . ': ' . $res['name'];
    $db->prepare("INSERT INTO events (type, severity, message, details_json) VALUES (?, ?, ?, ?)")
       ->execute(['access', 'INFO', $msg, json_encode(['id' => $id, 'enabled' => $enabled, 'by' => $who])]);
} catch (Throwable $e) {
    error_log('MiniDash access toggle log: ' . $e->getMessage());
}

foreach (get_access_objects() as $o) {
    if ($o['id'] === $id) access_reply(['success' => true, 'object' => $o]);
}
access_reply(['success' => true]);
