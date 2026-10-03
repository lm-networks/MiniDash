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
$cur_sid  = (string)($_SESSION['sg_sid'] ?? '');
$sessions = get_active_sessions($db, (int)($config['session_timeout'] ?? 60));
$suspicious_n = count(array_filter($sessions, fn($s) => (int)$s['suspicious'] === 1));

// Pełna historia logowań (purge trzyma ją 180 dni), stronicowana po 50.
$page  = max(1, (int)($_GET['page'] ?? 1));
$per   = 50;
$total = (int)$db->query("SELECT COUNT(*) FROM login_history")->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$page  = min($page, $pages);
$hq = $db->prepare("SELECT * FROM login_history ORDER BY logged_at DESC LIMIT ? OFFSET ?");
$hq->execute([$per, ($page - 1) * $per]);
$history = $hq->fetchAll(PDO::FETCH_ASSOC);

// login_history zapisuje czas lokalny (date()), user_sessions — UTC (CURRENT_TIMESTAMP).
$utc = fn(?string $t) => $t ? strtotime($t . ' UTC') : null;

function sec_ago(?int $ts): string
{
    if (!$ts) return '-';
    $d = time() - $ts;
    if ($d < 90) return __('account.just_now');
    if ($d < 3600) return floor($d / 60) . ' min ' . __('account.ago');
    if ($d < 86400) return floor($d / 3600) . ' h ' . __('account.ago');
    return floor($d / 86400) . ' d ' . __('account.ago');
}

function sec_os_icon(string $os): string
{
    return in_array($os, ['Android', 'iOS'], true) ? 'smartphone' : 'laptop';
}

