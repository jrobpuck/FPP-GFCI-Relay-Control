<?php
/*
 * GFCI Relay Control - settings page (Content Setup menu).
 *
 * Reads/writes config.json directly in the plugin's own directory, per
 * PLUGIN_GUIDELINES.md ("config only within the plugin dir / config/plugin.
 * <repoName> / plugindata/"). This plugin uses the plugin-dir option since
 * relay_daemon.py (a plain systemd-run Python process, not an fppd plugin
 * object) needs to read the same file directly.
 */

$pluginDir = __DIR__;
$configFile = $pluginDir . '/config.json';

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
        'twilio' => [
            'enabled' => false, 'account_sid' => '', 'auth_token' => '',
            'from_number' => '', 'to_number' => '',
        ],
        'pushover' => ['enabled' => false, 'user_key' => '', 'app_token' => ''],
    ],
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

    $config['notify']['twilio']['enabled'] = isset($_POST['twilio_enabled']);
    $config['notify']['twilio']['account_sid'] = trim($_POST['twilio_account_sid'] ?? '');
    $config['notify']['twilio']['auth_token'] = trim($_POST['twilio_auth_token'] ?? '');
    $config['notify']['twilio']['from_number'] = trim($_POST['twilio_from_number'] ?? '');
    $config['notify']['twilio']['to_number'] = trim($_POST['twilio_to_number'] ?? '');

    $config['notify']['pushover']['enabled'] = isset($_POST['pushover_enabled']);
    $config['notify']['pushover']['user_key'] = trim($_POST['pushover_user_key'] ?? '');
    $config['notify']['pushover']['app_token'] = trim($_POST['pushover_app_token'] ?? '');

    if ($config['board_host'] === '') {
        $error = 'Board host/IP is required.';
    } else {
        $bytes = @file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT));
        if ($bytes === false) {
            $error = "Could not write $configFile - the web server user can't write to the plugin "
                . "directory. On the FPP box, run: sudo chown -R fpp:fpp " . dirname($configFile)
                . " && sudo chmod 664 $configFile";
        } else {
            $saved = true;
        }
    }
} else {
    $config = gfci_load_config($configFile, $defaultConfig);
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

    <form method="post">
        <fieldset class="border rounded p-2 mb-3">
            <legend>Relay Board</legend>
            <div class="mb-2">
                <label>Board host/IP</label>
                <input type="text" class="form-control" name="board_host" value="<?php echo h($config['board_host']); ?>" placeholder="192.168.1.50" required>
            </div>
            <div class="mb-2">
                <label>Board port</label>
                <input type="number" class="form-control" name="board_port" value="<?php echo h($config['board_port']); ?>">
            </div>
        </fieldset>

        <fieldset class="border rounded p-2 mb-3">
            <legend>Show Schedule</legend>
            <div class="mb-2">
                <label>FPP host (usually 127.0.0.1, this box)</label>
                <input type="text" class="form-control" name="fpp_host" value="<?php echo h($config['fpp_host']); ?>">
            </div>
            <div class="mb-2">
                <label>Poll interval (seconds)</label>
                <input type="number" class="form-control" name="poll_interval_seconds" value="<?php echo h($config['poll_interval_seconds']); ?>" min="5">
            </div>
            <div class="mb-2">
                <label>Arm this many seconds before a playlist starts</label>
                <input type="number" class="form-control" name="arm_lead_seconds" value="<?php echo h($config['arm_lead_seconds']); ?>" min="0">
                <small class="text-muted">Bounded by the poll interval above - the relays won't actually energize more than one poll cycle later than requested.</small>
            </div>
            <div class="mb-2">
                <label>Keep armed this many seconds after a playlist ends</label>
                <input type="number" class="form-control" name="disarm_lag_seconds" value="<?php echo h($config['disarm_lag_seconds']); ?>" min="0">
            </div>
            <div class="mb-2">
                <label>Playlists to arm for (none selected = arm for any scheduled playlist)</label>
                <select class="form-control" name="watched_playlists[]" multiple size="6">
                    <?php foreach ($displayPlaylists as $pl): ?>
                        <option value="<?php echo h($pl); ?>" <?php echo in_array($pl, $config['watched_playlists'], true) ? 'selected' : ''; ?>><?php echo h($pl); ?></option>
                    <?php endforeach; ?>
                </select>
                <?php if (empty($allPlaylists)): ?>
                    <small class="text-muted">Could not reach FPP's local /api/playlists to populate this list<?php echo !empty($config['watched_playlists']) ? ' - showing previously-saved selections only' : ''; ?>.</small>
                <?php endif; ?>
            </div>
        </fieldset>

        <fieldset class="border rounded p-2 mb-3">
            <legend>Trip Notifications</legend>

            <div class="form-check mb-1">
                <input type="checkbox" class="form-check-input" name="ntfy_enabled" id="ntfy_enabled" <?php echo $config['notify']['ntfy']['enabled'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="ntfy_enabled">ntfy</label>
            </div>
            <div class="mb-2">
                <input type="text" class="form-control" name="ntfy_topic_url" value="<?php echo h($config['notify']['ntfy']['topic_url']); ?>" placeholder="https://ntfy.sh/your-topic">
            </div>

            <div class="form-check mb-1">
                <input type="checkbox" class="form-check-input" name="twilio_enabled" id="twilio_enabled" <?php echo $config['notify']['twilio']['enabled'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="twilio_enabled">Twilio SMS</label>
            </div>
            <div class="mb-1">
                <input type="text" class="form-control" name="twilio_account_sid" value="<?php echo h($config['notify']['twilio']['account_sid']); ?>" placeholder="Account SID">
            </div>
            <div class="mb-1">
                <input type="password" class="form-control" name="twilio_auth_token" value="<?php echo h($config['notify']['twilio']['auth_token']); ?>" placeholder="Auth token">
            </div>
            <div class="mb-1">
                <input type="text" class="form-control" name="twilio_from_number" value="<?php echo h($config['notify']['twilio']['from_number']); ?>" placeholder="From number (+1...)">
            </div>
            <div class="mb-2">
                <input type="text" class="form-control" name="twilio_to_number" value="<?php echo h($config['notify']['twilio']['to_number']); ?>" placeholder="To number (+1...)">
            </div>

            <div class="form-check mb-1">
                <input type="checkbox" class="form-check-input" name="pushover_enabled" id="pushover_enabled" <?php echo $config['notify']['pushover']['enabled'] ? 'checked' : ''; ?>>
                <label class="form-check-label" for="pushover_enabled">Pushover</label>
            </div>
            <div class="mb-1">
                <input type="text" class="form-control" name="pushover_user_key" value="<?php echo h($config['notify']['pushover']['user_key']); ?>" placeholder="User key">
            </div>
            <div class="mb-2">
                <input type="text" class="form-control" name="pushover_app_token" value="<?php echo h($config['notify']['pushover']['app_token']); ?>" placeholder="Application token">
            </div>
        </fieldset>

        <button type="submit" class="btn btn-primary">Save</button>
    </form>
</div>
