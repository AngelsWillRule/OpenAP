<?php
$aboutProfile = function_exists('openapReadRepeaterProfile') ? openapReadRepeaterProfile() : [];
$aboutCurrentMode = str_replace('-', '_', (string) ($aboutProfile['mode']['current'] ?? 'ap_ethernet'));
$aboutIsRepeaterWifi = $aboutCurrentMode === 'repeater_wifi';
$aboutUplinkHealth = $aboutIsRepeaterWifi && function_exists('openapUplinkHealth') ? openapUplinkHealth() : ['ready' => true];
$aboutUplinkConnected = !$aboutIsRepeaterWifi || !empty($aboutUplinkHealth['ready']);
$aboutHeaderServices = [
  'hostapd' => function_exists('openapServiceActive') && openapServiceActive('hostapd.service') === 'active',
  'dnsmasq' => function_exists('openapServiceActive') && openapServiceActive('dnsmasq.service') === 'active',
  'nftables' => function_exists('openapNatActive') ? openapNatActive() : (function_exists('openapServiceActive') && openapServiceActive('nftables.service') === 'active'),
  'lighttpd' => function_exists('openapServiceActive') && openapServiceActive('lighttpd.service') === 'active',
];
$aboutTopologyHealthy = $aboutHeaderServices['hostapd'] && $aboutUplinkConnected && $aboutHeaderServices['dnsmasq'] && $aboutHeaderServices['nftables'] && $aboutHeaderServices['lighttpd'];
if ($aboutCurrentMode === 'ap_ethernet_bridge') {
  $aboutTopologyHealthy = $aboutHeaderServices['hostapd'] && $aboutUplinkConnected && $aboutHeaderServices['lighttpd'];
}
$aboutModeIcon = in_array($aboutCurrentMode, ['ap_ethernet', 'ap_ethernet_bridge'], true) ? 'fa-network-wired' : 'fa-wifi';
$aboutModeLabel = $aboutCurrentMode === 'ap_ethernet_bridge' ? _('Ethernet Bridge') : ($aboutCurrentMode === 'ap_ethernet' ? _('Ethernet Mode') : _('Repeater'));
$aboutLiveState = !$aboutHeaderServices['hostapd'] ? 'offline' : ($aboutTopologyHealthy ? 'live' : 'degraded');
?>
<div class="row g-3 mb-3">
  <div class="col-xl-9 col-lg-8">
    <div class="openap-section-heading openap-topology-header openap-page-main-header openap-dashboard-main-header openap-about-heading">
      <div class="openap-topology-header-title">
        <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas fa-info-circle"></i></span>
        <div><strong><?php echo _("About OpenAP"); ?></strong></div>
      </div>
    </div>

    <div class="card shadow openap-about-shell">
      <div class="openap-about-hero">
        <div class="openap-about-logo-wrap">
          <img class="openap-about-logo" src="app/img/openap-sidebar-logo.png" alt="<?php echo _("OpenAP"); ?>">
        </div>
        <div class="openap-about-intro">
          <div class="openap-about-kicker"><?php echo _("Open wireless access point"); ?></div>
          <h1><?php echo _("OpenAP"); ?></h1>
          <div class="openap-about-version">v<?php echo htmlspecialchars(OPENAP_VERSION, ENT_QUOTES); ?></div>
          <p><?php echo _("A responsive control panel for access points, Ethernet uplinks and WiFi repeater deployments."); ?></p>
          <?php if (defined('OPENAP_UPDATE_ENABLED') && OPENAP_UPDATE_ENABLED && !OPENAP_MONITOR_ENABLED) : ?>
            <button type="button" class="btn-ss primary openap-about-update" name="check-update" data-bs-toggle="modal" data-bs-target="#chkupdateModal">
              <i class="fa-solid fa-cloud-arrow-down"></i><?php echo _("Check for update"); ?>
            </button>
          <?php endif; ?>
        </div>
      </div>

      <section class="openap-about-section">
        <div class="openap-about-section-heading">
          <span><i class="fas fa-broadcast-tower"></i></span>
          <div><strong><?php echo _("About the project"); ?></strong><small><?php echo _("OpenAP profile and purpose"); ?></small></div>
        </div>
        <div class="openap-about-copy">
          <p><?php echo _("OpenAP is adapted for existing hostapd installations and for systems where network interfaces must be discovered by capability and hardware identity rather than fixed Linux names."); ?></p>
          <p><?php echo _("The current project supports a dedicated WiFi access point over Ethernet and a validated two-radio WiFi repeater workflow, while preserving a clear separation between the web interface and privileged network helpers."); ?></p>
        </div>
      </section>

      <section class="openap-about-section">
        <div class="openap-about-section-heading">
          <span><i class="fas fa-code-branch"></i></span>
          <div><strong><?php echo _("Upstream project and attribution"); ?></strong><small><?php echo _("Open source foundations"); ?></small></div>
        </div>
        <div class="openap-about-copy">
          <p><?php echo sprintf(
              _('OpenAP is a fork based on RaspAP, a co-creation of %1$s and %2$s with contributions from the %3$s and %4$s.'),
              '<a href="https://github.com/billz" target="_blank" rel="noopener">billz</a>',
              '<a href="https://github.com/sirlagz" target="_blank" rel="noopener">SirLagz</a>',
              '<a href="https://github.com/raspap/raspap-webgui/graphs/contributors" target="_blank" rel="noopener">' . _('developer community') . '</a>',
              '<a href="https://crowdin.com/project/raspap" target="_blank" rel="noopener">' . _('language translators') . '</a>'
          ); ?></p>
          <div class="openap-about-attribution-note openap-info-badge">
            <i class="fas fa-balance-scale"></i>
            <span><?php echo _("RaspAP attribution and the GPL-3.0 license are retained as part of the OpenAP source and distribution."); ?></span>
          </div>
        </div>
      </section>
    </div>
  </div>

  <aside class="col-xl-3 col-lg-4">
    <?php echo openapWidgetArea('about'); ?>
  </aside>
</div>
