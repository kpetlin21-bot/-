<?php
/**
 * Прокси Overpass API (User-Agent + CORS для фронтенда).
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');
header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$query = $_GET['data'] ?? '';
if ($query === '') {
    http_response_code(400);
    echo json_encode(['error' => 'Missing data parameter'], JSON_UNESCAPED_UNICODE);
    exit;
}

$url = 'https://overpass-api.de/api/interpreter?data=' . rawurlencode($query);
$ctx = stream_context_create([
    'http' => [
        'method'  => 'GET',
        'header'  => "User-Agent: cleansyst-dashboard/1.0\r\nAccept: application/json\r\n",
        'timeout' => 60,
        'ignore_errors' => true,
    ],
]);

$body = @file_get_contents($url, false, $ctx);
if ($body === false) {
    http_response_code(502);
    echo json_encode(['error' => 'Overpass request failed'], JSON_UNESCAPED_UNICODE);
    exit;
}

echo $body;
