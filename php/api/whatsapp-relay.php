<?php
// Relay: accepts POST with JSON body { chatId, text }
// Forwards to OpenWA with correct API key
require_once __DIR__ . '/../env-loader.php';
session_start();
if (!($_SESSION['tetoca_admin'] ?? false)) {
    http_response_code(401);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    echo json_encode(['error' => 'Method not allowed']);
    exit;
}

$input = json_decode(file_get_contents('php://input'), true);
if (!$input || !isset($input['chatId']) || !isset($input['text'])) {
    http_response_code(400);
    echo json_encode(['error' => 'Missing chatId or text']);
    exit;
}

$sessionId = $_ENV['OPENWA_SESSION_ID'];
if (!$sessionId) { http_response_code(500); echo json_encode(['error' => 'OPENWA_SESSION_ID no configurado']); exit; }
$openwa_url = openwaBaseUrl() . "/api/sessions/{$sessionId}/messages/send-text";

$ch = curl_init($openwa_url);
curl_setopt_array($ch, [
    CURLOPT_RETURNTRANSFER => true,
    CURLOPT_POST => true,
    CURLOPT_POSTFIELDS => json_encode([
        'chatId' => $input['chatId'],
        'text' => $input['text']
    ]),
    CURLOPT_HTTPHEADER => [
        'Content-Type: application/json',
        'X-API-Key: ' . ($_ENV['OPENWA_API_KEY'] ?? '')
    ],
    CURLOPT_TIMEOUT => 10,
    CURLOPT_CONNECTTIMEOUT => 5,
]);

$response = curl_exec($ch);
$httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
$error = curl_error($ch);
curl_close($ch);

if ($error) {
    http_response_code(502);
    echo json_encode(['error' => 'OpenWA relay failed', 'detail' => $error]);
    exit;
}

http_response_code($httpCode);
echo $response;
