#!/bin/bash
# GFCI Relay Control uninstall script.
#
# Must remove every side effect this plugin left outside its own directory
# (PLUGIN_GUIDELINES.md "Uninstall completeness") and be safe to run more
# than once, since scripts/uninstall_plugin runs this and then
# unconditionally deletes the plugin directory regardless of exit status.
SERVICE_NAME="gfci-relay-daemon"

. ${FPPDIR}/scripts/common

systemctl disable --now ${SERVICE_NAME} 2>/dev/null || true
rm -f /etc/systemd/system/${SERVICE_NAME}.service
systemctl daemon-reload 2>/dev/null || true

# See fpp_install.sh - the reverse of that same restartFlag reasoning, so a
# removed "Arm Relays"/"Disarm Relays" command doesn't linger selectable
# in playlists/schedules/events until fppd happens to restart later.
setSetting restartFlag 1

# Deliberately NOT removing <mediadir>/plugindata/gfci-relay-control/ here:
# it holds config.json/state.json/trips.json (board host, ntfy topic,
# website API key, trip history), and the whole point of keeping those
# outside the plugin directory (see fpp_install.sh) is that a reinstall -
# which is exactly what an uninstall normally precedes - shouldn't lose
# them. Remove it by hand if you actually want the settings gone for good:
#   sudo rm -rf /home/fpp/media/plugindata/gfci-relay-control
