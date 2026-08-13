<?php
require_once __DIR__ . '/../env-loader.php';
session_start();
if (!($_SESSION['tetoca_admin'] ?? false)) {
    http_response_code(401);
    echo json_encode(['error' => 'No autorizado']);
    exit;
}

header('Content-Type: application/json');
$method = $_SERVER['REQUEST_METHOD'];

switch ($method) {
    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['appointmentId'])) {
            http_response_code(400);
            echo json_encode(['error' => 'appointmentId requerido']);
            exit;
        }
        $r = schedulerApiCall('/payments', 'POST', $data);
        if ($r['httpCode'] >= 400) {
            http_response_code($r['httpCode']);
            echo json_encode(['error' => 'Error al registrar pago', 'detail' => $r['data']]);
            exit;
        }
        echo json_encode(['success' => true, 'payment' => $r['data']]);
        break;

    case 'GET':
        $month = $_GET['month'] ?? '';
        $r = schedulerApiCall('/payments/stats' . ($month ? "?month=$month" : ''));
        echo json_encode($r['data']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error' => 'Método no permitido']);
}
