#!/bin/sh
set -eu

ap_mac="${1:-}"
uplink_mac="${2:-}"

fail() {
  echo "$1" >&2
  exit 1
}

echo "$ap_mac" | grep -Eiq '^([0-9a-f]{2}:){5}[0-9a-f]{2}$' || fail "Invalid AP MAC"
echo "$uplink_mac" | grep -Eiq '^([0-9a-f]{2}:){5}[0-9a-f]{2}$' || fail "Invalid uplink MAC"
[ "$(printf '%s' "$ap_mac" | tr A-F a-f)" != "$(printf '%s' "$uplink_mac" | tr A-F a-f)" ] || fail "AP and uplink MAC must differ"

iface_for_mac() {
  target="$(printf '%s' "$1" | tr A-F a-f)"
  for path in /sys/class/net/*; do
    [ -e "$path/address" ] || continue
    mac="$(tr A-F a-f < "$path/address")"
    [ "$mac" = "$target" ] || continue
    basename "$path"
    return 0
  done
  return 1
}

is_wireless_iface() {
  iface="$1"
  [ -d "/sys/class/net/$iface/wireless" ] && return 0
  iw dev 2>/dev/null | awk '$1 == "Interface" {print $2}' | grep -Fxq "$iface"
}

ap_iface="$(iface_for_mac "$ap_mac" || true)"
uplink_iface="$(iface_for_mac "$uplink_mac" || true)"
[ -n "$ap_iface" ] || fail "AP interface not found"
[ -n "$uplink_iface" ] || fail "Uplink interface not found"
[ "$ap_iface" != "$uplink_iface" ] || fail "AP and uplink interface must differ"

is_wireless_iface "$ap_iface" || fail "AP interface is not wireless"
is_wireless_iface "$uplink_iface" || fail "Uplink interface is not wireless"

profile="/etc/openap/repeater.ini"
hostapd_conf="/etc/hostapd/hostapd.conf"
dnsmasq_conf="/etc/dnsmasq.d/openap-repeater.conf"
nft="/etc/openap/networking/openap.nft"
uplink_service="/etc/systemd/system/openap-uplink.service"
uplink_wpa="/etc/wpa_supplicant/wpa_supplicant-${uplink_iface}.conf"
backup_dir="/etc/openap/backups/repeater-wifi-$(date +%Y%m%d-%H%M%S)"

mkdir -p "$backup_dir"
for path in "$profile" "$hostapd_conf" "$dnsmasq_conf" "$nft" "$uplink_service" "$uplink_wpa" /etc/systemd/network/*.network; do
  [ -e "$path" ] && cp -a "$path" "$backup_dir/"
done

subnet="$(awk -F ' *= *' '$1 == "subnet" {print $2}' "$profile" 2>/dev/null | head -1)"
network_gateway="$(awk -F ' *= *' '$1 == "gateway" {print $2}' "$profile" 2>/dev/null | head -1)"
dhcp_start="$(awk -F ' *= *' '$1 == "dhcp_start" {print $2}' "$profile" 2>/dev/null | head -1)"
dhcp_end="$(awk -F ' *= *' '$1 == "dhcp_end" {print $2}' "$profile" 2>/dev/null | head -1)"
ethernet_iface="$(awk -F ' *= *' '$1 == "ethernet" {print $2}' "$profile" 2>/dev/null | head -1)"
ethernet_physical_iface="$(awk -F ' *= *' '$1 == "ethernet_physical" {print $2}' "$profile" 2>/dev/null | head -1)"
ethernet_gateway="$(awk -F ' *= *' '$1 == "ethernet_gateway" {print $2}' "$profile" 2>/dev/null | head -1)"
wireless_country="$(awk -F ' *= *' '$1 == "country" {print $2}' "$profile" 2>/dev/null | head -1)"
old_ap_iface="$(awk -F ' *= *' '$1 == "ap" {print $2}' "$profile" 2>/dev/null | head -1)"
old_uplink_iface="$(awk -F ' *= *' '$1 == "uplink" {print $2}' "$profile" 2>/dev/null | head -1)"

subnet="${subnet:-10.88.77.0/24}"
network_gateway="${network_gateway:-10.88.77.1}"
dhcp_start="${dhcp_start:-10.88.77.50}"
dhcp_end="${dhcp_end:-10.88.77.200}"
ethernet_iface="${ethernet_iface:-eth0}"
ethernet_physical_iface="${ethernet_physical_iface:-$ethernet_iface}"
ethernet_gateway="${ethernet_gateway:-}"
wireless_country="${wireless_country:-$(sed -n 's/^country_code=//p' "$hostapd_conf" 2>/dev/null | head -1)}"
wireless_country="${wireless_country:-00}"

if [ ! -e "$uplink_wpa" ] && [ -n "$old_uplink_iface" ] \
  && [ -e "/etc/wpa_supplicant/wpa_supplicant-${old_uplink_iface}.conf" ]; then
  cp -a "/etc/wpa_supplicant/wpa_supplicant-${old_uplink_iface}.conf" "$uplink_wpa"
fi
if [ ! -e "$uplink_wpa" ] && [ -e /etc/wpa_supplicant/wpa_supplicant-wlan1.conf ]; then
  cp -a /etc/wpa_supplicant/wpa_supplicant-wlan1.conf "$uplink_wpa"
fi
[ -e "$uplink_wpa" ] || fail "Uplink wpa_supplicant config not found"
chown root:root "$uplink_wpa"
chmod 0600 "$uplink_wpa"

systemctl disable --now openap-uplink.service >/dev/null 2>&1 || true
for stale_profile in \
  /etc/systemd/network/20-openap-ap.network \
  "/etc/systemd/network/20-${old_ap_iface}-ap.network" \
  "/etc/systemd/network/20-${uplink_iface}-ap.network" \
  "/etc/systemd/network/30-${old_uplink_iface}-sta.network" \
  "/etc/systemd/network/30-${ap_iface}-sta.network"; do
  [ -n "$stale_profile" ] || continue
  [ ! -e "$stale_profile" ] || rm -f -- "$stale_profile"
done
ip address flush dev "$uplink_iface" 2>/dev/null || true

tmp_hostapd="$(mktemp)"
awk -v iface="$ap_iface" '
  BEGIN { done = 0 }
  /^interface=/ { print "interface=" iface; done = 1; next }
  { print }
  END { if (!done) print "interface=" iface }
' "$hostapd_conf" > "$tmp_hostapd"
cat "$tmp_hostapd" > "$hostapd_conf"
rm -f "$tmp_hostapd"

cat > "$dnsmasq_conf" <<EOF
interface=$ap_iface
bind-interfaces
listen-address=$network_gateway
dhcp-authoritative
dhcp-range=$dhcp_start,$dhcp_end,255.255.255.0,12h
dhcp-option=3,$network_gateway
dhcp-option=6,$network_gateway
server=1.1.1.1
server=1.0.0.1
no-resolv
domain-needed
bogus-priv
EOF

cat > "$nft" <<EOF
table ip openap_nat {
  chain postrouting {
    type nat hook postrouting priority srcnat; policy accept;
    oifname "$uplink_iface" ip saddr $subnet masquerade
  }
}
EOF

cat > "/etc/systemd/network/20-${ap_iface}-ap.network" <<EOF
[Match]
Name=$ap_iface

[Link]
RequiredForOnline=no

[Network]
Address=$network_gateway/24
ConfigureWithoutCarrier=yes
IPForward=yes
EOF

cat > "/etc/systemd/network/30-${uplink_iface}-sta.network" <<EOF
[Match]
Name=$uplink_iface

[Link]
RequiredForOnline=no

[Network]
DHCP=yes
IPv6AcceptRA=yes

[DHCPv4]
RouteMetric=100
UseDNS=yes
EOF

if [ -n "$ethernet_iface" ] && [ -e "/sys/class/net/$ethernet_iface" ]; then
  eth_addr="$(ip -4 -o addr show dev "$ethernet_iface" | awk '{print $4}' | head -1 || true)"
  if [ -n "$eth_addr" ]; then
    cat > "/etc/systemd/network/10-${ethernet_iface}.network" <<EOF
[Match]
Name=$ethernet_iface

[Network]
Address=$eth_addr
DNS=${ethernet_gateway:-1.1.1.1}
LinkLocalAddressing=ipv6
IPv6AcceptRA=no
EOF
  fi
fi

cat > "$uplink_service" <<EOF
[Unit]
Description=OpenAP upstream WiFi client on $uplink_iface
After=systemd-udev-settle.service systemd-networkd.service
Wants=systemd-udev-settle.service systemd-networkd.service
Before=network-online.target

[Service]
Type=simple
ExecStartPre=/bin/sh -c 'for i in \$(seq 1 30); do [ -e /sys/class/net/$uplink_iface ] && exit 0; sleep 1; done; exit 1'
ExecStartPre=/bin/sh -c '/usr/sbin/iw dev $uplink_iface set power_save off 2>/dev/null || true'
ExecStart=/sbin/wpa_supplicant -c $uplink_wpa -i $uplink_iface
Restart=always
RestartSec=5

[Install]
WantedBy=multi-user.target
EOF

cat > "$profile" <<EOF
[mode]
current = repeater_wifi

[interfaces]
ap = $ap_iface
uplink = $uplink_iface
ethernet = $ethernet_iface
ethernet_physical = $ethernet_physical_iface
ap_mac = $ap_mac
uplink_mac = $uplink_mac

[network]
subnet = $subnet
gateway = $network_gateway
dhcp_start = $dhcp_start
dhcp_end = $dhcp_end
ethernet_gateway = $ethernet_gateway

[wireless]
country = $wireless_country
EOF

chown www-data:www-data "$profile"
chgrp www-data "$hostapd_conf"
chmod 0640 "$profile"
chmod 0640 "$hostapd_conf"
chmod 0644 "$dnsmasq_conf" "$nft" "$uplink_service" /etc/systemd/network/*.network

systemctl daemon-reload
systemctl restart systemd-networkd.service
systemctl enable --now openap-uplink.service
systemctl restart openap-firewall.service
systemctl restart dnsmasq.service
systemctl restart hostapd.service

tries=25
while [ "$tries" -gt 0 ]; do
  if iw dev "$uplink_iface" link 2>/dev/null | grep -q '^Connected to ' \
    && ip -4 -o addr show dev "$uplink_iface" | grep -q ' inet '; then
    break
  fi
  tries=$((tries - 1))
  sleep 1
done

ip route del default dev "$ethernet_iface" >/dev/null 2>&1 || true

mkdir -p /run/openap
date +%s > /run/openap/mode-switch
echo "WiFi repeater active with AP $ap_iface and uplink $uplink_iface"
echo "Backup: $backup_dir"
