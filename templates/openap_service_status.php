<?php
$openapServiceStatusInterface = $apIface ?? $interface ?? '';
if ($openapServiceStatusInterface === '' && is_readable('/etc/openap/repeater.ini')) {
    $openapServiceStatusProfile = parse_ini_file('/etc/openap/repeater.ini', true, INI_SCANNER_RAW);
    if (is_array($openapServiceStatusProfile)) {
        $openapServiceStatusInterface = (string) ($openapServiceStatusProfile['interfaces']['ap'] ?? '');
    }
}
$openapServiceStatusList = $serviceList ?? [];
if ($openapServiceStatusList === []) {
    $openapServiceStatusList = [
        'hostapd' => function_exists('openapServiceActive') && openapServiceActive('hostapd.service') === 'active',
        'dnsmasq' => function_exists('openapServiceActive') && openapServiceActive('dnsmasq.service') === 'active',
        'nftables' => function_exists('openapNatActive')
            ? openapNatActive()
            : (function_exists('openapServiceActive') && openapServiceActive('nftables.service') === 'active'),
        'lighttpd' => function_exists('openapServiceActive') && openapServiceActive('lighttpd.service') === 'active',
        'dnscrypt' => function_exists('openapServiceActive') && openapServiceActive('dnscrypt-proxy.service') === 'active',
    ];
}
$openapServiceStatusServices = [
    'hostapd' => ['hostapd', htmlspecialchars((string) $openapServiceStatusInterface, ENT_QUOTES).' AP'],
    'dnsmasq' => ['dnsmasq', 'DHCP + DNS'],
    'nftables' => ['nftables', 'NAT / Firewall'],
    'lighttpd' => ['lighttpd', 'Web server'],
];

if (is_file('/etc/dnscrypt-proxy/dnscrypt-proxy.toml')) {
    $openapServiceStatusServices['dnscrypt'] = ['dnscrypt-proxy', 'Encrypted DNS'];
}
?>
<div class="stat-card border-top-green openap-side-status openap-service-status-card">
  <div class="stat-top openap-widget-body">
    <div class="openap-widget-heading">
      <div>
        <div class="openap-widget-title"><?php echo _("Service status"); ?></div>
        <div class="openap-widget-caption"><?php echo _("Core OpenAP services"); ?></div>
      </div>
      <div class="openap-widget-icon openap-widget-icon-green"><i class="fas fa-heart-pulse"></i></div>
    </div>
    <div class="openap-service-grid">
      <?php foreach ($openapServiceStatusServices as $serviceKey => $service): ?>
      <div class="openap-service-item">
        <span class="openap-service-led" style="background:<?php echo !empty($openapServiceStatusList[$serviceKey]) ? '#059669' : '#dc2626'; ?>"></span>
        <div><strong><?php echo $service[0]; ?></strong><small><?php echo $service[1]; ?></small></div>
      </div>
      <?php endforeach; ?>
    </div>
  </div>
  <div class="stat-bottom">
    <span><i class="fas fa-circle-check"></i> <?php echo _("Runtime monitored"); ?></span>
    <span><?php echo count($openapServiceStatusServices); ?> <?php echo _("services"); ?></span>
  </div>
</div>
