#!/bin/sh
set -eu

if [ "$#" -ne 0 ]; then
  echo "openap-system-reboot does not accept arguments" >&2
  exit 2
fi

if [ "$(id -u)" -ne 0 ]; then
  echo "openap-system-reboot must run as root" >&2
  exit 1
fi

exec /usr/bin/systemd-run \
  --quiet \
  --collect \
  --unit=openap-system-reboot \
  --on-active=2s \
  /bin/systemctl reboot
