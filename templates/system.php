<?php
function openapSystemEscape($value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES);
}

function openapSystemBadge(string $state, string $class): string
{
    return '<span class="openap-system-badge openap-system-badge-' . openapSystemEscape($class) . '">' . openapSystemEscape($state) . '</span>';
}

$openapActiveServices = count(array_filter($services, static function (array $service): bool {
    return ($service['statusClass'] ?? '') === 'up';
}));
$openapServiceDescriptions = [
    'hostapd.service' => _('WiFi access point'),
    'dnsmasq.service' => _('DHCP + DNS'),
    'openap-uplink.service' => _('WiFi uplink'),
    'openap-firewall.service' => _('NAT / Firewall'),
    'lighttpd.service' => _('Web server'),
    'systemd-networkd.service' => _('Network backend'),
];
?>
<div class="container-fluid p-0 openap-system-layout">
  <?php $status->showMessages(); ?>

  <div class="row g-3 mb-3">
    <div class="col-xl-9 col-lg-8">
      <div class="openap-section-heading openap-system-heading">
        <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas fa-server"></i></span>
        <div>
          <strong><?php echo _("System"); ?></strong>
          <small><?php echo _("Device information and diagnostics"); ?></small>
        </div>
        <div class="openap-system-heading-actions">
          <button type="button" class="openap-system-action danger" data-bs-toggle="modal" data-bs-target="#system-reboot-modal">
            <i class="fas fa-power-off"></i><span><?php echo _("Reboot"); ?></span>
          </button>
          <button type="button" onClick="window.location.reload();" class="openap-system-action">
            <i class="fas fa-sync-alt"></i><span><?php echo _("Refresh"); ?></span>
          </button>
        </div>
      </div>

      <div class="card shadow openap-system-shell">
        <div class="card-body openap-system-page">
          <div class="openap-system-hero">
            <div class="openap-system-identity">
              <span class="openap-system-identity-icon"><i class="fas fa-microchip"></i></span>
              <div>
                <div class="openap-system-kicker"><?php echo _("OpenAP diagnostics"); ?></div>
                <h4><?php echo openapSystemEscape($hostname); ?></h4>
                <div class="text-muted"><?php echo openapSystemEscape($os); ?> &middot; <?php echo openapSystemEscape($kernel); ?></div>
              </div>
            </div>
            <div class="openap-system-mode">
              <span><?php echo _("Operating mode"); ?></span>
              <strong><?php echo openapSystemEscape($openapModeLabel); ?></strong>
            </div>
          </div>

        <div class="openap-system-metrics">
          <div class="openap-system-metric">
            <i class="fas fa-microchip"></i>
            <span><?php echo _("CPU Load"); ?></span>
            <strong class="text-<?php echo openapSystemEscape($cpuload_status); ?>"><?php echo openapSystemEscape($cpuload); ?>%</strong>
          </div>
          <div class="openap-system-metric">
            <i class="fas fa-memory"></i>
            <span><?php echo _("Memory"); ?></span>
            <strong class="text-<?php echo openapSystemEscape($memused_status); ?>"><?php echo openapSystemEscape($memused); ?>%</strong>
          </div>
          <div class="openap-system-metric">
            <i class="fas fa-hdd"></i>
            <span><?php echo _("Disk"); ?></span>
            <strong class="text-<?php echo openapSystemEscape($diskused_status); ?>"><?php echo openapSystemEscape($diskused); ?>%</strong>
          </div>
          <div class="openap-system-metric">
            <i class="fas fa-temperature-half"></i>
            <span><?php echo _("Temperature"); ?></span>
            <strong class="text-<?php echo openapSystemEscape($cputemp_status); ?>"><?php echo openapSystemEscape($cputemp); ?>&deg;C</strong>
          </div>
        </div>

        <div class="row g-3">
          <div class="col-xl-6">
            <section class="openap-system-panel">
              <div class="openap-system-panel-title">
                <i class="fas fa-server"></i>
                <span><?php echo _("Operating System"); ?></span>
              </div>
              <div class="openap-system-list">
                <div><span><?php echo _("Hostname"); ?></span><strong><?php echo openapSystemEscape($hostname); ?></strong></div>
                <div><span><?php echo _("OS"); ?></span><strong><?php echo openapSystemEscape($os); ?></strong></div>
                <div><span><?php echo _("Kernel"); ?></span><strong><?php echo openapSystemEscape($kernel); ?></strong></div>
                <div><span><?php echo _("Architecture"); ?></span><strong><?php echo openapSystemEscape($machine); ?></strong></div>
                <div><span><?php echo _("Container"); ?></span><strong><?php echo openapSystemEscape($container); ?></strong></div>
                <div><span><?php echo _("CPU cores"); ?></span><strong><?php echo openapSystemEscape($cores); ?></strong></div>
                <div><span><?php echo _("Uptime"); ?></span><strong><?php echo openapSystemEscape($uptime); ?></strong></div>
                <div><span><?php echo _("System time"); ?></span><strong><?php echo openapSystemEscape($systime); ?></strong></div>
              </div>
            </section>
          </div>

          <div class="col-xl-6">
            <section class="openap-system-panel">
              <div class="openap-system-panel-title">
                <i class="fas fa-network-wired"></i>
                <span><?php echo _("Network Profile"); ?></span>
              </div>
              <div class="openap-system-list">
                <?php foreach ($network as $item) : ?>
                  <div>
                    <span><?php echo openapSystemEscape($item['label']); ?></span>
                    <strong><?php echo openapSystemEscape($item['value']); ?></strong>
                  </div>
                <?php endforeach; ?>
              </div>
            </section>
          </div>
        </div>

        <section class="openap-system-panel openap-system-services-panel mt-3">
          <div class="openap-system-panel-title">
            <i class="fas fa-heartbeat"></i>
            <span><?php echo _("Services"); ?></span>
          </div>
          <div class="table-responsive">
            <table class="table openap-system-table">
              <thead>
                <tr>
                  <th><?php echo _("Service"); ?></th>
                  <th><?php echo _("State"); ?></th>
                  <th><?php echo _("Enabled"); ?></th>
                  <th><?php echo _("Active since"); ?></th>
                </tr>
              </thead>
              <tbody>
                <?php foreach ($services as $service) : ?>
                  <tr>
                    <td><code><?php echo openapSystemEscape($service['name']); ?></code></td>
                    <td><?php echo openapSystemBadge($service['active'], $service['statusClass']); ?></td>
                    <td><?php echo openapSystemEscape($service['enabled']); ?></td>
                    <td><?php echo openapSystemEscape($service['since']); ?></td>
                  </tr>
                <?php endforeach; ?>
              </tbody>
            </table>
          </div>
        </section>

        <div class="row g-3 mt-0">
          <div class="col-xl-6">
            <section class="openap-system-panel">
              <div class="openap-system-panel-title">
                <i class="fas fa-code-branch"></i>
                <span><?php echo _("Project Software"); ?></span>
              </div>
              <div class="table-responsive">
                <table class="table openap-system-table">
                  <tbody>
                    <?php foreach ($software as $component) : ?>
                      <tr>
                        <td><?php echo openapSystemEscape($component['name']); ?></td>
                        <td><code><?php echo openapSystemEscape($component['version']); ?></code></td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>
          </div>

          <div class="col-xl-6">
            <section class="openap-system-panel openap-system-config-panel">
              <div class="openap-system-panel-title">
                <i class="fas fa-file-lines"></i>
                <span><?php echo _("Configuration Files"); ?></span>
              </div>
              <div class="table-responsive">
                <table class="table openap-system-table">
                  <tbody>
                    <?php foreach ($configFiles as $file) : ?>
                      <tr>
                        <td><code><?php echo openapSystemEscape($file['path']); ?></code></td>
                        <td>
                          <?php echo $file['exists'] ? openapSystemBadge(_("Readable"), 'up') : openapSystemBadge(_("Missing"), 'warn'); ?>
                          <span class="openap-system-file-time"><?php echo openapSystemEscape($file['modified']); ?></span>
                        </td>
                      </tr>
                    <?php endforeach; ?>
                  </tbody>
                </table>
              </div>
            </section>
          </div>
        </div>
        </div>
        <div class="card-footer openap-system-footer">
          <i class="fas fa-lock me-1"></i><?php echo _("Read-only information provided by OpenAP system diagnostics."); ?>
        </div>
      </div>
    </div>

    <aside class="col-xl-3 col-lg-4">
      <div class="row g-3 openap-system-side-widgets">
        <div class="col-12">
          <section class="stat-card border-top-green openap-system-side-card">
            <div class="stat-top">
              <div class="openap-widget-heading">
                <div>
                  <div class="openap-widget-title"><?php echo _("System health"); ?></div>
                  <div class="openap-widget-caption"><?php echo _("Live resource usage"); ?></div>
                </div>
                <span class="openap-system-side-icon violet"><i class="fas fa-microchip"></i></span>
              </div>
              <div class="openap-system-side-grid">
                <div><span><?php echo _("CPU used"); ?></span><strong><?php echo openapSystemEscape($cpuload); ?>%</strong></div>
                <div><span><?php echo _("RAM used"); ?></span><strong><?php echo openapSystemEscape($memused); ?>%</strong></div>
                <div><span><?php echo _("Temperature"); ?></span><strong><?php echo openapSystemEscape($cputemp); ?>&deg;C</strong></div>
                <div><span><?php echo _("Uptime"); ?></span><strong><?php echo openapSystemEscape($uptime); ?></strong></div>
              </div>
            </div>
            <div class="stat-bottom">
              <span><i class="fas fa-hdd me-1"></i><?php echo _("Disk"); ?>: <?php echo openapSystemEscape($diskused); ?>%</span>
              <span><?php echo _("Load"); ?>: <?php echo openapSystemEscape($cpuload); ?>%</span>
            </div>
          </section>
        </div>

        <div class="col-12">
          <section class="stat-card border-top-green openap-system-side-card">
            <div class="stat-top">
              <div class="openap-widget-heading">
                <div>
                  <div class="openap-widget-title"><?php echo _("Network profile"); ?></div>
                  <div class="openap-widget-caption"><?php echo _("Current OpenAP mode"); ?></div>
                </div>
                <span class="openap-system-side-icon blue"><i class="fas fa-network-wired"></i></span>
              </div>
              <div class="openap-system-side-mode">
                <span><?php echo _("Active mode"); ?></span>
                <strong><?php echo openapSystemEscape($openapModeLabel); ?></strong>
              </div>
              <div class="openap-system-side-list">
                <?php foreach (array_slice($network, 0, 3) as $item) : ?>
                  <div><span><?php echo openapSystemEscape($item['label']); ?></span><strong><?php echo openapSystemEscape($item['value']); ?></strong></div>
                <?php endforeach; ?>
              </div>
            </div>
          </section>
        </div>

        <div class="col-12">
          <?php require __DIR__ . '/openap_service_status.php'; ?>
        </div>
      </div>
    </aside>
  </div>
</div>

<div class="modal fade" id="system-reboot-modal" tabindex="-1" aria-labelledby="system-reboot-title" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered">
    <div class="modal-content">
      <div class="modal-header">
        <div class="modal-title" id="system-reboot-title">
          <i class="fas fa-power-off me-2"></i><?php echo _("Reboot system"); ?>
        </div>
        <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
      </div>
      <div class="modal-body">
        <?php echo _("OpenAP and its network services will be temporarily unavailable while the system restarts."); ?>
      </div>
      <div class="modal-footer">
        <button type="button" class="btn btn-outline-secondary" data-bs-dismiss="modal"><?php echo _("Cancel"); ?></button>
        <form method="POST" action="system_info" class="m-0">
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <input type="hidden" name="system_action" value="reboot">
          <button type="submit" class="btn btn-outline-danger">
            <i class="fas fa-power-off me-1"></i><?php echo _("Reboot"); ?>
          </button>
        </form>
      </div>
    </div>
  </div>
</div>
