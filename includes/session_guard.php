<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * Strażnik sesji — dołączany na końcu config.php, czyli na KAŻDYM żądaniu (strony i API).
 *
 * - zalogowana sesja bez wpisu w user_sessions dostaje go (rejestracja),
 * - sesja zakończona z panelu (revoked_at) jest wylogowywana od razu, razem z jej
 *   tokenem „zapamiętaj mnie",
 * - wolne rzeczy (geolokalizacja IP, ocena „podejrzana", alert na Telegram) robimy
 *   dopiero po wysłaniu odpowiedzi, żeby logowanie nie zwalniało.
 */

function sg_db(): ?PDO
{
    static $db = false;
    if ($db !== false) return $db;
    $path = dirname(__DIR__) . '/data/minidash.db';
    if (!file_exists($path)) return $db = null;
    try {
        $db = new PDO("sqlite:$path");
        $db->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $db->exec("PRAGMA busy_timeout=5000");
        // Tabela powstaje w migracji 005 (db.php). Do tego czasu strażnik nic nie robi.
        if (!$db->query("SELECT name FROM sqlite_master WHERE type='table' AND name='user_sessions'")->fetchColumn()) $db = null;
    } catch (Throwable $e) {
        $db = null;
    }
    return $db;
}

/** System i przeglądarka z User-Agenta (ta sama heurystyka co w historii logowań). */
function sg_parse_ua(string $ua): array
{
    $os = 'Unknown OS';
    if (preg_match('/android/i', $ua)) $os = 'Android';
    elseif (preg_match('/iphone|ipad/i', $ua)) $os = 'iOS';
    elseif (preg_match('/windows|win32/i', $ua)) $os = 'Windows';
    elseif (preg_match('/macintosh|mac os x/i', $ua)) $os = 'macOS';
    elseif (preg_match('/linux/i', $ua)) $os = 'Linux';

    $browser = 'Browser';
    if (preg_match('/edg\//i', $ua)) $browser = 'Edge';
    elseif (preg_match('/opr\/|opera/i', $ua)) $browser = 'Opera';
    elseif (preg_match('/chrome/i', $ua)) $browser = 'Chrome';
    elseif (preg_match('/firefox/i', $ua)) $browser = 'Firefox';
    elseif (preg_match('/safari/i', $ua)) $browser = 'Safari';
    return [$os, $browser];
}

/**
 * Czy nowa sesja wygląda podejrzanie. Czysta funkcja — testowalna.
 * Logowanie spoza Polski jest podejrzane zawsze. Nowe IP samo nie wystarcza
 * (telefon zmienia IP co chwilę) — dopiero nowe IP i niewidziany wcześniej
 * zestaw system+przeglądarka.
 *
 * @param array $known ['ips' => [...], 'devices' => ['Windows|Chrome', ...]] z poprzednich sesji
 * @return string[] powody (pusta tablica = OK)
 */
function sg_suspicious_reasons(string $ip, string $location, string $os, string $browser, array $known): array
{
    $reasons = [];
    $local = $location === 'Local Network' || preg_match('/^(10\.|192\.168\.|127\.|172\.(1[6-9]|2\d|3[01])\.|::1$)/', $ip);
    if (!$local && $location !== 'Unknown Location' && stripos($location, '(Poland)') === false) {
        $reasons[] = 'foreign';
    }
    $seen_ip = in_array($ip, $known['ips'] ?? [], true);
    $seen_dev = in_array($os . '|' . $browser, $known['devices'] ?? [], true);
    if (!$local && !$seen_ip && !$seen_dev && (!empty($known['ips']) || !empty($known['devices']))) {
        $reasons[] = 'new_device';
    }
    return $reasons;
}

function sg_logout_revoked(PDO $db, array $row): void
{
    if (!empty($row['remember_selector'])) {
        $db->prepare("DELETE FROM remember_tokens WHERE selector = ?")->execute([$row['remember_selector']]);
    }
    if (!empty($_COOKIE['remember_me'])) {
        $sel = explode(':', $_COOKIE['remember_me'], 2)[0];
        $db->prepare("DELETE FROM remember_tokens WHERE selector = ?")->execute([$sel]);
        setcookie('remember_me', '', ['expires' => 1, 'path' => '/']);
    }
    session_unset();
    session_destroy();

    $is_api = preg_match('/^api_|update_wan\.php$/', basename($_SERVER['SCRIPT_NAME'] ?? ''))
        || stripos($_SERVER['HTTP_ACCEPT'] ?? '', 'application/json') !== false;
    if ($is_api) {
        http_response_code(401);
        header('Content-Type: application/json');
        echo json_encode(['success' => false, 'message' => 'Session revoked']);
    } else {
        header('Location: login.php?revoked=1');
    }
    exit;
}

/** Dopisuje lokalizację, ocenia sesję i ewentualnie alarmuje — po wysłaniu odpowiedzi. */
function sg_enrich(int $row_id): void
{
    if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
    $db = sg_db();
    if (!$db) return;
    try {
        $row = $db->prepare("SELECT * FROM user_sessions WHERE id = ?");
        $row->execute([$row_id]);
        $s = $row->fetch(PDO::FETCH_ASSOC);
        if (!$s) return;

        if (!function_exists('get_ip_location')) require_once dirname(__DIR__) . '/functions.php';
        $location = get_ip_location((string)$s['ip']);

        // Co znamy z przeszłości: IP i urządzenia z wcześniejszych sesji i historii logowań.
        $ips = $db->query("SELECT DISTINCT ip FROM user_sessions WHERE id <> " . (int)$row_id . "
                           UNION SELECT DISTINCT ip FROM login_history")->fetchAll(PDO::FETCH_COLUMN);
        $devs = $db->query("SELECT DISTINCT os || '|' || browser FROM user_sessions WHERE id <> " . (int)$row_id . "
                            UNION SELECT DISTINCT os || '|' || browser FROM login_history")->fetchAll(PDO::FETCH_COLUMN);
        $reasons = sg_suspicious_reasons((string)$s['ip'], $location, (string)$s['os'], (string)$s['browser'], ['ips' => $ips, 'devices' => $devs]);

        $db->prepare("UPDATE user_sessions SET location = ?, suspicious = ?, suspicious_reason = ? WHERE id = ?")
           ->execute([$location, $reasons ? 1 : 0, implode(',', $reasons), $row_id]);

        global $config;
        if ($reasons && ($config['security']['login_alert_enabled'] ?? true) && function_exists('sendAlert')) {
            $why = implode(', ', array_map(fn($r) => $r === 'foreign' ? 'logowanie spoza Polski' : 'nowe urządzenie i nowy adres IP', $reasons));
            sendAlert(
                'Logowanie z nieznanego urządzenia',
                "Nowa sesja MiniDash: **{$s['os']} / {$s['browser']}**, IP **{$s['ip']}**, {$location}.\n"
                . "Powód: {$why}.\n"
                . "Jeśli to nie Ty — zakończ sesję: MiniDash → Dane osobiste → Bezpieczeństwo konta.",
                'critical'
            );
        }
    } catch (Throwable $e) {
        error_log('MiniDash session guard enrich: ' . $e->getMessage());
    }
}

function session_guard(): void
{
    if (PHP_SAPI === 'cli' || empty($_SESSION['logged_in'])) return;
    $db = sg_db();
    if (!$db) return;

    try {
        if (!empty($_SESSION['sg_sid'])) {
            $q = $db->prepare("SELECT * FROM user_sessions WHERE sid = ?");
            $q->execute([$_SESSION['sg_sid']]);
            $row = $q->fetch(PDO::FETCH_ASSOC);
            if ($row && $row['revoked_at'] !== null) sg_logout_revoked($db, $row);
            if ($row) {
                // last_seen co najwyżej raz na minutę — bez zapisu do bazy przy każdym żądaniu.
                if (time() - (int)($_SESSION['sg_touch'] ?? 0) > 60) {
                    $db->prepare("UPDATE user_sessions SET last_seen = datetime('now'), ip = ?, remember_selector = COALESCE(?, remember_selector) WHERE id = ?")
                       ->execute([$_SERVER['REMOTE_ADDR'] ?? '', $_SESSION['remember_selector'] ?? null, $row['id']]);
                    $_SESSION['sg_touch'] = time();
                }
                return;
            }
        }

        // Rejestracja: świeże logowanie, przywrócenie z „zapamiętaj mnie" albo sesja sprzed wdrożenia.
        $sid = bin2hex(random_bytes(16));
        $ua = (string)($_SERVER['HTTP_USER_AGENT'] ?? '');
        [$os, $browser] = sg_parse_ua($ua);
        $via = $_SESSION['sg_via'] ?? 'existing';
        $selector = $_SESSION['remember_selector']
            ?? (!empty($_COOKIE['remember_me']) ? explode(':', $_COOKIE['remember_me'], 2)[0] : null);
        $db->prepare("INSERT INTO user_sessions (sid, username, ip, os, browser, user_agent, via, remember_selector) VALUES (?, ?, ?, ?, ?, ?, ?, ?)")
           ->execute([$sid, $_SESSION['username'] ?? 'admin', $_SERVER['REMOTE_ADDR'] ?? '', $os, $browser, substr($ua, 0, 500), $via, $selector]);
        $id = (int)$db->lastInsertId();
        $_SESSION['sg_sid'] = $sid;
        $_SESSION['sg_touch'] = time();
        unset($_SESSION['sg_via']);
        // Sesje sprzed wdrożenia nie alarmują — to nie są nowe logowania.
        if ($via !== 'existing') register_shutdown_function('sg_enrich', $id);
        else register_shutdown_function(function () use ($id) {
            if (function_exists('fastcgi_finish_request')) @fastcgi_finish_request();
            $db = sg_db();
            if (!$db) return;
            if (!function_exists('get_ip_location')) require_once dirname(__DIR__) . '/functions.php';
            $row = $db->query("SELECT ip FROM user_sessions WHERE id = " . (int)$id)->fetchColumn();
            $db->prepare("UPDATE user_sessions SET location = ? WHERE id = ?")->execute([get_ip_location((string)$row), $id]);
        });
    } catch (Throwable $e) {
        error_log('MiniDash session guard: ' . $e->getMessage());
    }
}

/**
 * Aktywne sesje do wyświetlenia: niezakończone i żywe — PHP-owa sesja nie wygasła
 * albo nadal ma ważny token „zapamiętaj mnie" (wtedy wróci sama, więc też się liczy).
 */
function get_active_sessions(PDO $db, int $timeout_min): array
{
    $q = $db->prepare("SELECT s.* FROM user_sessions s
        WHERE s.revoked_at IS NULL
          AND (s.last_seen >= datetime('now', ?)
               OR EXISTS (SELECT 1 FROM remember_tokens r WHERE r.selector = s.remember_selector AND r.expires_at > datetime('now')))
        ORDER BY s.last_seen DESC");
    $q->execute(['-' . max(1, $timeout_min) . ' minutes']);
    return $q->fetchAll(PDO::FETCH_ASSOC);
}

/** Kończy sesję: oznacza ją i od razu kasuje jej token „zapamiętaj mnie". */
function revoke_session(PDO $db, int $id): bool
{
    $q = $db->prepare("SELECT remember_selector FROM user_sessions WHERE id = ? AND revoked_at IS NULL");
    $q->execute([$id]);
    $sel = $q->fetchColumn();
    if ($sel === false) return false;
    if ($sel) $db->prepare("DELETE FROM remember_tokens WHERE selector = ?")->execute([$sel]);
    $db->prepare("UPDATE user_sessions SET revoked_at = datetime('now') WHERE id = ?")->execute([$id]);
    return true;
}

/**
 * Kończy wszystkie sesje poza bieżącą i kasuje wszystkie inne tokeny „zapamiętaj mnie"
 * — także te sprzed wdrożenia rejestru, których nie da się przypisać do sesji.
 */
function revoke_other_sessions(PDO $db, string $current_sid, ?string $current_selector): int
{
    $q = $db->prepare("UPDATE user_sessions SET revoked_at = datetime('now') WHERE revoked_at IS NULL AND sid <> ?");
    $q->execute([$current_sid]);
    $n = $q->rowCount();
    if ($current_selector) $db->prepare("DELETE FROM remember_tokens WHERE selector <> ?")->execute([$current_selector]);
    else $db->exec("DELETE FROM remember_tokens");
    return $n;
}

session_guard();
