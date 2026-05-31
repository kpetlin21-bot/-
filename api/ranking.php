<?php
require_once __DIR__ . '/../db.php';

header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, OPTIONS');

if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

$config = is_file(__DIR__ . '/../projects_config.php')
    ? require __DIR__ . '/../projects_config.php'
    : ['names' => [], 'slugs' => []];

$activeIds = [1, 2, 4, 6, 7, 9, 10, 11, 12, 13, 14, 15];

try {
    $pdo = db_connect();
    $stmt = $pdo->prepare(
        "SELECT project_id, slug, payload, cached_at
         FROM api_cache
         WHERE action = 'dashboard' AND period = 'today' AND slug IS NOT NULL AND slug != ''"
    );
    $stmt->execute();
    $bySlug = [];
    while ($row = $stmt->fetch()) {
        $slug = $row['slug'] ?: '';
        if ($slug === '') {
            continue;
        }
        $data = json_decode($row['payload'], true);
        $rate = isset($data['tasks']['rate']) ? (int)$data['tasks']['rate'] : 0;
        $bySlug[$slug] = [
            'slug'       => $slug,
            'project_id' => (int)$row['project_id'],
            'name'       => $config['names'][(int)$row['project_id']] ?? $slug,
            'rate'       => $rate,
            'cached_at'  => $row['cached_at'],
        ];
    }

    $items = [];
    foreach ($activeIds as $pid) {
        $slug = $config['slugs'][$pid] ?? '';
        if ($slug === '') {
            continue;
        }
        $items[] = $bySlug[$slug] ?? [
            'slug'       => $slug,
            'project_id' => $pid,
            'name'       => $config['names'][$pid] ?? $slug,
            'rate'       => null,
            'cached_at'  => null,
        ];
    }

    usort($items, static function ($a, $b) {
        $ra = $a['rate'] ?? -1;
        $rb = $b['rate'] ?? -1;
        return $rb <=> $ra;
    });

    echo json_encode(['items' => $items], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => $e->getMessage()], JSON_UNESCAPED_UNICODE);
}
