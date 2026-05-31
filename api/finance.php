<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

/** property_id (TB) → город для аналитики */
function finance_city_for_property(int $propertyId): string {
    static $map = [
        1 => 'spb', 2 => 'spb', 4 => 'spb',
        6 => 'ekb', 7 => 'ekb', 9 => 'ekb', 10 => 'ekb', 11 => 'ekb', 13 => 'ekb', 14 => 'ekb', 15 => 'ekb',
        12 => 'kzn',
    ];
    return $map[$propertyId] ?? 'other';
}

try {
    $pdo = db_connect();
    $year  = (int)($_GET['year'] ?? 2026);
    $month = (int)($_GET['month'] ?? 5);

    $stmt = $pdo->prepare(
        'SELECT fm.property_id, fm.year, fm.month, fm.revenue, fm.payroll, fm.expenses, fm.margin,
                p.name AS property_name
         FROM finance_monthly fm
         JOIN properties p ON p.id = fm.property_id
         WHERE fm.year = ? AND fm.month = ?
         ORDER BY fm.revenue DESC'
    );
    $stmt->execute([$year, $month]);
    $rows = $stmt->fetchAll();

    $totals = ['revenue' => 0, 'payroll' => 0, 'expenses' => 0, 'margin' => 0];
    $byCity = [];
    foreach ($rows as &$r) {
        $r['property_id'] = (int)$r['property_id'];
        $r['revenue']  = (float)$r['revenue'];
        $r['payroll']  = (float)$r['payroll'];
        $r['expenses'] = (float)$r['expenses'];
        $r['margin']   = (float)$r['margin'];
        $city = finance_city_for_property($r['property_id']);
        $r['city'] = $city;
        foreach (['revenue', 'payroll', 'expenses', 'margin'] as $k) {
            $totals[$k] += $r[$k];
        }
        if (!isset($byCity[$city])) {
            $byCity[$city] = ['city' => $city, 'revenue' => 0, 'margin' => 0];
        }
        $byCity[$city]['revenue'] += $r['revenue'];
        $byCity[$city]['margin']  += $r['margin'];
    }
    unset($r);

    echo json_encode([
        'year'   => $year,
        'month'  => $month,
        'rows'   => $rows,
        'totals' => $totals,
        'by_city' => array_values($byCity),
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
