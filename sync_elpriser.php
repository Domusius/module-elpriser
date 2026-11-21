<?php
// system/modules/Elpriser/sync_elpriser.php
// El-Synk V2.1: Ren stabil batch med faste 45-sekunders pauser

$root = realpath(dirname(__FILE__) . '/../../../');
require_once $root . '/config/config.php';
require_once $root . '/inc/csrf.php';
require_once __DIR__ . '/admin/functions.php';

function elpriser_sync_is_admin(PDO $pdo): bool
{
    $uid = (int)($_SESSION['user_id'] ?? 0);
    if ($uid <= 0) {
        return false;
    }
    $stmt = $pdo->prepare("SELECT role FROM users WHERE id = ? LIMIT 1");
    $stmt->execute([$uid]);
    return $stmt->fetchColumn() === 'admin';
}

function elpriser_sync_key_ok(PDO $pdo): bool
{
    $key    = $_SERVER['HTTP_X_API_KEY'] ?? ($_POST['key'] ?? '');
    $stored = elpriser_setting($pdo, 'api_key_hash', '');
    return is_string($key) && $key !== '' && $stored !== '' && hash_equals($stored, hash('sha256', $key));
}

// AJAX Håndtering
$action = (string)($_POST['action'] ?? '');
if ($action !== '') {
    header('Content-Type: application/json; charset=utf-8');
    $isAdmin = elpriser_sync_is_admin($pdo);
    $byKey   = elpriser_sync_key_ok($pdo);

    if (!$byKey && !($isAdmin && csrf_check($_POST['csrf'] ?? ''))) {
        echo json_encode(['status' => 'error', 'message' => 'Adgang nægtet']);
        exit;
    }

    if ($action === 'ajax_sync_day') {
        @set_time_limit(60);
        $engines = elpriser_engines();
        $kilde   = (string)($_POST['kilde'] ?? '');
        if ($kilde === '') {
            $kilde = (string)elpriser_current_engine($pdo, $engines);
        }
        $region = strtoupper((string)($_POST['region'] ?? ''));
        if (!preg_match('/^[A-Z0-9-]{2,10}$/', $region)) {
            $region = strtoupper(elpriser_setting($pdo, 'region', 'DK1'));
        }
        $res = elpriser_run_engine_day($pdo, $kilde, (string)($_POST['date'] ?? ''), $region);
        echo json_encode(['status' => $res['ok'] ? 'ok' : 'error', 'message' => $res['message']]);
        exit;
    }

    if ($action === 'test_connection' && $isAdmin) {
        @set_time_limit(60);
        $rep = elpriser_connection_report();
        $rows = [];
        foreach ($rep['rows'] as [$name, $value, $ok]) {
            $rows[] = ['name' => $name, 'value' => $value, 'ok' => $ok];
        }
        echo json_encode(['status' => 'ok', 'rows' => $rows, 'hint' => $rep['hint']]);
        exit;
    }

    echo json_encode(['status' => 'error', 'message' => 'Ukendt handling']);
    exit;
}

if (!elpriser_sync_is_admin($pdo)) {
    exit('Adgang nægtet');
}

$limit = isset($_GET['limit']) ? max(1, min(1000, (int)$_GET['limit'])) : 1;

$engines   = elpriser_engines();
$currentId = elpriser_current_engine($pdo, $engines);
$region    = strtoupper(elpriser_setting($pdo, 'region', 'DK1'));
$installDate  = elpriser_start_date($pdo);
$missingDates = elpriser_missing_dates($pdo, $region, $limit);
?>

