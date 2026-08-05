#!/bin/sh
set -eu

profile="/etc/openap/repeater.ini"
timeout="${OPENAP_AP_ADDRESS_TIMEOUT:-30}"

case "$timeout" in
  ''|*[!0-9]*)
    echo "Invalid OpenAP AP-address timeout: $timeout" >&2
    exit 1
    ;;
esac

ap_iface="$(awk -F ' *= *' '$1 == "ap" {print $2; exit}' "$profile" 2>/dev/null || true)"
gateway="$(awk -F ' *= *' '$1 == "gateway" {print $2; exit}' "$profile" 2>/dev/null || true)"
mode="$(awk -F ' *= *' '$1 == "current" {print $2; exit}' "$profile" 2>/dev/null || true)"
bridge_iface="$(awk -F ' *= *' '$1 == "bridge" {print $2; exit}' "$profile" 2>/dev/null || true)"

if [ "$mode" = "ap_ethernet_bridge" ]; then
  [ -n "$bridge_iface" ] || bridge_iface=br0
  remaining="$timeout"
  while [ "$remaining" -gt 0 ]; do
    if ip -4 -o addr show dev "$bridge_iface" 2>/dev/null | grep -q ' inet '; then
      exit 0
    fi
    remaining=$((remaining - 1))
    sleep 1
  done
  echo "Timed out waiting for an IPv4 address on OpenAP bridge $bridge_iface" >&2
  exit 1
fi

[ -n "$ap_iface" ] || {
  echo "OpenAP AP interface is not configured in $profile" >&2
  exit 1
}
[ -n "$gateway" ] || {
  echo "OpenAP hotspot gateway is not configured in $profile" >&2
  exit 1
}

remaining="$timeout"
while [ "$remaining" -gt 0 ]; do
  if [ -e "/sys/class/net/$ap_iface" ] \
    && ip -4 -o addr show dev "$ap_iface" 2>/dev/null \
      | awk -v gateway="$gateway" '{ split($4, address, "/"); if (address[1] == gateway) found=1 } END { exit !found }'; then
    exit 0
  fi
  remaining=$((remaining - 1))
  sleep 1
done

echo "Timed out waiting for OpenAP gateway $gateway on $ap_iface" >&2
exit 1
