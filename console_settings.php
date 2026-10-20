<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
require_once 'config.php';
require_once 'db.php';
require_once 'functions.php';
require_once 'includes/navbar_stats.php';
require_once 'includes/unifi_probe.php';

if (!isset($_SESSION['logged_in']) || !$_SESSION['logged_in']) {
    header('Location: login.php');
    exit;
}

/*
 * Adres konsoli, klucz API i site. Zapis idzie do data/config.json (klucz szyfrowany, jak
 * pozostałe sekrety), który load_app_config() nakłada na .env - więc zmiana nie wymaga
 * dostępu do plików ani przebudowy kontenera.
 */
$error = '';
$show_skip = false;
$sites = [];
$form = [
    'controller_url' => (string)($config['controller_url'] ?? ''),
    'site'           => (string)($config['site'] ?? 'default'),
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $form['controller_url'] = trim((string)($_POST['controller_url'] ?? ''));
    $form['site'] = trim((string)($_POST['site'] ?? ''));
    $url  = unifi_normalize_url($form['controller_url']);
    $key  = str_replace(["\r", "\n"], '', trim((string)($_POST['api_key'] ?? '')));
    $key  = $key !== '' ? $key : (string)($config['api_key'] ?? '');
    $site = $form['site'] !== '' ? $form['site'] : 'default';

    if (!verify_csrf()) {
        $error = __('console_cfg.csrf');
    } elseif ((string)($config['admin_password'] ?? '') === ''
        || !hash_equals((string)$config['admin_password'], (string)($_POST['current_password'] ?? ''))) {
        $error = __('console_cfg.wrong_password');
    } elseif ($url === '') {
        $error = __('console_cfg.err_url');
    } elseif ($key === '') {
        $error = __('console_cfg.err_key');
    } elseif (!unifi_valid_site($site)) {
        $error = __('console_cfg.e_site');
    }

    // Pominąć test można tylko przy braku połączenia - odrzucony klucz i tak nic nie pobierze
    if ($error === '') {
        $probe = unifi_detect_sites($url, $key);
        $sites = $probe['sites'];
        $skippable = $probe['error'] === 'connect';
        if ($probe['error'] && !($skippable && !empty($_POST['skip_check']))) {
            $error = __('console_cfg.err_' . $probe['error'], ['detail' => $probe['detail']]);
            $show_skip = $skippable;
        } elseif ($sites && !in_array($site, array_column($sites, 'id'), true)) {
            $site = $sites[0]['id'];
        }
    }

    if ($error === '') {
        $configFile = __DIR__ . '/data/config.json';
        $stored = is_file($configFile) ? json_decode((string)file_get_contents($configFile), true) : [];
        if (!is_array($stored)) $stored = [];

        $changed = $url !== ($config['controller_url'] ?? '') || $key !== ($config['api_key'] ?? '') || $site !== ($config['site'] ?? '');
        $stored['controller_url'] = $url;
        $stored['api_key'] = $key;
        $stored['site'] = $site;
        encrypt_config($stored);

        if (file_put_contents($configFile, json_encode($stored, JSON_PRETTY_PRINT), LOCK_EX) === false) {
            $error = __('console_cfg.e_write');
        } else {
            // Odpowiedzi API w cache pochodzą ze starej konsoli/site'u
            if ($changed) {
                foreach (glob(__DIR__ . '/data/cache_*.json') ?: [] as $f) @unlink($f);
            }
            header('Location: console_settings.php?saved=1');
            exit;
        }
    }
}

