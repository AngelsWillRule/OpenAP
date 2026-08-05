<?php
$isRepeaterWifi = $isRepeaterWifi ?? false;
$uplinkState = $uplinkDetails['wpa_state'] ?? ($isRepeaterWifi ? 'DISCONNECTED' : 'COMPLETED');
$uplinkConnected = $isRepeaterWifi ? ($uplinkState === 'COMPLETED') : true;
$openapWifiHotspotCardHeader = $openapWifiHotspotCardHeader ?? false;
if ($openapWifiHotspotCardHeader) {
    $hotspotHeaderHealthy = $hostapdEnabled
        && $uplinkConnected
        && (bool) ($serviceList['dnsmasq'] ?? false)
        && (bool) ($serviceList['nftables'] ?? false)
        && (bool) ($serviceList['lighttpd'] ?? false);
    if ($currentMode === 'ap_ethernet_bridge') {
        $hotspotHeaderHealthy = $hostapdEnabled && $uplinkConnected && (bool) ($serviceList['lighttpd'] ?? false);
    }
    $hotspotHeaderModeIcon = in_array($currentMode, ['ap_ethernet', 'ap_ethernet_bridge'], true) ? 'fa-network-wired' : 'fa-wifi';
    $hotspotHeaderModeLabel = $currentMode === 'ap_ethernet_bridge' ? _('Ethernet Bridge') : (in_array($currentMode, ['ap_ethernet'], true) ? _('AP Ethernet') : _('Repeater'));
    $hotspotHeaderLiveState = !$hostapdEnabled ? 'offline' : ($hotspotHeaderHealthy ? 'live' : 'degraded');
}
?>
<?php if ($openapWifiHotspotCardHeader): ?>
<div class="card shadow openap-wifi-hotspot-card">
  <div class="card-header openap-topology-header">
    <div class="openap-topology-header-title">
      <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas fa-wifi"></i></span>
      <div><strong><?php echo _("WiFi Hotspot"); ?></strong><small><?php echo _("Wireless access point status"); ?></small></div>
    </div>
    <div class="openap-topology-health <?php echo $hotspotHeaderHealthy ? 'healthy' : 'degraded'; ?>">
      <i class="fas <?php echo $hotspotHeaderHealthy ? 'fa-check-circle' : 'fa-exclamation-triangle'; ?>"></i>
      <?php echo _("Status"); ?>: <?php echo $hotspotHeaderHealthy ? _("Healthy") : _("Degraded"); ?>
    </div>
    <div class="openap-topology-header-meta">
      <span class="badge rounded-pill openap-topology-mode-badge"><i class="fas <?php echo $hotspotHeaderModeIcon; ?>"></i> <?php echo htmlspecialchars($hotspotHeaderModeLabel, ENT_QUOTES); ?></span>
      <span class="badge rounded-pill badge-openap openap-topology-live-badge <?php echo $hotspotHeaderLiveState; ?>"><i class="fas fa-circle"></i> <?php echo _("Live"); ?></span>
    </div>
  </div>
<?php else: ?>
<div class="openap-section-heading openap-hotspot-section-heading">
  <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas fa-wifi"></i></span>
  <div><strong><?php echo _("WiFi Hotspot"); ?></strong><small><?php echo _("Wireless access point status"); ?></small></div>
