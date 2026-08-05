#!/bin/sh
set -eu

mode=new
if [ "${1:-}" = "--saved" ]; then
  mode=saved
  iface="${2:-}"
  requested_ssid="${3:-}"
  tmp_conf=""
else
  iface="${1:-}"
  tmp_conf="${2:-}"
  requested_ssid=""
fi

fail() {
  echo "$1" >&2
  exit 1
}

echo "$iface" | grep -Eq '^[A-Za-z0-9_.:-]+$' || fail "Invalid uplink interface"
[ -e "/sys/class/net/$iface" ] || fail "Uplink interface not found"

is_wireless_iface() {
  iface="$1"
  [ -d "/sys/class/net/$iface/wireless" ] && return 0
  iw dev 2>/dev/null | awk '$1 == "Interface" {print $2}' | grep -Fxq "$iface"
}

is_wireless_iface "$iface" || fail "Uplink interface is not wireless"

configured_uplink="$(awk -F ' *= *' '$1 == "uplink" {print $2}' /etc/openap/repeater.ini 2>/dev/null | head -1)"
configured_uplink_mac="$(awk -F ' *= *' '$1 == "uplink_mac" {print tolower($2)}' /etc/openap/repeater.ini 2>/dev/null | head -1)"
iface_mac="$(tr A-F a-f < "/sys/class/net/$iface/address")"
owned_by_openap=0
[ -n "$configured_uplink" ] && [ "$configured_uplink" = "$iface" ] && owned_by_openap=1
[ -n "$configured_uplink_mac" ] && [ "$configured_uplink_mac" != "-" ] && [ "$configured_uplink_mac" = "$iface_mac" ] && owned_by_openap=1
if [ "$owned_by_openap" -eq 0 ]; then
  active_ssid="$(iw dev "$iface" link 2>/dev/null | awk -F 'SSID: ' '/SSID:/ {print $2; exit}')"
  active_ipv4="$(ip -4 -o addr show dev "$iface" 2>/dev/null | awk '{print $4; exit}')"
  [ -z "$active_ssid" ] && [ -z "$active_ipv4" ] \
    || fail "Uplink interface $iface is already connected and is not assigned to OpenAP"
fi

dest="/etc/wpa_supplicant/wpa_supplicant-${iface}.conf"
store_dir="/etc/openap/uplinks"
network_conf="/etc/systemd/network/30-${iface}-sta.network"
backup_dir="/etc/openap/backups/uplink-wifi-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup_dir"
install -d -m 0750 -o root -g www-data "$store_dir"
[ -e "$dest" ] && cp -a "$dest" "$backup_dir/"
[ -e /etc/systemd/system/openap-uplink.service ] && cp -a /etc/systemd/system/openap-uplink.service "$backup_dir/"
[ -e "$network_conf" ] && cp -a "$network_conf" "$backup_dir/"

profile_ssid() {
  sed -n 's/^[[:space:]]*ssid="\(.*\)"[[:space:]]*$/\1/p' "$1" | head -1
}

store_profile() {
  profile="$1"
  ssid="$(profile_ssid "$profile")"
  [ -n "$ssid" ] || fail "Uplink profile has no SSID"
  key="$(printf %s "$ssid" | sha256sum | awk '{print $1}')"
  install -m 0640 -o root -g www-data "$profile" "$store_dir/$key.conf"
}

if [ "$mode" = new ] && [ -f "$dest" ]; then
  store_profile "$dest"
fi

if [ "$mode" = saved ]; then
  [ -n "$requested_ssid" ] || fail "Saved uplink SSID is required"
  key="$(printf %s "$requested_ssid" | sha256sum | awk '{print $1}')"
  source_conf="$store_dir/$key.conf"
  [ -f "$source_conf" ] || fail "Saved uplink profile not found"
  [ "$(profile_ssid "$source_conf")" = "$requested_ssid" ] || fail "Saved uplink profile mismatch"
else
  case "$tmp_conf" in
    /tmp/openap-uplink-*.conf) : ;;
    *) fail "Invalid temporary config path" ;;
  esac
  [ -f "$tmp_conf" ] || fail "Temporary config not found"
  source_conf="$tmp_conf"
