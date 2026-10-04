<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * Transfer jednego urządzenia: dzień / tydzień / miesiąc.
 * Liczony jako suma dodatnich różnic licznika (patrz transfer_window()).
 */
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthorized']);
    exit;
}

$mac = normalize_mac($_GET['mac'] ?? '');
if (!$mac) {
    echo json_encode(['error' => 'Missing MAC']);
    exit;
}

$empty = ['rx' => 0, 'tx' => 0, 'total' => 0];
$out = ['mac' => $mac, 'stats_24h' => $empty, 'stats_7d' => $empty, 'stats_30d' => $empty];

if (isset($db) && $db instanceof PDO) {
    foreach (['stats_24h' => '-1 day', 'stats_7d' => '-7 days', 'stats_30d' => '-30 days'] as $key => $since) {
        $one = transfer_window($db, $since, 'mac', $mac);
        if (isset($one[$mac])) $out[$key] = $one[$mac];
    }
}

echo json_encode($out);
