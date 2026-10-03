<?php
/**
 * Mapa sieci (topologia) z danych UniFi: stat/device + stat/sta.
 *
 * UniFi nie widzi zwykłych (niezarządzanych) switchy i wtedy zgłasza uplinki
 * nieprawdziwie - np. AP „wpięty" w port uplinkowy innego switcha. Wykrywamy je
 * z LLDP: jeśli na jednym porcie urządzenie widzi naraz kilku sąsiadów UniFi
 * (albo sąsiada i klientów przewodowych), to między nimi stoi switch
 * niezarządzany. Wstawiamy wtedy wirtualny węzeł i podpinamy pod niego tych
 * sąsiadów oraz klientów z tego portu. Nazwa węzła = nazwa portu z UniFi.
 */

/** MAC w formacie aa:bb:cc:dd:ee:ff (małe litery). */
function nm_mac($mac): string
{
    $h = strtolower(preg_replace('/[^0-9a-fA-F]/', '', (string)$mac));
    return strlen($h) === 12 ? implode(':', str_split($h, 2)) : '';
}

/**
 * Buduje drzewo topologii. Czysta funkcja - bez API, łatwa do testowania.
 *
 * @return array|null węzeł główny: ['kind' => device|unmanaged, 'mac','name','type','model',
 *                    'ip','online','uptime','port','hint','children' => [], 'clients' => []]
 */
