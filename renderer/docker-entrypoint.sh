#!/bin/sh
# Starts the virtual display for the headed Chrome, then the renderer. Everything writes to /tmp only
# (read-only root filesystem; /tmp and /dev/shm are emptyDirs).
set -eu
export DISPLAY="${DISPLAY:-:99}"
mkdir -p /tmp/.X11-unix /tmp/home
Xvfb "$DISPLAY" -screen 0 1920x1080x24 -nolisten tcp -ac >/tmp/xvfb.log 2>&1 &
# wait until the display answers (at most 10 s)
for attempt in 1 2 3 4 5 6 7 8 9 10 11 12 13 14 15 16 17 18 19 20; do
  if [ -e "/tmp/.X11-unix/X${DISPLAY#:}" ]; then
    break
  fi
  sleep 0.5
done
exec node /app/server.mjs "$@"
