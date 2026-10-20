<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/

// Already installed — block access
if (file_exists(__DIR__ . '/data/.installed')) {
    header('Location: login.php');
    exit;
}

// Auto-detect existing installation (data/.env or legacy root .env with real API key)
foreach ([__DIR__ . '/data/.env', __DIR__ . '/.env'] as $envCandidate) {
    if (!file_exists($envCandidate)) continue;
    $envContent = file_get_contents($envCandidate);
    if (preg_match('/UNIFI_API_KEY=(.+)/', $envContent, $m)) {
        $key = trim($m[1]);
        if ($key !== '' && $key !== 'your-api-key' && $key !== 'your-api-key-here') {
            @file_put_contents(__DIR__ . '/data/.installed', date('Y-m-d H:i:s'));
            header('Location: login.php');
            exit;
        }
    }
}

require_once __DIR__ . '/includes/unifi_probe.php';

// Kreator działa przed config.php (bez lang/*.json), więc ma własny, mały słownik.
// Język: ?lang= > ciasteczko panelu > język przeglądarki.
$lang = $_GET['lang'] ?? $_COOKIE['minidash_lang'] ?? '';
if (!in_array($lang, ['pl', 'en'], true)) {
    $lang = str_starts_with(strtolower($_SERVER['HTTP_ACCEPT_LANGUAGE'] ?? ''), 'pl') ? 'pl' : 'en';
}
if (isset($_GET['lang'])) {
    setcookie('minidash_lang', $lang, ['expires' => time() + 31536000, 'path' => '/', 'samesite' => 'Lax']);
}

$T = [
    'pl' => [
        'subtitle'       => 'Pierwsza konfiguracja',
        'console'        => 'Konsola UniFi',
        'address'        => 'Adres IP konsoli',
        'address_hint'   => 'UDR, UDM, UCG albo Cloud Key. Wystarczy sam adres, https:// dopiszę.',
        'api_key'        => 'Klucz API',
        'api_key_hint'   => 'W aplikacji UniFi Network na konsoli: Settings → Control Plane → Integrations → Create API Key. Klucz z unifi.ui.com nie zadziała.',
        'show'           => 'Pokaż',
        'test'           => 'Sprawdź połączenie',
        'testing'        => 'Łączę z konsolą...',
        'site'           => 'Site',
        'site_hint'      => 'Uzupełni się po sprawdzeniu połączenia. Możesz też wpisać ręcznie: UUID site\'u albo default.',
        'found_one'      => 'Połączono. Site: {name}',
        'found_many'     => 'Połączono. Znalezione site\'y: {n} - wybierz właściwy.',
        'err_url'        => 'Niepoprawny adres konsoli. Wpisz np. 10.0.0.1',
        'err_key'        => 'Wpisz klucz API.',
        'err_connect'    => 'Brak połączenia z konsolą pod tym adresem ({detail}).',
        'err_auth'       => 'Konsola odrzuciła klucz API ({detail}). Sprawdź, czy jest skopiowany w całości.',
        'err_http'       => 'Konsola odpowiedziała, ale bez listy site\'ów ({detail}).',
        'err_fetch'      => 'Nie udało się wykonać testu - odśwież stronę.',
        'skip_check'     => 'Zapisz bez sprawdzania (konsola jest teraz niedostępna)',
        'account'        => 'Konto w MiniDash',
        'account_hint'   => 'Tym loginem i hasłem zalogujesz się do panelu MiniDash. To nie jest konto konsoli UniFi.',
        'username'       => 'Login',
        'password'       => 'Hasło',
        'password2'      => 'Powtórz hasło',
        'min6'           => 'Min. 6 znaków',
        'full_name'      => 'Imię i nazwisko',
        'email'          => 'E-mail',
        'save'           => 'Zapisz i przejdź dalej',
        'done'           => 'Gotowe',
        'done_text'      => 'Konfiguracja zapisana. Zaloguj się ustawionym przed chwilą loginem i hasłem.',
        'go_login'       => 'Przejdź do logowania',
        'e_username'     => 'Wpisz login do MiniDash.',
        'e_password'     => 'Hasło musi mieć co najmniej 6 znaków.',
        'e_password2'    => 'Hasła się różnią.',
        'e_site'         => 'Niepoprawny Site ID.',
        'e_write'        => 'Nie mogę zapisać data/.env - katalog data/ musi być zapisywalny dla serwera WWW.',
    ],
    'en' => [
        'subtitle'       => 'Initial setup',
        'console'        => 'UniFi console',
        'address'        => 'Console IP address',
        'address_hint'   => 'UDR, UDM, UCG or Cloud Key. The address alone is enough, https:// is added for you.',
        'api_key'        => 'API key',
        'api_key_hint'   => 'In the UniFi Network app on the console: Settings → Control Plane → Integrations → Create API Key. A key from unifi.ui.com will not work.',
        'show'           => 'Show',
        'test'           => 'Test connection',
        'testing'        => 'Connecting to the console...',
        'site'           => 'Site',
        'site_hint'      => 'Filled in after the connection test. You can also type it: the site UUID or default.',
        'found_one'      => 'Connected. Site: {name}',
        'found_many'     => 'Connected. Sites found: {n} - pick the right one.',
        'err_url'        => 'Invalid console address. Enter e.g. 10.0.0.1',
        'err_key'        => 'Enter the API key.',
        'err_connect'    => 'Cannot reach the console at this address ({detail}).',
        'err_auth'       => 'The console rejected the API key ({detail}). Check that it was copied in full.',
        'err_http'       => 'The console answered, but without a site list ({detail}).',
        'err_fetch'      => 'The test could not run - reload the page.',
        'skip_check'     => 'Save without checking (the console is unreachable right now)',
        'account'        => 'MiniDash account',
        'account_hint'   => 'You will log in to the MiniDash panel with this username and password. It is not your UniFi console account.',
        'username'       => 'Username',
        'password'       => 'Password',
        'password2'      => 'Repeat password',
        'min6'           => 'Min. 6 characters',
        'full_name'      => 'Full name',
        'email'          => 'Email',
        'save'           => 'Save & continue',
        'done'           => 'All set',
        'done_text'      => 'Configuration saved. Log in with the username and password you have just set.',
        'go_login'       => 'Go to login',
        'e_username'     => 'Enter a MiniDash username.',
        'e_password'     => 'The password needs at least 6 characters.',
        'e_password2'    => 'The passwords do not match.',
        'e_site'         => 'Invalid Site ID.',
        'e_write'        => 'Cannot write data/.env - the data/ directory must be writable by the web server.',
    ],
];
function t(string $key, array $params = []): string
{
    global $T, $lang;
    $s = $T[$lang][$key] ?? $key;
    foreach ($params as $k => $v) $s = str_replace('{' . $k . '}', (string)$v, $s);
    return $s;
}

