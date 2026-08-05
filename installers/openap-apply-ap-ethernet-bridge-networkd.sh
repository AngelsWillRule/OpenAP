#!/bin/sh
set -eu

action="${1:---apply}"
eth_iface="${2:-}"
ap_mac="$(printf '%s' "${3:-}" | tr 'A-F' 'a-f')"
gateway_arg="${4:-}"
bridge_iface=br0
profile=/etc/openap/repeater.ini
hostapd_conf=/etc/hostapd/hostapd.conf
if [ -f /etc/openap/hostapd/hostapd.conf ]; then
  hostapd_conf=/etc/openap/hostapd/hostapd.conf
fi
nft_conf=/etc/openap/networking/openap.nft
dnsmasq_conf=/etc/dnsmasq.d/openap-repeater.conf
bridge_netdev=/etc/systemd/network/05-openap-bridge.netdev
bridge_network=/etc/systemd/network/05-openap-bridge.network
bridge_port=/etc/systemd/network/05-openap-bridge-port.network
bridge_ap=/etc/systemd/network/06-openap-bridge-ap.network

fail() { echo "$1" >&2; exit 1; }
ini_value() { awk -F ' *= *' -v key="$1" '$1 == key {print $2; exit}' "$profile" 2>/dev/null; }
is_wireless() { [ -d "/sys/class/net/$1/wireless" ] || iw dev 2>/dev/null | awk '$1=="Interface"{print $2}' | grep -Fxq "$1"; }

case "$action" in --apply|--gateway|--validate-only|--disable) ;; *) fail "Invalid networkd bridge action";; esac
systemctl is-active --quiet systemd-networkd.service || fail "systemd-networkd is not active"
printf '%s' "$eth_iface" | grep -Eq '^[A-Za-z0-9_.:-]+$' || fail "Invalid Ethernet interface"
[ -e "/sys/class/net/$eth_iface" ] || fail "Ethernet interface not found"

if [ "$action" = --disable ]; then
  systemctl stop hostapd.service >/dev/null 2>&1 || true
  rm -f "$bridge_ap" "$bridge_port" "$bridge_network" "$bridge_netdev"
  if [ -f "$hostapd_conf" ]; then
    sed -i '/^bridge=br0$/d' "$hostapd_conf"
  fi
  networkctl reload >/dev/null 2>&1 || true
  ip link set dev "$eth_iface" nomaster >/dev/null 2>&1 || true
  ip link del "$bridge_iface" type bridge >/dev/null 2>&1 || true
  systemctl restart systemd-networkd.service
  echo "Ethernet bridge disabled; systemd-networkd routed profile restored"
  exit 0
fi

printf '%s' "$ap_mac" | grep -Eqi '^([0-9a-f]{2}:){5}[0-9a-f]{2}$' || fail "Invalid AP MAC address"
ap_iface=""
for net_path in /sys/class/net/*; do
  [ -r "$net_path/address" ] || continue
  [ "$(tr 'A-F' 'a-f' < "$net_path/address")" = "$ap_mac" ] || continue
  ap_iface="$(basename "$net_path")"
  break
done
[ -n "$ap_iface" ] || fail "Selected AP interface not found"
is_wireless "$ap_iface" || fail "Selected AP interface is not wireless"
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

addresses="$(ip -4 -o address show dev "$eth_iface" scope global | awk '{print $4}' | paste -sd, -)"
gateway="$gateway_arg"
dns="$(resolvectl dns "$eth_iface" 2>/dev/null | sed 's/^[^:]*:[[:space:]]*//' | tr ' ' '\n' | grep -E '^([0-9]{1,3}\.){3}[0-9]{1,3}$' | paste -sd' ' - || true)"
eth_mac="$(tr 'A-F' 'a-f' < "/sys/class/net/$eth_iface/address")"

