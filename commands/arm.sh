#!/bin/bash
# Manual "Arm Relays" FPP Command - callable from playlists/schedules/events,
# on top of relay_daemon.py's automatic schedule-based arm/disarm.
DIR="$(cd "$(dirname "$0")/.." && pwd)"
exec python3 -c "
import sys
sys.path.insert(0, '$DIR')
import gfci_common as gc
cfg = gc.load_config()
logger = gc.get_logger('command')
gc.arm_board(cfg, logger)
"
