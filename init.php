<?php
// system/modules/Elpriser/init.php
// Menupunktet bygges af kernen ud fra module.json / databasen.

// Modulets CSS - kun på modulets egen side
add_action('header_scripts', function () {
    // Store/små bogstaver i adressen er ligegyldige, og stien bruger mappens rigtige navn
    if (strcasecmp((string)($_GET['side'] ?? ''), 'Elpriser') === 0) {
        $css = __DIR__ . '/style.css';
        $v   = file_exists($css) ? filemtime($css) : 1;
        echo '<link rel="stylesheet" href="system/modules/' . rawurlencode(basename(__DIR__)) . '/style.css?v=' . $v . '">';
    }
});

// Henter manglende dage, når nogen logger ind (kun hvis det er slået til under El-pris Opsætning)
add_action('user_login_sync', function ($loginUser = null) {
    global $pdo;
    if (!($pdo instanceof PDO)) {
        return;
    }
    try {
        require_once __DIR__ . '/admin/functions.php';
        if (elpriser_setting($pdo, 'auto_fetch', '0') === '1') {
            // Højst 7 dage og 8 sekunder, så login ikke bliver langsomt
            elpriser_sync_missing($pdo, 7, null, 8);
        }
    } catch (Throwable $e) {
        error_log('Elpriser: automatisk hentning fejlede: ' . $e->getMessage());
    }
});
