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

$days = (int)($_GET['days'] ?? 30);
if (!in_array($days, [7, 30, 90], true)) $days = 30;

$outages      = get_wan_outages($db, $days);
$availability = get_wan_availability($db, $days);
$monthly      = get_wan_monthly_availability($db, 3);
$gaps         = get_wan_data_gaps($db, $days, 10);

// Podsumowanie per łącze: liczba awarii, łączny czas, ostatnia awaria.
$now = time();
$per_link = [];
foreach (array_keys($availability) as $idx) {
    $per_link[$idx] = ['count' => 0, 'seconds' => 0, 'last' => null];
}
foreach ($outages as &$o) {
    $start = strtotime($o['start'] . ' UTC');
    $end   = $o['end'] !== null ? strtotime($o['end'] . ' UTC') : $now;
    $o['start_ts'] = $start;
    $o['end_ts']   = $o['end'] !== null ? $end : null;
    $o['seconds']  = max(0, $end - $start);
    $idx = $o['wan_idx'];
    if (!isset($per_link[$idx])) $per_link[$idx] = ['count' => 0, 'seconds' => 0, 'last' => null];
    $per_link[$idx]['count']++;
    $per_link[$idx]['seconds'] += $o['seconds'];
    if ($per_link[$idx]['last'] === null || $start > $per_link[$idx]['last']) $per_link[$idx]['last'] = $start;
}
unset($o);
ksort($per_link);

function wh_duration(int $sec): string
{
    if ($sec < 60) return '< 1 min';
    $d = intdiv($sec, 86400);
    $h = intdiv($sec % 86400, 3600);
    $m = intdiv($sec % 3600, 60);
    $parts = [];
    if ($d) $parts[] = $d . ' d';
    if ($h) $parts[] = $h . ' h';
    if ($m || !$parts) $parts[] = $m . ' min';
    return implode(' ', $parts);
}

function wh_pct_color(float $pct): string
{
    if ($pct >= 99.9) return 'emerald';
    if ($pct >= 99.0) return 'amber';
    return 'red';
}

