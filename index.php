<?php

/**
 * OpenAP WiFi access point and repeater management
 *
 * Derived from the RaspAP web interface and adapted for OpenAP's validated
 * AP-over-Ethernet and two-radio repeater workflows.
 *
 * @author  Lawrence Yau <sirlagz@gmail.com>
 * @author  Bill Zimmerman <billzimmerman@gmail.com>
 * @author  OpenAP contributors
 * @license GNU General Public License, version 3 (GPL-3.0)
 * @version 0.2.5.1
 * @link    https://github.com/AngelsWillRule/OpenAP
 * @see     https://github.com/RaspAP/raspap-webgui
 */

require_once 'includes/bootstrap.php';
require_once 'includes/config.php';
require_once 'includes/defaults.php';
require_once 'includes/autoload.php';
$handler = new OpenAP\Exceptions\ExceptionHandler;

require_once 'includes/CSRF.php';
require_once 'includes/session.php';
require_once 'includes/locale.php';
require_once 'includes/functions.php';

// Default page actions
require_once 'includes/dashboard.php';
require_once 'includes/clients.php';
require_once 'includes/repeater.php';
require_once 'includes/login.php';
require_once 'includes/authenticate.php';
require_once 'includes/admin.php';
require_once 'includes/hostapd.php';
require_once 'includes/system.php';
require_once 'includes/sysstats.php';
require_once 'includes/about.php';

// Load optional feature modules only when the active profile enables them.
// This keeps disabled legacy modules outside the OpenAP request path while
// preserving the upstream feature switches for other profiles.
$optionalFeatureModules = [
    'OPENAP_DHCP_ENABLED' => 'includes/dhcp.php',
    'OPENAP_ADBLOCK_ENABLED' => 'includes/adblock.php',
    'OPENAP_WIFICLIENT_ENABLED' => 'includes/configure_client.php',
    'OPENAP_NETWORK_ENABLED' => 'includes/networking.php',
    'OPENAP_VNSTAT_ENABLED' => 'includes/data_usage.php',
    'OPENAP_OPENVPN_ENABLED' => 'includes/openvpn.php',
    'OPENAP_WIREGUARD_ENABLED' => 'includes/wireguard.php',
    'OPENAP_VPN_PROVIDER_ENABLED' => 'includes/provider.php',
    'OPENAP_RESTAPI_ENABLED' => 'includes/restapi.php',
];

foreach ($optionalFeatureModules as $featureConstant => $modulePath) {
    if (defined($featureConstant) && constant($featureConstant)) {
        require_once $modulePath;
    }
}
unset($optionalFeatureModules, $featureConstant, $modulePath);

initializeApp();

