<?php
/*
 * GFCI Relay Control - shared data directory path (content.php, status.php,
 * receive_trip.php all include this).
 *
 * config.json/state.json/trips.json live under <mediadir>/plugindata/, NOT
 * in the plugin's own directory. A Plugin Manager update/reinstall deletes
 * the whole plugin directory and re-clones it from scratch - anything
 * stored inside it is destroyed on every update. plugindata/ is the one
 * location PLUGIN_GUIDELINES.md documents as surviving that. See NOTES.md.
 * Matches gfci_common.py's DATA_DIR on the Python side.
 */

function gfci_data_dir() {
    $mediaDir = getenv('MEDIADIR') ?: '/home/fpp/media';
    $dir = $mediaDir . '/plugindata/gfci-relay-control';
    if (!is_dir($dir)) {
        @mkdir($dir, 0775, true);
    }
    return $dir;
}
