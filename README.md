# FPP GFCI Relay Control

An FPP plugin that arms/disarms an external GFCI relay board (see the
companion `GFCI Mainboard` firmware project) around scheduled shows, and
texts/pushes an alert if a show needs the relays armed but the board isn't
confirmed on. It does not react to GFCI trips - the relay board's own
firmware already stops nothing on this side and sends its own trip alert
(see NOTES.md's "Alert redesign" and "Board webhook contract" sections).

Target platform: **Falcon Player (FPP) v10.0 beta 2+**. Can be installed
manually (`install.sh`) or through FPP's Plugin Manager (paste this repo's
URL in) - both paths end up running `scripts/fpp_install.sh`.

See [NOTES.md](NOTES.md) for the FPP v10 plugin-API research this design is
based on.

## Architecture

- **`relay_daemon.py`** - the primary mechanism. Runs as its own systemd
  service (`gfci-relay-daemon`), independent of fppd's lifecycle. Polls
  FPP's public REST API (`GET /api/schedule`, `GET /api/playlists`) and
  calls the relay board's `POST /api/show/start` / `POST /api/show/stop`
  to arm/disarm around the configured show schedule, expanded by
  `arm_lead_seconds` before and `disarm_lag_seconds` after. Also confirms
  (`GET /api/circuits`) the board actually reports itself armed whenever a
  show needs it, and sends the plugin's one alert if it doesn't. Stdlib-only
  - no pip installs, nothing that can break across an FPP OS/Python upgrade.
- **`callbacks.py`** - a fast-reacting backup, invoked directly by fppd via
  its script-plugin callback mechanism (see NOTES.md) on every playlist
  start. Only arms (never disarms - see the module docstring for why);
  non-essential either way, since `relay_daemon.py` arms/disarms within one
  poll interval on its own.
- **`receive_trip.php`** - webhook the relay board POSTs to
  (`/plugin/gfci-relay-control/receive_trip.php`) when it detects a GFCI
  trip. Records the trip for the Status page only - it does not send a
  notification (the board's firmware already has its own) and does not
  stop playback.
- **`notify.py`** - generic ntfy / Twilio SMS / Pushover dispatch, used
  only by `relay_daemon.py`'s board-health alert. Also runnable stand-alone
  to test configured credentials: `python3 notify.py --message "test"`.
- **`content.php`** / **`status.php`** - the Content Setup settings page and
  status page, registered via `menu.inc`.
- **`commands/`** - optional manual "Arm Relays" / "Disarm Relays" FPP
  Commands, callable from playlists, schedules, or Events.

## Install

Either paste this repo's URL into FPP's Plugin Manager, or install by hand:

```bash
git clone <this-repo> /home/fpp/media/tmp/gfci-relay-control-src
sudo /home/fpp/media/tmp/gfci-relay-control-src/install.sh
```

Then in the FPP UI: **Content Setup -> GFCI Relay Control - Settings**, set
the relay board's host/IP, the arm-lead/disarm-lag times, which playlists
should arm the relays, and where the board-not-responding alert should go.

### Settings page not saving?

`config.json`/`state.json`/`trips.json` need to be writable by whichever
user runs FPP's web server (normally `fpp`). If the plugin was installed
before this was fixed in `scripts/fpp_install.sh`, or the install ran as a
different user, fix it directly on the FPP box:

```bash
sudo chown fpp:fpp /home/fpp/media/plugins/gfci-relay-control/{config,state,trips}.json
sudo chmod 664 /home/fpp/media/plugins/gfci-relay-control/{config,state,trips}.json
```

content.php now surfaces a clear error (instead of silently discarding the
save) if this happens again.

## Uninstall

```bash
sudo systemctl disable --now gfci-relay-daemon
sudo rm -rf /home/fpp/media/plugins/gfci-relay-control
```

## Live-test checklist (v10 beta 2)

1. Run `install.sh` (or install via Plugin Manager), confirm no install-time errors.
2. `systemctl status gfci-relay-daemon` - confirm it's running.
3. `tail -f /home/fpp/media/logs/plugin-gfci-relay-control.log` while a
   scheduled or manually-started playlist runs; confirm arm fires from both
   `relay_daemon.py`'s poll loop (check the log line for the
   `arm_lead_seconds`/`disarm_lag_seconds` values it actually read) and
   `callbacks.py`, and disarm (respecting `disarm_lag_seconds`) fires from
   `relay_daemon.py` only.
4. Power off the relay board (or block its port) while a show is running
   and confirm the board-not-responding alert arrives within one poll
   interval, and that Status page shows "Board confirmed on: no".
5. Confirm the "Arm Relays"/"Disarm Relays" commands are selectable in a
   playlist after install (this needs the one fppd restart that
   `fpp_install.sh` flags via `restartFlag`).
