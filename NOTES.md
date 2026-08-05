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

## Verification checklist

See the "Live-test checklist" section in [README.md](README.md) - nothing
below this point has been run against a live v10 beta 2 box yet, only
verified against source.
