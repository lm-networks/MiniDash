# MiniDash — Release Notes

## v2.6.0 (2026-10-03)

Kontrola dostępu i reguły firewalla z poziomu MiniDash, edytor układu dashboardu, mapa sieci, sesje logowania oraz poprawki bezpieczeństwa.

### ⚠️ Bezpieczeństwo - przeczytaj przed aktualizacją
- **Instalacje na Apache / Synology Web Station:** w katalogu głównym nie było `.htaccess`, więc przez HTTP dało się pobrać `.env` (klucz API UniFi, hasło admina), całe `.git/` i `data/.encryption_key`. Nowy `.htaccess` zwraca 403 dla ukrytych plików i katalogów (poza `.well-known`), `includes/`, `migrations/`, `tests/`, `docker/`, `docs/` oraz plików `*.sql`, `*.sh`, `*.md`, `*.example`. Docker (nginx) już wcześniej blokował `.env` i `.git`
- `events.php` i `settings_notifications.php` działały **bez logowania** - widać było token bota Telegram i dane SMTP, a POST nadpisywał konfigurację powiadomień. Teraz wymagają sesji
- `update_wan.php` z przeglądarki tylko po zalogowaniu (zwracał IP WAN i ruch); cron z CLI bez zmian
- `api_detect_site.php` (kreator instalacji) zamknięty po instalacji - wcześniej pozwalał kazać serwerowi łączyć się z dowolnym adresem
- **Po aktualizacji sprawdź** spoza sieci, że `/.env`, `/.git/config`, `/data/.encryption_key` i `/data/config.json` zwracają 403 (gotowa komenda w `docs/INSTALL.md`). Jeśli panel był dostępny z internetu, rozważ wymianę klucza API UniFi, hasła admina, tokenu Telegrama i hasła SMTP

### Kontrola dostępu
- Przełączanie obiektów z UniFi **Settings → Objects** (blokady, harmonogramy) prosto z dashboardu
- Duży kafelek z listą obiektów - przełączenie z potwierdzeniem
- Pasek szybkich przełączników na całą szerokość i mikro-kafelki per obiekt: zielony = włączony, czerwony = wyłączony, kliknięcie przełącza bez potwierdzenia
- Wszystkie trzy widoki tego samego obiektu odświeżają się razem po każdej zmianie

### Firewall (`firewall.php`)
- Nowa strona z regułami firewalla (Integration API) i macierzą stref: domyślne zachowanie ruchu między każdą parą stref plus znaczniki Twoich reguł; kliknięcie komórki pokazuje reguły tej pary
- Włączanie i wyłączanie własnych reguł; reguły predefiniowane tylko do podglądu

### Sesje i historia logowań (`account_security.php`)
- Lista aktywnych sesji (urządzenie, IP, ostatnia aktywność) z możliwością zakończenia dowolnej z nich
- Pełna historia logowań
- Ostrzeżenie o podejrzanej sesji i alert na Telegramie: logowanie spoza Polski albo z nowego IP i niewidzianego wcześniej zestawu system + przeglądarka (samo nowe IP nie wystarcza - telefon zmienia je co chwilę)
- Zakończona sesja wylogowuje przy następnym żądaniu (`includes/session_guard.php`); migracja `005_user_sessions.sql`

### Edytor dashboardu
- Przycisk „Konfiguruj dashboard" w Danych osobistych: przeciąganie kafelków, szerokość (½, 1-5 kolumn), wysokość (auto, mini, 1-4 rzędy), ukrywanie, „Przywróć domyślny"
- Układ zapisywany na serwerze (`data/dashboard_layout.json`), więc taki sam na każdym urządzeniu
- Siatka w półkolumnach: dwa mikro-kafelki „½" mieszczą się w miejscu jednego zwykłego
- Kafelki układają się bez dziur (masonry wyrównane do siatki), wykres WAN równo z kafelkami, bez zbędnych pasków przewijania
- SortableJS ładowany tylko w trybie edycji