function build_network_tree(array $devs, array $clients): ?array
{
    $nodes = [];
    foreach ($devs as $d) {
        $mac = nm_mac($d['mac'] ?? '');
        if ($mac === '') continue;
        $uplink_ports = $port_names = [];
        foreach ((array)($d['port_table'] ?? []) as $p) {
            $idx = (int)($p['port_idx'] ?? 0);
            if (!empty($p['is_uplink'])) $uplink_ports[$idx] = true;
            $port_names[$idx] = trim((string)($p['name'] ?? ''));
        }
        $lldp = [];
        foreach ((array)($d['lldp_table'] ?? []) as $l) {
            $n = nm_mac($l['chassis_id'] ?? '');
            if ($n !== '' && $n !== $mac) $lldp[(int)($l['local_port_idx'] ?? 0)][$n] = true;
        }
        $nodes[$mac] = [
            'kind'     => 'device',
            'mac'      => $mac,
            'name'     => (string)($d['name'] ?? $d['model'] ?? $mac),
            'type'     => (string)($d['type'] ?? ''),
            'model'    => (string)($d['model'] ?? ''),
            'ip'       => (string)($d['ip'] ?? ''),
            'online'   => (int)($d['state'] ?? 0) === 1,
            'uptime'   => (int)($d['uptime'] ?? 0),
            'port'     => null,
            'hint'     => '',
            'children' => [],
            'clients'  => [],
            '_uplink'  => nm_mac($d['uplink']['uplink_mac'] ?? ''),
            '_uport'   => isset($d['uplink']['uplink_remote_port']) ? (int)$d['uplink']['uplink_remote_port'] : null,
            '_uports'  => $uplink_ports,
            '_pnames'  => $port_names,
            '_lldp'    => $lldp,
        ];
    }
    if (!$nodes) return null;

    $root = null;
    foreach ($nodes as $m => $n) if (in_array($n['type'], ['udm', 'ugw', 'uxg'], true)) { $root = $m; break; }
    if ($root === null) foreach ($nodes as $m => $n) if ($n['_uplink'] === '') { $root = $m; break; }
    $root = $root ?? array_key_first($nodes);

    // Klienci przewodowi per (urządzenie, port) - potrzebni do wykrywania switchy.
    $wired_at = [];
    foreach ($clients as $c) {
        if (empty($c['is_wired'])) continue;
        $sw = nm_mac($c['sw_mac'] ?? '');
        if ($sw !== '' && isset($c['sw_port'])) $wired_at[$sw][(int)$c['sw_port']] = ($wired_at[$sw][(int)$c['sw_port']] ?? 0) + 1;
    }

    // 1. Switche niezarządzane: port (nie uplinkowy) z ≥2 sąsiadami albo sąsiadem + klientami.
    //    Port, na którym widać bramę, to droga „w górę" - pomijamy (wyjątek: sama brama).
    $vsw = [];        // klucz => [owner, port, members[]]
    $member_of = [];  // mac urządzenia => klucz switcha (pierwszy wygrywa: brama sprawdzana pierwsza)
    $order = array_merge([$root], array_values(array_diff(array_keys($nodes), [$root])));
    foreach ($order as $m) {
        foreach ($nodes[$m]['_lldp'] as $port => $neigh) {
            if (isset($nodes[$m]['_uports'][$port])) continue;
            if ($m !== $root && isset($neigh[$root])) continue;
            $members = array_keys($neigh);
            $known = array_values(array_filter($members, fn($x) => isset($nodes[$x])));
            if (count($members) < 2 && !($members && !empty($wired_at[$m][$port]))) continue;
            $key = 'vsw:' . $m . ':' . $port;
            $vsw[$key] = ['owner' => $m, 'port' => $port, 'members' => $known];
            foreach ($known as $k) $member_of[$k] = $member_of[$k] ?? $key;
        }
    }
    foreach ($vsw as $key => $v) {
        $pname = $nodes[$v['owner']]['_pnames'][$v['port']] ?? '';
        if (preg_match('/^port\s*\d+$/i', $pname)) $pname = '';
        $nodes[$key] = [
            'kind' => 'unmanaged', 'mac' => '', 'name' => $pname, 'type' => 'unmanaged', 'model' => '', 'ip' => '',
            'online' => true, 'uptime' => 0, 'port' => null, 'hint' => 'lldp', 'children' => [], 'clients' => [],
            '_uplink' => '', '_uport' => null, '_uports' => [], '_pnames' => [], '_lldp' => [],
        ];
    }

    // 2. Rodzice.
    $parent = [];  // węzeł => [rodzic, port na rodzicu]
    foreach ($vsw as $key => $v) $parent[$key] = [$v['owner'], $v['port']];
    foreach ($nodes as $m => $n) {
        if ($m === $root || $n['kind'] !== 'device') continue;
        $u = $n['_uplink'];
        if (isset($member_of[$m])) {
            $key = $member_of[$m];
            $parent[$m] = [$key, null];
            // Nieznany MAC w uplinku albo w LLDP członka = najpewniej sam switch (np. Netgear
            // „Plus" wysyła LLDP). UniFi raz podaje go jako uplink, raz nie - patrzymy w oba.
            $unknown = array_merge([$u], array_keys(array_merge(...array_values($n['_lldp'] ?: [[]]))));
            foreach ($unknown as $x) {
                if ($x !== '' && !isset($nodes[$x]) && $nodes[$key]['mac'] === '') $nodes[$key]['mac'] = $x;
            }
        } elseif ($u !== '' && isset($nodes[$u])) {
            $parent[$m] = [$u, $n['_uport']];
        } elseif ($u !== '') {
            // Nieznany uplink, którego nie wykryliśmy po LLDP - osobny węzeł pod bramą.
            $uk = 'vsw:mac:' . $u;
            if (!isset($nodes[$uk])) {
                $nodes[$uk] = ['kind' => 'unmanaged', 'mac' => $u, 'name' => '', 'type' => 'unmanaged', 'model' => '', 'ip' => '',
                    'online' => true, 'uptime' => 0, 'port' => null, 'hint' => 'uplink', 'children' => [], 'clients' => [],
                    '_uplink' => '', '_uport' => null, '_uports' => [], '_pnames' => [], '_lldp' => []];
                $parent[$uk] = [$root, null];
            }
            $parent[$m] = [$uk, $n['_uport']];
        } else {
            $parent[$m] = [$root, null];
        }
    }
    // Ochrona przed cyklem: węzeł, który nie dochodzi do korzenia, podpinamy pod korzeń.
    foreach (array_keys($parent) as $m) {
        $seen = [$m => true];
        $p = $parent[$m][0];
        while ($p !== $root && isset($parent[$p]) && !isset($seen[$p])) { $seen[$p] = true; $p = $parent[$p][0]; }
        if ($p !== $root) $parent[$m] = [$root, null];
    }

    // 3. Klienci: przewodowy z portu ze switchem niezarządzanym trafia pod ten switch.
    foreach ($clients as $c) {
        $wired = !empty($c['is_wired']);
        $at = nm_mac($wired ? ($c['sw_mac'] ?? '') : ($c['ap_mac'] ?? ''));
        $port = $wired && isset($c['sw_port']) ? (int)$c['sw_port'] : null;
        $host = isset($nodes[$at]) ? $at : $root;
        if ($wired && isset($nodes['vsw:' . $at . ':' . $port])) $host = 'vsw:' . $at . ':' . $port;
        $nodes[$host]['clients'][] = [
            'name'   => (string)($c['name'] ?? $c['hostname'] ?? $c['mac'] ?? '?'),
            'ip'     => (string)($c['ip'] ?? ''),
            'wired'  => $wired,
            'port'   => $port,
            'essid'  => $wired ? '' : (string)($c['essid'] ?? ''),
            'signal' => $wired ? null : ($c['signal'] ?? null),
        ];
    }
    foreach ($nodes as &$n) usort($n['clients'], fn($a, $b) => strcasecmp($a['name'], $b['name']));
    unset($n);

    // 4. Składanie: dzieci po porcie rodzica, potem po nazwie.
    $children = [];
    foreach ($parent as $m => [$p, $port]) $children[$p][] = [$m, $port];
    $build = function (string $m, $port) use (&$build, &$nodes, $children): array {
        $n = $nodes[$m];
        $n['port'] = $port;
        $kids = $children[$m] ?? [];
        usort($kids, fn($a, $b) => [$a[1] ?? 999, $nodes[$a[0]]['name']] <=> [$b[1] ?? 999, $nodes[$b[0]]['name']]);
        foreach ($kids as [$km, $kp]) $n['children'][] = $build($km, $kp);
        unset($n['_uplink'], $n['_uport'], $n['_uports'], $n['_pnames'], $n['_lldp']);
        return $n;
    };
    return $build($root, null);
}