</div>
<?php endif; ?>
<div class="hs-section openap-hotspot-summary<?php echo $openapWifiHotspotCardHeader ? ' is-contained' : ''; ?>">
  <div class="openap-hotspot-identity">
    <span class="openap-hotspot-identity-icon <?php echo $hostapdEnabled ? 'is-running' : ''; ?>" aria-hidden="true"><i class="fas fa-wifi"></i></span>
    <div class="openap-hotspot-identity-copy">
      <strong><?php echo htmlspecialchars($ssid ?: '-', ENT_QUOTES); ?></strong>
      <small><?php echo _("Wireless access point"); ?></small>
    </div>
    <div class="openap-hotspot-state">
      <?php if ($hostapdEnabled): ?>
        <span class="openap-hotspot-status is-running"><i class="fas fa-circle"></i> <?php echo _("Running"); ?></span>
      <?php else: ?>
        <span class="openap-hotspot-status is-stopped"><i class="fas fa-circle"></i> <?php echo _("Stopped"); ?></span>
      <?php endif; ?>
      <?php if ($apIgnoreBroadcast): ?><span class="openap-hotspot-status is-hidden"><?php echo _("Hidden"); ?></span><?php endif; ?>
    </div>
  </div>

  <div class="openap-hotspot-metrics">
    <div class="openap-hotspot-metric">
      <span class="openap-hotspot-metric-icon"><i class="fas fa-signal"></i></span>
      <div><small><?php echo _("Band"); ?></small><strong data-openap-ap-channel="<?php echo htmlspecialchars($apChannel ?: '-', ENT_QUOTES); ?>"><?php echo $frequency === '5' ? '5 GHz' : '2.4 GHz'; ?> <span><?php echo _("Ch"); ?> <?php echo htmlspecialchars($apChannel ?: '-', ENT_QUOTES); ?></span></strong></div>
    </div>
    <div class="openap-hotspot-metric">
      <span class="openap-hotspot-metric-icon"><i class="fas fa-arrows-alt-h"></i></span>
      <div><small><?php echo _("Channel width"); ?></small><strong><?php echo (int) $apWidth; ?> MHz</strong></div>
    </div>
    <div class="openap-hotspot-metric">
      <span class="openap-hotspot-metric-icon"><i class="fas fa-shield-alt"></i></span>
      <div><small><?php echo _("Security"); ?></small><strong><?php echo htmlspecialchars($apSecurityType, ENT_QUOTES); ?><?php if ($apEncryption && $apEncryption !== 'CCMP'): ?> <span><?php echo htmlspecialchars($apEncryption, ENT_QUOTES); ?></span><?php endif; ?></strong></div>
    </div>
    <div class="openap-hotspot-metric">
      <span class="openap-hotspot-metric-icon"><i class="fas fa-broadcast-tower"></i></span>
      <div><small><?php echo _("TX Power"); ?></small><strong><?php echo htmlspecialchars($apTxPower, ENT_QUOTES); ?></strong></div>
    </div>
    <div class="openap-hotspot-metric">
      <span class="openap-hotspot-metric-icon"><i class="fas fa-users"></i></span>
      <div><small><?php echo _("Clients"); ?></small><strong><?php echo (int) $wirelessClients; ?> <span><?php echo _("connected"); ?></span></strong></div>
    </div>
    <div class="openap-hotspot-metric">
      <span class="openap-hotspot-metric-icon"><i class="fas <?php echo $isRepeaterWifi ? 'fa-arrow-up' : 'fa-network-wired'; ?>"></i></span>
      <div>
        <small><?php echo _("Uplink"); ?></small>
        <strong>
          <?php if ($uplinkConnected): ?>
            <?php echo $isRepeaterWifi ? htmlspecialchars($uplinkDetails['ssid'] ?? '-', ENT_QUOTES) : htmlspecialchars('Ethernet ('.$uplinkIface.')', ENT_QUOTES); ?>
            <?php if ($isRepeaterWifi && !empty($uplinkDetails['freq'])): ?><span>· ch<?php echo $uplinkDetails['freq'] >= 5000 ? round(($uplinkDetails['freq'] - 5000) / 5) : round(($uplinkDetails['freq'] - 2407) / 5); ?></span><?php endif; ?>
          <?php else: ?>
            <span><?php echo _("Not connected"); ?></span>
          <?php endif; ?>
        </strong>
      </div>
    </div>
  </div>
</div>
<?php if ($openapWifiHotspotCardHeader): ?>
</div>
<?php endif; ?>