if [ "$action" = --gateway ]; then
  [ "$(ini_value current)" = ap_ethernet_bridge ] || fail "Ethernet Bridge is not active"
  [ -f "$bridge_network" ] || fail "OpenAP bridge network configuration not found"
  backup_dir="/etc/openap/backups/bridge-gateway-$(date +%Y%m%d-%H%M%S)"
  mkdir -p "$backup_dir"
  cp -a "$bridge_network" "$profile" "$backup_dir/"
  bridge_tmp="$(mktemp)"
  awk -v gateway="$gateway" 'BEGIN{done=0} /^Gateway=/{print "Gateway=" gateway;done=1;next} {print} END{if(!done){print "";print "[Route]";print "Destination=0.0.0.0/0";print "Gateway=" gateway;print "Metric=50"}}' "$bridge_network" > "$bridge_tmp"
  cat "$bridge_tmp" > "$bridge_network"
  rm -f "$bridge_tmp"
  networkctl reload
  networkctl reconfigure "$bridge_iface"
  ip -4 route replace default via "$gateway" dev "$bridge_iface" metric 50
  ip -4 route show default dev "$bridge_iface" | grep -Eq "^default via $gateway([[:space:]]|$)" || fail "Bridge management gateway did not converge"
  profile_tmp="$(mktemp)"
  awk -v gateway="$gateway" 'BEGIN{done=0} /^ethernet_gateway[[:space:]]*=/{print "ethernet_gateway = " gateway;done=1;next} {print} END{if(!done)print "ethernet_gateway = " gateway}' "$profile" > "$profile_tmp"
  chown www-data:www-data "$profile_tmp"; chmod 0640 "$profile_tmp"; mv "$profile_tmp" "$profile"
  echo "Ethernet Bridge management gateway updated: $gateway"
  echo "Backup: $backup_dir"
  exit 0
fi

if [ "$action" = --validate-only ]; then
  [ -n "$addresses" ] || fail "Ethernet interface has no IPv4 address"
  echo "Ethernet Bridge validation passed"
  echo "Backend: systemd-networkd"
  echo "Bridge: $bridge_iface; Ethernet: $eth_iface; AP: $ap_iface"
  exit 0
fi

[ -n "$addresses" ] || fail "Ethernet interface has no IPv4 address"
profile_uplink="$(ini_value uplink)"
profile_uplink_mac="$(ini_value uplink_mac)"
profile_subnet="$(ini_value subnet)"
profile_gateway="$(ini_value gateway)"
profile_dhcp_start="$(ini_value dhcp_start)"
profile_dhcp_end="$(ini_value dhcp_end)"
profile_country="$(ini_value country)"
backup_dir="/etc/openap/backups/ap-ethernet-bridge-networkd-$(date +%Y%m%d-%H%M%S)"
mkdir -p "$backup_dir"
for path in "$profile" "$hostapd_conf" "$nft_conf" "$dnsmasq_conf"; do
  [ ! -e "$path" ] || cp -a "$path" "$backup_dir/"
done
cp -a /etc/systemd/network "$backup_dir/systemd-network"

rollback() {
  rc=$?
  trap - EXIT INT TERM
  [ "$rc" -eq 0 ] && return 0
  systemctl stop hostapd.service >/dev/null 2>&1 || true
  rm -f "$bridge_ap" "$bridge_port" "$bridge_network" "$bridge_netdev"
  rm -rf /etc/systemd/network
  cp -a "$backup_dir/systemd-network" /etc/systemd/network
  [ ! -f "$backup_dir/hostapd.conf" ] || cp -a "$backup_dir/hostapd.conf" "$hostapd_conf"
  [ ! -f "$backup_dir/openap.nft" ] || cp -a "$backup_dir/openap.nft" "$nft_conf"
  [ ! -f "$backup_dir/openap-repeater.conf" ] || cp -a "$backup_dir/openap-repeater.conf" "$dnsmasq_conf"
  [ ! -f "$backup_dir/repeater.ini" ] || cp -a "$backup_dir/repeater.ini" "$profile"
  ip link set dev "$eth_iface" nomaster >/dev/null 2>&1 || true
  ip link del "$bridge_iface" type bridge >/dev/null 2>&1 || true
  systemctl restart systemd-networkd.service >/dev/null 2>&1 || true
  systemctl restart openap-firewall.service >/dev/null 2>&1 || true
  systemctl restart dnsmasq.service >/dev/null 2>&1 || true
  systemctl restart openap-ap-address.service >/dev/null 2>&1 || true
  systemctl restart hostapd.service >/dev/null 2>&1 || true
  echo "Bridge activation failed; original systemd-networkd configuration restored" >&2
  exit "$rc"
}
trap rollback EXIT INT TERM

