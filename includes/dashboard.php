<?php

require_once 'includes/config.php';
require_once 'includes/functions.php';

use OpenAP\System\Sysinfo;
use OpenAP\UI\Dashboard;
use OpenAP\Messages\StatusMessage;
use OpenAP\Plugins\PluginManager;
use OpenAP\Networking\Hotspot\WiFiManager;

/**
 * Returns the kernel byte counters for a network interface.
 *
 * @return array{rx_bytes:int,tx_bytes:int}
 */
function openapGetInterfaceTraffic(string $interface): array
{
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $interface)) {
        return [];
    }

    $statisticsPath = '/sys/class/net/' . $interface . '/statistics';
    $rxPath = $statisticsPath . '/rx_bytes';
    $txPath = $statisticsPath . '/tx_bytes';
    if (!is_readable($rxPath) || !is_readable($txPath)) {
        return [];
    }

    $rxBytes = trim((string) file_get_contents($rxPath));
    $txBytes = trim((string) file_get_contents($txPath));
    if (!ctype_digit($rxBytes) || !ctype_digit($txBytes)) {
        return [];
    }

    return [
        'rx_bytes' => (int) $rxBytes,
        'tx_bytes' => (int) $txBytes,
    ];
}

function openapFormatBytes(int $bytes): string
{
    $bytes = max(0, $bytes);
    $units = ['B', 'KB', 'MB', 'GB', 'TB'];
    $value = (float) $bytes;
    $unit = 0;
    while ($value >= 1024 && $unit < count($units) - 1) {
        $value /= 1024;
        $unit++;
    }

    $precision = $unit === 0 ? 0 : ($value >= 100 ? 0 : 1);
    return number_format($value, $precision, '.', '') . ' ' . $units[$unit];
}

function openapGetInterfaceGateway(string $interface): string
{
    if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $interface)) {
        return '';
    }

    $output = [];
    $returnCode = 1;
    exec(
        '/usr/sbin/ip -4 route show default dev ' . escapeshellarg($interface) . ' 2>/dev/null',
        $output,
        $returnCode
    );
    if ($returnCode !== 0) {
        return '';
    }

    foreach ($output as $route) {
        if (preg_match('/(?:^|\s)via\s+([0-9.]+)(?:\s|$)/', $route, $matches)
            && filter_var($matches[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) !== false) {
            return $matches[1];
        }
    }

    return '';
}

/**
 * Displays the dashboard
 */
