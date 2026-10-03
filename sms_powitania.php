<?php
/** Created by Łukasz Misiura (c) 2025 | dev.lm-ads.com **/

/**
 * Powitania SMS przy wejściu i wyjściu z VPN-a.
 *
 * Gdy wskazana osoba włączy router (wejście do VPN-a) albo go wyłączy, leci do
 * niej SMS-em jedno zdanie od „Cerbera, asystenta LM-Networks". Treść zależy od
 * pory dnia, bo o piątej rano co innego ma sens niż o dziewiątej wieczorem.
 *
 * Wysyłka idzie przez bramkę HTTP na PBX-ie (`sms_relay.py`, port 8090), a nie
 * przez SSH: to ta sama droga, którą wysyła Cerber, tylko bez pośrednictwa
 * Telegrama - bot Telegrama nie odbiera poleceń od innego bota.
 *
 * Wszystko poza kodem siedzi w `data/sms_powitania.json`: numery, teksty,
 * godziny progów i dane bramki. Zmiana zdania albo przesunięcie progu nie
 * wymaga więc ruszania kodu ani wdrożenia.
 *
 * NIC STĄD NIE MOŻE WYWRÓCIĆ CRONA. Powitanie jest miłym dodatkiem, a wyzwalacz
 * obok pilnuje sieci - dlatego każdy błąd kończy się wpisem w logu i ciszą.
 */

/** Domyślna konfiguracja - używana, gdy pliku jeszcze nie ma. */
function smsPowitaniaDomyslne()
{
    return [
        'wlaczone' => true,
        'bramka' => [
            'url' => 'http://10.10.0.212:8090/send',
            'uzytkownik' => 'dpcrm',
            'haslo' => '',
            'urzadzenie' => 'dongle0',
        ],
        // Ile minut ciszy po poprzednim SMS-ie do tej osoby. Tunel na telefonie
        // potrafi migotać przy przeskoku WiFi/LTE, a wtedy "Do zobaczenia"
        // i "Witaj" przyszłyby w odstępie minuty.
        'odstep_minut' => 20,
        'osoby' => [],
        'okna' => [
            'rano_od' => '04:30', 'rano_do' => '07:30',
            'wyjscie_od' => '05:45', 'wyjscie_do' => '08:30',
            'powrot_od' => '13:00', 'powrot_do' => '20:30',
            'noc_od' => '20:00',
        ],
        'teksty' => [],
    ];
}

function smsPowitaniaKonfig()
{
    $plik = __DIR__ . '/data/sms_powitania.json';

    if (!file_exists($plik)) {
        return smsPowitaniaDomyslne();
    }

    $dane = json_decode(file_get_contents($plik), true);

    return is_array($dane) ? array_merge(smsPowitaniaDomyslne(), $dane) : smsPowitaniaDomyslne();
}

function smsPowitaniaLog($tekst)
{
    @file_put_contents(
        __DIR__ . '/logs/sms_powitania.log',
        date('Y-m-d H:i:s') . ' ' . $tekst . "\n",
        FILE_APPEND
    );
}

/**
 * Czy godzina mieści się w oknie. Okna nie przechodzą przez północ, bo żadne
 * z nich tego nie potrzebuje - noc obsługuje osobny próg „od".
 */
function smsPowitaniaWOknie($minuty, $od, $do)
{
    $naMinuty = function ($hhmm) {
        $czesci = explode(':', $hhmm);

        return ((int) $czesci[0]) * 60 + (int) (isset($czesci[1]) ? $czesci[1] : 0);
    };

    return $minuty >= $naMinuty($od) && $minuty <= $naMinuty($do);
}

/**
 * Który zestaw zdań pasuje do tego zdarzenia.
 *
 * @return string klucz w `teksty`
 */
