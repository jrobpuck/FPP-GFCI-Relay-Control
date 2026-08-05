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
