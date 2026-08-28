#!/bin/sh
set -eu

action="${1:---apply}"
ethernet_iface="${2:-}"
ethernet_gateway="${3:-}"
requested_ap_mac="${4:-}"
apply_status=/run/openap/ethernet-mode-apply-status
profile="/etc/openap/repeater.ini"
roles="/etc/openap/wifi-roles.ini"
dnsmasq_conf="/etc/dnsmasq.d/openap-repeater.conf"
nft_conf="/etc/openap/networking/openap.nft"

fail() { echo "openap-apply-ap-ethernet: $1" >&2; exit 1; }
write_apply_status() {
  state="$1"
  message="${2:-}"
  mkdir -p /run/openap
  status_tmp="/run/openap/.ethernet-mode-apply-status.$$"
  {
    printf 'state = %s\n' "$state"
    printf 'target = ap_ethernet\n'
    printf 'updated = %s\n' "$(date +%s)"
    printf 'message = %s\n' "$(printf '%s' "$message" | tr '\n\r=' '   ')"
  } > "$status_tmp"
  chmod 0644 "$status_tmp"
  mv "$status_tmp" "$apply_status"
}
valid_mac() { printf '%s' "$1" | grep -Eiq '^([0-9a-f]{2}:){5}[0-9a-f]{2}$'; }
ini_value() { awk -F ' *= *' -v key="$1" '$1 == key {print $2; exit}' "$2" 2>/dev/null || true; }
role_value() {
  awk -F ' *= *' -v key="$1" '
    /^\[active\]$/ { active=1; next }
    /^\[/ { active=0 }
    active && $1 == key { print $2; exit }
  ' "$roles" 2>/dev/null || true
}
iface_for_mac() {
  target="$(printf '%s' "$1" | tr A-F a-f)"
  for path in /sys/class/net/*; do
    [ -e "$path/address" ] || continue
    [ "$(tr A-F a-f < "$path/address")" = "$target" ] || continue
    iface="$(basename "$path")"
    # A Linux bridge inherits the MAC of one of its member interfaces.  After
    # boot, openap0 can therefore match an AP role before the physical radio
    # does when /sys/class/net is traversed alphabetically.  Role MACs always
    # identify Wi-Fi devices, so never resolve them to bridges or other
    # non-wireless interfaces.
    is_wireless_iface "$iface" || continue
    printf '%s\n' "$iface"
    return 0
  done
  return 1
}
is_wireless_iface() {
  [ -d "/sys/class/net/$1/wireless" ] && return 0
  iw dev 2>/dev/null | awk '$1 == "Interface" {print $2}' | grep -Fxq "$1"
}
initialize_legacy_roles() {
  [ ! -e "$roles" ] || fail "Wi-Fi role model exists but is not readable"
  legacy_ap_mac="$(ini_value ap_mac "$profile" | tr A-F a-f)"
  legacy_uplink_mac="$(ini_value uplink_mac "$profile" | tr A-F a-f)"
  valid_mac "$legacy_ap_mac" || fail "Legacy AP identity is invalid"
  [ "$requested_ap_mac" = "$legacy_ap_mac" ] || fail "Requested AP does not match the legacy profile"
  if [ -n "$legacy_uplink_mac" ] && ! valid_mac "$legacy_uplink_mac"; then fail "Legacy uplink identity is invalid"; fi
  [ "$legacy_uplink_mac" != "$legacy_ap_mac" ] || fail "Legacy AP and uplink identities overlap"
  legacy_ap_iface="$(iface_for_mac "$legacy_ap_mac" || true)"
  [ -n "$legacy_ap_iface" ] || fail "Legacy AP interface is not present"
  is_wireless_iface "$legacy_ap_iface" || fail "Legacy AP interface is not wireless"
  legacy_info="$(iw dev "$legacy_ap_iface" info 2>/dev/null || true)"
  printf '%s\n' "$legacy_info" | grep -Eq '^[[:space:]]*type[[:space:]]+AP$' || fail "Legacy AP interface is not active as an access point"
  legacy_frequency="$(printf '%s\n' "$legacy_info" | sed -n 's/.*(\([0-9][0-9]*\) MHz).*/\1/p' | head -n 1)"
  case "$legacy_frequency" in 24??) legacy_ap_slot=ap_24ghz ;; 5???) legacy_ap_slot=ap_5ghz ;; *) fail "Legacy AP band cannot be resolved safely" ;; esac
  roles_tmp="$(mktemp /etc/openap/.wifi-roles.ini.XXXXXX)" || fail "Cannot create temporary Wi-Fi role model"
  trap 'rm -f -- "$roles_tmp"' EXIT HUP INT TERM
  {
    printf '%s\n' '[meta]' 'version = 2' '' '[active]'
    if [ "$legacy_ap_slot" = ap_24ghz ]; then printf 'ap_24ghz = %s\nap_5ghz =\n' "$legacy_ap_mac"; else printf 'ap_24ghz =\nap_5ghz = %s\n' "$legacy_ap_mac"; fi
    printf 'uplink = %s\n\n[draft]\n' "$legacy_uplink_mac"
    if [ "$legacy_ap_slot" = ap_24ghz ]; then printf 'ap_24ghz = %s\nap_5ghz =\n' "$legacy_ap_mac"; else printf 'ap_24ghz =\nap_5ghz = %s\n' "$legacy_ap_mac"; fi
    printf 'uplink = %s\n' "$legacy_uplink_mac"
  } > "$roles_tmp"
  chown www-data:www-data "$roles_tmp"; chmod 0640 "$roles_tmp"
  mv -n "$roles_tmp" "$roles" || fail "Cannot install migrated Wi-Fi role model"
  trap - EXIT HUP INT TERM
  [ -r "$roles" ] || fail "Migrated Wi-Fi role model is not readable"
}
wait_hostapd_ready() {
  interfaces="$*"
  attempts=10
  while [ "$attempts" -gt 0 ]; do
    ready=1
    for iface in $interfaces; do
      timeout 1 hostapd_cli -p /run/hostapd -i "$iface" ping 2>/dev/null | grep -Fxq PONG \
        || ready=0
    done
    [ "$ready" -eq 1 ] && return 0
    attempts=$((attempts - 1))
    sleep 1
  done
  return 1
}

