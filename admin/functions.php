<?php
// system/modules/Elpriser/admin/functions.php
// Læser mappen price_engines og kører kilderne.
//
// Hver kilde er en fil i price_engines/. Øverst i filen står en lille header, som siden læser UDEN at køre filen:
//
//   // @navn      Energi Data Service (Energinet)     (vises i menuen)
//   // @land      DK
//   // @regioner  DK1=Vestdanmark, DK2=Østdanmark     (kode=navn, kommasepareret)
//   // @valuta    DKK
//   // @funktion  elpriser_energinet_hent_dag         (funktionen, der henter én dag)
//   // @beskrivelse  Kort tekst om kilden
//
// Funktionen får ($pdo, $dato, $region, $fetcher) og giver ['ok' => bool, 'message' => tekst].
// Filer, hvis navn begynder med _, er skabeloner og vises ikke. Se _skabelon.php.

require_once __DIR__ . '/../functions.php';

/**
 * Alle kilder i price_engines/: [id => ['id','navn','land','regioner','valuta','funktion','beskrivelse']].
 */
function elpriser_engines(): array
{
    $out = [];
    foreach ((array)glob(__DIR__ . '/price_engines/*.php') as $file) {
        $id = basename($file, '.php');
        if (!preg_match('/^[A-Za-z][A-Za-z0-9_]*$/', $id)) {
            continue;
        }
        $head = (string)file_get_contents($file, false, null, 0, 4000);
        $meta = [];
        foreach (['navn', 'land', 'regioner', 'valuta', 'funktion', 'beskrivelse'] as $tag) {
            if (preg_match('/^[ \t]*(?:\/\/|\*|#)[ \t]*@' . $tag . '[ \t]+(.+?)[ \t]*$/mi', $head, $m)) {
                $meta[$tag] = trim($m[1]);
            }
        }
        // En kilde skal have et navn og en gyldig funktion
        if (empty($meta['navn']) || empty($meta['funktion']) || !preg_match('/^elpriser_[a-z0-9_]+$/', $meta['funktion'])) {
            continue;
        }
        $regions = [];
        foreach (explode(',', $meta['regioner'] ?? '') as $part) {
            if (preg_match('/^\s*([A-Za-z0-9-]{2,10})\s*(?:=\s*(.+?))?\s*$/', $part, $m)) {
                $regions[strtoupper($m[1])] = $m[2] ?? strtoupper($m[1]);
            }
        }
        $out[$id] = [
            'id'          => $id,
            'navn'        => $meta['navn'],
            'land'        => strtoupper(substr($meta['land'] ?? '', 0, 2)),
            'regioner'    => $regions,
            'valuta'      => strtoupper(substr($meta['valuta'] ?? '', 0, 3)),
            'funktion'    => $meta['funktion'],
            'beskrivelse' => $meta['beskrivelse'] ?? '',
        ];
    }
    uasort($out, function ($a, $b) {
        return strcasecmp($a['navn'], $b['navn']);
    });
    return $out;
}

/**
 * Finder en kilde ud fra en gemt værdi. Ældre værdier som "Google Sheets" og "google_sheets" matches også.
 */
function elpriser_resolve_engine(string $value, array $engines): ?string
{
    if (isset($engines[$value])) {
        return $value;
    }
    $norm = strtolower(str_replace([' ', '-'], '_', trim($value)));
    foreach ($engines as $id => $e) {
        if (strtolower($id) === $norm) {
            return $id;
        }
    }
    return null;
}

/**
 * Den kilde, der er valgt under indstillinger (eller den første på listen).
 */
function elpriser_current_engine(PDO $pdo, array $engines): ?string
{
    $id = elpriser_resolve_engine(elpriser_setting($pdo, 'fetch_method', ''), $engines);
    if ($id === null && $engines) {
        $id = array_key_first($engines);
    }
    return $id;
}

/**
 * Alle regioner fra alle kilder: [kode => navn].
 */
function elpriser_all_regions(array $engines): array
{
    $out = [];
    foreach ($engines as $e) {
        foreach ($e['regioner'] as $code => $name) {
            $out[$code] = $name;
        }
    }
    return $out;
}

/**
 * Kører én kilde for én dag. Kilden skal findes i price_engines/, og regionen skal høre til kilden.
 * Giver ['ok' => bool, 'message' => tekst].
 */
function elpriser_run_engine_day(PDO $pdo, string $id, string $dato, string $region, ?callable $fetcher = null): array
{
    $engines = elpriser_engines();
    $real = elpriser_resolve_engine($id, $engines);
    if ($real === null) {
        return ['ok' => false, 'message' => 'Ukendt kilde.'];
    }
    if (!elpriser_valid_date($dato)) {
        return ['ok' => false, 'message' => 'Ugyldig dato.'];
    }
    $e = $engines[$real];
    if ($e['regioner'] && !isset($e['regioner'][$region])) {
        return ['ok' => false, 'message' => 'Regionen ' . $region . ' hører ikke til kilden ' . $e['navn'] . '.'];
    }
    require_once __DIR__ . '/price_engines/' . $real . '.php';
    if (!function_exists($e['funktion'])) {
        return ['ok' => false, 'message' => 'Kilden mangler funktionen ' . $e['funktion'] . '.'];
    }
    try {
        $res = $e['funktion']($pdo, $dato, $region, $fetcher);
    } catch (Throwable $t) {
        error_log('Elpriser: kilden ' . $real . ' fejlede: ' . $t->getMessage());
        return ['ok' => false, 'message' => 'Kilden fejlede.'];
    }
    return ['ok' => !empty($res['ok']), 'message' => (string)($res['message'] ?? '')];
}

/**
 * Henter de dage, der mangler, med den valgte kilde (højst $maxDays dage, og højst $timeBudget sekunder).
 * Stopper ved første fejl. Giver ['ok' => bool, 'added' => antal, 'message' => tekst].
 */
function elpriser_sync_missing(PDO $pdo, int $maxDays = 30, ?callable $fetcher = null, int $timeBudget = 0): array
{
    $engines = elpriser_engines();
    $id = elpriser_current_engine($pdo, $engines);
    if ($id === null) {
        return ['ok' => false, 'added' => 0, 'message' => 'Der er ingen kilder i price_engines.'];
    }
    $region = strtoupper(elpriser_setting($pdo, 'region', 'DK1'));
    $dates  = elpriser_missing_dates($pdo, $region, $maxDays);
    $added  = 0;
    $started = microtime(true);

    foreach ($dates as $d) {
        if ($timeBudget > 0 && (microtime(true) - $started) > $timeBudget) {
            break;
        }
        $res = elpriser_run_engine_day($pdo, $id, $d, $region, $fetcher);
        if (!$res['ok']) {
            return ['ok' => false, 'added' => $added, 'message' => $d . ': ' . $res['message']];
        }
        $added++;
    }
    return ['ok' => true, 'added' => $added, 'message' => $added === 0 ? 'Alt er allerede hentet.' : 'Tilføjede ' . $added . ' nye dage.'];
}
