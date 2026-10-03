<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * Układ dashboardu: kafelki jako widżety w jednej siatce, kolejność / rozmiar /
 * widoczność z data/dashboard_layout.json, tryb edycji (przeciąganie, skalowanie).
 *
 * index.php owija każdy kafelek w dw_start()/dw_end() — treść trafia do bufora,
 * a dw_render() wypisuje je w kolejności z zapisanego układu.
 */

$GLOBALS['dw_widgets'] = [];
$GLOBALS['dw_current'] = null;

const DW_LAYOUT_FILE = __DIR__ . '/../data/dashboard_layout.json';

/**
 * @param int        $w szerokość domyślna w kolumnach (1–5)
 * @param int|string $h wysokość domyślna: 'auto' (naturalna) albo liczba „kafelków"
 *                      (1 kafelek = wysokość małego kafelka statystyk)
 */
function dw_start(string $id, int $w = 1, $h = 1): void
{
    $GLOBALS['dw_current'] = ['id' => $id, 'w' => $w, 'h' => $h];
    ob_start();
}

function dw_end(): void
{
    $cur = $GLOBALS['dw_current'];
    $html = ob_get_clean();
    if ($cur === null) return;
    $GLOBALS['dw_widgets'][$cur['id']] = ['w' => $cur['w'], 'h' => $cur['h'], 'html' => $html];
    $GLOBALS['dw_current'] = null;
}

/** Zapisany układ: ['order' => [id...], 'widgets' => [id => ['w','h','hidden']]]. */
function dw_load_layout(): array
{
    if (!is_file(DW_LAYOUT_FILE)) return ['order' => [], 'widgets' => []];
    $j = json_decode((string)file_get_contents(DW_LAYOUT_FILE), true);
    return is_array($j) ? $j + ['order' => [], 'widgets' => []] : ['order' => [], 'widgets' => []];
}

/**
 * Normalizuje układ przysłany z przeglądarki. Czysta funkcja — testowalna.
 * Odrzuca śmieci zamiast ufać klientowi: id tylko [a-z0-9_], szerokość 1–5,
 * wysokość 'auto' albo 1–4.
 */
function dw_sanitize_layout($in): array
{
    $out = ['order' => [], 'widgets' => []];
    if (!is_array($in)) return $out;
    foreach ((array)($in['order'] ?? []) as $id) {
        if (is_string($id) && preg_match('/^[a-z0-9_]{1,40}$/', $id) && !in_array($id, $out['order'], true)) $out['order'][] = $id;
    }
    foreach ((array)($in['widgets'] ?? []) as $id => $cfg) {
        if (!is_string($id) || !preg_match('/^[a-z0-9_]{1,40}$/', $id) || !is_array($cfg)) continue;
        $w = (int)($cfg['w'] ?? 1);
        $h = $cfg['h'] ?? 'auto';
        $out['widgets'][$id] = [
            'w'      => max(1, min(5, $w)),
            'h'      => ($h === 'auto' || !is_numeric($h)) ? 'auto' : max(1, min(4, (int)$h)),
            'hidden' => !empty($cfg['hidden']),
        ];
    }
    return $out;
}

/**
 * Kolejność wyświetlania: najpierw zapisana, potem nowe kafelki (których w zapisie
 * jeszcze nie ma, np. drugie łącze WAN) w kolejności domyślnej. Czysta funkcja.
 */
function dw_resolve_order(array $default_ids, array $saved_order): array
{
    $order = array_values(array_filter($saved_order, fn($id) => in_array($id, $default_ids, true)));
    foreach ($default_ids as $id) if (!in_array($id, $order, true)) $order[] = $id;
    return $order;
}

function dw_label(string $id): string
{
    if (preg_match('/^wan_(\d+)$/', $id, $m)) return 'WAN ' . $m[1];
    $key = 'dash_edit.w_' . $id;
    $t = __($key);
    return $t === $key ? $id : $t;
}

