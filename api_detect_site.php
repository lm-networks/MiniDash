<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/** Test połączenia z konsolą + lista site'ów - kreator instalacji i strona „Konsola UniFi" */
header('Content-Type: application/json');
require_once __DIR__ . '/includes/unifi_probe.php';

$stored_key = '';
// Przed instalacją endpoint obsługuje kreator. Po instalacji tylko zalogowany admin z tokenem
// CSRF - inaczej każdy mógłby kazać serwerowi łączyć się z dowolnym adresem.
if (file_exists(__DIR__ . '/data/.installed')) {
    require_once __DIR__ . '/config.php';
    require_once __DIR__ . '/functions.php';
    if (empty($_SESSION['logged_in']) || !verify_csrf($_SERVER['HTTP_X_CSRF_TOKEN'] ?? '')) {
        http_response_code(403);
        echo json_encode(['error' => 'forbidden', 'sites' => []]);
        exit;
    }
    // Na stronie ustawień puste pole klucza = sprawdzamy zapisany klucz
    $stored_key = (string)($config['api_key'] ?? '');
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'method', 'sites' => []]);
    exit;
}

$base = unifi_normalize_url($_POST['controller_url'] ?? '');
$key  = trim($_POST['api_key'] ?? '') ?: $stored_key;

if ($key === '') {
    echo json_encode(['error' => 'key', 'sites' => []]);
    exit;
}

$res = unifi_detect_sites($base, $key);
echo json_encode(['controller_url' => $base] + $res);
