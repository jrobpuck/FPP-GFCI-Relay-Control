"""
Shared helpers for the GFCI Relay Control plugin's Python scripts
(relay_daemon.py, callbacks.py, notify.py).

Stdlib only - no external deps, so nothing here needs a pip install step
on the FPP box, and nothing here breaks across an OS/Python upgrade.
"""
import datetime
import json
import logging
import os
import urllib.error
import urllib.request

PLUGIN_DIR = os.path.dirname(os.path.abspath(__file__))
CONFIG_PATH = os.path.join(PLUGIN_DIR, "config.json")
STATE_PATH = os.path.join(PLUGIN_DIR, "state.json")
TRIPS_PATH = os.path.join(PLUGIN_DIR, "trips.json")
REPO_NAME = "gfci-relay-control"

DEFAULT_CONFIG = {
    "fpp_host": "127.0.0.1",
    "board_host": "",
    "board_port": 80,
    "watched_playlists": [],
    "poll_interval_seconds": 30,
    "arm_lead_seconds": 0,
    "disarm_lag_seconds": 0,
    "http_timeout_seconds": 3,
    "notify": {
        "ntfy": {"enabled": False, "topic_url": ""},
        "twilio": {
            "enabled": False,
            "account_sid": "",
            "auth_token": "",
            "from_number": "",
            "to_number": "",
        },
        "pushover": {"enabled": False, "user_key": "", "app_token": ""},
    },
}


def _deep_merge_defaults(cfg, defaults):
    for key, value in defaults.items():
        if key not in cfg:
            cfg[key] = value
        elif isinstance(value, dict) and isinstance(cfg.get(key), dict):
            _deep_merge_defaults(cfg[key], value)
    return cfg


def load_config():
    cfg = dict(DEFAULT_CONFIG)
    if os.path.exists(CONFIG_PATH):
        try:
            with open(CONFIG_PATH, "r", encoding="utf-8") as f:
                on_disk = json.load(f)
            cfg = _deep_merge_defaults(on_disk, DEFAULT_CONFIG)
        except (OSError, ValueError):
            logging.getLogger("gfci").exception(
                "Failed to read %s, falling back to defaults", CONFIG_PATH
            )
    return cfg


def get_logger(name):
    logdir = os.environ.get("LOGDIR", "/home/fpp/media/logs")
    logger = logging.getLogger(name)
    if logger.handlers:
        return logger
    logger.setLevel(logging.INFO)
    try:
        handler = logging.FileHandler(
            os.path.join(logdir, "plugin-%s.log" % REPO_NAME)
        )
    except OSError:
        handler = logging.StreamHandler()
    handler.setFormatter(
        logging.Formatter("%(asctime)s [%(name)s] %(levelname)s: %(message)s")
    )
    logger.addHandler(handler)
    return logger


def _board_url(cfg, path):
    host = cfg.get("board_host", "")
    port = cfg.get("board_port", 80)
    if not host:
        return None
    if port and int(port) != 80:
        return "http://%s:%s%s" % (host, port, path)
    return "http://%s%s" % (host, path)


def _post(url, timeout, body=b"{}"):
    req = urllib.request.Request(
        url, data=body, method="POST", headers={"Content-Type": "application/json"}
    )
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return 200 <= resp.status < 300


def _board_action(cfg, logger, path, verb):
    url = _board_url(cfg, path)
    if not url:
        logger.warning("board_host not configured, cannot %s relays", verb)
        return False
    timeout = cfg.get("http_timeout_seconds", 3)
    try:
        ok = _post(url, timeout)
        logger.info("%s relays via %s -> %s", verb, url, "ok" if ok else "non-2xx")
        return ok
    except (urllib.error.URLError, OSError) as e:
        logger.warning("%s relays via %s failed: %s", verb, url, e)
        return False


def arm_board(cfg, logger):
    return _board_action(cfg, logger, "/api/show/start", "arm")


