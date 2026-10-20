<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/
/**
 * Połączenie z konsolą UniFi niezależne od reszty aplikacji: używa go kreator instalacji
 * (setup.php działa przed config.php) i strona ustawień konsoli (console_settings.php).
 */

/**
 * Adres konsoli w dowolnej postaci ("10.0.0.1", "10.0.0.1:8443", "https://10.0.0.1/")
 * → "https://10.0.0.1[:port]". Pusty string = adres niepoprawny.
 */
function unifi_normalize_url(string $input): string
{
    $input = trim(str_replace(["\r", "\n"], '', $input));
    if ($input === '') return '';
    if (!preg_match('#^https?://#i', $input)) $input = 'https://' . $input;

    $p = parse_url($input);
    if (!$p || empty($p['host'])) return '';
    // Ścieżka, login w adresie czy parametry nie mają sensu - klient dokleja /proxy/network/...
    if (isset($p['user']) || isset($p['query']) || isset($p['fragment'])) return '';
    if (($p['path'] ?? '') !== '' && $p['path'] !== '/') return '';
    if (!preg_match('/^([a-z0-9-]+\.)*[a-z0-9-]+$|^\[[0-9a-f:.]+\]$/i', $p['host'])) return '';

    return strtolower($p['scheme']) . '://' . strtolower($p['host']) . (isset($p['port']) ? ':' . (int)$p['port'] : '');
}

/** GET do konsoli z kluczem API. Zwraca [kod HTTP, treść, błąd cURL]. */
function unifi_probe_get(string $url, string $apiKey): array
{
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        // Konsole UniFi mają certyfikat self-signed
        CURLOPT_SSL_VERIFYPEER => false,
        CURLOPT_SSL_VERIFYHOST => false,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_TIMEOUT        => 10,
        CURLOPT_HTTPHEADER     => ["X-API-KEY: $apiKey", 'Accept: application/json'],
    ]);
    $body = curl_exec($ch);
    $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);
    return [$code, is_string($body) ? $body : '', $err];
}

/**
 * Lista site'ów konsoli - przy okazji test adresu i klucza API.
 * Wynik: ['sites' => [['id' => ..., 'name' => ...], ...], 'error' => null|'url'|'connect'|'auth'|'http', 'detail' => string]
 */
function unifi_detect_sites(string $base, string $apiKey): array
{
    if ($base === '') return ['sites' => [], 'error' => 'url', 'detail' => ''];

    // Integration API zwraca UUID site'u, którego MiniDash używa w wywołaniach v1
    [$code, $body, $err] = unifi_probe_get("$base/proxy/network/integration/v1/sites", $apiKey);
    $sites = [];
    if ($code === 200) {
        foreach ((json_decode($body, true)['data'] ?? []) as $s) {
            $id = $s['siteId'] ?? $s['id'] ?? '';
            if ($id !== '') $sites[] = ['id' => $id, 'name' => $s['meta']['name'] ?? $s['name'] ?? $id];
        }
    }

    // Starsze kontrolery bez Integration API
    if (!$sites && $err === '') {
        [$code2, $body2] = unifi_probe_get("$base/proxy/network/api/self/sites", $apiKey);
        if ($code2 === 200) {
            foreach ((json_decode($body2, true)['data'] ?? []) as $s) {
                $id = $s['name'] ?? $s['_id'] ?? '';
                if ($id !== '') $sites[] = ['id' => $id, 'name' => $s['desc'] ?? $id];
            }
        }
    }

    if ($sites) return ['sites' => $sites, 'error' => null, 'detail' => ''];
    if ($err !== '') return ['sites' => [], 'error' => 'connect', 'detail' => $err];
    if ($code === 401 || $code === 403) return ['sites' => [], 'error' => 'auth', 'detail' => "HTTP $code"];
    return ['sites' => [], 'error' => 'http', 'detail' => "HTTP $code"];
}

/** Site ID z kreatora/ustawień: UUID, "default" albo krótka nazwa site'u. */
function unifi_valid_site(string $site): bool
{
    return (bool)preg_match('/^[A-Za-z0-9_-]{1,64}$/', $site);
}