$navbar_stats = get_navbar_stats();
$saved_key = (string)($config['api_key'] ?? '');
$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
?>
<!DOCTYPE html>
<html lang="<?= $h($_active_lang) ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title><?= __('console_cfg.title') ?> | MiniDash</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <link rel="stylesheet" href="dashboard.css">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <script src="assets/js/lucide.min.js"></script>
    <style>
        .cfg-input {
            width: 100%;
            padding: 0.65rem 0.75rem 0.65rem 2.5rem;
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.06);
            border-radius: 0.75rem;
            color: #e2e8f0;
            outline: none;
            transition: all 0.2s;
        }
        .cfg-input:focus { border-color: rgba(59, 130, 246, 0.5); box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25); }
        .cfg-input::placeholder { color: #475569; }
        select.cfg-input option { background: #0f172a; }
        .cfg-label { display: block; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.375rem; padding: 0 0.25rem; }
        .cfg-icon { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #64748b; }
    </style>
</head>
<body class="pt-24 pb-12 antialiased">
    <?php render_nav(__('console_cfg.title'), $navbar_stats); ?>

    <div class="max-w-3xl mx-auto px-6">
        <div class="flex flex-wrap items-center justify-between gap-4 mb-8">
            <div>
                <h2 class="text-3xl font-black text-white tracking-tight"><?= __('console_cfg.title') ?></h2>
                <p class="text-slate-500 mt-1 font-medium"><?= __('console_cfg.subtitle') ?></p>
            </div>
            <a href="index.php" class="p-2.5 bg-white/5 border border-white/10 rounded-xl text-slate-400 hover:text-white transition" title="Dashboard">
                <i data-lucide="arrow-left" class="w-5 h-5"></i>
            </a>
        </div>

        <?php if (isset($_GET['saved']) && $error === ''): ?>
        <div class="mb-6 p-4 rounded-2xl bg-emerald-500/10 border border-emerald-500/30 text-emerald-300 text-sm flex items-center gap-3">
            <i data-lucide="check-circle" class="w-5 h-5 shrink-0"></i>
            <span><?= __('console_cfg.saved') ?></span>
        </div>
        <?php endif; ?>

        <?php if ($error): ?>
        <div class="mb-6 p-4 rounded-2xl bg-rose-500/10 border border-rose-500/30 text-rose-300 text-sm flex items-center gap-3">
            <i data-lucide="alert-circle" class="w-5 h-5 shrink-0"></i>
            <span><?= $h($error) ?></span>
        </div>
        <?php endif; ?>

        <form method="POST" action="console_settings.php" autocomplete="off" class="glass-card p-6 md:p-8 space-y-6">
            <?= csrf_field() ?>

            <h3 class="text-xs font-bold text-blue-400 uppercase tracking-widest flex items-center gap-2">
                <i data-lucide="server" class="w-3.5 h-3.5"></i>
                <?= __('console_cfg.section_console') ?>
            </h3>

            <div>
                <label class="cfg-label" for="controllerUrl"><?= __('console_cfg.address') ?></label>
                <div class="relative">
                    <span class="cfg-icon"><i data-lucide="router" class="w-4 h-4"></i></span>
                    <input type="text" name="controller_url" id="controllerUrl" required class="cfg-input"
                           placeholder="10.0.0.1" inputmode="url" spellcheck="false" value="<?= $h($form['controller_url']) ?>">
                </div>
                <p class="text-[11px] text-slate-600 mt-1 px-1"><?= __('console_cfg.address_hint') ?></p>
            </div>

            <div>
                <label class="cfg-label" for="apiKey"><?= __('console_cfg.api_key') ?></label>
                <div class="relative">
                    <span class="cfg-icon"><i data-lucide="key" class="w-4 h-4"></i></span>
                    <input type="password" name="api_key" id="apiKey" class="cfg-input" style="padding-right: 4.5rem"
                           spellcheck="false" autocomplete="new-password"
                           placeholder="<?= $saved_key !== '' ? '••••••••' . $h(substr($saved_key, -4)) : '' ?>">
                    <button type="button" onclick="toggleKey()" class="absolute right-3 top-1/2 -translate-y-1/2 text-[11px] font-bold text-slate-500 hover:text-slate-300 uppercase tracking-widest"><?= __('console_cfg.show') ?></button>
                </div>
                <?php if ($saved_key !== ''): ?>
                <p class="text-[11px] text-slate-600 mt-1 px-1"><?= __('console_cfg.api_key_keep', ['last4' => $h(substr($saved_key, -4))]) ?></p>
                <?php endif; ?>
                <p class="text-[11px] text-slate-500 mt-1 px-1"><?= __('console_cfg.api_key_hint') ?></p>
            </div>

            <div>
                <button type="button" id="testBtn" onclick="testConnection()"
                        class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-semibold text-indigo-300 bg-indigo-500/15 border border-indigo-500/30 hover:bg-indigo-500/25 transition">
                    <i data-lucide="plug-zap" class="w-4 h-4"></i>
                    <span><?= __('console_cfg.test') ?></span>
                </button>
                <p id="testStatus" class="text-xs px-1 mt-2 hidden"></p>
            </div>

            <div>
                <label class="cfg-label" for="siteInput"><?= __('console_cfg.site') ?></label>
                <div class="relative">
                    <span class="cfg-icon"><i data-lucide="layout-grid" class="w-4 h-4"></i></span>
                    <!-- Pole tekstowe z podpowiedziami: lista z testu połączenia albo wpis ręczny -->
                    <input type="text" name="site" id="siteInput" list="siteList" class="cfg-input"
                           placeholder="default" spellcheck="false" value="<?= $h($form['site']) ?>">
                    <datalist id="siteList">
                        <?php foreach ($sites as $s): ?>
                            <option value="<?= $h($s['id']) ?>"><?= $h($s['name']) ?></option>
                        <?php endforeach; ?>
                    </datalist>
                </div>
                <p class="text-[11px] text-slate-600 mt-1 px-1"><?= __('console_cfg.site_hint') ?></p>
            </div>

            <?php if ($show_skip): ?>
            <label class="flex items-start gap-2 text-xs text-amber-300/90 px-1 cursor-pointer">
                <input type="checkbox" name="skip_check" value="1" class="mt-0.5 accent-amber-500">
                <span><?= __('console_cfg.skip_check') ?></span>
            </label>
            <?php endif; ?>

            <div class="border-t border-white/5"></div>

            <div>
                <label class="cfg-label" for="currentPass"><?= __('console_cfg.current_password') ?> *</label>
                <div class="relative">
                    <span class="cfg-icon"><i data-lucide="lock" class="w-4 h-4"></i></span>
                    <input type="password" name="current_password" id="currentPass" required class="cfg-input" autocomplete="current-password">
                </div>
                <p class="text-[11px] text-slate-600 mt-1 px-1"><?= __('console_cfg.current_password_hint') ?></p>
            </div>

            <button type="submit" class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 rounded-xl transition shadow-xl shadow-blue-600/20 flex items-center justify-center gap-2">
                <i data-lucide="save" class="w-4 h-4"></i>
                <span><?= __('console_cfg.save') ?></span>
            </button>

            <div class="text-[11px] text-slate-500 space-y-1 px-1">
                <p><?= __('console_cfg.note_account') ?></p>
                <p><?= __('console_cfg.note_env') ?></p>
            </div>
        </form>
    </div>

    <?php include __DIR__ . '/includes/footer.php'; ?>

    <script>
    lucide.createIcons();
    const CSRF = <?= json_encode(csrf_token()) ?>;
    const L = <?= json_encode([
        'testing'     => __('console_cfg.testing'),
        'found_one'   => __('console_cfg.found_one'),
        'found_many'  => __('console_cfg.found_many'),
        'err_url'     => __('console_cfg.err_url'),
        'err_key'     => __('console_cfg.err_key'),
        'err_connect' => __('console_cfg.err_connect'),
        'err_auth'    => __('console_cfg.err_auth'),
        'err_http'    => __('console_cfg.err_http'),
        'err_fetch'   => __('console_cfg.err_fetch'),
    ], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
    const fmt = (s, p) => s.replace(/\{(\w+)\}/g, (_, k) => p[k] ?? '');

    function toggleKey() {
        const f = document.getElementById('apiKey');
        f.type = f.type === 'password' ? 'text' : 'password';
    }

    function setStatus(text, color) {
        const st = document.getElementById('testStatus');
        st.textContent = text;
        st.style.color = color;
        st.classList.remove('hidden');
    }

    async function testConnection() {
        const url = document.getElementById('controllerUrl').value.trim();
        if (!url) return setStatus(L.err_url, '#ef4444');

        const btn = document.getElementById('testBtn');
        btn.disabled = true;
        btn.style.opacity = '0.5';
        setStatus(L.testing, '#818cf8');

        try {
            const body = new FormData();
            body.append('controller_url', url);
            body.append('api_key', document.getElementById('apiKey').value.trim());
            const res = await fetch('api_detect_site.php', { method: 'POST', body, headers: { 'X-CSRF-Token': CSRF } });
            const data = await res.json();

            if (data.error || !data.sites || !data.sites.length) {
                setStatus(fmt(L['err_' + data.error] || L.err_fetch, { detail: data.detail || '' }), '#ef4444');
                return;
            }
            if (data.controller_url) document.getElementById('controllerUrl').value = data.controller_url;

            const input = document.getElementById('siteInput');
            const list = document.getElementById('siteList');
            list.innerHTML = '';
            data.sites.forEach(s => list.append(new Option(s.name, s.id)));
            if (!data.sites.some(s => s.id === input.value)) input.value = data.sites[0].id;
            setStatus(data.sites.length === 1
                ? fmt(L.found_one, { name: data.sites[0].name })
                : fmt(L.found_many, { n: data.sites.length }), '#10b981');
        } catch (e) {
            setStatus(L.err_fetch, '#ef4444');
        } finally {
            btn.disabled = false;
            btn.style.opacity = '1';
        }
    }
    </script>
</body>
</html>
