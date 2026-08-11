#!/bin/bash
set -e

# GFCI Relay Control - manual installer.
#
# This plugin is NOT distributed through FPP's Plugin Manager / pluginList.json.
# Run this script once, by hand, on the FPP box after cloning this repo there:
#
#   git clone <this-repo> /home/fpp/media/tmp/gfci-relay-control-src
#   sudo /home/fpp/media/tmp/gfci-relay-control-src/install.sh
#
# It copies the repo into FPP's plugin directory and then runs the same
# scripts/fpp_install.sh lifecycle script FPP's own Plugin Manager would run,
# so the two install paths behave identically from that point on.

REPO_NAME="gfci-relay-control"
SRC_DIR="$(cd "$(dirname "$0")" && pwd)"
FPPDIR="${FPPDIR:-/opt/fpp}"
MEDIADIR="${MEDIADIR:-/home/fpp/media}"
TARGET_DIR="${MEDIADIR}/plugins/${REPO_NAME}"

if [ "$(id -u)" -ne 0 ]; then
    echo "This installs a systemd service and writes under ${MEDIADIR}/plugins - re-run with sudo." >&2
    exit 1
fi

if [ ! -d "${FPPDIR}/scripts" ]; then
    echo "FPPDIR (${FPPDIR}) does not look like an FPP install (no scripts/ dir)." >&2
    echo "Set FPPDIR=/path/to/fpp before running this script if FPP is installed elsewhere." >&2
    exit 1
fi

echo "Installing to ${TARGET_DIR} ..."
mkdir -p "${TARGET_DIR}"
# config.json/state.json/trips.json live under ${MEDIADIR}/plugindata/ (see
# scripts/fpp_install.sh), never here, so there's nothing settings-related
# for this sync to preserve or clobber.
rsync -a --exclude='.git' "${SRC_DIR}/" "${TARGET_DIR}/"

chown -R fpp:fpp "${TARGET_DIR}"

FPPDIR="${FPPDIR}" MEDIADIR="${MEDIADIR}" bash "${TARGET_DIR}/scripts/fpp_install.sh"

echo
echo "Installed. Next steps:"
echo "  1. In the FPP UI: Content Setup -> GFCI Relay Control - Settings, set the board host/IP."
echo "  2. Check the daemon: systemctl status gfci-relay-daemon"
echo "  3. Tail logs: tail -f ${MEDIADIR}/logs/plugin-${REPO_NAME}.log"