def disarm_board(cfg, logger):
    return _board_action(cfg, logger, "/api/show/stop", "disarm")


def fpp_get_json(cfg, path):
    host = cfg.get("fpp_host", "127.0.0.1")
    timeout = cfg.get("http_timeout_seconds", 3)
    url = "http://%s%s" % (host, path)
    with urllib.request.urlopen(url, timeout=timeout) as resp:
        return json.loads(resp.read().decode("utf-8"))


def playlist_matches(cfg, name):
    watched = cfg.get("watched_playlists") or []
    if not watched:
        return True
    return name in watched


# FPP schedule "day" codes (src/ScheduleEntry.h): 0=Sun..6=Sat, 7=Everyday,
# 8=Weekdays, 9=Weekend, 10=Mon/Wed/Fri, 11=Tue/Thu, 12=Sun-Thu, 13=Fri/Sat,
# 14=OddDay, 15=EvenDay.
def _day_code_matches(day_code, py_weekday, day_of_year):
    if day_code == 0:
        return py_weekday == 6
    if 1 <= day_code <= 6:
        return py_weekday == day_code - 1
    if day_code == 7:
        return True
    if day_code == 8:
        return py_weekday <= 4
    if day_code == 9:
        return py_weekday >= 5
    if day_code == 10:
        return py_weekday in (0, 2, 4)
    if day_code == 11:
        return py_weekday in (1, 3)
    if day_code == 12:
        return py_weekday in (6, 0, 1, 2, 3)
    if day_code == 13:
        return py_weekday in (4, 5)
    if day_code == 14:
        return day_of_year % 2 == 1
    if day_code == 15:
        return day_of_year % 2 == 0
    return False


def _parse_date(s):
    return datetime.datetime.strptime(s, "%Y-%m-%d").date()


def _parse_time(s):
    return datetime.datetime.strptime(s, "%H:%M:%S").time()


def schedule_entry_active(entry, now, lead_seconds=0, lag_seconds=0, logger=None):
    """Return True if this /api/schedule entry - expanded by arm_lead_seconds
    before its start and disarm_lag_seconds after its end - covers `now`.

    Works in full datetimes rather than time-of-day comparisons so a lead
    time pulling the window into the previous day, an overnight show, and a
    lag time pushing the window into the next day all fall out of the same
    logic instead of needing separate special cases.
    """
    try:
        if not entry.get("enabled"):
            return False
        start_date = _parse_date(entry["startDate"])
        end_date = _parse_date(entry["endDate"])
        start_t = _parse_time(entry["startTime"])
        end_t = _parse_time(entry["endTime"])
        day_code = int(entry["day"])
        lead = datetime.timedelta(seconds=max(lead_seconds, 0))
        lag = datetime.timedelta(seconds=max(lag_seconds, 0))

        # The entry's day-code can anchor its occurrence on "yesterday" (an
        # overnight show, or a lead time pulling the window earlier) as well
        # as "today" - check both.
        for anchor in (now.date() - datetime.timedelta(days=1), now.date()):
            if not (start_date <= anchor <= end_date):
                continue
            if not _day_code_matches(
                day_code, anchor.weekday(), anchor.timetuple().tm_yday
            ):
                continue
            start_dt = datetime.datetime.combine(anchor, start_t)
            end_dt = datetime.datetime.combine(anchor, end_t)
            if end_dt <= start_dt:
                end_dt += datetime.timedelta(days=1)  # overnight show
            if (start_dt - lead) <= now < (end_dt + lag):
                return True
        return False
    except (KeyError, ValueError) as e:
        if logger:
            logger.warning("Skipping unparseable schedule entry %r: %s", entry, e)
        return False


def write_state(state):
    tmp = STATE_PATH + ".tmp"
    with open(tmp, "w", encoding="utf-8") as f:
        json.dump(state, f)
    os.replace(tmp, STATE_PATH)
