<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

try {
    $pdo = db_connect();
    $stmt = $pdo->query(
        "SELECT ran_at FROM sync_log WHERE status = 'ok' ORDER BY ran_at DESC LIMIT 1"
    );
    $row = $stmt->fetch();
    $ranAt = $row['ran_at'] ?? null;
    $label = '—';
    if ($ranAt) {
        $dt = new DateTime($ranAt);
        $label = $dt->format('d.m H:i');
    }
    echo json_encode([
        'last_ok_at' => $ranAt,
        'label'      => $label,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
