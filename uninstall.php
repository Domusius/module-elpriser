<?php
/**
 * system/modules/Elpriser/uninstall.php
 * Sletter tabeller og indstillinger relateret til El-priser modulet.
 */

// Filen må kun køres af kernen (via Modul Styring), aldrig åbnes direkte i browseren.
if (!isset($pdo) || !($pdo instanceof PDO)) {
    http_response_code(403);
    exit('Adgang nægtet');
}

try {
    // 1. Slet tabellerne
    $pdo->exec("DROP TABLE IF EXISTS `electricity_prices`");
    $pdo->exec("DROP TABLE IF EXISTS `electricity_rates_history`");

    // 2. Slet de specifikke indstillinger modulet indsatte i site_settings
    $settingsKeys = ['el_region', 'el_auto_fetch', 'el_fetch_method', 'el_sheets_url', 'el_vat_percent', 'el_start_date', 'el_api_key_hash', 'elpriser_auto_fetch'];
    $placeholders = implode(',', array_fill(0, count($settingsKeys), '?'));

    $stmt = $pdo->prepare("DELETE FROM site_settings WHERE setting_key IN ($placeholders)");
    $stmt->execute($settingsKeys);

    // 3. Slet database-update referencer (tabellen findes kun i nogle systemer)
    try {
        $pdo->prepare("DELETE FROM database_updates WHERE module = ?")->execute(['El-priser']);
    } catch (Throwable $e) {
        // Ingen tabel med den slags referencer: det er i orden
    }

    echo "SUCCESS: El-priser modul er nu afinstalleret og data er slettet.";
} catch (Exception $e) {
    echo "FEJL ved sletning af El-priser modul: " . $e->getMessage();
}
