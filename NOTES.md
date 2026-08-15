# FPP v10 Plugin API - Research Notes

This supersedes the earlier `FPP_V10_NOTES.md` draft (written before any of
this was verified against source). Everything below was confirmed by
reading `FalconChristmas/fpp` @ `master` directly (`src/Plugins.cpp`,
`src/playlist/Playlist.cpp`, `scripts/eventScript`, `www/api/openapi.json`)
and the current `fpp-plugin-Template` repo, on 2026-08-05. Original
open questions are answered inline.

## Website integration reported the playlist name, not the song (found 2026-08-15)

`relay_daemon.py` was passing `current_playlist_name()`'s result (the
*playlist*, e.g. "Main Show") into `report_website_status()`'s `song`
parameter. `Playlist::GetCurrentStatus()` (see below) actually exposes the
playing media/song's filename as a separate top-level `current_song` field,
sibling to `current_playlist`, not nested under it. `gfci_common.py`'s
single-status-fetch helper was split into `current_status(cfg)` (the raw
`GET /api/fppd/status` response) plus two small extractors -
`current_playlist_name(status)` for arm/disarm matching and
`current_song_name(status)` for what actually gets reported to the
website - both pulled from one fetch rather than two.

## config.json wiped on every plugin update (found 2026-08-10)

`board_host` (and everything else in `config.json`) came back empty after
what should have been an unrelated code update, breaking arm/disarm
entirely (`board_host not configured, cannot arm/disarm relays` in the
log). Root cause: `config.json`/`state.json`/`trips.json` were living
inside the plugin's own directory
(`/home/fpp/media/plugins/gfci-relay-control/`), and per
`PLUGIN_GUIDELINES.md`'s install lifecycle, `scripts/uninstall_plugin` runs
`fpp_uninstall.sh` and then **unconditionally deletes the whole plugin
directory** - which is exactly what happens on every Plugin Manager
update/reinstall, not just a real uninstall. Every push to this repo that
reached the Pi via Plugin Manager was silently destroying the user's
settings.

