<?php
/**
- system/modules/Elpriser/admin/settings.php
 */
if (!isset($user) || $user['role'] !== 'admin') {
    exit('Adgang nægtet');
}

// Sikkerhedstoken (CSRF). Kernen kan allerede have indlæst det.
if (!function_exists('csrf_token')) {
    require_once __DIR__ . '/../../../../inc/csrf.php';
}
require_once __DIR__ . '/functions.php';

$current_module_key = 'Elpriser';
$modDir = rawurlencode(basename(dirname(__DIR__))); // mappens rigtige navn, til links 
$status_msg = "";

// 1. Læs tilgængelige widgets til layout-bjælken
$widgets_folder = __DIR__ . '/../widgets/';
$available_widgets = [];
if (file_exists($widgets_folder . 'widgets.json')) {
    $json_content = file_get_contents($widgets_folder . 'widgets.json');
    $data = json_decode($json_content, true);
    $available_widgets = $data['widgets'] ?? [];
}

// 2. POST Håndtering
if ($_SERVER['REQUEST_METHOD'] === 'POST' && !csrf_check($_POST['csrf'] ?? '')) {
    $status_msg = '<i class="fas fa-exclamation-triangle"></i> Handlingen blev afvist, fordi sikkerhedstokenet manglede eller var forkert. Prøv igen fra denne side.';
} elseif ($_SERVER['REQUEST_METHOD'] === 'POST') {
    
    // Gem Layout
    if (isset($_POST['save_dashboard_toggle'])) {
        $newState  = isset($_POST['show_on_dashboard']) ? 1 : 0;
        $newWidget = $_POST['dashboard_widget_type'] ?? '';
        if (!in_array($newWidget, array_column($available_widgets, 'file'), true)) {
            $newWidget = '';
        }
        $newSize   = $_POST['dashboard_size'] ?? 'medium';
        if (!in_array($newSize, ['small', 'medium', 'full'], true)) {
            $newSize = 'medium';
        }
        $newOrder  = (int)($_POST['dashboard_order'] ?? 0);

        $stmt = $pdo->prepare("UPDATE modules SET show_on_dashboard = ?, dashboard_widget_type = ?, dashboard_size = ?, dashboard_order = ? WHERE module_key = ?");
        $stmt->execute([$newState, $newWidget, $newSize, $newOrder, $current_module_key]);
        $status_msg = '<i class="fas fa-check-circle"></i> Layout opdateret!';
    }

    // Gem Forbindelse (Metode, URL / Startdato, Region)
    if (isset($_POST['save_el_settings'])) {
        $engines_now = elpriser_engines();
        $metode    = elpriser_resolve_engine((string)($_POST['el_fetch_method'] ?? ''), $engines_now);
        $url       = trim((string)($_POST['el_sheets_url'] ?? ''));
        $startDate = trim((string)($_POST['el_start_date'] ?? ''));
        $reg       = strtoupper(trim((string)($_POST['el_region'] ?? '')));
        $auto      = isset($_POST['elpriser_auto_fetch']) ? '1' : '0';
        $known_regions = elpriser_all_regions($engines_now);

        if ($metode === null) {
            $status_msg = '<i class="fas fa-times-circle"></i> Fejl: Vælg en af kilderne på listen.';
        } elseif ($metode === 'google_sheets' && $url !== '' && !elpriser_valid_https_url(str_replace('pubhtml', 'pub?output=csv', $url))) {
            $status_msg = '<i class="fas fa-times-circle"></i> Fejl: Linket til arket skal begynde med https:// og må ikke indeholde mellemrum.';
        } elseif ($metode !== 'google_sheets' && $startDate !== '' && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $startDate)) {
            $status_msg = '<i class="fas fa-times-circle"></i> Fejl: Vælg en gyldig startdato.';
        } elseif (!preg_match('/^[A-Z0-9-]{2,10}$/', $reg) || ($known_regions && !isset($known_regions[$reg]))) {
            $status_msg = '<i class="fas fa-times-circle"></i> Fejl: Vælg en af regionerne på listen.';
        } elseif ($engines_now[$metode]['regioner'] && !isset($engines_now[$metode]['regioner'][$reg])) {
            $status_msg = '<i class="fas fa-times-circle"></i> Fejl: Regionen hører ikke til den valgte kilde.';
        } else {
            elpriser_save_setting($pdo, 'fetch_method', $metode);
            if ($metode === 'google_sheets') {
                elpriser_save_setting($pdo, 'sheets_url', $url);
            } else {
                elpriser_save_setting($pdo, 'start_date', $startDate);
            }
            elpriser_save_setting($pdo, 'region', $reg);
            elpriser_save_setting($pdo, 'auto_fetch', $auto);
            $status_msg = '<i class="fas fa-check-circle"></i> Indstillinger gemt!';
        }
    }

    // MANUEL SYNKRONISERING (Bruger nu den dynamiske motor-funktion)
    if (isset($_POST['sync_manual'])) {
        @set_time_limit(120);
        $result = elpriser_sync_missing($pdo, 30, null, 90);
        if ($result['ok']) {
            $status_msg = '<i class="fas fa-check-circle"></i> Synkronisering gennemført! ' . htmlspecialchars($result['message']);
        } else {
            $status_msg = '<i class="fas fa-times-circle"></i> Fejl: ' . htmlspecialchars($result['message']);
        }
    }
}