if [ "$action" = --tracked ]; then
  tracked_action="${2:-}"
  shift 2
  write_apply_status applying
  tracked_output="$(mktemp /tmp/openap-ap-ethernet.XXXXXX)"
  if "$0" "$tracked_action" "$@" >"$tracked_output" 2>&1; then
    cat "$tracked_output"
    rm -f "$tracked_output"
    write_apply_status success
    exit 0
  else
    rc=$?
  fi
  message="$(tail -1 "$tracked_output" 2>/dev/null || true)"
  cat "$tracked_output" >&2
  rm -f "$tracked_output"
  write_apply_status failed "$message"
  exit "$rc"
fi

case "$action" in --apply|--apply-delayed|--validate-only) ;; *) fail "Invalid action" ;; esac
[ "$(id -u)" -eq 0 ] || fail "This command must run as root"
case "$ethernet_iface" in ''|lo|*[!A-Za-z0-9_.:-]*) fail "Invalid Ethernet interface" ;; esac
printf '%s' "$ethernet_gateway" | grep -Eq '^([0-9]{1,3}\.){3}[0-9]{1,3}$' || fail "Invalid Ethernet gateway"
valid_mac "$requested_ap_mac" || fail "Invalid AP MAC address"
requested_ap_mac="$(printf '%s' "$requested_ap_mac" | tr A-F a-f)"
[ -r "$profile" ] || fail "OpenAP profile not found"
[ -r "$roles" ] || initialize_legacy_roles
[ -e "/sys/class/net/$ethernet_iface" ] || fail "Ethernet interface not found"
is_wireless_iface "$ethernet_iface" && fail "Selected uplink is wireless, not Ethernet"
[ "$(cat "/sys/class/net/$ethernet_iface/carrier" 2>/dev/null || true)" = 1 ] || fail "Ethernet carrier is down"