Fixed by moving all three files to
`<mediadir>/plugindata/gfci-relay-control/` - the location
`PLUGIN_GUIDELINES.md` documents as surviving exactly this
(`gfci_common.py`'s `DATA_DIR`, `gfci_paths.php`'s `gfci_data_dir()`, both
independently computing the same path from `$MEDIADIR`/`getenv('MEDIADIR')`
so there's one place per language, not one per file). `scripts/
fpp_install.sh` migrates any files still sitting in the old plugin-dir
location on first run after this fix (one-time, only fires if the
directory hasn't already been wiped by a reinstall since); `scripts/
fpp_uninstall.sh` deliberately does *not* remove `plugindata/` - that's the
whole point of moving it there, since uninstall normally precedes a
reinstall. This also happens to be a cleaner permission story: the daemon
(systemd, `User=fpp`) and the web pages (also `fpp`, normally) each create
these files themselves as `fpp` on first actual use now, rather than
`fpp_install.sh` pre-seeding a file that might run as root depending on how
the install was triggered - see the entry below for how that class of bug
showed up before this fix existed.

## The old design's `addHookFunction()` / `fpp-plugin.php` does not exist

There is no `addHookFunction()` API and no `PlaylistStarted`/`PlaylistFinished`
hook-name convention anywhere in current FPP. That was never the real
mechanism - not "removed in v10", just not a thing. The real mechanism,
unchanged in shape for a long time and still current in the v10 master
branch, is a **`callbacks` script** in the plugin's root directory:

- File must be named `callbacks` or `callbacks.{sh,pl,php,py}` - fppd's
  `PluginManager::loadUserPlugin()` (`src/Plugins.cpp`) looks for exactly
  that name.
- On load, fppd runs `${FPPDIR}/scripts/eventScript <callbacks-file> --list`
  and reads a comma-separated list of supported event types from stdout
  (we return `playlist,lifecycle`).
- `eventScript` (`scripts/eventScript`) is a tiny dispatcher: it picks the
  interpreter by the callback file's extension (`.py` → `python3`, `.php` →
  `php`, etc.) and `exec`s it with the remaining args - so `callbacks.py`
  receives plain argv, no special FPP-side runtime.
- On a playlist event, fppd runs:
  `eventScript <callbacks-file> --type playlist --data '<json>'`
  The JSON is `Playlist::GetInfo()`'s output (`name`, `desc`, `repeat`,
  `loop`, `loopCount`, `random`, `blankAtEnd`, `size`, `currentEntry`,
  `currentState`) plus `Action` (one of `start`, `playing`, `stop`,
  `query_next` - confirmed from every `PluginManager::playlistCallback()`
  call site in `src/playlist/Playlist.cpp`), `Section`, `Item`.
- On lifecycle events (plugin load/unload, i.e. fppd start/stop):
  `eventScript <callbacks-file> --type lifecycle <startup|shutdown>`.

`callbacks.py` in this repo implements exactly this contract.

## `plugin.json` vs `pluginInfo.json`

Confirmed: `pluginInfo.json` + `versions[]` (with `minFPPVersion`/
`maxFPPVersion`/`branch`/`sha`) is the current format, used by the
git-clone-based Plugin Manager. Since this plugin is installed manually
(`install.sh` copies files in directly, no git-clone-on-install), the
`versions[]`/`srcURL` fields in `pluginInfo.json` are never actually read by
FPP for this plugin - they're kept only as human-readable metadata and for
forward-compatibility if this ever moves to Plugin Manager distribution
later.

## `/api/schedule` and `/api/playlists` response shapes (confirmed via `www/api/openapi.json`)

Two *different* pairs of endpoints share similar-looking names - easy to
confuse:

- `/api/fppd/schedule`, `/api/fppd/playlists` - fppd's own internal API
  (historically port 32322, now proxied). `playlists` here means "the
  playlist(s) currently playing", not the full list. **Do not use these** -
  `PLUGIN_GUIDELINES.md` explicitly says never to call fppd's internal port
  directly; use the public, documented API instead.
- `/api/schedule` (public, what this plugin uses) - returns a bare JSON
  array of the full schedule config (`config/schedule.json`), one object
  per entry:
  ```json
  {
    "day": 7, "enabled": 0, "startDate": "2014-01-01", "endDate": "2099-12-31",
    "startTime": "17:00:00", "endTime": "23:00:00", "playlist": "Main Show",
    "repeat": 1, "stopType": 0
  }
  ```
- `/api/playlists` (public, what this plugin uses) - returns a bare JSON
  array of playlist name strings: `["Playlist_1", "Playlist_2", ...]`.

`day` codes, confirmed from `src/ScheduleEntry.h`:
`0`=Sunday ... `6`=Saturday, `7`=Everyday, `8`=Weekdays, `9`=Weekend,
`10`=Mon/Wed/Fri, `11`=Tue/Thu, `12`=Sun-Thu, `13`=Fri/Sat, `14`=OddDay,
`15`=EvenDay. `gfci_common.py`'s `_day_code_matches()` implements all of
these.

## Board webhook contract (confirmed from firmware `FppClient.cpp`)

The relay board POSTs `{"circuit": <int>, "name": "<string>"}` to
`/plugin/gfci-relay-control/receive_trip.php` with a ~2s client timeout.
`receive_trip.php` only records the trip for the Status page now - it does
not send a notification (the board's own firmware notifier already does
that) and never has stopped playback itself (the board's firmware currently
calls `/api/playlists/stop` directly in `FppClient::notifyTrip()` - that is
a firmware change, out of scope for this repo; see NOTES.md's "Alert
redesign" entry below).

## Alert redesign (after first live use)

Originally this plugin also sent an SMS/push on every GFCI trip
(`notify_trip()` in `notify.py`, called from `receive_trip.php`). Removed:
the relay board's own firmware already has an SMTP notifier for trips
(`Notifier.cpp` / `/api/notify/config` in the firmware), so FPP sending a
second alert for the same event was redundant. `notify.py` is now a
generic `send_alert(cfg, logger, message)` used for exactly one thing: the
board-health check below. Kept it importable directly by `relay_daemon.py`
(no more `receive_trip.php` shelling out to it) and still runnable
stand-alone (`python3 notify.py --message "..."`) to test configured
credentials.

Simplified further afterward to ntfy-only, dropping Twilio/Pushover
entirely (unused, more settings-page surface than the one alert needed).
`send_ntfy()`/`send_alert()` now return `(ok, detail)` instead of just
logging, so `content.php`'s "Send Test Alert" button can shell out to
`notify.py` and show the real failure reason inline - useful since the
board-health alert only fires on an actual board-not-responding condition,
which isn't something to wait around for just to confirm ntfy delivery
works. Old `config.json` files with leftover `twilio`/`pushover` keys are
harmless - nothing reads them anymore.

Whether the board's firmware should still call `/api/playlists/stop` on a
trip is a firmware question (`FppClient.cpp`, a different project -
`GFCI Mainboard`), not something this repo controls.

## Board-armed health check (added after first live use)

`relay_daemon.py` now confirms the board actually reports itself armed
whenever a show needs it (`gfci_common.board_confirmed_armed()`: GET
`/api/circuits`, require every entry's `online` and `relay` both true) -
not just that the last `POST /api/show/start` returned 2xx. This is what
catches the board going unreachable, or a relay not actually energizing,
sometime after the daemon believed it had armed - including during the
`arm_lead_seconds` window before a show, which is exactly the case that
prompted this (relays were expected 5 minutes early and didn't confirm).
One alert per incident (cleared once confirmed healthy again, or once the
show no longer needs the relays armed), to avoid repeating every poll.

## `armed` never went true / lead didn't fire (found on second live test)

`compute_desired_armed()` only ever asked "does `/api/schedule` predict a
show right now" - it never checked whether FPP was actually playing
anything. A playlist/sequence started manually (or via an FPP Command or
Event, or any path that isn't a configured Scheduler entry) has no
`/api/schedule` entry at all, so the daemon stayed at `armed: false` in
`state.json` the entire time even while `callbacks.py`'s separate
start-triggered hook correctly turned the relays on - looking from the
Status page like the daemon was permanently stuck disarmed.

Fixed by adding a second, independent signal: `GET /api/fppd/status`
(`gfci_common.current_playlist_name()`), which reports what FPP is
*actually* playing right now regardless of how it started.
`compute_desired_armed()` now returns true if *either* the schedule
predicts a matching show *or* FPP is actually playing a matching one; only
returns `None` (meaning: don't change the current armed state) if both
`/api/schedule` and `/api/fppd/status` were unreachable this poll, so a
transient API hiccup can't flip an already-armed show back off.

Important consequence: `arm_lead_seconds`/`disarm_lag_seconds` can only
ever come from the `/api/schedule` branch - there is no "5 minutes before"
for a start FPP didn't know about in advance. Testing lead/lag requires an
actual FPP Scheduler entry (Status/Control -> Scheduler) with a real future
start time, not a manually-started playlist/sequence. `relay_daemon.py`
now logs `schedule_active`/`currently_playing` (and the lead/lag values it
read) on every poll specifically so this is diagnosable from
`plugin-gfci-relay-control.log` without guessing.

`/api/fppd/status` traces directly to real code: `PlayerResource::
GetCurrentStatus()` -> `GetCurrentFPPDStatus()` (`httpAPI.cpp`) ->
`Playlist::GetCurrentStatus()`, which sets `current_playlist.playlist` to
`""` when idle or the playing playlist's name otherwise. This originally
used `/api/player/status` instead, on the assumption it was an alias (same
openapi.json description text, similarly-named) - that was never actually
confirmed against a controller, and manual-playback testing showed it
wasn't being detected, consistent with that endpoint either not existing
or returning a different shape. Switched to the one actually traced.
Also worth correcting from earlier notes: the "never call fppd's internal
port :32322 directly" guideline is about the raw TCP port, not about
avoiding URL paths that happen to contain `fppd` - `/api/fppd/status` is a
normal documented HTTP path over the standard web port.

## Arm lead / disarm lag (added after first live install)

`schedule_entry_active()` in `gfci_common.py` expands each schedule entry's
window by `arm_lead_seconds` before `startTime` and `disarm_lag_seconds`
after `endTime`, computed as full datetimes (not time-of-day arithmetic) so
overnight shows and a lead pulling the window into the previous day both
fall out of the same code path. Precision is bounded by
`poll_interval_seconds`, since `relay_daemon.py` only re-evaluates the
window on each poll.

`callbacks.py`'s fast-path used to disarm immediately on a playlist "stop"
event; that's now removed, since it would race `relay_daemon.py`'s
lag-respecting disarm and cut it short. Arm-on-"start" is unaffected -
lead time doesn't apply there anyway, since a "start" event fires after the
show has already begun.

## Settings page not persisting (found on first live install)

First real install (via Plugin Manager, after the srcURL fix below) showed
`content.php` accepting the form but not persisting `board_host` or
`watched_playlists`. Two independent bugs, both fixed:

1. `scripts/fpp_install.sh` created `config.json` under whatever user ran
   the install (Plugin Manager needs root for the systemd unit), while
   `content.php` writes as the FPP web server's user (normally `fpp`) - a
   root-owned mode-644 file is readable but not writable by that user, so
   `file_put_contents()` failed silently (return value was never checked).
   Fixed by explicitly `chown fpp:fpp` + `chmod 664`-ing `config.json`,
   `state.json`, `trips.json` at the end of install, and by having
   `content.php` check the write's return value and surface a real error
   instead of reporting success regardless.
2. The playlist `<select>` was populated only from a live
   `GET /api/playlists` call. If that call failed (e.g. `allow_url_fopen`
   disabled, in which case `file_get_contents('http://...')` fails
   silently), the list rendered with zero `<option>`s, so a previously
   saved `watched_playlists` selection had nothing to render as "selected"
   - it looked unsaved even when `config.json` was correct. Fixed by
   fetching via curl (works regardless of `allow_url_fopen`) and by
   unioning the live list with whatever's already in `config.json`, so a
   saved selection is never invisible just because the live fetch failed.

## Plugin Manager install (`srcURL`)

The Plugin Manager's "paste a URL" install path does a real `git clone
<srcURL> <branch>` (`install_plugin PLUGINNAME GITURL BRANCH SHA1`) even
for a plugin that's normally installed manually - `pluginInfo.json`'s
`srcURL`/`homeURL`/`bugURL` must point at a real, pushed remote or that
clone fails outright (`ERROR: failed to clone plugin`). Filled in from this
repo's actual `origin` remote.

## Verification checklist

See the "Live-test checklist" section in [README.md](README.md) - nothing
below this point has been run against a live v10 beta 2 box yet, only
verified against source.
