<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * Ranking transferu: per urządzenie i per VLAN, dla wybranego zakresu.
 * Transfer liczony deltami licznika (patrz transfer_window()).
 */
ini_set('display_errors', 0);
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

$ranges = ['day' => '-1 day', 'week' => '-7 days', 'month' => '-30 days'];
$range  = $_GET['range'] ?? 'day';
$since  = $ranges[$range] ?? $ranges['day'];

if (!isset($db) || !($db instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB unavailable']);
    exit;
}

$devices_raw = loadDevices();          // mac => [name, vlan, ...]
$vlan_names  = get_vlans();            // id => name

// Rzeczywisty (ostatni) VLAN per urządzenie z historii - bywa inny niż ustawiony przy dodaniu.
$last_vlan = [];
foreach ($db->query("SELECT ch.mac, ch.vlan FROM client_history ch
                     JOIN (SELECT mac, MAX(id) mid FROM client_history GROUP BY mac) m
                       ON ch.id = m.mid", PDO::FETCH_ASSOC) as $r) {
    $last_vlan[$r['mac']] = (int)$r['vlan'];
}

$vname = fn($id) => $vlan_names[(string)(int)$id] ?? ($vlan_names[$id] ?? ('VLAN ' . (int)$id));

// Per urządzenie
$dev_tx = transfer_window($db, $since, 'mac');
$devices = [];
foreach ($dev_tx as $mac => $t) {
    if ($t['total'] <= 0) continue;
    $vid = $last_vlan[$mac] ?? (isset($devices_raw[$mac]['vlan']) ? (int)$devices_raw[$mac]['vlan'] : 0);
    $devices[] = [
        'mac'      => $mac,
        'name'     => $devices_raw[$mac]['name'] ?? $mac,
        'vlan'     => $vid,
        'vlan_name'=> $vname($vid),
        'rx'       => $t['rx'],
        'tx'       => $t['tx'],
        'total'    => $t['total'],
    ];
}
usort($devices, fn($a, $b) => $b['total'] <=> $a['total']);

// Per VLAN
$vlan_tx = transfer_window($db, $since, 'vlan');
$vlans = [];
foreach ($vlan_tx as $vid => $t) {
    if ($t['total'] <= 0) continue;
    $vlans[] = [
        'vlan'  => (int)$vid,
        'name'  => $vname($vid),
        'rx'    => $t['rx'],
        'tx'    => $t['tx'],
        'total' => $t['total'],
    ];
}
usort($vlans, fn($a, $b) => $b['total'] <=> $a['total']);

echo json_encode(['success' => true, 'range' => $range, 'devices' => $devices, 'vlans' => $vlans]);
