<?php
$apDisplayInterface = $apIface ?: $interface;
$apDisplayChannel = $apChannel ?: '-';
$dhcpActive = (int) ($dhcpPool['active'] ?? 0);
$dhcpTotal = (int) ($dhcpPool['total'] ?? 150);
$dhcpRange = ($dhcpPool['range_start'] ?? '10.88.77.50').' - '.($dhcpPool['range_end'] ?? '10.88.77.200');
$dhcpLeaseTime = $dhcpPool['lease_time'] ?? '12h';
$dhcpDns = $dhcpPool['dns'] ?? '10.88.77.1';
$dhcpPercent = $dhcpTotal > 0 ? min(100, (int) round(($dhcpActive / $dhcpTotal) * 100)) : 0;
$apConfigurationServices = [
    'hostapd' => ['hostapd', htmlspecialchars($interface).' AP'],
    'dnsmasq' => ['dnsmasq', 'DHCP + DNS'],
    'nftables' => ['nftables', 'NAT / Firewall'],
];
if ($isRepeaterWifi) {
    $apConfigurationServices['wpa_supplicant'] = ['wpa_supplicant', htmlspecialchars($uplinkIface).' uplink'];
}
$apConfigurationServices['lighttpd'] = ['lighttpd', 'Web server'];
?>