function DisplayDashboard(string $template = 'dashboard'): void
{
    global $extraFooterScripts;
    $extraFooterScripts[] = [
        'src' => 'app/js/ui/dashboard.js?v=' . filemtime('app/js/ui/dashboard.js'),
        'defer' => false,
    ];
    if ($template === 'ap_configuration') {
        $extraFooterScripts[] = [
            'src' => 'app/js/ui/ap_configuration.js?v=' . filemtime('app/js/ui/ap_configuration.js'),
            'defer' => false,
        ];
    } elseif ($template === 'dhcp_setting') {
        $extraFooterScripts[] = [
            'src' => 'app/js/ui/dhcp_setting.js?v=' . filemtime('app/js/ui/dhcp_setting.js'),
            'defer' => false,
        ];
    } elseif ($template === 'logging') {
        $extraFooterScripts[] = [
            'src' => 'app/js/ui/logging.js?v=' . filemtime('app/js/ui/logging.js'),
            'defer' => false,
        ];
    }
    // instantiate RaspAP objects
    $system = new Sysinfo();
    $dashboard = new Dashboard();
    $status = new StatusMessage();
    openapLoadDashboardFlashMessages($status);
    if ($template === 'dhcp_setting' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['SaveDhcpSettings'])) {
        openapHandleDhcpSettings($status);
    }
    if ($template === 'dhcp_setting' && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['SaveEncryptedDns'])) {
        $_SESSION['openap_encrypted_dns_result'] = openapHandleEncryptedDns($status);
        header('Location: /dhcp_setting', true, 303);
        exit;
    }
    if (isset($_GET['dhcp_upstream'])) {
        $status->addMessage(_('DHCP settings are managed by the upstream router while Ethernet Bridge mode is active.'), 'info');
    }
    if (isset($_GET['rebooting'])) {
        $status->addMessage(_('System reboot requested. OpenAP will be temporarily unavailable.'), 'info');
    }
    if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['dashboard_action'])) {
        openapHandleDashboardAction((string) $_POST['dashboard_action'], $status);
        openapStoreDashboardFlashMessages($status);
        header('Location: /', true, 303);
        exit;
    }
    $pluginManager = PluginManager::getInstance();
    $wifi = new WiFiManager();

    $modeSwitchSettling = false;
    $modeSwitchMarker = '/run/openap/mode-switch';
    if (is_readable($modeSwitchMarker)) {
        $modeSwitchAt = (int) trim((string) @file_get_contents($modeSwitchMarker));
        $modeSwitchSettling = $modeSwitchAt > 0 && (time() - $modeSwitchAt) < 25;
    }
    // set AP and client interface session vars
    $wifi->getWifiInterface();

    $interface = $_SESSION['ap_interface'] ?? 'wlan0';
    $clientInterface = $_SESSION['wifi_client_interface'];
    $hostname = $system->hostname();
    $revision = $system->rpiRevision();
    $deviceImage = $dashboard->getDeviceImage($revision);
    $hostapd = $system->hostapdStatus();
    $adblock = $system->adBlockStatus();
    $vpn = $system->getActiveVpnInterface();
    $frequency = $dashboard->getFrequencyBand($interface);
    $details = $dashboard->getInterfaceDetails($interface);
    $wireless = $dashboard->getWirelessDetails($interface);
    $connectionInterface = $dashboard->getConnectionInterface();
    $connectionType = $dashboard->getConnectionType($connectionInterface);
    $connectionIcon = $dashboard->getConnectionIcon($connectionType);
    $publicDetails = $dashboard->getInterfaceDetails($connectionInterface);
    $publicWireless = $dashboard->getWirelessDetails($connectionInterface);
    $wirelessClients = $dashboard->getWirelessClients($interface);
    $ethernetClients = $dashboard->getEthernetClients();
    $totalClients = $wirelessClients + $ethernetClients;
    $plugins = $pluginManager->getInstalledPlugins();
    $bridgedEnable = getBridgedState();

    if ($bridgedEnable) {
        $interface = 'br0';
        $details = $dashboard->getInterfaceDetails($interface);
        $connectionType = 'ethernet';
    }
    
    $ipv4Address = $details['ipv4'];
    $ipv4Netmask = $details['ipv4_netmask'];
    $macAddress = $details['mac'];
    $ssid = $wireless['ssid'];
    $publicIpv4Address = $publicDetails['ipv4'];
    $publicIpv4Netmask = $publicDetails['ipv4_netmask'];
    $publicMacAddress = $publicDetails['mac'];
    $publicSsid = $publicWireless['ssid'];
    $ethernetActive = ($connectionType === 'ethernet') ? "active" : "inactive";
    $wirelessActive = ($connectionType === 'wireless') ? "active" : "inactive";
    $tetheringActive = ($connectionType === 'tethering') ? "active" : "inactive";
    $cellularActive = ($connectionType === 'cellular') ? "active" : "inactive";
    $bridgedStatus = ($bridgedEnable == 1) ? "active" : "";
    $hostapdStatus = ($hostapd[0] == 1) ?  "active" : "";
    $adblockStatus = ($adblock == true) ?  "active" : "";
    $wirelessClientActive = ($wirelessClients > 0) ? "active" : "inactive";
    $wirelessClientLabel = sprintf(
        _('%d WLAN %s'),
        $wirelessClients,
        $dashboard->formatClientLabel($wirelessClients)
    );
    $ethernetClientActive = ($ethernetClients > 0) ? "active" : "inactive";
    $ethernetClientLabel = sprintf(
        _('%d LAN %s'),
        $ethernetClients,
        $dashboard->formatClientLabel($ethernetClients)
    );
    $totalClientsActive = ($totalClients > 0) ? "active": "inactive";
    $freq5active = $freq24active = "";
    $varName = "freq" . str_replace('.', '', $frequency) . "active";
    $$varName = "active";
    $vpnStatus = $vpn ? "active" : "inactive";
    $vpnManaged = $vpn ? $dashboard->getVpnManaged($vpn) : null;
    $firewallManaged = $firewallStatus = "";
    $firewallInstalled = (bool) array_filter($plugins, function($p) {
        return substr($p, -strlen('Firewall')) === 'Firewall';
    });
    if (!$firewallInstalled) {
        $firewallUnavailable = '<i class="fas fa-slash fa-stack-2x"></i>';
    } else {
        $firewallManaged = '<a href="/plugin__Firewall">';
        $firewallStatus = ($dashboard->firewallEnabled() == true) ? "active" : "";
        $firewallUnavailable = null;
    }

    // ============================================================
    // New: additional data for enhanced dashboard
    // ============================================================

    // System metrics
    $cpuPercent   = $system->systemLoadPercentage();
    $memUsedPct   = $system->usedMemory();
    $diskUsedPct  = $system->usedDisk();
    $sysTemp      = $system->systemTemperature();
    $sysUptime    = $system->uptime();
    $loadAvg      = $system->loadAvg1Min();
    $memInfo      = @file_get_contents('/proc/meminfo');
    $memTotal     = 0;
    if ($memInfo && preg_match('/MemTotal:\s+(\d+)/', $memInfo, $m)) {
        $memTotal = round((int)$m[1] / 1024);
    }

    $dashboardServiceLogs = openapDashboardServiceLogs();
    $serviceLogs = [];
    if ($template === 'logging') {
        $loggingServiceNames = ['hostapd', 'dnsmasq', 'lighttpd'];
        if (is_file('/etc/dnscrypt-proxy/dnscrypt-proxy.toml')) {
            $loggingServiceNames[] = 'dnscrypt-proxy';
        }
        foreach ($loggingServiceNames as $logService) {
            $serviceLogs[$logService] = openapReadServiceLog($logService);
        }
    }

    // Operating profile
    $profile = function_exists('openapReadRepeaterProfile') ? openapReadRepeaterProfile() : [];
    $currentMode = str_replace('-', '_', $profile['mode']['current'] ?? 'ap_ethernet');
    $isRepeaterWifi = ($currentMode === 'repeater_wifi');
    $uplinkIface = $isRepeaterWifi
        ? ($profile['interfaces']['uplink'] ?? ($_SESSION['wifi_client_interface'] ?? 'wlan1'))
        : ($profile['interfaces']['ethernet'] ?? 'eth0');
    $uplinkGateway = openapGetInterfaceGateway($uplinkIface);
    $uplinkLabel = $isRepeaterWifi ? _('WiFi uplink') : _('Ethernet uplink');
    $uplinkKind = $isRepeaterWifi ? 'wireless' : 'ethernet';
    $repeaterWifiInterfaces = function_exists('openapDetectWirelessInterfaces') ? openapDetectWirelessInterfaces() : [];
    $repeaterRoles = function_exists('openapSelectedRepeaterRoles') ? openapSelectedRepeaterRoles($repeaterWifiInterfaces) : ['valid' => false];
    $repeaterWifiCount = count($repeaterWifiInterfaces);
    $repeaterModeAvailable = $repeaterWifiCount >= 2 && !empty($repeaterRoles['valid']);
    $repeaterModeUnavailableReason = $repeaterWifiCount >= 2
        ? _('Repeater mode requires one AP-capable and one managed-capable WiFi interface.')
        : sprintf(_('Repeater mode requires at least 2 WiFi interfaces. Detected: %d.'), $repeaterWifiCount);

    // DHCP pool info
    $dhcpPool = function_exists('openapDhcpPoolInfo') ? openapDhcpPoolInfo() : ['active' => 0, 'total' => 150];

    // Client list (AP side). Keep counters tied to OpenAP's real AP interface
    // so mode switches do not leave stale generic RaspAP client counts behind.
    $apIface = $profile['interfaces']['ap'] ?? $interface;
    $clientList = function_exists('openapGetClientList') ? openapGetClientList($apIface) : [];
    if ($currentMode === 'ap_ethernet_bridge' && function_exists('getInterfaceNeighbors')) {
        $bridgeNeighbors = getInterfaceNeighbors($uplinkIface);
        foreach ($clientList as &$bridgeClient) {
            $bridgeMac = strtolower((string) ($bridgeClient['mac'] ?? ''));
            $bridgeClient['ip'] = $bridgeNeighbors[$bridgeMac]['ip'] ?? '-';
            $bridgeClient['state'] = $bridgeNeighbors[$bridgeMac]['state'] ?? '-';
        }
        unset($bridgeClient);
    }
    usort($clientList, static function (array $left, array $right): int { return ((int) ($right['traffic_bytes'] ?? 0)) <=> ((int) ($left['traffic_bytes'] ?? 0)); });    $topClientTraffic = 0;    foreach ($clientList as $trafficClient) { if (!empty($trafficClient['connected'])) { $topClientTraffic = max($topClientTraffic, (int) ($trafficClient['traffic_bytes'] ?? 0)); } }    foreach ($clientList as &$trafficClient) { $trafficClient['top_usage'] = $topClientTraffic > 0 && (int) ($trafficClient['traffic_bytes'] ?? 0) === $topClientTraffic; }    unset($trafficClient);
    $clientBreakdown = function_exists('openapGetClientDeviceBreakdown') ? openapGetClientDeviceBreakdown($apIface) : ['total' => 0, 'avg_signal' => 0, 'strong' => 0, 'medium' => 0, 'weak' => 0];
    $wirelessClients = 0;
    foreach ($clientList as $client) {
        if (!empty($client['connected'])) {
            $wirelessClients++;
        }
    }
    $ethernetClients = 0;
    $totalClients = $wirelessClients;
    $wirelessClientActive = ($wirelessClients > 0) ? "active" : "inactive";
    $wirelessClientLabel = sprintf(
        _('%d WLAN %s'),
        $wirelessClients,
        $dashboard->formatClientLabel($wirelessClients)
    );
    $ethernetClientActive = "inactive";
    $ethernetClientLabel = sprintf(
        _('%d LAN %s'),
        0,
        $dashboard->formatClientLabel(0)
    );
    $totalClientsActive = ($totalClients > 0) ? "active" : "inactive";

    // Interface traffic stats
    $trafficAp  = function_exists('openapGetInterfaceTraffic') ? openapGetInterfaceTraffic($apIface) : [];
    $trafficUplink = function_exists('openapGetInterfaceTraffic') ? openapGetInterfaceTraffic($uplinkIface) : [];

    // Read repeater uplink details directly from nl80211. Avoid wpa_cli here:
    // with Ubuntu 26.04 sudo-rs under PHP-CGI it can hold requests for 10s.
    $uplinkDetails = [];
    $uplinkSignal = [];
    $uplinkHealth = $isRepeaterWifi && function_exists('openapUplinkHealth')
        ? openapUplinkHealth()
        : ['ready' => true, 'reason' => 'Ethernet uplink'];
    if ($isRepeaterWifi && preg_match('/^[A-Za-z0-9_.:-]+$/', $uplinkIface)) {
        $uplinkLinkOutput = [];
        exec('/usr/sbin/iw dev ' . escapeshellarg($uplinkIface) . ' link 2>/dev/null', $uplinkLinkOutput, $uplinkLinkReturn);
        $uplinkLink = implode("\n", $uplinkLinkOutput);
        $uplinkIsConnected = !empty($uplinkHealth['ready']);
        if (preg_match('/^[[:space:]]*SSID:[[:space:]]*(.+)$/m', $uplinkLink, $matches)) {
            $uplinkDetails['ssid'] = trim($matches[1]);
        }
        $uplinkDetails['wpa_state'] = $uplinkIsConnected ? 'COMPLETED' : 'DISCONNECTED';
        $uplinkDetails['ip_address'] = function_exists('openapInterfaceIpv4') ? openapInterfaceIpv4($uplinkIface) : '-';
        if (preg_match('/^[[:space:]]*freq:[[:space:]]*([0-9.]+)/m', $uplinkLink, $matches)) {
            $uplinkFrequency = (int) round((float) $matches[1]);
            $uplinkSignal['FREQUENCY'] = (string) $uplinkFrequency;
            $uplinkSignal['CHANNEL'] = function_exists('openapFrequencyChannel') ? openapFrequencyChannel($uplinkFrequency) : '-';
        }
        if (preg_match('/^[[:space:]]*signal:[[:space:]]*(-?[0-9.]+)[[:space:]]+dBm/m', $uplinkLink, $matches)) {
            $uplinkSignal['RSSI'] = (string) round((float) $matches[1]);
        }
    } elseif (!$isRepeaterWifi) {
        $uplinkDetails = [
            'ssid' => 'Ethernet',
            'wpa_state' => 'COMPLETED',
            'ip_address' => function_exists('openapInterfaceIpv4') ? openapInterfaceIpv4($uplinkIface) : ($publicIpv4Address ?? '-'),
        ];
    }

    // Service status array
    $serviceList = [
        'hostapd'  => function_exists('openapServiceActive') ? openapServiceActive('hostapd.service') === 'active' : false,
        'dnsmasq'  => function_exists('openapServiceActive') ? openapServiceActive('dnsmasq.service') === 'active' : false,
        'nftables' => function_exists('openapNatActive') ? openapNatActive() : (function_exists('openapServiceActive') ? openapServiceActive('nftables.service') === 'active' : false),
        'lighttpd' => function_exists('openapServiceActive') ? openapServiceActive('lighttpd.service') === 'active' : false,
        'dnscrypt' => function_exists('openapServiceActive') ? openapServiceActive('dnscrypt-proxy.service') === 'active' : false,
        'openvpn'  => $vpn !== null,
    ];
    if ($isRepeaterWifi) {
        $serviceList['wpa_supplicant'] = !empty($uplinkHealth['ready']);
    }

    // Band distribution (if we know total clients, estimate from frequency)
    $band5Count = $frequency === '5' ? $totalClients : 0;
    $band24Count = $frequency === '2.4' ? $totalClients : 0;

    // Determine available 5GHz channels from iw (filters out NO-IR channels)
    $available5ghzChannels = [];
    $channelTxPowerLimits = [];
    try {
        $parser = new \OpenAP\Parsers\IwParser($interface);
        $freqs = $parser->parseIwInfo();
        foreach ($freqs as $f) {
            $channelTxPowerLimits[(int) $f['Channel']] = max(1, min(30, (int) floor($f['dBm'])));
            if ($f['MHz'] >= 5000) {
                $available5ghzChannels[] = $f['Channel'];
            }
        }
    } catch (\Exception $e) {
        // fallback: only channels confirmed working with world reg domain (00)
        $available5ghzChannels = [36, 40, 44];
    }

    // Hostapd client details
    // Use the same systemd-backed status shown in the services panel. The
    // legacy pidof check can report a stale/false state in VM and sudo-rs
    // environments even while hostapd is serving the configured AP.
    // A hostapd process may survive USB passthrough loss. Treat the AP as
    // enabled only when its configured interface, MAC and gateway are live.
    $hostapdEnabled = (bool) ($serviceList['hostapd'] ?? false)
        && function_exists('openapApInterfaceReady')
        && openapApInterfaceReady();
    $apChannel = '';
    $apHwMode = 'n';
    $apSecurityType = 'WPA2-PSK';
    $apSecurityMode = '2';
    $apEncryption = 'CCMP';
    $apPsk = '';
    $apTxPower = '20 dBm';
    $apTxPowerMax = 30;
    $apCountry = 'IT';
    $apWmm = true;
    $apIsolation = false;
    $apIgnoreBroadcast = false;
    $apFreq = '';
    $apWidth = 20;
    $ap80211n = false;
    $ap80211ac = false;
    $apHtCapab = '';
    $apVhtOperChwidth = '';

    // Read hostapd config file for PSK, security, hw_mode
    $hostapdConfFile = defined('OPENAP_HOSTAPD_CONFIG')
        ? OPENAP_HOSTAPD_CONFIG
        : '/etc/hostapd/hostapd.conf';
    if (is_readable($hostapdConfFile)) {
        $confContent = file_get_contents($hostapdConfFile);
        if (!empty($confContent)) {
            // Parse key hostapd settings
            $patterns = [
                'hw_mode' => '/^hw_mode=(.+)$/m',
                'wpa_passphrase' => '/^wpa_passphrase=(.+)$/m',
                'country_code' => '/^country_code=(.+)$/m',
                'wpa' => '/^wpa=(.+)$/m',
                'wpa_pairwise' => '/^wpa_pairwise=(.+)$/m',
                'rsn_pairwise' => '/^rsn_pairwise=(.+)$/m',
                'wmm_enabled' => '/^wmm_enabled=(.+)$/m',
                'ap_isolate' => '/^ap_isolate=(.+)$/m',
                'ignore_broadcast_ssid' => '/^ignore_broadcast_ssid=(.+)$/m',
                'ieee80211n' => '/^ieee80211n=(.+)$/m',
                'ieee80211ac' => '/^ieee80211ac=(.+)$/m',
                'ht_capab' => '/^ht_capab=(.+)$/m',
                'vht_oper_chwidth' => '/^vht_oper_chwidth=(.+)$/m',
            ];
            foreach ($patterns as $key => $pattern) {
                if (preg_match($pattern, $confContent, $m)) {
                    $val = trim($m[1]);
                    switch ($key) {
                        case 'hw_mode': $apHwMode = $val; break;
                        case 'wpa_passphrase': $apPsk = $val; break;
                        case 'country_code': $apCountry = $val; break;
                        case 'wpa':
                            $apSecurityMode = $val;
                            if ($val == '1') $apSecurityType = 'WPA-PSK';
                            elseif ($val == '2') $apSecurityType = 'WPA2-PSK';
                            elseif ($val == '3') $apSecurityType = 'WPA3-SAE';
                            break;
                        case 'wpa_pairwise':
                        case 'rsn_pairwise':
                            if (!empty($val)) $apEncryption = $val;
                            break;
                        case 'wmm_enabled': $apWmm = ($val == '1'); break;
                        case 'ap_isolate': $apIsolation = ($val == '1'); break;
                        case 'ignore_broadcast_ssid': $apIgnoreBroadcast = ($val == '1'); break;
                        case 'ieee80211n': $ap80211n = ($val == '1'); break;
                        case 'ieee80211ac': $ap80211ac = ($val == '1'); break;
                        case 'ht_capab': $apHtCapab = $val; break;
                        case 'vht_oper_chwidth': $apVhtOperChwidth = $val; break;
                    }
                }
            }
            if (!preg_match('/^wpa=.+$/m', $confContent)) {
                $apSecurityMode = 'none';
                $apSecurityType = 'None (open network)';
                $apEncryption = 'None';
                $apPsk = '';
            }

            if ($ap80211ac) {
                $apHwMode = 'ac';
            } elseif ($ap80211n) {
                $apHwMode = 'n';
            }
            if ($apVhtOperChwidth === '1') {
                $apWidth = 80;
            } elseif (preg_match('/\[HT40[+-]\]/', $apHtCapab)) {
                $apWidth = 40;
            }
        }
    }

    // Prefer live radio values from nl80211. This remains available to the web
    // process without sudo and avoids losing channel details when hostapd_cli
    // cannot be executed from a particular FastCGI/sudo implementation.
    if (preg_match('/^[A-Za-z0-9_.-]+$/', $apIface)) {
        $liveRadioOutput = [];
        exec('/usr/sbin/iw dev ' . escapeshellarg($apIface) . ' info 2>/dev/null', $liveRadioOutput, $liveRadioReturn);
        if ($liveRadioReturn === 0) {
            $liveRadio = implode("\n", $liveRadioOutput);
            if (preg_match('/^[[:space:]]*channel[[:space:]]+([0-9]+)[[:space:]]+\(([0-9]+)[[:space:]]+MHz\),[[:space:]]+width:[[:space:]]+([0-9]+)[[:space:]]+MHz/m', $liveRadio, $matches)) {
                $apChannel = $matches[1];
                $apFreq = $matches[2];
                $apWidth = (int) $matches[3];
            }
            if (preg_match('/^[[:space:]]*txpower[[:space:]]+([0-9.]+)[[:space:]]+dBm/m', $liveRadio, $matches)) {
                $reportedTxPower = (float) $matches[1];
                $apTxPowerMax = $channelTxPowerLimits[(int) $apChannel] ?? 30;
                $apTxPower = min($reportedTxPower, (float) $apTxPowerMax) . ' dBm';
            }
        }
    }

    echo renderTemplate(
        $template, compact(
            // Original vars
            "revision",
            "deviceImage",
            "interface",
            "clientInterface",
            "bridgedStatus",
            "hostapdStatus",
            "adblockStatus",
            "vpnStatus",
            "vpnManaged",
            "firewallUnavailable",
            "firewallStatus",
            "firewallManaged",
            "ipv4Address",
            "ipv4Netmask",
            "macAddress",
            "ssid",
            "publicIpv4Address",
            "publicIpv4Netmask",
            "publicMacAddress",
            "publicSsid",
            "frequency",
            "freq5active",
            "freq24active",
            "wirelessClients",
            "wirelessClientLabel",
            "wirelessClientActive",
            "ethernetClients",
            "ethernetClientLabel",
            "ethernetClientActive",
            "totalClients",
            "totalClientsActive",
            "connectionType",
            "connectionIcon",
            "ethernetActive",
            "wirelessActive",
            "tetheringActive",
            "cellularActive",
            "status",
            // New vars
            "currentMode",
            "modeSwitchSettling",
            "isRepeaterWifi",
            "uplinkLabel",
            "uplinkKind",
            "repeaterWifiCount",
            "repeaterModeAvailable",
            "repeaterModeUnavailableReason",
            "cpuPercent",
            "memUsedPct",
            "diskUsedPct",
            "sysTemp",
            "sysUptime",
            "loadAvg",
            "memTotal",
            "dhcpPool",
            "clientList",
            "clientBreakdown",
            "trafficAp",
            "trafficUplink",
            "uplinkDetails",
            "uplinkSignal",
            "uplinkHealth",
            "serviceList",
            "band5Count",
            "band24Count",
            "hostapdEnabled",
            "apChannel",
            "apHwMode",
            "apSecurityType",
            "apSecurityMode",
            "apEncryption",
            "apPsk",
            "apTxPower",
            "apTxPowerMax",
            "channelTxPowerLimits",
            "apCountry",
            "apWmm",
            "apIsolation",
            "apIgnoreBroadcast",
            "apFreq",
            "apWidth",
            "available5ghzChannels",
            "apIface",
            "uplinkIface",
            "uplinkGateway",
            "dashboardServiceLogs",
            "serviceLogs"
        )
    );
}

