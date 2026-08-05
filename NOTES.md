# FPP v10 Plugin API - Research Notes

This supersedes the earlier `FPP_V10_NOTES.md` draft (written before any of
this was verified against source). Everything below was confirmed by
reading `FalconChristmas/fpp` @ `master` directly (`src/Plugins.cpp`,
`src/playlist/Playlist.cpp`, `scripts/eventScript`, `www/api/openapi.json`)
and the current `fpp-plugin-Template` repo, on 2026-08-05. Original
open questions are answered inline.

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
`/plugin/gfci-relay-control/receive_trip.php` with a ~2s client timeout,
*after* it has already called `/api/playlists/stop` itself. This plugin's
job on trip is notification only, not stopping playback - the board does
that directly. `receive_trip.php` responds immediately and dispatches
`notify.py` in the background so it never risks hitting the board's
timeout.

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