function smsPowitaniaSlot($rodzaj, $zdarzenie, $czas, $okna)
{
    if ($rodzaj === 'telefon') {
        return $zdarzenie === 'up' ? 'telefon_up' : 'telefon_down';
    }

    $minuty = ((int) date('H', $czas)) * 60 + (int) date('i', $czas);
    $weekend = in_array(date('N', $czas), ['6', '7'], true);

    if ($zdarzenie === 'up') {
        if (smsPowitaniaWOknie($minuty, $okna['rano_od'], $okna['rano_do'])) {
            // W weekend nikt nie idzie do pracy, więc i powitanie jest inne.
            return $weekend ? 'weekend_rano' : 'rano';
        }

        if (smsPowitaniaWOknie($minuty, $okna['powrot_od'], $okna['powrot_do'])) {
            return 'powrot';
        }

        return 'inna_pora_up';
    }

    // Wyjście do pracy tylko w dni robocze - w sobotę o siódmej to nie to samo.
    if (!$weekend && smsPowitaniaWOknie($minuty, $okna['wyjscie_od'], $okna['wyjscie_do'])) {
        return 'wyjscie';
    }

    $nocOd = explode(':', $okna['noc_od']);
    if ($minuty >= ((int) $nocOd[0]) * 60 + (int) (isset($nocOd[1]) ? $nocOd[1] : 0)) {
        return 'noc';
    }

    return 'inna_pora_down';
}

/**
 * Meldunek na Telegram: co dokładnie poszło SMS-em.
 *
 * Wysyłka SMS-a jest jedynym zdarzeniem w tej aplikacji, którego nie widać
 * z żadnego ekranu - wiadomość ląduje na cudzym telefonie i tyle. Bez tego
 * meldunku jedynym śladem byłby plik logu na serwerze.
 *
 * `function_exists` jest tu istotne: przy uruchomieniu kontrolnym
 * (`php sms_powitania.php`) nie ma bootstrapu MiniDasha, więc nadawcy
 * Telegrama też nie ma - i nie może to niczego wywrócić.
 */
function smsPowitaniaMeldunek($tekst, $waga = 'info')
{
    if (!function_exists('sendTelegramNotification')) {
        return;
    }

    try {
        // sendTelegramNotification wysyla z parse_mode=Markdown, wiec pojedynczy
        // `_`, `*`, `[` albo backtick w tresci wywraca cala wiadomosc bledem
        // „can't parse entities" i meldunek przepada bez sladu w Telegramie.
        // W logs/php_errors.log widac 25 takich przypadkow na wlasnych alertach
        // MiniDasha - dlatego znaki aktywne w Markdownie escapujemy tutaj.
        $bezpieczny = preg_replace('/([_*`\[\]])/', '\\\\$1', $tekst);

        sendTelegramNotification($bezpieczny, $waga);
    } catch (Throwable $e) {
        smsPowitaniaLog('MELDUNEK NIEUDANY: ' . $e->getMessage());
    }
}

/**
 * Co jest nie tak z tą treścią. Pusta tablica = wszystko w porządku.
 *
 * Sprawdzane jest to, co realnie psuje wiadomość po drodze do telefonu albo
 * wywraca zamysł:
 *
 * - polskie znaki przełączają SMS na kodowanie UCS-2 i limit spada ze 160
 *   znaków na 70, więc zdanie po cichu tnie się na dwie wiadomości,
 * - powyżej 160 znaków operator dzieli SMS-a tak samo,
 * - brak „Cerbera" znaczy, że wiadomość nie brzmi jak od niego - a to jest
 *   cały pomysł na te powitania,
 * - cudzysłów rozwaliłby polecenie po stronie bramki.
 *
 * @return array<int, string> lista problemów po polsku
 */
function smsPowitaniaSprawdzTresc($tresc)
{
    $problemy = [];
    $tresc = (string) $tresc;

    if (trim($tresc) === '') {
        return ['pusta treść'];
    }

    if (strlen($tresc) > 160) {
        $problemy[] = 'za długa (' . strlen($tresc) . ' znaków, limit 160)';
    }

    if (preg_match('/[^\x20-\x7E]/', $tresc)) {
        $problemy[] = 'znaki spoza ASCII (ogonki tną SMS-a do 70 znaków)';
    }

    if (stripos($tresc, 'cerber') === false) {
        $problemy[] = 'brak podpisu Cerbera';
    }

    if (strpos($tresc, '"') !== false) {
        $problemy[] = 'cudzysłów - rozwali polecenie bramki';
    }

    return $problemy;
}

/**
 * Sprowadza wariant do postaci ['tekst' => ..., 'min_nieobecnosc_h' => ...].
 *
 * W pliku wariant może być zwykłym napisem albo obiektem z warunkiem - dzięki
 * temu zdania bez żadnych wymagań zapisuje się tak samo krótko jak dotąd.
 */
