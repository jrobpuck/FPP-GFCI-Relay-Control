#!/usr/bin/env python3
"""
GFCI Relay Control - alert dispatch (ntfy).

FPP does not send GFCI-trip notifications - the relay board's own firmware
notifier already handles that. The one alert this plugin sends is
relay_daemon.py's "a show needs the relays armed but the board isn't
confirmed on" check (see relay_daemon.py's board_confirmed_armed handling).
ntfy is the only channel - see NOTES.md for why Twilio/Pushover were
dropped.

Also runnable as a CLI for testing:
  python3 notify.py --message "test"

Stdlib-only, same reasoning as gfci_common.py.
"""
import argparse
import sys
import urllib.error
import urllib.request

import gfci_common as gc

TITLE = "GFCI Relay Control"


def send_ntfy(cfg, message, logger):
    """Returns (ok, detail) - detail is None on success, else a short
    human-readable reason, so content.php's test button can show it."""
    settings = cfg.get("notify", {}).get("ntfy", {})
    if not settings.get("enabled"):
        return False, "ntfy is not enabled"
    if not settings.get("topic_url"):
        return False, "no ntfy topic URL configured"
    timeout = cfg.get("http_timeout_seconds", 3)
    req = urllib.request.Request(
        settings["topic_url"],
        data=message.encode("utf-8"),
        headers={"Title": TITLE},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout) as resp:
            logger.info("ntfy alert sent")
            return 200 <= resp.status < 300, None
    except urllib.error.HTTPError as e:
        logger.warning("ntfy alert failed: HTTP %s", e.code)
        return False, "HTTP %s from ntfy server" % e.code
    except (urllib.error.URLError, OSError) as e:
        logger.warning("ntfy alert failed: %s", e)
        return False, str(e)


def send_alert(cfg, logger, message):
    return send_ntfy(cfg, message, logger)


def main():
    parser = argparse.ArgumentParser(description="Send a test ntfy alert")
    parser.add_argument("--message", required=True)
    args = parser.parse_args()

    logger = gc.get_logger("notify")
    cfg = gc.load_config()
    try:
        ok, detail = send_alert(cfg, logger, args.message)
    except Exception:  # noqa: BLE001
        logger.exception("Unhandled error sending alert")
        print("FAILED: unhandled error, check the log")
        return 1
    if ok:
        print("OK")
        return 0
    print("FAILED: %s" % detail)
    return 1


if __name__ == "__main__":
    sys.exit(main())
