<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
// Włączanie / wyłączanie własnych reguł firewalla z MiniDasha.
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

ob_start();
header('Content-Type: application/json');

function fw_reply(array $r, int $code = 200): void
{
    ob_clean();
    http_response_code($code);
    echo json_encode($r);
    exit;
}

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) fw_reply(['success' => false, 'message' => 'Unauthorized'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') fw_reply(['success' => false, 'message' => 'Method not allowed'], 405);
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) fw_reply(['success' => false, 'message' => 'CSRF'], 403);

$data    = json_decode(file_get_contents('php://input'), true) ?: [];
$id      = (string)($data['id'] ?? '');
$enabled = (bool)($data['enabled'] ?? false);

$res = set_firewall_policy_enabled($id, $enabled);
if (!$res['ok']) fw_reply(['success' => false, 'message' => $res['error'] ?? 'error'], 502);

try {
    $who = $_SESSION['username'] ?? 'admin';
    $msg = ($enabled ? __('firewall.log_on') : __('firewall.log_off')) . ': ' . $res['name'];
    $db->prepare("INSERT INTO events (type, severity, message, details_json) VALUES (?, ?, ?, ?)")
       ->execute(['firewall', 'INFO', $msg, json_encode(['id' => $id, 'enabled' => $enabled, 'by' => $who])]);
} catch (Throwable $e) {
    error_log('MiniDash firewall toggle log: ' . $e->getMessage());
}

fw_reply(['success' => true, 'enabled' => $enabled]);