<!DOCTYPE html>
<html lang="da">
<head>
    <meta charset="UTF-8">
    <title>El-Synk V2.1</title>
    <style>
        body { font-family: 'Segoe UI', sans-serif; background: #0f172a; color: #f8fafc; padding: 40px; }
        .container { max-width: 700px; margin: auto; background: #1e293b; padding: 30px; border-radius: 16px; box-shadow: 0 10px 25px rgba(0,0,0,0.3); }
        .header { text-align: center; margin-bottom: 30px; }
        .btn-grid { display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; margin-bottom: 20px; }
        .btn-more { grid-column: span 3; display: grid; grid-template-columns: repeat(3, 1fr); gap: 10px; }
        .btn { padding: 12px; border: none; border-radius: 8px; font-weight: 600; cursor: pointer; background: #334155; color: white; transition: 0.2s; text-decoration: none; text-align: center; }
        .btn:hover { background: #475569; }
        .btn.active { background: #2563eb; }
        .btn-run { background: #10b981; width: 100%; font-size: 1.1rem; margin-top: 10px; cursor: pointer; }
        .btn-run:hover { background: #059669; }
        .log { background: #000; color: #4ade80; padding: 15px; height: 300px; overflow-y: auto; font-family: 'Consolas', monospace; font-size: 13px; margin-top: 25px; border-radius: 8px; border: 1px solid #334155; }
        .status-bar { background: #334155; padding: 15px; border-radius: 8px; margin-bottom: 20px; font-size: 0.95rem; }
        .status-bar select { background: #0f172a; color: #f8fafc; border: 1px solid #475569; border-radius: 6px; padding: 5px 8px; font-size: 0.9rem; }
        .test-link { text-align: center; margin-top: 15px; font-size: 0.85rem; }
        .test-link a { color: #94a3b8; }
    </style>
</head>
<body>

<div class="container">
    <div class="header">
        <h1>&#9889; Elpris Synkronisering</h1>
    </div>

    <div class="status-bar">
        &#128197; Startdato: <b><?= htmlspecialchars($installDate) ?></b><br>
        &#128269; Finder første hul... Næste manglende dato er: <b><?= !empty($missingDates) ? htmlspecialchars($missingDates[0]) : 'Ingen (Færdig!)' ?></b><br>
        &#128268; Kilde:
        <?php if ($engines): ?>
            <select id="kilde">
                <?php foreach ($engines as $id => $e): ?>
                    <option value="<?= htmlspecialchars($id) ?>" <?= $id === $currentId ? 'selected' : '' ?>>
                        <?= htmlspecialchars($e['navn'] . ($e['land'] !== '' ? ' (' . $e['land'] . ')' : '')) ?>
                    </option>
                <?php endforeach; ?>
            </select>
            &nbsp;Region: <b><?= htmlspecialchars($region) ?></b>
        <?php else: ?>
            <b>Ingen kilder i mappen price_engines</b>
        <?php endif; ?>
    </div>

    <div class="btn-grid">
        <a href="?limit=1" class="btn <?= $limit == 1 ? 'active' : '' ?>">1 dag</a>
        <a href="?limit=3" class="btn <?= $limit == 3 ? 'active' : '' ?>">3 dage</a>
        <a href="?limit=5" class="btn <?= $limit == 5 ? 'active' : '' ?>">5 dage</a>
    </div>
    <div class="btn-more">
        <a href="?limit=50" class="btn <?= $limit == 50 ? 'active' : '' ?>">50 dage</a>
        <a href="?limit=100" class="btn <?= $limit == 100 ? 'active' : '' ?>">100 dage</a>
        <a href="?limit=300" class="btn <?= $limit == 300 ? 'active' : '' ?>">300 dage</a>
    </div>

    <?php if (!empty($missingDates) && $engines): ?>
        <button type="button" id="btnSync" onclick="startSync()" class="btn btn-run">KØR SYNKRONISERING (<?= count($missingDates) ?> dage)</button>
    <?php elseif (empty($missingDates)): ?>
        <div style="text-align:center; padding: 20px; color: #10b981; font-weight: bold;">&#9989; Alle priser er hentet!</div>
    <?php endif; ?>

    <div id="log" class="log" style="display:none;"></div>

    <div class="test-link"><a href="#" onclick="testConnection(); return false;">Test forbindelsen til internettet</a></div>
</div>

<script>
const CSRF = <?= json_encode(csrf_token()) ?>;

function esc(s) {
    return String(s).replace(/[&<>"']/g, c => ({'&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#39;'}[c]));
}

const sleep = ms => new Promise(r => setTimeout(r, ms));

async function post(fields) {
    const body = new URLSearchParams(Object.assign({csrf: CSRF}, fields));
    const resp = await fetch(location.pathname, {method: 'POST', body: body});
    return await resp.json();
}

async function startSync() {
    const dates = <?= json_encode($missingDates) ?>;
    if (dates.length === 0) return;
    
    const kilde = document.getElementById('kilde') ? document.getElementById('kilde').value : '';
    const log = document.getElementById('log');
    const btn = document.getElementById('btnSync');
    
    log.style.display = "block";
    log.innerHTML = "--- Starter batch-synkronisering ---<br>";
    btn.disabled = true;
    btn.style.opacity = "0.6";

    for (let i = 0; i < dates.length; i++) {
        const d = dates[i];

        if (i > 0) {
            log.innerHTML += `<br>--- Venter 45 sekunder før næste dag (${esc(d)})... ---`;
            log.scrollTop = log.scrollHeight;
            
            // Rent, uforstyrret holdepunkt i 45 sekunder
            await sleep(45000);
        }

        log.innerHTML += `<br>> Henter ${esc(d)}... `;
        log.scrollTop = log.scrollHeight;

        try {
            let r = await post({action: 'ajax_sync_day', date: d, kilde: kilde});
            
            if (r.status !== "ok" && String(r.message).includes('429')) {
                log.innerHTML += `<span style="color:#fbbf24;">[Rate limit 429 - venter ekstra 30 sek...]</span> `;
                log.scrollTop = log.scrollHeight;
                await sleep(30000);
                r = await post({action: 'ajax_sync_day', date: d, kilde: kilde});
            }

            if (r.status === "ok") {
                log.innerHTML += `<span style="color:white;">[GEMT]</span>`;
            } else {
                log.innerHTML += `<span style="color:#f87171;">[FEJL: ${esc(r.message)}]</span><br><br><b>Stoppet ved fejl.</b>`;
                btn.disabled = false;
                btn.style.opacity = "1";
                log.scrollTop = log.scrollHeight;
                return;
            }
        } catch (e) {
            log.innerHTML += `<span style="color:#f87171;">[NETVÆRKSFEJL]</span><br><br><b>Stoppet ved netværksfejl.</b>`;
            btn.disabled = false;
            btn.style.opacity = "1";
            log.scrollTop = log.scrollHeight;
            return;
        }
        log.scrollTop = log.scrollHeight;
    }

    log.innerHTML += "<br><br><b>SYNKRONISERING FÆRDIG!</b><br>Genindlæser om 3 sekunder...";
    log.scrollTop = log.scrollHeight;
    setTimeout(() => location.reload(), 3000);
}

async function testConnection() {
    const log = document.getElementById('log');
    log.style.display = "block";
    log.innerHTML = "--- Tester forbindelsen ---<br>";
    try {
        const r = await post({action: 'test_connection'});
        if (r.status !== "ok") {
            log.innerHTML += `<span style="color:#f87171;">[${esc(r.message)}]</span><br>`;
            return;
        }
        for (const row of r.rows) {
            const color = row.ok ? '#4ade80' : '#f87171';
            log.innerHTML += `<span style="color:${color};">[${row.ok ? 'OK' : 'FEJL'}]</span> ${esc(row.name)}: ${esc(row.value)}<br>`;
        }
        if (r.hint) {
            log.innerHTML += `<br><span style="color:#fbbf24;">${esc(r.hint)}</span><br>`;
        }
    } catch (e) {
        log.innerHTML += `<span style="color:#f87171;">[FEJL]</span><br>`;
    }
    log.scrollTop = log.scrollHeight;
}
</script>

</body>
</html>