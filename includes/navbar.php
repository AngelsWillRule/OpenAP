<?php
$openapAdminUsername = $_SESSION['user_id'] ?? '';
$openapMobilePath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
try {
  $openapAdminAuth = new \OpenAP\Auth\HTTPAuth;
  $openapAdminConfig = $openapAdminAuth->getAuthConfig();
  $openapAdminUsername = $openapAdminConfig['admin_user'] ?? $openapAdminUsername;
} catch (\Throwable $e) {
  $openapAdminUsername = $_SESSION['user_id'] ?? '';
}
?>
<nav class="sb-topnav">
  <div class="topbar-left">
    <button class="sidebar-toggle-btn" id="sidebarToggle" type="button" aria-label="Toggle sidebar">
      <i class="fas fa-bars"></i>
    </button>
    <a class="topbar-brand" href="/">
      <span class="topbar-brand-logo-frame">
        <img class="topbar-brand-logo" src="app/img/openap-header-wordmark.png?v=<?php echo filemtime('app/img/openap-header-wordmark.png'); ?>" alt="<?php echo htmlspecialchars(OPENAP_BRAND_TEXT); ?>">
      </span>
    </a>
  </div>

  <div class="topbar-center"></div>

  <div class="topbar-right">
    <?php
    // Read operating mode from repeater profile
    $profile = function_exists('openapReadRepeaterProfile') ? openapReadRepeaterProfile() : [];
    $currentMode = $profile['mode']['current'] ?? 'ap_ethernet';

    // Health checks (same logic as api_health.php)
    $hostapdActive = function_exists('openapServiceActive') && openapServiceActive('hostapd.service') === 'active';
    $dnsmasqActive = function_exists('openapServiceActive') && openapServiceActive('dnsmasq.service') === 'active';
    $firewallActive = function_exists('openapServiceActive')
      && openapServiceActive('openap-firewall.service') === 'active'
      && function_exists('openapNatActive')
      && openapNatActive();
    $lighttpdActive = function_exists('openapServiceActive') && openapServiceActive('lighttpd.service') === 'active';

    $apIface = $profile['interfaces']['ap'] ?? (defined('OPENAP_WIFI_AP_INTERFACE') ? OPENAP_WIFI_AP_INTERFACE : 'wlan0');
    // Avoid hostapd_cli in the common page header. A stale hostapd process can
    // remain active after USB passthrough loss, so also require the configured
    // AP interface, MAC and hotspot gateway to be present.
    $apEnabled = $hostapdActive
      && function_exists('openapApInterfaceReady')
      && openapApInterfaceReady();
    $uplinkConnected = true;
    if ($currentMode === 'repeater_wifi') {
      $uplinkHealth = function_exists('openapUplinkHealth') ? openapUplinkHealth() : [];
      $uplinkConnected = !empty($uplinkHealth['ready']);
    }
    if ($currentMode === 'ap_ethernet_bridge') {
      $dnsmasqActive = true;
      $firewallActive = true;
    }
    $healthy = $hostapdActive && $dnsmasqActive && $firewallActive && $lighttpdActive && $apEnabled && $uplinkConnected;
    ?>

    <!-- Dark mode toggle (if enabled) -->
    <?php if (isset($_SESSION['theme']) && isset($_SESSION['theme']['modes']) && in_array('dark', $_SESSION['theme']['modes'])): ?>
      <div class="openap-theme-toggle">
        <input type="checkbox" class="visually-hidden dark-mode-toggle" id="navbar-dark-mode" <?php echo getDarkMode() ? 'checked' : ''; ?>>
        <label class="openap-theme-toggle-button" for="navbar-dark-mode"
          data-bs-toggle="tooltip" data-bs-placement="bottom" title="<?php echo _("Change light/dark theme"); ?>">
          <i class="fas <?= getDarkMode() ? 'fa-moon' : 'fa-sun' ?> openap-theme-mode-icon" aria-hidden="true"></i>
          <span><?php echo _("Theme"); ?></span>
        </label>
      </div>
    <?php endif; ?>

    <!-- Admin avatar -->
    <button class="admin-avatar-link openap-admin-trigger" type="button" title="<?php echo _("Admin area"); ?>" data-bs-toggle="modal" data-bs-target="#openapAdminModal" aria-label="<?php echo _("Admin area"); ?>">
      <span class="admin-avatar"><i class="fas fa-user" aria-hidden="true"></i></span>
    </button>
  </div>
