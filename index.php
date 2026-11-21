<?php
// system/modules/Elpriser/index.php

/**
 * 1. INITIALISERING OG HENTNING AF INSTALLATIONSDATO FRA DB
 */
require_once __DIR__ . '/functions.php';
if (file_exists(__DIR__ . '/admin/functions.php')) {
    require_once __DIR__ . '/admin/functions.php';
}

$installDate = date('Y-m-d');
$eRegion = isset($pdo) && function_exists('elpriser_setting') ? strtoupper(elpriser_setting($pdo, 'region', 'DK1')) : 'DK1';

if (isset($pdo)) {
    $stmt = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'installation_date' LIMIT 1");
    $stmt->execute();
    $res = $stmt->fetchColumn();
    if ($res && elpriser_valid_date((string)$res)) { 
        $installDate = $res; 
    } else {
        $stmtFirst = $pdo->prepare("SELECT MIN(dato) FROM electricity_prices WHERE region = ?");
        $stmtFirst->execute([$eRegion]);
        $first = $stmtFirst->fetchColumn();
        if ($first) {
            $installDate = $first;
        }
    }
}
$startTS = strtotime(date('Y-m-01', strtotime($installDate)));

/**
 * 2. NAVIGATION OG DATO-LOGIK
 */
$view = $_GET['view'] ?? 'month';
if (!in_array($view, ['month', 'year', 'comparison'])) {
    $view = 'month';
}

$date = (string)($_GET['date'] ?? '');
if (!preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $date)) {
    $date = date('Y-m');
}
$currentTime = strtotime($date . "-01");

if ($currentTime < $startTS) {
    $currentTime = $startTS;
    $date = date('Y-m', $currentTime);
}

$selectedYear = date('Y', $currentTime);

$prevMonthTS = strtotime("-1 month", $currentTime);
$nextMonthTS = strtotime("+1 month", $currentTime);
$prevYearTS  = strtotime("-1 year", $currentTime);
$nextYearTS  = strtotime("+1 year", $currentTime);

$prevYearDisabled  = (date('Y', $prevYearTS) < date('Y', $startTS)) ? 'disabled' : '';
$prevMonthDisabled = ($prevMonthTS < $startTS) ? 'disabled' : '';
$nextDisabled      = ($nextMonthTS > time()) ? 'disabled' : '';

/**
 * 3. DATAHENTNING
 */
$history = [];
$comparisonData = [];
$availableYears = [];

