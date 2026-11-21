<?php
// system/modules/Elpriser/functions.php
// Fælles funktioner for El-priser: indstillinger, regnemåden, hentning fra internettet og datoer.
// Alle navne starter med elpriser_, så de ikke støder ind i andre moduler.

/**
 * Læser en indstilling (gemt som el_<navn> i site_settings).
 */
function elpriser_setting(PDO $pdo, string $key, string $default = ''): string
{
    $keys = ['el_' . $key];
    if ($key === 'auto_fetch') {
        $keys[] = 'elpriser_auto_fetch'; // det ældre navn
    }
    $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ? LIMIT 1");
    foreach ($keys as $k) {
        $stmt->execute([$k]);
        $val = $stmt->fetchColumn();
        if ($val !== false && $val !== null && $val !== '') {
            return (string)$val;
        }
    }
    return $default;
}

/**
 * Gemmer en indstilling.
 */
function elpriser_save_setting(PDO $pdo, string $key, string $value): void
{
    $stmt = $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (:k, :v) ON DUPLICATE KEY UPDATE setting_value = :v");
    $stmt->execute([':k' => 'el_' . $key, ':v' => $value]);
}

/**
 * Er teksten en rigtig dato på formen ÅÅÅÅ-MM-DD?
 */
function elpriser_valid_date(string $s): bool
{
    return (bool)preg_match('/^(\d{4})-(\d{2})-(\d{2})$/', $s, $m) && checkdate((int)$m[2], (int)$m[3], (int)$m[1]);
}

/**
 * Er adressen en https-adresse uden mellemrum? Kun https accepteres.
 */
function elpriser_valid_https_url(string $url): bool
{
    return (bool)preg_match('#^https://[A-Za-z0-9.-]+(:[0-9]{1,5})?(/[^\s]*)?$#', $url);
}

/**
 * Startdatoen for hentning: den, der er valgt under indstillinger, ellers 30 dage tilbage.
 */
function elpriser_start_date(PDO $pdo): string
{
    $s = elpriser_setting($pdo, 'start_date', '');
    return elpriser_valid_date($s) ? $s : date('Y-m-d', strtotime('-30 days'));
}

/**
 * De dage fra startdatoen til i dag, der mangler en pris (højst $limit).
 */
function elpriser_missing_dates(PDO $pdo, string $region, int $limit): array
{
    $limit = max(1, min(1000, $limit));
    $stmt = $pdo->prepare("SELECT dato FROM electricity_prices WHERE region = ? AND spot_price_raw > 0");
    $stmt->execute([$region]);
    $existing = array_flip($stmt->fetchAll(PDO::FETCH_COLUMN));

    $missing = [];
    $check = elpriser_start_date($pdo);
    $today = date('Y-m-d');
    while ($check <= $today && count($missing) < $limit) {
        if (!isset($existing[$check])) {
            $missing[] = $check;
        }
        $check = date('Y-m-d', strtotime($check . ' +1 day'));
    }
    return $missing;
}

/**
 * Afgifterne, der gælder på en given dato: den nyeste række med start_dato før eller på datoen.
 * Findes ingen, er alle nul (så er prisen kun spotprisen med moms).
 */
function elpriser_rate_for(PDO $pdo, string $date): array
{
    $stmt = $pdo->prepare("SELECT elafgift, transport_samlet, abonnement_kwh FROM electricity_rates_history WHERE start_dato <= ? ORDER BY start_dato DESC LIMIT 1");
    $stmt->execute([$date]);
    $row = $stmt->fetch(PDO::FETCH_ASSOC);
    if (!$row) {
        return ['elafgift' => 0.0, 'transport_samlet' => 0.0, 'abonnement_kwh' => 0.0];
    }
    return array_map('floatval', $row);
}

/**
 * Momssats som tal (25 % giver 0.25).
 */
function elpriser_vat(PDO $pdo): float
{
    $p = (float)str_replace(',', '.', elpriser_setting($pdo, 'vat_percent', '25'));
    return max(0.0, min(100.0, $p)) / 100;
}

/**
 * Den ENE regnemåde: samlet pris pr. kWh = (spotpris + elafgift + transport + abonnement) * (1 + moms).
 */
function elpriser_total(float $spot, array $rate, float $vat): float
{
    return ($spot + $rate['elafgift'] + $rate['transport_samlet'] + $rate['abonnement_kwh']) * (1 + $vat);
}