// 3. DATA HENTNING
$modStmt = $pdo->prepare("SELECT show_on_dashboard, dashboard_widget_type, dashboard_size, dashboard_order FROM modules WHERE module_key = ?");
$modStmt->execute([$current_module_key]);
$module = $modStmt->fetch();

$showOnDash    = $module['show_on_dashboard'] ?? 0;
$currentWidget = $module['dashboard_widget_type'] ?? '';
$dashSize      = $module['dashboard_size'] ?? 'medium';
$dashOrder     = $module['dashboard_order'] ?? 0;

$engines    = elpriser_engines();
$eMetode    = elpriser_current_engine($pdo, $engines) ?? '';
$eSheet     = elpriser_setting($pdo, 'sheets_url', '');
$eStartDate = elpriser_setting($pdo, 'start_date', '');
$eRegion    = strtoupper(elpriser_setting($pdo, 'region', 'DK1'));
$autoFetch  = elpriser_setting($pdo, 'auto_fetch', '0');
$allRegions = elpriser_all_regions($engines);
if (!isset($allRegions[$eRegion])) {
    $allRegions[$eRegion] = $eRegion; // en gemt region, som ingen kilde kender, vises stadig
}

// Find seneste dato i DB
$stmtMax = $pdo->prepare("SELECT MAX(dato) FROM electricity_prices WHERE region = ?");
$stmtMax->execute([$eRegion]);
$lastDate = $stmtMax->fetchColumn();

$isSheets = ($eMetode === 'google_sheets');
?>

