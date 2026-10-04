<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * Dziennik zdarzeń dla logs.php.
 *
 * Źródłem jest lokalna tabela SQLite `events` (ta sama, którą zapisuje
 * cron_triggers.php i którą pokazuje dzwonek). Kontroler UniFi 10.x nie
 * udostępnia już zdarzeń kluczem API (stat/event = 404, rest/alarm = 400,
 * v2/system-log wymaga sesji z cookie) - dlatego czytamy swoje dane.
 *
 * Odpowiedź bez zmian względem starej wersji: logs.php woła ten plik dla
 * type=event i type=alarm, a wyniki scala po `id`.
 */
ini_set('display_errors', 0);
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

header('Content-Type: application/json');

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    http_response_code(401);
    echo json_encode(['success' => false, 'error' => 'Unauthorized']);
    exit;
}

if (!isset($db) || !($db instanceof PDO)) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'DB unavailable', 'data' => []]);
    exit;
}

$type  = ($_GET['type'] ?? 'event') === 'alarm' ? 'alarm' : 'event';
$limit = min(500, max(1, (int)($_GET['limit'] ?? 100)));

/** Kategoria do kolumny w logs.php - wyprowadzona z treści zdarzenia. */
function log_category(string $type, string $message): string
{
    if ($type === 'access') return 'Kontrola dostępu';
    $m = mb_strtolower($message);
    if (strpos($m, 'vpn') !== false)                                   return 'VPN';
    if (strpos($m, 'transfer') !== false)                             return 'Transfer';
    if (strpos($m, 'offline') !== false || strpos($m, 'online') !== false) return 'Status';
    if (strpos($m, 'wan') !== false || strpos($m, 'łącz') !== false || strpos($m, 'internet') !== false) return 'WAN';
    if (strpos($m, 'zagro') !== false || strpos($m, 'threat') !== false || strpos($m, 'blok') !== false) return 'Zagrożenie';
    if (strpos($m, 'logow') !== false || strpos($m, 'sesj') !== false || strpos($m, 'login') !== false)  return 'Dostęp';
    return 'Alert';
}

// type=alarm zwraca tylko istotne (CRITICAL/WARNING), type=event wszystko.
// logs.php scala oba po id, więc nakładanie się zbiorów nie tworzy duplikatów.
$sql = "SELECT id, type, severity, message, details_json, created_at FROM events";
if ($type === 'alarm') {
    $sql .= " WHERE severity IN ('CRITICAL', 'WARNING')";
}
$sql .= " ORDER BY id DESC LIMIT " . $limit;

$processed = [];
try {
    foreach ($db->query($sql, PDO::FETCH_ASSOC) as $ev) {
        $created = (string)($ev['created_at'] ?? '');
        $raw = null;
        if (!empty($ev['details_json'])) {
            $decoded = json_decode($ev['details_json'], true);
            if (is_array($decoded)) $raw = $decoded;
        }
        $sev = strtoupper((string)($ev['severity'] ?? 'INFO'));
        if (!in_array($sev, ['INFO', 'WARNING', 'ERROR', 'CRITICAL'], true)) $sev = 'INFO';

        $processed[] = [
            'id'       => 'db_' . $ev['id'],   // prefiks: id event != id alarm w scalaniu po stronie logs.php
            'date'     => $created,
            'ts'       => $created !== '' ? strtotime($created) : time(),
            'severity' => $sev,
            'category' => log_category((string)($ev['type'] ?? ''), (string)($ev['message'] ?? '')),
            'message'  => (string)($ev['message'] ?? ''),
            'raw'      => $raw,
        ];
    }
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['success' => false, 'error' => 'Query failed', 'data' => []]);
    exit;
}

echo json_encode(['success' => true, 'data' => $processed, 'type' => $type]);
