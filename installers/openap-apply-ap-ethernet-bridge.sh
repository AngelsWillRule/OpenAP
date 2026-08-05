#!/bin/sh
set -eu

action="${1:---apply}"
eth_iface="${2:-}"
gateway_arg="${4:-}"
ap_mac="$(printf '%s' "${3:-}" | tr 'A-F' 'a-f')"
bridge_iface="br0"
bridge_con="openap-bridge"
bridge_port_con="openap-bridge-${eth_iface}"
profile=/etc/openap/repeater.ini
hostapd_conf=/etc/hostapd/hostapd.conf
if [ -f /etc/openap/hostapd/hostapd.conf ]; then
  hostapd_conf=/etc/openap/hostapd/hostapd.conf
fi
nft_conf=/etc/openap/networking/openap.nft
dnsmasq_conf=/etc/dnsmasq.d/openap-repeater.conf

fail() { echo "$1" >&2; exit 1; }
ini_value() { awk -F ' *= *' -v key="$1" '$1 == key {print $2; exit}' "$profile" 2>/dev/null; }
is_wireless() { [ -d "/sys/class/net/$1/wireless" ] || iw dev 2>/dev/null | awk '$1=="Interface"{print $2}' | grep -Fxq "$1"; }

case "$action" in --apply|--apply-delayed|--gateway|--gateway-delayed|--routed-delayed|--restore-routed|--validate-only|--disable) ;; *) fail "Invalid bridge action";; esac
printf '%s' "$eth_iface" | grep -Eq '^[A-Za-z0-9_.:-]+$' || fail "Invalid Ethernet interface"
[ "$eth_iface" != lo ] || fail "Invalid Ethernet interface"
[ -e "/sys/class/net/$eth_iface" ] || fail "Ethernet interface not found"
! is_wireless "$eth_iface" || fail "Bridge uplink must be Ethernet"
[ "$(cat "/sys/class/net/$eth_iface/type" 2>/dev/null)" = 1 ] || fail "Interface is not Ethernet"
if command -v nmcli >/dev/null 2>&1 && systemctl is-active --quiet NetworkManager.service; then
  network_backend=NetworkManager
elif systemctl is-active --quiet systemd-networkd.service; then
  network_backend=systemd-networkd
else
  fail "Neither NetworkManager nor systemd-networkd is available for bridge mode"
fi