function openapHandleDhcpSettings(StatusMessage $status): void
{
    $profile = function_exists('openapReadRepeaterProfile') ? openapReadRepeaterProfile() : [];
    if (($profile['mode']['current'] ?? '') === 'ap_ethernet_bridge') {
        $status->addMessage('DHCP settings are read-only in Ethernet Bridge mode.', 'danger');
        return;
    }
    $values = [
        'subnet' => trim((string) ($_POST['dhcp_subnet'] ?? '')),
        'gateway' => trim((string) ($_POST['dhcp_gateway'] ?? '')),
        'start' => trim((string) ($_POST['dhcp_start'] ?? '')),
        'end' => trim((string) ($_POST['dhcp_end'] ?? '')),
        'lease' => trim((string) ($_POST['dhcp_lease_time'] ?? '')),
        'policy' => trim((string) ($_POST['dhcp_dns_policy'] ?? '')),
        'advertised' => trim((string) ($_POST['dhcp_advertised_dns'] ?? '')),
        'upstream' => trim((string) ($_POST['dhcp_upstream_dns'] ?? '')),
    ];
    if (!preg_match('/^([0-9.]+)\/(\d{1,2})$/', $values['subnet'], $subnetMatch)
        || filter_var($subnetMatch[1], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false
        || (int) $subnetMatch[2] !== 24) {
        $status->addMessage('Invalid hotspot subnet. OpenAP DHCP uses a fixed /24 network.', 'danger');
        return;
    }
    $prefix = (int) $subnetMatch[2];
    $mask = (0xffffffff << (32 - $prefix)) & 0xffffffff;
    $network = ip2long($subnetMatch[1]);
    if (($network & $mask) !== $network) {
        $status->addMessage('The subnet must use its network address.', 'danger');
        return;
    }
    $apInterface = (string) ($profile['interfaces']['ap'] ?? '');
    $uplinkInterface = (string) ($profile['interfaces']['uplink'] ?? '');
    $addressOutput = [];
    exec('/usr/sbin/ip -4 -o address show scope global 2>/dev/null', $addressOutput);
    foreach ($addressOutput as $addressLine) {
        if (!preg_match('/^\d+:\s+(\S+)\s+inet\s+([0-9.]+)\/(\d{1,2})\b/', $addressLine, $addressMatch)) {
            continue;
        }
        $interface = rtrim($addressMatch[1], ':');
        if ($interface === $apInterface) {
            continue;
        }
        $address = ip2long($addressMatch[2]);
        $interfacePrefix = (int) $addressMatch[3];
        if ($address === false || $interfacePrefix < 0 || $interfacePrefix > 32) {
            continue;
        }
        $interfaceMask = $interfacePrefix === 0
            ? 0
            : ((0xffffffff << (32 - $interfacePrefix)) & 0xffffffff);
        $interfaceNetwork = $address & $interfaceMask;
        $interfaceBroadcast = $interfaceNetwork | (~$interfaceMask & 0xffffffff);
        $requestedBroadcast = $network | (~$mask & 0xffffffff);
        if ($network <= $interfaceBroadcast && $interfaceNetwork <= $requestedBroadcast) {
            $role = $interface === $uplinkInterface ? 'WiFi uplink' : 'interface';
            $status->addMessage(
                sprintf(
                    'Hotspot subnet %s overlaps the %s %s (%s/%d). Choose a different /24 network so client and uplink routes remain unambiguous.',
                    $values['subnet'],
                    $role,
                    $interface,
                    $addressMatch[2],
                    $interfacePrefix
                ),
                'danger'
            );
            return;
        }
    }
    if (ip2long($values['gateway']) !== $network + 1) {
        $status->addMessage('The gateway must be the first usable address in the /24 subnet.', 'danger');
        return;
    }
    $ips = [];
    foreach (['gateway', 'start', 'end'] as $key) {
        if (filter_var($values[$key], FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false) {
            $status->addMessage('Gateway and DHCP range must contain valid IPv4 addresses.', 'danger');
            return;
        }
        $ips[$key] = ip2long($values[$key]);
        if (($ips[$key] & $mask) !== $network || $ips[$key] === $network || $ips[$key] === ($network | (~$mask & 0xffffffff))) {
            $status->addMessage('Gateway and DHCP range must be usable addresses in the selected subnet.', 'danger');
            return;
        }
    }
    if ($ips['start'] > $ips['end'] || ($ips['gateway'] >= $ips['start'] && $ips['gateway'] <= $ips['end'])) {
        $status->addMessage('The DHCP range is invalid or contains the gateway address.', 'danger');
        return;
    }
    if (!preg_match('/^[1-9][0-9]*[mhdw]$/i', $values['lease'])) {
        $status->addMessage('Invalid lease time. Examples: 30m, 12h, 7d.', 'danger');
        return;
    }
    $encryptedDnsState = is_readable('/etc/openap/encrypted-dns.ini')
        ? parse_ini_file('/etc/openap/encrypted-dns.ini', true, INI_SCANNER_TYPED)
        : [];
    if (!empty($encryptedDnsState['encrypted_dns']['enabled'])) {
        $values['policy'] = 'local';
        $values['advertised'] = $values['gateway'];
        $values['upstream'] = '127.0.2.1';
    }
    if (!in_array($values['policy'], ['local', 'external'], true)) {
        $status->addMessage('Invalid DNS policy.', 'danger');
        return;
    }
    foreach (['advertised', 'upstream'] as $key) {
        $dnsAddresses = array_filter(array_map('trim', explode(',', $values[$key])));
        if ($dnsAddresses === [] || array_filter($dnsAddresses, fn($ip) => filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4) === false)) {
            $status->addMessage('DNS fields must contain comma-separated IPv4 addresses.', 'danger');
            return;
        }
        $values[$key] = implode(',', $dnsAddresses);
    }
    $command = 'sudo /usr/local/sbin/openap-apply-dhcp-settings --delayed';
    foreach ($values as $value) {
        $command .= ' ' . escapeshellarg($value);
    }
    exec($command . ' 2>&1', $output, $return);
    if ($return !== 0) {
        $status->addMessage('Unable to apply DHCP settings: ' . implode(' ', $output), 'danger');
        return;
    }
    $status->addMessage('DHCP settings saved successfully.', 'success');
}

function openapHandleEncryptedDns(StatusMessage $status): array
{
    $enabled = (string) ($_POST['encrypted_dns_enabled'] ?? '0');
    $provider = trim((string) ($_POST['encrypted_dns_provider'] ?? ''));
    if (!in_array($enabled, ['0', '1'], true)) {
        return ['success' => false, 'message' => _('Invalid Encrypted DNS state.')];
    }
    if (!in_array($provider, ['cloudflare', 'quad9'], true)) {
        return ['success' => false, 'message' => _('Unsupported Encrypted DNS provider.')];
    }

    $mode = $enabled === '1' ? 'enable' : 'disable';
    $command = 'sudo /usr/local/sbin/openap-apply-encrypted-dns '
        . escapeshellarg($mode) . ' ' . escapeshellarg($provider);
    exec($command . ' 2>&1', $output, $return);
    if ($return !== 0) {
        return [
            'success' => false,
            'message' => _('Unable to apply Encrypted DNS settings: ') . implode(' ', $output),
        ];
    }

    return [
        'success' => true,
        'message' => $enabled === '1'
            ? _('Encrypted DNS enabled successfully.')
            : _('Encrypted DNS disabled; direct public DNS restored.'),
    ];
}



function openapLoadDashboardFlashMessages(StatusMessage $status): void
{
    if (empty($_SESSION['openap_dashboard_flash']) || !is_array($_SESSION['openap_dashboard_flash'])) {
        return;
    }
    foreach ($_SESSION['openap_dashboard_flash'] as $message) {
        if (is_string($message) && $message !== '') {
            $status->messages[] = $message;
        }
    }
    unset($_SESSION['openap_dashboard_flash']);
}

function openapStoreDashboardFlashMessages(StatusMessage $status): void
{
    $_SESSION['openap_dashboard_flash'] = $status->messages;
}

function openapHandleDashboardAction(string $action, StatusMessage $status): void
{
    $commands = [
        'restart_ap' => [
            'label' => 'AP service',
            'cmd' => 'sudo /bin/systemctl restart hostapd.service',
            'wait' => 'ap',
        ],
        'restart_dhcp' => [
            'label' => 'DHCP/DNS service',
            'cmd' => 'sudo /bin/systemctl restart dnsmasq.service',
            'wait' => 'dhcp',
        ],
    ];

    if (!isset($commands[$action])) {
        $status->addMessage('Unknown dashboard action.', 'danger');
        return;
    }

    exec($commands[$action]['cmd'] . ' 2>&1', $output, $return);
    if ($return !== 0) {
        $status->addMessage('Failed to restart ' . $commands[$action]['label'] . ': ' . implode(' ', $output), 'danger');
        return;
    }

    $ready = true;
    if ($commands[$action]['wait'] === 'ap' && function_exists('openapWaitAfterRepeaterAction')) {
        $ready = openapWaitAfterRepeaterAction('restart_ap');
    } elseif ($commands[$action]['wait'] === 'dhcp' && function_exists('openapWaitAfterRepeaterAction')) {
        $ready = openapWaitAfterRepeaterAction('restart_dhcp');
    }

    $status->addMessage('Restarted ' . $commands[$action]['label'] . '.', 'success');
    if (!$ready) {
        $status->addMessage('Service restarted, but status is still settling. Refresh the page in a few seconds.', 'warning');
    }
}

function openapDashboardServiceLogs(): string
{
    $command = 'sudo /bin/journalctl -u hostapd.service -u dnsmasq.service -n 120 --no-pager --output=short-iso 2>&1';
    exec($command, $output, $return);
    if ($return !== 0) {
        return 'Unable to read service logs: ' . implode("\n", $output);
    }
    return trim(implode("\n", $output));
}

function openapReadServiceLog(string $service): string
{
    $allowed = ['hostapd', 'dnsmasq', 'lighttpd', 'dnscrypt-proxy'];
    if (!in_array($service, $allowed, true)) {
        return _('Invalid service.');
    }
    $command = 'sudo /usr/bin/journalctl -u ' . escapeshellarg($service . '.service')
        . ' -n 240 --no-pager --output=short-iso 2>&1';
    exec($command, $output, $return);
    $log = trim(implode("\n", $output));
    if ($return !== 0) {
        return _('Unable to read this service log.') . ($log !== '' ? "\n" . $log : '');
    }
    return $log !== '' ? $log : _('No recent log entries.');
}

/**
 * Renders a URL for an svg solid line representing the associated
 * connection type
 *
 * @param string $connectionType
 * @return string
 */
function renderConnection(string $connectionType): string
{
    $deviceMap = [
        'ethernet'  => 'device-1',
        'wireless'  => 'device-2',
        'tethering' => 'device-3',
        'cellular'  => 'device-4'
    ];
    if (!isset($deviceMap[$connectionType])) {
        return 'app/img/solid.php';
    }

    return sprintf('app/img/solid.php?joint&%s&out', $deviceMap[$connectionType]);
}

/**
 * Renders a URL for an svg solid line representing associated
 * client connection(s)
 *
 * @param int $wirelessClients
 * @param int $ethernetClients
 * @return string
 */
function renderClientConnections(int $wirelessClients, int $ethernetClients): string
{
    $devices = [];

    if ($wirelessClients > 0) {
        $devices[] = 'device-1&out';
    }
    if ($ethernetClients > 0) {
        $devices[] = 'device-2&out';
    }
    return empty($devices) ? '' : sprintf(
        '<img src="app/img/right-solid.php?%s" class="solid-lines solid-lines-right" alt="Client connections">',
        implode('&', $devices)
    );
}
