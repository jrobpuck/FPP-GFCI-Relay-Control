#!/usr/bin/env python3
"""
GFCI Relay Control - fppd plugin callback script.

This is FPP's real script-plugin hook mechanism (confirmed against
FalconChristmas/fpp master, src/Plugins.cpp): fppd looks for a file named
`callbacks` (or callbacks.sh/.pl/.php/.py) in the plugin directory, runs
  scripts/eventScript <this-file> --list
once at load to learn which event types it supports (a comma-separated
list on stdout), then on each matching event runs
  scripts/eventScript <this-file> --type playlist --data '<json>'
  scripts/eventScript <this-file> --type lifecycle <startup|shutdown>

There is no `addHookFunction()` / `PlaylistStarted` API in current FPP -
that was never the real mechanism here, and fpp-plugin.php-style hook
registration does not exist in this codebase.

This script is a fast-reacting BACKUP to relay_daemon.py, which is the
primary arm/disarm mechanism (it polls FPP's schedule independently and
does not depend on this callback ever firing). If this script fails or is
never invoked, relay_daemon.py still arms/disarms within one poll
interval, so every code path here fails soft: log and exit 0.
"""
import json
import sys

import gfci_common as gc

SUPPORTED_TYPES = "playlist,lifecycle"


def handle_playlist(logger, data_arg):
    try:
        data = json.loads(data_arg)
    except ValueError:
        logger.warning("Could not parse playlist callback --data payload")
        return

    action = data.get("Action", "")
    name = data.get("name", "")
    if action not in ("start", "stop"):
        return  # "playing" / "query_next" - not a state transition we care about

    cfg = gc.load_config()
    if not gc.playlist_matches(cfg, name):
        return

    if action == "start":
        gc.arm_board(cfg, logger)
    else:
        gc.disarm_board(cfg, logger)


def handle_lifecycle(logger, lifecycle):
    logger.info("fppd %s plugin callbacks", lifecycle)


def main(argv):
    logger = gc.get_logger("callbacks")
    try:
        if "--list" in argv:
            print(SUPPORTED_TYPES)
            return 0

        if "--type" not in argv:
            return 0
        event_type = argv[argv.index("--type") + 1]

        if event_type == "playlist" and "--data" in argv:
            handle_playlist(logger, argv[argv.index("--data") + 1])
        elif event_type == "lifecycle":
            # fppd calls: callbacks --type lifecycle <startup|shutdown>
            lifecycle = argv[argv.index("--type") + 2]
            handle_lifecycle(logger, lifecycle)
    except Exception:  # noqa: BLE001 - must never make fppd's fork/waitpid unhappy
        logger.exception("Unhandled error in callbacks.py argv=%r", argv)
    return 0


if __name__ == "__main__":
    sys.exit(main(sys.argv[1:]))
