<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';
require_once 'includes/navbar_stats.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}

$navbar_stats = get_navbar_stats();
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('transfer.title') ?> | MiniDash</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="dashboard.css">
    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="custom-scrollbar">
    <?php render_nav(__('transfer.title'), $navbar_stats); ?>

    <div class="max-w-7xl mx-auto p-4 md:p-8">
        <!-- Header + zakres -->
        <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-black text-white mb-2 flex items-center gap-3">
                    <i data-lucide="arrow-down-up" class="w-8 h-8 text-cyan-400"></i>
                    <?= __('transfer.title') ?>
                </h1>
                <p class="text-slate-500 text-sm"><?= __('transfer.subtitle') ?></p>
            </div>
            <div class="flex items-center gap-1 p-1 bg-slate-900 border border-white/10 rounded-xl self-start">
                <button data-range="day"   onclick="setRange('day')"   class="range-btn px-4 py-2 rounded-lg text-xs font-black uppercase tracking-wider transition"><?= __('common.day') ?></button>
                <button data-range="week"  onclick="setRange('week')"  class="range-btn px-4 py-2 rounded-lg text-xs font-black uppercase tracking-wider transition"><?= __('common.week') ?></button>
                <button data-range="month" onclick="setRange('month')" class="range-btn px-4 py-2 rounded-lg text-xs font-black uppercase tracking-wider transition"><?= __('common.month') ?></button>
            </div>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
            <!-- Urządzenia -->
            <div class="lg:col-span-2 glass-card flex flex-col">
                <div class="p-4 border-b border-white/5 flex items-center gap-3 bg-slate-950/30">
                    <div class="p-2 bg-slate-800 rounded-lg text-cyan-400"><i data-lucide="smartphone" class="w-4 h-4"></i></div>
                    <span class="font-bold text-sm text-slate-300"><?= __('transfer.by_device') ?></span>
                </div>
                <div class="overflow-x-auto">
                    <table class="w-full text-left">
                        <thead>
                            <tr class="bg-slate-950/50 text-[12px] font-black text-slate-500 uppercase tracking-widest border-b border-white/5">
                                <th class="px-5 py-3"><?= __('transfer.device') ?></th>
                                <th class="px-5 py-3">VLAN</th>
                                <th class="px-5 py-3 text-right"><?= __('transfer.download') ?></th>
                                <th class="px-5 py-3 text-right"><?= __('transfer.upload') ?></th>
                                <th class="px-5 py-3 text-right"><?= __('transfer.total') ?></th>
                            </tr>
                        </thead>
                        <tbody id="devBody" class="divide-y divide-white/[0.02] text-sm"></tbody>
                    </table>
                </div>
            </div>

            <!-- VLAN -->
            <div class="glass-card flex flex-col">
                <div class="p-4 border-b border-white/5 flex items-center gap-3 bg-slate-950/30">
                    <div class="p-2 bg-slate-800 rounded-lg text-violet-400"><i data-lucide="network" class="w-4 h-4"></i></div>
                    <span class="font-bold text-sm text-slate-300"><?= __('transfer.by_vlan') ?></span>
                </div>
                <div id="vlanBody" class="p-4 space-y-3"></div>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        let currentRange = 'day';

        function formatBytes(bytes, d = 2) {
            bytes = parseFloat(bytes) || 0;
            if (bytes <= 0) return '0 B';
            const k = 1024, sizes = ['B', 'KB', 'MB', 'GB', 'TB'];
            const i = Math.min(sizes.length - 1, Math.floor(Math.log(bytes) / Math.log(k)));
            return parseFloat((bytes / Math.pow(k, i)).toFixed(d)) + ' ' + sizes[i];
        }
        function escHtml(s) { const el = document.createElement('div'); el.textContent = s || ''; return el.innerHTML; }

        function setRange(r) {
            currentRange = r;
            document.querySelectorAll('.range-btn').forEach(b => {
                const on = b.dataset.range === r;
                b.classList.toggle('bg-cyan-600/20', on);
                b.classList.toggle('text-cyan-300', on);
                b.classList.toggle('text-slate-500', !on);
            });
            load();
        }

        function load() {
            document.getElementById('devBody').innerHTML = '<tr><td colspan="5" class="px-5 py-10 text-center text-slate-500"><?= __('transfer.loading') ?></td></tr>';
            document.getElementById('vlanBody').innerHTML = '<p class="text-slate-500 text-sm text-center py-6"><?= __('transfer.loading') ?></p>';

            fetch(`api_transfer.php?range=${currentRange}`)
                .then(r => r.json())
                .then(d => {
                    if (!d.success) throw new Error(d.error || 'error');
                    renderDevices(d.devices || []);
                    renderVlans(d.vlans || []);
                })
                .catch(() => {
                    document.getElementById('devBody').innerHTML = '<tr><td colspan="5" class="px-5 py-10 text-center text-rose-400"><?= __('transfer.error') ?></td></tr>';
                    document.getElementById('vlanBody').innerHTML = '<p class="text-rose-400 text-sm text-center py-6"><?= __('transfer.error') ?></p>';
                });
        }

        function renderDevices(list) {
            const body = document.getElementById('devBody');
            if (!list.length) { body.innerHTML = '<tr><td colspan="5" class="px-5 py-10 text-center text-slate-500 italic"><?= __('transfer.empty') ?></td></tr>'; return; }
            const max = list[0].total || 1;
            body.innerHTML = list.map(d => `
                <tr class="hover:bg-white/[0.02] transition-colors">
                    <td class="px-5 py-3">
                        <div class="font-bold text-white">${escHtml(d.name)}</div>
                        <div class="font-mono text-[10px] text-slate-600">${escHtml(d.mac)}</div>
                        <div class="mt-1 h-1 rounded-full bg-slate-800 overflow-hidden"><div class="h-full bg-cyan-500/70" style="width:${Math.max(2, (d.total / max) * 100)}%"></div></div>
                    </td>
                    <td class="px-5 py-3"><span class="text-[11px] font-bold text-slate-400 bg-slate-800/60 px-2 py-0.5 rounded">${escHtml(d.vlan_name)}</span></td>
                    <td class="px-5 py-3 text-right font-mono text-emerald-400">${formatBytes(d.rx)}</td>
                    <td class="px-5 py-3 text-right font-mono text-amber-400">${formatBytes(d.tx)}</td>
                    <td class="px-5 py-3 text-right font-mono font-bold text-white">${formatBytes(d.total)}</td>
                </tr>`).join('');
        }

        function renderVlans(list) {
            const body = document.getElementById('vlanBody');
            if (!list.length) { body.innerHTML = '<p class="text-slate-500 text-sm text-center py-6 italic"><?= __('transfer.empty') ?></p>'; return; }
            const max = list[0].total || 1;
            body.innerHTML = list.map(v => `
                <div>
                    <div class="flex justify-between items-baseline mb-1">
                        <span class="font-bold text-sm text-white">${escHtml(v.name)}</span>
                        <span class="font-mono text-xs text-slate-300">${formatBytes(v.total)}</span>
                    </div>
                    <div class="h-2 rounded-full bg-slate-800 overflow-hidden"><div class="h-full bg-violet-500/70" style="width:${Math.max(2, (v.total / max) * 100)}%"></div></div>
                    <div class="flex gap-4 mt-1 text-[10px] font-mono text-slate-500">
                        <span class="text-emerald-400/70">↓ ${formatBytes(v.rx)}</span>
                        <span class="text-amber-400/70">↑ ${formatBytes(v.tx)}</span>
                    </div>
                </div>`).join('');
        }

        setRange('day');
    </script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
