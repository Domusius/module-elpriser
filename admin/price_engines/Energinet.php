<?php
// system/modules/Elpriser/admin/price_engines/Energinet.php
//
// @navn         Energi Data Service (Energinet)
// @land         DK
// @regioner     DK1=Vest, DK2=Øst
// @valuta       DKK
// @funktion     elpriser_energinet_hent_dag
// @beskrivelse  Åbne spotpriser fra Energinet. Ingen nøgle. Afgifter og moms lægges på af modulet.
//
// Se vilkårene for brug af data på energidataservice.dk.
//
// Datasættet Elspotprices fik ingen nye priser efter 2025-09-30. Fra 2025-10-01 bruges DayAheadPrices
// (felter: TimeUTC, TimeDK, PriceArea, DayAheadPriceDKK i kr pr. MWh). Ældre dage hentes stadig fra Elspotprices.
// Priserne grupperes pr. time (også når kilden har kvarterspriser), så gennemsnit, højeste og laveste
// er sammenlignelige med de ældre data.

if (!function_exists('elpriser_energinet_hent_dag')) {

    /**
     * Henter én dag, regner timegennemsnit, højeste og laveste og gemmer dagen.
     * Giver ['ok' => bool, 'message' => tekst].
     */
    function elpriser_energinet_hent_dag(PDO $pdo, string $dato, string $region, ?callable $fetcher = null): array
    {
        $fetch = $fetcher ?? 'elpriser_http_get';

        if ($dato <= '2025-09-30') {
            [$dataset, $timeField, $utcField, $priceField] = ['Elspotprices', 'HourDK', 'HourUTC', 'SpotPriceDKK'];
        } else {
            [$dataset, $timeField, $utcField, $priceField] = ['DayAheadPrices', 'TimeDK', 'TimeUTC', 'DayAheadPriceDKK'];
        }

        $url = 'https://api.energidataservice.dk/dataset/' . $dataset . '?' . http_build_query([
            // Bredere end nødvendigt, så tidszoneforskelle ikke koster en dag. Dagen sorteres lokalt.
            'start'  => date('Y-m-d', strtotime($dato . ' -1 day')),
            'end'    => date('Y-m-d', strtotime($dato . ' +2 days')),
            'filter' => json_encode(['PriceArea' => [$region]]),
            'sort'   => $timeField . ' ASC',
            'limit'  => 1000,
        ]);

        [$ok, $body] = $fetch($url);
        if (!$ok) {
            return ['ok' => false, 'message' => 'Kunne ikke hente fra Energi Data Service: ' . $body];
        }
        $json = json_decode($body, true);
        if (!is_array($json) || !isset($json['records']) || !is_array($json['records'])) {
            return ['ok' => false, 'message' => 'Uventet svar fra Energi Data Service.'];
        }

        // timer (UTC) for den ønskede dag (dansk tid) => priser i kr/kWh
        $hours = [];
        foreach ($json['records'] as $rec) {
            $local = (string)($rec[$timeField] ?? '');
            $utc   = (string)($rec[$utcField] ?? $local);
            $price = $rec[$priceField] ?? null;
            if (!preg_match('/^(\d{4}-\d{2}-\d{2})T\d{2}:/', $local, $m) || $m[1] !== $dato || $price === null || !is_numeric($price)) {
                continue;
            }
            $hours[substr($utc, 0, 13)][] = (float)$price / 1000.0;
        }

        // En dag skal være (næsten) komplet: 23-25 timer
        if (count($hours) < 23) {
            return ['ok' => false, 'message' => 'Priserne for ' . $dato . ' er ikke komplette endnu (' . count($hours) . ' timer).'];
        }

        $hourly = [];
        foreach ($hours as $values) {
            $hourly[] = array_sum($values) / count($values);
        }
        elpriser_save_day($pdo, $dato, $region, array_sum($hourly) / count($hourly), max($hourly), min($hourly));
        return ['ok' => true, 'message' => 'OK'];
    }
}
