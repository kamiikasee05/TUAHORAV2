<?php
ob_start();
require_once __DIR__ . '/../env-loader.php';
session_start();

$storedHash = $_ENV['ADMIN_PASSWORD_HASH'] ?? '';
if (!$storedHash || $storedHash === 'CAMBIAR_HASH_BCRYPT') {
    http_response_code(500);
    header('Content-Type: application/json');
    echo json_encode(['error' => 'ADMIN_PASSWORD_HASH no configurado. Ejecutar: php -r "echo password_hash(\'<password>\', PASSWORD_BCRYPT);"']);
    exit;
}

$configFile = __DIR__ . '/../config.json';
if (!file_exists($configFile)) {
    $config = [
        'brand' => ['name' => 'Nails by Laura', 'tagline' => '', 'address' => '', 'whatsapp' => '', 'instagram' => '', 'profesional' => ''],
        'colors' => ['primary' => '#E8A0A0', 'secondary' => '#F5F0F0', 'accent' => '#B56576', 'text' => '#2D2D2D', 'background' => '#FFFFFF'],
        'logo' => 'uploads/logo.png',
        'gallery' => [],
        'professionals' => [],
    ];
    file_put_contents($configFile, json_encode($config, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));
}
$config = json_decode(file_get_contents($configFile), true) ?: [];

function saveConfig(array $data): bool {
    global $configFile;
    $json = json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
    if ($json === false) return false;
    $result = @file_put_contents($configFile, $json);
    if ($result === false) return false;
    // LEGACY: ya no se replica a web/config.json. La landing (SPA) la sirve el
    // Scheduler desde scheduler/data/config.json via GET /config.json; php/config.json
    // queda solo como cache local (professionals + fallback si el Scheduler no responde).
    return true;
}

function schedulerBranding(): ?array {
    $res = schedulerApiCall('branding');
    if (($res['httpCode'] ?? 0) === 200 && is_array($res['data'])) {
        return $res['data'];
    }
    return null;
}

function schedulerBaseUrl(): string {
    return rtrim(preg_replace('#/api/v1/?$#', '', schedulerApiUrl()), '/');
}

function normalizeGalleryItems(array $gallery): array {
    $items = [];
    foreach ($gallery as $g) {
        if (is_array($g) && isset($g['filename'])) {
            $items[] = $g;
        } elseif (is_string($g)) {
            $items[] = ['filename' => basename($g)];
        }
    }
    return $items;
}

function buildMultipartBody(array $parts): array {
    $boundary = '----FormBoundary' . bin2hex(random_bytes(8));
    $body = '';
    foreach ($parts as $p) {
        $body .= '--' . $boundary . "\r\n";
        if (isset($p['path'])) {
            $body .= 'Content-Disposition: form-data; name="' . $p['name'] . '"; filename="' . $p['filename'] . "\"\r\n";
            $body .= 'Content-Type: ' . $p['type'] . "\r\n\r\n";
            $body .= file_get_contents($p['path']);
            $body .= "\r\n";
        } else {
            $body .= 'Content-Disposition: form-data; name="' . $p['name'] . "\"\r\n\r\n";
            $body .= $p['value'];
            $body .= "\r\n";
        }
    }
    $body .= '--' . $boundary . "--\r\n";
    return [$body, $boundary];
}

function jsonResponse(array $data, int $code = 200): void {
    if (ob_get_level()) ob_clean();
    http_response_code($code);
    header('Content-Type: application/json');
    echo json_encode($data);
    exit;
}

$isLoggedIn = ($_SESSION['tetoca_admin'] ?? false) === true;

if (isset($_GET['logout'])) {
    session_destroy();
    header('Location: /admin/index.php');
    exit;
}

$attemptsFile = sys_get_temp_dir() . '/admin_login_attempts.json';
$attempts = [];
if (file_exists($attemptsFile)) {
    $attempts = json_decode(file_get_contents($attemptsFile), true) ?: [];
}
$ip = $_SERVER['HTTP_X_REAL_IP'] ?? $_SERVER['REMOTE_ADDR'] ?? '127.0.0.1';
$now = time();
$window = 900;
$maxAttempts = 5;
if (!isset($attempts[$ip])) { $attempts[$ip] = []; }
$attempts[$ip] = array_values(array_filter($attempts[$ip], fn($t) => $now - $t < $window));

if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] === 'login') {
    if (count($attempts[$ip]) >= $maxAttempts) {
        $loginError = 'Demasiados intentos. Espera 15 minutos.';
    } else {
        $inputPass = $_POST['password'] ?? '';
        $passwordValid = password_verify($inputPass, $storedHash);
        if ($passwordValid) {
            $attempts[$ip] = [];
            file_put_contents($attemptsFile, json_encode($attempts));
            $_SESSION['tetoca_admin'] = true;
            $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
            header('Location: /admin/index.php');
            exit;
        }
        $attempts[$ip][] = $now;
        file_put_contents($attemptsFile, json_encode($attempts));
        $loginError = 'Contraseña incorrecta';
    }
}

if ($isLoggedIn && $_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['action']) && $_POST['action'] !== 'login') {
    if (!isset($_POST['csrf_token']) || ($_POST['csrf_token'] ?? '') !== ($_SESSION['csrf_token'] ?? '')) {
        jsonResponse(['error' => 'Token CSRF invalido'], 403);
    }
    $action = $_POST['action'];

    if ($action === 'save_brand') {
        $brand = $config['brand'] ?? [];
        $brand['name'] = trim($_POST['name'] ?? '');
        $brand['tagline'] = trim($_POST['tagline'] ?? '');
        $brand['address'] = trim($_POST['address'] ?? '');
        $brand['whatsapp'] = trim($_POST['whatsapp'] ?? '');
        $brand['instagram'] = trim($_POST['instagram'] ?? '');
        $brand['profesional'] = trim($_POST['profesional'] ?? '');
        $config['brand'] = $brand;
        if (!saveConfig($config)) {
            jsonResponse(['error' => 'No se puede guardar: permisos de escritura en config.json'], 500);
        }
        // Scheduler = fuente de verdad del branding (hace syncProvider internamente).
        $res = schedulerApiCall('branding', 'PUT', ['brand' => $brand]);
        if (($res['httpCode'] ?? 0) < 200 || ($res['httpCode'] ?? 0) >= 300) {
            $detail = is_array($res['data']) ? ($res['data']['message'] ?? json_encode($res['data'])) : ($res['error'] ?: 'Scheduler no disponible');
            jsonResponse(['error' => 'Marca guardada en caché, pero el Scheduler no se actualizó: ' . $detail], 502);
        }
        jsonResponse(['success' => true, 'brand' => $brand]);
    }

    if ($action === 'save_colors') {
        $colors = $config['colors'] ?? [];
        $colors['primary'] = $_POST['primary'] ?? '';
        $colors['secondary'] = $_POST['secondary'] ?? '';
        $colors['accent'] = $_POST['accent'] ?? '';
        $colors['text'] = $_POST['text'] ?? '';
        $colors['background'] = $_POST['background'] ?? '';
        $config['colors'] = $colors;
        if (!saveConfig($config)) {
            jsonResponse(['error' => 'No se puede guardar: permisos de escritura en config.json'], 500);
        }
        $res = schedulerApiCall('branding', 'PUT', ['colors' => $colors]);
        if (($res['httpCode'] ?? 0) < 200 || ($res['httpCode'] ?? 0) >= 300) {
            $detail = is_array($res['data']) ? ($res['data']['message'] ?? json_encode($res['data'])) : ($res['error'] ?: 'Scheduler no disponible');
            jsonResponse(['error' => 'Colores guardados en caché, pero el Scheduler no se actualizó: ' . $detail], 502);
        }
        jsonResponse(['success' => true, 'colors' => $colors]);
    }

    if ($action === 'upload_logo') {
        if (empty($_FILES['logo']) || $_FILES['logo']['error'] !== UPLOAD_ERR_OK) {
            jsonResponse(['error' => 'Error al subir el archivo'], 400);
        }
        $file = $_FILES['logo'];
        // Forward al Scheduler: valida mime/magia de bytes y guarda en scheduler/data/uploads/.
        $url = schedulerApiUrl() . '/branding/logo';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => ['file' => new CURLFile($file['tmp_name'], $file['type'], $file['name'])],
            CURLOPT_HTTPHEADER => ['X-API-Key: ' . ($_ENV['SCHEDULER_API_KEY'] ?? '')],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 15,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $resp = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !($resp['success'] ?? false)) {
            jsonResponse(['error' => 'El Scheduler rechazó el logo: ' . ($resp['message'] ?? ($err ?: 'error desconocido'))], 502);
        }
        $config['logo'] = $resp['logo'] ?? '';
        saveConfig($config);
        jsonResponse(['success' => true, 'logo' => $config['logo']]);
    }

    if ($action === 'delete_logo') {
        $res = schedulerApiCall('branding/logo', 'DELETE');
        if (($res['httpCode'] ?? 0) < 200 || ($res['httpCode'] ?? 0) >= 300) {
            $detail = is_array($res['data']) ? ($res['data']['message'] ?? json_encode($res['data'])) : ($res['error'] ?: 'Scheduler no disponible');
            jsonResponse(['error' => 'No se pudo eliminar el logo en el Scheduler: ' . $detail], 502);
        }
        $config['logo'] = '';
        saveConfig($config);
        jsonResponse(['success' => true]);
    }

    if ($action === 'upload_gallery') {
        if (empty($_FILES['images'])) { jsonResponse(['error' => 'No se recibieron imágenes'], 400); }
        $files = $_FILES['images'];
        $parts = [];
        for ($i = 0; $i < count($files['name']); $i++) {
            if ($files['error'][$i] !== UPLOAD_ERR_OK) continue;
            $parts[] = [
                'name' => 'files',
                'path' => $files['tmp_name'][$i],
                'filename' => $files['name'][$i],
                'type' => $files['type'][$i] ?: 'application/octet-stream',
            ];
        }
        if (empty($parts)) { jsonResponse(['error' => 'No se pudo procesar ninguna imagen'], 400); }
        list($multipart, $boundary) = buildMultipartBody($parts);
        // Forward al Scheduler: valida mime/magia de bytes y guarda en scheduler/data/uploads/gallery/.
        $url = schedulerApiUrl() . '/branding/gallery';
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_POST => true,
            CURLOPT_POSTFIELDS => $multipart,
            CURLOPT_HTTPHEADER => [
                'X-API-Key: ' . ($_ENV['SCHEDULER_API_KEY'] ?? ''),
                'Content-Type: multipart/form-data; boundary=' . $boundary,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT => 30,
        ]);
        $body = curl_exec($ch);
        $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err = curl_error($ch);
        $resp = json_decode($body, true);
        if ($code < 200 || $code >= 300 || !($resp['success'] ?? false)) {
            jsonResponse(['error' => 'El Scheduler rechazó las imágenes: ' . ($resp['message'] ?? ($err ?: 'error desconocido'))], 502);
        }
        $galleryItems = [];
        $sched = schedulerBranding();
        if ($sched) { $galleryItems = normalizeGalleryItems($sched['gallery'] ?? []); }
        $config['gallery'] = $galleryItems;
        saveConfig($config);
        jsonResponse(['success' => true, 'gallery' => $galleryItems, 'added' => count($resp['added'] ?? []), 'errors' => []]);
    }

    if ($action === 'delete_gallery') {
        $filename = $_POST['filename'] ?? '';
        if (!$filename) { jsonResponse(['error' => 'Falta filename'], 400); }
        $res = schedulerApiCall('branding/gallery/' . rawurlencode($filename), 'DELETE');
        if (($res['httpCode'] ?? 0) < 200 || ($res['httpCode'] ?? 0) >= 300) {
            $detail = is_array($res['data']) ? ($res['data']['message'] ?? json_encode($res['data'])) : ($res['error'] ?: 'Scheduler no disponible');
            jsonResponse(['error' => 'No se pudo eliminar la imagen en el Scheduler: ' . $detail], 502);
        }
        $galleryItems = [];
        $sched = schedulerBranding();
        if ($sched) { $galleryItems = normalizeGalleryItems($sched['gallery'] ?? []); }
        $config['gallery'] = $galleryItems;
        saveConfig($config);
        jsonResponse(['success' => true, 'gallery' => $galleryItems]);
    }

    if ($action === 'save_professionals') {
        $profs = json_decode($_POST['professionals'] ?? '[]', true);
        if (!is_array($profs)) { jsonResponse(['error' => 'Datos inválidos'], 400); }
        if (!empty($_FILES['photo']) && $_FILES['photo']['error'] === UPLOAD_ERR_OK) {
            $profDir = __DIR__ . '/../uploads/professionals/';
            if (!is_dir($profDir)) { mkdir($profDir, 0755, true); }
            $ext = strtolower(pathinfo($_FILES['photo']['name'], PATHINFO_EXTENSION));
            if (!in_array($ext, ['jpg','jpeg','png'])) { jsonResponse(['error' => 'Solo JPG y PNG'], 400); }
            if ($_FILES['photo']['size'] > 2 * 1024 * 1024) { jsonResponse(['error' => 'Máximo 2MB'], 400); }
            $fname = 'prof_' . uniqid() . '.' . $ext;
            move_uploaded_file($_FILES['photo']['tmp_name'], $profDir . $fname);
            $photoIdx = (int)($_POST['photo_idx'] ?? -1);
            if ($photoIdx >= 0 && $photoIdx < count($profs)) {
                $oldPhoto = $profs[$photoIdx]['photo'] ?? '';
                if ($oldPhoto && file_exists(__DIR__ . '/../' . $oldPhoto)) { unlink(__DIR__ . '/../' . $oldPhoto); }
                $profs[$photoIdx]['photo'] = 'uploads/professionals/' . $fname;
            }
        }
        $config['professionals'] = $profs;
        if (!saveConfig($config)) {
            jsonResponse(['error' => 'No se puede guardar: permisos de escritura en config.json'], 500);
        }
        jsonResponse(['success' => true, 'professionals' => $profs]);
    }

    jsonResponse(['error' => 'Acción desconocida'], 400);
}

