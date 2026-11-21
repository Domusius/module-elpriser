<?php
// system/modules/Elpriser/admin/price_engines/_skabelon.php
//
// SKABELON til en ny kilde. Filer, hvis navn begynder med _, vises ikke i menuen.
//
// Sådan laver du en ny kilde (fx for et andet land):
// 1. Kopiér filen, og kald kopien fx Tyskland.php (bogstaver, tal og _, må ikke begynde med _).
// 2. Ret headeren herunder. Menuen læser navnet, og siden bruger funktionen og regionerne.
// 3. Omdøb funktionen nederst, så den passer til @funktion. Navnet skal begynde med elpriser_ og være unikt.
// 4. Hent priserne for ÉN dag ($dato, format ÅÅÅÅ-MM-DD) som pris pr. kWh UDEN afgifter og moms, og kald
//    elpriser_save_day($pdo, $dato, $region, $gennemsnit, $hoejeste, $laveste).
//    Har din kilde allerede de samlede priser, så brug elpriser_save_day_totals().
// 5. Brug $fetcher, hvis den er sat, ellers elpriser_http_get($url). Kun https er tilladt.
// 6. Giv ['ok' => true, 'message' => 'OK'] ved succes og ['ok' => false, 'message' => 'forklaring'] ved fejl.
//
// @navn         Min kilde
// @land         XX
// @regioner     XX1=Region 1, XX2=Region 2
// @valuta       EUR
// @funktion     elpriser_minkilde_hent_dag
// @beskrivelse  Kort tekst om, hvor priserne kommer fra.

if (!function_exists('elpriser_minkilde_hent_dag')) {
    function elpriser_minkilde_hent_dag(PDO $pdo, string $dato, string $region, ?callable $fetcher = null): array
    {
        $fetch = $fetcher ?? 'elpriser_http_get';

        // [$ok, $body] = $fetch('https://eksempel.dk/priser?dato=' . urlencode($dato));
        // ... læs $body, og find gennemsnit, højeste og laveste pris for dagen ...
        // elpriser_save_day($pdo, $dato, $region, $gennemsnit, $hoejeste, $laveste);
        // return ['ok' => true, 'message' => 'OK'];

        return ['ok' => false, 'message' => 'Skabelonen henter ikke noget.'];
    }
}
