<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
// Zapis / reset układu dashboardu (tryb edycji z Danych osobistych).
require_once 'config.php';
require_once 'functions.php';
require_once 'includes/dashboard_layout.php';

ob_start();
header('Content-Type: application/json');

function dl_reply(array $r, int $code = 200): void
{
    ob_clean();
    http_response_code($code);
    echo json_encode($r);
    exit;
}

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) dl_reply(['success' => false, 'message' => 'Unauthorized'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') dl_reply(['success' => false, 'message' => 'Method not allowed'], 405);
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) dl_reply(['success' => false, 'message' => 'CSRF'], 403);

$data = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($data['action'] ?? '');

if ($action === 'reset') {
    if (is_file(DW_LAYOUT_FILE) && !@unlink(DW_LAYOUT_FILE)) dl_reply(['success' => false, 'message' => 'write error'], 500);
    dl_reply(['success' => true]);
}

if ($action === 'save') {
    $layout = dw_sanitize_layout($data['layout'] ?? null);
    if (!$layout['order']) dl_reply(['success' => false, 'message' => 'empty layout'], 400);
    $ok = file_put_contents(DW_LAYOUT_FILE, json_encode($layout, JSON_PRETTY_PRINT), LOCK_EX);
    if ($ok === false) dl_reply(['success' => false, 'message' => 'write error'], 500);
    dl_reply(['success' => true]);
}

dl_reply(['success' => false, 'message' => 'Invalid action'], 400);
