<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
cors_preflight();

$limit = min(50, max(1, (int)($_GET['limit'] ?? 50)));
$jkFilter = trim($_GET['jk'] ?? '');

$pdo = db();

$sql = '
    SELECT id, jk_name, manager_name, checked_at, houses,
           score_total, score_territory, score_containers, score_cleaning, status
    FROM checklists
';
$params = [];

if ($jkFilter !== '') {
    $sql .= ' WHERE jk_name LIKE ? OR jk_name LIKE ?';
    $params[] = '%' . $jkFilter . '%';
    $params[] = '%' . str_replace('-', ' ', $jkFilter) . '%';
}

$sql .= ' ORDER BY checked_at DESC, id DESC LIMIT ' . $limit;

$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

foreach ($rows as &$row) {
    $row['houses'] = json_decode($row['houses'] ?? '[]', true) ?: [];
    $row['score_total'] = (float)$row['score_total'];
    $row['score_territory'] = (float)$row['score_territory'];
    $row['score_containers'] = (float)$row['score_containers'];
    $row['score_cleaning'] = (float)$row['score_cleaning'];
}
unset($row);

json_response(['success' => true, 'checklists' => $rows]);
