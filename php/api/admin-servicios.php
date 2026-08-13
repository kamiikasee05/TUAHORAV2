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
        $r = schedulerApiCall('/services');
        http_response_code($r['httpCode']);
        echo json_encode($r['data']);
        break;

    case 'POST':
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data || empty($data['name'])) {
            http_response_code(400);
            echo json_encode(['error' => 'Nombre requerido']);
            exit;
        }
        $r = schedulerApiCall('/services', 'POST', [
            'name' => $data['name'],
            'duration' => (int)($data['duration'] ?? 30),
            'price' => (float)($data['price'] ?? 0),
            'currency' => $data['currency'] ?? 'ARS',
            'description' => $data['description'] ?? '',
            'slotInterval' => (int)($data['slotInterval'] ?? 15),
            'attendantsNumber' => 1,
            'serviceCategoryId' => $data['serviceCategoryId'] ?? null,
        ]);
        http_response_code($r['httpCode']);
        echo json_encode($r['data']);
        break;

    case 'PUT':
        $id = $_GET['id'] ?? null;
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'ID requerido']); exit; }
        $data = json_decode(file_get_contents('php://input'), true);
        if (!$data) { http_response_code(400); echo json_encode(['error'=>'Datos inválidos']); exit; }
        $r = schedulerApiCall("/services/$id", 'PUT', $data);
        http_response_code($r['httpCode']);
        echo json_encode($r['data']);
        break;

    case 'DELETE':
        $id = $_GET['id'] ?? null;
        if (!$id) { http_response_code(400); echo json_encode(['error'=>'ID requerido']); exit; }
        $r = schedulerApiCall("/services/$id", 'DELETE');
        http_response_code($r['httpCode']);
        echo json_encode($r['data']);
        break;

    default:
        http_response_code(405);
        echo json_encode(['error'=>'Método no permitido']);
}