// Marca/Colores/Logo/Galeria: el Scheduler es la fuente de verdad (GET /api/v1/branding).
// php/config.json queda como cache local para profesionales y como fallback.
$schedulerBase = schedulerBaseUrl();
$schedBranding = schedulerBranding();
if ($schedBranding) {
    $config['brand'] = $schedBranding['brand'] ?? $config['brand'] ?? [];
    $config['colors'] = $schedBranding['colors'] ?? $config['colors'] ?? [];
    $config['logo'] = $schedBranding['logo'] ?? $config['logo'] ?? '';
    $config['gallery'] = normalizeGalleryItems($schedBranding['gallery'] ?? []);
    saveConfig($config);
}
$brand = $config['brand'] ?? [];
$colors = $config['colors'] ?? [];
$gallery = normalizeGalleryItems($config['gallery'] ?? []);
$logo = $config['logo'] ?? '';
$logoUrl = $logo ? $schedulerBase . '/' . $logo . '?t=' . time() : '';
$waNumber = $brand['whatsapp'] ?? '5493826403110';
$professionals = $config['professionals'] ?? [];
?>
<!DOCTYPE html>
<html lang="es">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>TeToca — Panel</title>
<link href="https://fonts.googleapis.com/css2?family=Material+Symbols+Outlined:wght,FILL@100..700,0..1&display=swap" rel="stylesheet">
<script src="https://cdn.tailwindcss.com"></script>
<script id="tailwind-config">
tailwind.config={theme:{extend:{colors:{"surface-container-low":"#f6f3f2","outline-variant":"#d7c2c1","primary":"#884e4f","on-surface-variant":"#524343","primary-container":"#e8a0a0","tertiary-container":"#f399ab","secondary-container":"#e6e1e1","tertiary":"#914758","secondary":"#605e5e","error-container":"#ffdad6"}}}}
</script>
<style>
.material-symbols-outlined { font-variation-settings: 'FILL' 0, 'wght' 400, 'GRAD' 0, 'opsz' 24; vertical-align: middle; }
.glass-nav { backdrop-filter: blur(12px); background: rgba(252,249,248,.7); }
.soft-shadow { box-shadow: 0 4px 16px rgba(0,0,0,.04); }
::-webkit-scrollbar { width: 5px; }
::-webkit-scrollbar-thumb { background: #d7c2c1; border-radius: 10px; }
.tab-content { display: none; }
.tab-content.active { display: block; }
.btn { display: inline-flex; align-items: center; gap: 6px; padding: 8px 16px; border-radius: 10px; font-size: 13px; cursor: pointer; border: none; text-decoration: none; transition: .2s; font-family: inherit; }
.btn-primary { background: #884e4f; color: #fff; }
.btn-primary:hover { background: #753e3f; }
.btn-ghost { background: transparent; color: #666; border: 1.5px solid #d7c2c1; }
.btn-ghost:hover { border-color: #884e4f; color: #884e4f; }
.btn-danger { background: #fef2f2; color: #e74c3c; }
.btn-danger:hover { background: #fee2e2; }
.btn-sm { padding: 5px 10px; font-size: 12px; }
.btn-xs { padding: 3px 8px; font-size: 11px; border-radius: 6px; }
table { width: 100%; border-collapse: collapse; }
th, td { text-align: left; padding: 12px 8px; border-bottom: 1px solid #f0ebe7; font-size: 14px; }
th { color: #999; font-weight: 500; text-transform: uppercase; font-size: 12px; letter-spacing: .5px; }
.card { background: #fff; border-radius: 16px; padding: 24px; margin-bottom: 24px; box-shadow: 0 4px 16px rgba(0,0,0,.04); border: 1px solid rgba(215, 194, 193, 0.2); }
.card h2 { font-size: 18px; margin-bottom: 16px; color: #555; }
.card .desc { font-size: 13px; color: #999; margin-bottom: 16px; }
.form-row { display: grid; grid-template-columns: 1fr 1fr; gap: 16px; margin-bottom: 16px; }
.form-row.three { grid-template-columns: 1fr 1fr 1fr; }
label { display: block; font-size: 13px; color: #666; margin-bottom: 4px; }
input, textarea, select { width: 100%; padding: 10px 12px; border: 1.5px solid #d7c2c1; border-radius: 10px; font-size: 14px; outline: none; font-family: inherit; transition: .2s; }
input:focus, textarea:focus { border-color: #884e4f; box-shadow: 0 0 0 3px rgba(136,78,79,.15); }
textarea { resize: vertical; min-height: 60px; }
.form-actions { display: flex; gap: 8px; justify-content: flex-end; margin-top: 8px; }
.hidden { display: none !important; }
.toast { position: fixed; bottom: 24px; right: 24px; background: #333; color: #fff; padding: 12px 20px; border-radius: 12px; font-size: 14px; opacity: 0; transition: .3s; z-index: 9999; pointer-events: none; }
.toast.show { opacity: 1; }
.modal-overlay { position: fixed; top: 0; left: 0; width: 100%; height: 100%; background: rgba(0,0,0,.35); display: none; align-items: center; justify-content: center; z-index: 9998; padding: 24px; backdrop-filter: blur(2px); }
.modal-overlay.show { display: flex; }
.modal { background: #fff; border-radius: 20px; padding: 32px; width: 100%; max-width: 460px; max-height: 90vh; overflow-y: auto; box-shadow: 0 16px 48px rgba(0,0,0,.15); position: relative; animation: modalIn .25s ease; }
@keyframes modalIn { from { opacity: 0; transform: scale(.95) translateY(10px); } to { opacity: 1; transform: scale(1) translateY(0); } }
.modal-close { position: absolute; top: 16px; right: 16px; background: transparent; border: none; font-size: 22px; cursor: pointer; color: #999; width: 32px; height: 32px; border-radius: 50%; display: flex; align-items: center; justify-content: center; transition: .2s; }
.modal-close:hover { background: #f0ebe7; color: #333; }
.modal h2 { font-size: 20px; color: #333; margin-bottom: 20px; }
.modal h3 { font-size: 15px; color: #555; margin-bottom: 8px; }
.modal .detail-row { display: flex; gap: 8px; margin-bottom: 10px; font-size: 14px; }
.modal .detail-row .icon { width: 20px; color: #884e4f; flex-shrink: 0; }
.modal .detail-row .val { color: #333; }
.status-badge { display: inline-block; padding: 3px 10px; border-radius: 20px; font-size: 12px; font-weight: 500; }
.status-badge.confirmed { background: #f5e1e4; color: #884e4f; }
.status-badge.cancelled { background: #f0ebe7; color: #999; }
.modal-actions { display: flex; gap: 8px; margin-top: 20px; flex-wrap: wrap; }
.reschedule-section { margin-top: 16px; padding-top: 16px; border-top: 1px solid #f0ebe7; }
.slot-options { display: flex; flex-wrap: wrap; gap: 6px; margin-top: 8px; }
.slot-btn { padding: 8px 16px; border: 1.5px solid #d7c2c1; border-radius: 10px; background: #fff; cursor: pointer; font-size: 13px; font-family: inherit; transition: .2s; color: #555; }
.slot-btn:hover { border-color: #884e4f; color: #884e4f; }
.slot-btn.selected { background: #884e4f; color: #fff; border-color: #884e4f; }
.cal-grid { display: grid; grid-template-columns: repeat(7,1fr); gap: 3px; background: #f0ebe7; border-radius: 12px; overflow: hidden; }
.cal-cell { background: #fff; min-height: 100px; padding: 4px; font-size: 12px; cursor: pointer; transition: .2s; overflow: hidden; }
.cal-cell:hover { background: #fdf6f0; }
.cal-cell.other-month { background: #faf8f6; color: #ccc; }
.cal-cell.today { background: #f5e1e4; }
.cal-cell .day-num { font-weight: 600; font-size: 13px; padding: 2px 4px; color: #666; }
.cal-cell.today .day-num { color: #884e4f; }
.cal-cell .cal-appt { padding: 2px 4px; margin: 1px 0; border-radius: 4px; font-size: 11px; cursor: pointer; white-space: nowrap; overflow: hidden; text-overflow: ellipsis; color: #fff; transition: .15s; }
.cal-cell .cal-appt:hover { opacity: .8; }
.cal-cell .cal-appt.confirmed { background: #884e4f; }
.cal-cell .cal-appt.cancelled { background: #e8ddd6; color: #999; text-decoration: line-through; }
.cal-cell .cal-appt .cal-time { font-weight: 500; }
.cal-weekday { background: #fff; padding: 8px 4px; text-align: center; font-size: 12px; font-weight: 600; color: #999; text-transform: uppercase; letter-spacing: .5px; border-bottom: 1px solid #f0ebe7; }
.precio { font-weight: 600; color: #884e4f; }
.duracion { color: #888; }
.empty-state { text-align: center; padding: 48px 24px; color: #bbb; }
.empty-state .icon { font-size: 48px; margin-bottom: 8px; }
.color-grid { display: grid; grid-template-columns: repeat(auto-fit,minmax(150px,1fr)); gap: 16px; }
.color-item { display: flex; flex-direction: column; align-items: center; gap: 6px; }
.color-item label { font-size: 12px; color: #888; }
.color-item input[type="color"] { width: 64px; height: 44px; border: 2px solid #d7c2c1; border-radius: 8px; padding: 2px; cursor: pointer; }
.preview-card { border-radius: 16px; overflow: hidden; border: 1px solid #eee; margin-top: 16px; }
.preview-header { padding: 14px 20px; display: flex; align-items: center; justify-content: space-between; color: #fff; font-weight: 600; font-size: 15px; }
.preview-hero { padding: 40px 20px; text-align: center; }
.preview-hero h3 { font-size: 22px; margin-bottom: 6px; }
.preview-hero p { font-size: 14px; opacity: .8; }
.preview-btn { display: inline-block; margin-top: 12px; padding: 10px 24px; border-radius: 24px; color: #fff; font-weight: 500; font-size: 14px; }
.logo-preview { max-width: 200px; max-height: 80px; margin-bottom: 12px; border-radius: 8px; }
.gallery-grid-pf { display: grid; grid-template-columns: repeat(auto-fill,minmax(140px,1fr)); gap: 12px; }
.gallery-thumb { position: relative; aspect-ratio: 1; border-radius: 12px; overflow: hidden; background: #f0ebe7; }
.gallery-thumb img { width: 100%; height: 100%; object-fit: cover; }
.gallery-thumb .delete-btn { position: absolute; top: 6px; right: 6px; width: 26px; height: 26px; border-radius: 50%; background: rgba(231,76,60,.85); color: #fff; border: none; cursor: pointer; font-size: 14px; display: flex; align-items: center; justify-content: center; }
.gallery-empty { grid-column: 1/-1; text-align: center; padding: 40px; color: #bbb; font-size: 14px; }
.upload-zone { border: 2px dashed #d7c2c1; border-radius: 12px; padding: 32px; text-align: center; cursor: pointer; transition: .2s; }
.upload-zone:hover { border-color: #884e4f; background: #fdf6f0; }
.upload-zone input[type="file"] { display: none; }
.wa-loading { color: #999; font-size: 14px; padding: 32px; }
.wa-error { color: #e74c3c; font-size: 14px; padding: 32px; }
.break-row { display: flex; gap: 4px; align-items: center; margin-bottom: 4px; flex-wrap: wrap; }
.break-row input { width: 80px; padding: 6px 8px; font-size: 12px; }
#form-horarios input[type="time"] { width: 110px; padding: 6px 8px; font-size: 13px; }
.sidebar-link { background: none; border: none; cursor: pointer; font-family: inherit; font-size: 0.875rem; color: #524343; transition: all 0.2s ease; }
.sidebar-link:hover { background: #f0eded; color: #884e4f; }
.sidebar-link.active { background: #e8a0a0; color: #6a3537; font-weight: 700; }
.table-wrap { overflow-x: auto; -webkit-overflow-scrolling: touch; }
.sidebar-overlay { display: none; }

@media(max-width:767px) {
  .form-row,.form-row.three { grid-template-columns: 1fr; }
  .cal-grid { gap: 2px; }
  .cal-cell { min-height: 60px; font-size: 10px; }
  .cal-cell .day-num { font-size: 11px; padding: 1px 2px; }
  .cal-cell .cal-appt { font-size: 9px; padding: 1px 2px; }
  .color-grid { grid-template-columns: repeat(2,1fr); }
  .sidebar-open .sidebar { transform: translateX(0); }
  .sidebar-open .sidebar-overlay { display: block; }
  .card { padding: 16px !important; }
  .card h2 { font-size: 16px; }
  .modal { padding: 20px; margin: 12px; }
  #form-horarios input[type="time"] { width: 90px; font-size: 12px; }
  .break-row input { width: 70px; font-size: 11px; }
}
</style>
</head>
<body class="bg-background text-on-surface font-sans antialiased min-h-screen">

<?php if (!$isLoggedIn): ?>
<div class="flex items-center justify-center min-h-screen px-6">
  <div class="bg-white rounded-2xl p-8 w-full max-w-sm shadow-lg border border-[#d7c2c1]/30 text-center">
    <div class="flex items-center justify-center gap-2 mb-2">
      <span class="material-symbols-outlined text-[#884e4f] text-3xl">spa</span>
      <h1 class="font-serif text-2xl text-[#884e4f]">TeToca</h1>
    </div>
    <p class="text-sm text-[#524343] mb-6">Panel de administración</p>
    <form method="post">
      <input type="hidden" name="action" value="login">
      <label class="block text-left text-xs font-semibold text-[#524343] mb-2">Contraseña</label>
      <input type="password" name="password" placeholder="••••••••" autofocus class="w-full px-4 py-3 border-2 border-[#d7c2c1] rounded-xl text-sm outline-none focus:border-[#884e4f] focus:ring-2 focus:ring-[#884e4f]/20 mb-4 font-sans">
      <button type="submit" class="w-full py-3 bg-[#884e4f] text-white rounded-xl font-medium hover:brightness-90 transition-all font-sans">Ingresar</button>
      <?php if (isset($loginError)): ?><p class="text-red-600 text-sm mt-4"><?=htmlspecialchars($loginError)?></p><?php endif; ?>
    </form>
  </div>
</div>

<?php else: ?>
<div class="flex">
  <!-- Sidebar overlay -->
  <div class="sidebar-overlay fixed inset-0 z-40 bg-black/30 md:hidden" id="sidebarOverlay" onclick="toggleSidebar()"></div>
  <!-- Sidebar -->
  <aside class="sidebar fixed -translate-x-full md:translate-x-0 top-0 left-0 z-50 w-64 h-screen bg-surface-container-low border-r border-outline-variant flex flex-col overflow-hidden transition-transform duration-300 ease-in-out" id="sidebar">
    <div class="flex items-center gap-2 px-5 pt-5 pb-3">
      <span class="material-symbols-outlined text-[#884e4f] text-2xl">spa</span>
      <span class="font-serif text-xl text-[#884e4f] tracking-tight font-semibold">TeToca</span>
    </div>
    <div class="px-4 pb-3 border-b border-outline-variant/40">
      <p class="text-xs font-medium text-[#524343]">Gesti&oacute;n de Sal&oacute;n</p>
    </div>
    <nav class="flex-1 overflow-y-auto p-2 space-y-0.5" id="sidebarNav">
      <button class="flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-lg text-sm sidebar-link active" data-tab="dashboard" data-title="Dashboard"><span class="material-symbols-outlined text-xl">dashboard</span> Dashboard</button>
      <button class="flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-lg text-sm sidebar-link" data-tab="servicios" data-title="Servicios"><span class="material-symbols-outlined text-xl">content_cut</span> Servicios</button>
      <button class="flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-lg text-sm sidebar-link" data-tab="horarios" data-title="Horarios"><span class="material-symbols-outlined text-xl">schedule</span> Horarios</button>
      <button class="flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-lg text-sm sidebar-link" data-tab="calendario" data-title="Calendario"><span class="material-symbols-outlined text-xl">calendar_month</span> Calendario</button>
      <button class="flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-lg text-sm sidebar-link" data-tab="turnos" data-title="Turnos"><span class="material-symbols-outlined text-xl">event_available</span> Turnos</button>
      <button class="flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-lg text-sm sidebar-link" data-tab="clientes" data-title="Clientes"><span class="material-symbols-outlined text-xl">group</span> Clientes</button>
      <button class="flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-lg text-sm sidebar-link" data-tab="profesionales" data-title="Profesionales"><span class="material-symbols-outlined text-xl">badge</span> Profesionales</button>
      <div class="h-px bg-outline-variant/40 my-2 mx-3"></div>
      <button class="flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-lg text-sm sidebar-link" data-tab="whatsapp" data-title="WhatsApp"><span class="material-symbols-outlined text-xl">chat</span> WhatsApp</button>
      <button class="flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-lg text-sm sidebar-link" data-tab="marca" data-title="Marca"><span class="material-symbols-outlined text-xl">brush</span> Marca</button>
      <button class="flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-lg text-sm sidebar-link" data-tab="logo" data-title="Logo"><span class="material-symbols-outlined text-xl">image</span> Logo</button>
      <button class="flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-lg text-sm sidebar-link" data-tab="colores" data-title="Colores"><span class="material-symbols-outlined text-xl">palette</span> Colores</button>
      <button class="flex items-center gap-3 w-full text-left px-3 py-2.5 rounded-lg text-sm sidebar-link" data-tab="galeria" data-title="Galería"><span class="material-symbols-outlined text-xl">photo_library</span> Galer&iacute;a</button>
    </nav>
    <div class="p-3 border-t border-outline-variant/40">
      <a href="?logout=1" class="flex items-center gap-3 px-3 py-2.5 rounded-lg text-sm text-[#ba1a1a] hover:bg-error-container/20 transition-colors"><span class="material-symbols-outlined text-xl">logout</span> Cerrar sesi&oacute;n</a>
    </div>
  </aside>

  <!-- Main Content -->
  <main class="flex-1 min-h-screen">
    <!-- Header -->
    <header class="sticky top-0 z-30 glass-nav border-b border-white/20 h-14 md:h-16 flex items-center justify-between px-3 md:px-8">
      <div class="flex items-center gap-3">
        <button class="md:hidden text-primary" onclick="toggleSidebar()" aria-label="Menu">
          <span class="material-symbols-outlined text-2xl">menu</span>
        </button>
        <h2 class="font-serif text-lg md:text-xl text-primary tracking-tight" id="header-title">TeToca &middot; Dashboard</h2>
      </div>
      <a href="?logout=1" class="text-xs md:text-sm text-[#524343] hover:text-primary transition-colors flex items-center gap-1"><span class="material-symbols-outlined text-lg">logout</span> <span class="hidden md:inline">Salir</span></a>
    </header>

    <div class="p-4 md:p-8 max-w-6xl mx-auto">
      <!-- DASHBOARD -->
      <div id="tab-dashboard" class="tab-content active">
        <div class="grid grid-cols-2 md:grid-cols-5 gap-4 mb-8" id="statsGrid">
          <div class="bg-white rounded-xl p-4 soft-shadow border border-outline-variant/20 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-primary-container/20 flex items-center justify-center text-primary"><span class="material-symbols-outlined text-xl">calendar_today</span></div>
            <div><p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Turnos hoy</p><p class="text-xl font-bold text-primary" id="statHoy">-</p></div>
          </div>
          <div class="bg-white rounded-xl p-4 soft-shadow border border-outline-variant/20 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-tertiary-container/20 flex items-center justify-center text-tertiary"><span class="material-symbols-outlined text-xl">payments</span></div>
            <div><p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Turnos esta semana</p><p class="text-xl font-bold text-primary" id="statSemana">-</p></div>
          </div>
          <div class="bg-white rounded-xl p-4 soft-shadow border border-outline-variant/20 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-secondary-container/50 flex items-center justify-center text-secondary"><span class="material-symbols-outlined text-xl">event_note</span></div>
            <div><p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Turnos este mes</p><p class="text-xl font-bold text-primary" id="statMes">-</p></div>
          </div>
          <div class="bg-white rounded-xl p-4 soft-shadow border border-outline-variant/20 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-[#e8f5e9] flex items-center justify-center text-[#2e7d32]"><span class="material-symbols-outlined text-xl">check_circle</span></div>
            <div><p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Cobrado</p><p class="text-xl font-bold text-[#2e7d32]" id="statCobrado">-</p></div>
          </div>
          <div class="bg-white rounded-xl p-4 soft-shadow border border-outline-variant/20 flex items-center gap-3">
            <div class="w-10 h-10 rounded-lg bg-[#fff3e0] flex items-center justify-center text-[#e65100]"><span class="material-symbols-outlined text-xl">hourglass_empty</span></div>
            <div><p class="text-xs font-bold text-on-surface-variant uppercase tracking-wider">Pendiente</p><p class="text-xl font-bold text-[#e65100]" id="statPendiente">-</p></div>
          </div>
        </div>
        <div class="card">
          <h2>Pr&oacute;ximos turnos (7 d&iacute;as)</h2>
          <div id="proximos-container"><div class="empty-state">Cargando...</div></div>
        </div>
      </div>

      <!-- SERVICIOS -->
      <div id="tab-servicios" class="tab-content">
        <div class="card">
          <h2>Agregar servicio</h2>
          <form id="form-servicio">
            <div class="form-row three">
              <div><label>Nombre</label><input name="name" required></div>
              <div><label>Precio (ARS)</label><input name="price" type="number" min="0" step="1" required></div>
              <div><label>Duraci&oacute;n (min)</label><input name="duration" type="number" min="5" step="5" required></div>
            </div>
            <div class="form-row"><div><label>Descripci&oacute;n</label><textarea name="description"></textarea></div></div>
            <div class="form-actions">
              <button type="button" class="btn btn-ghost hidden" id="btn-cancelar" onclick="cancelarEdicion()">Cancelar</button>
              <button type="submit" class="btn btn-primary">Guardar servicio</button>
            </div>
          </form>
        </div>
        <div class="card">
          <h2>Servicios actuales</h2>
          <div id="loading">Cargando...</div>
          <div class="table-wrap"><table id="tabla-servicios" class="hidden">
            <thead><tr><th>Nombre</th><th>Descripci&oacute;n</th><th>Duraci&oacute;n</th><th>Precio</th><th></th></tr></thead>
            <tbody id="tbody-servicios"></tbody>
          </table></div>
          <div id="empty" class="hidden" style="text-align:center;padding:32px;color:#999;">No hay servicios todav&iacute;a</div>
        </div>
      </div>

      <!-- HORARIOS -->
      <div id="tab-horarios" class="tab-content">
        <div class="card">
          <h2>Mis horarios</h2>
          <p style="color:#999;font-size:13px;margin-bottom:16px;">Configur&aacute; tu disponibilidad semanal. Los d&iacute;as desactivados no mostrar&aacute;n horarios.</p>
          <div id="wp-loading" style="text-align:center;padding:24px;color:#999;">Cargando horarios...</div>
          <form id="form-horarios" class="hidden">
            <div class="table-wrap"><table><thead><tr><th>D&iacute;a</th><th>Activo</th><th>Desde</th><th>Hasta</th><th>Descanso</th></tr></thead>
              <tbody id="wp-tbody"></tbody>
            </table></div>
            <div class="form-actions" style="margin-top:16px;">
              <button type="submit" class="btn btn-primary" id="btn-guardar-horarios">Guardar horarios</button>
            </div>
          </form>
        </div>
      </div>

      <!-- CALENDARIO -->
      <div id="tab-calendario" class="tab-content">
        <div class="card">
          <h2>Días no laborables</h2>
          <p style="color:#999;font-size:13px;margin-bottom:16px;">Bloqueá fechas específicas (feriados, vacaciones). No se pueden bloquear días que tengan turnos activos.</p>
          <div style="display:flex;gap:8px;margin-bottom:16px;flex-wrap:wrap;">
            <input type="date" id="doff-date" style="flex:1;min-width:160px;">
            <input type="text" id="doff-reason" placeholder="Motivo (ej: Feriado nacional)" style="flex:2;min-width:200px;">
            <button class="btn btn-primary" id="btn-add-doff">Agregar</button>
          </div>
          <div id="doff-error" style="color:#e74c3c;font-size:13px;margin-bottom:8px;display:none;"></div>
          <div id="doff-loading" style="text-align:center;padding:16px;color:#999;">Cargando...</div>
          <div class="table-wrap"><table id="tabla-doff" class="hidden">
            <thead><tr><th>Fecha</th><th>Motivo</th><th></th></tr></thead>
            <tbody id="tbody-doff"></tbody>
          </table></div>
          <div id="doff-empty" class="hidden" style="text-align:center;padding:16px;color:#999;">No hay días no laborables cargados</div>
        </div>
        <div class="card">
          <div class="flex items-center justify-between mb-4">
            <h3 id="calTitle" class="text-lg font-semibold text-[#555]">Mes</h3>
            <div class="flex gap-2">
              <button class="btn btn-ghost btn-sm" onclick="navegarCal(-1)">&larr;</button>
              <button class="btn btn-ghost btn-sm" onclick="irHoy()">Hoy</button>
              <button class="btn btn-ghost btn-sm" onclick="navegarCal(1)">&rarr;</button>
            </div>
          </div>
          <div id="calContainer" style="text-align:center;padding:32px;color:#999;">Cargando calendario...</div>
        </div>
        <div class="flex gap-4 flex-wrap items-center mb-4 text-xs text-[#999]">
          <span>Referencia:</span>
          <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-[#884e4f]"></span> Confirmado</span>
          <span class="flex items-center gap-1"><span class="inline-block w-3 h-3 rounded-sm bg-[#e8ddd6]"></span> Cancelado</span>
        </div>
      </div>

      <!-- TURNOS -->
      <div id="tab-turnos" class="tab-content">
        <div class="card">
          <h2>Gesti&oacute;n de turnos</h2>
          <div class="flex flex-col sm:flex-row gap-2 mb-4">
            <input type="text" id="searchTurno" placeholder="Buscar por cliente..." oninput="filtrarTurnos()" class="w-full sm:flex-1">
            <select id="filtroEstado" onchange="filtrarTurnos()" class="w-full sm:w-auto">
              <option value="">Todos los estados</option>
              <option value="confirmed">Confirmados</option>
              <option value="cancelled">Cancelados</option>
            </select>
          </div>
          <div id="turnosContainer"><div class="empty-state">Cargando turnos...</div></div>
        </div>
      </div>

      <!-- CLIENTES -->
      <div id="tab-clientes" class="tab-content">
        <div class="card">
          <h2>Clientes</h2>
          <div class="flex flex-col sm:flex-row gap-2 mb-4">
            <input type="text" id="clientSearch" placeholder="Buscar por nombre o tel&eacute;fono..." class="w-full sm:flex-1">
          </div>
          <div class="table-wrap"><table id="tabla-clientes"><thead><tr><th>Nombre</th><th>Tel&eacute;fono</th><th>Email</th><th>Turnos</th><th></th></tr></thead>
            <tbody id="tbody-clientes"><tr><td colspan="5" style="text-align:center;color:#999;padding:32px;">Cargando...</td></tr></tbody>
          </table></div>
        </div>
      </div>

      <!-- WHATSAPP -->
      <div id="tab-whatsapp" class="tab-content">
        <div class="card" style="text-align:center;max-width:480px;margin:0 auto;">
          <h2 style="margin-bottom:4px;">📱 Conexi&oacute;n WhatsApp</h2>
          <p style="color:#999;font-size:13px;margin-bottom:24px;">Escane&aacute; el c&oacute;digo QR con WhatsApp Business para recibir y responder mensajes autom&aacute;ticamente.</p>
          <div id="whatsapp-status"><div class="wa-loading">⏳ Conectando...</div></div>
          <div id="whatsapp-qr-container" style="display:none;">
            <img id="whatsapp-qr-img" src="" alt="QR WhatsApp" style="max-width:300px;width:100%;border-radius:12px;box-shadow:0 4px 20px rgba(0,0,0,.08);">
            <p style="color:#888;font-size:13px;margin-top:12px;">📱 Abr&iacute; WhatsApp en tu celu &rarr; Dispositivos vinculados &rarr; Vincular</p>
          </div>
          <div id="whatsapp-connected" style="display:none;">
            <div style="font-size:64px;margin-bottom:12px;">✅</div>
            <h3 style="color:#4caf50;">WhatsApp conectado</h3>
            <p style="color:#888;font-size:14px;">Los mensajes de confirmaci&oacute;n y recordatorios se enviar&aacute;n autom&aacute;ticamente.</p>
          </div>
        </div>
      </div>

      <!-- MARCA -->
      <div id="tab-marca" class="tab-content">
        <div class="card">
          <h2>Informaci&oacute;n de la marca</h2>
          <p class="desc">Estos datos se muestran en la landing page.</p>
          <form id="formMarca">
            <div class="space-y-4">
              <div><label>Nombre del sal&oacute;n</label><input type="text" name="name" value="<?=htmlspecialchars($brand['name'] ?? '')?>" required></div>
              <div><label>Eslogan / Tagline</label><input type="text" name="tagline" value="<?=htmlspecialchars($brand['tagline'] ?? '')?>"></div>
              <div><label>Direcci&oacute;n</label><input type="text" name="address" value="<?=htmlspecialchars($brand['address'] ?? '')?>"></div>
              <div><label>N&uacute;mero de WhatsApp (c&oacute;digo pa&iacute;s + n&uacute;mero, sin +)</label><input type="text" name="whatsapp" value="<?=htmlspecialchars($brand['whatsapp'] ?? '')?>"></div>
              <div><label>Usuario de Instagram (con @)</label><input type="text" name="instagram" value="<?=htmlspecialchars($brand['instagram'] ?? '')?>"></div>
              <div><label>Nombre del profesional</label><input type="text" name="profesional" value="<?=htmlspecialchars($brand['profesional'] ?? '')?>"></div>
            </div>
            <div class="form-actions" style="margin-top:16px;">
              <button type="button" class="btn btn-primary" onclick="guardarMarca()">Guardar cambios</button>
            </div>
          </form>
        </div>
      </div>

      <!-- LOGO -->
      <div id="tab-logo" class="tab-content">
        <div class="card">
          <h2>Logo del sal&oacute;n</h2>
          <p class="desc">Sub&iacute; el logo en formato PNG. Tama&ntilde;o m&aacute;ximo: 2MB. Se redimensiona autom&aacute;ticamente a 400px de ancho.</p>
          <div style="margin-bottom:16px;">
            <?php if ($logoUrl): ?>
              <img src="<?=$logoUrl?>" alt="Logo actual" class="logo-preview" id="logoPreview">
            <?php else: ?>
              <div class="w-[200px] h-[60px] bg-[#f0ebe7] rounded-lg flex items-center justify-center text-[#bbb] text-sm mb-3" id="logoPlaceholder">Sin logo</div>
              <img src="" alt="Logo" class="logo-preview" id="logoPreview" style="display:none;">
            <?php endif; ?>
          </div>
          <div class="flex gap-2">
            <label class="btn btn-primary" style="cursor:pointer;"><?=$logoUrl ? 'Cambiar logo' : 'Subir logo'?><input type="file" accept="image/png" id="logoInput" hidden></label>
            <?php if ($logoUrl): ?>
              <button class="btn btn-danger" id="btnDeleteLogo">Eliminar logo</button>
            <?php endif; ?>
          </div>
        </div>
      </div>

      <!-- COLORES -->
      <div id="tab-colores" class="tab-content">
        <div class="card">
          <h2>Paleta de colores</h2>
          <p class="desc">Personaliz&aacute; los colores de la landing page.</p>
          <form id="formColores">
            <div class="color-grid">
              <?php
              $colorFields = [
                'primary' => 'Principal (botones, links)',
                'secondary' => 'Secundario (fondos suaves)',
                'accent' => 'Acento (degradados, detalles)',
                'text' => 'Texto principal',
                'background' => 'Fondo de p&aacute;gina',
              ];
              foreach ($colorFields as $key => $label):
                $val = htmlspecialchars($colors[$key] ?? '#000000');
              ?>
              <div class="color-item">
                <label><?=$label?></label>
                <input type="color" name="<?=$key?>" value="<?=$val?>">
              </div>
              <?php endforeach; ?>
            </div>
            <div class="form-actions" style="margin-top:16px;">
              <button type="submit" class="btn btn-primary">Guardar colores</button>
            </div>
          </form>
          <div class="preview-card" id="colorPreview" style="margin-top:20px;">
            <div class="preview-header" style="background:<?=htmlspecialchars($colors['primary']??'#E8A0A0')?>;"><?=htmlspecialchars($brand['name'] ?? 'Sal&oacute;n')?></div>
            <div class="preview-hero" style="background:<?=htmlspecialchars($colors['secondary']??'#F5F0F0')?>;color:<?=htmlspecialchars($colors['text']??'#2D2D2D')?>;">
              <h3><?=htmlspecialchars($brand['tagline'] ?: 'Tu eslogan ac&aacute;')?></h3>
              <p>Vista previa del hero con los colores seleccionados</p>
              <span class="preview-btn" style="background:<?=htmlspecialchars($colors['primary']??'#E8A0A0')?>;">Reservar ahora</span>
            </div>
          </div>
        </div>
      </div>

      <!-- GALER&Iacute;A -->
      <div id="tab-galeria" class="tab-content">
        <div class="card">
          <h2>Galer&iacute;a de trabajos</h2>
          <p class="desc">Sub&iacute; fotos de tus trabajos. JPG o PNG, m&aacute;ximo 5MB cada una. Hasta 10 im&aacute;genes.</p>
          <div class="gallery-grid-pf" id="galleryGrid">
            <?php if (empty($gallery)): ?>
              <div class="gallery-empty" id="galleryEmpty">No hay im&aacute;genes todav&iacute;a</div>
            <?php else: ?>
              <?php foreach ($gallery as $img): ?>
                <?php $fname = htmlspecialchars($img['filename'] ?? ''); ?>
                <?php if ($fname): ?>
                <div class="gallery-thumb" data-filename="<?=$fname?>">
                  <img src="<?=htmlspecialchars($schedulerBase, ENT_QUOTES)?>/uploads/gallery/<?=$fname?>" alt="" loading="lazy">
                  <button class="delete-btn" title="Eliminar">&times;</button>
                </div>
                <?php endif; ?>
              <?php endforeach; ?>
            <?php endif; ?>
          </div>
          <div style="margin-top:16px;">
            <label class="upload-zone" id="galleryUploadZone">
              <div>📁 Hac&eacute; clic o arrastr&aacute; una imagen</div>
              <div style="font-size:12px;color:#999;margin-top:4px;">JPG o PNG &middot; M&aacute;x 5MB &middot; <?=count($gallery)?>/10</div>
              <input type="file" accept="image/jpeg,image/png" id="galleryInput" hidden multiple>
            </label>
          </div>
        </div>
      </div>

      <!-- PROFESIONALES -->
      <div id="tab-profesionales" class="tab-content">
        <div class="card">
          <h2>Gestión de profesionales</h2>
          <p class="desc">Administrá los profesionales que se muestran en la sección "La profesional" de la landing page.</p>

          <form id="formProfesional" style="margin-bottom:24px;padding:16px;background:#f9f7f6;border-radius:12px;border:1px solid #e4e2e1;">
            <h3 style="font-size:15px;font-weight:600;color:#444;margin-bottom:12px;" id="profFormTitle">Agregar profesional</h3>
            <input type="hidden" id="profEditIdx" value="-1">
            <div class="space-y-3">
              <div><label>Nombre</label><input type="text" id="profName" required placeholder="Ej: Cecilia Natali Godoy"></div>
              <div><label>Título / Especialidad</label><input type="text" id="profTitle" placeholder="Ej: Manicurista profesional"></div>
              <div><label>Biografía</label><textarea id="profBio" rows="3" placeholder="Contá quién es, su experiencia, pasión..."></textarea></div>
              <div><label>Destacados (uno por línea)</label><textarea id="profHighlights" rows="3" placeholder="Esmaltes hipoalergénicos&#10;Técnicas de última generación&#10;Tendencias actuales"></textarea></div>
              <div>
                <label>Foto</label>
                <div style="display:flex;gap:12px;align-items:center;flex-wrap:wrap;">
                  <img id="profPhotoPreview" style="width:80px;height:80px;border-radius:50%;object-fit:cover;display:none;border:2px solid #e4e2e1;">
                  <label class="btn btn-ghost" style="cursor:pointer;">Seleccionar foto<input type="file" accept="image/jpeg,image/png" id="profPhotoInput" hidden></label>
                  <button type="button" class="btn btn-ghost btn-sm" id="btnProfPhotoClear" style="display:none;">Quitar foto</button>
                </div>
              </div>
            </div>
            <div class="form-actions" style="margin-top:12px;">
              <button type="submit" class="btn btn-primary" id="btnGuardarProf">Guardar profesional</button>
              <button type="button" class="btn btn-ghost hidden" id="btnCancelarProf" onclick="cancelarEdicionProf()">Cancelar</button>
            </div>
          </form>

          <div id="profLoading" style="text-align:center;padding:24px;color:#999;">Cargando profesionales...</div>
          <div id="profEmpty" class="hidden" style="text-align:center;padding:32px;color:#999;">No hay profesionales cargados. Agregá el primero arriba.</div>
          <div id="profList"></div>
        </div>
      </div>
    </div>
  </main>
</div>

<!-- Modal -->
<div class="modal-overlay" id="modalOverlay">
  <div class="modal">
    <button class="modal-close" onclick="cerrarModal()">✕</button>
    <div id="modalBody"></div>
  </div>
</div>

<div id="toast" class="toast"></div>

<script>
const CSRF_TOKEN = '<?= $_SESSION['csrf_token'] ?? '' ?>';
const SCHEDULER_BASE = '<?= htmlspecialchars($schedulerBase, ENT_QUOTES) ?>';
const API = '../api/admin-servicios.php';
const WP_API = '../api/horarios-admin.php';
const TURNOS_API = '../api/turnos-admin.php';
const CLIENTES_API = '../api/customers-admin.php';

const DAYS = [
  { key:'monday', label:'Lunes' }, { key:'tuesday', label:'Martes' },
  { key:'wednesday', label:'Mi\u00e9rcoles' }, { key:'thursday', label:'Jueves' },
  { key:'friday', label:'Viernes' }, { key:'saturday', label:'S\u00e1bado' },
  { key:'sunday', label:'Domingo' },
];
const MONTHS = ['Enero','Febrero','Marzo','Abril','Mayo','Junio','Julio','Agosto','Septiembre','Octubre','Noviembre','Diciembre'];
const DAY_LABELS = ['Lun','Mar','Mi\u00e9','Jue','Vie','S\u00e1b','Dom'];

let allAppointments = [];
let allServices = [];
let calYear, calMonth;
let selectedAppt = null;
let editingService = null;
let selectedSlot = null;
let waPollTimer = null;
let serviciosLoaded = false;

function toggleSidebar() { document.getElementById('sidebar').classList.toggle('-translate-x-full'); document.body.classList.toggle('sidebar-open'); }

document.querySelectorAll('.sidebar-link').forEach(function(link) {
  link.addEventListener('click', function(e) {
    e.preventDefault();
    document.querySelectorAll('.sidebar-link').forEach(function(l) { l.classList.remove('active'); });
    document.querySelectorAll('.tab-content').forEach(function(c) { c.classList.remove('active'); });
    this.classList.add('active');
    var tab = this.dataset.tab;
    var panel = document.getElementById('tab-' + tab);
    if (panel) panel.classList.add('active');
    var headerTitle = document.getElementById('header-title');
    if (headerTitle) headerTitle.textContent = 'TeToca \u00B7 ' + (this.dataset.title || this.textContent.trim());
    if (window.innerWidth < 768) toggleSidebar();
    if (tab === 'dashboard') cargarDashboard();
    if (tab === 'servicios' && !serviciosLoaded) { serviciosLoaded = true; cargarServicios(); }
    if (tab === 'horarios') { cargarHorarios(); cargarDiasOff(); }
    if (tab === 'calendario') renderCalendario();
    if (tab === 'turnos') renderTurnos();
    if (tab === 'clientes') cargarClientes();
    if (tab === 'profesionales') cargarProfesionales();
    if (tab === 'whatsapp') { cargarWhatsApp(); if (!waPollTimer) waPollTimer = setInterval(cargarWhatsApp, 5000); }
    else if (waPollTimer) { clearInterval(waPollTimer); waPollTimer = null; }
  });
});

function mostrarToast(msg) { var t = document.getElementById('toast'); t.textContent = msg; t.classList.add('show'); setTimeout(function() { t.classList.remove('show'); }, 2500); }
function esc(s) { return String(s||'').replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;').replace(/"/g,'&quot;').replace(/'/g,'&#39;'); }
function abrirModal(html) { document.getElementById('modalBody').innerHTML = html; document.getElementById('modalOverlay').classList.add('show'); }
function cerrarModal() { document.getElementById('modalOverlay').classList.remove('show'); }
document.getElementById('modalOverlay').addEventListener('click', function(e) { if (e.target === e.currentTarget) cerrarModal(); });

function clearDashCache() { try { sessionStorage.removeItem('dashCache'); } catch(e) {} }

const PAGOS_API = '../api/pagos-admin.php';

async function cargarDashboard() {
  try {
    var cached;
    try { cached = JSON.parse(sessionStorage.getItem('dashCache')); } catch(e) {}
    if (cached && Date.now() - cached.ts < 30000) { renderDashboard(cached.data, cached.stats); return; }
    var month = new Date().getFullYear() + '-' + String(new Date().getMonth()+1).padStart(2,'0');
    var [apptsRes, statsRes] = await Promise.all([
      fetch(TURNOS_API + '?month=' + month),
      fetch(PAGOS_API + '?month=' + month),
    ]);
    var appts = await apptsRes.json();
    var stats = await statsRes.json();
    try { sessionStorage.setItem('dashCache', JSON.stringify({ts:Date.now(), data:appts, stats:stats})); } catch(e) {}
    renderDashboard(appts, stats);
  } catch(e) { document.getElementById('proximos-container').innerHTML = '<div class="empty-state">Error al cargar datos</div>'; }
}

function renderDashboard(appts, stats) {
  try {
    var now = new Date(); var today = now.toISOString().slice(0,10);
    document.getElementById('statHoy').textContent = appts.filter(function(a) { return a.start.slice(0,10) === today; }).length;
    var weekStart = new Date(now); weekStart.setDate(now.getDate() - now.getDay() + 1);
    var weekEnd = new Date(weekStart); weekEnd.setDate(weekStart.getDate() + 6);
    document.getElementById('statSemana').textContent = appts.filter(function(a) { var d = a.start.slice(0,10); return d >= weekStart.toISOString().slice(0,10) && d <= weekEnd.toISOString().slice(0,10); }).length;
    var monthAppts = appts.filter(function(a) { return a.start.slice(0,7) === today.slice(0,7); });
    document.getElementById('statMes').textContent = monthAppts.length;
    if (stats) {
      document.getElementById('statCobrado').textContent = '$' + Number(stats.cobrado || 0).toLocaleString('es-AR');
      document.getElementById('statPendiente').textContent = '$' + Number(stats.pendiente || 0).toLocaleString('es-AR');
    } else {
      document.getElementById('statCobrado').textContent = '$0';
      document.getElementById('statPendiente').textContent = '$0';
    }
    var proximos = appts.filter(function(a) { var d = a.start.slice(0,10); return d >= today && d <= new Date(now.getTime()+7*86400000).toISOString().slice(0,10) && a.status !== 'cancelled'; });
    proximos.sort(function(a,b) { return a.start.localeCompare(b.start); });
    var container = document.getElementById('proximos-container');
    if (!proximos.length) { container.innerHTML = '<div class="empty-state">No hay turnos pr\u00f3ximos</div>'; return; }
    var html = '<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Hora</th><th>Cliente</th><th>Servicio</th><th>Precio</th><th>Pago</th></tr></thead><tbody>';
    proximos.slice(0,10).forEach(function(a) {
      var pagado = a.payment && a.payment.status === 'paid';
      var pagoHtml = pagado ? '<span style="color:#2e7d32;font-weight:600;">\u2705 Pagado</span>' : '<span style="color:#e65100;">\u23f3 Pendiente</span>';
      html += '<tr><td>' + a.start.slice(0,10).split('-').reverse().join('/') + '</td><td class="whitespace-nowrap"><strong>' + a.start.slice(11,16) + '</strong></td><td>' + esc((a.customer? a.customer.firstName:'') + ' ' + (a.customer? a.customer.lastName:'')) + '</td><td style="color:#888;font-size:13px;" class="whitespace-nowrap">' + esc(a.service? a.service.name:'') + '</td><td class="precio whitespace-nowrap">' + (a.service ? '$' + Number(a.service.price).toLocaleString('es-AR') : '') + '</td><td>' + pagoHtml + '</td></tr>';
    });
    container.innerHTML = html + '</tbody></table></div>';
  } catch(e) {}
}

async function cargarServicios() {
  try {
    var res = await fetch(API); var data = await res.json();
    document.getElementById('loading').classList.add('hidden');
    var tbody = document.getElementById('tbody-servicios'); tbody.innerHTML = '';
    if (!Array.isArray(data) || data.length === 0) { document.getElementById('empty').classList.remove('hidden'); document.getElementById('tabla-servicios').classList.add('hidden'); return; }
    document.getElementById('empty').classList.add('hidden'); document.getElementById('tabla-servicios').classList.remove('hidden');
    allServices = data;
    data.forEach(function(s) {
      var tr = document.createElement('tr');
      tr.innerHTML = '<td><strong>' + esc(s.name) + '</strong></td><td style="color:#888;font-size:13px;">' + esc(s.description||'-') + '</td><td class="duracion">' + s.duration + ' min</td><td class="precio">$' + Number(s.price).toLocaleString('es-AR') + '</td><td class="actions"><button class="btn btn-ghost btn-sm" onclick="editarServicio(' + s.id + ')">Editar</button> <button class="btn btn-danger btn-sm" onclick="eliminarServicio(' + s.id + ')">Eliminar</button></td>';
      tbody.appendChild(tr);
    });
  } catch(e) {}
}

document.getElementById('form-servicio').addEventListener('submit', async function(e) {
  e.preventDefault();
  var fd = new FormData(e.target); var data = Object.fromEntries(fd);
  data.price = parseFloat(data.price); data.duration = parseInt(data.duration);
  var url = API; var method = 'POST';
  if (editingService) { url += '?id=' + editingService; method = 'PUT'; }
  data.csrf_token = CSRF_TOKEN;
  var res = await fetch(url, { method: method, headers:{'Content-Type':'application/json'}, body:JSON.stringify(data) });
  if (!res.ok) { var err = await res.json(); mostrarToast('Error: ' + (err.error||'desconocido')); return; }
  mostrarToast(editingService ? 'Servicio actualizado' : 'Servicio creado');
  cancelarEdicion(); cargarServicios();
});

async function editarServicio(id) {
  var res = await fetch(API); var servicios = await res.json(); var s = servicios.find(function(x) { return x.id === id; });
  if (!s) return;
  editingService = id;
  var form = document.getElementById('form-servicio');
  form.querySelector('[name="name"]').value = s.name; form.querySelector('[name="price"]').value = s.price;
  form.querySelector('[name="duration"]').value = s.duration; form.querySelector('[name="description"]').value = s.description||'';
  form.querySelector('[type="submit"]').textContent = 'Actualizar servicio';
  document.getElementById('btn-cancelar').classList.remove('hidden');
  form.scrollIntoView({behavior:'smooth'});
}

function cancelarEdicion() {
  editingService = null; document.getElementById('form-servicio').reset();
  document.getElementById('form-servicio').querySelector('[type="submit"]').textContent = 'Guardar servicio';
  document.getElementById('btn-cancelar').classList.add('hidden');
}

async function eliminarServicio(id) {
  if (!confirm('\u00bfEliminar este servicio?')) return;
  var res = await fetch(API+'?id='+id, {method:'DELETE'});
  if (!res.ok) { var err = await res.json(); mostrarToast('Error: '+(err.error||'desconocido')); return; }
  mostrarToast('Servicio eliminado'); cargarServicios();
}

async function cargarHorarios() {
  var res = await fetch(WP_API); var data = await res.json();
  document.getElementById('wp-loading').classList.add('hidden'); document.getElementById('form-horarios').classList.remove('hidden');
  var wp = data.workingPlan || {}; var tbody = document.getElementById('wp-tbody'); tbody.innerHTML = '';
  DAYS.forEach(function(d) {
    var day = wp[d.key]; var active = day && day.start && day.end;
    var tr = document.createElement('tr');
    tr.innerHTML = '<td><strong>' + d.label + '</strong></td><td><input type="checkbox" class="day-active" data-day="' + d.key + '" ' + (active?'checked':'') + '></td>' +
      '<td><input type="time" class="day-start" data-day="' + d.key + '" value="' + (active?day.start:'09:00') + '" ' + (active?'':'disabled') + '></td>' +
      '<td><input type="time" class="day-end" data-day="' + d.key + '" value="' + (active?day.end:'18:00') + '" ' + (active?'':'disabled') + '></td>' +
      '<td><div class="breaks-container" data-day="' + d.key + '">' +
      (active&&day.breaks?day.breaks.map(function(b){return '<div class="break-row"><input type="time" class="break-start" value="'+b.start+'"><input type="time" class="break-end" value="'+b.end+'"><button type="button" class="btn btn-ghost btn-xs" onclick="this.closest(\'.break-row\').remove()">\u2715</button></div>';}).join(''):'') +
      '<button type="button" class="btn btn-ghost btn-xs" style="margin-top:4px;" onclick="agregarDescanso(\'' + d.key + '\')">+ Descanso</button></div></td>';
    tbody.appendChild(tr);
  });
  document.querySelectorAll('.day-active').forEach(function(cb) {
    cb.addEventListener('change', function() {
      var day = this.dataset.day; var row = this.closest('tr');
      row.querySelector('.day-start').disabled = !this.checked;
      row.querySelector('.day-end').disabled = !this.checked;
      row.querySelectorAll('.breaks-container input').forEach(function(i) { i.disabled = !cb.checked; });
    });
  });
}

function agregarDescanso(day) {
  var container = document.querySelector('.breaks-container[data-day="' + day + '"]');
  var div = document.createElement('div'); div.className = 'break-row';
  div.innerHTML = '<input type="time" class="break-start" value="13:00"><input type="time" class="break-end" value="14:00"><button type="button" class="btn btn-ghost btn-xs" onclick="this.closest(\'.break-row\').remove()">\u2715</button>';
  container.insertBefore(div, container.lastElementChild);
}

document.getElementById('form-horarios').addEventListener('submit', async function(e) {
  e.preventDefault();
  var btn = document.getElementById('btn-guardar-horarios'); btn.textContent = 'Guardando...'; btn.disabled = true;
  var workingPlan = {};
  DAYS.forEach(function(d) {
    var active = document.querySelector('.day-active[data-day="' + d.key + '"]').checked;
    if (!active) { workingPlan[d.key] = null; return; }
    var start = document.querySelector('.day-start[data-day="' + d.key + '"]').value;
    var end = document.querySelector('.day-end[data-day="' + d.key + '"]').value;
    var breaks = [];
    document.querySelector('.breaks-container[data-day="' + d.key + '"]').querySelectorAll('.break-row').forEach(function(row) {
      var bs = row.querySelector('.break-start').value; var be = row.querySelector('.break-end').value;
      if (bs && be) breaks.push({start:bs, end:be});
    });
    workingPlan[d.key] = {start: start, end: end, breaks: breaks};
  });
  try {
    var res = await fetch(WP_API, {method:'PUT', headers:{'Content-Type':'application/json'}, body:JSON.stringify({workingPlan:workingPlan, csrf_token:CSRF_TOKEN})});
    var data = await res.json();
    if (!data.success) { mostrarToast('Error: '+(data.error||'')); return; }
    mostrarToast('Horarios guardados');
  } catch(e) { mostrarToast('Error de conexi\u00f3n'); }
  finally { btn.textContent = 'Guardar horarios'; btn.disabled = false; }
});

const DOFF_API = '../api/horarios-admin.php?action=days_off';

async function cargarDiasOff() {
  var res = await fetch(DOFF_API); var data = await res.json();
  document.getElementById('doff-loading').classList.add('hidden');
  if (!data.length) { document.getElementById('doff-empty').classList.remove('hidden'); document.getElementById('tabla-doff').classList.add('hidden'); return; }
  document.getElementById('tabla-doff').classList.remove('hidden'); document.getElementById('doff-empty').classList.add('hidden');
  var tbody = document.getElementById('tbody-doff'); tbody.innerHTML = '';
  data.forEach(function(d) {
    var tr = document.createElement('tr');
    tr.innerHTML = '<td>' + d.date + '</td><td>' + (d.reason||'') + '</td><td><button class="btn btn-ghost btn-xs" onclick="eliminarDiaOff(\'' + d.date + '\')">\u2715</button></td>';
    tbody.appendChild(tr);
  });
}

async function eliminarDiaOff(date) {
  if (!confirm('\u00bfDesbloquear el ' + date + '?')) return;
  var res = await fetch(DOFF_API + '&date=' + date, {method:'DELETE'});
  var data = await res.json();
  if (!data.success) { mostrarToast('Error: '+(data.error||'')); return; }
  mostrarToast('D\u00eda desbloqueado'); cargarDiasOff();
}

document.getElementById('btn-add-doff').addEventListener('click', async function() {
  var date = document.getElementById('doff-date').value;
  var reason = document.getElementById('doff-reason').value.trim();
  if (!date) { mostrarToast('Seleccion\u00e1 una fecha'); return; }
  var errDiv = document.getElementById('doff-error');
  errDiv.style.display = 'none';
  var btn = this; btn.textContent = 'Guardando...'; btn.disabled = true;
  try {
    var res = await fetch(DOFF_API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({date:date, reason:reason, csrf_token:CSRF_TOKEN})});
    var data = await res.json();
    if (!data.success) { if (data.error) { errDiv.textContent = data.error; errDiv.style.display = 'block'; } else { mostrarToast('Error'); } return; }
    mostrarToast('D\u00eda bloqueado'); document.getElementById('doff-date').value = ''; document.getElementById('doff-reason').value = '';
    cargarDiasOff();
  } catch(e) { mostrarToast('Error de conexi\u00f3n'); }
  finally { btn.textContent = 'Agregar'; btn.disabled = false; }
});

function irHoy() { var n = new Date(); calYear = n.getFullYear(); calMonth = n.getMonth(); renderCalendario(); }
function navegarCal(dir) { calMonth += dir; if (calMonth > 11) { calMonth = 0; calYear++; } if (calMonth < 0) { calMonth = 11; calYear--; } renderCalendario(); }

async function renderCalendario() {
  if (calYear === undefined) { var n = new Date(); calYear = n.getFullYear(); calMonth = n.getMonth(); }
  var monthStr = calYear + '-' + String(calMonth+1).padStart(2,'0');
  document.getElementById('calTitle').textContent = MONTHS[calMonth] + ' ' + calYear;
  document.getElementById('calContainer').innerHTML = 'Cargando...';
  var res = await fetch(TURNOS_API + '?month=' + monthStr);
  var appts = await res.json();
  allAppointments = appts;
  var firstDay = new Date(calYear, calMonth, 1);
  var lastDay = new Date(calYear, calMonth + 1, 0);
  var startDow = firstDay.getDay();
  var startOffset = startDow === 0 ? 6 : startDow - 1;
  var dayMap = {};
  appts.forEach(function(a) { var d = a.start.slice(0,10); if (!dayMap[d]) dayMap[d] = []; dayMap[d].push(a); });
  var todayStr = new Date().toISOString().slice(0,10);
  var html = '<div class="cal-grid">';
  DAY_LABELS.forEach(function(l) { html += '<div class="cal-weekday">' + l + '</div>'; });
  var prevLastDay = new Date(calYear, calMonth, 0).getDate();
  for (var i = startOffset - 1; i >= 0; i--) { html += '<div class="cal-cell other-month"><div class="day-num">' + (prevLastDay - i) + '</div></div>'; }
  for (var d = 1; d <= lastDay.getDate(); d++) {
    var dateStr = calYear + '-' + String(calMonth+1).padStart(2,'0') + '-' + String(d).padStart(2,'0');
    var isToday = dateStr === todayStr;
    html += '<div class="' + (isToday ? 'cal-cell today' : 'cal-cell') + '"><div class="day-num">' + d + '</div>';
    if (dayMap[dateStr]) { dayMap[dateStr].forEach(function(a) { html += '<div class="cal-appt ' + (a.status||'confirmed') + '" onclick="event.stopPropagation();abrirModalTurno(' + a.id + ')"><span class="cal-time">' + a.start.slice(11,16) + '</span> ' + esc(a.customer? a.customer.firstName:'?') + '</div>'; }); }
    html += '</div>';
  }
  var remaining = (7 - ((startOffset + lastDay.getDate()) % 7)) % 7;
  for (var d = 1; d <= remaining; d++) { html += '<div class="cal-cell other-month"><div class="day-num">' + d + '</div></div>'; }
  html += '</div>';
  document.getElementById('calContainer').innerHTML = html;
}

async function abrirModalTurno(id) {
  var appt = allAppointments.find(function(a) { return a.id === id; });
  if (!appt) return;
  selectedAppt = appt;
  var c = appt.customer || {}; var s = appt.service || {};
  var status = appt.status || 'confirmed';
  var pm = appt.payment || {};
  var estaPagado = pm.status === 'paid';
  var metodoPago = pm.method || '';
  var pagoLabel = estaPagado ? 'Pagado' : 'Pendiente';
  var pagoColor = estaPagado ? '#2e7d32' : '#e65100';
  var html = '<h2>Detalle del turno</h2>' +
    '<div class="detail-row"><span class="icon">\ud83d\udc64</span><span class="val"><strong>' + esc(c.firstName) + ' ' + esc(c.lastName) + '</strong></span></div>' +
    '<div class="detail-row"><span class="icon">\ud83d\udcde</span><span class="val">' + esc(c.phone||'\u2014') + '</span></div>' +
    '<div class="detail-row"><span class="icon">\u2709\ufe0f</span><span class="val">' + esc(c.email||'\u2014') + '</span></div>' +
    '<div style="height:1px;background:#f0ebe7;margin:12px 0;"></div>' +
    '<div class="detail-row"><span class="icon">\ud83d\udc85</span><span class="val">' + esc(s.name||'\u2014') + '</span></div>' +
    '<div class="detail-row"><span class="icon">\u23f1\ufe0f</span><span class="val">' + appt.start.slice(11,16) + ' - ' + appt.end.slice(11,16) + ' (' + (s.duration||'?') + ' min)</span></div>' +
    '<div class="detail-row"><span class="icon">\ud83d\udcb0</span><span class="val">' + (s.price ? '$' + Number(s.price).toLocaleString('es-AR') : '\u2014') + '</span></div>' +
    '<div class="detail-row"><span class="icon">\ud83d\udcc5</span><span class="val" style="text-transform:capitalize;">' + new Date(appt.start).toLocaleDateString('es-AR', { weekday:'long', day:'numeric', month:'long', year:'numeric' }) + '</span></div>' +
    '<div class="detail-row"><span class="icon">\ud83c\udff7\ufe0f</span><span class="val"><span class="status-badge ' + status + '">' + (status === 'confirmed' ? 'Confirmado' : 'Cancelado') + '</span></span></div>' +
    '<div class="detail-row"><span class="icon">\ud83d\udcb8</span><span class="val"><span style="color:' + pagoColor + ';font-weight:600;">' + pagoLabel + '</span>' + (estaPagado && metodoPago ? ' <span style="color:#888;font-size:12px;">(' + esc(metodoPago) + ')</span>' : '') + '</span></div>';
  if (status !== 'cancelled') {
    html += '<div class="modal-actions">';
    if (!estaPagado) {
      html += '<button class="btn btn-primary" onclick="registrarPago(' + id + ',' + (s.price || 0) + ')" style="background:#2e7d32;">\u2705 Marcar pagado</button>';
    } else {
      html += '<button class="btn btn-ghost" onclick="anularPago(' + id + ')">\u21a9 Anular pago</button>';
    }
    html += '<button class="btn btn-primary" onclick="mostrarReagendar()">Reagendar</button><button class="btn btn-danger" onclick="cancelarTurno(' + id + ')">Cancelar turno</button></div>';
  } else { html += '<div class="modal-actions"><button class="btn btn-ghost" onclick="cerrarModal()">Cerrar</button></div>'; }
  html += '<div id="rescheduleSection" class="reschedule-section hidden"><h3>Reagendar turno</h3><label>Nueva fecha</label><input type="date" id="reschedDate" min="' + new Date().toISOString().slice(0,10) + '" onchange="cargarSlotsReagendar(' + id + ')"><div id="reschedSlots" style="margin-top:8px;"></div><div class="form-actions" style="margin-top:12px;"><button class="btn btn-ghost" onclick="cerrarReagendar()">Cancelar</button><button class="btn btn-primary" id="btnConfirmarResched" onclick="confirmarReagendar(' + id + ')" disabled>Confirmar reagendamiento</button></div></div>';
  abrirModal(html);
}

async function registrarPago(id, amount) {
  if (!confirm('\u00bfConfirm\u00e1s que este turno fue pagado?')) return;
  var metodo = prompt('M\u00e9todo de pago: efectivo, transferencia, mercadopago, otro', 'efectivo');
  if (!metodo) return;
  var btn = document.querySelector('.modal-actions .btn-primary');
  if (btn) btn.disabled = true;
  try {
    var res = await fetch(PAGOS_API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({appointmentId:id, amount:amount, method:metodo, status:'paid'})});
    var data = await res.json();
    if (!data.success) { mostrarToast('Error: ' + (data.error || '')); return; }
    mostrarToast('\u2705 Pago registrado');
    clearDashCache(); cerrarModal();
    var activeTab = document.querySelector('.sidebar-link.active');
    if (activeTab) { if (activeTab.dataset.tab === 'turnos') renderTurnos(); if (activeTab.dataset.tab === 'dashboard') cargarDashboard(); }
  } catch(e) { mostrarToast('Error de conexi\u00f3n'); }
}

async function anularPago(id) {
  if (!confirm('\u00bfAnular el pago de este turno?')) return;
  try {
    var res = await fetch(PAGOS_API, {method:'POST', headers:{'Content-Type':'application/json'}, body:JSON.stringify({appointmentId:id, amount:0, method:'', status:'pending'})});
    var data = await res.json();
    if (!data.success) { mostrarToast('Error: ' + (data.error || '')); return; }
    mostrarToast('Pago anulado');
    clearDashCache(); cerrarModal();
    var activeTab = document.querySelector('.sidebar-link.active');
    if (activeTab) { if (activeTab.dataset.tab === 'turnos') renderTurnos(); if (activeTab.dataset.tab === 'dashboard') cargarDashboard(); }
  } catch(e) { mostrarToast('Error de conexi\u00f3n'); }
}

function cerrarReagendar() { var sec = document.getElementById('rescheduleSection'); if (sec) sec.classList.add('hidden'); var slots = document.getElementById('reschedSlots'); if (slots) slots.innerHTML = ''; }
function mostrarReagendar() { var sec = document.getElementById('rescheduleSection'); if (sec) sec.classList.remove('hidden'); var tomorrow = new Date(); tomorrow.setDate(tomorrow.getDate() + 1); var inp = document.getElementById('reschedDate'); if (inp) inp.min = tomorrow.toISOString().slice(0,10); }

async function cargarSlotsReagendar(id) {
  var date = document.getElementById('reschedDate').value;
  if (!date) return;
  var appt = selectedAppt;
  if (!appt || !appt.service) { document.getElementById('reschedSlots').innerHTML = '<div style="color:#999;">Servicio no disponible</div>'; return; }
  var res = await fetch('../api/slots.php?serviceId=' + appt.service.id + '&date=' + date);
  var data = await res.json();
  var container = document.getElementById('reschedSlots');
  document.getElementById('btnConfirmarResched').disabled = true;
  if (data.dayOff || !data.slots || data.slots.length === 0) { container.innerHTML = '<div style="color:#999;">No hay horarios disponibles</div>'; return; }
  var html = '<label style="margin-top:8px;">Horario disponible</label><div class="slot-options">';
  data.slots.forEach(function(s) { html += '<button type="button" class="slot-btn" data-slot="' + s + '" onclick="seleccionarSlot(this,\'' + s + '\')">' + s + '</button>'; });
  container.innerHTML = html + '</div>';
}

function seleccionarSlot(el, slot) { document.querySelectorAll('.slot-btn').forEach(function(b) { b.classList.remove('selected'); }); el.classList.add('selected'); selectedSlot = slot; document.getElementById('btnConfirmarResched').disabled = false; }

async function confirmarReagendar(id) {
  var date = document.getElementById('reschedDate').value, slot = selectedSlot;
  if (!date || !slot || !selectedAppt || !selectedAppt.service) return;
  var dur = selectedAppt.service.duration;
  var end = new Date(new Date(date + 'T' + slot + ':00').getTime() + dur * 60000).toISOString().slice(0,19).replace('T',' ');
  var res = await fetch(TURNOS_API + '?id=' + id, { method:'PUT', headers:{'Content-Type':'application/json'}, body:JSON.stringify({start: date + ' ' + slot + ':00', end: end, csrf_token:CSRF_TOKEN}) });
  if (!res.ok) { var err = await res.json(); mostrarToast('Error: '+(err.error||'desconocido')); return; }
  mostrarToast('Turno reagendado con \u00e9xito'); cerrarModal(); renderCalendario();
  clearDashCache();
  var activeTab = document.querySelector('.sidebar-link.active');
  if (activeTab) { if (activeTab.dataset.tab === 'turnos') renderTurnos(); if (activeTab.dataset.tab === 'dashboard') cargarDashboard(); }
}

async function cancelarTurno(id) {
  if (!confirm('\u00bfEst\u00e1s segura de cancelar este turno?')) return;
  var res = await fetch(TURNOS_API + '?id=' + id, {method:'DELETE'});
  if (!res.ok) { var err = await res.json(); mostrarToast('Error: '+(err.error||'desconocido')); return; }
  mostrarToast('Turno cancelado'); cerrarModal(); renderCalendario();
  clearDashCache();
  var activeTab = document.querySelector('.sidebar-link.active');
  if (activeTab) { if (activeTab.dataset.tab === 'turnos') renderTurnos(); if (activeTab.dataset.tab === 'dashboard') cargarDashboard(); }
}

async function renderTurnos() {
  var container = document.getElementById('turnosContainer');
  container.innerHTML = '<div class="empty-state">Cargando turnos...</div>';
  var res = await fetch(TURNOS_API);
  allAppointments = await res.json();
  filtrarTurnos();
}

function filtrarTurnos() {
  var q = document.getElementById('searchTurno').value.toLowerCase();
  var estado = document.getElementById('filtroEstado').value;
  var list = allAppointments;
  if (q) list = list.filter(function(a) { var c = a.customer || {}; return (c.firstName+' '+c.lastName).toLowerCase().indexOf(q) >= 0 || (c.phone||'').indexOf(q) >= 0; });
  if (estado) list = list.filter(function(a) { return a.status === estado; });
  list.sort(function(a,b) { return a.start.localeCompare(b.start); });
  var container = document.getElementById('turnosContainer');
  if (!list.length) { container.innerHTML = '<div class="empty-state"><div class="icon">\ud83d\udccb</div>No se encontraron turnos</div>'; return; }
  var html = '<div class="table-wrap"><table><thead><tr><th>Fecha</th><th>Hora</th><th>Cliente</th><th>Servicio</th><th>Precio</th><th>Pago</th><th>Estado</th><th></th></tr></thead><tbody>';
  list.forEach(function(a) {
    var c = a.customer || {}; var s = a.service || {};
    var est = a.status || 'confirmed';
    var pm = a.payment || {};
    var pagado = pm.status === 'paid';
    var pagoHtml = pagado ? '<span style="color:#2e7d32;font-size:12px;font-weight:600;">Pagado</span>' : '<span style="color:#e65100;font-size:12px;">Pendiente</span>';
    html += '<tr class="turno-row"><td class="whitespace-nowrap">' + a.start.slice(0,10).split('-').reverse().join('/') + '</td><td class="whitespace-nowrap" style="font-weight:500;">' + a.start.slice(11,16) + ' - ' + a.end.slice(11,16) + '</td>' +
      '<td><span class="cliente">' + esc(c.firstName) + ' ' + esc(c.lastName) + '</span>' + (c.phone ? '<br><span style="font-size:12px;color:#999;">'+esc(c.phone)+'</span>' : '') + '</td>' +
      '<td class="servicio-info whitespace-nowrap">' + esc(s.name||'') + '</td><td class="precio whitespace-nowrap">' + (s.price ? '$' + Number(s.price).toLocaleString('es-AR') : '') + '</td>' +
      '<td class="whitespace-nowrap">' + pagoHtml + '</td>' +
      '<td><span class="status-badge ' + est + '">' + (est === 'confirmed' ? 'Confirmado' : 'Cancelado') + '</span></td>' +
      '<td class="actions whitespace-nowrap"><button class="btn btn-ghost btn-sm" onclick="abrirModalTurno(' + a.id + ')">Ver</button>' +
      (est !== 'cancelled' ? '<button class="btn btn-danger btn-sm" onclick="cancelarTurnoLista(' + a.id + ')">Cancelar</button>' : '') + '</td></tr>';
  });
  container.innerHTML = html + '</tbody></table></div>';
}

async function cancelarTurnoLista(id) {
  if (!confirm('\u00bfCancelar este turno?')) return;
  var res = await fetch(TURNOS_API + '?id=' + id, {method:'DELETE'});
  if (!res.ok) { var err = await res.json(); mostrarToast('Error: '+(err.error||'desconocido')); return; }
  mostrarToast('Turno cancelado'); clearDashCache(); renderTurnos(); renderCalendario();
}

async function cargarClientes() {
  var tbody = document.getElementById('tbody-clientes');
  tbody.innerHTML = '<tr><td colspan="5" style="text-align:center;color:#999;padding:32px;">Cargando...</td></tr>';
  try {
    var res = await fetch(CLIENTES_API); var data = await res.json();
    if (!Array.isArray(data) || data.length === 0) { tbody.innerHTML = '<tr><td colspan="5"><div class="empty-state"><div class="icon">\ud83d\udc65</div>No hay clientes todav\u00eda</div></td></tr>'; return; }
    var q = document.getElementById('clientSearch').value.toLowerCase();
    var list = q ? data.filter(function(c) { return ((c.firstName||'')+' '+(c.lastName||'')).toLowerCase().indexOf(q) >= 0 || (c.phone||'').indexOf(q) >= 0 || (c.email||'').toLowerCase().indexOf(q) >= 0; }) : data;
    if (!list.length) { tbody.innerHTML = '<tr><td colspan="5"><div class="empty-state">No se encontraron clientes</div></td></tr>'; return; }
    list.sort(function(a,b) { return (a.firstName||'').localeCompare(b.firstName||''); });
    tbody.innerHTML = '';
    list.forEach(function(c) {
      var tr = document.createElement('tr');
      tr.innerHTML = '<td><strong>' + esc(c.firstName||'') + ' ' + esc(c.lastName||'') + '</strong></td><td>' + esc(c.phone||'\u2014') + '</td><td>' + esc(c.email||'\u2014') + '</td><td>' + (c.appointmentCount||0) + '</td><td>' + (c.appointmentCount === 0 ? '<button class="btn btn-danger btn-sm" onclick="eliminarCliente(' + c.id + ')">Eliminar</button>' : '') + '</td>';
      tbody.appendChild(tr);
    });
  } catch(e) { tbody.innerHTML = '<tr><td colspan="5"><div class="empty-state">Error al cargar clientes</div></td></tr>'; }
}
document.getElementById('clientSearch').addEventListener('input', cargarClientes);

async function eliminarCliente(id) {
  if (!confirm('\u00bfEliminar este cliente?')) return;
  try { var res = await fetch(CLIENTES_API + '?id=' + id, {method:'DELETE'}); var data = await res.json(); if (!res.ok) { mostrarToast(data.error || 'Error'); return; } mostrarToast('Cliente eliminado'); cargarClientes(); }
  catch(e) { mostrarToast('Error de conexi\u00f3n'); }
}

async function cargarWhatsApp() {
  var statusDiv = document.getElementById('whatsapp-status');
  var qrDiv = document.getElementById('whatsapp-qr-container');
  var connectedDiv = document.getElementById('whatsapp-connected');
  try {
    var res = await fetch('../api/whatsapp-qr.php');
    var data = await res.json();
    if (data.status === 'connected') { statusDiv.innerHTML = ''; qrDiv.style.display = 'none'; connectedDiv.style.display = 'block'; if (waPollTimer) { clearInterval(waPollTimer); waPollTimer = null; } return; }
    if (data.status === 'awaiting_qr' && data.qr) { statusDiv.innerHTML = ''; document.getElementById('whatsapp-qr-img').src = data.qr; qrDiv.style.display = 'block'; connectedDiv.style.display = 'none'; return; }
    statusDiv.innerHTML = '<div class="wa-loading">\u23f3 Esperando c\u00f3digo QR...</div><div class="wa-retry">La p\u00e1gina se actualiza autom\u00e1ticamente</div>'; qrDiv.style.display = 'none'; connectedDiv.style.display = 'none';
  } catch(e) { statusDiv.innerHTML = '<div class="wa-error">\u274c No se pudo conectar con el servicio de WhatsApp</div><div class="wa-retry">Asegurate que el contenedor de Baileys est\u00e9 funcionando</div>'; qrDiv.style.display = 'none'; connectedDiv.style.display = 'none'; }
}

function guardarMarca() {
  var form = document.getElementById('formMarca');
  var btn = form.querySelector('[type="button"]');
  btn.disabled = true; btn.textContent = 'Guardando...';
  var fd = new FormData(form);
  fd.append('action', 'save_brand'); fd.append('csrf_token', CSRF_TOKEN);
  fetch('/admin/index.php', { method: 'POST', body: fd })
    .then(function(r) { return r.text(); })
    .then(function(text) { var d; try { d = JSON.parse(text); } catch(e) { mostrarToast('Error: respuesta inv\u00e1lida'); btn.disabled = false; btn.textContent = 'Guardar cambios'; return; } if (d.success) { mostrarToast('Marca guardada'); } else { mostrarToast('Error: ' + (d.error || 'desconocido')); } })
    .catch(function(err) { mostrarToast('Error de red'); })
    .finally(function() { btn.disabled = false; btn.textContent = 'Guardar cambios'; });
}

var formColores = document.getElementById('formColores');
if (formColores) {
  formColores.querySelectorAll('input[type="color"]').forEach(function(input) {
    input.addEventListener('input', function() {
      var previewHeader = document.querySelector('.preview-header'), previewHero = document.querySelector('.preview-hero'), previewBtn = document.querySelector('.preview-btn');
      var name = this.name, val = this.value;
      if (name === 'primary') { if (previewHeader) previewHeader.style.background = val; if (previewBtn) previewBtn.style.background = val; }
      if (name === 'secondary' && previewHero) previewHero.style.background = val;
      if (name === 'text' && previewHero) previewHero.style.color = val;
    });
  });
  formColores.addEventListener('submit', function(e) {
    e.preventDefault();
    var fd = new FormData(this);
    fd.append('action', 'save_colors'); fd.append('csrf_token', CSRF_TOKEN);
    fetch('/admin/index.php', { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(d) { mostrarToast(d.success ? 'Colores guardados' : ('Error: ' + (d.error || 'desconocido'))); })
      .catch(function() { mostrarToast('Error de conexi\u00f3n'); });
  });
}

(function() {
  var logoInput = document.getElementById('logoInput');
  if (logoInput) {
    logoInput.addEventListener('change', function() {
      if (!this.files || !this.files[0]) return;
      var file = this.files[0];
      if (file.type !== 'image/png') { mostrarToast('Solo se permiten archivos PNG'); return; }
      if (file.size > 2 * 1024 * 1024) { mostrarToast('M\u00e1ximo 2MB'); return; }
      var fd = new FormData();
      fd.append('action', 'upload_logo'); fd.append('csrf_token', CSRF_TOKEN); fd.append('logo', file);
      fetch('/admin/index.php', { method: 'POST', body: fd })
        .then(function(r) { return r.json(); })
        .then(function(d) {
          if (d.error) { mostrarToast('Error: ' + d.error); return; }
          mostrarToast('Logo actualizado');
          var preview = document.getElementById('logoPreview'), placeholder = document.getElementById('logoPlaceholder');
          preview.src = SCHEDULER_BASE + '/' + d.logo; preview.style.display = 'block';
          if (placeholder) placeholder.style.display = 'none';
          var delBtn = document.getElementById('btnDeleteLogo');
          if (delBtn) { delBtn.style.display = 'inline-flex'; } else {
            var div = document.querySelector('#tab-logo .flex');
            if (div) { var b = document.createElement('button'); b.className = 'btn btn-danger'; b.id = 'btnDeleteLogo'; b.textContent = 'Eliminar logo'; b.addEventListener('click', eliminarLogo); div.appendChild(b); }
          }
        })
        .catch(function() { mostrarToast('Error de conexi\u00f3n'); });
    });
  }

  function eliminarLogo() {
    if (!confirm('\u00bfEliminar el logo?')) return;
    var fd = new FormData();
    fd.append('action', 'delete_logo'); fd.append('csrf_token', CSRF_TOKEN);
    fetch('/admin/index.php', { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(d) {
        if (d.error) { mostrarToast('Error: ' + d.error); return; }
        mostrarToast('Logo eliminado');
        var preview = document.getElementById('logoPreview'), placeholder = document.getElementById('logoPlaceholder');
        preview.src = ''; preview.style.display = 'none';
        if (placeholder) placeholder.style.display = 'flex';
        var delBtn = document.getElementById('btnDeleteLogo');
        if (delBtn) delBtn.style.display = 'none';
      })
      .catch(function() { mostrarToast('Error de conexi\u00f3n'); });
  }

  var btnDeleteLogo = document.getElementById('btnDeleteLogo');
  if (btnDeleteLogo) btnDeleteLogo.addEventListener('click', eliminarLogo);
})();

(function() {
  window.eliminarGaleria = function(filename) {
    if (!confirm('\u00bfEliminar esta imagen?')) return;
    var fd = new FormData();
    fd.append('action', 'delete_gallery'); fd.append('csrf_token', CSRF_TOKEN); fd.append('filename', filename);
    fetch('/admin/index.php', { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(d) { if (d.error) { mostrarToast('Error: ' + d.error); return; } mostrarToast('Imagen eliminada'); renderizarGaleria(d.gallery); })
      .catch(function() { mostrarToast('Error de conexi\u00f3n'); });
  };

  function renderizarGaleria(gallery) {
    var grid = document.getElementById('galleryGrid');
    if (!gallery || gallery.length === 0) { grid.innerHTML = '<div class="gallery-empty">No hay im\u00e1genes todav\u00eda</div>'; }
    else {
      var html = '';
      gallery.forEach(function(img) {
        html += '<div class="gallery-thumb" data-filename="' + img.filename + '"><img src="' + SCHEDULER_BASE + '/uploads/gallery/' + img.filename + '" alt="" loading="lazy"><button class="delete-btn" title="Eliminar">&times;</button></div>';
      });
      grid.innerHTML = html;
    }
    var zone = document.getElementById('galleryUploadZone');
    if (zone) { var countDiv = zone.querySelector('div:last-child'); if (countDiv) countDiv.textContent = 'JPG o PNG \u00b7 M\u00e1x 5MB \u00b7 ' + (gallery ? gallery.length : 0) + '/10'; }
  }

  // event delegation for delete buttons
  document.getElementById('galleryGrid').addEventListener('click', function(e) {
    var btn = e.target.closest('.delete-btn');
    if (!btn) return;
    var thumb = btn.closest('.gallery-thumb');
    if (!thumb) return;
    window.eliminarGaleria(thumb.dataset.filename);
  });

  function subirGaleria(files) {
    var valid = [];
    for (var i = 0; i < files.length; i++) { var f = files[i]; if (['image/jpeg','image/png'].indexOf(f.type) >= 0 && f.size <= 5*1024*1024) valid.push(f); }
    if (!valid.length) { mostrarToast('Ninguna imagen v\u00e1lida'); return; }
    var fd = new FormData();
    fd.append('action', 'upload_gallery'); fd.append('csrf_token', CSRF_TOKEN);
    valid.forEach(function(f) { fd.append('images[]', f); });
    mostrarToast('Subiendo ' + valid.length + ' imagen(es)...');
    fetch('/admin/index.php', { method: 'POST', body: fd })
      .then(function(r) { return r.json(); })
      .then(function(d) { if (d.error) { mostrarToast('Error: ' + d.error); return; } var msg = d.added + ' imagen(es) agregada(s)'; if (d.errors && d.errors.length) msg += ' (' + d.errors.length + ' error(es))'; mostrarToast(msg); renderizarGaleria(d.gallery); })
      .catch(function() { mostrarToast('Error de conexi\u00f3n'); });
  }

  var galleryInput = document.getElementById('galleryInput'), galleryZone = document.getElementById('galleryUploadZone');
  if (galleryInput && galleryZone) {
    galleryZone.addEventListener('dragover', function(e) { e.preventDefault(); this.style.borderColor = '#884e4f'; });
    galleryZone.addEventListener('dragleave', function() { this.style.borderColor = ''; });
    galleryZone.addEventListener('drop', function(e) { e.preventDefault(); this.style.borderColor = ''; if (e.dataTransfer.files && e.dataTransfer.files.length) subirGaleria(e.dataTransfer.files); });
    galleryInput.addEventListener('change', function() { if (this.files && this.files.length) { var files = Array.from(this.files); this.value = ''; subirGaleria(files); } });
  }
})();

/* ===== PROFESIONALES ===== */
var profesionales = [];

function cargarProfesionales() {
  fetch('/config.json').then(function(r) { if (r.ok) return r.json(); }).then(function(cfg) {
    profesionales = cfg.professionals || [];
    renderProfesionales();
  }).catch(function() {
    document.getElementById('profLoading').classList.add('hidden');
    document.getElementById('profEmpty').classList.remove('hidden');
  });
}

function renderProfesionales() {
  var loading = document.getElementById('profLoading'), empty = document.getElementById('profEmpty'), list = document.getElementById('profList');
  loading.classList.add('hidden');
  if (!profesionales.length) { empty.classList.remove('hidden'); list.innerHTML = ''; return; }
  empty.classList.add('hidden');
  var html = '<div class="table-wrap"><table><thead><tr><th>Foto</th><th>Nombre</th><th>Título</th><th></th></tr></thead><tbody>';
  profesionales.forEach(function(p, i) {
    var photoHtml = p.photo ? '<img src="../' + p.photo + '" style="width:48px;height:48px;border-radius:50%;object-fit:cover;">' : '<div style="width:48px;height:48px;border-radius:50%;background:#e4e2e1;display:flex;align-items:center;justify-content:center;font-size:20px;color:#999;">&#9786;</div>';
    html += '<tr><td>' + photoHtml + '</td><td><strong>' + esc(p.name) + '</strong></td><td style="color:#888;font-size:13px;">' + esc(p.title || '-') + '</td>' +
      '<td class="actions"><button class="btn btn-ghost btn-sm" onclick="editarProfesional(' + i + ')">Editar</button> ' +
      '<button class="btn btn-danger btn-sm" onclick="eliminarProfesional(' + i + ')">Eliminar</button></td></tr>';
  });
  html += '</tbody></table></div>';
  list.innerHTML = html;
}

function editarProfesional(idx) {
  var p = profesionales[idx];
  document.getElementById('profEditIdx').value = idx;
  document.getElementById('profName').value = p.name || '';
  document.getElementById('profTitle').value = p.title || '';
  document.getElementById('profBio').value = p.bio || '';
  document.getElementById('profHighlights').value = (p.highlights || []).join('\n');
  document.getElementById('profFormTitle').textContent = 'Editar profesional';
  document.getElementById('btnGuardarProf').textContent = 'Actualizar profesional';
  document.getElementById('btnCancelarProf').classList.remove('hidden');
  var preview = document.getElementById('profPhotoPreview');
  if (p.photo) { preview.src = '../' + p.photo; preview.style.display = 'block'; } else { preview.style.display = 'none'; }
  document.getElementById('profPhotoInput').value = '';
  document.getElementById('btnProfPhotoClear').style.display = p.photo ? 'inline-block' : 'none';
  document.getElementById('formProfesional').scrollIntoView({behavior:'smooth'});
}

function cancelarEdicionProf() {
  document.getElementById('profEditIdx').value = '-1';
  document.getElementById('profName').value = '';
  document.getElementById('profTitle').value = '';
  document.getElementById('profBio').value = '';
  document.getElementById('profHighlights').value = '';
  document.getElementById('profPhotoInput').value = '';
  document.getElementById('profPhotoPreview').style.display = 'none';
  document.getElementById('btnProfPhotoClear').style.display = 'none';
  document.getElementById('profFormTitle').textContent = 'Agregar profesional';
  document.getElementById('btnGuardarProf').textContent = 'Guardar profesional';
  document.getElementById('btnCancelarProf').classList.add('hidden');
}

function eliminarProfesional(idx) {
  if (!confirm('\u00bfEliminar a ' + profesionales[idx].name + '?')) return;
  profesionales.splice(idx, 1);
  guardarProfesionalesArray(profesionales);
}

function guardarProfesionalesArray(arr) {
  var fd = new FormData();
  fd.append('action', 'save_professionals');
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('professionals', JSON.stringify(arr));
  fetch('/admin/index.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.error) { mostrarToast('Error: ' + d.error); return; }
      profesionales = d.professionals || [];
      renderProfesionales();
      mostrarToast('Profesionales guardados');
    })
    .catch(function() { mostrarToast('Error de conexi\u00f3n'); });
}

document.getElementById('formProfesional').addEventListener('submit', function(e) {
  e.preventDefault();
  var btn = document.getElementById('btnGuardarProf');
  btn.textContent = 'Guardando...'; btn.disabled = true;
  var idx = parseInt(document.getElementById('profEditIdx').value);
  var highlights = document.getElementById('profHighlights').value.split('\n').map(function(l) { return l.trim(); }).filter(function(l) { return l; });
  var prof = {
    name: document.getElementById('profName').value.trim(),
    title: document.getElementById('profTitle').value.trim(),
    bio: document.getElementById('profBio').value.trim(),
    highlights: highlights
  };
  var photoInput = document.getElementById('profPhotoInput');
  if (idx >= 0 && idx < profesionales.length) {
    prof.photo = profesionales[idx].photo || '';
  }
  var arr;
  if (idx >= 0 && idx < profesionales.length) {
    arr = profesionales.slice();
    arr[idx] = prof;
  } else {
    arr = profesionales.slice();
    arr.push(prof);
  }
  var fd = new FormData();
  fd.append('action', 'save_professionals');
  fd.append('csrf_token', CSRF_TOKEN);
  fd.append('professionals', JSON.stringify(arr));
  fd.append('photo_idx', idx >= 0 ? String(idx) : String(arr.length - 1));
  if (photoInput.files && photoInput.files[0]) {
    fd.append('photo', photoInput.files[0]);
  }
  fetch('/admin/index.php', { method: 'POST', body: fd })
    .then(function(r) { return r.json(); })
    .then(function(d) {
      if (d.error) { mostrarToast('Error: ' + d.error); btn.disabled = false; btn.textContent = idx >= 0 ? 'Actualizar profesional' : 'Guardar profesional'; return; }
      profesionales = d.professionals || [];
      renderProfesionales();
      cancelarEdicionProf();
      mostrarToast('Profesional guardado');
    })
    .catch(function() { mostrarToast('Error de conexi\u00f3n'); })
    .finally(function() { btn.disabled = false; btn.textContent = idx >= 0 ? 'Actualizar profesional' : 'Guardar profesional'; });
});

document.getElementById('profPhotoInput').addEventListener('change', function() {
  if (this.files && this.files[0]) {
    var reader = new FileReader();
    var preview = document.getElementById('profPhotoPreview');
    reader.onload = function(e) { preview.src = e.target.result; preview.style.display = 'block'; };
    reader.readAsDataURL(this.files[0]);
    document.getElementById('btnProfPhotoClear').style.display = 'inline-block';
  }
});

document.getElementById('btnProfPhotoClear').addEventListener('click', function() {
  document.getElementById('profPhotoInput').value = '';
  document.getElementById('profPhotoPreview').style.display = 'none';
  this.style.display = 'none';
});

cargarDashboard();
</script>
<?php endif; ?>
</body>
</html>