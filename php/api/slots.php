<?php
// Proxy GET de horarios disponibles -> Scheduler /slots
// Reemplazo del legacy api/horarios.php (v1, no copiado) que el admin
// usa para reagendar turnos.
require_once __DIR__ . '/../env-loader.php';
header('Content-Type: application/json');
require_once __DIR__ . '/cors.php';

$serviceId = (int)($_GET['serviceId'] ?? 0);
$date = $_GET['date'] ?? '';
if (!$serviceId || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $date)) {
    http_response_code(400);
    echo json_encode(['error' => 'Faltan serviceId y date (YYYY-MM-DD)']);
    exit;
}

$r = schedulerApiCall("/slots?serviceId={$serviceId}&date={$date}");
if ($r['httpCode'] !== 200) {
    http_response_code(502);
    echo json_encode(['slots' => [], 'error' => 'Error al consultar horarios']);
    exit;
}

echo json_encode($r['data']);