cat > "$bridge_netdev" <<EOF
[NetDev]
Name=$bridge_iface
Kind=bridge
MACAddress=$eth_mac

[Bridge]
STP=false
EOF

cat > "$bridge_network" <<EOF
[Match]
Name=$bridge_iface

[Link]
RequiredForOnline=yes

[Network]
Address=$addresses
DNS=$dns
LinkLocalAddressing=ipv6
IPv6AcceptRA=no
EOF
if [ -n "$gateway" ]; then
  cat >> "$bridge_network" <<EOF

[Route]
Destination=0.0.0.0/0
Gateway=$gateway
Metric=50
EOF
fi

cat > "$bridge_port" <<EOF
[Match]
Name=$eth_iface

[Link]
RequiredForOnline=no

[Network]
Bridge=$bridge_iface
LinkLocalAddressing=no
EOF

cat > "$bridge_ap" <<EOF
[Match]
Name=$ap_iface

[Link]
RequiredForOnline=no

[Network]
LinkLocalAddressing=no
IPv6AcceptRA=no
EOF

hostapd_tmp="$(mktemp)"
awk -v iface="$ap_iface" 'BEGIN{i=0;b=0} /^interface=/{if(!i)print "interface="iface;i=1;next} /^bridge=/{if(!b)print "bridge=br0";b=1;next} {print} END{if(!i)print "interface="iface;if(!b)print "bridge=br0"}' "$hostapd_conf" > "$hostapd_tmp"
chown root:www-data "$hostapd_tmp"
chmod 0640 "$hostapd_tmp"
mv "$hostapd_tmp" "$hostapd_conf"

systemctl stop hostapd.service
systemctl stop dnsmasq.service
systemctl stop openap-firewall.service
systemctl stop openap-ap-address.service >/dev/null 2>&1 || true
ip -4 address flush dev "$ap_iface" scope global >/dev/null 2>&1 || true
systemctl restart systemd-networkd.service

tries=20
while [ "$tries" -gt 0 ]; do
  ip -4 -o address show dev "$bridge_iface" | grep -q ' inet ' && break
  tries=$((tries-1))
  sleep 1
done
[ "$tries" -gt 0 ] || fail "Bridge did not receive the Ethernet IPv4 address"
tries=10
while [ "$tries" -gt 0 ]; do
  ip -4 route show default dev "$bridge_iface" | grep -Eq "^default via $gateway([[:space:]]|$)" && break
  tries=$((tries-1))
  sleep 1
done
[ "$tries" -gt 0 ] || fail "Bridge management gateway did not converge"
ip link set dev "$ap_iface" up
profile_tmp="$(mktemp)"
awk 'BEGIN{done=0} /^current[[:space:]]*=/{print "current = ap_ethernet_bridge";done=1;next} {print} END{if(!done)print "current = ap_ethernet_bridge"}' "$profile" > "$profile_tmp"
chown www-data:www-data "$profile_tmp"
chmod 0640 "$profile_tmp"
mv "$profile_tmp" "$profile"
systemctl restart hostapd.service

cat > "$profile" <<EOF
[mode]
current = ap_ethernet_bridge

[runtime]
address_backend = systemd-networkd
firewall_backend = none

[interfaces]
ap = $ap_iface
uplink = $profile_uplink
ethernet = $bridge_iface
ethernet_physical = $eth_iface
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
chown www-data:www-data "$profile"
chmod 0640 "$profile"
mkdir -p /run/openap
date +%s > /run/openap/mode-switch
trap - EXIT INT TERM
echo "Ethernet Bridge active on $bridge_iface ($eth_iface + $ap_iface)"
echo "Backend: systemd-networkd"
echo "DHCP is provided by the upstream Ethernet network"
echo "Backup: $backup_dir"