if (isset($pdo)) {
    $stmtYears = $pdo->prepare("SELECT DISTINCT YEAR(dato) as y FROM electricity_prices WHERE region = ? ORDER BY y DESC");
    $stmtYears->execute([$eRegion]);
    $availableYears = $stmtYears->fetchAll(PDO::FETCH_COLUMN);

    $stmtToday = $pdo->prepare("SELECT price_gns FROM electricity_prices WHERE region = ? ORDER BY dato DESC LIMIT 1");
    $stmtToday->execute([$eRegion]);
    $stats_today = (float)$stmtToday->fetchColumn();

    $stmt30 = $pdo->prepare("SELECT AVG(price_gns) FROM electricity_prices WHERE region = ? AND dato > DATE_SUB(CURDATE(), INTERVAL 30 DAY)");
    $stmt30->execute([$eRegion]);
    $stats_30days = (float)$stmt30->fetchColumn();

    $stmtYear = $pdo->prepare("SELECT AVG(price_gns) FROM electricity_prices WHERE region = ? AND YEAR(dato) = ?");
    $stmtYear->execute([$eRegion, $selectedYear]);
    $avg_selected_year = (float)$stmtYear->fetchColumn();

    if ($view === 'year') {
        $stmt = $pdo->prepare("SELECT DATE_FORMAT(dato, '%Y-%m') as periode, AVG(price_gns) as price_gns FROM electricity_prices WHERE region = ? AND YEAR(dato) = ? GROUP BY periode ORDER BY periode ASC");
        $stmt->execute([$eRegion, $selectedYear]);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } elseif ($view === 'comparison') {
        $selectedYears = $_GET['years'] ?? array_slice($availableYears, 0, 3);
        if (!is_array($selectedYears)) $selectedYears = [$selectedYears];
        
        foreach ($selectedYears as $y) {
            $y = (int)$y;
            $stmtC = $pdo->prepare("SELECT MONTH(dato) as m, AVG(price_gns) as price_gns FROM electricity_prices WHERE region = ? AND YEAR(dato) = ? GROUP BY m ORDER BY m ASC");
            $stmtC->execute([$eRegion, $y]);
            $rows = $stmtC->fetchAll(PDO::FETCH_ASSOC);
            
            $monthsMap = array_fill(1, 12, null);
            foreach ($rows as $r) {
                $monthsMap[(int)$r['m']] = (float)$r['price_gns'];
            }
            $comparisonData[$y] = array_values($monthsMap);
        }
    } else {
        $stmt = $pdo->prepare("SELECT * FROM electricity_prices WHERE region = ? AND dato LIKE ? ORDER BY dato ASC");
        $stmt->execute([$eRegion, $date . '%']);
        $history = $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
}

$avg_period = !empty($history) ? array_sum(array_column($history, 'price_gns')) / count($history) : 0;

$max_val = !empty($history) ? max(array_column($history, 'price_gns')) : 0;
$max_axis = ($max_val > 0) ? ceil($max_val * 1.25) : 5; 
$steps = 5;
$step_size = $max_axis / $steps;
?>

<!-- Inkluder Chart.js til linjegrafen -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>

<div class="el-module">
    <div class="el-navigation">
        <div class="nav-combined">
            <?php if ($view !== 'comparison'): ?>
                <div class="nav-unit">
                    <span class="nav-label">ÅR</span>
                    <div class="nav-controls">
                        <a href="<?= $prevYearDisabled ? '#' : "?side=Elpriser&view=$view&date=".date('Y-m', $prevYearTS) ?>" class="nav-btn <?= $prevYearDisabled ?>"><i class="fas fa-angle-double-left"></i></a>
                        <span class="nav-display"><?= $selectedYear ?></span>
                        <a href="<?= $nextDisabled ? '#' : "?side=Elpriser&view=$view&date=".date('Y-m', $nextYearTS) ?>" class="nav-btn <?= $nextDisabled ?>"><i class="fas fa-angle-double-right"></i></a>
                    </div>
                </div>
                <?php if ($view !== 'year'): ?>
                <div class="nav-unit">
                    <span class="nav-label">MÅNED</span>
                    <div class="nav-controls">
                        <a href="<?= $prevMonthDisabled ? '#' : "?side=Elpriser&view=$view&date=".date('Y-m', $prevMonthTS) ?>" class="nav-btn <?= $prevMonthDisabled ?>"><i class="fas fa-chevron-left"></i></a>
                        <span class="nav-display"><?= date('F', $currentTime) ?></span>
                        <a href="<?= $nextDisabled ? '#' : "?side=Elpriser&view=$view&date=".date('Y-m', $nextMonthTS) ?>" class="nav-btn <?= $nextDisabled ?>"><i class="fas fa-chevron-right"></i></a>
                    </div>
                </div>
                <?php endif; ?>
            <?php else: ?>
                <div class="nav-unit">
                    <span class="nav-label">SAMMENLIGNING</span>
                    <div class="nav-controls" style="font-size: 0.85rem; padding: 4px 6px; color: #555;">Vælg år nedenfor</div>
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Visnings-knapper i højre side (Måned, År, Sammenlign) -->
        <div class="view-switcher">
            <a href="?side=Elpriser&view=month&date=<?= $date ?>" class="view-btn <?= $view == 'month' ? 'active' : '' ?>">Måned</a>
            <a href="?side=Elpriser&view=year&date=<?= $date ?>" class="view-btn <?= $view == 'year' ? 'active' : '' ?>">År</a>
            <a href="?side=Elpriser&view=comparison" class="view-btn <?= $view == 'comparison' ? 'active' : '' ?>">Sammenlign</a>
        </div>
    </div>

    <!-- Statistik-kort -->
    <div class="el-stats-grid">
        <div class="el-card"><span class="el-label">SENESTE PRIS</span><span class="el-value"><?= number_format($stats_today, 2, ',', '.') ?> kr</span></div>
        <div class="el-card"><span class="el-label">GNS. FOR <?= $selectedYear ?></span><span class="el-value"><?= number_format($avg_selected_year, 2, ',', '.') ?> kr</span></div>
        <div class="el-card highlight"><span class="el-label">GNS. PERIODE</span><span class="el-value"><?= number_format($avg_period, 2, ',', '.') ?> kr</span></div>
        <div class="el-card"><span class="el-label">SIDSTE 30 DAGE</span><span class="el-value"><?= number_format($stats_30days, 2, ',', '.') ?> kr</span></div>
    </div>

    <!-- Graf boks -->
    <div class="el-chart-box" style="padding: 40px 20px 60px 20px;">
        <?php if ($view === 'comparison'): ?>
            <!-- Års-sammenligning med tjekbokse og Chart.js linjegraf -->
            <form method="GET" action="" style="margin-bottom: 25px; display: flex; gap: 15px; align-items: center; flex-wrap: wrap; background: #f8fafc; padding: 15px; border-radius: 8px;">
                <input type="hidden" name="side" value="Elpriser">
                <input type="hidden" name="view" value="comparison">
                <span style="font-weight: 600; font-size: 0.9rem; color: #333;">Vælg år der skal vises:</span>
                <?php foreach ($availableYears as $y): 
                    $isChecked = isset($_GET['years']) ? in_array($y, $_GET['years']) : true;
                ?>
                    <label style="display: flex; align-items: center; gap: 6px; cursor: pointer; font-size: 0.9rem;">
                        <input type="checkbox" name="years[]" value="<?= $y ?>" <?= $isChecked ? 'checked' : '' ?>> <?= $y ?>
                    </label>
                <?php endforeach; ?>
                <button type="submit" style="background: #2563eb; color: white; border: none; padding: 6px 14px; border-radius: 6px; cursor: pointer; font-weight: 600;">Opdater graf</button>
            </form>

            <div style="position: relative; height: 320px; width: 100%;">
                <canvas id="yearlyComparisonChart"></canvas>
            </div>

            <script>
                const ctx = document.getElementById('yearlyComparisonChart').getContext('2d');
                const comparisonData = <?= json_encode($comparisonData) ?>;
                
                const months = ['Jan', 'Feb', 'Mar', 'Apr', 'Maj', 'Jun', 'Jul', 'Aug', 'Sep', 'Okt', 'Nov', 'Dec'];
                const colors = ['#2563eb', '#10b981', '#f59e0b', '#ef4444', '#8b5cf6', '#ec4899', '#14b8a6'];
                
                let datasets = [];
                let colorIdx = 0;

                for (const [year, monthlyPrices] of Object.entries(comparisonData)) {
                    datasets.push({
                        label: 'År ' + year,
                        data: monthlyPrices,
                        borderColor: colors[colorIdx % colors.length],
                        backgroundColor: colors[colorIdx % colors.length],
                        borderWidth: 2.5,
                        tension: 0.2,
                        fill: false,
                        pointRadius: 4,
                        pointHoverRadius: 6
                    });
                    colorIdx++;
                }

                new Chart(ctx, {
                    type: 'line',
                    data: {
                        labels: months,
                        datasets: datasets
                    },
                    options: {
                        responsive: true,
                        maintainAspectRatio: false,
                        plugins: {
                            legend: {
                                position: 'top',
                            }
                        },
                        scales: {
                            y: {
                                beginAtZero: false,
                                grid: { color: '#f1f5f9' }
                            },
                            x: {
                                grid: { display: false }
                            }
                        }
                    }
                });
            </script>

        <?php elseif (!empty($history)): ?>
            <!-- Standard Måned / År Søjlegraf -->
            <div style="display: flex; height: 300px; position: relative;">
                <div style="display: flex; flex-direction: column-reverse; justify-content: space-between; width: 50px; color: #999; font-size: 0.75rem; border-right: 2px solid #f0f0f0; text-align: right; padding-right: 10px;">
                    <?php for ($i = 0; $i <= $steps; $i++): ?>
                        <span style="height: 0; line-height: 0; display: flex; align-items: center; justify-content: flex-end;"><?= number_format($i * $step_size, 1, ',', '.') ?></span>
                    <?php endfor; ?>
                </div>

                <div style="flex: 1; display: flex; align-items: flex-end; gap: 4px; position: relative; padding-left: 5px;">
                    <?php for ($i = 1; $i <= $steps; $i++): ?>
                        <div style="position: absolute; left: 0; right: 0; bottom: <?= ($i / $steps) * 100 ?>%; border-bottom: 1px dashed #eee; height: 0; z-index: 1;"></div>
                    <?php endfor; ?>

                    <?php foreach($history as $row): 
                        $val = (float)$row['price_gns'];
                        $heightPct = ($val / $max_axis) * 100;
                        
                        if ($val > ($avg_period * 1.2)) $color = '#d32f2f';
                        elseif ($val > $avg_period) $color = '#f39c12';
                        elseif ($val > ($avg_period * 0.8)) $color = '#2ecc71';
                        else $color = '#27ae60';

                        $label = $view == 'year' ? date('M', strtotime($row['periode']."-01")) : date('d.', strtotime($row['dato']));
                    ?>
                        <div style="flex: 1; display: flex; flex-direction: column; justify-content: flex-end; height: 100%; position: relative; z-index: 2;">
                            <span style="position: absolute; bottom: <?= $heightPct ?>%; left: 50%; transform: translate(-50%, -5px); font-size: 0.65rem; font-weight: bold; color: #444; white-space: nowrap;">
                                <?= number_format($val, 2, ',', '.') ?>
                            </span>
                            <div style="height: <?= max(0.5, $heightPct) ?>%; background-color: <?= $color ?>; width: 100%; border-radius: 2px 2px 0 0;" 
                                 title="<?= $label ?>: <?= number_format($val, 2, ',', '.') ?> kr">
                            </div>
                            <span style="position: absolute; top: 100%; left: 0; right: 0; text-align: center; font-size: 0.7rem; color: #888; padding-top: 8px;">
                                <?= $label ?>
                            </span>
                        </div>
                    <?php endforeach; ?>
                </div>
            </div>
        <?php else: ?>
            <div class="no-data-msg" style="padding: 50px;">Ingen data fundet for denne periode.</div>
        <?php endif; ?>
    </div>
</div>