<div class="container-fluid p-0 openap-ap-configuration-page">
  <?php $status->showMessages(); ?>

  <div class="row g-3 mb-3">
    <div class="col-xl-9 col-lg-8">
      <?php $openapWifiHotspotCardHeader = true; require __DIR__ . '/wifi_hotspot.php'; ?>

      <div class="openap-section-heading openap-ap-configuration-heading">
        <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas fa-broadcast-tower"></i></span>
        <div><strong><?php echo _("AP Configuration"); ?></strong><small><?php echo _("Wireless access point settings"); ?></small></div>
      </div>
      <div class="card shadow openap-ap-config-panel" id="apConfigurationPanel">
        <form method="POST" action="hostapd_conf" id="apConfigurationForm" class="needs-validation" novalidate>
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <input type="hidden" name="interface" value="<?php echo htmlspecialchars($apDisplayInterface, ENT_QUOTES); ?>">
          <input type="hidden" name="wpa_pairwise" value="CCMP">
          <input type="hidden" name="country_code" value="<?php echo htmlspecialchars($apCountry, ENT_QUOTES); ?>">
          <input type="hidden" name="repeaterEnable" value="1">
          <div class="hotspot-panes openap-config-sections">
            <section class="openap-config-section openap-config-basic" id="apc-basic">
              <div class="openap-config-section-heading"><i class="fas fa-sliders-h"></i><span><?php echo _("Basic"); ?></span></div>
              <div class="hfield-row">
                <div class="hfield-group wide"><div class="hfield-label"><?php echo _("SSID"); ?></div><input type="text" class="hfield-input" name="ssid" value="<?php echo htmlspecialchars(($ssid !== '-') ? $ssid : '', ENT_QUOTES); ?>" required minlength="1" maxlength="32"></div>
                <div class="hfield-group narrow"><div class="hfield-label"><?php echo _("Band"); ?> <span class="chip-cap">auto</span></div><select class="hfield-select" id="apcBand"><option value="24" <?php echo in_array($apHwMode, ['b','g','n']) ? 'selected' : ''; ?>>2.4 GHz</option><option value="5" <?php echo in_array($apHwMode, ['a','ac']) ? 'selected' : ''; ?>>5 GHz</option></select></div>
                <div class="hfield-group narrow"><div class="hfield-label"><?php echo _("Ch"); ?></div><select class="hfield-select" name="channel" id="apcChannel"><optgroup label="2.4 GHz"><?php for ($c=1;$c<=13;$c++): ?><option value="<?php echo $c; ?>" data-band="24" data-max-dbm="<?php echo (int) ($channelTxPowerLimits[$c] ?? 30); ?>" <?php echo (int)$apChannel === $c ? 'selected' : ''; ?>><?php echo $c; ?></option><?php endfor; ?></optgroup><optgroup label="5 GHz"><?php foreach ($available5ghzChannels as $c): ?><option value="<?php echo $c; ?>" data-band="5" data-max-dbm="<?php echo (int) ($channelTxPowerLimits[(int) $c] ?? 30); ?>" <?php echo (int)$apChannel === (int)$c ? 'selected' : ''; ?>><?php echo $c; ?></option><?php endforeach; ?></optgroup></select></div>
              </div>
              <div class="hfield-row">
                <div class="hfield-group"><div class="hfield-label"><?php echo _("Wireless Mode"); ?></div><input type="text" class="hfield-input" id="apcModeDisplay" value="<?php echo in_array($apHwMode, ['a','ac']) ? '802.11a / 802.11ac (5 GHz)' : '802.11n (2.4 GHz)'; ?>" readonly aria-readonly="true"><input type="hidden" name="hw_mode" id="apcMode" value="<?php echo in_array($apHwMode, ['a','ac']) ? 'ac' : 'n'; ?>"></div>
                <div class="hfield-group narrow"><div class="hfield-label"><?php echo _("Width"); ?></div><select class="hfield-select" name="openap_channel_width" id="apcWidth" data-current-width="<?php echo (int)$apWidth; ?>"></select></div>
                <div class="hfield-group narrow"><div class="hfield-label"><?php echo _("TX dBm"); ?></div><input type="number" class="hfield-input" name="txpower" value="<?php echo (int) $apTxPower; ?>" min="1" max="<?php echo (int) $apTxPowerMax; ?>" required></div>
              </div>
              <div class="openap-config-info-badge"><i class="fas fa-info-circle" aria-hidden="true"></i><span><?php echo htmlspecialchars($apDisplayInterface, ENT_QUOTES); ?> · <span id="apcChipInfo"><?php echo strtoupper($apHwMode).' · '.htmlspecialchars($apCountry, ENT_QUOTES); ?></span></span></div>
            </section>

            <section class="openap-config-section openap-config-security" id="apc-security">
              <div class="openap-config-section-heading"><i class="fas fa-shield-alt"></i><span><?php echo _("Security"); ?></span></div>
              <div class="hfield-row"><div class="hfield-group"><div class="hfield-label"><?php echo _("Security Type"); ?></div><select class="hfield-select" id="apcSecurity" name="wpa"><option value="2" <?php echo $apSecurityMode !== 'none' ? 'selected' : ''; ?>>WPA2-PSK</option><option value="none" <?php echo $apSecurityMode === 'none' ? 'selected' : ''; ?>><?php echo _("None (open network)"); ?></option></select></div><div class="hfield-group"><div class="hfield-label"><?php echo _("Encryption"); ?></div><input class="hfield-input" id="apcEncryption" value="<?php echo htmlspecialchars($apEncryption, ENT_QUOTES); ?>" readonly></div></div>
              <div class="hfield-row" id="apcPskRow"><div class="hfield-group wide"><div class="hfield-label"><?php echo _("Pre-shared Key (PSK)"); ?></div><div class="d-flex gap-1"><input type="password" class="hfield-input" id="apcPsk" name="wpa_passphrase" value="<?php echo htmlspecialchars($apPsk ?: '', ENT_QUOTES); ?>" minlength="8" maxlength="63" <?php echo $apSecurityMode === 'none' ? 'disabled' : 'required'; ?>><button type="button" class="btn-icon-ss" id="apcTogglePsk" title="<?php echo _("Toggle visibility"); ?>" aria-label="<?php echo _("Toggle password visibility"); ?>"><i class="fas fa-eye"></i></button><button type="button" class="btn-icon-ss" data-bs-toggle="modal" data-bs-target="#apConfigurationWifiQrModal" title="<?php echo _("WiFi QR code"); ?>" aria-label="<?php echo _("Open WiFi QR code"); ?>"><i class="fas fa-qrcode"></i></button></div></div></div>
              <div class="openap-config-info-badge openap-security-info-badge" id="apcSecurityNote"><i class="fas fa-info-circle" aria-hidden="true"></i><span><?php echo $apSecurityMode === 'none' ? _("Open networks have no password or traffic encryption. Anyone within range can connect.") : _("WPA2-PSK with AES/CCMP is used for client compatibility."); ?></span></div>
              <div class="hfield-row openap-advanced-toggles" style="margin-top:20px">
                <div class="hfield-group openap-advanced-toggle">
                  <div class="openap-advanced-toggle-header">
                    <div class="openap-advanced-toggle-copy">
                      <div class="openap-advanced-toggle-title"><i class="fas fa-user-shield" aria-hidden="true"></i><span><?php echo _("AP Isolation"); ?></span></div>
                      <div class="openap-advanced-toggle-description"><?php echo _("Prevents wireless clients from communicating directly with each other."); ?></div>
                    </div>
                    <div class="form-check form-switch m-0">
                      <input type="hidden" name="apIsolation" value="0">
                      <input class="form-check-input" type="checkbox" role="switch" name="apIsolation" id="apcIsolation" value="1" <?php echo $apIsolation ? 'checked' : ''; ?> aria-label="<?php echo _("AP Isolation"); ?>">
                    </div>
                  </div>
                </div>
                <div class="hfield-group openap-advanced-toggle">
                  <div class="openap-advanced-toggle-header">
                    <div class="openap-advanced-toggle-copy">
                      <div class="openap-advanced-toggle-title"><i class="fas fa-eye-slash" aria-hidden="true"></i><span><?php echo _("Hidden SSID"); ?></span></div>
                      <div class="openap-advanced-toggle-description"><?php echo _("Stops the network name from appearing in normal WiFi scans."); ?></div>
                    </div>
                    <div class="form-check form-switch m-0">
                      <input type="hidden" name="hiddenSSID" value="0">
                      <input class="form-check-input" type="checkbox" role="switch" name="hiddenSSID" id="apcHiddenSsid" value="1" <?php echo $apIgnoreBroadcast ? 'checked' : ''; ?> aria-label="<?php echo _("Hidden SSID"); ?>">
                    </div>
                  </div>
                </div>
              </div>
            </section>
          </div>

          <div class="card-footer d-flex justify-content-between align-items-center gap-2 px-3 py-2">
            <div class="btn-group-ss"><?php if ($hostapdEnabled): ?><button type="submit" name="RestartHotspot" class="btn-ss"><i class="fas fa-sync-alt"></i> <?php echo _("Restart"); ?></button><button type="submit" name="StopHotspot" class="btn-ss danger"><i class="fas fa-stop"></i></button><?php else: ?><button type="submit" name="StartHotspot" class="btn-ss success"><i class="fas fa-play"></i></button><?php endif; ?></div>
            <button type="submit" name="SaveHostAPDSettings" value="1" class="btn-ss primary"><i class="fas fa-save"></i> <?php echo _("Save"); ?></button>
          </div>
        </form>
      </div>
    </div>

    <div class="col-xl-3 col-lg-4">
      <div class="row g-3 openap-ap-side-widgets">
        <div class="col-12">
          <?php require __DIR__ . '/openap_service_status.php'; ?>
        </div>

        <div class="col-12">
          <div class="stat-card border-top-blue openap-side-dhcp">
            <div class="stat-top openap-widget-body">
              <div class="openap-widget-heading">
                <div><div class="openap-widget-title"><?php echo _("DHCP setting"); ?></div><div class="openap-widget-caption"><?php echo _("Hotspot address pool"); ?></div></div>
                <div class="openap-widget-icon openap-widget-icon-blue"><i class="fas fa-arrow-right-arrow-left"></i></div>
              </div>
              <div class="openap-dhcp-summary">
                <div class="openap-dhcp-lease-count"><strong><?php echo $dhcpActive; ?> <span>/ <?php echo $dhcpTotal; ?></span></strong><small><?php echo _("Leases active"); ?></small></div>
                <div class="openap-dhcp-meta"><span><?php echo _("Lease"); ?> <strong><?php echo htmlspecialchars($dhcpLeaseTime, ENT_QUOTES); ?></strong></span><span>DNS <strong><?php echo htmlspecialchars($dhcpDns, ENT_QUOTES); ?></strong></span></div>
              </div>
              <div class="openap-dhcp-track" role="progressbar" aria-label="<?php echo _("DHCP leases in use"); ?>" aria-valuenow="<?php echo $dhcpActive; ?>" aria-valuemin="0" aria-valuemax="<?php echo $dhcpTotal; ?>"><span style="width:<?php echo $dhcpPercent; ?>%"></span></div>
            </div>
            <div class="stat-bottom"><span><i class="fas fa-network-wired"></i> <?php echo _("Pool"); ?></span><strong><?php echo htmlspecialchars($dhcpRange, ENT_QUOTES); ?></strong></div>
          </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="apOpenSecurityConfirmModal" tabindex="-1" aria-labelledby="apOpenSecurityConfirmModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered openap-ap-ethernet-dialog">
    <div class="modal-content openap-ap-ethernet-modal openap-open-security-modal">
      <div class="modal-header openap-ap-ethernet-header">
        <div>
          <div class="openap-ap-ethernet-title" id="apOpenSecurityConfirmModalTitle"><?php echo _("Open network"); ?></div>
          <div class="openap-ap-ethernet-subtitle"><?php echo _("Confirm hotspot security"); ?></div>
        </div>
        <div class="openap-ap-ethernet-header-actions">
          <span class="openap-ap-ethernet-header-icon"><i class="fas fa-lock-open"></i></span>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
        </div>
      </div>
      <div class="modal-body openap-ap-ethernet-body openap-open-security-body">
        <div class="openap-open-security-label"><?php echo _("Security mode"); ?></div>
        <div class="openap-open-security-warning">
          <span class="openap-open-security-warning-icon"><i class="fas fa-triangle-exclamation"></i></span>
          <div>
            <strong><?php echo _("No password or encryption"); ?></strong>
            <p><?php echo _("Anyone within range will be able to connect to this hotspot and inspect unencrypted wireless traffic."); ?></p>
          </div>
        </div>
        <div class="openap-open-security-note"><i class="fas fa-circle-info"></i><span><?php echo _("Only continue if an open network is intentional."); ?></span></div>
        <div class="openap-ap-ethernet-status">
          <span><?php echo _("Security"); ?>: <strong><?php echo _("None"); ?></strong></span>
          <span><?php echo _("Encryption"); ?>: <strong><?php echo _("None"); ?></strong></span>
        </div>
        <div class="openap-ap-ethernet-actions">
          <button type="button" class="btn-ss" data-bs-dismiss="modal"><?php echo _("Cancel"); ?></button>
          <button type="button" class="btn-ss primary" id="apOpenSecurityConfirm"><i class="fas fa-lock-open"></i> <?php echo _("Create open network"); ?></button>
        </div>
      </div>
    </div>
  </div>
