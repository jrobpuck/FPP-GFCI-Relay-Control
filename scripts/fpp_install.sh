#!/bin/bash
set -e

# GFCI Relay Control install script.
#
# Runs from <plugindir>/scripts/fpp_install.sh - resolve the plugin's own
# directory relative to this script rather than assuming any particular
# caller/cwd, since this plugin is installed manually (install.sh) rather
# than through FPP's git-clone Plugin Manager flow.
PLUGINDIR="$(cd "$(dirname "$0")/.." && pwd)"
SERVICE_NAME="gfci-relay-daemon"

. ${FPPDIR}/scripts/common

chmod +x "$PLUGINDIR"/commands/*.sh
chmod +x "$PLUGINDIR"/relay_daemon.py "$PLUGINDIR"/callbacks.py "$PLUGINDIR"/notify.py

if [ ! -f "$PLUGINDIR/config.json" ]; then
    cp "$PLUGINDIR/config.example.json" "$PLUGINDIR/config.json"
fi
touch "$PLUGINDIR/state.json" "$PLUGINDIR/trips.json"

# This script may run as root (Plugin Manager needs root for the systemd
# unit below), but content.php and receive_trip.php are written to by
# whatever user runs FPP's web server - usually 'fpp', not root. A
# root-owned, mode-644 config.json is readable but not writable by that
# user, which makes the settings page look like it silently ignores every
# save. Fix ownership/mode on the files those pages actually write to,
# regardless of which user this install script itself ran as.
chown fpp:fpp "$PLUGINDIR/config.json" "$PLUGINDIR/state.json" "$PLUGINDIR/trips.json" 2>/dev/null || true
chmod 664 "$PLUGINDIR/config.json" "$PLUGINDIR/state.json" "$PLUGINDIR/trips.json"

cat > /etc/systemd/system/${SERVICE_NAME}.service <<EOF
[Unit]
Description=GFCI Relay Control daemon (arms/disarms relay board around FPP shows)
After=network-online.target
Wants=network-online.target

[Service]
Type=simple
User=fpp
ExecStart=/usr/bin/python3 ${PLUGINDIR}/relay_daemon.py
Restart=on-failure
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

systemctl daemon-reload
systemctl enable --now ${SERVICE_NAME}

# fppd only reads commands/descriptions.json at its own startup
# (PluginManager::loadUserPlugins(), src/Plugins.cpp), never on
# install/reinstall - flag a restart so the "Arm Relays"/"Disarm Relays"
# commands this plugin ships become selectable in playlists/schedules/
# events without the user having to know to restart fppd themselves.
setSetting restartFlag 1