</nav>

<?php if ($openapMobilePath !== '/login'): ?>
<?php
$openapMobileNavIndex = $openapMobilePath === '/ap_configuration'
  ? 1
  : ($openapMobilePath === '/dhcp_setting' ? 2 : 0);
?>
<nav class="openap-mobile-bottom-nav"
     data-active-index="<?php echo $openapMobileNavIndex; ?>"
     style="--openap-mobile-active:<?php echo $openapMobileNavIndex; ?>"
     aria-label="<?php echo _("Quick navigation"); ?>">
  <span class="openap-mobile-bottom-indicator" aria-hidden="true"></span>
  <a class="openap-mobile-bottom-link<?php echo in_array($openapMobilePath, ['/', '/dashboard'], true) ? ' active' : ''; ?>"
     data-mobile-index="0" href="/"<?php echo in_array($openapMobilePath, ['/', '/dashboard'], true) ? ' aria-current="page"' : ''; ?>>
    <i class="fas fa-tachometer-alt" aria-hidden="true"></i>
    <span><?php echo _("Dashboard"); ?></span>
  </a>
  <a class="openap-mobile-bottom-link<?php echo $openapMobilePath === '/ap_configuration' ? ' active' : ''; ?>"
     data-mobile-index="1" href="/ap_configuration"<?php echo $openapMobilePath === '/ap_configuration' ? ' aria-current="page"' : ''; ?>>
    <i class="fas fa-broadcast-tower" aria-hidden="true"></i>
    <span><?php echo _("AP Config"); ?></span>
  </a>
  <a class="openap-mobile-bottom-link<?php echo $openapMobilePath === '/dhcp_setting' ? ' active' : ''; ?>"
     data-mobile-index="2" href="/dhcp_setting"<?php echo $openapMobilePath === '/dhcp_setting' ? ' aria-current="page"' : ''; ?>>
    <i class="fas fa-exchange-alt" aria-hidden="true"></i>
    <span><?php echo _("DHCP"); ?></span>
  </a>
</nav>
<?php endif; ?>