if [ "$action" != --disable ]; then
  printf '%s' "$ap_mac" | grep -Eqi '^([0-9a-f]{2}:){5}[0-9a-f]{2}$' || fail "Invalid AP MAC address"
  ap_iface=""
  for net_path in /sys/class/net/*; do
    [ -r "$net_path/address" ] || continue
    [ "$(tr 'A-F' 'a-f' < "$net_path/address")" = "$ap_mac" ] || continue
    ap_iface="$(basename "$net_path")"; break
  done
  [ -n "$ap_iface" ] || fail "Selected AP interface not found"
  is_wireless "$ap_iface" || fail "Selected AP interface is not wireless"
  iw phy "$(iw dev "$ap_iface" info 2>/dev/null | awk '$1=="wiphy"{print "phy"$2;exit}')" info 2>/dev/null | grep -Eq '^[[:space:]]+\* AP$' || fail "Selected WiFi interface is not AP-capable"
  [ "$(cat "/sys/class/net/$eth_iface/carrier" 2>/dev/null || echo 0)" = 1 ] || fail "Ethernet carrier is down"
  printf '%s' "$gateway_arg" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || fail "Invalid management gateway"
  python3 - "$eth_iface" "$gateway_arg" <<'PY' || fail "Management gateway is not on the Ethernet subnet"
import ipaddress
import subprocess
import sys

iface, gateway = sys.argv[1:]
gateway_ip = ipaddress.IPv4Address(gateway)
output = subprocess.check_output(
    ["ip", "-4", "-o", "address", "show", "dev", iface, "scope", "global"],
    text=True,
)
if not output.strip() and iface != "br0":
    output = subprocess.check_output(
        ["ip", "-4", "-o", "address", "show", "dev", "br0", "scope", "global"],
        text=True,
    )
networks = [ipaddress.IPv4Interface(line.split()[3]).network for line in output.splitlines()]
raise SystemExit(0 if any(gateway_ip in network for network in networks) else 1)
PY
fi

if [ "$action" = --apply-delayed ]; then
  unit="openap-ethernet-bridge-apply-$(date +%s)"
  systemd-run --quiet --collect --unit="$unit" --on-active=2s \
    /usr/local/sbin/openap-apply-ap-ethernet-bridge --apply "$eth_iface" "$ap_mac" "$gateway_arg"
  echo "Ethernet Bridge activation scheduled"
  exit 0
fi

if [ "$action" = --gateway-delayed ]; then
  unit="openap-ethernet-bridge-gateway-$(date +%s)"
  systemd-run --quiet --collect --unit="$unit" --on-active=2s \
    /usr/local/sbin/openap-apply-ap-ethernet-bridge --gateway "$eth_iface" "$ap_mac" "$gateway_arg"
  echo "Ethernet Bridge management gateway update scheduled"
  exit 0
fi

if [ "$action" = --routed-delayed ]; then
  printf '%s' "$gateway_arg" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || fail "Invalid gateway"
  unit="openap-ethernet-routed-apply-$(date +%s)"
  systemd-run --quiet --collect --unit="$unit" --on-active=2s \
    /usr/local/sbin/openap-apply-ap-ethernet-bridge --restore-routed "$eth_iface" "$ap_mac" "$gateway_arg"
  echo "Routed AP Ethernet activation scheduled"
  exit 0
fi

if [ "$action" = --restore-routed ]; then
  /usr/local/sbin/openap-apply-ap-ethernet-bridge --disable "$eth_iface" "$ap_mac"
  exec /usr/local/sbin/openap-apply-ap-ethernet --apply "$eth_iface" "$gateway_arg" "$ap_mac"
fi

if [ "$network_backend" = systemd-networkd ]; then
  exec /usr/local/sbin/openap-apply-ap-ethernet-bridge-networkd \
    "$action" "$eth_iface" "$ap_mac" "$gateway_arg"
fi

if [ "$action" = --gateway ]; then
  [ "$(ini_value current)" = ap_ethernet_bridge ] || fail "Ethernet Bridge is not active"
  nmcli -t -f NAME connection show | grep -Fxq "$bridge_con" || fail "OpenAP bridge connection not found"
  method="$(nmcli -g ipv4.method connection show "$bridge_con")"
  if [ "$method" = manual ]; then
    nmcli connection modify "$bridge_con" ipv4.gateway "$gateway_arg"
  else
    nmcli connection modify "$bridge_con" ipv4.ignore-auto-routes yes ipv4.routes "0.0.0.0/0 $gateway_arg 50"
  fi
  nmcli connection up "$bridge_con" >/dev/null
  tries=10
  while [ "$tries" -gt 0 ]; do
    ip -4 route show default dev "$bridge_iface" | grep -Eq "^default via $gateway_arg([[:space:]]|$)" && break
    tries=$((tries-1)); sleep 1
  done
  [ "$tries" -gt 0 ] || fail "Bridge management gateway did not converge"
  profile_tmp="$(mktemp)"
  awk -v gateway="$gateway_arg" 'BEGIN{done=0} /^ethernet_gateway[[:space:]]*=/{print "ethernet_gateway = " gateway;done=1;next} {print} END{if(!done)print "ethernet_gateway = " gateway}' "$profile" > "$profile_tmp"
  chown www-data:www-data "$profile_tmp"; chmod 0640 "$profile_tmp"; mv "$profile_tmp" "$profile"
  echo "Ethernet Bridge management gateway updated: $gateway_arg"
  exit 0
fi

original_con="$(nmcli -g GENERAL.CONNECTION device show "$eth_iface" 2>/dev/null | head -1)"
[ "$original_con" != -- ] || original_con=""
if [ -z "$original_con" ]; then original_con="$(ini_value ethernet_connection)"; fi

if [ "$action" = --validate-only ]; then
  [ -n "$original_con" ] || fail "No active Ethernet connection profile"
  method="$(nmcli -g ipv4.method connection show "$original_con")"
  case "$method" in auto|manual) ;; *) fail "Unsupported Ethernet IPv4 method: $method";; esac
  echo "Ethernet Bridge validation passed"
  echo "Bridge: $bridge_iface; Ethernet: $eth_iface; AP: $ap_iface"
  echo "IPv4 configuration source: $original_con ($method)"
  echo "Upstream router will provide DHCP to WiFi clients"
  echo "No changes were applied"
  exit 0
fi

if [ "$action" = --disable ]; then
  saved_con="$(ini_value ethernet_connection)"
  [ -n "$saved_con" ] || saved_con="$original_con"
  systemctl stop hostapd.service >/dev/null 2>&1 || true
  nmcli connection down "$bridge_port_con" >/dev/null 2>&1 || true
  nmcli connection down "$bridge_con" >/dev/null 2>&1 || true
  nmcli connection delete "$bridge_port_con" >/dev/null 2>&1 || true
  nmcli connection delete "$bridge_con" >/dev/null 2>&1 || true
  if [ -n "$saved_con" ] && nmcli -t -f NAME connection show | grep -Fxq "$saved_con"; then
    nmcli connection modify "$saved_con" connection.autoconnect yes
    nmcli connection up "$saved_con" >/dev/null
  fi
  if [ -f "$hostapd_conf" ]; then
    sed -i '/^bridge=br0$/d' "$hostapd_conf"
  fi
  echo "Ethernet bridge disabled; restored connection: ${saved_con:-unknown}"
  exit 0
fi

[ -n "$original_con" ] || fail "No active Ethernet connection profile"
method="$(nmcli -g ipv4.method connection show "$original_con")"
case "$method" in auto|manual) ;; *) fail "Unsupported Ethernet IPv4 method: $method";; esac
addresses="$(nmcli -g ipv4.addresses connection show "$original_con" | paste -sd, -)"
gateway="$gateway_arg"
dns="$(nmcli -g ipv4.dns connection show "$original_con" | paste -sd, -)"
eth_mac="$(tr 'A-F' 'a-f' < "/sys/class/net/$eth_iface/address")"
profile_uplink="$(ini_value uplink)"
profile_uplink_mac="$(ini_value uplink_mac)"
profile_subnet="$(ini_value subnet)"
profile_gateway="$(ini_value gateway)"
profile_dhcp_start="$(ini_value dhcp_start)"
profile_dhcp_end="$(ini_value dhcp_end)"
profile_country="$(ini_value country)"
backup_dir="/etc/openap/backups/ap-ethernet-bridge-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup_dir"
for path in "$profile" "$hostapd_conf" "$nft_conf" "$dnsmasq_conf"; do [ ! -e "$path" ] || cp -a "$path" "$backup_dir/"; done
nmcli connection show "$original_con" > "$backup_dir/original-ethernet-connection.txt"

rollback() {
  rc=$?
  trap - EXIT INT TERM
  [ "$rc" -eq 0 ] && return 0
  systemctl stop hostapd.service >/dev/null 2>&1 || true
  nmcli connection down "$bridge_port_con" >/dev/null 2>&1 || true
  nmcli connection down "$bridge_con" >/dev/null 2>&1 || true
  nmcli connection delete "$bridge_port_con" >/dev/null 2>&1 || true
  nmcli connection delete "$bridge_con" >/dev/null 2>&1 || true
  nmcli connection modify "$original_con" connection.autoconnect yes >/dev/null 2>&1 || true
  nmcli connection up "$original_con" >/dev/null 2>&1 || true
  [ ! -f "$backup_dir/hostapd.conf" ] || cp -a "$backup_dir/hostapd.conf" "$hostapd_conf"
  [ ! -f "$backup_dir/openap.nft" ] || cp -a "$backup_dir/openap.nft" "$nft_conf"
  [ ! -f "$backup_dir/openap-repeater.conf" ] || cp -a "$backup_dir/openap-repeater.conf" "$dnsmasq_conf"
  [ ! -f "$backup_dir/repeater.ini" ] || cp -a "$backup_dir/repeater.ini" "$profile"
  systemctl restart openap-firewall.service >/dev/null 2>&1 || true
  systemctl restart dnsmasq.service >/dev/null 2>&1 || true
  systemctl restart openap-ap-address.service >/dev/null 2>&1 || true
  systemctl restart hostapd.service >/dev/null 2>&1 || true
  echo "Bridge activation failed; original Ethernet connection restored" >&2
  exit "$rc"
}
trap rollback EXIT INT TERM

nmcli connection delete "$bridge_port_con" >/dev/null 2>&1 || true
nmcli connection delete "$bridge_con" >/dev/null 2>&1 || true
if [ "$method" = manual ]; then
  [ -n "$addresses" ] || fail "Ethernet profile has no static IPv4 address"
  nmcli connection add type bridge ifname "$bridge_iface" con-name "$bridge_con" connection.autoconnect no connection.autoconnect-priority 200 bridge.stp no bridge.mac-address "$eth_mac" ipv4.method manual ipv4.addresses "$addresses" ipv4.gateway "$gateway" ipv4.dns "$dns" ipv6.method disabled >/dev/null
else
  nmcli connection add type bridge ifname "$bridge_iface" con-name "$bridge_con" connection.autoconnect no connection.autoconnect-priority 200 bridge.stp no bridge.mac-address "$eth_mac" ipv4.method auto ipv4.ignore-auto-routes yes ipv4.routes "0.0.0.0/0 $gateway 50" ipv6.method disabled >/dev/null
fi
nmcli connection add type ethernet slave-type bridge ifname "$eth_iface" master "$bridge_iface" con-name "$bridge_port_con" connection.autoconnect no connection.autoconnect-priority 200 >/dev/null
nmcli connection modify "$original_con" connection.autoconnect no

hostapd_tmp="$(mktemp)"
awk -v iface="$ap_iface" 'BEGIN{i=0;b=0} /^interface=/{if(!i)print "interface="iface;i=1;next} /^bridge=/{if(!b)print "bridge=br0";b=1;next} {print} END{if(!i)print "interface="iface;if(!b)print "bridge=br0"}' "$hostapd_conf" > "$hostapd_tmp"
chown root:www-data "$hostapd_tmp"; chmod 0640 "$hostapd_tmp"; mv "$hostapd_tmp" "$hostapd_conf"

systemctl stop hostapd.service
systemctl stop dnsmasq.service
systemctl stop openap-firewall.service
systemctl stop openap-ap-address.service >/dev/null 2>&1 || true
ip -4 addr flush dev "$ap_iface" scope global >/dev/null 2>&1 || true
nmcli connection up "$bridge_con" >/dev/null
nmcli connection up "$bridge_port_con" >/dev/null
tries=20
while [ "$tries" -gt 0 ]; do
  ip -4 -o addr show dev "$bridge_iface" | grep -q ' inet ' && break
  tries=$((tries-1)); sleep 1
done
[ "$tries" -gt 0 ] || fail "Bridge did not receive an IPv4 address"
tries=10
while [ "$tries" -gt 0 ]; do
  ip -4 route show default dev "$bridge_iface" | grep -Eq "^default via $gateway([[:space:]]|$)" && break
  tries=$((tries-1)); sleep 1
done
[ "$tries" -gt 0 ] || fail "Bridge management gateway did not converge"
nmcli connection modify "$bridge_con" connection.autoconnect yes
nmcli connection modify "$bridge_port_con" connection.autoconnect yes
ip link set dev "$ap_iface" up
profile_tmp="$(mktemp)"
awk 'BEGIN{done=0} /^current[[:space:]]*=/{print "current = ap_ethernet_bridge";done=1;next} {print} END{if(!done)print "current = ap_ethernet_bridge"}' "$profile" > "$profile_tmp"
chown www-data:www-data "$profile_tmp"; chmod 0640 "$profile_tmp"; mv "$profile_tmp" "$profile"
systemctl restart hostapd.service

cat > "$profile" <<EOF
[mode]
current = ap_ethernet_bridge

[runtime]
address_backend = NetworkManager
firewall_backend = none

[interfaces]
ap = $ap_iface
uplink = $profile_uplink
ethernet = $bridge_iface
ethernet_physical = $eth_iface
ethernet_connection = $original_con
ap_mac = $ap_mac
uplink_mac = $profile_uplink_mac
ethernet_mac = $eth_mac

[network]
bridge = $bridge_iface
subnet = $profile_subnet
gateway = $profile_gateway
dhcp_start = $profile_dhcp_start
dhcp_end = $profile_dhcp_end
ethernet_gateway = $gateway

[wireless]
country = $profile_country
EOF
chown www-data:www-data "$profile"; chmod 0640 "$profile"
mkdir -p /run/openap; date +%s > /run/openap/mode-switch
trap - EXIT INT TERM
echo "Ethernet Bridge active on $bridge_iface ($eth_iface + $ap_iface)"
echo "DHCP is provided by the upstream Ethernet network"
echo "Backup: $backup_dir"