function smsPowitaniaWariant($wariant)
{
    if (is_array($wariant)) {
        return $wariant + ['tekst' => '', 'min_nieobecnosc_h' => null, 'max_nieobecnosc_h' => null];
    }

    return ['tekst' => (string) $wariant, 'min_nieobecnosc_h' => null, 'max_nieobecnosc_h' => null];
}

/**
 * Czy wariant pasuje do długości nieobecności.
 *
 * „Czekał cały dzień" ma sens po powrocie z pracy, a nie po dwugodzinnym
 * wypadzie do sklepu. Gdy nie wiemy, jak długo jej nie było (pierwszy raz po
 * wdrożeniu, wyczyszczony stan), wariant z warunkiem NIE startuje - lepiej
 * wysłać zdanie neutralne niż nietrafione.
 *
 * @param  int|null  $przerwa  sekundy nieobecności albo null
 */
function smsPowitaniaPasujeCzasem($wariant, $przerwa)
{
    if ($wariant['min_nieobecnosc_h'] === null && $wariant['max_nieobecnosc_h'] === null) {
        return true;
    }

    // Nie tylko null: gdyby plik stanu kiedykolwiek zawierał śmieć, dzielenie
    // rzuciłoby TypeError, a ten - złapany wyżej - uciszyłby całe powitanie.
    if (!is_numeric($przerwa)) {
        return false;
    }

    $godzin = (float) $przerwa / 3600;

    if ($wariant['min_nieobecnosc_h'] !== null && $godzin < (float) $wariant['min_nieobecnosc_h']) {
        return false;
    }

    if ($wariant['max_nieobecnosc_h'] !== null && $godzin > (float) $wariant['max_nieobecnosc_h']) {
        return false;
    }

    return true;
}

/**
 * Wybiera wariant, który przechodzi kontrolę i pasuje do sytuacji.
 *
 * Zła treść nie może uciszyć powitania - to psułoby niespodziankę gorzej niż
 * literówka. Dlatego najpierw losujemy z tych poprawnych, a gdy żaden nie
 * przechodzi, idzie pierwszy z brzegu i zostaje głośny wpis w logu.
 *
 * @param  array<int, string|array>  $warianty
 * @param  int|null                  $przerwa  sekundy nieobecności albo null
 * @return string
 */
function smsPowitaniaWybierz($warianty, $slot, $przerwa = null)
{
    $dobre = [];
    $zapasowy = null;

    foreach ($warianty as $surowy) {
        $wariant = smsPowitaniaWariant($surowy);
        $problemy = smsPowitaniaSprawdzTresc($wariant['tekst']);

        if ($problemy !== []) {
            smsPowitaniaLog("UWAGA [$slot] odrzucony wariant (" . implode('; ', $problemy) . "): {$wariant['tekst']}");

            continue;
        }

        // Zdanie bez warunków nadaje się zawsze - trzymamy je jako zapasowe na
        // wypadek, gdyby wszystkie pozostałe odpadły przez czas nieobecności.
        if ($zapasowy === null && $wariant['min_nieobecnosc_h'] === null && $wariant['max_nieobecnosc_h'] === null) {
            $zapasowy = $wariant['tekst'];
        }

        if (smsPowitaniaPasujeCzasem($wariant, $przerwa)) {
            $dobre[] = $wariant['tekst'];
        }
    }

    if ($dobre !== []) {
        return $dobre[array_rand($dobre)];
    }

    if ($zapasowy !== null) {
        return $zapasowy;
    }

    smsPowitaniaLog("UWAGA [$slot] zaden wariant nie przeszedl kontroli - leci pierwszy mimo to");

    $pierwszy = smsPowitaniaWariant($warianty[0]);

    return $pierwszy['tekst'];
}

