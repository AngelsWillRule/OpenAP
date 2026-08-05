<div class="row g-3 mb-3">
  <div class="col-xl-9 col-lg-8">
    <div class="openap-section-heading openap-about-heading">
      <span class="openap-section-heading-icon" aria-hidden="true"><i class="fas fa-info-circle"></i></span>
      <div><strong><?php echo _("About OpenAP"); ?></strong><small><?php echo _("Project identity and attribution"); ?></small></div>
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
          <div class="openap-about-attribution-note">
            <i class="fas fa-balance-scale"></i>
            <span><?php echo _("RaspAP attribution and the GPL-3.0 license are retained as part of the OpenAP source and distribution."); ?></span>
          </div>
        </div>
      </section>
    </div>
  </div>

  <aside class="col-xl-3 col-lg-4">
    <div class="row g-3 openap-about-side-widgets">
      <div class="col-12">
        <?php require dirname(__DIR__) . '/openap_service_status.php'; ?>
      </div>
      <div class="col-12">
        <section class="stat-card border-top-green openap-about-side-card">
          <div class="stat-top openap-widget-body">
            <div class="openap-widget-heading">
              <div><div class="openap-widget-title"><?php echo _("OpenAP release"); ?></div><div class="openap-widget-caption"><?php echo _("Installed web interface"); ?></div></div>
              <div class="openap-widget-icon openap-widget-icon-green"><i class="fas fa-code-branch"></i></div>
            </div>
            <div class="openap-about-release">
              <span><?php echo _("Version"); ?></span>
              <strong><?php echo htmlspecialchars(OPENAP_VERSION, ENT_QUOTES); ?></strong>
              <small><i class="fas fa-circle-check"></i> <?php echo _("Installed"); ?></small>
            </div>
          </div>
          <div class="stat-bottom"><span><?php echo _("Project"); ?></span><strong>OpenAP</strong></div>
        </section>
      </div>

      <div class="col-12">
        <section class="stat-card border-top-blue openap-about-side-card">
          <div class="stat-top openap-widget-body">
            <div class="openap-widget-heading">
              <div><div class="openap-widget-title"><?php echo _("OpenAP links"); ?></div><div class="openap-widget-caption"><?php echo _("Official project resources"); ?></div></div>
              <div class="openap-widget-icon openap-widget-icon-blue"><i class="fas fa-external-link-alt"></i></div>
            </div>
            <div class="openap-about-links">
              <a href="https://github.com/AngelsWillRule/OpenAP" target="_blank" rel="noopener"><i class="fa-brands fa-github"></i><span>GitHub</span><i class="fas fa-chevron-right"></i></a>
              <a href="https://angelswillrule.github.io/OpenAP/" target="_blank" rel="noopener"><i class="fas fa-book-reader"></i><span><?php echo _("Documentation"); ?></span><i class="fas fa-chevron-right"></i></a>
            </div>
          </div>
        </section>
      </div>

      <div class="col-12">
        <section class="stat-card border-top-green openap-about-side-card">
          <div class="stat-top openap-widget-body">
            <div class="openap-widget-heading">
              <div><div class="openap-widget-title"><?php echo _("License"); ?></div><div class="openap-widget-caption"><?php echo _("Free and open source"); ?></div></div>
              <div class="openap-widget-icon openap-widget-icon-green"><i class="fas fa-balance-scale"></i></div>
            </div>
            <div class="openap-about-license">
              <strong>GPL-3.0</strong>
              <p><?php echo _("You may use, study, modify and redistribute the software under its license terms."); ?></p>
              <a href="https://github.com/raspap/raspap-webgui/blob/master/LICENSE" target="_blank" rel="noopener"><?php echo _("Read license"); ?> <i class="fas fa-arrow-right"></i></a>
            </div>
          </div>
        </section>
      </div>
    </div>
  </aside>
</div>