$error = '';
$success = false;
$show_skip = false;
$sites = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Znak nowej linii w polu dopisałby do .env własną zmienną (np. drugie ADMIN_PASSWORD)
    $clean = fn($v) => str_replace(["\r", "\n"], '', trim((string)$v));
    $url       = unifi_normalize_url($_POST['controller_url'] ?? '');
    $api_key   = $clean($_POST['api_key'] ?? '');
    $site      = $clean($_POST['site'] ?? '') ?: 'default';
    $username  = $clean($_POST['admin_username'] ?? '');
    $password  = str_replace(["\r", "\n"], '', (string)($_POST['admin_password'] ?? ''));
    $password2 = str_replace(["\r", "\n"], '', (string)($_POST['admin_password2'] ?? ''));

    if ($url === '') {
        $error = t('err_url');
    } elseif ($api_key === '') {
        $error = t('err_key');
    } elseif (!unifi_valid_site($site)) {
        $error = t('e_site');
    } elseif ($username === '') {
        $error = t('e_username');
    } elseif (strlen($password) < 6) {
        $error = t('e_password');
    } elseif (!hash_equals($password, $password2)) {
        $error = t('e_password2');
    }

    // Test połączenia po stronie serwera - złapie literówkę w kluczu, zanim panel ruszy bez danych.
    // Pominąć go można tylko przy braku połączenia (konsola chwilowo offline) - odrzucony klucz
    // i tak nic nie pobierze.
    if ($error === '') {
        $probe = unifi_detect_sites($url, $api_key);
        $sites = $probe['sites'];
        $skippable = $probe['error'] === 'connect';
        if ($probe['error'] && !($skippable && !empty($_POST['skip_check']))) {
            $error = t('err_' . $probe['error'], ['detail' => $probe['detail']]);
            $show_skip = $skippable;
        } elseif ($sites && !in_array($site, array_column($sites, 'id'), true)) {
            // Nie kliknięto „Sprawdź połączenie" albo wpisany site nie istnieje - bierzemy pierwszy
            $site = $sites[0]['id'];
        }
    }

    if ($error === '') {
        $fields = [
            'UNIFI_CONTROLLER_URL' => $url,
            'UNIFI_API_KEY'        => $api_key,
            'UNIFI_SITE'           => $site,
            'ADMIN_USERNAME'       => $username,
            'ADMIN_PASSWORD'       => $password,
            'ADMIN_FULL_NAME'      => $clean($_POST['admin_full_name'] ?? ''),
            'ADMIN_EMAIL'          => $clean($_POST['admin_email'] ?? ''),
            'DEBUG'                => 'false',
        ];

        $env = "# MiniDash Configuration\n";
        $env .= "# Generated by Setup Wizard on " . date('Y-m-d H:i:s') . "\n\n";
        foreach ($fields as $k => $v) $env .= "$k=$v\n";

        // data/.env, nie katalog główny: w Dockerze data/ to wolumen (przetrwa aktualizację),
        // a serwer WWW nie potrzebuje prawa zapisu do katalogu aplikacji
        $dataDir = __DIR__ . '/data';
        $envPath = $dataDir . '/.env';
        if (!is_dir($dataDir)) @mkdir($dataDir, 0770, true);

        // Najpierw uprawnienia 0600 na pustym pliku, dopiero potem sekrety
        if (@file_put_contents($envPath, '') === false) {
            $error = t('e_write');
        } else {
            @chmod($envPath, 0600);
            if (@file_put_contents($envPath, $env, LOCK_EX) === false) {
                $error = t('e_write');
            } else {
                @file_put_contents($dataDir . '/.installed', date('Y-m-d H:i:s'));
                $success = true;
            }
        }
    }
}

