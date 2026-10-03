<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}

$fw = get_firewall_view();
$rules = $fw['rules'];

// Grupowanie po parze stref, jak macierz stref w konsoli.
$pairs = [];
foreach ($rules as $r) $pairs[$r['src_zone'] . ' → ' . $r['dst_zone']][] = $r;

$cnt_user  = count(array_filter($rules, fn($r) => $r['user']));
$cnt_block = count(array_filter($rules, fn($r) => $r['user'] && $r['action'] === 'BLOCK'));
$cnt_off   = count(array_filter($rules, fn($r) => $r['user'] && !$r['enabled']));

function fw_hits(?int $h): string
{
    if ($h === null) return '-';
    if ($h >= 1000000) return number_format($h / 1000000, 1, ',', ' ') . ' M';
    if ($h >= 1000) return number_format($h / 1000, 1, ',', ' ') . ' k';
    return (string)$h;
}

function fw_ago(?int $ts): string
{
    if (!$ts) return '';
    $d = time() - $ts;
    if ($d < 60) return __('firewall.just_now');
    if ($d < 3600) return floor($d / 60) . ' min';
    if ($d < 86400) return floor($d / 3600) . ' h';
    return floor($d / 86400) . ' d';
}
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('firewall.title') ?> | MiniDash</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="pt-24 pb-12 antialiased">
    <?php render_nav(__('firewall.title')); ?>

    <div class="max-w-7xl mx-auto px-6">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-6">
            <div>
                <h2 class="text-3xl font-black text-white tracking-tight"><?= __('firewall.title') ?></h2>
                <p class="text-slate-500 mt-1 font-medium">
                    <?= $cnt_user ?> <?= __('firewall.own_rules') ?> · <?= $cnt_block ?> <?= __('firewall.blocking') ?> · <?= $cnt_off ?> <?= __('firewall.disabled') ?> · <?= count($rules) ?> <?= __('firewall.total') ?>
                </p>
            </div>
            <div class="flex flex-wrap items-center gap-2">
                <input type="text" id="fw-search" placeholder="<?= htmlspecialchars(__('firewall.search'), ENT_QUOTES) ?>" class="bg-slate-900/50 border border-white/10 rounded-xl px-4 py-2 text-sm text-slate-200 focus:outline-none focus:ring-2 focus:ring-blue-500/50 w-56">
                <select id="fw-action" class="bg-slate-900/50 border border-white/10 rounded-xl px-3 py-2 text-sm text-slate-200 focus:outline-none">
                    <option value=""><?= __('firewall.all_actions') ?></option>
                    <option value="ALLOW">ALLOW</option>
                    <option value="BLOCK">BLOCK</option>
                    <option value="REJECT">REJECT</option>
                </select>
                <label class="flex items-center gap-2 px-3 py-2 bg-white/5 border border-white/10 rounded-xl text-sm text-slate-300 cursor-pointer">
                    <input type="checkbox" id="fw-system" class="accent-blue-500"> <?= __('firewall.show_system') ?>
                </label>
                <a href="index.php" class="p-2.5 bg-white/5 border border-white/10 rounded-xl text-slate-400 hover:text-white transition" title="Dashboard">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
            </div>
        </div>

        <?php if ($fw['error']): ?>
            <div class="py-24 text-center bg-slate-900/40 rounded-3xl border border-white/5 border-dashed text-slate-400"><?= __('firewall.load_error') ?> (<?= htmlspecialchars($fw['error']) ?>)</div>
        <?php else: ?>
        <div class="space-y-6" id="fw-groups">
            <?php foreach ($pairs as $pair => $list): ?>
            <div class="glass-card p-5 fw-group">
                <div class="flex items-center gap-3 mb-3">
                    <i data-lucide="arrow-right-left" class="w-4 h-4 text-slate-500"></i>
                    <h3 class="text-sm font-black uppercase tracking-widest text-slate-300"><?= htmlspecialchars($pair) ?></h3>
                    <span class="text-[11px] font-mono text-slate-600 fw-count"></span>
                </div>
                <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <tbody>
                    <?php foreach ($list as $r):
                        $ac = $r['action'] === 'ALLOW' ? 'emerald' : 'red';
                    ?>
                        <tr class="fw-row border-t border-white/5 <?= $r['enabled'] ? '' : 'opacity-50' ?>"
                            data-user="<?= $r['user'] ? '1' : '0' ?>" data-action="<?= htmlspecialchars($r['action']) ?>"
                            data-search="<?= htmlspecialchars(mb_strtolower($r['name'] . ' ' . $r['src'] . ' ' . $r['dst'] . ' ' . $r['ips_full'] . ' ' . $pair)) ?>">
                            <td class="py-2.5 pr-3 w-12">
                                <?php if ($r['user']): ?>
                                <button type="button" role="switch" aria-checked="<?= $r['enabled'] ? 'true' : 'false' ?>" data-id="<?= htmlspecialchars($r['id']) ?>" data-name="<?= htmlspecialchars($r['name']) ?>"
                                        onclick="toggleFwRule(this)" title="<?= htmlspecialchars(__('firewall.toggle_hint'), ENT_QUOTES) ?>"
                                        class="relative w-9 h-5 rounded-full transition-colors <?= $r['enabled'] ? 'bg-blue-500' : 'bg-slate-700' ?>">
                                    <span class="absolute top-0.5 left-0.5 w-4 h-4 rounded-full bg-white shadow transition-transform <?= $r['enabled'] ? 'translate-x-4' : '' ?>"></span>
                                </button>
                                <?php else: ?>
                                <i data-lucide="lock" class="w-3.5 h-3.5 text-slate-600" title="<?= htmlspecialchars(__('firewall.system_rule'), ENT_QUOTES) ?>"></i>
                                <?php endif; ?>
                            </td>
                            <td class="py-2.5 pr-3 w-20">
                                <span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-widest bg-<?= $ac ?>-500/15 text-<?= $ac ?>-400"><?= htmlspecialchars($r['action']) ?></span>
                            </td>
                            <td class="py-2.5 pr-3">
                                <div class="font-bold text-slate-200"><?= htmlspecialchars($r['name']) ?></div>
                                <div class="text-[11px] text-slate-500 font-mono"<?= $r['ips_full'] !== '' ? ' title="' . htmlspecialchars($r['ips_full']) . '"' : '' ?>>
                                    <?= htmlspecialchars($r['src']) ?> <span class="text-slate-600">→</span> <?= htmlspecialchars($r['dst']) ?>
                                    <?php if ($r['protocol']): ?><span class="ml-2 text-slate-600"><?= htmlspecialchars($r['protocol']) ?></span><?php endif; ?>
                                    <?php if ($r['schedule']): ?><span class="ml-2 text-amber-500/80"><i data-lucide="clock" class="w-3 h-3 inline -mt-0.5"></i> <?= htmlspecialchars($r['schedule']) ?></span><?php endif; ?>
                                </div>
                            </td>
                            <td class="py-2.5 text-right whitespace-nowrap">
                                <div class="font-mono font-bold text-slate-300" title="<?= htmlspecialchars(__('firewall.hits'), ENT_QUOTES) ?>"><?= fw_hits($r['hits']) ?></div>
                                <?php if ($r['last_hit']): ?>
                                <div class="text-[10px] text-slate-600" title="<?= date('d.m.Y H:i', $r['last_hit']) ?>"><?= __('firewall.last_hit') ?> <?= fw_ago($r['last_hit']) ?></div>
                                <?php endif; ?>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
                </div>
            </div>
            <?php endforeach; ?>
        </div>
        <div id="fw-empty" class="hidden py-16 text-center text-slate-500"><?= __('firewall.no_match') ?></div>
        <?php endif; ?>
    </div>

    <script>
        lucide.createIcons();

        function applyFwFilters() {
            const q = document.getElementById('fw-search').value.trim().toLowerCase();
            const act = document.getElementById('fw-action').value;
            const sys = document.getElementById('fw-system').checked;
            let any = false;
            document.querySelectorAll('.fw-group').forEach(g => {
                let n = 0;
                g.querySelectorAll('.fw-row').forEach(r => {
                    const ok = (sys || r.dataset.user === '1')
                        && (!act || r.dataset.action === act)
                        && (!q || r.dataset.search.includes(q));
                    r.style.display = ok ? '' : 'none';
                    if (ok) n++;
                });
                g.style.display = n ? '' : 'none';
                g.querySelector('.fw-count').innerText = n ? '(' + n + ')' : '';
                if (n) any = true;
            });
            document.getElementById('fw-empty').classList.toggle('hidden', any);
            try { localStorage.setItem('minidash.fwSystem', sys ? '1' : '0'); } catch (e) {}
        }
        try { document.getElementById('fw-system').checked = localStorage.getItem('minidash.fwSystem') === '1'; } catch (e) {}
        ['fw-search', 'fw-action', 'fw-system'].forEach(id => document.getElementById(id)?.addEventListener('input', applyFwFilters));
        document.getElementById('fw-system')?.addEventListener('change', applyFwFilters);
        if (document.getElementById('fw-groups')) applyFwFilters();

        async function toggleFwRule(btn) {
            if (btn.disabled) return;
            const want = btn.getAttribute('aria-checked') !== 'true';
            if (!confirm((want ? <?= json_encode(__('firewall.confirm_on')) ?> : <?= json_encode(__('firewall.confirm_off')) ?>) + ' "' + btn.dataset.name + '"?')) return;
            btn.disabled = true;
            btn.style.opacity = '0.5';
            try {
                const res = await fetch('api_firewall_toggle.php', {
                    method: 'POST',
                    headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode(csrf_token()) ?> },
                    body: JSON.stringify({ id: btn.dataset.id, enabled: want })
                });
                const json = await res.json();
                if (!json.success) throw new Error(json.message || ('HTTP ' + res.status));
                btn.setAttribute('aria-checked', want ? 'true' : 'false');
                btn.classList.toggle('bg-blue-500', want);
                btn.classList.toggle('bg-slate-700', !want);
                btn.firstElementChild.classList.toggle('translate-x-4', want);
                btn.closest('tr').classList.toggle('opacity-50', !want);
            } catch (e) {
                alert(<?= json_encode(__('firewall.toggle_error')) ?> + ' ' + e.message);
            } finally {
                btn.disabled = false;
                btn.style.opacity = '';
            }
        }
    </script>
</body>
</html>