fi

install -m 0640 -o root -g www-data "$source_conf" "$dest"
[ "$mode" = saved ] || rm -f "$tmp_conf"
target_ssid="$(profile_ssid "$dest")"
[ -n "$target_ssid" ] || fail "Uplink profile has no SSID"

cat > "$network_conf" <<EOF
[Match]
Name=$iface

[Link]
RequiredForOnline=no

[Network]
DHCP=yes
IPv6AcceptRA=yes

[DHCPv4]
RouteMetric=100
UseDNS=yes
EOF

cat > /etc/systemd/system/openap-uplink.service <<EOF
[Unit]
Description=OpenAP upstream WiFi client on $iface
After=systemd-udev-settle.service systemd-networkd.service
Wants=systemd-udev-settle.service systemd-networkd.service
Before=network-online.target

[Service]
Type=simple
ExecStartPre=/bin/sh -c 'for i in \$(seq 1 30); do [ -e /sys/class/net/$iface ] && exit 0; sleep 1; done; exit 1'
ExecStartPre=/bin/sh -c '/usr/sbin/iw dev $iface set power_save off 2>/dev/null || true'
ExecStart=/sbin/wpa_supplicant -c $dest -i $iface
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

chmod 0644 /etc/systemd/system/openap-uplink.service
chmod 0644 "$network_conf"
systemctl daemon-reload
systemctl restart systemd-networkd.service
systemctl enable openap-uplink.service
systemctl restart openap-uplink.service

attempt_invocation=""
invocation_tries=20
while [ "$invocation_tries" -gt 0 ]; do
  attempt_invocation="$(systemctl show openap-uplink.service -p InvocationID --value 2>/dev/null)"
  [ -n "$attempt_invocation" ] && break
  invocation_tries=$((invocation_tries - 1))
  sleep 0.1
done
[ -n "$attempt_invocation" ] || fail "Unable to identify the new uplink service attempt"

attempt_events() {
  journalctl --no-pager _SYSTEMD_INVOCATION_ID="$attempt_invocation" 2>/dev/null
}

restore_rejected_candidate() {
  systemctl disable --now openap-uplink.service >/dev/null 2>&1 || true
  if [ "$mode" = new ]; then
    previous_conf="$backup_dir/$(basename "$dest")"
    if [ -f "$previous_conf" ]; then
      install -m 0640 -o root -g www-data "$previous_conf" "$dest"
    else
      rm -f "$dest"
    fi
  fi
}

# A fresh wpa_supplicant CONNECTED event is mandatory. Live link and IPv4
# state alone may briefly belong to the preceding service invocation.
tries=180
while [ "$tries" -gt 0 ]; do
  live_ssid="$(iw dev "$iface" link 2>/dev/null | awk -F 'SSID: ' '/SSID:/ {print $2; exit}')"
  if attempt_events | grep -q 'CTRL-EVENT-CONNECTED' \
    && [ "$live_ssid" = "$target_ssid" ] \
    && ip -4 -o addr show dev "$iface" | grep -q ' inet '; then
    [ "$mode" = saved ] || store_profile "$dest"
    echo "Uplink connected on $iface"
    echo "Backup: $backup_dir"
    exit 0
  fi
  if [ $((tries % 4)) -eq 0 ] \
    && attempt_events | grep -Eq 'reason=WRONG_KEY|4-Way Handshake failed'; then
    restore_rejected_candidate
    echo "Connection failed: the WiFi password was rejected. Check the password and try again; a weak signal or access-point policy may also prevent authentication." >&2
    exit 3
  fi
  tries=$((tries - 1))
  sleep 0.25
done

if attempt_events | grep -q 'CTRL-EVENT-CONNECTED'; then
  failure_message="Connection failed after authentication: no IPv4 address was obtained. DHCP may be unavailable or too slow."
elif [ -n "$live_ssid" ]; then
  failure_message="Connection failed before authentication completed. Check the password, signal strength and access-point settings."
else
  failure_message="Connection failed: the network may be unavailable, its signal may be too weak, or its security settings may have changed. Use Edit password if the network is no longer open."
fi
restore_rejected_candidate
echo "$failure_message" >&2
exit 4
