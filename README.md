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
  service (`gfci-relay-daemon`), independent of fppd's lifecycle. Arms
  around a show if *either* `GET /api/schedule` predicts one right now
  (expanded by `arm_lead_seconds`/`disarm_lag_seconds` - the only source
  that has a start time to be "before" or "after") *or* `GET
  /api/fppd/status` says FPP is actually playing a matching playlist
  right now, however it started. Calls the relay board's `POST
  /api/show/start` / `POST /api/show/stop` to arm/disarm, and confirms
  (`GET /api/circuits`) the board actually reports itself armed, sending
  the plugin's one alert if it doesn't. Stdlib-only - no pip installs,
  nothing that can break across an FPP OS/Python upgrade.
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
- **`notify.py`** - ntfy dispatch, used only by `relay_daemon.py`'s
  board-health alert. Also runnable stand-alone to test the configured
  topic: `python3 notify.py --message "test"` (prints `OK` or
  `FAILED: <reason>`) - the settings page's "Send Test Alert" button
  shells out to this same script.
- **`content.php`** / **`status.php`** - the Content Setup settings page and
  status page, registered via `menu.inc`.
- **`gfci_paths.php`** / `gfci_common.py`'s `DATA_DIR` - both independently
  compute `<mediadir>/plugindata/gfci-relay-control/`, where
  `config.json`/`state.json`/`trips.json` actually live. Deliberately not
  inside the plugin's own directory - see "Settings keep disappearing?"
  below.
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

### Settings keep disappearing after an update?

Fixed - `config.json`/`state.json`/`trips.json` used to live inside the
plugin's own directory, which a Plugin Manager update/reinstall deletes and
re-clones from scratch, silently wiping every setting. They now live under
`/home/fpp/media/plugindata/gfci-relay-control/`, which survives that. See
NOTES.md's "config.json wiped on every plugin update" entry. If you hit
this before the fix: just re-enter your settings once more after updating
to a version that includes it - they'll stick from here on.

### Settings page not saving?

The files under `/home/fpp/media/plugindata/gfci-relay-control/` need to
be writable by whichever user runs FPP's web server (normally `fpp`). Fix
it directly on the FPP box:

```bash
sudo chown -R fpp:fpp /home/fpp/media/plugindata/gfci-relay-control
sudo chmod -R u+rwX,g+rwX /home/fpp/media/plugindata/gfci-relay-control
```

content.php surfaces a clear error (instead of silently discarding the
save) if this happens.

### Status page stuck on "disarmed"?

Fixed - `relay_daemon.py` used to only trust `/api/schedule`, so anything
that wasn't a real Scheduler entry (a manually-started playlist, an FPP
Command/Event, a test run) never registered as armed even while the relays
were actually on. It now also checks `GET /api/fppd/status` for what FPP
is really playing. See NOTES.md's "`armed` never went true" entry.

### Arm-lead/disarm-lag not firing?

Only `/api/schedule` entries have a start time to apply lead/lag to - a
manually-started playlist/sequence has no "5 minutes before" to arm
against. Test with a real FPP Scheduler entry (Status/Control ->
Scheduler), not a manual play. `tail -f
/home/fpp/media/logs/plugin-gfci-relay-control.log` for the
`schedule_active`/`currently_playing`/lead/lag values the daemon read on
each poll.

## Uninstall

```bash
sudo systemctl disable --now gfci-relay-daemon
sudo rm -rf /home/fpp/media/plugins/gfci-relay-control
```

This intentionally leaves `/home/fpp/media/plugindata/gfci-relay-control/`
(your settings) in place, so a later reinstall picks up where you left
off. Remove it too if you actually want the settings gone for good.

## Live-test checklist (v10 beta 2)

1. Run `install.sh` (or install via Plugin Manager), confirm no install-time errors.
2. `systemctl status gfci-relay-daemon` - confirm it's running.
3. Create a real Scheduler entry a few minutes out with `arm_lead_seconds`
   set; `tail -f /home/fpp/media/logs/plugin-gfci-relay-control.log` and
   confirm `schedule_active` flips true `arm_lead_seconds` before the
   entry's start time, and disarm (respecting `disarm_lag_seconds`) fires
   after it ends.
4. Separately, manually start a watched playlist (no Scheduler entry) and
   confirm `currently_playing` flips true and the Status page shows ARMED
   - this is the fast path `callbacks.py` also covers.
5. Power off the relay board (or block its port) while a show is running
   and confirm the board-not-responding alert arrives within one poll
   interval, and that Status page shows "Board confirmed on: no".
6. Confirm the "Arm Relays"/"Disarm Relays" commands are selectable in a
   playlist after install (this needs the one fppd restart that
   `fpp_install.sh` flags via `restartFlag`).
7. Update the plugin (Plugin Manager "Reinstall" or re-run `install.sh`)
   and confirm settings are still there afterward.
