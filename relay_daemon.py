#!/usr/bin/env python3
"""
GFCI Relay Control - primary daemon.

Runs as its own systemd service (installed by scripts/fpp_install.sh),
independent of fppd's own start/stop cycle. Polls FPP's public REST API
for the configured schedule and arms/disarms the GFCI relay board's
"show" relays to match. This is the primary arm/disarm mechanism; the
fppd-side callbacks.py script is a faster-reacting backup only - if it
never fires (or fppd's plugin-callback ABI changes out from under it in
some future FPP release), shows are still protected within one poll
interval.

Deliberately stdlib-only: this process must keep working across FPP
version upgrades that change the plugin-framework internals, so it does
not touch fppd's plugin API, PHP, or any third-party package that could
disappear from a future base image.
"""
import datetime
import signal
import sys
import time

import gfci_common as gc

_running = True


def _handle_sigterm(signum, frame):
    global _running
    _running = False


def compute_desired_armed(cfg, logger):
    try:
        schedule = gc.fpp_get_json(cfg, "/api/schedule")
    except Exception as e:  # noqa: BLE001 - transient network/HTTP errors are routine here
        logger.warning("Could not fetch /api/schedule: %s", e)
        return None

    now = datetime.datetime.now()
    lead = cfg.get("arm_lead_seconds", 0)
    lag = cfg.get("disarm_lag_seconds", 0)
    for entry in schedule:
        playlist = entry.get("playlist", "")
        if not gc.playlist_matches(cfg, playlist):
            continue
        if gc.schedule_entry_active(entry, now, lead, lag, logger):
            return True
    return False


def check_watched_playlists_exist(cfg, logger):
    watched = cfg.get("watched_playlists") or []
    if not watched:
        return
    try:
        playlists = gc.fpp_get_json(cfg, "/api/playlists")
    except Exception as e:  # noqa: BLE001
        logger.warning("Could not fetch /api/playlists: %s", e)
        return
    for name in watched:
        if name not in playlists:
            logger.warning(
                "Configured watched playlist %r does not match any playlist FPP currently knows about",
                name,
            )


def main():
    signal.signal(signal.SIGTERM, _handle_sigterm)
    logger = gc.get_logger("relay_daemon")
    logger.info("GFCI relay daemon starting")

    armed = None  # unknown until first successful poll
    last_playlist_check = 0.0

    while _running:
        cfg = gc.load_config()
        desired = compute_desired_armed(cfg, logger)

        if desired is not None and desired != armed:
            ok = gc.arm_board(cfg, logger) if desired else gc.disarm_board(cfg, logger)
            if ok:
                armed = desired

        now_ts = time.time()
        if now_ts - last_playlist_check > 300:
            check_watched_playlists_exist(cfg, logger)
            last_playlist_check = now_ts

        gc.write_state(
            {
                "armed": armed,
                "last_poll": datetime.datetime.now().isoformat(timespec="seconds"),
            }
        )

        interval = cfg.get("poll_interval_seconds", 30)
        for _ in range(int(max(interval, 1))):
            if not _running:
                break
            time.sleep(1)

    logger.info("GFCI relay daemon stopping")


if __name__ == "__main__":
    sys.exit(main())