ethernet_addr="$(ip -4 -o address show dev "$ethernet_iface" | awk '{print $4; exit}')"
[ -n "$ethernet_addr" ] || fail "Ethernet interface has no IPv4 address"
ethernet_ip="${ethernet_addr%/*}"
[ "$ethernet_gateway" != "$ethernet_ip" ] || fail "Ethernet gateway cannot be the OpenAP address $ethernet_ip"
ip -4 route get "$ethernet_gateway" oif "$ethernet_iface" 2>/dev/null | grep -Eq "(^|[[:space:]])dev[[:space:]]+$ethernet_iface([[:space:]]|$)" \
  || fail "Ethernet gateway $ethernet_gateway is not reachable through $ethernet_iface"

ap24_mac="$(role_value ap_24ghz | tr A-F a-f)"
ap5_mac="$(role_value ap_5ghz | tr A-F a-f)"
uplink_mac="$(role_value uplink | tr A-F a-f)"
[ "$requested_ap_mac" = "$ap24_mac" ] || [ "$requested_ap_mac" = "$ap5_mac" ] \
  || fail "Requested AP is not an active AP role"
ap24_iface=""
ap5_iface=""
uplink_iface=""
[ -z "$ap24_mac" ] || ap24_iface="$(iface_for_mac "$ap24_mac" || true)"
[ -z "$ap5_mac" ] || ap5_iface="$(iface_for_mac "$ap5_mac" || true)"
[ -z "$uplink_mac" ] || uplink_iface="$(iface_for_mac "$uplink_mac" || true)"
[ -n "$ap24_iface$ap5_iface" ] || fail "No active AP interface is present"
for iface in $ap24_iface $ap5_iface; do
  is_wireless_iface "$iface" || fail "AP interface is not wireless: $iface"
done
primary_ap="$ap24_iface"
[ -n "$primary_ap" ] || primary_ap="$ap5_iface"

if [ "$action" = --apply-delayed ]; then
  write_apply_status scheduled
  unit="openap-ap-ethernet-apply-$(date +%s)"
  systemd-run --quiet --collect --unit="$unit" --on-active=2s \
    /usr/local/sbin/openap-apply-ap-ethernet --tracked --apply \
    "$ethernet_iface" "$ethernet_gateway" "$requested_ap_mac"
  echo "AP Ethernet activation scheduled"
  exit 0
fi

profile_bridge="$(ini_value bridge "$profile")"
hotspot_iface="$profile_bridge"
if [ -n "$hotspot_iface" ]; then
  [ -d "/sys/class/net/$hotspot_iface/bridge" ] || fail "Configured hotspot bridge is not present"
else
  if [ -n "$ap24_iface" ] && [ -z "$ap5_iface" ]; then :
  elif [ -z "$ap24_iface" ] && [ -n "$ap5_iface" ]; then :
  else fail "A dual-band hotspot requires a configured bridge"
  fi
  hotspot_iface="$primary_ap"
fi
subnet="$(ini_value subnet "$profile")"; subnet="${subnet:-10.88.77.0/24}"
hotspot_gateway="$(ini_value gateway "$profile")"; hotspot_gateway="${hotspot_gateway:-10.88.77.1}"
dhcp_start="$(ini_value dhcp_start "$profile")"; dhcp_start="${dhcp_start:-10.88.77.50}"
dhcp_end="$(ini_value dhcp_end "$profile")"; dhcp_end="${dhcp_end:-10.88.77.200}"
country="$(ini_value country "$profile")"; country="${country:-00}"

if [ "$action" = --validate-only ]; then
  echo "AP via Ethernet validation passed"
  echo "Ethernet uplink: $ethernet_iface ($ethernet_addr), gateway: $ethernet_gateway"
  echo "AP interfaces:$([ -n "$ap24_iface" ] && printf ' %s' "$ap24_iface")$([ -n "$ap5_iface" ] && printf ' %s' "$ap5_iface")"
  echo "Hotspot interface: $hotspot_iface ($hotspot_gateway)"
  echo "No changes were applied"
  exit 0