$h = fn($v) => htmlspecialchars((string)$v, ENT_QUOTES);
$posted_site = $_POST['site'] ?? '';
?>
<!DOCTYPE html>
<html lang="<?= $lang ?>">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Setup - MiniDash</title>
    <link rel="icon" type="image/png" href="img/favicon.png">
    <script src="https://cdn.tailwindcss.com"></script>
    <link rel="stylesheet" href="assets/css/fonts.css">
    <link rel="stylesheet" href="dashboard.css">
    <script src="assets/js/lucide.min.js"></script>
    <style>
        .glass-card:hover { transform: none; }
        .setup-input {
            width: 100%;
            padding: 0.65rem 0.75rem 0.65rem 2.5rem;
            background: rgba(30, 41, 59, 0.5);
            border: 1px solid rgba(255, 255, 255, 0.05);
            border-radius: 0.75rem;
            color: #e2e8f0;
            outline: none;
            transition: all 0.2s;
        }
        .setup-input:focus {
            border-color: rgba(59, 130, 246, 0.5);
            box-shadow: 0 0 0 2px rgba(59, 130, 246, 0.25);
        }
        .setup-input::placeholder { color: #475569; }
        select.setup-input option { background: #0f172a; }
        .setup-label { display: block; font-size: 0.75rem; font-weight: 700; color: #64748b; text-transform: uppercase; letter-spacing: 0.1em; margin-bottom: 0.375rem; padding: 0 0.25rem; }
        .setup-icon { position: absolute; left: 0.75rem; top: 50%; transform: translateY(-50%); color: #64748b; }
    </style>
</head>
<body class="min-h-screen flex items-center justify-center p-4">
    <div class="w-full max-w-lg">
        <div class="text-center mb-8">
            <div class="inline-flex items-center justify-center w-16 h-16 rounded-2xl bg-slate-800/50 mb-4 shadow-xl shadow-blue-500/10 border border-white/10">
                <img src="img/logo_lm-u.png" alt="MiniDash" class="w-10 h-10 opacity-90">
            </div>
            <h1 class="text-3xl font-bold bg-clip-text text-transparent bg-gradient-to-r from-blue-400 to-indigo-400">MiniDash</h1>
            <p class="text-slate-500 text-sm mt-2 font-medium tracking-wide uppercase"><?= t('subtitle') ?></p>
            <p class="text-[11px] mt-2 font-bold tracking-widest">
                <a href="?lang=pl" class="<?= $lang === 'pl' ? 'text-blue-400' : 'text-slate-600 hover:text-slate-300' ?>">PL</a>
                <span class="text-slate-700 mx-1">/</span>
                <a href="?lang=en" class="<?= $lang === 'en' ? 'text-blue-400' : 'text-slate-600 hover:text-slate-300' ?>">EN</a>
            </p>
        </div>

        <?php if ($success): ?>
            <div class="glass-card p-8 shadow-2xl text-center space-y-6">
                <div class="inline-flex items-center justify-center w-16 h-16 rounded-full bg-emerald-500/10 text-emerald-400">
                    <i data-lucide="check-circle" class="w-10 h-10"></i>
                </div>
                <h2 class="text-xl font-bold text-white"><?= t('done') ?></h2>
                <p class="text-slate-400 text-sm"><?= t('done_text') ?></p>
                <a href="login.php"
                   class="inline-flex items-center justify-center gap-2 w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 rounded-xl transition shadow-xl shadow-blue-600/20">
                    <span><?= t('go_login') ?></span>
                    <i data-lucide="arrow-right" class="w-4 h-4"></i>
                </a>
            </div>
        <?php else: ?>
            <div class="glass-card p-8 shadow-2xl relative overflow-hidden">
                <div class="absolute -top-24 -right-24 w-48 h-48 bg-blue-500/10 rounded-full blur-3xl"></div>

                <?php if ($error): ?>
                    <div class="bg-red-500/10 border border-red-500/20 text-red-400 px-4 py-3 rounded-lg mb-6 flex items-center gap-3 relative z-10">
                        <i data-lucide="alert-circle" class="w-5 h-5 shrink-0"></i>
                        <span class="text-sm font-medium"><?= $h($error) ?></span>
                    </div>
                <?php endif; ?>

                <form method="POST" action="setup.php" class="space-y-7 relative z-10" autocomplete="off">
                    <div class="space-y-4">
                        <h3 class="text-xs font-bold text-blue-400 uppercase tracking-widest flex items-center gap-2">
                            <i data-lucide="server" class="w-3.5 h-3.5"></i>
                            <?= t('console') ?>
                        </h3>

                        <div>
                            <label class="setup-label" for="controllerUrl"><?= t('address') ?> *</label>
                            <div class="relative">
                                <span class="setup-icon"><i data-lucide="router" class="w-4 h-4"></i></span>
                                <input type="text" name="controller_url" id="controllerUrl" required
                                       class="setup-input" placeholder="10.0.0.1" inputmode="url" spellcheck="false"
                                       value="<?= $h($_POST['controller_url'] ?? '') ?>">
                            </div>
                            <p class="text-[11px] text-slate-600 mt-1 px-1"><?= t('address_hint') ?></p>
                        </div>

                        <div>
                            <label class="setup-label" for="apiKey"><?= t('api_key') ?> *</label>
                            <div class="relative">
                                <span class="setup-icon"><i data-lucide="key" class="w-4 h-4"></i></span>
                                <input type="password" name="api_key" id="apiKey" required
                                       class="setup-input" style="padding-right: 4.5rem" spellcheck="false" autocomplete="new-password"
                                       value="<?= $h($_POST['api_key'] ?? '') ?>">
                                <button type="button" onclick="toggleKey()" class="absolute right-3 top-1/2 -translate-y-1/2 text-[11px] font-bold text-slate-500 hover:text-slate-300 uppercase tracking-widest"><?= t('show') ?></button>
                            </div>
                            <p class="text-[11px] text-slate-600 mt-1 px-1"><?= t('api_key_hint') ?></p>
                        </div>

                        <button type="button" id="testBtn" onclick="testConnection()"
                                class="w-full flex items-center justify-center gap-2 py-2.5 rounded-xl text-sm font-semibold text-indigo-300 bg-indigo-500/15 border border-indigo-500/30 hover:bg-indigo-500/25 transition">
                            <i data-lucide="plug-zap" class="w-4 h-4"></i>
                            <span><?= t('test') ?></span>
                        </button>
                        <p id="testStatus" class="text-xs px-1 hidden"></p>

                        <div>
                            <label class="setup-label" for="siteInput"><?= t('site') ?></label>
                            <div class="relative">
                                <span class="setup-icon"><i data-lucide="layout-grid" class="w-4 h-4"></i></span>
                                <!-- Pole tekstowe z podpowiedziami: lista z testu połączenia albo wpis ręczny -->
                                <input type="text" name="site" id="siteInput" list="siteList" class="setup-input"
                                       placeholder="default" spellcheck="false"
                                       value="<?= $h($posted_site !== '' ? $posted_site : ($sites[0]['id'] ?? 'default')) ?>">
                                <datalist id="siteList">
                                    <?php foreach ($sites as $s): ?>
                                        <option value="<?= $h($s['id']) ?>"><?= $h($s['name']) ?></option>
                                    <?php endforeach; ?>
                                </datalist>
                            </div>
                            <p class="text-[11px] text-slate-600 mt-1 px-1"><?= t('site_hint') ?></p>
                        </div>

                        <?php if ($show_skip): ?>
                            <label class="flex items-start gap-2 text-xs text-amber-300/90 px-1 cursor-pointer">
                                <input type="checkbox" name="skip_check" value="1" class="mt-0.5 accent-amber-500">
                                <span><?= t('skip_check') ?></span>
                            </label>
                        <?php endif; ?>
                    </div>

                    <div class="border-t border-white/5"></div>

                    <div class="space-y-4">
                        <h3 class="text-xs font-bold text-emerald-400 uppercase tracking-widest flex items-center gap-2">
                            <i data-lucide="user-cog" class="w-3.5 h-3.5"></i>
                            <?= t('account') ?>
                        </h3>
                        <p class="text-[11px] text-slate-500 px-1 -mt-2"><?= t('account_hint') ?></p>

                        <div>
                            <label class="setup-label" for="adminUser"><?= t('username') ?> *</label>
                            <div class="relative">
                                <span class="setup-icon"><i data-lucide="user" class="w-4 h-4"></i></span>
                                <input type="text" name="admin_username" id="adminUser" required class="setup-input"
                                       placeholder="admin" autocomplete="username" spellcheck="false"
                                       value="<?= $h($_POST['admin_username'] ?? '') ?>">
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="setup-label" for="adminPass"><?= t('password') ?> *</label>
                                <div class="relative">
                                    <span class="setup-icon"><i data-lucide="lock" class="w-4 h-4"></i></span>
                                    <input type="password" name="admin_password" id="adminPass" required minlength="6"
                                           class="setup-input" placeholder="<?= t('min6') ?>" autocomplete="new-password">
                                </div>
                            </div>
                            <div>
                                <label class="setup-label" for="adminPass2"><?= t('password2') ?> *</label>
                                <div class="relative">
                                    <span class="setup-icon"><i data-lucide="lock-keyhole" class="w-4 h-4"></i></span>
                                    <input type="password" name="admin_password2" id="adminPass2" required minlength="6"
                                           class="setup-input" autocomplete="new-password">
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-2 gap-3">
                            <div>
                                <label class="setup-label" for="adminName"><?= t('full_name') ?></label>
                                <div class="relative">
                                    <span class="setup-icon"><i data-lucide="badge-check" class="w-4 h-4"></i></span>
                                    <input type="text" name="admin_full_name" id="adminName" class="setup-input"
                                           value="<?= $h($_POST['admin_full_name'] ?? '') ?>">
                                </div>
                            </div>
                            <div>
                                <label class="setup-label" for="adminEmail"><?= t('email') ?></label>
                                <div class="relative">
                                    <span class="setup-icon"><i data-lucide="mail" class="w-4 h-4"></i></span>
                                    <input type="email" name="admin_email" id="adminEmail" class="setup-input"
                                           value="<?= $h($_POST['admin_email'] ?? '') ?>">
                                </div>
                            </div>
                        </div>
                    </div>

                    <button type="submit"
                            class="w-full bg-blue-600 hover:bg-blue-500 text-white font-bold py-3.5 rounded-xl transition shadow-xl shadow-blue-600/20 group flex items-center justify-center gap-2">
                        <i data-lucide="save" class="w-4 h-4"></i>
                        <span><?= t('save') ?></span>
                        <i data-lucide="arrow-right" class="w-4 h-4 group-hover:translate-x-1 transition"></i>
                    </button>
                </form>
            </div>
        <?php endif; ?>

        <footer class="mt-12 text-center text-slate-500 text-[11px] uppercase tracking-[0.3em] font-medium">
            <a href="https://www.lm-networks.pl" target="_blank" rel="noopener" class="hover:text-slate-300 transition">LM-Networks</a> &copy; 2025-2026
        </footer>
    </div>

    <script>
    lucide.createIcons();
    const L = <?= json_encode($T[$lang], JSON_UNESCAPED_UNICODE | JSON_HEX_TAG) ?>;
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
        const key = document.getElementById('apiKey').value.trim();
        if (!url) return setStatus(L.err_url, '#ef4444');
        if (!key) return setStatus(L.err_key, '#ef4444');

        const btn = document.getElementById('testBtn');
        btn.disabled = true;
        btn.style.opacity = '0.5';
        setStatus(L.testing, '#818cf8');

        try {
            const body = new FormData();
            body.append('controller_url', url);
            body.append('api_key', key);
            const data = await (await fetch('api_detect_site.php', { method: 'POST', body })).json();

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
