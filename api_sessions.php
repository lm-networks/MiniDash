<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
// Kończenie sesji z panelu „Bezpieczeństwo konta".
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

ob_start();
header('Content-Type: application/json');

function sess_reply(array $r, int $code = 200): void
{
    ob_clean();
    http_response_code($code);
    echo json_encode($r);
    exit;
}

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) sess_reply(['success' => false, 'message' => 'Unauthorized'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST') sess_reply(['success' => false, 'message' => 'Method not allowed'], 405);
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) sess_reply(['success' => false, 'message' => 'CSRF'], 403);

$data   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($data['action'] ?? '');
$cur    = (string)($_SESSION['sg_sid'] ?? '');

$log = function (string $msg, array $details) use ($db) {
    try {
        $db->prepare("INSERT INTO events (type, severity, message, details_json) VALUES (?, ?, ?, ?)")
           ->execute(['security', 'WARNING', $msg, json_encode($details)]);
    } catch (Throwable $e) {
        error_log('MiniDash sessions log: ' . $e->getMessage());
    }
};

if ($action === 'revoke') {
    $id = (int)($data['id'] ?? 0);
    $q = $db->prepare("SELECT sid, os, browser, ip FROM user_sessions WHERE id = ?");
    $q->execute([$id]);
    $row = $q->fetch(PDO::FETCH_ASSOC);
    if (!$row) sess_reply(['success' => false, 'message' => 'not found'], 404);
    if ($row['sid'] === $cur) sess_reply(['success' => false, 'message' => __('account.cant_revoke_current')], 400);
    if (!revoke_session($db, $id)) sess_reply(['success' => false, 'message' => 'already ended'], 409);
    $log(__('account.log_revoked') . ": {$row['os']} / {$row['browser']} ({$row['ip']})", ['id' => $id]);
    sess_reply(['success' => true]);
}

if ($action === 'revoke_others') {
    // Selektor tokenu TEJ sesji — z rejestru, a gdy go brak (sesja sprzed wdrożenia) z ciasteczka.
    // Bez tego „wyloguj inne" skasowałoby też zapamiętane logowanie bieżącego urządzenia.
    $q = $db->prepare("SELECT remember_selector FROM user_sessions WHERE sid = ?");
    $q->execute([$cur]);
    $cur_sel = $q->fetchColumn() ?: ($_SESSION['remember_selector']
        ?? (!empty($_COOKIE['remember_me']) ? explode(':', $_COOKIE['remember_me'], 2)[0] : null));
    $n = revoke_other_sessions($db, $cur, $cur_sel);
    $log(__('account.log_revoked_others') . ": $n", ['count' => $n]);
    sess_reply(['success' => true, 'count' => $n]);
}

sess_reply(['success' => false, 'message' => 'Invalid action'], 400);
