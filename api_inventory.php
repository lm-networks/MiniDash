<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * Zapis inwentarza: właściciel, notatka, zatwierdzenie urządzenia.
 * Akcje (POST JSON): save {mac, owner, note}, approve {mac}, unapprove {mac}, approve_all.
 */
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

ob_start();
header('Content-Type: application/json');

function inv_reply(array $r, int $code = 200): void
{
    ob_clean();
    http_response_code($code);
    echo json_encode($r);
    exit;
}

if (empty($_SESSION['logged_in']))                                  inv_reply(['success' => false, 'message' => 'Unauthorized'], 401);
if ($_SERVER['REQUEST_METHOD'] !== 'POST')                          inv_reply(['success' => false, 'message' => 'Method not allowed'], 405);
if (!verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))              inv_reply(['success' => false, 'message' => 'CSRF'], 403);
if (!isset($db) || !($db instanceof PDO))                          inv_reply(['success' => false, 'message' => 'DB unavailable'], 500);

$data   = json_decode(file_get_contents('php://input'), true) ?: [];
$action = (string)($data['action'] ?? '');
$mac    = normalize_mac($data['mac'] ?? '');

/** Tworzy wiersz, jeśli go nie ma (żeby UPDATE miał co aktualizować). */
function inv_ensure(PDO $db, string $mac): void
{
    $db->prepare("INSERT OR IGNORE INTO device_inventory (mac) VALUES (?)")->execute([$mac]);
}

try {
    switch ($action) {
        case 'save':
            if ($mac === '') inv_reply(['success' => false, 'message' => 'Missing MAC'], 400);
            $owner = mb_substr(trim((string)($data['owner'] ?? '')), 0, 120);
            $note  = mb_substr(trim((string)($data['note'] ?? '')), 0, 500);
            inv_ensure($db, $mac);
            $db->prepare("UPDATE device_inventory SET owner = ?, note = ?, updated_at = datetime('now') WHERE mac = ?")
               ->execute([$owner, $note, $mac]);
            inv_reply(['success' => true]);

        case 'approve':
        case 'unapprove':
            if ($mac === '') inv_reply(['success' => false, 'message' => 'Missing MAC'], 400);
            $ap = $action === 'approve' ? 1 : 0;
            inv_ensure($db, $mac);
            $db->prepare("UPDATE device_inventory SET approved = ?, approved_at = CASE WHEN ? = 1 THEN datetime('now') ELSE NULL END, updated_at = datetime('now') WHERE mac = ?")
               ->execute([$ap, $ap, $mac]);
            inv_reply(['success' => true, 'approved' => (bool)$ap]);

        case 'approve_all':
            // Zatwierdza wszystkie obecnie widziane urządzenia (baseline).
            $n = 0;
            foreach (get_inventory($db, false) as $d) {
                if ($d['approved']) continue;
                inv_ensure($db, $d['mac']);
                $db->prepare("UPDATE device_inventory SET approved = 1, approved_at = datetime('now'), updated_at = datetime('now') WHERE mac = ?")
                   ->execute([$d['mac']]);
                $n++;
            }
            inv_reply(['success' => true, 'count' => $n]);

        default:
            inv_reply(['success' => false, 'message' => 'Unknown action'], 400);
    }
} catch (Throwable $e) {
    error_log('MiniDash inventory: ' . $e->getMessage());
    inv_reply(['success' => false, 'message' => 'Server error'], 500);
}
