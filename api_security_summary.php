<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * Zbiorcze dane dla kafelków dashboardu: ocena bezpieczeństwa, liczba blokad (24h)
 * i blokady wg ISP/organizacji źródła. Jeden fetch zamiast trzech.
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
session_write_close();

// Ocena bezpieczeństwa
$score = compute_security_score(get_unifi_security_settings() ?: []);

// Zagrożenia (24h), z pominięciem ignorowanych IP
$events = fetch_threat_events('24h')['events'] ?? [];
$ignore = [];
if (isset($db)) {
    try {
        foreach ($db->query("SELECT ip FROM threat_ignore", PDO::FETCH_ASSOC) as $r) $ignore[] = $r['ip'];
    } catch (Throwable $e) { /* brak tabeli = brak filtra */ }
}
if ($ignore) {
    $events = array_values(array_filter($events, fn($e) => !in_array($e['src_ip'] ?? '', $ignore, true)));
}

$blocked_events = array_values(array_filter($events, fn($e) => ($e['action'] ?? '') === 'blocked'));
$blocked = count($blocked_events);

// Blokady wg ISP/organizacji (src_geo.org)
$orgs = [];
foreach ($blocked_events as $e) {
    $org = trim((string)($e['src_geo']['org'] ?? ''));
    $cc  = strtolower((string)($e['src_geo']['country_code'] ?? ($e['country_code'] ?? '')));
    if ($org === '' || $cc === 'local') continue;
    if (!isset($orgs[$org])) $orgs[$org] = ['org' => $org, 'count' => 0, 'cc' => $cc];
    $orgs[$org]['count']++;
}
usort($orgs, fn($a, $b) => $b['count'] <=> $a['count']);
$top_orgs = array_slice(array_values($orgs), 0, 10);

echo json_encode([
    'success'   => true,
    'score'     => $score,
    'blocked'   => $blocked,
    'total'     => count($events),
    'top_orgs'  => $top_orgs,
]);
