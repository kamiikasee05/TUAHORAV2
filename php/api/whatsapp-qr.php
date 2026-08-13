<?php
require_once __DIR__ . '/../env-loader.php';
session_start();
if (!($_SESSION['tetoca_admin'] ?? false)) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json');

$apiKey = $_ENV['OPENWA_API_KEY'] ?? '';
$sessionId = $_ENV['OPENWA_SESSION_ID'] ?? '';
if (!$apiKey || !$sessionId) { http_response_code(500); echo json_encode(['error' => 'OPENWA_API_KEY y OPENWA_SESSION_ID requeridos']); exit; }

$ch = curl_init(openwaBaseUrl() . "/api/sessions/{$sessionId}/qr");
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_TIMEOUT => 5,
    CURLOPT_HTTPHEADER => ["X-API-Key: {$apiKey}"],
]);
$res = curl_exec($ch);
$code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
curl_close($ch);

if ($code !== 200) {
    http_response_code(502);
    echo json_encode(['error' => 'OpenWA no disponible', 'status' => 'disconnected']);
    exit;
}

$data = json_decode($res, true);
$qr = null;
$status = 'disconnected';

if (isset($data['qrCode'])) {
    $qr = $data['qrCode'];
    $rawStatus = $data['status'] ?? 'initializing';
    // Map OpenWA status names to what the admin panel expects
    $statusMap = [
        'qr_ready' => 'awaiting_qr',
        'initializing' => 'awaiting_qr',
        'connected' => 'connected',
        'disconnected' => 'disconnected',
    ];
    $status = $statusMap[$rawStatus] ?? 'awaiting_qr';
} elseif (isset($data['status'])) {
    $rawStatus = $data['status'];
    $statusMap = [
        'qr_ready' => 'awaiting_qr',
        'initializing' => 'awaiting_qr',
        'connected' => 'connected',
        'disconnected' => 'disconnected',
    ];
    $status = $statusMap[$rawStatus] ?? 'awaiting_qr';
}

echo json_encode(['qr' => $qr, 'status' => $status]);
