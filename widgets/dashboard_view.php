<?php
/**
 * system/modules/Elpriser/widgets/dashboard_view.php
 * Tilpasset kolonnenavne: price_gns, price_max, price_min, dato
 */

$today = date('Y-m-d');

try {
    // Vi henter den seneste række baseret på dato
    $price_stmt = $pdo->prepare("SELECT price_gns, price_max, price_min FROM electricity_prices WHERE dato = ? LIMIT 1");
    $price_stmt->execute([$today]);
    $prices = $price_stmt->fetch();

    // Hvis i dag ikke findes endnu, henter vi bare den nyeste overhovedet
    if (!$prices) {
        $prices = $pdo->query("SELECT price_gns, price_max, price_min FROM electricity_prices ORDER BY dato DESC LIMIT 1")->fetch();
    }

    $gns = number_format($prices['price_gns'] ?? 0, 2, ',', '.');
    $max = number_format($prices['price_max'] ?? 0, 2, ',', '.');
    $min = number_format($prices['price_min'] ?? 0, 2, ',', '.');

} catch (PDOException $e) {
    echo "<p style='color: #94a3b8; font-size: 0.8em;'>Fejl i elpris-data.</p>";
    return;
}
?>

<div style="display: flex; flex-direction: column; gap: 10px;">
    <div style="text-align: center; padding: 10px; background: #f8fafc; border-radius: 12px; border: 1px solid #e2e8f0;">
        <div style="font-size: 0.7em; color: #64748b; font-weight: 800; text-transform: uppercase;">Gennemsnit i dag</div>
        <div style="font-size: 1.5em; font-weight: 900; color: #3b82f6;"><?= $gns ?> <span style="font-size: 0.5em;">kr/kWh</span></div>
    </div>

    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 10px;">
        <div style="padding: 8px; background: #fff5f5; border-radius: 8px; text-align: center; border: 1px solid #fed7d7;">
            <div style="font-size: 0.6em; color: #9b2c2c; font-weight: 700;">MAX</div>
            <div style="font-weight: 800; color: #9b2c2c;"><?= $max ?></div>
        </div>
        <div style="padding: 8px; background: #f0fdf4; border-radius: 8px; text-align: center; border: 1px solid #dcfce7;">
            <div style="font-size: 0.6em; color: #166534; font-weight: 700;">MIN</div>
            <div style="font-weight: 800; color: #166534;"><?= $min ?></div>
        </div>
    </div>
</div>