### Mapa sieci
- W oknie „Urządzenia UniFi" przełącznik **Lista / Mapa** (wybór zapamiętany w przeglądarce)
- Drzewo od bramy w dół: porty, urządzenia, status online/offline, klienci po kliknięciu w licznik (IP, port albo SSID)
- **Switche niezarządzane wykrywane automatycznie z LLDP**: kilka urządzeń UniFi (albo urządzenie i klienci przewodowi) na jednym porcie = switch pomiędzy. Wstawiany jest wirtualny węzeł nazwany jak port w UniFi, a pod nim urządzenia i klienci z tego portu. Dzięki temu mapa nie powiela błędnych uplinków, które kontroler zgłasza dla sprzętu za zwykłym switchem
- Logika w czystej funkcji `build_network_tree()` (`includes/network_map.php`), pokrytej testami

### Poprawki
- **Strefa czasowa z ustawień jest wreszcie stosowana.** „Auto" bierze strefę konsoli z `get/setting` → `locale` (wcześniej szukana w pustym `get/setting/system`, przez co panel pokazywał „Z konsoli (UTC)", a PHP używało strefy z `php.ini`). Strefa konsoli zapamiętywana na dobę w `data/console_timezone.txt`
- Statystyki w pasku nawigacji na wszystkich stronach (Firewall, Zdarzenia, Powiadomienia)
- Zapis „Dane osobiste" nic nie robił - przycisk był poza formularzem
- Brakujące tłumaczenia EN; testy 149/149

### Znane problemy
- `logs.php` nie wyświetla logów: `api_logs.php` odpowiada poprawnie, ale zwraca pustą listę. Do poprawy w następnej wersji

---

## v2.5.0 (2026-10-03)

Historia awarii łączy WAN i wybór łącza na wykresie dashboardu.