$wan_colors = [1 => 'blue', 2 => 'emerald', 3 => 'pink', 4 => 'teal'];
?>
<!DOCTYPE html>
<html lang="pl">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('wan_history.title') ?> | MiniDash</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <script src="assets/js/lucide.min.js"></script>
</head>
<body class="pt-24 pb-12 antialiased">
    <?php render_nav(__('wan_history.title'), $navbar_stats); ?>

    <div class="max-w-6xl mx-auto px-6">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-8">
            <div>
                <h2 class="text-3xl font-black text-white tracking-tight"><?= __('wan_history.title') ?></h2>
                <p class="text-slate-500 mt-1 font-medium"><?= __('wan_history.subtitle') ?></p>
            </div>
            <div class="flex items-center gap-2">
                <?php foreach ([7, 30, 90] as $d): ?>
                <a href="?days=<?= $d ?>" class="px-4 py-2 rounded-xl text-[12px] font-black uppercase tracking-widest border transition <?= $d === $days ? 'bg-blue-600 border-blue-500 text-white' : 'bg-white/5 border-white/10 text-slate-400 hover:text-white' ?>"><?= $d ?> <?= __('wan_history.days') ?></a>
                <?php endforeach; ?>
                <a href="index.php" class="p-2.5 ml-2 bg-white/5 border border-white/10 rounded-xl text-slate-400 hover:text-white transition" title="Dashboard">
                    <i data-lucide="arrow-left" class="w-5 h-5"></i>
                </a>
            </div>
        </div>

        <?php if (empty($availability)): ?>
            <div class="py-32 text-center bg-slate-900/40 rounded-3xl border border-white/5 border-dashed">
                <i data-lucide="activity" class="w-10 h-10 text-slate-600 mx-auto mb-4"></i>
                <h3 class="text-xl font-bold text-slate-400"><?= __('wan_history.no_data') ?></h3>
            </div>
        <?php else: ?>

        <!-- Kafelki per łącze -->
        <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mb-8">
            <?php foreach ($per_link as $idx => $pl):
                $av  = $availability[$idx] ?? null;
                $col = $wan_colors[$idx] ?? 'slate';
                $pc  = $av ? wh_pct_color($av['pct']) : 'slate';
            ?>
            <div class="glass-card p-6">
                <div class="flex items-center justify-between mb-5">
                    <div class="flex items-center gap-3">
                        <div class="p-2.5 bg-<?= $col ?>-500/10 text-<?= $col ?>-400 rounded-xl"><i data-lucide="globe" class="w-5 h-5"></i></div>
                        <span class="text-xl font-black text-<?= $col ?>-400">WAN<?= $idx ?></span>
                    </div>
                    <?php if ($av): ?>
                    <div class="text-right">
                        <div class="text-3xl font-black font-mono text-<?= $pc ?>-400"><?= number_format($av['pct'], 3) ?>%</div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-600"><?= __('wan_history.availability') ?> · <?= number_format($av['samples'], 0, ',', ' ') ?> <?= __('wan_history.samples') ?></div>
                    </div>
                    <?php endif; ?>
                </div>
                <div class="grid grid-cols-3 gap-3 text-center">
                    <div class="p-3 bg-slate-900/40 rounded-xl border border-white/5">
                        <div class="text-lg font-black font-mono <?= $pl['count'] ? 'text-red-400' : 'text-slate-300' ?>"><?= $pl['count'] ?></div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-600"><?= __('wan_history.outages') ?></div>
                    </div>
                    <div class="p-3 bg-slate-900/40 rounded-xl border border-white/5">
                        <div class="text-lg font-black font-mono text-slate-300"><?= $pl['count'] ? '~' . wh_duration($pl['seconds']) : '-' ?></div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-600"><?= __('wan_history.downtime') ?></div>
                    </div>
                    <div class="p-3 bg-slate-900/40 rounded-xl border border-white/5">
                        <div class="text-sm font-black font-mono text-slate-300 leading-7"><?= $pl['last'] ? date('d.m H:i', $pl['last']) : '-' ?></div>
                        <div class="text-[10px] font-black uppercase tracking-widest text-slate-600"><?= __('wan_history.last_outage') ?></div>
                    </div>
                </div>
            </div>
            <?php endforeach; ?>
        </div>

        <!-- Lista awarii -->
        <div class="glass-card p-6 mb-8">
            <div class="flex items-center gap-3 mb-5">
                <div class="p-2.5 bg-red-500/10 text-red-400 rounded-xl"><i data-lucide="zap-off" class="w-5 h-5"></i></div>
                <h3 class="text-xl font-bold tracking-tight"><?= __('wan_history.outages_list') ?></h3>
            </div>
            <?php if (empty($outages)): ?>
                <div class="py-10 text-center text-slate-500"><?= __('wan_history.no_outages') ?></div>
            <?php else: ?>
            <div class="overflow-x-auto">
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-[11px] font-black uppercase tracking-widest text-slate-500 border-b border-white/5">
                            <th class="text-left py-3 pr-4"><?= __('wan_history.col_link') ?></th>
                            <th class="text-left py-3 pr-4"><?= __('wan_history.col_start') ?></th>
                            <th class="text-left py-3 pr-4"><?= __('wan_history.col_end') ?></th>
                            <th class="text-right py-3"><?= __('wan_history.col_duration') ?></th>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($outages as $o): $col = $wan_colors[$o['wan_idx']] ?? 'slate'; ?>
                        <tr class="border-b border-white/5">
                            <td class="py-3 pr-4 font-black text-<?= $col ?>-400">WAN<?= (int)$o['wan_idx'] ?></td>
                            <td class="py-3 pr-4 font-mono text-slate-300"><?= date('d.m.Y H:i', $o['start_ts']) ?></td>
                            <td class="py-3 pr-4 font-mono text-slate-300">
                                <?php if ($o['end_ts'] === null): ?>
                                    <span class="px-2 py-0.5 rounded bg-red-500/20 text-red-400 text-[11px] font-black uppercase tracking-widest animate-pulse"><?= __('wan_history.ongoing') ?></span>
                                <?php else: ?>
                                    <?= date('d.m.Y H:i', $o['end_ts']) ?>
                                <?php endif; ?>
                            </td>
                            <td class="py-3 text-right font-mono font-bold text-slate-200">~<?= wh_duration($o['seconds']) ?></td>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
            <?php endif; ?>
            <p class="mt-4 text-[11px] text-slate-600"><?= __('wan_history.approx_note') ?></p>
        </div>

        <div class="grid grid-cols-1 lg:grid-cols-2 gap-4">
            <!-- Dostępność miesięczna -->
            <div class="glass-card p-6">
                <div class="flex items-center gap-3 mb-5">
                    <div class="p-2.5 bg-blue-500/10 text-blue-400 rounded-xl"><i data-lucide="calendar" class="w-5 h-5"></i></div>
                    <h3 class="text-xl font-bold tracking-tight"><?= __('wan_history.monthly') ?></h3>
                </div>
                <table class="w-full text-sm">
                    <thead>
                        <tr class="text-[11px] font-black uppercase tracking-widest text-slate-500 border-b border-white/5">
                            <th class="text-left py-3"><?= __('wan_history.month') ?></th>
                            <?php foreach (array_keys($per_link) as $idx): ?>
                            <th class="text-right py-3 text-<?= $wan_colors[$idx] ?? 'slate' ?>-400">WAN<?= $idx ?></th>
                            <?php endforeach; ?>
                        </tr>
                    </thead>
                    <tbody>
                    <?php foreach ($monthly as $ym => $vals): ?>
                        <tr class="border-b border-white/5">
                            <td class="py-3 font-mono text-slate-300"><?= htmlspecialchars($ym) ?></td>
                            <?php foreach (array_keys($per_link) as $idx): $v = $vals[$idx] ?? null; ?>
                            <td class="py-3 text-right font-mono font-bold <?= $v === null ? 'text-slate-600' : 'text-' . wh_pct_color($v) . '-400' ?>"><?= $v === null ? '-' : number_format($v, 3) . '%' ?></td>
                            <?php endforeach; ?>
                        </tr>
                    <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <!-- Przerwy w pomiarach -->
            <div class="glass-card p-6">
                <div class="flex items-center gap-3 mb-2">
                    <div class="p-2.5 bg-slate-500/10 text-slate-400 rounded-xl"><i data-lucide="database-zap" class="w-5 h-5"></i></div>
                    <h3 class="text-xl font-bold tracking-tight"><?= __('wan_history.gaps_title') ?></h3>
                    <span class="text-[12px] font-mono text-slate-500">(<?= count($gaps) ?>)</span>
                </div>
                <p class="text-[12px] text-slate-500 mb-4"><?= __('wan_history.gaps_desc') ?></p>
                <?php if (empty($gaps)): ?>
                    <div class="py-6 text-center text-slate-500"><?= __('wan_history.no_gaps') ?></div>
                <?php else: ?>
                <div class="max-h-[320px] overflow-y-auto pr-1">
                    <table class="w-full text-sm">
                        <tbody>
                        <?php foreach ($gaps as $g): ?>
                            <tr class="border-b border-white/5">
                                <td class="py-2 pr-3 font-mono text-slate-400"><?= date('d.m.Y H:i', $g['start']) ?></td>
                                <td class="py-2 pr-3 font-mono text-slate-400">→ <?= date('d.m H:i', $g['end']) ?></td>
                                <td class="py-2 text-right font-mono text-slate-300"><?= wh_duration($g['seconds']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                <?php endif; ?>
            </div>
        </div>
        <?php endif; ?>
    </div>

    <script>lucide.createIcons();</script>
</body>
</html>
