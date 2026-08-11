<?php
/*
 * GFCI Relay Control - settings page (Content Setup menu).
 *
 * Reads/writes config.json under <mediadir>/plugindata/gfci-relay-control/
 * (see gfci_paths.php) - NOT the plugin's own directory, which a Plugin
 * Manager update/reinstall deletes and re-clones from scratch.
 * relay_daemon.py (a plain systemd-run Python process, not an fppd plugin
 * object) reads the exact same file via gfci_common.py's matching DATA_DIR.
 */

require_once __DIR__ . '/gfci_paths.php';

$pluginDir = __DIR__;
$configFile = gfci_data_dir() . '/config.json';

$defaultConfig = [
    'fpp_host' => '127.0.0.1',
    'board_host' => '',
    'board_port' => 80,
    'watched_playlists' => [],
    'poll_interval_seconds' => 30,
    'arm_lead_seconds' => 0,
    'disarm_lag_seconds' => 0,
    'http_timeout_seconds' => 3,
    'notify' => [
        'ntfy' => ['enabled' => false, 'topic_url' => ''],
    ],
    'website' => ['enabled' => false, 'url' => '', 'api_key' => ''],
];

function gfci_load_config($file, $defaults) {
    if (!file_exists($file)) {
        return $defaults;
    }
    $data = json_decode(file_get_contents($file), true);
    if (!is_array($data)) {
        return $defaults;
    }
    return array_replace_recursive($defaults, $data);
}

$saved = false;
$error = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $config = gfci_load_config($configFile, $defaultConfig);

    $config['fpp_host'] = trim($_POST['fpp_host'] ?? $config['fpp_host']);
    $config['board_host'] = trim($_POST['board_host'] ?? $config['board_host']);
    $config['board_port'] = (int) ($_POST['board_port'] ?? $config['board_port']);
    $config['poll_interval_seconds'] = max(5, (int) ($_POST['poll_interval_seconds'] ?? 30));
    $config['arm_lead_seconds'] = max(0, (int) ($_POST['arm_lead_seconds'] ?? 0));
    $config['disarm_lag_seconds'] = max(0, (int) ($_POST['disarm_lag_seconds'] ?? 0));
    $config['watched_playlists'] = array_values(array_filter(array_map('trim', $_POST['watched_playlists'] ?? [])));

    $config['notify']['ntfy']['enabled'] = isset($_POST['ntfy_enabled']);
    $config['notify']['ntfy']['topic_url'] = trim($_POST['ntfy_topic_url'] ?? '');

    $config['website']['enabled'] = isset($_POST['website_enabled']);
    $config['website']['url'] = trim($_POST['website_url'] ?? '');
    $config['website']['api_key'] = trim($_POST['website_api_key'] ?? '');

    if ($config['board_host'] === '') {
        $error = 'Board host/IP is required.';
    } else {
        $bytes = @file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        if ($bytes === false) {
            $error = "Could not write $configFile - the web server user can't write there. "
                . "On the FPP box, run: sudo chown -R fpp:fpp " . dirname($configFile)
                . " && sudo chmod -R u+rwX,g+rwX " . dirname($configFile);
        } else {
            $saved = true;
        }
    }
} else {
    $config = gfci_load_config($configFile, $defaultConfig);
}

// "Send Test Alert" - runs after any save above, so it always reflects
// what's on screen. Shells out to notify.py (same code path
// relay_daemon.py uses) rather than reimplementing the ntfy POST here, so
// there is exactly one place that knows how to talk to ntfy.
$testResult = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['test_ntfy']) && !$error) {
    $notifyScript = $pluginDir . '/notify.py';
    $cmd = sprintf(
        'python3 %s --message %s 2>&1',
        escapeshellarg($notifyScript),
        escapeshellarg('Test alert from GFCI Relay Control settings page')
    );
    exec($cmd, $outputLines, $exitCode);
    $testResult = ['ok' => $exitCode === 0, 'output' => implode("\n", $outputLines)];
}

// Pull the playlist list from FPP's own local API to populate the picker.
// Prefer curl over file_get_contents(url) since allow_url_fopen is disabled
// in some PHP configs, which would otherwise fail silently.
function gfci_fetch_playlists() {
    $url = 'http://127.0.0.1/api/playlists';
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
        curl_setopt($ch, CURLOPT_TIMEOUT, 3);
        $raw = curl_exec($ch);
        curl_close($ch);
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 3]]);
        $raw = @file_get_contents($url, false, $ctx);
    }
    $decoded = $raw !== false ? json_decode($raw, true) : null;
    return is_array($decoded) ? $decoded : [];
}

$allPlaylists = gfci_fetch_playlists();
// Union with whatever is already saved, so a previously-picked playlist
// never silently disappears from the list just because the live fetch
// failed (or that playlist was since renamed/removed) - otherwise the
// selection looks like it "didn't save" on the next page load.
$displayPlaylists = array_values(array_unique(array_merge($allPlaylists, $config['watched_playlists'])));
sort($displayPlaylists);

function h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES);
}
?>