/**
 * Gemmer en dag ud fra spotpriserne (kr/kWh uden afgifter og moms).
 * Samme regnemåde, uanset hvilken kilde dagen kommer fra.
 */
function elpriser_save_day(PDO $pdo, string $date, string $region, float $spotAvg, float $spotMax, float $spotMin): void
{
    $rate = elpriser_rate_for($pdo, $date);
    $vat  = elpriser_vat($pdo);
    elpriser_save_day_totals($pdo, $date, $region,
        elpriser_total($spotAvg, $rate, $vat), elpriser_total($spotMax, $rate, $vat), elpriser_total($spotMin, $rate, $vat), $spotAvg);
}

/**
 * Gemmer en dag, hvor kilden allerede giver de samlede priser (fx et ark, der har regnet afgifter på).
 */
function elpriser_save_day_totals(PDO $pdo, string $date, string $region, float $gns, float $max, float $min, float $spot): void
{
    $rate = elpriser_rate_for($pdo, $date);
    $stmt = $pdo->prepare("
        INSERT INTO electricity_prices (dato, region, price_gns, price_max, price_min, spot_price_raw, nettarif, afgift, abonnement_kwh)
        VALUES (:d, :r, :g, :x, :n, :s, :t, :a, :b)
        ON DUPLICATE KEY UPDATE
            price_gns = VALUES(price_gns), price_max = VALUES(price_max), price_min = VALUES(price_min),
            spot_price_raw = VALUES(spot_price_raw), nettarif = VALUES(nettarif),
            afgift = VALUES(afgift), abonnement_kwh = VALUES(abonnement_kwh)
    ");
    $stmt->execute([
        ':d' => $date, ':r' => $region,
        ':g' => $gns, ':x' => $max, ':n' => $min, ':s' => $spot,
        ':t' => $rate['transport_samlet'], ':a' => $rate['elafgift'], ':b' => $rate['abonnement_kwh'],
    ]);
}

/**
 * Henter en https-adresse. Certifikatet kontrolleres altid, og kun https er tilladt.
 * Giver [ok, indhold eller en fejlbesked, der forklarer, hvad der mangler].
 */
function elpriser_http_get(string $url, int $timeout = 20): array
{
    if (!elpriser_valid_https_url($url)) {
        return [false, 'Adressen skal begynde med https://'];
    }

    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_CONNECTTIMEOUT => 8,
            CURLOPT_FOLLOWLOCATION => true,
            CURLOPT_MAXREDIRS      => 5,
            CURLOPT_PROTOCOLS      => CURLPROTO_HTTPS,
            CURLOPT_REDIR_PROTOCOLS => CURLPROTO_HTTPS,
            CURLOPT_SSL_VERIFYPEER => true,
            CURLOPT_SSL_VERIFYHOST => 2,
            CURLOPT_MAXFILESIZE    => 5 * 1024 * 1024,
            CURLOPT_USERAGENT      => 'Domusius-Elpriser/1.0',
        ]);
        $body = curl_exec($ch);
        $err  = curl_error($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false) {
            return [false, 'Forbindelsen fejlede (cURL): ' . ($err !== '' ? $err : 'ukendt fejl')];
        }
        if ($code < 200 || $code >= 300) {
            return [false, 'Serveren svarede med status ' . $code . '.'];
        }
        return [true, (string)$body];
    }

    // Uden cURL bruges PHP's egen hentning. Den kræver allow_url_fopen og OpenSSL.
    if (!ini_get('allow_url_fopen')) {
        return [false, 'cURL-udvidelsen er ikke slået til i PHP, og allow_url_fopen er slået fra, så serveren kan ikke hente fra internettet.'];
    }
    if (!extension_loaded('openssl')) {
        return [false, 'cURL og OpenSSL mangler i PHP, så serveren kan ikke bruge https.'];
    }
    $ctx = stream_context_create([
        'http' => ['timeout' => $timeout, 'user_agent' => 'Domusius-Elpriser/1.0', 'follow_location' => 1, 'max_redirects' => 5],
        'ssl'  => ['verify_peer' => true, 'verify_peer_name' => true],
    ]);
    // PHP melder en fejl i flere trin (fx først certifikatet, så at forbindelsen ikke kunne åbnes). Vi samler dem.
    $errors = [];
    set_error_handler(function ($no, $str) use (&$errors) {
        $errors[] = preg_replace('/^file_get_contents\([^)]*\):\s*/', '', $str);
        return true;
    });
    $body = file_get_contents($url, false, $ctx);
    restore_error_handler();
    if ($body === false) {
        $why = $errors ? substr(preg_replace('/\s+/', ' ', implode(' ', array_slice($errors, 0, 3))), 0, 400) : 'ukendt fejl';
        return [false, 'Forbindelsen fejlede (PHP): ' . $why];
    }
    return [true, $body];
}

