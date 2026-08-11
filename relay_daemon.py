#!/usr/bin/env python3
"""
GFCI Relay Control - primary daemon.

Runs as its own systemd service (installed by scripts/fpp_install.sh),
independent of fppd's own start/stop cycle. Polls FPP's public REST API
for the configured schedule and arms/disarms the GFCI relay board's
"show" relays to match, expanded by arm_lead_seconds/disarm_lag_seconds.
This is the primary arm/disarm mechanism; the fppd-side callbacks.py
script is a faster-reacting backup only (arm-on-start; it never disarms -
see callbacks.py's docstring) - if it never fires, shows are still
protected within one poll interval.

Whenever a show requires the relays armed, this also confirms the board
actually reports itself online with relays on (not just that the last
arm command got a 2xx) and sends one alert if it doesn't - this is the
plugin's only outbound notification. GFCI-trip notifications are the
relay board's own job (its firmware has its own SMTP notifier); this
plugin never stops a show or sends a trip alert.

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
import notify

_running = True


def _handle_sigterm(signum, frame):
    global _running
    _running = False


def compute_desired_armed(cfg, logger):
    """Armed if EITHER /api/schedule predicts a matching show right now
    (the only source that knows about arm_lead_seconds/disarm_lag_seconds,
    since only a Scheduler entry has a start time to be "before" or
    "after"), OR FPP is actually playing a matching playlist right now
    regardless of why (manually started, an FPP Command/Event, a test run,
    ...). The second check is what makes "armed" track reality for
    anything that didn't come from a Scheduler entry.

    Returns None only if BOTH checks failed to reach FPP at all, so a
    transient API hiccup doesn't flip an already-armed show back off.
    """
    now = datetime.datetime.now()
    lead = cfg.get("arm_lead_seconds", 0)
    lag = cfg.get("disarm_lag_seconds", 0)

    schedule_reachable = True
    schedule_active = False
    try:
        schedule = gc.fpp_get_json(cfg, "/api/schedule")
        for entry in schedule:
            playlist = entry.get("playlist", "")
            if not gc.playlist_matches(cfg, playlist):
                continue
            if gc.schedule_entry_active(entry, now, lead, lag, logger):
                schedule_active = True
                break
    except Exception as e:  # noqa: BLE001 - transient network/HTTP errors are routine here
        logger.warning("Could not fetch /api/schedule: %s", e)
        schedule_reachable = False

    player_reachable = True
    currently_playing = False
    name = ""
    try:
        name = gc.current_playlist_name(cfg)
        currently_playing = bool(name) and gc.playlist_matches(cfg, name)
    except Exception as e:  # noqa: BLE001
        logger.warning("Could not fetch /api/player/status: %s", e)
        player_reachable = False

    logger.info(
        "poll: schedule_active=%s currently_playing=%s (lead=%ss lag=%ss)",
        schedule_active if schedule_reachable else "unreachable",
        currently_playing if player_reachable else "unreachable",
        lead,
        lag,
    )

    show_status = "playing" if currently_playing else ("idle" if schedule_active else "stopped")
    gc.report_website_status(cfg, logger, name, show_status)   # <-- add this

    if not schedule_reachable and not player_reachable:
        return None
    return schedule_active or currently_playing


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
    board_alert_sent = False

    while _running:
        cfg = gc.load_config()
        desired = compute_desired_armed(cfg, logger)

        

        if desired is not None and desired != armed:
            logger.info(
                "Desired armed state: %s -> %s (arm_lead_seconds=%s disarm_lag_seconds=%s)",
                armed,
                desired,
                cfg.get("arm_lead_seconds", 0),
                cfg.get("disarm_lag_seconds", 0),
            )
            ok = gc.arm_board(cfg, logger) if desired else gc.disarm_board(cfg, logger)
            if ok:
                armed = desired

        # Confirm the board actually reflects "armed", not just that our
        # last POST got a 2xx - this is what catches the board going
        # unreachable (or a relay not actually energizing) sometime after
        # that, including during the arm_lead_seconds window before a show.
        board_confirmed = None
        if desired:
            board_confirmed = gc.board_confirmed_armed(cfg, logger)
            if not board_confirmed and not board_alert_sent:
                logger.warning("Show requires relays armed but board is not confirmed on")
                notify.send_alert(
                    cfg,
                    logger,
                    "GFCI Relay Control: a show needs the relays armed, but the "
                    "relay board is not responding or not confirmed on.",
                )
                board_alert_sent = True
            elif board_confirmed:
                board_alert_sent = False
        else:
            board_alert_sent = False

        now_ts = time.time()
        if now_ts - last_playlist_check > 300:
            check_watched_playlists_exist(cfg, logger)
            last_playlist_check = now_ts

        gc.write_state(
            {
                "armed": armed,
                "board_confirmed": board_confirmed,
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