<div style="padding: 20px; font-family: 'Segoe UI', system-ui, sans-serif;">

    <?php if($status_msg): ?>
        <div style="background: #dcfce7; color: #166534; padding: 15px; border-radius: 10px; margin-bottom: 20px; border: 1px solid #bbf7d0; font-weight: 600;">
            <?= $status_msg ?>
        </div>
    <?php endif; ?>

    <div style="display: flex; align-items: center; gap: 12px; margin-bottom: 20px;">
        <i class="fas fa-bolt" style="color: #ecc94b; font-size: 1.4em;"></i>
        <h3 style="margin:0; font-size: 1.25em; font-weight: 700; color: #1e293b;">El-pris Administration</h3>
    </div>

    <div style="background: white; padding: 15px 20px; border-radius: 12px; border: 1px solid #e2e8f0; margin-bottom: 25px; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <form method="post" style="display: flex; align-items: center; justify-content: space-between; gap: 15px;">
            <?= csrf_field() ?>
            <div style="display: flex; align-items: center; gap: 12px;">
                <input type="checkbox" name="show_on_dashboard" value="1" <?= $showOnDash ? 'checked' : '' ?> style="width: 18px; height: 18px;">
                <span style="font-weight: 700; color: #334155;">Vis på dashboard</span>
                <span style="background: <?= $showOnDash ? '#dcfce7' : '#fee2e2' ?>; color: <?= $showOnDash ? '#166534' : '#991b1b' ?>; padding: 2px 8px; border-radius: 4px; font-size: 10px; font-weight: 800;">
                    <?= $showOnDash ? 'SYNLIG' : 'SKJULT' ?>
                </span>
            </div>

            <div style="display: flex; align-items: center; gap: 15px;">
                <select name="dashboard_widget_type" style="padding: 6px 10px; border-radius: 6px; border: 1px solid #cbd5e1; font-size: 13px;">
                    <option value="">-- Vælg Widget --</option>
                    <?php foreach ($available_widgets as $widget): ?>
                        <option value="<?= htmlspecialchars($widget['file']) ?>" <?= ($currentWidget == $widget['file']) ? 'selected' : '' ?>>
                            <?= htmlspecialchars($widget['name']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>

                <div style="font-size: 11px; font-weight: 700; color: #64748b;">STØRRELSE:
                    <select name="dashboard_size" style="padding: 6px; border-radius: 6px; border: 1px solid #cbd5e1; margin-left: 5px;">
                        <option value="small" <?= $dashSize == 'small' ? 'selected' : '' ?>>Lille (1/4)</option>
                        <option value="medium" <?= $dashSize == 'medium' ? 'selected' : '' ?>>Mellem (1/2)</option>
                        <option value="full" <?= $dashSize == 'full' ? 'selected' : '' ?>>Fuld bredde</option>
                    </select>
                </div>

                <div style="font-size: 11px; font-weight: 700; color: #64748b;">NR:
                    <input type="number" name="dashboard_order" value="<?= $dashOrder ?>" style="width: 45px; padding: 5px; border: 1px solid #cbd5e1; border-radius: 6px; margin-left: 5px;">
                </div>

                <button type="submit" name="save_dashboard_toggle" style="background: #1e293b; color: white; border: none; padding: 8px 15px; border-radius: 6px; font-weight: 700; cursor: pointer; font-size: 12px;">Gem Layout</button>
            </div>
        </form>
    </div>

    <div style="background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden; box-shadow: 0 1px 3px rgba(0,0,0,0.05);">
        <div style="padding: 15px 20px; border-bottom: 1px solid #f1f5f9; background: #fafafa;">
             <h3 style="margin:0; font-size: 14px; color: #475569; display: flex; align-items: center; gap: 8px;">
                <i class="fas fa-link" style="color: #94a3b8;"></i> Forbindelse & Opsætning
             </h3>
        </div>
        
        <form method="post" style="padding: 25px;">
            <?= csrf_field() ?>
            <div style="display: flex; gap: 20px; margin-bottom: 25px;">
                
                <div style="flex: 1;">
                    <label for="el_fetch_method" style="display: block; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Metode:</label>
                    <select name="el_fetch_method" id="el_fetch_method" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff; font-weight: 600; color: #1e293b;">
                        <?php foreach ($engines as $id => $e): ?>
                            <option value="<?= htmlspecialchars($id) ?>" <?= $eMetode === $id ? 'selected' : '' ?>><?= htmlspecialchars($e['navn'] . ($e['land'] !== '' ? ' (' . $e['land'] . ')' : '')) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>

                <!-- Felt 2a: Google Sheets CSV Link -->
                <div id="wrap_sheets_url" style="flex: 2; <?= $isSheets ? '' : 'display: none;' ?>">
                    <label for="el_sheets_url" style="display: block; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Google Sheets CSV Link:</label>
                    <input type="text" name="el_sheets_url" id="el_sheets_url" value="<?= htmlspecialchars($eSheet) ?>" placeholder="Indsæt link her..." style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 13px; box-sizing: border-box;">
                </div>

                <!-- Felt 2b: Startdato (Energinet / øvrige kilder) -->
                <div id="wrap_start_date" style="flex: 2; <?= $isSheets ? 'display: none;' : '' ?>">
                    <label for="el_start_date" style="display: block; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Hent elpriser fra dato:</label>
                    <input type="date" name="el_start_date" id="el_start_date" value="<?= htmlspecialchars($eStartDate) ?>" style="width: 100%; padding: 9px 10px; border-radius: 8px; border: 1px solid #e2e8f0; font-size: 13px; font-family: inherit; color: #1e293b; box-sizing: border-box;">
                </div>

                <div style="flex: 1;">
                    <label for="el_region" style="display: block; font-size: 11px; font-weight: 800; color: #64748b; text-transform: uppercase; margin-bottom: 8px;">Region:</label>
                    <select name="el_region" id="el_region" style="width: 100%; padding: 10px; border-radius: 8px; border: 1px solid #e2e8f0; background: #fff; font-weight: 600; color: #1e293b;">
                        <?php foreach ($allRegions as $code => $regionName): ?>
                            <option value="<?= htmlspecialchars($code) ?>" <?= $eRegion === $code ? 'selected' : '' ?>><?= htmlspecialchars($code === $regionName ? $code : $code . ' (' . $regionName . ')') ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>

            <div style="display: flex; justify-content: space-between; align-items: center; padding-top: 20px; border-top: 1px dashed #e2e8f0;">
                <div style="display: flex; align-items: center; gap: 20px;">
                    <a id="link_guide_ark" href="system/modules/<?= $modDir ?>/admin/guide_elpriser.php" target="_blank" style="font-size: 12px; color: #3b82f6; text-decoration: none; font-weight: 700; text-transform: uppercase; <?= $isSheets ? '' : 'display: none;' ?>">
                        <i class="fas fa-file-csv" style="margin-right: 5px;"></i> Se guide til ark
                    </a>
                    <a href="system/modules/<?= $modDir ?>/sync_elpriser.php" target="_blank" style="font-size: 12px; color: #3b82f6; text-decoration: none; font-weight: 700; text-transform: uppercase;">
                        <i class="fas fa-sync" style="margin-right: 5px;"></i> Åbn synkronisering
                    </a>
                    <label style="display: flex; align-items: center; gap: 8px; font-size: 13px; font-weight: 600; color: #475569; cursor: pointer;">
                        <input type="checkbox" name="elpriser_auto_fetch" value="1" <?= $autoFetch == '1' ? 'checked' : '' ?> style="width: 16px; height: 16px;">
                        Auto-opdater ved login
                    </label>
                </div>

                <div style="display: flex; gap: 12px;">
                    <button type="submit" name="sync_manual" style="background: #64748b; color: white; border: none; padding: 10px 20px; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 12px;">KØR MANUEL SYNK</button>
                    <button type="submit" name="save_el_settings" style="background: #3b82f6; color: white; border: none; padding: 10px 25px; border-radius: 8px; font-weight: 700; cursor: pointer; font-size: 12px; box-shadow: 0 2px 4px rgba(59, 130, 246, 0.3);">GEM INDSTILLINGER</button>
                </div>
            </div>
        </form>
    </div>

    <div style="margin-top: 25px; background: white; border-radius: 12px; border: 1px solid #e2e8f0; overflow: hidden;">
        <div style="padding: 15px 20px; border-bottom: 1px solid #f1f5f9; display: flex; align-items: center; gap: 10px;">
            <i class="fas fa-database" style="color: #94a3b8;"></i>
            <span style="font-weight: 700; color: #475569; font-size: 14px;">Seneste 10 dage i databasen</span>
        </div>
        <table style="width: 100%; border-collapse: collapse;">
            <thead>
                <tr style="text-align: left; background: #f8fafc; border-bottom: 1px solid #e2e8f0;">
                    <th style="padding: 12px 20px; font-size: 12px; color: #64748b;">Dato</th>
                    <th style="padding: 12px 20px; font-size: 12px; color: #64748b;">Gns. Pris</th>
                    <th style="padding: 12px 20px; font-size: 12px; color: #64748b; text-align: right;">Region</th>
                </tr>
            </thead>
            <tbody>
                <?php
                $stmt = $pdo->prepare("SELECT dato, price_gns, region FROM electricity_prices WHERE region = ? ORDER BY dato DESC LIMIT 10");
                $stmt->execute([$eRegion]);
                while ($row = $stmt->fetch()): ?>
                <tr style="border-bottom: 1px solid #f1f5f9;">
                    <td style="padding: 12px 20px; font-size: 13px; font-weight: 500;"><?= date('d. M Y', strtotime($row['dato'])) ?></td>
                    <td style="padding: 12px 20px; font-size: 13px; font-weight: 700;"><?= number_format($row['price_gns'], 2, ',', '.') ?> kr</td>
                    <td style="padding: 12px 20px; font-size: 11px; text-align: right; color: #94a3b8; font-weight: 800;"><?= htmlspecialchars($row['region']) ?></td>
                </tr>
                <?php endwhile; ?>
            </tbody>
        </table>
    </div>

</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    const methodSelect = document.getElementById('el_fetch_method');
    const wrapSheets   = document.getElementById('wrap_sheets_url');
    const wrapDate     = document.getElementById('wrap_start_date');
    const guideLink    = document.getElementById('link_guide_ark');

    function toggleSourceFields() {
        const isSheets = (methodSelect.value === 'google_sheets');
        wrapSheets.style.display = isSheets ? '' : 'none';
        wrapDate.style.display   = isSheets ? 'none' : '';
        if (guideLink) {
            guideLink.style.display = isSheets ? '' : 'none';
        }
    }

    if (methodSelect) {
        toggleSourceFields();
        methodSelect.addEventListener('change', toggleSourceFields);
    }
});
</script>