$reason_label = [
    'foreign'    => __('account.reason_foreign'),
    'new_device' => __('account.reason_new_device'),
];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('account.title') ?> | MiniDash</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="pt-24 pb-12 antialiased">
    <?php render_nav(__('account.title'), $navbar_stats); ?>

    <div class="max-w-5xl mx-auto px-6">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-8">
            <div>
                <h2 class="text-3xl font-black text-white tracking-tight"><?= __('account.title') ?></h2>
                <p class="text-slate-500 mt-1 font-medium"><?= __('account.subtitle') ?></p>
            </div>
            <a href="index.php" class="p-2.5 bg-white/5 border border-white/10 rounded-xl text-slate-400 hover:text-white transition" title="Dashboard">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
        </div>

        <?php if ($suspicious_n): ?>
        <div class="mb-6 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/40 flex items-start gap-3">
            <i data-lucide="shield-alert" class="w-6 h-6 text-rose-400 shrink-0"></i>
            <div>
                <div class="font-black text-rose-300"><?= __('account.alert_title') ?> (<?= $suspicious_n ?>)</div>
                <div class="text-sm text-rose-200/80"><?= __('account.alert_body') ?></div>
            </div>
        </div>
        <?php endif; ?>

        <!-- Aktywne sesje -->
        <div class="glass-card p-6 mb-8">
            <div class="flex flex-wrap items-center justify-between gap-3 mb-5">
                <div class="flex items-center gap-3">
                    <div class="w-10 h-10 rounded-xl bg-emerald-500/10 flex items-center justify-center text-emerald-400"><i data-lucide="monitor-smartphone" class="w-5 h-5"></i></div>
                    <div>
                        <h3 class="text-lg font-bold tracking-tight"><?= __('account.sessions') ?> <span class="text-slate-500 font-mono text-sm">(<?= count($sessions) ?>)</span></h3>
                        <p class="text-[12px] text-slate-500"><?= __('account.sessions_desc') ?></p>
                    </div>
                </div>
                <?php if (count($sessions) > 1): ?>
                <button type="button" onclick="revokeOthers()" class="px-4 py-2 rounded-xl bg-rose-600/15 border border-rose-500/40 text-rose-300 hover:bg-rose-600/25 text-[12px] font-black uppercase tracking-widest transition">
                    <?= __('account.revoke_others') ?>
                </button>
                <?php endif; ?>
            </div>
            <div class="space-y-3">
                <?php foreach ($sessions as $s):
                    $is_cur = $s['sid'] === $cur_sid;
                    $bad = (int)$s['suspicious'] === 1;
                    $reasons = array_filter(explode(',', (string)$s['suspicious_reason']));
                ?>
                <div class="p-4 rounded-2xl border flex flex-wrap items-center gap-4 <?= $bad ? 'bg-rose-500/10 border-rose-500/40' : ($is_cur ? 'bg-emerald-500/5 border-emerald-500/20' : 'bg-slate-900/40 border-white/5') ?>" data-session-row="<?= (int)$s['id'] ?>">
                    <div class="w-11 h-11 rounded-xl flex items-center justify-center shrink-0 <?= $bad ? 'bg-rose-500/20 text-rose-300' : 'bg-slate-800 text-slate-400' ?>">
                        <i data-lucide="<?= sec_os_icon((string)$s['os']) ?>" class="w-5 h-5"></i>
                    </div>
                    <div class="min-w-0 flex-grow">
                        <div class="flex flex-wrap items-center gap-2">
                            <span class="font-bold text-white"><?= htmlspecialchars($s['os'] . ' · ' . $s['browser']) ?></span>
                            <?php if ($is_cur): ?><span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-widest bg-emerald-500/20 text-emerald-300"><?= __('account.current') ?></span><?php endif; ?>
                            <?php if ($bad): ?><span class="px-2 py-0.5 rounded text-[10px] font-black uppercase tracking-widest bg-rose-500/30 text-rose-200 animate-pulse"><?= __('account.unknown_device') ?></span><?php endif; ?>
                            <?php if ($s['via'] === 'remember'): ?><span class="text-[10px] text-slate-500 uppercase tracking-widest"><?= __('account.via_remember') ?></span><?php endif; ?>
                        </div>
                        <div class="text-[12px] font-mono text-slate-400 mt-0.5">
                            IP <?= htmlspecialchars((string)$s['ip']) ?> · <i data-lucide="map-pin" class="w-3 h-3 inline -mt-0.5"></i> <?= htmlspecialchars($s['location'] ?: '…') ?>
                        </div>
                        <div class="text-[11px] text-slate-500 mt-0.5">
                            <?= __('account.started') ?> <?= date('d.m.Y H:i', $utc($s['created_at'])) ?> · <?= __('account.last_seen') ?> <?= sec_ago($utc($s['last_seen'])) ?>
                        </div>
                        <?php if ($bad): ?>
                        <div class="text-[12px] text-rose-300 mt-1 font-bold">
                            <?= htmlspecialchars(implode(', ', array_map(fn($r) => $reason_label[$r] ?? $r, $reasons))) ?> — <?= __('account.if_not_you') ?>
                        </div>
                        <?php endif; ?>
                    </div>
                    <?php if (!$is_cur): ?>
                    <button type="button" onclick="revokeSession(<?= (int)$s['id'] ?>, this)" class="px-4 py-2 rounded-xl text-[12px] font-black uppercase tracking-widest transition <?= $bad ? 'bg-rose-600 hover:bg-rose-500 text-white' : 'bg-white/5 border border-white/10 text-slate-300 hover:text-white hover:border-rose-500/50' ?>">
                        <?= __('account.revoke') ?>
                    </button>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
                <?php if (!$sessions): ?>
                <div class="py-8 text-center text-slate-500"><?= __('account.no_sessions') ?></div>
                <?php endif; ?>
            </div>
        </div>

        <!-- Historia logowań -->
        <div class="glass-card p-6" id="history">
            <div class="flex items-center gap-3 mb-5">
                <div class="w-10 h-10 rounded-xl bg-slate-500/10 flex items-center justify-center text-slate-400"><i data-lucide="history" class="w-5 h-5"></i></div>
                <div>
                    <h3 class="text-lg font-bold tracking-tight"><?= __('account.history') ?> <span class="text-slate-500 font-mono text-sm">(<?= $total ?>)</span></h3>
                    <p class="text-[12px] text-slate-500"><?= __('account.history_desc') ?></p>
                </div>
            </div>
            <?php if (!$history): ?>
                <div class="py-8 text-center text-slate-500"><?= __('account.no_history') ?></div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-[11px] font-black uppercase tracking-widest text-slate-500 border-b border-white/5">
                            <th class="text-left py-3 pr-4"><?= __('account.col_date') ?></th>
                            <th class="text-left py-3 pr-4"><?= __('account.col_device') ?></th>
                            <th class="text-left py-3 pr-4">IP</th>
                            <th class="text-left py-3"><?= __('account.col_location') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($history as $h):
                        $foreign = $h['location'] && $h['location'] !== 'Local Network' && $h['location'] !== 'Unknown Location' && stripos($h['location'], '(Poland)') === false;
                    ?>
                        <tr class="border-b border-white/5 <?= $foreign ? 'bg-rose-500/5' : '' ?>">
                            <td class="py-2.5 pr-4 font-mono text-slate-300 whitespace-nowrap"><?= date('d.m.Y H:i', strtotime($h['logged_at'])) ?></td>
                            <td class="py-2.5 pr-4 text-slate-300"><?= htmlspecialchars(($h['os'] ?? '') . ' · ' . ($h['browser'] ?? '')) ?></td>
                            <td class="py-2.5 pr-4 font-mono text-slate-400"><?= htmlspecialchars((string)$h['ip']) ?></td>
                            <td class="py-2.5 <?= $foreign ? 'text-rose-300 font-bold' : 'text-slate-400' ?>"><?= htmlspecialchars((string)$h['location']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php if ($pages > 1): ?>
            <div class="flex items-center justify-center gap-2 mt-5">
                <?php for ($p = 1; $p <= $pages; $p++): ?>
                <a href="?page=<?= $p ?>#history" class="px-3 py-1.5 rounded-lg text-[12px] font-bold <?= $p === $page ? 'bg-blue-600 text-white' : 'bg-white/5 text-slate-400 hover:text-white' ?>"><?= $p ?></a>
                <?php endfor; ?>
            </div>
            <?php endif; ?>
            <?php endif; ?>
        </div>
    </div>

    <script>
        lucide.createIcons();
        const CSRF = <?= json_encode(csrf_token()) ?>;

        async function sessPost(body) {
            const res = await fetch('api_sessions.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': CSRF },
                body: JSON.stringify(body)
            });
            const json = await res.json();
            if (!json.success) throw new Error(json.message || ('HTTP ' + res.status));
            return json;
        }

        async function revokeSession(id, btn) {
            if (!confirm(<?= json_encode(__('account.confirm_revoke')) ?>)) return;
            btn.disabled = true;
            try {
                await sessPost({ action: 'revoke', id });
                const row = document.querySelector('[data-session-row="' + id + '"]');
                if (row) row.remove();
                if (typeof showToast === 'function') showToast(<?= json_encode(__('account.revoked_ok')) ?>, 'success');
            } catch (e) {
                alert(<?= json_encode(__('account.error')) ?> + ' ' + e.message);
                btn.disabled = false;
            }
        }

        async function revokeOthers() {
            if (!confirm(<?= json_encode(__('account.confirm_revoke_others')) ?>)) return;
            try {
                const r = await sessPost({ action: 'revoke_others' });
                if (typeof showToast === 'function') showToast(<?= json_encode(__('account.revoked_others_ok')) ?> + ' ' + r.count, 'success');
                setTimeout(() => location.reload(), 900);
            } catch (e) {
                alert(<?= json_encode(__('account.error')) ?> + ' ' + e.message);
            }
        }
    </script>
</body>
</html>
