#!/usr/bin/env python3
"""
GFCI Relay Control - alert dispatch (ntfy / Twilio / Pushover).

FPP does not send GFCI-trip notifications - the relay board's own firmware
notifier already handles that. The one alert this plugin sends is
relay_daemon.py's "a show needs the relays armed but the board isn't
confirmed on" check (see relay_daemon.py's board_confirmed_armed handling).

Also runnable as a CLI for testing configured credentials:
  python3 notify.py --message "test"

Stdlib-only, same reasoning as gfci_common.py.
"""
import argparse
import base64
import sys
import urllib.error
import urllib.parse
import urllib.request

import gfci_common as gc

TITLE = "GFCI Relay Control"


def _post_form(url, fields, timeout, headers=None):
    body = urllib.parse.urlencode(fields).encode("utf-8")
    req_headers = {"Content-Type": "application/x-www-form-urlencoded"}
    req_headers.update(headers or {})
    req = urllib.request.Request(url, data=body, headers=req_headers, method="POST")
    with urllib.request.urlopen(req, timeout=timeout) as resp:
        return 200 <= resp.status < 300


def send_ntfy(cfg, message, logger):
    settings = cfg.get("notify", {}).get("ntfy", {})
    if not settings.get("enabled") or not settings.get("topic_url"):
        return
    timeout = cfg.get("http_timeout_seconds", 3)
    req = urllib.request.Request(
        settings["topic_url"],
        data=message.encode("utf-8"),
        headers={"Title": TITLE},
        method="POST",
    )
    try:
        with urllib.request.urlopen(req, timeout=timeout):
            logger.info("ntfy alert sent")
    except (urllib.error.URLError, OSError) as e:
        logger.warning("ntfy alert failed: %s", e)


def send_twilio(cfg, message, logger):
    settings = cfg.get("notify", {}).get("twilio", {})
    if not settings.get("enabled"):
        return
    sid = settings.get("account_sid", "")
    token = settings.get("auth_token", "")
    if not (sid and token and settings.get("from_number") and settings.get("to_number")):
        logger.warning("Twilio enabled but missing required settings")
        return
    url = "https://api.twilio.com/2010-04-01/Accounts/%s/Messages.json" % sid
    auth = base64.b64encode(("%s:%s" % (sid, token)).encode("utf-8")).decode("ascii")
    fields = {
        "To": settings["to_number"],
        "From": settings["from_number"],
        "Body": message,
    }
    try:
        _post_form(
            url,
            fields,
            cfg.get("http_timeout_seconds", 3),
            headers={"Authorization": "Basic %s" % auth},
        )
        logger.info("Twilio SMS sent")
    except (urllib.error.URLError, OSError) as e:
        logger.warning("Twilio SMS failed: %s", e)


def send_pushover(cfg, message, logger):
    settings = cfg.get("notify", {}).get("pushover", {})
    if not settings.get("enabled") or not (
        settings.get("user_key") and settings.get("app_token")
    ):
        return
    try:
        _post_form(
            "https://api.pushover.net/1/messages.json",
            {
                "token": settings["app_token"],
                "user": settings["user_key"],
                "message": message,
                "title": TITLE,
            },
            cfg.get("http_timeout_seconds", 3),
        )
        logger.info("Pushover alert sent")
    except (urllib.error.URLError, OSError) as e:
        logger.warning("Pushover alert failed: %s", e)


def send_alert(cfg, logger, message):
    send_ntfy(cfg, message, logger)
    send_twilio(cfg, message, logger)
    send_pushover(cfg, message, logger)


def main():
    parser = argparse.ArgumentParser(description="Send a test alert through configured channels")
    parser.add_argument("--message", required=True)
    args = parser.parse_args()

    logger = gc.get_logger("notify")
    cfg = gc.load_config()
    try:
        send_alert(cfg, logger, args.message)
    except Exception:  # noqa: BLE001
        logger.exception("Unhandled error sending alert")
    return 0


if __name__ == "__main__":
    sys.exit(main())