fi

command -v python3 >/dev/null 2>&1 || fail "python3 is required"
work_dir="$(mktemp -d /run/openap-ap-ethernet.XXXXXX)"
case "$work_dir" in /run/openap-ap-ethernet.*) ;; *) fail "Unexpected temporary directory" ;; esac
backup_dir="/etc/openap/backups/ap-ethernet-$(date +%Y%m%d-%H%M%S)"
[ ! -e "$backup_dir" ] || fail "Backup destination already exists"
mkdir -p "$backup_dir"
cleanup() { rm -rf -- "$work_dir"; }
trap cleanup EXIT HUP INT TERM

encrypted_dns_enabled="$(ini_value enabled /etc/openap/encrypted-dns.ini)"
if [ "$encrypted_dns_enabled" = true ]; then
  dnsmasq_servers='server=127.0.2.1'
else
  dnsmasq_servers="$(sed -n '/^server=/p' "$dnsmasq_conf" 2>/dev/null | grep -v '^server=127\.0\.2\.1$' || true)"
  [ -n "$dnsmasq_servers" ] || dnsmasq_servers='server=1.1.1.1
server=1.0.0.1'
fi

cat > "$work_dir/ethernet.network" <<EOF
[Match]
Name=$ethernet_iface

[Network]
Address=$ethernet_addr
DNS=$ethernet_gateway
DNS=1.1.1.1
LinkLocalAddressing=ipv6
IPv6AcceptRA=no

[Route]
Destination=0.0.0.0/0
Gateway=$ethernet_gateway
Metric=50
EOF
if [ -n "$profile_bridge" ]; then
  cat > "$work_dir/hotspot.netdev" <<EOF
[NetDev]
Name=$profile_bridge
Kind=bridge

[Bridge]
STP=false
EOF
  cat > "$work_dir/hotspot.network" <<EOF
[Match]
Name=$profile_bridge

[Link]
RequiredForOnline=no