</div>

<div class="modal fade" id="apConfigurationWifiQrModal" tabindex="-1" aria-labelledby="apConfigurationWifiQrModalTitle" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered wifi-qr-dialog">
    <div class="modal-content wifi-qr-modal">
      <div class="modal-header wifi-qr-header">
        <div>
          <div class="wifi-qr-eyebrow"><i class="fas fa-qrcode"></i> <?php echo _("WiFi access"); ?></div>
          <h2 id="apConfigurationWifiQrModalTitle" class="wifi-qr-title"><?php echo htmlspecialchars($ssid ?: '-', ENT_QUOTES); ?></h2>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
      </div>
      <div class="modal-body wifi-qr-body">
        <div class="wifi-qr-frame">
          <img src="app/img/wifi-qr-code.php" alt="<?php echo _("OpenAP WiFi QR code"); ?>" class="wifi-qr-image">
        </div>
        <div class="wifi-qr-details">
          <div class="wifi-qr-detail"><span><?php echo _("Network"); ?></span><strong><?php echo htmlspecialchars($ssid ?: '-', ENT_QUOTES); ?></strong></div>
          <div class="wifi-qr-detail"><span><?php echo _("Security"); ?></span><strong><?php echo htmlspecialchars($apSecurityType ?: 'WPA', ENT_QUOTES); ?></strong></div>
        </div>
        <div class="wifi-qr-hint"><i class="fas fa-mobile-alt"></i> <?php echo _("Scan with a phone camera to join the hotspot."); ?></div>
      </div>
      <div class="modal-footer wifi-qr-footer">
        <a class="btn-ss" href="/app/lib/signprint.php" target="_blank" rel="noopener"><i class="fas fa-external-link-alt"></i> <?php echo _("Open full page"); ?></a>
        <a class="btn-ss primary" href="/app/img/wifi-qr-code.php?download=1"><i class="fas fa-download"></i> <?php echo _("Download SVG"); ?></a>
      </div>
    </div>
  </div>
</div>
