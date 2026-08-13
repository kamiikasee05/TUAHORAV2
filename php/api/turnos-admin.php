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
    case 'GET':
        $month = $_GET['month'] ?? '';
        $appts = schedulerApiCall('/appointments?with=customer,service')['data'] ?? [];
        $result = [];
        foreach ($appts as $a) {
            if ($a['providerId'] != providerId()) continue;
            if ($month) { $d = substr($a['start'], 0, 7); if ($d !== $month) continue; }
            $result[] = [
                'id' => (int)$a['id'],
                'start' => $a['start'],
                'end' => $a['end'],
                'status' => $a['status'] ?? 'confirmed',
                'notes' => $a['notes'] ?? '',
                'hash' => $a['hash'] ?? '',
                'service' => $a['service'] ?? null,
                'customer' => $a['customer'] ?? null,
            ];
        }
        echo json_encode($result);
        break;

    case 'PUT':
        $id = $_GET['id'] ?? null;
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'ID requerido']); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['start']) || empty($data['end'])) {
            http_response_code(400); echo json_encode(['error'=>'start y end requeridos']); exit;
        }
        $current = schedulerApiCall("/appointments/$id")['data'] ?? [];
        if (!$current || isset($current['success'])) {
            http_response_code(404); echo json_encode(['error'=>'Turno no encontrado']); exit;
        }
        $r = schedulerApiCall("/appointments/$id", 'PUT', [
            'start' => $data['start'], 'end' => $data['end'],
            'serviceId' => $current['serviceId'], 'providerId' => $current['providerId'],
            'customerId' => $current['customerId'], 'notes' => $current['notes'] ?? '',
        ]);
        if ($r['httpCode'] !== 200) {
            http_response_code(500); echo json_encode(['error'=>'Error al reagendar','detail'=>$r['data']]); exit;
        }
        echo json_encode(['success'=>true,'message'=>'Turno reagendado']);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'ID requerido']); exit; }
        $r = schedulerApiCall("/appointments/$id", 'DELETE');
        if ($r['httpCode'] !== 200) {
            http_response_code(500); echo json_encode(['error'=>'Error al cancelar','detail'=>$r['data']]); exit;
        }
        echo json_encode(['success'=>true,'message'=>'Turno cancelado']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error'=>'Método no permitido']);
}