<div class="modal fade openap-admin-modal" id="openapAdminModal" tabindex="-1" aria-labelledby="openapAdminModalLabel" aria-hidden="true">
  <div class="modal-dialog modal-dialog-centered openap-admin-dialog">
    <div class="modal-content openap-admin-modal-content">
      <div class="modal-header openap-ap-ethernet-header">
        <div>
          <div class="openap-ap-ethernet-title" id="openapAdminModalLabel"><?php echo _("Account settings"); ?></div>
          <div class="openap-ap-ethernet-subtitle"><?php echo _("Manage administrator access"); ?></div>
        </div>
        <div class="openap-ap-ethernet-header-actions">
          <span class="openap-ap-ethernet-header-icon" aria-hidden="true"><i class="fas fa-user-lock"></i></span>
          <button type="button" class="btn-close" data-bs-dismiss="modal" aria-label="<?php echo _("Close"); ?>"></button>
        </div>
      </div>

      <form id="openapAdminModalForm" role="form" action="auth_conf" method="POST" class="needs-validation" novalidate>
        <div class="modal-body">
          <?php echo \OpenAP\Tokens\CSRF::hiddenField(); ?>
          <input type="hidden" name="openap_admin_modal" value="1">
          <div class="openap-admin-feedback" id="openapAdminFeedback" aria-live="polite"></div>

          <div class="openap-admin-panel">
            <div class="openap-admin-panel-heading">
              <i class="fas fa-user-lock"></i>
              <span><?php echo _("Credentials"); ?></span>
            </div>

            <div class="mb-3">
              <label class="form-label" for="openap-admin-username"><?php echo _("Username"); ?></label>
              <input type="text" class="form-control" id="openap-admin-username" name="username" value="<?php echo htmlspecialchars($openapAdminUsername, ENT_QUOTES); ?>" required>
              <div class="invalid-feedback"><?php echo _("Please provide a valid username."); ?></div>
            </div>

            <div class="mb-3">
              <label class="form-label" for="openap-admin-oldpass"><?php echo _("Current password"); ?></label>
              <div class="input-group has-validation">
                <input type="password" class="form-control" id="openap-admin-oldpass" name="oldpass" autocomplete="current-password" required>
                <div class="input-group-text js-toggle-password" data-bs-target="[name=oldpass]" data-toggle-with="fas fa-eye-slash"><i class="fas fa-eye mx-2"></i></div>
                <div class="invalid-feedback"><?php echo _("Please enter your old password."); ?></div>
              </div>
            </div>

            <div class="row g-3">
              <div class="col-sm-6">
                <label class="form-label" for="openap-admin-newpass"><?php echo _("New password"); ?></label>
                <div class="input-group has-validation">
                  <input type="password" class="form-control" id="openap-admin-newpass" name="newpass" autocomplete="new-password" required>
                  <div class="input-group-text js-toggle-password" data-bs-target="[name=newpass]" data-toggle-with="fas fa-eye-slash"><i class="fas fa-eye mx-2"></i></div>
                  <div class="invalid-feedback"><?php echo _("Please enter a new password."); ?></div>
                </div>
              </div>
              <div class="col-sm-6">
                <label class="form-label" for="openap-admin-newpassagain"><?php echo _("Repeat password"); ?></label>
                <div class="input-group has-validation">
                  <input type="password" class="form-control" id="openap-admin-newpassagain" name="newpassagain" autocomplete="new-password" required>
                  <div class="input-group-text js-toggle-password" data-bs-target="[name=newpassagain]" data-toggle-with="fas fa-eye-slash"><i class="fas fa-eye mx-2"></i></div>
                  <div class="invalid-feedback" id="openap-admin-password-match-feedback"><?php echo _("Please re-enter your new password."); ?></div>
                </div>
              </div>
            </div>
          </div>

          <div class="openap-admin-session">
            <div>
              <div class="openap-admin-session-title"><?php echo _("Active session"); ?></div>
              <div class="openap-admin-session-copy"><?php echo _("End the current administrator session and return to login."); ?></div>
            </div>
            <button type="submit" class="btn btn-outline-danger" name="logout" value="1" formnovalidate onclick="disableValidation(this.form)">
              <i class="fas fa-sign-out-alt me-1"></i><?php echo _("Logout"); ?>
            </button>
          </div>
        </div>

        <div class="modal-footer">
          <button type="button" class="btn btn-light" data-bs-dismiss="modal"><?php echo _("Cancel"); ?></button>
          <?php if (!OPENAP_MONITOR_ENABLED) : ?>
            <button type="submit" class="btn btn-primary openap-admin-save" name="UpdateAdminPassword" value="1">
              <i class="fas fa-save me-1"></i><?php echo _("Save settings"); ?>
            </button>
          <?php endif ?>
        </div>
      </form>
    </div>
  </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function () {
  var form = document.getElementById('openapAdminModalForm');
  var feedback = document.getElementById('openapAdminFeedback');
  var modal = document.getElementById('openapAdminModal');
  var username = document.getElementById('openap-admin-username');
  var newPassword = document.getElementById('openap-admin-newpass');
  var repeatedPassword = document.getElementById('openap-admin-newpassagain');
  var passwordMatchFeedback = document.getElementById('openap-admin-password-match-feedback');

  if (!form || !feedback || !newPassword || !repeatedPassword) {
    return;
  }

  function validatePasswordMatch(showState) {
    var hasRepeat = repeatedPassword.value.length > 0;
    var mismatch = hasRepeat && newPassword.value !== repeatedPassword.value;
    repeatedPassword.setCustomValidity(mismatch ? 'Passwords do not match.' : '');
    repeatedPassword.setAttribute('aria-invalid', mismatch ? 'true' : 'false');
    repeatedPassword.classList.toggle('is-invalid', mismatch && (showState || hasRepeat));
    repeatedPassword.classList.toggle('is-valid', hasRepeat && !mismatch);
    if (passwordMatchFeedback) {
      passwordMatchFeedback.textContent = mismatch
        ? '<?php echo addslashes(_("Passwords do not match.")); ?>'
        : '<?php echo addslashes(_("Please re-enter your new password.")); ?>';
    }
    return !mismatch;
  }

  newPassword.addEventListener('input', function () {
    validatePasswordMatch(false);
  });
  repeatedPassword.addEventListener('input', function () {
    validatePasswordMatch(false);
  });

  function resetAdminForm() {
    form.reset();
    form.classList.remove('was-validated');
    feedback.innerHTML = '';
    form.querySelectorAll('.is-valid, .is-invalid').forEach(function (field) {
      field.classList.remove('is-valid', 'is-invalid');
      field.setAttribute('aria-invalid', 'false');
    });
    repeatedPassword.setCustomValidity('');
    if (passwordMatchFeedback) {
      passwordMatchFeedback.textContent = '<?php echo addslashes(_("Please re-enter your new password.")); ?>';
    }
    ['oldpass', 'newpass', 'newpassagain'].forEach(function (fieldName) {
      var field = form.querySelector('[name="' + fieldName + '"]');
      if (field) {
        field.value = '';
        field.type = 'password';
      }
    });
    form.querySelectorAll('.js-toggle-password i').forEach(function (icon) {
      icon.classList.remove('fa-eye-slash');
      icon.classList.add('fa-eye');
    });
  }

  if (modal) {
    modal.addEventListener('hidden.bs.modal', resetAdminForm);
  }

  form.addEventListener('submit', function (event) {
    var submitter = event.submitter || document.activeElement;

    if (submitter && submitter.name === 'logout') {
      return;
    }

    event.preventDefault();
    event.stopPropagation();

    validatePasswordMatch(true);
    if (!form.checkValidity()) {
      form.classList.add('was-validated');
      return;
    }

    var saveButton = form.querySelector('[name="UpdateAdminPassword"]');
    var originalText = saveButton ? saveButton.innerHTML : '';
    var formData = new FormData(form);
    var csrfField = form.querySelector('[name="csrf_token"]');
    var csrfToken = csrfField ? csrfField.value : '';
    formData.set('UpdateAdminPassword', '1');

    feedback.innerHTML = '';
    if (saveButton) {
      saveButton.disabled = true;
      saveButton.innerHTML = '<span class="spinner-border spinner-border-sm me-2" role="status" aria-hidden="true"></span><?php echo _("Saving"); ?>';
    }

    fetch(form.action, {
      method: 'POST',
      body: formData,
      credentials: 'same-origin',
      headers: {
        'X-Requested-With': 'XMLHttpRequest',
        'X-CSRF-Token': csrfToken,
        'Accept': 'application/json'
      }
    })
      .then(function (response) {
        return response.text().then(function (text) {
          if (!response.ok) {
            throw new Error(text || 'HTTP ' + response.status);
          }
          try {
            return JSON.parse(text);
          } catch (error) {
            throw new Error(text ? text.slice(0, 220) : 'Empty response');
          }
        });
      })
      .then(function (data) {
        feedback.innerHTML = data.messageHtml || '';
        if (data.success) {
          if (username && data.username) {
            username.value = data.username;
            username.defaultValue = data.username;
          }
          form.classList.remove('was-validated');
          ['oldpass', 'newpass', 'newpassagain'].forEach(function (fieldName) {
            var field = form.querySelector('[name="' + fieldName + '"]');
            if (field) {
              field.value = '';
            }
          });
          repeatedPassword.setCustomValidity('');
          repeatedPassword.classList.remove('is-valid', 'is-invalid');
          repeatedPassword.setAttribute('aria-invalid', 'false');
        }
      })
      .catch(function (error) {
        var detail = error && error.message ? error.message : '<?php echo _("Please try again."); ?>';
        feedback.innerHTML = '<div class="alert alert-danger fade show" role="alert"><?php echo _("Unable to update account settings."); ?><div class="small mt-1">' + detail.replace(/[&<>"']/g, function (char) {
          return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#039;'}[char];
        }) + '</div></div>';
      })
      .finally(function () {
        if (saveButton) {
          saveButton.disabled = false;
          saveButton.innerHTML = originalText;
        }
      });
  });
});
</script>
