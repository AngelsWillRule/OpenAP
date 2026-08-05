#!/bin/sh
set -eu

action="${1:---apply}"
iface="${2:-}"
gateway="${3:-}"
requested_ap_mac="${4:-}"

fail() {
  echo "$1" >&2
  exit 1
}

is_wireless_iface() {
  wireless_candidate="$1"
  [ -d "/sys/class/net/$wireless_candidate/wireless" ] && return 0
  iw dev 2>/dev/null | awk '$1 == "Interface" {print $2}' | grep -Fxq "$wireless_candidate"
}

case "$iface" in
  ""|lo) fail "Invalid ethernet interface" ;;
esac
case "$action" in
  --apply|--validate-only) ;;
  *) fail "Invalid action" ;;
esac
echo "$iface" | grep -Eq '^[A-Za-z0-9_.:-]+$' || fail "Invalid interface name"
echo "$gateway" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || fail "Invalid gateway"

[ -e "/sys/class/net/$iface" ] || fail "Interface not found"

echo "$requested_ap_mac" | grep -Eqi '^([0-9a-f]{2}:){5}[0-9a-f]{2}$' || fail "Invalid AP MAC address"
requested_ap_iface=""
for net_path in /sys/class/net/*; do
  [ -r "$net_path/address" ] || continue
  [ "$(tr 'A-F' 'a-f' < "$net_path/address")" = "$(printf '%s' "$requested_ap_mac" | tr 'A-F' 'a-f')" ] || continue
  requested_ap_iface="$(basename "$net_path")"
  break
done
[ -n "$requested_ap_iface" ] || fail "Selected AP interface not found"
is_wireless_iface "$requested_ap_iface" || fail "Selected AP interface is not wireless"
iw phy "$(iw dev "$requested_ap_iface" info 2>/dev/null | awk '$1 == "wiphy" {print "phy" $2; exit}')" info 2>/dev/null | grep -Eq '^[[:space:]]+\* AP$' || fail "Selected WiFi interface is not AP-capable"

! is_wireless_iface "$iface" || fail "Interface is wireless, not ethernet"
[ "$(cat "/sys/class/net/$iface/type" 2>/dev/null)" = "1" ] || fail "Interface is not ethernet"
[ "$(cat "/sys/class/net/$iface/carrier" 2>/dev/null || echo 0)" = "1" ] || fail "Ethernet carrier is down"

eth_addr="$(ip -4 -o addr show dev "$iface" | awk '{print $4}' | head -1)"
[ -n "$eth_addr" ] || fail "Ethernet interface has no IPv4 address"

profile="/etc/openap/repeater.ini"
address_backend="$(awk -F ' *= *' '$1 == "address_backend" {print $2}' "$profile" 2>/dev/null | head -1)"
firewall_backend="$(awk -F ' *= *' '$1 == "firewall_backend" {print $2}' "$profile" 2>/dev/null | head -1)"
address_backend="${address_backend:-systemd-networkd}"
firewall_backend="${firewall_backend:-openap-firewall}"
[ "$firewall_backend" != "none" ] || firewall_backend="openap-firewall"
current_eth_gateway="$(ip -4 route show default dev "$iface" | awk '$1 == "default" && $2 == "via" {print $3; exit}')"
eth_ip="${eth_addr%/*}"
[ "$gateway" != "$eth_ip" ] || fail "Ethernet gateway cannot be the Raspberry address $eth_ip"
gateway_route="$(ip -4 route get "$gateway" 2>/dev/null || true)"
printf '%s\n' "$gateway_route" | grep -Eq "(^|[[:space:]])dev[[:space:]]+$iface([[:space:]]|$)" \
  || fail "Ethernet gateway $gateway is not reachable through $iface"

if [ "$action" = "--validate-only" ]; then
  echo "AP via Ethernet validation passed"
  echo "Ethernet uplink: $iface ($eth_addr), gateway: $gateway"
  echo "AP interface: $requested_ap_iface ($requested_ap_mac)"
  echo "No changes were applied"
  exit 0
fi

nft="/etc/openap/networking/openap.nft"
dnsmasq_conf="/etc/dnsmasq.d/openap-repeater.conf"
eth_network="/etc/systemd/network/10-${iface}.network"
backup_dir="/etc/openap/backups/ap-ethernet-$(date +%Y%m%d-%H%M%S)"
hostapd_conf="/etc/hostapd/hostapd.conf"
if [ "$address_backend" = "openap-ap-address" ] || [ -f /etc/openap/hostapd/hostapd.conf ]; then
  hostapd_conf="/etc/openap/hostapd/hostapd.conf"
fi

mkdir -p "$backup_dir"
for path in "$profile" "$nft" "$dnsmasq_conf" "$eth_network" "$hostapd_conf" /etc/systemd/network/30-wlan1-sta.network; do
  [ -e "$path" ] && cp -a "$path" "$backup_dir/"
done

subnet="$(awk -F ' *= *' '$1 == "subnet" {print $2}' "$profile" 2>/dev/null | head -1)"
old_ap_iface="$(awk -F ' *= *' '$1 == "ap" {print $2}' "$profile" 2>/dev/null | head -1)"
uplink_iface="$(awk -F ' *= *' '$1 == "uplink" {print $2}' "$profile" 2>/dev/null | head -1)"
ap_mac="$(awk -F ' *= *' '$1 == "ap_mac" {print $2}' "$profile" 2>/dev/null | head -1)"
uplink_mac="$(awk -F ' *= *' '$1 == "uplink_mac" {print $2}' "$profile" 2>/dev/null | head -1)"
network_gateway="$(awk -F ' *= *' '$1 == "gateway" {print $2}' "$profile" 2>/dev/null | head -1)"
dhcp_start="$(awk -F ' *= *' '$1 == "dhcp_start" {print $2}' "$profile" 2>/dev/null | head -1)"
dhcp_end="$(awk -F ' *= *' '$1 == "dhcp_end" {print $2}' "$profile" 2>/dev/null | head -1)"
wireless_country="$(awk -F ' *= *' '$1 == "country" {print $2}' "$profile" 2>/dev/null | head -1)"

subnet="${subnet:-10.88.77.0/24}"
ap_iface="$requested_ap_iface"
ap_mac="$(tr 'A-F' 'a-f' < "/sys/class/net/$ap_iface/address")"
uplink_iface="${uplink_iface:-wlan1}"
ap_mac="${ap_mac:-}"
uplink_mac="${uplink_mac:-}"
wireless_country="${wireless_country:-$(sed -n 's/^country_code=//p' /etc/hostapd/hostapd.conf 2>/dev/null | head -1)}"
wireless_country="${wireless_country:-00}"
network_gateway="${network_gateway:-10.88.77.1}"
dhcp_start="${dhcp_start:-10.88.77.50}"
dhcp_end="${dhcp_end:-10.88.77.200}"
ap_network="/etc/systemd/network/20-${ap_iface}-ap.network"

if [ "$uplink_iface" = "$ap_iface" ]; then
  uplink_iface=""
  uplink_mac=""
fi

if [ "$address_backend" = "systemd-networkd" ]; then
cat > "$eth_network" <<EOF
[Match]
Name=$iface

[Network]
Address=$eth_addr
DNS=$gateway
DNS=1.1.1.1
LinkLocalAddressing=ipv6
IPv6AcceptRA=no

[Route]
Destination=0.0.0.0/0
Gateway=$gateway
Metric=50
EOF
else
  command -v nmcli >/dev/null 2>&1 || fail "NetworkManager is required by the Raspberry address backend"
  eth_connection="$(nmcli -g GENERAL.CONNECTION device show "$iface" 2>/dev/null | head -1)"
  [ -n "$eth_connection" ] && [ "$eth_connection" != "--" ] || fail "No active NetworkManager connection for $iface"
  nmcli connection show "$eth_connection" > "$backup_dir/original-ethernet-connection.txt"
fi

cat > "$nft" <<EOF
table ip openap_nat {
  chain postrouting {
    type nat hook postrouting priority srcnat; policy accept;
    oifname "$iface" ip saddr $subnet masquerade
  }
}
EOF

if [ -n "$old_ap_iface" ] && [ "$old_ap_iface" != "$ap_iface" ]; then
  old_ap_network="/etc/systemd/network/20-${old_ap_iface}-ap.network"
  [ ! -f "$old_ap_network" ] || rm -f "$old_ap_network"
fi

if [ "$address_backend" = "systemd-networkd" ]; then
cat > "$ap_network" <<EOF
[Match]
Name=$ap_iface

[Link]
RequiredForOnline=no

[Network]
Address=$network_gateway/24
DHCP=no
LinkLocalAddressing=no
ConfigureWithoutCarrier=yes
EOF
fi

dns_disabled=0
[ ! -f "$dnsmasq_conf" ] || ! grep -q '^port=0$' "$dnsmasq_conf" || dns_disabled=1
encrypted_dns_enabled=0
if [ -r /etc/openap/encrypted-dns.ini ] \
  && awk -F ' *= *' '$1 == "enabled" && $2 == "true" {found=1} END {exit !found}' /etc/openap/encrypted-dns.ini; then
  encrypted_dns_enabled=1
fi
{
  echo "interface=$ap_iface"
  echo "bind-interfaces"
  echo "listen-address=$network_gateway"
  [ "$dns_disabled" -eq 0 ] || echo "port=0"
  echo "dhcp-authoritative"
  echo "dhcp-range=$dhcp_start,$dhcp_end,255.255.255.0,12h"
  echo "dhcp-option=3,$network_gateway"
  if [ "$dns_disabled" -eq 1 ]; then
    echo "dhcp-option=6,1.1.1.1,1.0.0.1"
  else
    echo "dhcp-option=6,$network_gateway"
    if [ "$encrypted_dns_enabled" -eq 1 ]; then
      echo "server=127.0.2.1"
    else
      echo "server=1.1.1.1"
      echo "server=1.0.0.1"
    fi
    echo "no-resolv"
    echo "domain-needed"
    echo "bogus-priv"
  fi
} > "$dnsmasq_conf"

cat > "$profile" <<EOF
[mode]
current = ap_ethernet

[runtime]
address_backend = $address_backend
firewall_backend = $firewall_backend

[interfaces]
ap = $ap_iface
uplink = $uplink_iface
ethernet = $iface
ap_mac = $ap_mac
uplink_mac = $uplink_mac

[network]
subnet = $subnet
gateway = $network_gateway
dhcp_start = $dhcp_start
dhcp_end = $dhcp_end
ethernet_gateway = $gateway

[wireless]
country = $wireless_country
EOF

chown www-data:www-data "$profile"
chmod 0640 "$profile"
chmod 0644 "$dnsmasq_conf" "$nft"
if [ "$address_backend" = "systemd-networkd" ]; then
  chmod 0644 "$eth_network" "$ap_network"
fi

if [ -f "$hostapd_conf" ]; then
  hostapd_tmp="$(mktemp)"
  awk -v iface="$ap_iface" 'BEGIN { done=0 } /^interface=/ { if (!done) print "interface=" iface; done=1; next } { print } END { if (!done) print "interface=" iface }' "$hostapd_conf" > "$hostapd_tmp"
  chown root:www-data "$hostapd_tmp"
  chmod 0640 "$hostapd_tmp"
  mv "$hostapd_tmp" "$hostapd_conf"
fi

systemctl disable --now openap-uplink.service >/dev/null 2>&1 || true
if [ "$address_backend" = "systemd-networkd" ]; then
  systemctl restart systemd-networkd.service
elif systemctl cat openap-ap-address.service >/dev/null 2>&1; then
  systemctl restart openap-ap-address.service
fi
for network_path in /sys/class/net/*; do
  stale_iface="$(basename "$network_path")"
  [ "$stale_iface" != "$ap_iface" ] || continue
  is_wireless_iface "$stale_iface" || continue
  stale_gateway_cidr="$(ip -4 -o addr show dev "$stale_iface" | awk -v gateway="$network_gateway" '{ split($4, address, "/"); if (address[1] == gateway) { print $4; exit } }')"
  [ -z "$stale_gateway_cidr" ] || ip addr del "$stale_gateway_cidr" dev "$stale_iface"
done
if [ "$address_backend" = "systemd-networkd" ]; then
  networkctl reconfigure "$ap_iface" >/dev/null 2>&1 || true
fi
ip link set dev "$ap_iface" up
tries=10
while ! ip -4 -o addr show dev "$ap_iface" | grep -Fq " $network_gateway/"; do
  tries=$((tries - 1))
  [ "$tries" -gt 0 ] || fail "AP address $network_gateway was not assigned to $ap_iface; dnsmasq was not restarted"
  sleep 1
done
if [ "$address_backend" != "systemd-networkd" ]; then
  nmcli connection modify "$eth_connection" ipv4.gateway "$gateway" ipv4.never-default no
  nmcli device reapply "$iface" >/dev/null
  tries=10
  current_eth_gateway=""
  while [ "$tries" -gt 0 ]; do
    current_eth_gateway="$(ip -4 route show default dev "$iface" | awk '$1 == "default" && $2 == "via" {print $3; exit}')"
    [ "$current_eth_gateway" != "$gateway" ] || break
    tries=$((tries - 1))
    sleep 1
  done
  [ "$current_eth_gateway" = "$gateway" ] \
    || fail "NetworkManager did not activate Ethernet gateway $gateway"
fi
[ -z "$uplink_iface" ] || ip route del default dev "$uplink_iface" >/dev/null 2>&1 || true
dnsmasq --test
systemctl restart openap-firewall.service
systemctl restart dnsmasq.service
systemctl restart hostapd.service

mkdir -p /run/openap
date +%s > /run/openap/mode-switch
echo "AP over ethernet active on $iface via $gateway"
echo "Backup: $backup_dir"
