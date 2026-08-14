<?php
$envVars = [
    'EA_API_USER',
    'EA_API_PASS',
    'EA_ADMIN_PASSWORD',
    'OPENWA_API_KEY',
    'OPENWA_SESSION_ID',
    'OPENWA_BASE_URL',
    'ADMIN_PASSWORD_HASH',
    'CORS_ORIGIN',
    'EA_BASE_URL',
    'SCHEDULER_URL',
    'SCHEDULER_API_KEY',
    'PROVIDER_ID',
];

foreach ($envVars as $var) {
    $val = getenv($var);
    if ($val !== false && $val !== '') {
        $_ENV[$var] = $val;
    }
}

function providerId(): int {
    $v = $_ENV['PROVIDER_ID'] ?? '';
    return $v !== '' ? (int)$v : 5;
}

function schedulerApiUrl(): string {
    return $_ENV['SCHEDULER_URL'] ?? 'http://127.0.0.1:3000/api/v1';
}

function openwaBaseUrl(): string {
    return rtrim($_ENV['OPENWA_BASE_URL'] ?? 'http://127.0.0.1:2785', '/');
}

function schedulerApiCall(string $endpoint, string $method = 'GET', $body = null): array {
    $url = schedulerApiUrl() . '/' . ltrim($endpoint, '/');
    $ch = curl_init($url);
    $opts = [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT => 5,
        CURLOPT_CONNECTTIMEOUT => 3,
    ];
    if ($method === 'POST') {
        $opts[CURLOPT_POST] = true;
        $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body);
    } elseif ($method === 'PUT') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'PUT';
        $opts[CURLOPT_POSTFIELDS] = is_string($body) ? $body : json_encode($body);
    } elseif ($method === 'DELETE') {
        $opts[CURLOPT_CUSTOMREQUEST] = 'DELETE';
    }
    $key = $_ENV['SCHEDULER_API_KEY'] ?? $_ENV['EA_API_PASS'] ?? '';
    $opts[CURLOPT_HTTPHEADER] = ['X-API-Key: ' . $key, 'Content-Type: application/json'];
    curl_setopt_array($ch, $opts);
    $res = curl_exec($ch);
    $code = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err = curl_error($ch);
    // curl_close() es deprecado en PHP 8.x (no-op) y emitía un aviso que corrompía el JSON del proxy.
    return ['data' => json_decode($res, true) ?? $res, 'httpCode' => $code, 'error' => $err];
}