/** Wysyła SMS przez bramkę na PBX-ie. Zwraca odpowiedź albo null. */
function smsPowitaniaWyslij($bramka, $numer, $tresc)
{
    $adres = $bramka['url'] . '?' . http_build_query([
        'to' => $numer,
        'text' => $tresc,
        'device' => $bramka['urzadzenie'],
    ]);

    $ch = curl_init($adres);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 15,
        CURLOPT_CONNECTTIMEOUT => 5,
        CURLOPT_USERPWD => $bramka['uzytkownik'] . ':' . $bramka['haslo'],
        CURLOPT_HTTPAUTH => CURLAUTH_BASIC,
    ]);

    $odpowiedz = curl_exec($ch);
    $kod = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $blad = curl_error($ch);
    curl_close($ch);

    if ($odpowiedz === false || $kod !== 200) {
        smsPowitaniaLog("BLAD bramki (HTTP $kod): " . ($blad ?: substr((string) $odpowiedz, 0, 200)));

        return null;
    }

    return $odpowiedz;
}

/**
 * Powitanie albo pożegnanie dla użytkownika VPN-a.
 *
 * @param  string      $user       nazwa profilu VPN, np. „k8-r"
 * @param  string      $zdarzenie  „up" (połączył się) albo „down"
 * @param  int|null    $czas       znacznik czasu - do testów
 * @param  array|null  $nadpisz    nadpisanie konfiguracji - do testów
 * @return string|null wysłana treść albo null, gdy nic nie poszło
 */
function smsPowitanie($user, $zdarzenie, $czas = null, $nadpisz = null)
{
    try {
        $config = smsPowitaniaKonfig();

        if (is_array($nadpisz)) {
            $config = array_replace_recursive($config, $nadpisz);
        }

        if (empty($config['wlaczone']) || !isset($config['osoby'][$user])) {
            return null;
        }

        $osoba = $config['osoby'][$user];
        $czas = $czas === null ? time() : $czas;

        // Cisza po poprzednim SMS-ie: migotanie tunelu nie może zamienić się
        // w serię wiadomości.
        $plikStanu = __DIR__ . '/data/sms_powitania_stan.json';
        $stan = file_exists($plikStanu) ? json_decode(file_get_contents($plikStanu), true) : [];
        if (!is_array($stan)) {
            $stan = [];
        }

        // Jak długo jej nie było: liczone od ostatniego zniknięcia z sieci,
        // a nie od ostatniego SMS-a. Zapisujemy też przy pominiętych wysyłkach,
        // inaczej migotanie tunelu zafałszowałoby długość nieobecności.
        $przerwa = null;

        if ($zdarzenie === 'up' && isset($stan['ostatni_down'][$user])) {
            $przerwa = $czas - (int) $stan['ostatni_down'][$user];
        }

        if ($zdarzenie === 'down') {
            $stan['ostatni_down'][$user] = $czas;
            @file_put_contents($plikStanu, json_encode($stan));
        }

        $odstep = ((int) $config['odstep_minut']) * 60;
        if (isset($stan[$user]) && ($czas - (int) $stan[$user]) < $odstep) {
            smsPowitaniaLog("POMINIETO $user/$zdarzenie - mniej niz {$config['odstep_minut']} min od poprzedniego");

            return null;
        }

        $rodzaj = isset($osoba['rodzaj']) ? $osoba['rodzaj'] : 'router';
        $slot = smsPowitaniaSlot($rodzaj, $zdarzenie, $czas, $config['okna']);

        // Pierwszy raz danego rodzaju - pełna wizytówka („Cerber, asystent
        // LM-Networks"), bo adresatka nie wie jeszcze, kto do niej pisze.
        // Każdy następny raz to samo „Cerber" - powtarzanie całej formułki
        // czterysta razy brzmi jak automat, a nie jak znajomy.
        $przedstawione = isset($stan['przedstawione'][$user]) ? $stan['przedstawione'][$user] : [];
        $pierwszyRaz = !in_array($slot, $przedstawione, true);

        $zrodlo = ($pierwszyRaz && !empty($config['teksty_pierwsze'][$slot]))
            ? $config['teksty_pierwsze'][$slot]
            : (isset($config['teksty'][$slot]) ? $config['teksty'][$slot] : null);

        if (empty($zrodlo)) {
            smsPowitaniaLog("BRAK TEKSTU dla slotu $slot ($user/$zdarzenie)");

            return null;
        }

        // Losowanie z kilku wersji: przy jej regularności to samo zdanie
        // codziennie zamieniłoby niespodziankę w automat. Losujemy jednak
        // wyłącznie z wariantów, które przeszły kontrolę treści.
        $tresc = smsPowitaniaWybierz($zrodlo, $slot, $przerwa);

        $odpowiedz = smsPowitaniaWyslij($config['bramka'], $osoba['numer'], $tresc);

        if ($odpowiedz === null) {
            // Nieudana wysylka nie moze przejsc w ciszy - to jedyny moment,
            // w ktorym cos, co mialo dojsc do niej, nie doszlo.
            smsPowitaniaMeldunek(
                "SMS NIE POSZEDL do {$osoba['numer']} ($user / $slot)\n" . $tresc,
                'warning'
            );

            return null;
        }

        $stan[$user] = $czas;

        if ($pierwszyRaz) {
            $przedstawione[] = $slot;
            $stan['przedstawione'][$user] = $przedstawione;
        }

        @file_put_contents($plikStanu, json_encode($stan));

        smsPowitaniaLog("WYSLANO $user/$zdarzenie [$slot" . ($pierwszyRaz ? '/pierwszy' : '') . "] -> {$osoba['numer']}: $tresc");

        $kierunek = $zdarzenie === 'up' ? 'pojawil sie' : 'zniknal';
        smsPowitaniaMeldunek(
            "SMS wyslany do {$osoba['numer']}\n"
            . "profil: $user ($kierunek), pora: $slot\n\n"
            . $tresc,
            'info'
        );

        return $tresc;
    } catch (Throwable $e) {
        smsPowitaniaLog('WYJATEK: ' . $e->getMessage());

        return null;
    }
}