### Historia łączy WAN (`wan_history.php`)
- Nowa strona, link „Historia awarii" w panelu „Łącze WAN" na dashboardzie
- Kafelek per łącze: dostępność w procentach, liczba awarii, łączny czas przerw, ostatnia awaria; zakres 7 / 30 / 90 dni
- Lista awarii: łącze, początek, koniec (albo „trwa"), czas trwania z dokładnością do ~1 min
- Dostępność miesięczna każdego łącza z ostatnich trzech miesięcy
- Osobno „przerwy w pomiarach" (≥ 10 min bez żadnej próbki - brak prądu, stojący cron). Nie liczą się jako awaria ani nie zaniżają dostępności, bo w tym czasie stan łącza jest nieznany
- Wszystko liczone z `wan_stats` (kolumna `up` per łącze z v2.4.0), bez odpytywania kontrolera
- Grupowanie próbek w przerwy w czystej funkcji `group_wan_outages()`, pokrytej testami

### Wybór łącza na wykresie
- Kliknięcie kafelka WAN1 / WAN2 nad wykresem przełącza wykres na to łącze, w jego kolorach (WAN1 niebieski, WAN2 zielony)
- Nagłówek panelu pokazuje wtedy publiczne IP, status i transfer wybranego łącza
- Ponowne kliknięcie albo przycisk „Wszystkie łącza" wraca do sumy z przerywaną linią per łącze; wybór zapamiętany w przeglądarce

---

## v2.4.0 (2026-07-24)

Drugie łącze WAN w całej aplikacji, alert failovera, raport dobowy i kafelek największych konsumentów.

### Dual-WAN
- Nowy helper `get_wan_links()` — jedno źródło prawdy o łączach dla dashboardu, pollera i wyzwalaczy. Zwraca **również łącza w dole**; wcześniej każdy z trzech konsumentów filtrował po `up`, więc łącze zapasowe nie istniało w UI dopóki nie przejęło ruchu
- `find_trad_gateway()` szuka bramy po obecności klucza `wan1`, nie po liście modeli — `UCG Fiber` do tej pory w tej liście nie występował
- **Fix: poller nigdy nie znajdował bramy na UCG Fiber** — `update_wan.php` dopasowywał model do listy `['UDR','UDM','UXG','USG']`, nie trafiał i zapisywał `rx=0, tx=0`. Wykres live i cała tabela `wan_stats` zawierały wyłącznie zera
- Panel „Łącze WAN": pasek per łącze (status, IP, transfer) nad wykresem, aktualizowany na żywo. Pojawia się przy dwóch i więcej łączach
- Wykres dokłada przerywaną linię pobierania per łącze — przy failoverze suma wygląda identycznie niezależnie od tego, które łącze niesie ruch
- Status zbiorczy WAN to teraz „ONLINE, gdy żyje choć jedno łącze", a nie „gdy lista łączy jest niepusta"
- `wan_stats` rozbite na łącza: migracja `004_wan_stats_multiwan.sql` dokłada `wan_idx` (0 = wiersz zbiorczy, 1..n = łącza) oraz `up` do liczenia dostępności

### Alert failovera
- Nowy wyzwalacz `triggers.wan_alert_enabled`: łącze padło → `critical`, wróciło → `info` z czasem przerwy
- Treść alertu wymienia łącza, które nadal działają, albo mówi wprost „BRAK — sieć jest bez internetu"
- Alert dopiero po dwóch cyklach w dole; pojedynczy nieudany odczyt przy renegocjacji łącza nie jest awarią
- Pusta odpowiedź kontrolera nie zmienia stanu — timeout API nie wygeneruje fałszywej awarii
- Logika w czystej funkcji `evaluate_wan_transitions()`, pokrytej testami; cron tylko wysyła to, co ona zwróci

### Raport dobowy
- `triggers.daily_report_enabled` + suwak godziny wysyłki; severity `info`, czyli topik Info na Telegramie
- Zawiera: ruch WAN 24h (średnia, szczyt, szacowany wolumen), dostępność każdego łącza, zdarzenia wg severity, nowe urządzenia i transfer urządzeń monitorowanych
- Warunek wysyłki to „godzina minęła i dziś jeszcze nie było", a nie równość godzin — jeden nieudany przebieg crona nie gubi raportu na cały dzień
- Budowany wyłącznie z SQLite, bez odpytywania kontrolera: raport wyjdzie także wtedy, gdy API akurat leży
- Nazwy urządzeń czyszczone ze znaków Markdown — pojedynczy `_` w nazwie wywracał parsowanie i cała wiadomość wracała z HTTP 400

### Dashboard
- Kafelek „Najwięksi konsumenci" o szerokości trzech kafelków statystyk (`col-span-3` w siatce pięciu kolumn): 5 klientów po sumie transferu, z podziałem na pobieranie/wysyłanie i czasem sesji. Liczniki są skumulowane od momentu połączenia klienta — stąd czas sesji obok, inaczej urządzenie wiszące w sieci od tygodnia zawsze bije rekordzistę z ostatniej godziny
- Fix: `uptime` klienta dobierane z traditional API przy scalaniu list — Integration API go nie zwraca, więc czas sesji zawsze wynosił `0s`

### Baza
- `PRAGMA busy_timeout=5000` — poller z przeglądarki i cron trafiały w bazę jednocześnie, a SQLite zwracał „database is locked" natychmiast i cykl przepadał
- Zapis próbek WAN w jednej transakcji zamiast trzech osobnych INSERT-ów

---

## v2.3.3 (2026-07-24)

Powiadomienia Telegrama do topików grupy, naprawa jednostek transferu i alertu prędkości.

### Telegram — topiki
- Routing powiadomień do wątków grupy wg severity: `thread_critical` / `thread_warning` / `thread_info` w ustawieniach
- Normalizacja siedmiu nazw severity krążących po kodzie (`info`, `warning`, `critical`, `attack`, `alert`, `medium`, `high`) do trzech topików; nieznana wartość trafia do Info, żeby nowy poziom nie zgubił powiadomienia
- Puste ID topiku = brak `message_thread_id` = zachowanie sprzed zmiany, więc instalacja pisząca na czat prywatny działa bez zmian w konfiguracji
- Składnia komunikatów bez zmian — dokładany jest wyłącznie `message_thread_id`
- Nieudana wysyłka trafia do `logs/php_errors.log` (kod HTTP, `chat_id`, wątek, opis błędu od Telegrama). Wcześniej `@curl_exec` połykał wszystko i zły `chat_id` objawiał się wyłącznie ciszą
- Pole Bot Token jako `type="password"` z przyciskiem podglądu

### Transfer klientów — jednostki
- Nowa funkcja `client_rate_bps($klient, 'rx'|'tx')` jako jedyne źródło prawdy: `rxRateBps`/`txRateBps` brane wprost (bity/s), `rx_bytes-r` i warianty `wired-` mnożone przez 8 (bajty/s)
- **Usunięte poleganie na `rx_rate`/`tx_rate` ze `stat/sta`** — to wynegocjowana prędkość linku Wi-Fi w Kbps, nie transfer. Klient z linkiem 72 Mbps i ruchem 366 B/s raportował 72 000
- Ten sam błędny łańcuch fallbacków był powielony w `functions.php`, `index.php` (2×) i `monitored.php` — wszystkie wołają teraz helper
- Wzbogacanie z Integration API w `cron_triggers.php` przenosi wartości pod `rxRateBps`/`txRateBps` zamiast mieszać je z prędkością linku pod `rx_rate`
- Fix: podwójne mnożenie przez 8 w tabeli klientów zawyżało odczyt ośmiokrotnie

### Alert prędkości
- Obejmuje wszystkich aktywnych klientów, nie tylko listę monitorowanych
- Próg porównywany z `max(rx, tx)`, treść alertu podaje kierunek (pobieranie / wysyłanie)
- Usunięta druga kopia triggera z `index.php`: patrzyła tylko na download i odpalała się jedynie przy otwartym dashboardzie, bo cooldown trzymała w `$_SESSION`, niedostępnej dla crona
- `last_speeds.json` zapisuje tylko klientów widzianych w danym cyklu, więc plik nie puchnie o MAC-i urządzeń nieobecnych w sieci

### Fixes
- Fix: duplikat na liście klientów — `foreach ($clients as &$c)` bez `unset()` powodował, że kolejna pętla o tej samej nazwie zmiennej nadpisywała ostatni element tablicy. Ostatni klient był wyświetlany jako kopia przedostatniego i **znikał z listy**

---

## v2.3.2 (2026-06-09)

Anti-flapping alertów statusu, pewniejsze wykrywanie klientów i rebranding domeny.

### Device Status / Anti-flapping
- Okno karencji przed alertem OFFLINE (`triggers.offline_grace_sec`, domyślnie 60s, suwak w ustawieniach wyzwalaczy, 0 = wyłączone)
- Pojedyncze pudło pollingu (roaming między AP, WiFi power-save, handoff) nie generuje już fałszywych alertów OFFLINE→ONLINE
- Stan trzymany w tabeli `device_offline_pending` — próg oparty o czas, odporny na to, że funkcję wołają co minutę dwa crony (cron_triggers.php + update_wan.php)
- Czas „był online przez…" liczony od momentu realnego zniknięcia, nie zawyżony o karencję
- Nowa migracja: `migrations/003_device_offline_pending.sql`

### Wykrywanie klientów
- Traditional `stat/sta` jako PRIMARY (wszystkie VLAN-y + telefony z losowym MAC), Integration API v1 jako enrichment — `monitored.php`, `update_wan.php`
- Wzbogacone alerty OFFLINE/ONLINE/nowe-urządzenie o uplink (switch:port) i SSID

### Branding
- Linki `www.lm-ads.com` / `lm-ads.com` → `lm-networks.pl` (komentarze deweloperskie `dev.lm-ads.com` bez zmian)

---

## v2.3.1 (2026-04-15)

Docker & stability fixes, update notifications, auto-detect Site ID.

### Setup Wizard
- Auto-detect Site ID — przycisk "Detect" odpytuje kontroler i wypelnia Site ID automatycznie
- Wsparcie multi-site — pokazuje liste site'ow do wyboru
- Hint pod polem Site ID

### Update Notifications
- Banner pod navbarem gdy dostepna jest nowsza wersja na GitHub
- Sprawdzanie co 6h, cache w data/update_check.json
- Dismissable (przycisk X)
- version.json w repo jako zrodlo prawdy

### Fixes
- Fix: biala strona index.php — catch non-array clients z API kontrolera
- Fix: stray output w functions.php psuacy header() na wszystkich stronach
- Fix: session_write_close() w devices.php blokujacy zapis ustawien
- Fix: hardcoded gateway 10.0.0.1 w pingach — teraz dynamiczny z Controller URL
- Fix: usuniety obsolete version w docker-compose.yml
- Footer: dynamiczna wersja z MINIDASH_VERSION zamiast hardcoded v1.5.0

---

## v2.3.0 (2026-04-15)

Docker fixes & Setup Wizard — rozwiązanie problemów z instalacją Docker i nowy kreator konfiguracji.

### Setup Wizard (new)
- Kreator pierwszej konfiguracji — formularz zamiast ręcznej edycji .env
- Automatyczne wykrywanie pierwszego uruchomienia (marker data/.installed)
- Pola: Controller URL, API Key, Site ID, Admin login/hasło, imię, email
- Walidacja wymaganych pól i minimalnej długości hasła
- Zapis konfiguracji do .env z odpowiednimi uprawnieniami
- Redirect z login.php i index.php na setup.php gdy brak konfiguracji
- Istniejące instalacje (z poprawnym API key) automatycznie pomijają wizard

### Docker
- Fix: biała strona po instalacji — baza danych tworzona jako root, PHP-FPM (www-data) nie mógł pisać
- Fix: dodany drugi chown w start.sh po migracji bazy danych
- Fix: dodane brakujące PHP curl extension (wymagane przez requirements)
- Dodany bash do Alpine — terminal w Synology Container Manager teraz działa
- Dodany mc (Midnight Commander) — łatwiejsza nawigacja po kontenerze
- config.php: wartości z .env nadpisują zmienne Docker (setup wizard ma priorytet)

### VPN Triggers
- Poprawiony endpoint API: stat/event zamiast rest/alarm (kompatybilność z UDR)
- Ulepszone dopasowanie kluczy VPN (case-insensitive, connect/disconnect)
- Severity w alertach VPN (info/warning)
- Odblokowany trigger VPN w UI (usunięty opacity-50)

---

## v2.2.0 (2026-04-12)

Threat Watch — przebudowany moduł Security z zakładkami i live IDS/IPS monitoring.

### Threat Watch (IDS/IPS)
- Nowa zakładka "Zagrożenia" w Security — live monitoring IDS/IPS z auto-refresh co 60s
- Kaskadowy API fallback: V2 traffic-flows → stat/ips/event → rest/alarm (kompatybilność z UDR, UDM Pro, UDM SE, UCG, UXG)
- Pre-loaded dane z PHP — natychmiastowe przełączanie między zakładkami
- Path memory (24h cache) — zapamiętuje który endpoint działa, pomija niedziałające
- Paginacja listy zagrożeń (25/50/100) z nawigacją < >
- Filtry: zakres czasu (1h/24h/7d), ryzyko (high/medium/low), akcja (blocked/alert), wyszukiwarka
- Modal szczegółów zdarzenia z GeoIP (kraj, miasto, ISP) dla source i destination
- Sidebar: top kraje źródłowe, rozkład ryzyka, top kategorie zagrożeń
- Eksport CSV z filtrowanych danych
- Ignorowanie IP z listy zagrożeń (integracja z threat_ignore)

### Security Overview
- Zakładka "Przegląd" z Security Score (SVG), statusem IPS/Honeypot/Ad-block
- Wyświetlanie trybu IPS: IDS (detekcja), IPS (detekcja + blokowanie), IPS Inline (pełna ochrona)
- Karty konfiguracji: IPS mode, Ad Blocking, Honeypot, Geo-blocking
- Protection pillars, modal reguł firewalla, modal geo-blocking z flagami krajów
- Przebudowany security score modal z rozbiciem na czynniki

### Backend
- Nowa funkcja `fetch_api_post()` — POST z API key dla V2 i legacy endpointów
- Nowa funkcja `fetch_threat_events()` — kaskadowy fallback z normalizacją danych
- Normalizatory: `normalize_v2_threat()`, `normalize_legacy_threat()`, `normalize_alarm_threat()`
- Nowy endpoint `api_threats.php` — AJAX z filtrami, statystykami, top countries/categories
- Dodany `ips_mode` do `get_unifi_security_settings()`

### UI/CSS
- Nowe glow: `stat-glow-orange`, `stat-glow-red`, `stat-glow-rose` w dashboard.css
- Style paginacji `.page-size-btn` z aktywnym stanem (orange)

### i18n (PL/EN)
- 43 nowe klucze `threats.*` (PL + EN) — eventy, filtry, paginacja, modal, sidebar
- 6 kluczy `threats.mode_*` — tryby IPS/IDS
- 2 klucze `security.tab_overview`, `security.tab_threats`

## v2.1.1 (2026-04-11)

Internationalization, security audit, notification fixes.

### i18n (PL/EN)
- Full English translation of the entire interface
- __() function with parameter support and dot-notation
- Language files: lang/pl.json (~520 keys), lang/en.json
- Language switcher in Personal settings modal (saved to config.json)
- All pages translated: dashboard, security, stalker, monitored, devices, events, history, logs, protect, login, footer, navbar, notification settings

### Notifications
- Device names now visible in Telegram and all other channels (subject + message)
- Severity correctly saved in SQLite events (instead of hardcoded WARNING)
- Bell panel — colors based on severity (critical=red, warning=amber, info=green)
- Alert clicks navigate to events.php (instead of history.php with empty MAC)

### Security Audit
- Auth check added to api_ping.php, api_clear_history.php, api_save_settings.php
- display_errors=0 in production (config.php, api_user_settings.php)
- error_reporting(0) in update_wan.php replaced with proper logging
- Avatar upload: MIME type validation + 5MB size limit + random filename + old file cleanup

### Code Cleanup
- Removed ~370 lines of dead code (old _disabled_render_* functions)
- Removed 20+ debug files from data/ and logs/
- WAN Link card no longer stretches to VLAN height (self-start)

### Docker
- Docker files ready: Dockerfile, docker-compose.yml, nginx.conf, start.sh

---

## v2.1.0 (2026-04-10)

Dashboard expansion, security improvements, system settings and optimizations.

### Dashboard
- Dynamic WAN sessions from API (replaced hardcoded data)
- VLAN detection from UniFi API networkconf (replaced hardcoded IP map)
- VLAN detail modal — click VLAN to see clients with transfer stats
- AP drilldown — wired and wireless clients via sw_mac/ap_mac
- Navbar upload color changed to amber (consistent with Egress)
- formatBps supports Tbps/Gbps
- WAN units fix (rx_bytes-r * 8 for bps)
- Stalker widget shows active WiFi sessions count

### Security
- IPS config dropdown with clickable modals (rules, threat intel, geo-blocking)
- Geo-blocking modal with country flags and block counts (from IPS events)
- Security score fix — blocked threats count as positive, not penalty
- VPN detection from networkconf (+10 pts score)
- Firewall rules detection fix (meta.rc replaced with !empty data check)
- MongoDB ObjectId support in get_trad_site_id
- Blocked IPs country code from srcipCountry
- Security events pagination (MiniPagination)
- Cache TTL increased (5min settings, 2min events)

### Smart Triggers
- New device — alert on unknown MAC (with learning phase + cooldown)
- IPS Alert — notification on blocked attack
- High latency — configurable threshold alert
- VPN connection — connect/disconnect alerts
- Speed spike — traffic threshold per device

### System Settings
- Data retention — per-table sliders (7-730 days)
- Session security — timeout, max login attempts, lock duration
- Dashboard refresh — configurable polling interval
- Database management — size, records, VACUUM, config/DB export

### About System
- CPU, RAM, Disk, Uptime in modal
- Update channel info
- Dynamic detection of installed apps (Network, Protect, Talk, Access)
- VPN list from networkconf (OpenVPN + WireGuard)

### Notifications
- Alerts visible in bell panel (sendAlert -> SQLite events -> notification panel)
- Process Manager — real data from API
- WAN health — latency, packet_loss from gateway device stats

### Other
- Global pagination component (MiniPagination) — reusable
- Removed fake progress bars from Ingress/Egress
- Removed hardcoded Process Manager data
- monitored.php — detail modal fixed, add device button
- protect.php — improvements

---

## v2.0.0 (2026-04-10)

Major update: SQLite migration, Wi-Fi Stalker, enhanced Threat Watch, new notification channels, credential encryption.

### SQLite Database
- Migration from JSON files to SQLite as central storage
- Automatic migration system (migrations/) — new schema versions applied on startup
- Auto-purge of old data (configurable day thresholds per table)
- migrate_json.php script for one-time migration of existing data

### Wi-Fi Stalker (new)
- Real-time WiFi session tracking
- Roaming detection between Access Points
- Roaming history with RSSI, channel and timing
- Device watchlist with roaming alerts
- CSV export
- Filters: time range (1h/24h/7d/30d), band (2.4/5/6 GHz), search
- Dashboard widget with active session count
- 30s polling with automatic change detection

### Threat Watch Enhancements
- IP Ignore List — whitelist of IPs excluded from threat analysis
- Time range filters (1h/24h/7d/30d) on security events
- Auto-purge of old events (30 days)

### AP Drilldown
- Click on infrastructure device to expand client list
- WiFi clients (by ap_mac) and wired clients (by sw_mac with port number)
- Data from UniFi Traditional API (RSSI, network, speed, IP)

### New Notification Channels
- Discord — webhook with rich embeds
- n8n — generic webhook for automation
- Configuration panels in notification settings
- Test endpoint api_test_alert.php

### Credential Encryption
- Sensitive config.json fields encrypted with sodium_crypto_secretbox
- Automatic encryption on save, decryption on read
- Key auto-generated (data/.encryption_key)
- ENC: prefix on encrypted values

### Security & Structure
- Secrets moved from hardcoded values to .env
- Git initialized with .gitignore blocking sensitive data
- Removed UTF-8 BOM from all PHP files
- Debug/test files moved to _old/

### Footer
- App version + git hash (clickable changelog)
- LM-Networks branding with links (lm-networks.pl, dev.lm-ads.com)
- Copyright 2025-2026

### Favicon
- SVG favicon on all pages

---

## v1.5.0 (2026-02-06)

### System Logs (logs.php)
- API-driven Event System instead of file parsing
- Severity filters (INFO/WARNING/ERROR/CRITICAL)
- Pagination: 25, 50, 100, 500 per page
- Modal with raw JSON event preview

### UniFi Protect Dashboard (protect.php)
- Dynamic camera grid: 1, 2, 4, 9, 12 views
- Interactive slots for camera source selection
- NVR status, disk usage, recording status and estimated archive length
- Bandwidth monitoring for cameras

### General
- Layout standardized to max-w-7xl
- Logs icon added to navigation

---

Created by Lukasz Misiura | dev.lm-ads.com
