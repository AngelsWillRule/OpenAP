#!/bin/sh
set -eu

profile=/etc/openap/repeater.ini
timeout="${OPENAP_AP_INTERFACE_TIMEOUT:-30}"
action="${1:---prepare}"

case "$action" in
  --prepare|--ensure-address) ;;
  *)
    echo "Invalid OpenAP AP-interface action: $action" >&2
    exit 2
    ;;
esac

case "$timeout" in
  ''|*[!0-9]*)
    echo "Invalid OpenAP AP-interface timeout: $timeout" >&2
    exit 1
    ;;
esac

profile_value() {
  awk -F ' *= *' -v key="$1" '$1 == key {print $2; exit}' "$profile"
}

[ -r "$profile" ] || {
  echo "OpenAP profile is not readable: $profile" >&2
  exit 1
}

ap_iface="$(profile_value ap)"
ap_mac="$(profile_value ap_mac | tr '[:upper:]' '[:lower:]')"
gateway="$(profile_value gateway)"
subnet="$(profile_value subnet)"
prefix="${subnet#*/}"

case "$ap_iface" in
  ''|*[!A-Za-z0-9_.:-]*)
    echo "Invalid OpenAP AP interface: $ap_iface" >&2
    exit 1
    ;;
esac
case "$ap_mac" in
  [0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]:[0-9a-f][0-9a-f]) ;;
  *)
    echo "Invalid OpenAP AP MAC address: $ap_mac" >&2
    exit 1
    ;;
esac
case "$prefix" in
  ''|*[!0-9]*)
    echo "Invalid OpenAP hotspot subnet: $subnet" >&2
    exit 1
    ;;
esac

remaining="$timeout"
while [ "$remaining" -gt 0 ]; do
  if [ -r "/sys/class/net/$ap_iface/address" ]; then
    actual_mac="$(tr '[:upper:]' '[:lower:]' < "/sys/class/net/$ap_iface/address")"
    if [ "$actual_mac" = "$ap_mac" ]; then
      break
    fi
    echo "OpenAP AP interface $ap_iface has unexpected MAC $actual_mac" >&2
    exit 1
  fi
  remaining=$((remaining - 1))
  sleep 1
done

[ -r "/sys/class/net/$ap_iface/address" ] || {
  echo "Timed out waiting for OpenAP AP interface $ap_iface" >&2
  exit 1
}

if [ "$action" = "--ensure-address" ]; then
  # hostapd is already running. Never cycle the link here: doing so terminates
  # the daemon on Raspberry brcmfmac radios. If hostapd preserved the address,
  # the post-start check is intentionally a no-op.
  if /usr/sbin/ip -4 -o address show dev "$ap_iface" \
    | awk -v gateway="$gateway" -v prefix="$prefix" '
        $4 == gateway "/" prefix { found=1 }
        END { exit found ? 0 : 1 }
      '; then
    exit 0
  fi
  /usr/sbin/ip -4 address flush dev "$ap_iface"
  /usr/sbin/ip address add "$gateway/$prefix" dev "$ap_iface"
  /usr/sbin/ip link set dev "$ap_iface" up
else
  /usr/sbin/ip link set dev "$ap_iface" down
  /usr/sbin/ip address flush dev "$ap_iface"
  /usr/sbin/ip address add "$gateway/$prefix" dev "$ap_iface"
  /usr/sbin/ip link set dev "$ap_iface" up
fi

/usr/sbin/ip -4 -o address show dev "$ap_iface" \
  | awk -v gateway="$gateway" '
      { split($4, address, "/"); if (address[1] == gateway) found=1 }
      END { exit found ? 0 : 1 }
    '