/**
 * Som elpriser_http_get, men gemmer svaret i et par minutter, så et ark ikke hentes igen for hver dag.
 */
function elpriser_cached_get(string $url, int $ttl = 120, ?callable $fetcher = null): array
{
    $fetch = $fetcher ?? 'elpriser_http_get';
    $file  = sys_get_temp_dir() . '/elpriser_' . md5($url) . '.cache';
    if (is_file($file) && (time() - filemtime($file)) < $ttl) {
        $body = file_get_contents($file);
        if ($body !== false) {
            return [true, $body];
        }
    }
    [$ok, $body] = $fetch($url);
    if ($ok) {
        @file_put_contents($file, $body);
    }
    return [$ok, $body];
}

/**
 * Tester, om serveren kan hente fra internettet, og siger hvorfor ikke, hvis den ikke kan.
 * Giver ['rows' => [[navn, værdi, ok], ...], 'hint' => tekst].
 */
function elpriser_connection_report(string $testUrl = 'https://api.energidataservice.dk/dataset/DayAheadPrices?limit=1'): array
{
    $curl    = function_exists('curl_init');
    $cv      = $curl ? curl_version() : [];
    $openssl = extension_loaded('openssl');
    $fopen   = (bool)ini_get('allow_url_fopen');

    $rows = [
        ['PHP-version', PHP_VERSION, true],
        ['cURL', $curl ? 'Til (version ' . ($cv['version'] ?? '?') . ')' : 'Mangler', $curl],
        ['OpenSSL', $openssl ? 'Til' : 'Mangler', $openssl],
        ['allow_url_fopen', $fopen ? 'Til' : 'Fra', $fopen],
    ];

    $started = microtime(true);
    [$ok, $body] = elpriser_http_get($testUrl, 15);
    $ms = (int)round((microtime(true) - $started) * 1000);
    if ($ok && strpos((string)$body, '"records"') === false) {
        $ok = false;
        $body = 'Svaret lignede ikke data fra Energi Data Service.';
    }
    $rows[] = ['Forbindelse til Energi Data Service', $ok ? 'OK (' . $ms . ' ms)' : 'Fejlede: ' . $body, $ok];

    $hint = '';
    if (!$ok) {
        $b = (string)$body;
        if (!$curl && !$fopen) {
            $hint = 'Slå cURL-udvidelsen til i PHP-profilen. På en Synology: Web Station, Skriptsprog, PHP, rediger profilen og vælg fanen Udvidelser. Menunavnene kan afvige lidt.';
        } elseif (!$curl && !$openssl) {
            $hint = 'Slå OpenSSL-udvidelsen (eller cURL) til i PHP-profilen, så serveren kan bruge https.';
        } elseif (stripos($b, 'certificate') !== false || stripos($b, 'SSL') !== false) {
            $hint = 'Serveren kan ikke kontrollere certifikatet. Peg curl.cainfo og openssl.cafile i PHP-indstillingerne på en CA-fil. Slå ikke certifikatkontrollen fra.';
        } elseif (stripos($b, 'resolve') !== false || stripos($b, 'getaddrinfo') !== false) {
            $hint = 'Serveren kan ikke slå internetadresser op (DNS). Tjek serverens netværks- og DNS-indstillinger.';
        } elseif (stripos($b, 'timed out') !== false || stripos($b, 'timeout') !== false) {
            $hint = 'Forbindelsen timede ud. Tjek, om serveren har adgang til internettet, og om en firewall blokerer udgående forbindelser.';
        } else {
            $hint = 'Læs fejlen ovenfor. Kan serveren ellers hente fra internettet, så prøv igen senere.';
        }
    }
    return ['rows' => $rows, 'hint' => $hint];
}
