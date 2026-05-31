<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

function staff_city_for_property(?int $propertyId): string {
    if ($propertyId === null) {
        return 'other';
    }
    static $map = [
        1 => 'spb', 2 => 'spb', 4 => 'spb',
        6 => 'ekb', 7 => 'ekb', 9 => 'ekb', 10 => 'ekb', 11 => 'ekb', 13 => 'ekb', 14 => 'ekb', 15 => 'ekb',
        12 => 'kzn',
    ];
    return $map[$propertyId] ?? 'other';
}

try {
    $pdo = db_connect();
    $stmt = $pdo->query(
        'SELECT s.*, p.name AS property_name, p.id AS property_id
         FROM staff s
         LEFT JOIN properties p ON p.id = s.property_id
         ORDER BY s.full_name'
    );
    $rows = $stmt->fetchAll();
    foreach ($rows as &$r) {
        $r['id'] = (int)$r['id'];
        $r['property_id'] = $r['property_id'] !== null ? (int)$r['property_id'] : null;
        $r['salary'] = $r['salary'] !== null ? (float)$r['salary'] : null;
        $r['city'] = staff_city_for_property($r['property_id']);
    }
    unset($r);

    echo json_encode(['rows' => $rows], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
