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
$items   = get_inventory($db, true);
$pending = array_values(array_filter($items, fn($d) => !$d['approved']));
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('inventory.title') ?> | MiniDash</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="dashboard.css">
    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="custom-scrollbar">
    <?php render_nav(__('inventory.title'), $navbar_stats); ?>

    <div class="max-w-7xl mx-auto p-4 md:p-8">
        <div class="mb-8 flex flex-col md:flex-row md:items-center justify-between gap-4">
            <div>
                <h1 class="text-3xl font-black text-white mb-2 flex items-center gap-3">
                    <i data-lucide="boxes" class="w-8 h-8 text-teal-400"></i>
                    <?= __('inventory.title') ?>
                </h1>
                <p class="text-slate-500 text-sm"><?= __('inventory.subtitle') ?></p>
            </div>
            <div class="flex items-center gap-3 self-start">
                <?php if ($pending): ?>
                <span class="px-3 py-1.5 rounded-xl text-xs font-black bg-amber-500/10 text-amber-400 border border-amber-500/20">
                    <?= count($pending) ?> <?= __('inventory.pending') ?>
                </span>
                <button onclick="approveAll()" class="px-4 py-2 rounded-xl text-xs font-black uppercase tracking-wider bg-emerald-600/20 text-emerald-300 border border-emerald-500/30 hover:bg-emerald-600/30 transition">
                    <?= __('inventory.approve_all') ?>
                </button>
                <?php endif; ?>
            </div>
        </div>

        <div class="glass-card overflow-hidden">
            <div class="overflow-x-auto">
                <table class="w-full text-left">
                    <thead>
                        <tr class="bg-slate-950/50 text-[12px] font-black text-slate-500 uppercase tracking-widest border-b border-white/5">
                            <th class="px-4 py-3 text-center w-12"></th>
                            <th class="px-4 py-3"><?= __('inventory.device') ?></th>
                            <th class="px-4 py-3"><?= __('inventory.owner') ?></th>
                            <th class="px-4 py-3"><?= __('inventory.note') ?></th>
                            <th class="px-4 py-3 whitespace-nowrap"><?= __('inventory.first_seen') ?></th>
                            <th class="px-4 py-3 text-right"><?= __('inventory.status') ?></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-white/[0.03] text-sm">
                        <?php foreach ($items as $d): ?>
                        <tr class="hover:bg-white/[0.02] transition-colors <?= $d['approved'] ? '' : 'bg-amber-500/[0.04]' ?>" data-mac="<?= $h($d['mac']) ?>">
                            <td class="px-4 py-3 text-center">
                                <span class="inline-block w-2.5 h-2.5 rounded-full <?= $d['online'] ? 'bg-emerald-500 shadow-[0_0_8px_rgba(16,185,129,.5)]' : 'bg-slate-700' ?>" title="<?= $d['online'] ? 'online' : 'offline' ?>"></span>
                            </td>
                            <td class="px-4 py-3">
                                <div class="font-bold text-white"><?= $h($d['name']) ?></div>
                                <div class="font-mono text-[10px] text-slate-600"><?= $h($d['mac_raw']) ?><?= $d['online'] && $d['ip'] ? ' · ' . $h($d['ip']) : '' ?></div>
                            </td>
                            <td class="px-4 py-3">
                                <input type="text" value="<?= $h($d['owner']) ?>" placeholder="—" data-field="owner"
                                    class="inv-input w-full bg-transparent border border-transparent hover:border-white/10 focus:border-teal-500 focus:bg-slate-900 rounded-lg px-2 py-1 text-slate-200 text-sm outline-none transition">
                            </td>
                            <td class="px-4 py-3">
                                <input type="text" value="<?= $h($d['note']) ?>" placeholder="—" data-field="note"
                                    class="inv-input w-full bg-transparent border border-transparent hover:border-white/10 focus:border-teal-500 focus:bg-slate-900 rounded-lg px-2 py-1 text-slate-400 text-sm outline-none transition">
                            </td>
                            <td class="px-4 py-3 text-slate-500 text-xs whitespace-nowrap font-mono"><?= $h($d['first_seen']) ?></td>
                            <td class="px-4 py-3 text-right">
                                <?php if ($d['approved']): ?>
                                    <button onclick="setApproval(this,'unapprove')" class="inv-badge text-[11px] font-bold px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 hover:bg-rose-500/10 hover:text-rose-400 hover:border-rose-500/20 transition" title="<?= __('inventory.click_to_unapprove') ?>">
                                        <?= __('inventory.approved') ?>
                                    </button>
                                <?php else: ?>
                                    <button onclick="setApproval(this,'approve')" class="inv-badge text-[11px] font-black uppercase tracking-wider px-3 py-1 rounded-lg bg-amber-500/15 text-amber-300 border border-amber-500/30 hover:bg-emerald-600/20 hover:text-emerald-300 hover:border-emerald-500/30 transition">
                                        <?= __('inventory.approve') ?>
                                    </button>
                                <?php endif; ?>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                        <?php if (!$items): ?>
                        <tr><td colspan="6" class="px-4 py-12 text-center text-slate-500 italic"><?= __('inventory.empty') ?></td></tr>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>

    <script>
        lucide.createIcons();
        const CSRF = <?= json_encode(csrf_token()) ?>;
        const T = { approved: <?= json_encode(__('inventory.approved')) ?>, approve: <?= json_encode(__('inventory.approve')) ?>,
                    saved: <?= json_encode(__('inventory.saved')) ?>, error: <?= json_encode(__('inventory.error')) ?>,
                    confirmAll: <?= json_encode(__('inventory.confirm_all')) ?> };

        async function invPost(payload) {
            const r = await fetch('api_inventory.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify(payload)
            });
            const j = await r.json();
            if (!j.success) throw new Error(j.message || 'error');
            return j;
        }

        // Zapis właściciela/notatki po wyjściu z pola (jeśli się zmieniło).
        document.querySelectorAll('.inv-input').forEach(inp => {
            inp.dataset.orig = inp.value;
            inp.addEventListener('blur', async () => {
                if (inp.value === inp.dataset.orig) return;
                const row = inp.closest('tr');
                inp.classList.add('opacity-50');
                try {
                    await invPost({ action: 'save', mac: row.dataset.mac,
                        owner: row.querySelector('[data-field="owner"]').value,
                        note:  row.querySelector('[data-field="note"]').value });
                    inp.dataset.orig = inp.value;
                    inp.classList.remove('opacity-50');
                    inp.classList.add('border-emerald-500/40');
                    setTimeout(() => inp.classList.remove('border-emerald-500/40'), 800);
                } catch (e) {
                    inp.classList.remove('opacity-50');
                    inp.value = inp.dataset.orig;
                    alert(T.error + ' ' + e.message);
                }
            });
            inp.addEventListener('keydown', e => { if (e.key === 'Enter') inp.blur(); });
        });

        async function setApproval(btn, action) {
            const row = btn.closest('tr');
            btn.disabled = true;
            try {
                const j = await invPost({ action, mac: row.dataset.mac });
                if (j.approved) {
                    row.classList.remove('bg-amber-500/[0.04]');
                    btn.outerHTML = `<button onclick="setApproval(this,'unapprove')" class="inv-badge text-[11px] font-bold px-2.5 py-1 rounded-lg bg-emerald-500/10 text-emerald-400 border border-emerald-500/20 hover:bg-rose-500/10 hover:text-rose-400 hover:border-rose-500/20 transition">${T.approved}</button>`;
                } else {
                    row.classList.add('bg-amber-500/[0.04]');
                    btn.outerHTML = `<button onclick="setApproval(this,'approve')" class="inv-badge text-[11px] font-black uppercase tracking-wider px-3 py-1 rounded-lg bg-amber-500/15 text-amber-300 border border-amber-500/30 hover:bg-emerald-600/20 hover:text-emerald-300 hover:border-emerald-500/30 transition">${T.approve}</button>`;
                }
            } catch (e) {
                btn.disabled = false;
                alert(T.error + ' ' + e.message);
            }
        }

        async function approveAll() {
            if (!confirm(T.confirmAll)) return;
            try { await invPost({ action: 'approve_all' }); location.reload(); }
            catch (e) { alert(T.error + ' ' + e.message); }
        }
    </script>
    <?php include __DIR__ . '/includes/footer.php'; ?>
</body>
</html>