// Machine-readable endpoints must run before any page markup is emitted.
// Keeping health checks outside the normal layout also makes reboot polling
// inexpensive and guarantees a valid JSON response body.
if (($_SERVER['PATH_INFO'] ?? '') === '/api/health') {
    require_once 'includes/api_health.php';
    openapServeHealthJson();
}
?>
<!DOCTYPE html>
<html lang="en" <?php setTheme();?>>
  <head>
    <meta charset="utf-8">
    <?php echo \OpenAP\Tokens\CSRF::metaTag(); ?>
    <meta http-equiv="X-UA-Compatible" content="IE=edge" />
    <meta name="viewport" content="width=device-width, initial-scale=1, shrink-to-fit=no" />
    <meta name="description" content="">
    <meta name="author" content="">

    <title><?php echo OPENAP_BRAND_TITLE; ?></title>

    <!-- Bootstrap Core CSS -->
    <link href="dist/bootstrap/css/bootstrap.min.css?v=<?= filemtime('dist/bootstrap/css/bootstrap.min.css'); ?>" rel="stylesheet">

    <!-- SB-Admin CSS -->
    <link href="dist/sb-admin/css/styles.css?v=<?= filemtime('dist/sb-admin/css/styles.css'); ?>" rel="stylesheet">

    <!-- Font Awesome -->
    <link href="dist/font-awesome/css/all.min.css?v=<?= filemtime('dist/font-awesome/css/all.min.css'); ?>" rel="stylesheet" type="text/css">

    <!-- Custom CSS -->
    <link href="app/css/base.css?v=<?= filemtime('app/css/base.css'); ?>" rel="stylesheet" />
    <link href="<?php echo $_SESSION['theme']['url'] . "?v=" . filemtime($_SESSION['theme']['url']); ?>" title="main" rel="stylesheet">
    <link href="app/css/openap-recovered-responsive.css?v=<?= filemtime('app/css/openap-recovered-responsive.css'); ?>" rel="stylesheet" />
    
    <!-- Meta -->
    <link rel="icon" type="image/png" href="/app/icons/openap-favicon-96x96.png?v=<?= filemtime('app/icons/openap-favicon-96x96.png'); ?>" sizes="96x96" />
    <link rel="shortcut icon" href="/app/icons/openap-favicon.ico?v=<?= filemtime('app/icons/openap-favicon.ico'); ?>" />
    <link rel="apple-touch-icon" sizes="180x180" href="/app/icons/openap-apple-touch-icon.png?v=<?= filemtime('app/icons/openap-apple-touch-icon.png'); ?>" />
    <link rel="manifest" href="/app/icons/site.webmanifest?v=<?= filemtime('app/icons/site.webmanifest'); ?>" />
    <meta name="apple-mobile-web-app-title" content="<?php echo htmlspecialchars(OPENAP_BRAND_TEXT); ?>" />
    <script>
      (function () {
        try {
          if (window.matchMedia('(max-width: 991.98px)').matches &&
              window.localStorage.getItem('openap|mobile-sidebar-open') === 'true') {
            document.documentElement.classList.add('openap-mobile-sidebar-open');
          }
        } catch (e) {}
      })();
    </script>
    <meta name="theme-color" content="#ffffff">
  </head>

  <body class="sb-nav-fixed">
    <!-- Navbar -->
    <?php ob_start(); ?>
    <?php require_once 'includes/navbar.php'; ?>
    <!-- End of Navbar -->
    <div id="layoutSidenav">
      <div id="layoutSidenav_nav">
        <nav class="sb-sidenav accordion sb-sidenav-light" id="sidenavAccordion">
          <div class="sb-sidenav-menu">
            <div class="nav">
              <!-- Sidebar -->
              <?php require_once 'includes/sidebar.php'; ?>
              <!-- End of Sidebar -->
            </div>
          </div>
        </nav>
      </div>
      <div id="layoutSidenav_content">
        <main>
          <div class="container-fluid mt-2">
            <?php require_once 'includes/page_actions.php'; ?>
          </div>
        </main>
        <footer class="py-4 mt-auto">
          <div class="container-fluid px-4">
            <?php require_once 'includes/footer.php'; ?>
          </div>
        </footer>
      </div>
    </div>
    <?php ob_end_flush(); ?>
    <!-- jQuery -->
    <script src="dist/jquery/jquery.min.js?v=<?= filemtime('dist/jquery/jquery.min.js'); ?>"></script>

    <!-- Bootstrap Core JavaScript -->
    <script src="dist/bootstrap/js/bootstrap.bundle.min.js?v=<?= filemtime('dist/bootstrap/js/bootstrap.bundle.min.js'); ?>"></script>

    <!-- SB-Admin JavaScript -->
    <script src="dist/sb-admin/js/scripts.js?v=<?= filemtime('dist/sb-admin/js/scripts.js'); ?>"></script>

    <!-- jQuery Mask plugin -->
    <script src="dist/jquery-mask/jquery.mask.min.js?v=<?= filemtime('dist/jquery-mask/jquery.mask.min.js'); ?>"></script>

    <script type="module" src="app/js/app.js?v=<?= filemtime('app/js/app.js'); ?>"></script>

    <?php loadFooterScripts($extraFooterScripts); ?>
  </body>
</html>