function dw_render(): void
{
    $layout = dw_load_layout();
    $widgets = $GLOBALS['dw_widgets'];
    $order = dw_resolve_order(array_keys($widgets), (array)$layout['order']);
    ?>
    <style>
        /* Masonry: rzędy po 8 px, każdy kafelek zajmuje tyle rzędów, ile ma wysokości
           (liczone w JS — dwLayout). Nic nie rozciąga się do sąsiada, a „dense" wpycha
           następne kafelki w wolne miejsce pod niższymi. */
        .dash-grid { display: grid; gap: 16px; grid-template-columns: minmax(0, 1fr); grid-auto-rows: 8px; grid-auto-flow: row dense; margin-bottom: 3rem; }
        @media (min-width: 640px)  { .dash-grid { grid-template-columns: repeat(2, minmax(0, 1fr)); } }
        @media (min-width: 1024px) { .dash-grid { grid-template-columns: repeat(5, minmax(0, 1fr)); } }
        .dash-widget { position: relative; min-width: 0; grid-row-end: span 12; }
        .dash-widget > :not(.dw-tools) { height: 100%; }
        @media (min-width: 640px)  { .dw-w2, .dw-w3, .dw-w4, .dw-w5 { grid-column: span 2; } }
        @media (min-width: 1024px) { .dw-w3 { grid-column: span 3; } .dw-w4 { grid-column: span 4; } .dw-w5 { grid-column: span 5; } }
        /* Stała wysokość (w kafelkach): zawartość przewija się w środku kafelka. */
        .dw-fixed > :not(.dw-tools) { position: absolute; inset: 0; overflow-x: hidden; overflow-y: auto; scrollbar-width: none; }
        /* Bez widocznych pasków: poziomo nic nie przewijamy (poświata WAN1 wystaje poza kartę),
           a pionowo treść da się przewinąć kółkiem / palcem, gdy faktycznie się nie mieści. */
        .dw-fixed > :not(.dw-tools)::-webkit-scrollbar { display: none; }
        .dw-hidden { display: none; }
        .dw-tools { display: none; }
        .dash-editing .dw-hidden { display: block; opacity: .35; }
        .dash-editing .dash-widget { outline: 2px dashed rgba(96,165,250,.35); outline-offset: 3px; border-radius: 1.5rem; }
        .dash-editing .dash-widget::after { content: ''; position: absolute; inset: 0; z-index: 20; }
        .dash-editing .dw-tools { display: flex; position: absolute; top: 8px; left: 8px; right: 8px; z-index: 30; align-items: center; gap: 4px;
            padding: 4px 6px; border-radius: 12px; background: rgba(2,6,23,.92); border: 1px solid rgba(255,255,255,.1); font-size: 11px; color: #cbd5e1; }
        .dw-tools button { padding: 2px 6px; border-radius: 6px; background: rgba(255,255,255,.06); line-height: 1.2; }
        .dw-tools button:hover { background: rgba(255,255,255,.14); color: #fff; }
        .dw-handle { cursor: grab; touch-action: none; }
        .dw-ghost { opacity: .3; }
        .dw-chosen { outline-color: rgba(96,165,250,.9) !important; }
    </style>
    <div id="dash-grid" class="dash-grid">
    <?php foreach ($order as $id):
        $def = $widgets[$id];
        $cfg = $layout['widgets'][$id] ?? [];
        $w = (int)($cfg['w'] ?? $def['w']);
        $h = $cfg['h'] ?? $def['h'];
        $hidden = !empty($cfg['hidden']);
        $cls = 'dash-widget dw-w' . $w . ($h !== 'auto' ? ' dw-fixed' : '') . ($hidden ? ' dw-hidden' : '');
    ?>
        <div class="<?= $cls ?>" data-widget="<?= htmlspecialchars($id) ?>" data-w="<?= $w ?>" data-h="<?= htmlspecialchars((string)$h) ?>"
             data-default-w="<?= (int)$def['w'] ?>" data-hidden="<?= $hidden ? '1' : '0' ?>" data-label="<?= htmlspecialchars(dw_label($id)) ?>">
            <?= $def['html'] ?>
        </div>
    <?php endforeach; ?>
    </div>
    <script>
    // Wysokość kafelka → liczba 8-pikselowych rzędów siatki. 'auto' = naturalna wysokość
    // treści, liczba = tyle „kafelków" (1 kafelek = wysokość małego kafelka statystyk).
    window.dwLayout = (function () {
        let queued = false;
        const cardOf = el => [...el.children].find(c => !c.classList.contains('dw-tools'));
        // Naturalna wysokość treści — także kafelka o stałej wysokości (tam karta jest
        // pozycjonowana absolutnie, więc na chwilę zdejmujemy pozycję i wysokość).
        function natural(card) {
            const pos = card.style.position, h = card.style.height;
            card.style.position = 'static';
            card.style.height = 'auto';
            const px = card.getBoundingClientRect().height;
            card.style.position = pos;
            card.style.height = h;
            return px;
        }
        function run() {
            queued = false;
            const g = document.getElementById('dash-grid');
            if (!g) return;
            const cs = getComputedStyle(g);
            const row = parseFloat(cs.gridAutoRows) || 8;
            const gap = parseFloat(cs.rowGap) || 16;
            const items = [...g.querySelectorAll(':scope > .dash-widget')].filter(el => el.offsetParent !== null && cardOf(el));
            // 1 „kafelek" = wysokość najwyższego małego kafelka (szer. 1, wys. 1), żeby żaden się nie ucinał
            // i małe kafelki stały równymi rzędami.
            let tile = 0;
            items.forEach(el => { if (el.dataset.h === '1' && el.dataset.w === '1') tile = Math.max(tile, natural(cardOf(el))); });
            tile = Math.min(260, Math.max(140, tile || 176));
            // Telefon (jedna kolumna): małe kafelki w naturalnej wysokości — równanie rzędów nie ma tu sensu.
            const oneCol = cs.gridTemplateColumns.trim().split(/\s+/).length === 1;
            // Liczymy w rzędach siatki, nie w pikselach: kafelek „N" = N × rzędy jednego kafelka.
            // Inaczej zaokrąglenie do rzędu 8 px rozjeżdża krawędzie (2 kafelki ≠ 2 × 1 kafelek).
            const rows = px => Math.max(1, Math.ceil((px + gap) / (row + gap)));
            const tileRows = rows(tile);
            items.forEach(el => {
                let n = el.dataset.h === 'auto' ? 0 : +el.dataset.h;
                if (oneCol && n === 1 && el.dataset.w === '1') n = 0;
                el.style.gridRowEnd = 'span ' + (n ? n * tileRows : rows(natural(cardOf(el))));
            });
        }
        function schedule() { if (!queued) { queued = true; requestAnimationFrame(run); } }
        run();
        // Treść dociąga się później (Tailwind z CDN, fonty, listy z API) — przeliczamy przy każdej zmianie rozmiaru.
        if (window.ResizeObserver) {
            const ro = new ResizeObserver(schedule);
            document.querySelectorAll('#dash-grid > .dash-widget > :not(.dw-tools)').forEach(c => ro.observe(c));
        }
        window.addEventListener('resize', schedule);
        window.addEventListener('load', schedule);
        return schedule;
    })();
    </script>
    <?php
}

/** Pasek trybu edycji + logika (przeciąganie przez SortableJS ładowany dopiero tutaj). */
function dw_render_editor(): void
{
    $t = fn(string $k) => json_encode(__('dash_edit.' . $k));
    ?>
    <div id="dash-edit-bar" class="hidden fixed bottom-6 left-1/2 -translate-x-1/2 z-[60] flex flex-wrap items-center gap-2 px-4 py-3 rounded-2xl bg-slate-950/95 border border-blue-500/40 shadow-2xl shadow-blue-900/40">
        <i data-lucide="layout-dashboard" class="w-5 h-5 text-blue-400"></i>
        <span class="text-sm font-bold text-white mr-2"><?= __('dash_edit.bar_title') ?></span>
        <span class="text-[11px] text-slate-500 mr-2 hidden md:inline"><?= __('dash_edit.bar_hint') ?></span>
        <button type="button" onclick="dashEdit.reset()" class="px-3 py-2 rounded-xl text-[12px] font-bold text-slate-300 bg-white/5 hover:bg-white/10"><?= __('dash_edit.reset') ?></button>
        <button type="button" onclick="dashEdit.cancel()" class="px-3 py-2 rounded-xl text-[12px] font-bold text-slate-300 bg-white/5 hover:bg-white/10"><?= __('dash_edit.cancel') ?></button>
        <button type="button" onclick="dashEdit.save()" class="px-4 py-2 rounded-xl text-[12px] font-black uppercase tracking-widest text-white bg-blue-600 hover:bg-blue-500"><?= __('dash_edit.save') ?></button>
    </div>
    <script>
    window.dashEdit = (function () {
        const grid = () => document.getElementById('dash-grid');
        let sortable = null;
        const T = { width: <?= $t('width') ?>, height: <?= $t('height') ?>, auto: <?= $t('auto') ?>, hide: <?= $t('hide') ?>, show: <?= $t('show') ?>,
                    saved: <?= $t('saved') ?>, error: <?= $t('error') ?>, confirmReset: <?= $t('confirm_reset') ?> };

        function applySize(el) {
            const w = +el.dataset.w, h = el.dataset.h;
            el.className = el.className.replace(/\bdw-(w\d|fixed)\b/g, '').replace(/\s+/g, ' ').trim();
            el.classList.add('dw-w' + w);
            if (h !== 'auto') el.classList.add('dw-fixed');
            el.classList.toggle('dw-hidden', el.dataset.hidden === '1');
            if (window.dwLayout) window.dwLayout();
            const tools = el.querySelector(':scope > .dw-tools');
            if (tools) {
                tools.querySelector('[data-v="w"]').innerText = w;
                tools.querySelector('[data-v="h"]').innerText = h === 'auto' ? T.auto : h;
                tools.querySelector('[data-act="hide"]').innerText = el.dataset.hidden === '1' ? T.show : T.hide;
            }
        }

        function addTools(el) {
            if (el.querySelector(':scope > .dw-tools')) return;
            const d = document.createElement('div');
            d.className = 'dw-tools';
            d.innerHTML =
                '<span class="dw-handle px-1 text-slate-400" title="drag">⠿</span>' +
                '<span class="font-bold truncate flex-1 min-w-0">' + el.dataset.label.replace(/</g, '&lt;') + '</span>' +
                '<span title="' + T.width + '">↔</span><button type="button" data-act="w-">−</button><span data-v="w" class="w-3 text-center"></span><button type="button" data-act="w+">+</button>' +
                '<span class="ml-1" title="' + T.height + '">↕</span><button type="button" data-act="h-">−</button><span data-v="h" class="min-w-[26px] text-center"></span><button type="button" data-act="h+">+</button>' +
                '<button type="button" data-act="hide" class="ml-1"></button>';
            d.addEventListener('click', ev => {
                const b = ev.target.closest('button[data-act]');
                if (!b) return;
                ev.stopPropagation();
                const act = b.dataset.act;
                let w = +el.dataset.w, h = el.dataset.h === 'auto' ? 0 : +el.dataset.h;
                if (act === 'w-') w = Math.max(1, w - 1);
                if (act === 'w+') w = Math.min(5, w + 1);
                if (act === 'h-') h = Math.max(0, h - 1);   // 0 = auto
                if (act === 'h+') h = Math.min(4, h + 1);
                if (act === 'hide') el.dataset.hidden = el.dataset.hidden === '1' ? '0' : '1';
                el.dataset.w = w;
                el.dataset.h = h === 0 ? 'auto' : String(h);
                applySize(el);
                window.dispatchEvent(new Event('resize'));   // wykresy (Chart.js) dopasowują się do nowego rozmiaru
            });
            el.appendChild(d);
            applySize(el);
        }

        function loadSortable() {
            return new Promise((res, rej) => {
                if (window.Sortable) return res();
                const s = document.createElement('script');
                s.src = 'https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js';
                s.onload = res; s.onerror = rej;
                document.head.appendChild(s);
            });
        }

        async function enter() {
            const g = grid();
            if (!g || document.body.classList.contains('dash-editing')) return;
            document.body.classList.add('dash-editing');
            g.querySelectorAll(':scope > .dash-widget').forEach(addTools);
            document.getElementById('dash-edit-bar').classList.remove('hidden');
            g.scrollIntoView({ behavior: 'smooth', block: 'start' });
            try {
                await loadSortable();
                sortable = Sortable.create(g, { handle: '.dw-handle', animation: 150, ghostClass: 'dw-ghost', chosenClass: 'dw-chosen',
                                                onEnd: () => window.dwLayout && window.dwLayout() });
                window.dwLayout && window.dwLayout();
            } catch (e) { alert(T.error + ' SortableJS'); }
        }

        function collect() {
            const order = [], widgets = {};
            grid().querySelectorAll(':scope > .dash-widget').forEach(el => {
                const id = el.dataset.widget;
                order.push(id);
                widgets[id] = { w: +el.dataset.w, h: el.dataset.h, hidden: el.dataset.hidden === '1' };
            });
            return { order, widgets };
        }

        async function post(body) {
            const res = await fetch('api_dashboard_layout.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/json', 'X-CSRF-Token': <?= json_encode(csrf_token()) ?> },
                body: JSON.stringify(body)
            });
            const j = await res.json();
            if (!j.success) throw new Error(j.message || ('HTTP ' + res.status));
        }

        function leave() {
            document.body.classList.remove('dash-editing');
            document.getElementById('dash-edit-bar').classList.add('hidden');
            if (sortable) { sortable.destroy(); sortable = null; }
            grid().querySelectorAll('.dw-tools').forEach(t => t.remove());
            if (window.dwLayout) window.dwLayout();   // ukryte kafelki znikają — przeliczamy układ
            const u = new URL(location.href);
            if (u.searchParams.has('edit_dashboard')) { u.searchParams.delete('edit_dashboard'); history.replaceState(null, '', u); }
        }

        async function save() {
            try {
                await post({ action: 'save', layout: collect() });
                leave();
                if (typeof showToast === 'function') showToast(T.saved, 'success');
            } catch (e) { alert(T.error + ' ' + e.message); }
        }

        function cancel() {
            const u = new URL(location.href);
            u.searchParams.delete('edit_dashboard');
            location.href = u.toString();
        }

        async function reset() {
            if (!confirm(T.confirmReset)) return;
            try { await post({ action: 'reset' }); cancel(); } catch (e) { alert(T.error + ' ' + e.message); }
        }

        document.addEventListener('DOMContentLoaded', () => {
            if (new URLSearchParams(location.search).has('edit_dashboard')) enter();
        });
        return { enter, save, cancel, reset };
    })();
    </script>
    <?php
}
