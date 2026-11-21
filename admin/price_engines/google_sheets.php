<?php
// system/modules/Elpriser/admin/price_engines/google_sheets.php
//
// @navn         Google Sheets (CSV)
// @funktion     elpriser_google_sheets_hent_dag
// @beskrivelse  Henter færdige priser fra et Google-ark, der er udgivet som CSV. Se guiden til arket.
//
// Arkets kolonner (se guiden): B = dato, W = gennemsnitspris, X = højeste, Y = laveste, Z = spotpris.
// Priserne i arket er de samlede priser, så modulet lægger ikke afgifter og moms på igen.
// Rækker, hvor kolonne B ikke er en dato, springes over (overskrifter og tomme linjer).

if (!function_exists('elpriser_google_sheets_hent_dag')) {

    /**
     * Datoer på formerne ÅÅÅÅ-MM-DD, DD-MM-ÅÅÅÅ, DD/MM/ÅÅÅÅ og DD.MM.ÅÅÅÅ.
     */
    function elpriser_google_sheets_date(string $s): ?string
    {
        $s = trim($s);
        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})/', $s, $m)) {
            [$y, $mo, $d] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } elseif (preg_match('/^(\d{1,2})[-\/.](\d{1,2})[-\/.](\d{4})/', $s, $m)) {
            [$d, $mo, $y] = [(int)$m[1], (int)$m[2], (int)$m[3]];
        } else {
            return null;
        }
        return checkdate($mo, $d, $y) ? sprintf('%04d-%02d-%02d', $y, $mo, $d) : null;
    }

    /**
     * Tal fra et ark: "2,45", "2.45" eller "1.234,56".
     */
    function elpriser_google_sheets_num(string $s): float
    {
        $s = trim(str_replace(["\xc2\xa0", ' ', 'kr', 'KR', 'Kr'], '', $s));
        if ($s === '') {
            return 0.0;
        }
        if (strpos($s, ',') !== false) {
            $s = str_replace('.', '', $s);
            $s = str_replace(',', '.', $s);
        }
        return is_numeric($s) ? (float)$s : 0.0;
    }

    function elpriser_google_sheets_hent_dag(PDO $pdo, string $dato, string $region, ?callable $fetcher = null): array
    {
        $url = trim(elpriser_setting($pdo, 'sheets_url', ''));
        if ($url === '') {
            return ['ok' => false, 'message' => 'Ingen Google Sheets URL angivet under indstillinger.'];
        }
        // Adressen "pubhtml" giver en webside. Vi skal bruge CSV.
        $url = str_replace('pubhtml', 'pub?output=csv', $url);
        if (!elpriser_valid_https_url($url)) {
            return ['ok' => false, 'message' => 'Adressen til arket skal begynde med https://'];
        }

        // Arket gemmes et par minutter, så det ikke hentes igen for hver dag
        [$ok, $body] = elpriser_cached_get($url, 120, $fetcher);
        if (!$ok) {
            return ['ok' => false, 'message' => 'Kunne ikke hente arket: ' . $body];
        }

        $lines = preg_split('/\r\n|\n|\r/', $body);
        $first = implode("\n", array_slice($lines, 0, 5));
        $delim = substr_count($first, ';') > substr_count($first, ',') ? ';' : ',';

        foreach ($lines as $line) {
            if (trim($line) === '') {
                continue;
            }
            $row = str_getcsv($line, $delim);
            if (elpriser_google_sheets_date($row[1] ?? '') !== $dato) {
                continue;
            }
            $gns = elpriser_google_sheets_num($row[22] ?? '');
            $max = elpriser_google_sheets_num($row[23] ?? '');
            $min = elpriser_google_sheets_num($row[24] ?? '');
            $spot = elpriser_google_sheets_num($row[25] ?? '');
            if ($gns <= 0) {
                return ['ok' => false, 'message' => 'Arket har ingen pris for ' . $dato . ' i kolonne W.'];
            }
            if ($spot <= 0) {
                $spot = $gns / (1 + elpriser_vat($pdo)); // spotprisen er ikke angivet i arket
            }
            elpriser_save_day_totals($pdo, $dato, $region, $gns, $max > 0 ? $max : $gns, $min > 0 ? $min : $gns, $spot);
            return ['ok' => true, 'message' => 'OK'];
        }
        return ['ok' => false, 'message' => 'Datoen ' . $dato . ' findes ikke i arket.'];
    }
}