/*
 * Kontrola całego pliku z ustawieniami - do uruchomienia po ręcznej edycji:
 *
 *     php sms_powitania.php
 *
 * Sprawdza wszystkie warianty i wypisuje, co jest nie tak; kod wyjścia 1, gdy
 * cokolwiek nie przechodzi. Niczego nie wysyła.
 *
 * Blok odpala się WYŁĄCZNIE przy bezpośrednim wywołaniu z linii poleceń -
 * cron dołącza ten plik przez `require_once`, więc nie ma jak tu wpaść.
 */
if (PHP_SAPI === 'cli'
    && isset($_SERVER['argv'][0])
    && realpath($_SERVER['argv'][0]) === realpath(__FILE__)) {

    $config = smsPowitaniaKonfig();
    $bledow = 0;

    echo 'Powitania: ' . (!empty($config['wlaczone']) ? 'WLACZONE' : 'WYLACZONE') . PHP_EOL;

    foreach ($config['osoby'] as $kto => $osoba) {
        echo "  $kto -> {$osoba['numer']} ({$osoba['rodzaj']})" . PHP_EOL;
    }

    foreach (['teksty_pierwsze', 'teksty'] as $zestaw) {
        if (empty($config[$zestaw])) {
            continue;
        }

        echo PHP_EOL . "[$zestaw]" . PHP_EOL;

        foreach ($config[$zestaw] as $slot => $warianty) {
            foreach ($warianty as $surowy) {
                $wariant = smsPowitaniaWariant($surowy);
                $tresc = $wariant['tekst'];
                $problemy = smsPowitaniaSprawdzTresc($tresc);
                $znak = $problemy === [] ? 'OK  ' : 'BLAD';
                $bledow += $problemy === [] ? 0 : 1;

                $warunek = '';
                if ($wariant['min_nieobecnosc_h'] !== null) {
                    $warunek .= ' [tylko gdy nie bylo jej >' . $wariant['min_nieobecnosc_h'] . 'h]';
                }
                if ($wariant['max_nieobecnosc_h'] !== null) {
                    $warunek .= ' [tylko gdy nie bylo jej <' . $wariant['max_nieobecnosc_h'] . 'h]';
                }

                printf("  %s %-15s %3d zn.  %s%s%s" . PHP_EOL,
                    $znak, $slot, strlen($tresc), $tresc, $warunek,
                    $problemy === [] ? '' : '  <-- ' . implode('; ', $problemy));
            }
        }
    }

    echo PHP_EOL . ($bledow === 0 ? 'Wszystko w porzadku.' : "Do poprawy: $bledow") . PHP_EOL;

    exit($bledow === 0 ? 0 : 1);
}
