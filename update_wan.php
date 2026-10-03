<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * MiniDash - Update WAN Stats
 */
error_reporting(E_ALL & ~E_NOTICE);
ini_set('display_errors', 0);
ob_start();

require_once __DIR__ . '/config.php';
require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

header('Content-Type: application/json');

// Cron woła ten plik z CLI; z przeglądarki tylko po zalogowaniu (zwraca IP WAN i ruch).
if (PHP_SAPI !== 'cli' && empty($_SESSION['logged_in'])) {
    ob_end_clean();
    http_response_code(401);
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit;
}

$siteId = $config['site'];
$file = __DIR__ . '/data/wan_stats.json';
$history = [];

try {
    // 1. Fetch Traditional Device Stats (more reliable for real-time rates)
    $tradSite = get_trad_site_id($config['site']);
    $trad_resp = fetch_api("/proxy/network/api/s/{$tradSite}/stat/device");
    $trad_devices = $trad_resp['data'] ?? [];
    
    // 2. Fetch Infrastructure (Modern list)
    $resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/devices");
    if (empty($resp['data']) && $siteId !== 'default') {
        $siteId = 'default';
        $resp = fetch_api("/proxy/network/integration/v1/sites/default/devices");
    }
    $devices = $resp['data'] ?? [];
    
    // Brama z traditional API — po MAC-u z Integration API, a gdy ten nic nie zwrócił
    // (albo model jest spoza listy) po obecności klucza wan1.
    $gateway = null;
    foreach ($devices as $d) {
        $model = strtoupper($d['model'] ?? '');
        $type = strtolower($d['type'] ?? '');
        if (in_array($model, ['UDR', 'UDM', 'UXG', 'USG']) ||
            strpos($model, 'DREAM') !== false ||
            $type === 'udm' ||
            $type === 'gateway' ||
            isset($d['wan1'])) {
            $gateway = $d;
            break;
        }
    }

    $trad_gateway = null;
    if ($gateway) {
        $g_mac = normalize_mac($gateway['macAddress'] ?? $gateway['mac'] ?? '');
        foreach ($trad_devices as $td) {
            if (normalize_mac($td['mac'] ?? '') === $g_mac) { $trad_gateway = $td; break; }
        }
    }
    if (!$trad_gateway) $trad_gateway = find_trad_gateway($trad_devices);

    $wan_links = get_wan_links($trad_gateway);

    $rx = 0;
    $tx = 0;
    foreach ($wan_links as $l) {
        $rx += $l['rx'];
        $tx += $l['tx'];
    }

    // Fallback to integration stats if 0
    if ($rx == 0 && $tx == 0 && $gateway) {
        $rx = $gateway['uplink']['rxRateBps'] ?? $gateway['wan1']['rxRateBps'] ?? 0;
        $tx = $gateway['uplink']['txRateBps'] ?? $gateway['wan1']['txRateBps'] ?? 0;
    }

    // 3. Save History for Chart
    $history = file_exists($file) ? json_decode(file_get_contents($file), true) : [];
    if (!is_array($history)) $history = [];

    // rx/tx to suma wszystkich łączy (wykres zbiorczy), wans[] rozbija ją na łącza.
    // Starsze wpisy nie mają klucza wans — front musi to znieść.
    $history[] = [
        'timestamp' => time(),
        'rx' => (float)$rx,
        'tx' => (float)$tx,
        'wans' => array_map(fn($l) => [
            'idx'  => $l['idx'],
            'name' => $l['name'],
            'ip'   => $l['ip'],
            'up'   => $l['up'],
            'rx'   => $l['rx'],
            'tx'   => $l['tx'],
        ], $wan_links)
    ];

    if (count($history) > 60) $history = array_slice($history, -60);
    file_put_contents($file, json_encode($history));

    if (isset($db)) {
        // wan_idx = 0 → wiersz zbiorczy (zgodny z tym, co zapisywano wcześniej),
        // 1..n → poszczególne łącza. Zapytania muszą filtrować po wan_idx.
        // Jedna transakcja na cykl: przy dwóch łączach to trzy INSERT-y, a baza leży na
        // udziale sieciowym, gdzie każde osobne zdjęcie blokady potrafi skończyć się
        // "database is locked" (takie wpisy są już w logs/cron_errors.log).
        $stmt = $db->prepare("INSERT INTO wan_stats (rx_bytes, tx_bytes, wan_idx, up) VALUES (?, ?, ?, ?)");
        $db->beginTransaction();
        try {
            $stmt->execute([$rx ?? 0, $tx ?? 0, 0, 1]);
            foreach ($wan_links as $l) {
                $stmt->execute([$l['rx'], $l['tx'], $l['idx'], $l['up'] ? 1 : 0]);
            }
            $db->commit();
        } catch (Exception $e) {
            $db->rollBack();
            throw $e;
        }
    }

    // 4. Update Monitored Devices Status History
    $monitored_config = loadDevices();
    if (!empty($monitored_config)) {
        // PRIMARY: Traditional stat/sta — zwraca WSZYSTKICH klientów ze WSZYSTKICH VLANów
        $trad_sta = fetch_api("/proxy/network/api/s/$tradSite/stat/sta");
        $all_active_clients = $trad_sta['data'] ?? [];

        // ENRICHMENT: Integration API v1 — dodaje rxRateBps/txRateBps gdy traditional rates = 0
        $integ_resp = fetch_api("/proxy/network/integration/v1/sites/$siteId/clients?limit=1000");
        $integ_map = [];
        foreach (($integ_resp['data'] ?? []) as $ic) {
            $imac = normalize_mac($ic['macAddress'] ?? $ic['mac'] ?? '');
            if ($imac) $integ_map[$imac] = $ic;
        }
        foreach ($all_active_clients as &$c) {
            $cmac = normalize_mac($c['mac'] ?? '');
            if (isset($integ_map[$cmac])) {
                $ic = $integ_map[$cmac];
                if (empty($c['rx_rate'])) $c['rx_rate'] = $ic['rxRateBps'] ?? 0;
                if (empty($c['tx_rate'])) $c['tx_rate'] = $ic['txRateBps'] ?? 0;
            }
        }
        unset($c);

        $statuses = detect_known_devices($all_active_clients, $monitored_config);
        $last_speeds_file = __DIR__ . '/data/last_speeds.json';
        $last_speeds = file_exists($last_speeds_file) ? json_decode(file_get_contents($last_speeds_file), true) : [];
        if (!is_array($last_speeds)) $last_speeds = [];

        $threshold_bps = ($config['triggers']['speed_threshold_mbps'] ?? 100) * 1000 * 1000;
        $speed_alert_enabled = $config['triggers']['speed_alert_enabled'] ?? false;

        foreach ($statuses as $mac => $info) {
            saveDeviceHistory($mac, $info['status']);
            
            // Record Client Stats History
            if (isset($db) && $info['status'] === 'on') {
                $stmt_hist = $db->prepare("INSERT INTO client_history (mac, rx_bytes, tx_bytes, ip, vlan, seen_at) VALUES (?, ?, ?, ?, ?, datetime('now'))");
                $stmt_hist->execute([
                    $mac, 
                    $info['rx_bytes'] ?? 0, 
                    $info['tx_bytes'] ?? 0,
                    $info['ip'] ?? '',
                    $info['vlan'] ?? 0
                ]);
            }

            // Speed Spike Check
            if ($speed_alert_enabled && $info['status'] === 'on') {
                $current_speed = max($info['rx_rate'], $info['tx_rate']);
                $last_speed = $last_speeds[$mac] ?? 0;

                if ($current_speed > $threshold_bps && $last_speed <= $threshold_bps) {
                    $mbps = round($current_speed / 1000000, 1);
                    $name = $info['name'] ?? $mac;
                    sendAlert(
                        "Wzrost transferu: $name",
                        "Urządzenie **$name** ($mac) generuje duzy ruch: **$mbps Mbps**.",
                        'warning'
                    );
                }
                $last_speeds[$mac] = $current_speed;
            }
        }
        file_put_contents($last_speeds_file, json_encode($last_speeds));
    }

    // === TRIGGER: New Device Detection ===
    if ($config['triggers']['new_device_alert_enabled'] ?? false) {
        $known_macs_file = __DIR__ . '/data/known_macs.json';
        $known_macs = file_exists($known_macs_file) ? json_decode(file_get_contents($known_macs_file), true) : [];
        if (!is_array($known_macs)) $known_macs = [];
        $is_first_run = !isset($known_macs['_initialized']);

        // Cooldown: max 1 alert batch per 5 minutes
        $last_new_device_alert = $known_macs['_last_alert'] ?? 0;
        $can_alert = !$is_first_run && (time() - $last_new_device_alert > 300);

        $sta_resp = fetch_api('/proxy/network/api/s/default/stat/sta');
        $new_count = 0;
        foreach (($sta_resp['data'] ?? []) as $client) {
            $mac = strtolower($client['mac'] ?? '');
            if (!$mac) continue;
            if (!isset($known_macs[$mac])) {
                $name = $client['name'] ?? $client['hostname'] ?? $mac;
                $ip = $client['ip'] ?? $client['last_ip'] ?? '';
                $vlan_id = detect_vlan_id($ip, $client['vlan'] ?? null);
                $vlan_name = get_vlan_name($vlan_id);
                $network = $client['essid'] ?? $client['network'] ?? '';
                $is_wired = !empty($client['is_wired']);
                $known_macs[$mac] = ['name' => $name, 'first_seen' => date('Y-m-d H:i:s')];
                $uplink_device = $client['sw_mac'] ?? $client['ap_mac'] ?? '';
                $sw_port = $client['sw_port'] ?? 0;
                if ($can_alert && $new_count < 3) {
                    $details = "📡 IP: $ip | 🏷️ $vlan_name";
                    if ($is_wired) {
                        if ($uplink_device) {
                            $uplink_name = get_infra_device_name_by_mac($uplink_device);
                            $uplink_label = $uplink_name ?: strtoupper(substr($uplink_device, -8));
                            $details .= " | 🔌 Uplink: $uplink_label" . ($sw_port ? ":$sw_port" : '');
                        } else {
                            $details .= " | 🔌 Ethernet";
                        }
                    } else {
                        if ($network) $details .= " | 📶 $network";
                    }
                    sendAlert(
                        "Nowe urzadzenie: $name",
                        "$details\nMAC: $mac",
                        'warning'
                    );
                    $new_count++;
                    $known_macs['_last_alert'] = time();
                }
            }
        }
        $known_macs['_initialized'] = true;
        file_put_contents($known_macs_file, json_encode($known_macs));
    }

    // === TRIGGER: IPS/IDS Alert ===
    if ($config['triggers']['ips_alert_enabled'] ?? false) {
        $last_ips_check = $_SESSION['last_ips_alert_check'] ?? 0;
        $now = time();
        if ($now - $last_ips_check > 60) { // Check every 60s max
            $ips_resp = fetch_api('/proxy/network/api/s/default/rest/alarm?limit=5');
            $ips_events = $ips_resp['data'] ?? [];
            $last_ips_id_file = __DIR__ . '/data/last_ips_event_id.txt';
            $last_id = file_exists($last_ips_id_file) ? trim(file_get_contents($last_ips_id_file)) : '';

            foreach ($ips_events as $evt) {
                $evt_id = $evt['_id'] ?? '';
                if ($evt_id === $last_id) break; // Already seen
                $action = $evt['inner_alert_action'] ?? '';
                if ($action === 'blocked') {
                    $src = $evt['src_ip'] ?? '?';
                    $dst = $evt['dest_ip'] ?? 'Local';
                    $port = $evt['dest_port'] ?? '';
                    $proto = $evt['proto'] ?? 'TCP';
                    $sig = $evt['inner_alert_signature'] ?? 'Unknown';
                    $cat = $evt['inner_alert_category'] ?? 'Threat';
                    $cc = strtoupper($evt['srcipCountry'] ?? '??');
                    sendAlert(
                        "Zablokowano Atak!",
                        "⚠️ $cat | 🌍 $cc | 🛡️ $sig\nZrodlo: **$src** → $dst" . ($port ? ":$port" : "") . " ($proto)",
                        'critical'
                    );
                    break;
                }
            }
            if (!empty($ips_events[0]['_id'])) {
                file_put_contents($last_ips_id_file, $ips_events[0]['_id']);
            }
            $_SESSION['last_ips_alert_check'] = $now;
        }
    }

    // === TRIGGER: High Latency ===
    if ($config['triggers']['latency_alert_enabled'] ?? false) {
        $latency_threshold = $config['triggers']['latency_threshold_ms'] ?? 100;
        $dev_resp_lat = fetch_api('/proxy/network/api/s/default/stat/device');
        foreach (($dev_resp_lat['data'] ?? []) as $d) {
            if (in_array($d['type'] ?? '', ['ugw', 'udm', 'uxg'])) {
                $latency = $d['wan1']['latency'] ?? $d['uplink']['latency'] ?? 0;
                if ($latency > $latency_threshold) {
                    $last_lat_alert = $_SESSION['last_latency_alert'] ?? 0;
                    if (time() - $last_lat_alert > 300) { // 5 min cooldown
                        sendAlert(
                            "Wysoka latencja WAN: {$latency}ms",
                            "Opoznienie lacza WAN wynosi **{$latency}ms** (prog: {$latency_threshold}ms).",
                            'warning'
                        );
                        $_SESSION['last_latency_alert'] = time();
                    }
                }
                break;
            }
        }
    }

    // === TRIGGER: VPN Connection Alert ===
    if ($config['triggers']['vpn_alert_enabled'] ?? false) {
        $last_vpn_check = $_SESSION['last_vpn_alert_check'] ?? 0;
        if (time() - $last_vpn_check > 30) {
            $vpn_resp = fetch_api("/proxy/network/api/s/$tradSite/stat/event?limit=20&_sort=-time");
            $last_vpn_id_file = __DIR__ . '/data/last_vpn_event_id.txt';
            $last_vpn_id = file_exists($last_vpn_id_file) ? trim(file_get_contents($last_vpn_id_file)) : '';

            foreach (($vpn_resp['data'] ?? []) as $evt) {
                $evt_id = $evt['_id'] ?? '';
                if ($evt_id === $last_vpn_id) break;
                $key = $evt['key'] ?? '';
                if (strpos($key, 'EVT_VPN') !== false || stripos($key, 'vpn') !== false) {
                    $msg = $evt['msg'] ?? 'VPN event';
                    $is_connect = stripos($key, 'connect') !== false && stripos($key, 'disconnect') === false;
                    $icon = $is_connect ? 'VPN Polaczono' : 'VPN Rozlaczono';
                    $severity = $is_connect ? 'info' : 'warning';
                    sendAlert("$icon", $msg, $severity);
                    break;
                }
            }
            if (!empty($vpn_resp['data'][0]['_id'])) {
                file_put_contents($last_vpn_id_file, $vpn_resp['data'][0]['_id']);
            }
            $_SESSION['last_vpn_alert_check'] = time();
        }
    }

} catch (Exception $e) {
    // Silently continue
}

ob_end_clean();
echo json_encode(!empty($history) ? $history : []);




