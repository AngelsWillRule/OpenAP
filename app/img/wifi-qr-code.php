<?php

require_once '../../includes/config.php';
require_once '../../includes/defaults.php';
require_once '../../includes/functions.php';

$hostapd = parse_ini_file(OPENAP_HOSTAPD_CONFIG, false, INI_SCANNER_RAW);

// handle parse failure
if ($hostapd === false) {
    header('HTTP/1.0 500 Internal Server Error');
    exit('Error: Unable to parse hostapd configuration');
}

// assume WPA encryption and get the passphrase
$type = "WPA";
$password = $hostapd['wpa_psk'] ?? $hostapd['wpa_passphrase'] ?? '';

// use WEP if configured
$wep_default_key = intval($hostapd['wep_default_key'] ?? 0);
$wep_key = 'wep_key' . $wep_default_key;
if (array_key_exists($wep_key, $hostapd)) {
    $type = "WEP";
    $password = $hostapd[$wep_key];
}

// if password is still empty, assume nopass
if (empty($password)) {
    $type = "nopass";
}

$ssid = $hostapd['ssid'];
$hidden = intval($hostapd['ignore_broadcast_ssid'] ?? 0) !== 0 ? "H:true" : "";

$ssid = qr_encode($ssid);
$password = qr_encode($password);

$data = "WIFI:S:$ssid;T:$type;P:$password;$hidden;";
$command = "qrencode -t svg -m 1 -o - " . mb_escapeshellarg($data);
$svg = shell_exec($command);

$config_mtime  = filemtime(OPENAP_HOSTAPD_CONFIG);
$last_modified = gmdate('D, d M Y H:i:s ', $config_mtime) . 'GMT';
$etag = hash('sha256', $data);
$content_length = strlen($svg);

header("Content-Type: image/svg+xml; charset=UTF-8");
header("Content-Length: $content_length");
header("Last-Modified: $last_modified");
header(
    !empty($_GET['download'])
        ? 'Content-Disposition: attachment; filename="openap-wifi-qr.svg"'
        : 'Content-Disposition: inline; filename="openap-wifi-qr.svg"'
);
header("ETag: \"$etag\"");
header("X-QR-Code-Content: $data");
echo $svg;
