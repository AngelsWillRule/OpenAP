<?php

require_once '../../includes/autoload.php';
require_once '../../includes/session.php';
require_once '../../includes/config.php';
require_once '../../includes/authenticate.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');

$interface = isset($_GET['interface']) ? (string) $_GET['interface'] : '';
if (!preg_match('/^[A-Za-z0-9_.:-]+$/', $interface)) {
    http_response_code(400);
    echo json_encode(['error' => 'Invalid interface']);
    exit;
}

$statisticsPath = '/sys/class/net/' . $interface . '/statistics';
$rxPath = $statisticsPath . '/rx_bytes';
$txPath = $statisticsPath . '/tx_bytes';
if (!is_readable($rxPath) || !is_readable($txPath)) {
    http_response_code(404);
    echo json_encode(['error' => 'Interface not available']);
    exit;
}

$rxBytes = trim((string) file_get_contents($rxPath));
$txBytes = trim((string) file_get_contents($txPath));
if (!ctype_digit($rxBytes) || !ctype_digit($txBytes)) {
    http_response_code(500);
    echo json_encode(['error' => 'Traffic counters unavailable']);
    exit;
}

echo json_encode([
    'interface' => $interface,
    'rx_bytes' => (int) $rxBytes,
    'tx_bytes' => (int) $txBytes,
    'timestamp_ms' => (int) round(microtime(true) * 1000),
]);