/** Rysuje drzewo jako zagnieżdżoną listę. */
function render_network_tree(?array $tree): void
{
    if (!$tree) {
        echo '<p class="text-slate-500 text-sm">' . htmlspecialchars(__('netmap.empty')) . '</p>';
        return;
    }
    echo '<div class="nm-internet"><i data-lucide="globe" class="w-4 h-4"></i>Internet</div>';
    echo '<ul class="nm-tree nm-root">';
    nm_render_node($tree);
    echo '</ul>';
}

function nm_render_node(array $n): void
{
    $h = fn($s) => htmlspecialchars((string)$s);
    $um = $n['kind'] === 'unmanaged';
    $icon = $um ? 'git-fork' : (in_array($n['type'], ['udm', 'ugw', 'uxg'], true) ? 'router' : ($n['type'] === 'uap' ? 'wifi' : 'layers'));
    $name = $um ? ($n['name'] !== '' ? $n['name'] : __('netmap.unmanaged')) : $n['name'];
    $sub = $um
        ? __('netmap.unmanaged') . ($n['mac'] !== '' ? ' · ' . $n['mac'] : '')
        : trim($n['model'] . ($n['ip'] !== '' ? ' · ' . $n['ip'] : ''));
    $cnt = count($n['clients']);
    echo '<li>';
    if ($n['port'] !== null) echo '<span class="nm-port">' . $h(__('netmap.port')) . ' ' . (int)$n['port'] . '</span>';
    echo '<div class="nm-node' . ($um ? ' nm-um' : '') . ($n['online'] ? '' : ' nm-off') . '"'
        . ($um ? ' title="' . $h(__('netmap.unmanaged_hint')) . '"' : '') . '>';
    echo '<span class="nm-ico"><i data-lucide="' . $icon . '" class="w-4 h-4"></i></span>';
    echo '<span class="min-w-0 flex-1"><span class="nm-name">' . $h($name) . '</span><span class="nm-sub">' . $h($sub) . '</span></span>';
    if (!$um) echo '<span class="nm-dot" title="' . ($n['online'] ? 'online' : 'offline') . '"></span>';
    if ($cnt) {
        echo '<button type="button" class="nm-cnt" onclick="this.closest(\'li\').classList.toggle(\'nm-open\')" title="' . $h(__('netmap.clients')) . '">'
            . '<i data-lucide="users" class="w-3 h-3"></i>' . $cnt . '</button>';
    }
    echo '</div>';
    if ($cnt) {
        echo '<div class="nm-clients">';
        foreach ($n['clients'] as $c) {
            $meta = $c['wired'] ? ($c['port'] !== null && !$um ? __('netmap.port') . ' ' . $c['port'] : '') : $c['essid'];
            echo '<span class="nm-client"><i data-lucide="' . ($c['wired'] ? 'cable' : 'wifi') . '" class="w-3 h-3 shrink-0"></i>'
                . '<b>' . $h($c['name']) . '</b>'
                . ($c['ip'] !== '' ? '<em>' . $h($c['ip']) . '</em>' : '')
                . ($meta !== '' ? '<em>' . $h($meta) . '</em>' : '') . '</span>';
        }
        echo '</div>';
    }
    if ($n['children']) {
        echo '<ul class="nm-tree">';
        foreach ($n['children'] as $k) nm_render_node($k);
        echo '</ul>';
    }
    echo '</li>';
}