[Network]
Address=$hotspot_gateway/${subnet#*/}
DHCP=no
LinkLocalAddressing=no
ConfigureWithoutCarrier=yes
EOF
  for iface in $ap24_iface $ap5_iface; do
    cat > "$work_dir/${iface}-ap.network" <<EOF
[Match]
Name=$iface

[Link]
RequiredForOnline=no

[Network]
DHCP=no
LinkLocalAddressing=no
ConfigureWithoutCarrier=yes
EOF
  done
fi
cat > "$work_dir/dnsmasq.conf" <<EOF
interface=$hotspot_iface
bind-interfaces
listen-address=$hotspot_gateway
dhcp-authoritative
dhcp-range=$dhcp_start,$dhcp_end,255.255.255.0,12h
dhcp-option=3,$hotspot_gateway
dhcp-option=6,$hotspot_gateway
$dnsmasq_servers
no-resolv
domain-needed
bogus-priv
EOF
dnsmasq --test --conf-file="$work_dir/dnsmasq.conf" >/dev/null
cat > "$work_dir/openap.nft" <<EOF
table ip openap_nat {
  chain postrouting {
    type nat hook postrouting priority srcnat; policy accept;
    oifname "$ethernet_iface" ip saddr $subnet masquerade
  }
}
EOF
nft -c -f "$work_dir/openap.nft"

python3 - "$profile" "$work_dir/repeater.ini" "$primary_ap" "$uplink_iface" \
  "$ethernet_iface" "$requested_ap_mac" "$uplink_mac" "$ap24_iface" "$ap5_iface" \
  "$profile_bridge" "$subnet" "$hotspot_gateway" "$dhcp_start" "$dhcp_end" \
  "$ethernet_gateway" "$country" <<'PY'
import configparser, sys
(source, target, primary_ap, uplink, ethernet, ap_mac, uplink_mac, ap24, ap5,
 bridge, subnet, gateway, dhcp_start, dhcp_end, ethernet_gateway, country) = sys.argv[1:]
config = configparser.ConfigParser(interpolation=None, strict=False)
config.optionxform = str
config.read(source, encoding="utf-8")
for section in ("mode", "runtime", "interfaces", "network", "wireless"):
    if not config.has_section(section): config.add_section(section)
config["mode"]["current"] = "ap_ethernet"
config["runtime"]["address_backend"] = "systemd-networkd"
config["runtime"]["firewall_backend"] = "openap-firewall"
i = config["interfaces"]
i["ap"] = primary_ap; i["uplink"] = uplink; i["ethernet"] = ethernet
i["ethernet_physical"] = ethernet; i["ap_mac"] = ap_mac; i["uplink_mac"] = uplink_mac
i["ap_24ghz"] = ap24; i["ap_5ghz"] = ap5
if bridge: i["bridge"] = bridge
else: i.pop("bridge", None)
n = config["network"]
n["subnet"] = subnet; n["gateway"] = gateway; n["dhcp_start"] = dhcp_start
n["dhcp_end"] = dhcp_end; n["ethernet_gateway"] = ethernet_gateway
config["wireless"]["country"] = country
with open(target, "w", encoding="utf-8") as stream: config.write(stream, space_around_delimiters=True)
PY

ethernet_network="/etc/systemd/network/10-${ethernet_iface}.network"
hotspot_netdev=/etc/systemd/network/15-openap-hotspot.netdev
hotspot_network=/etc/systemd/network/20-openap-hotspot.network
legacy_ap_network=/etc/systemd/network/20-openap-ap.network
for path in "$profile" "$dnsmasq_conf" "$nft_conf" "$ethernet_network" \
  "$hotspot_netdev" "$hotspot_network" "$legacy_ap_network"; do
  [ -e "$path" ] || continue
  cp -a --parents "$path" "$backup_dir"
  printf '%s\n' "$path" >> "$backup_dir/existing-paths"
done
for iface in $ap24_iface $ap5_iface; do
  path="/etc/systemd/network/20-${iface}-ap.network"
  [ -e "$path" ] || continue
  cp -a --parents "$path" "$backup_dir"
  printf '%s\n' "$path" >> "$backup_dir/existing-paths"
done
uplink_was_active=0
systemctl is-active --quiet openap-uplink.service && uplink_was_active=1 || true
uplink_was_enabled=0
systemctl is-enabled --quiet openap-uplink.service && uplink_was_enabled=1 || true
watchdog_was_active=0
systemctl is-active --quiet openap-uplink-watchdog.timer && watchdog_was_active=1 || true
watchdog_was_enabled=0
systemctl is-enabled --quiet openap-uplink-watchdog.timer && watchdog_was_enabled=1 || true

rollback() {
  reason="${1:-Final AP Ethernet verification failed}"
  trap - EXIT HUP INT TERM
  rm -f -- "$hotspot_netdev" "$hotspot_network" "$legacy_ap_network"
  for iface in $ap24_iface $ap5_iface; do
    rm -f -- "/etc/systemd/network/20-${iface}-ap.network"
  done
  while IFS= read -r path; do
    [ -n "$path" ] || continue
    [ -e "$backup_dir$path" ] && cp -a "$backup_dir$path" "$path"
  done < "$backup_dir/existing-paths"
  systemctl restart systemd-networkd.service >/dev/null 2>&1 || true
  systemctl reset-failed hostapd.service dnsmasq.service >/dev/null 2>&1 || true
  systemctl restart hostapd.service dnsmasq.service openap-firewall.service >/dev/null 2>&1 || true
  [ "$uplink_was_enabled" -eq 0 ] || systemctl enable openap-uplink.service >/dev/null 2>&1 || true
  [ "$uplink_was_active" -eq 0 ] || systemctl start openap-uplink.service >/dev/null 2>&1 || true
  [ "$watchdog_was_enabled" -eq 0 ] || systemctl enable openap-uplink-watchdog.timer >/dev/null 2>&1 || true
  [ "$watchdog_was_active" -eq 0 ] || systemctl start openap-uplink-watchdog.timer >/dev/null 2>&1 || true
  echo "openap-apply-ap-ethernet: $reason; previous configuration restored from $backup_dir" >&2
  rm -rf -- "$work_dir"
  exit 1
}
trap rollback HUP INT TERM

install -o www-data -g www-data -m 0640 "$work_dir/repeater.ini" "$profile"
install -o root -g root -m 0644 "$work_dir/dnsmasq.conf" "$dnsmasq_conf"
install -o root -g root -m 0644 "$work_dir/openap.nft" "$nft_conf"
install -o root -g root -m 0644 "$work_dir/ethernet.network" "$ethernet_network"
rm -f -- "$hotspot_netdev" "$hotspot_network"
if [ -n "$profile_bridge" ]; then
  rm -f -- "$legacy_ap_network"
fi
for iface in $ap24_iface $ap5_iface; do rm -f -- "/etc/systemd/network/20-${iface}-ap.network"; done
if [ -n "$profile_bridge" ]; then
  install -o root -g root -m 0644 "$work_dir/hotspot.netdev" "$hotspot_netdev"
  install -o root -g root -m 0644 "$work_dir/hotspot.network" "$hotspot_network"
  for iface in $ap24_iface $ap5_iface; do
    install -o root -g root -m 0644 "$work_dir/${iface}-ap.network" \
      "/etc/systemd/network/20-${iface}-ap.network"
  done
fi

systemctl disable --now openap-uplink-watchdog.timer >/dev/null 2>&1 || true
systemctl stop openap-uplink-watchdog.service >/dev/null 2>&1 || true
systemctl reset-failed openap-uplink-watchdog.service >/dev/null 2>&1 || true
systemctl disable --now openap-uplink.service >/dev/null 2>&1 || true
systemctl restart systemd-networkd.service || rollback "Network manager restart failed"
systemctl reset-failed hostapd.service dnsmasq.service >/dev/null 2>&1 || true
systemctl restart hostapd.service || rollback "WiFi access point restart failed"
/usr/local/sbin/openap-wait-ap-address || rollback "Hotspot gateway did not become ready"
systemctl restart dnsmasq.service || rollback "DHCP/DNS activation failed"
systemctl restart openap-firewall.service || rollback "Firewall activation failed"
if [ -n "$profile_bridge" ]; then
  for iface in $ap24_iface $ap5_iface; do
    ip link set dev "$iface" master "$profile_bridge" || rollback "Unable to attach AP interface $iface to $profile_bridge"
    ip -4 address del "$hotspot_gateway/24" dev "$iface" >/dev/null 2>&1 || true
  done
fi

remaining=15
while [ "$remaining" -gt 0 ]; do
  ip -4 route show default dev "$ethernet_iface" | grep -Fq "via $ethernet_gateway " && break
  remaining=$((remaining - 1)); sleep 1
done
[ "$remaining" -gt 0 ] || rollback "Ethernet gateway $ethernet_gateway did not become active"
ip -4 -o address show dev "$hotspot_iface" | grep -Fq " $hotspot_gateway/" \
  || rollback "Hotspot interface has no gateway address"
wait_hostapd_ready $ap24_iface $ap5_iface \
  || rollback "hostapd is not ready on all hotspot radios"
systemctl is-active --quiet hostapd.service dnsmasq.service openap-firewall.service systemd-networkd.service \
  || rollback "A required OpenAP service is inactive"
nft list table ip openap_nat 2>/dev/null | grep -Fq "oifname \"$ethernet_iface\"" \
  || rollback "NAT does not use the Ethernet uplink"
grep -Fxq "interface=$hotspot_iface" "$dnsmasq_conf" || rollback "dnsmasq is not bound to the hotspot interface"

mkdir -p /run/openap
date +%s > /run/openap/mode-switch
trap cleanup EXIT HUP INT TERM
echo "AP over Ethernet active on $ethernet_iface via $ethernet_gateway"
echo "AP interfaces:$([ -n "$ap24_iface" ] && printf ' %s' "$ap24_iface")$([ -n "$ap5_iface" ] && printf ' %s' "$ap5_iface")"
echo "Hotspot interface: $hotspot_iface ($hotspot_gateway)"
echo "Backup: $backup_dir"
