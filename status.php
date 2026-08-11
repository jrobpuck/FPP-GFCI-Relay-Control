<?php
/*
 * GFCI Relay Control - status page.
 *
 * Shows relay_daemon.py's last known state (state.json, written every poll)
 * and the recent GFCI trip history (trips.json, appended by receive_trip.php).
 * Both live under <mediadir>/plugindata/gfci-relay-control/ (see
 * gfci_paths.php), not the plugin's own directory - see NOTES.md.
 */

require_once __DIR__ . '/gfci_paths.php';

$dataDir = gfci_data_dir();

function h($s) {
    return htmlspecialchars((string) $s, ENT_QUOTES);
}

$state = [];
$stateFile = $dataDir . '/state.json';
if (file_exists($stateFile)) {
    $decoded = json_decode(file_get_contents($stateFile), true);
    if (is_array($decoded)) {
        $state = $decoded;
    }
}

$trips = [];
$tripsFile = $dataDir . '/trips.json';
if (file_exists($tripsFile)) {
    $decoded = json_decode(file_get_contents($tripsFile), true);
    if (is_array($decoded)) {
        $trips = array_reverse($decoded);
    }
}

$staleSecs = null;
if (!empty($state['last_poll'])) {
    $staleSecs = time() - strtotime($state['last_poll']);
}
?>

<div class="mt-2">
    <fieldset class="border rounded p-2 mb-3">
        <legend>Relay Daemon</legend>
        <?php if (empty($state)): ?>
            <div class="alert alert-warning">No state.json yet - relay_daemon.py may not have started. Check
                <code>systemctl status gfci-relay-daemon</code> on the FPP box.</div>
        <?php else: ?>
            <p>
                Armed:
                <?php if ($state['armed'] === true): ?>
                    <span class="badge bg-danger">ARMED</span>
                <?php elseif ($state['armed'] === false): ?>
                    <span class="badge bg-secondary">disarmed</span>
                <?php else: ?>
                    <span class="badge bg-warning">unknown</span>
                <?php endif; ?>
            </p>
            <p>Last poll: <?php echo h($state['last_poll'] ?? 'never'); ?>
                <?php if ($staleSecs !== null && $staleSecs > 300): ?>
                    <span class="text-danger">(<?php echo h($staleSecs); ?>s ago - daemon may be stuck or stopped)</span>
                <?php endif; ?>
            </p>
            <?php if (array_key_exists('board_confirmed', $state) && $state['board_confirmed'] !== null): ?>
                <p>
                    Board confirmed on:
                    <?php if ($state['board_confirmed'] === true): ?>
                        <span class="badge bg-success">yes</span>
                    <?php else: ?>
                        <span class="badge bg-danger">no - not responding or relay not actually on</span>
                    <?php endif; ?>
                </p>
            <?php endif; ?>
        <?php endif; ?>
    </fieldset>

    <fieldset class="border rounded p-2 mb-3">
        <legend>Recent GFCI Trips</legend>
        <?php if (empty($trips)): ?>
            <p class="text-muted">No trips recorded.</p>
        <?php else: ?>
            <table class="table table-sm">
                <thead><tr><th>Time</th><th>Circuit</th><th>Name</th></tr></thead>
                <tbody>
                <?php foreach ($trips as $t): ?>
                    <tr>
                        <td><?php echo h($t['time'] ?? ''); ?></td>
                        <td><?php echo h($t['circuit'] ?? ''); ?></td>
                        <td><?php echo h($t['name'] ?? ''); ?></td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        <?php endif; ?>
    </fieldset>
</div>
