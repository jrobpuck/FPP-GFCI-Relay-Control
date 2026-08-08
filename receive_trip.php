<?php
/*
 * GFCI Relay Control - trip webhook.
 *
 * The relay board POSTs here directly (see FppClient::notifyTrip() in the
 * board firmware). This endpoint's only job is recording the trip for the
 * Status page - it does not send a notification (the board's own firmware
 * notifier already does that; see NOTES.md) and does not stop playback
 * (also the board's job). It must still return fast, since the board's
 * HTTP client only waits ~2s.
 *
 * Expected body: {"circuit": <int>, "name": "<string>"}
 */

header('Content-Type: application/json');

$pluginDir = __DIR__;
$tripsFile = $pluginDir . '/trips.json';
$logDir = getenv('LOGDIR') ?: '/home/fpp/media/logs';
$logFile = $logDir . '/plugin-gfci-relay-control.log';

function gfci_log($logFile, $msg) {
    $line = '[' . date('c') . '] [receive_trip] ' . $msg . "\n";
    @file_put_contents($logFile, $line, FILE_APPEND);
}

$raw = file_get_contents('php://input');
$data = json_decode($raw, true);

if (!is_array($data) || !isset($data['circuit']) || !isset($data['name'])) {
    gfci_log($logFile, 'Rejected malformed trip payload: ' . substr($raw, 0, 200));
    http_response_code(400);
    echo json_encode(['ok' => false, 'error' => 'expected {"circuit":int,"name":string}']);
    exit;
}

$circuit = (int) $data['circuit'];
$name = substr(preg_replace('/[\x00-\x1F\x7F]/', '', (string) $data['name']), 0, 64);

gfci_log($logFile, "GFCI trip on circuit $circuit ($name)");

// Append to trips.json, keeping only the most recent 50 entries.
$fh = fopen($tripsFile, 'c+');
if ($fh !== false) {
    flock($fh, LOCK_EX);
    $contents = stream_get_contents($fh);
    $trips = json_decode($contents, true);
    if (!is_array($trips)) {
        $trips = [];
    }
    $trips[] = [
        'time' => date('c'),
        'circuit' => $circuit,
        'name' => $name,
    ];
    if (count($trips) > 50) {
        $trips = array_slice($trips, -50);
    }
    ftruncate($fh, 0);
    rewind($fh);
    fwrite($fh, json_encode($trips));
    flock($fh, LOCK_UN);
    fclose($fh);
} else {
    gfci_log($logFile, "Could not open $tripsFile for writing");
}

echo json_encode(['ok' => true]);