<div class="mt-2">
    <?php if ($saved): ?>
        <div class="alert alert-success">Settings saved. relay_daemon.py picks up changes on its next poll.</div>
    <?php endif; ?>
    <?php if ($error): ?>
        <div class="alert alert-danger"><?php echo h($error); ?></div>
    <?php endif; ?>
    <?php if ($testResult): ?>
        <div class="alert <?php echo $testResult['ok'] ? 'alert-success' : 'alert-danger'; ?>">
            <strong>Test alert <?php echo $testResult['ok'] ? 'sent' : 'failed'; ?>:</strong>
            <?php echo h($testResult['output']); ?>
        </div>
    <?php endif; ?>

    <form method="post">
        <fieldset class="border rounded p-3 mb-3">
            <legend class="fs-6 fw-bold">Relay Board</legend>
            <div class="mb-3">
                <label for="board_host" class="form-label">Board host/IP</label>
                <input type="text" class="form-control" id="board_host" name="board_host" value="<?php echo h($config['board_host']); ?>" placeholder="192.168.1.50" required>
            </div>
            <div class="mb-2">
                <label for="board_port" class="form-label">Board port</label>
                <input type="number" class="form-control" id="board_port" name="board_port" value="<?php echo h($config['board_port']); ?>">
            </div>
        </fieldset>

        <fieldset class="border rounded p-3 mb-3">
            <legend class="fs-6 fw-bold">Show Schedule</legend>
            <div class="mb-3">
                <label for="fpp_host" class="form-label">FPP host (usually 127.0.0.1, this box)</label>
                <input type="text" class="form-control" id="fpp_host" name="fpp_host" value="<?php echo h($config['fpp_host']); ?>">
            </div>
            <div class="mb-3">
                <label for="poll_interval_seconds" class="form-label">Poll interval (seconds)</label>
                <input type="number" class="form-control" id="poll_interval_seconds" name="poll_interval_seconds" value="<?php echo h($config['poll_interval_seconds']); ?>" min="5">
            </div>
            <div class="mb-3">
                <label for="arm_lead_seconds" class="form-label">Arm this many seconds before a playlist starts</label>
                <input type="number" class="form-control" id="arm_lead_seconds" name="arm_lead_seconds" value="<?php echo h($config['arm_lead_seconds']); ?>" min="0">
                <div class="form-text">Only applies to a real FPP Scheduler entry - bounded by the poll interval above.</div>
            </div>
            <div class="mb-3">
                <label for="disarm_lag_seconds" class="form-label">Keep armed this many seconds after a playlist ends</label>
                <input type="number" class="form-control" id="disarm_lag_seconds" name="disarm_lag_seconds" value="<?php echo h($config['disarm_lag_seconds']); ?>" min="0">
            </div>
            <div class="mb-2">
                <label for="watched_playlists" class="form-label">Playlists to arm for (none selected = arm for any playlist)</label>
                <select class="form-control" id="watched_playlists" name="watched_playlists[]" multiple size="6">
                    <?php foreach ($displayPlaylists as $pl): ?>
                        <option value="<?php echo h($pl); ?>" <?php echo in_array($pl, $config['watched_playlists'], true) ? 'selected' : ''; ?>><?php echo h($pl); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($allPlaylists)): ?>
                    <div class="form-text">Could not reach FPP's local /api/playlists to populate this list<?php echo !empty($config['watched_playlists']) ? ' - showing previously-saved selections only' : ''; ?>.</div>
                <?php endif; ?>
            </div>
        </fieldset>

        <fieldset class="border rounded p-3 mb-3">
            <legend class="fs-6 fw-bold">Alerts (ntfy)</legend>
            <p class="text-muted">Sent only when a show needs the relays armed but the relay board isn't confirmed on. GFCI-trip alerts are the relay board's own job - it has its own notifier for that.</p>

            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" role="switch" id="ntfy_enabled" name="ntfy_enabled" <?php echo $config['notify']['ntfy']['enabled'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="ntfy_enabled">Enable ntfy alerts</label>
            </div>
            <div class="mb-3">
                <label for="ntfy_topic_url" class="form-label">ntfy topic URL</label>
                <input type="text" class="form-control" id="ntfy_topic_url" name="ntfy_topic_url" value="<?php echo h($config['notify']['ntfy']['topic_url']); ?>" placeholder="https://ntfy.sh/your-topic-name">
                <div class="form-text">The full subscribe URL for your topic - e.g. subscribe to it in the ntfy app, then paste that same URL here.</div>
            </div>
            <button type="submit" name="test_ntfy" value="1" class="btn btn-outline-secondary btn-sm">Send Test Alert</button>
        </fieldset>

        <fieldset class="border rounded p-3 mb-3">
            <legend class="fs-6 fw-bold">Website Integration</legend>
            <p class="text-muted">Reports the currently playing song/status to an external site.</p>

            <div class="form-check form-switch mb-3">
                <input type="checkbox" class="form-check-input" role="switch" id="website_enabled" name="website_enabled" <?php echo $config['website']['enabled'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="website_enabled">Enable website reporting</label>
            </div>
            <div class="mb-3">
                <label for="website_url" class="form-label">Update URL</label>
                <input type="text" class="form-control" id="website_url" name="website_url" value="<?php echo h($config['website']['url']); ?>" placeholder="https://example.com/scripts/updateData.php">
            </div>
            <div class="mb-2">
                <label for="website_api_key" class="form-label">API key</label>
                <input type="password" class="form-control" id="website_api_key" name="website_api_key" value="<?php echo h($config['website']['api_key']); ?>" autocomplete="off">
            </div>
        </fieldset>

        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>
