<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
cors_preflight();

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) {
    json_response(['success' => false, 'error' => 'id required'], 400);
}

$pdo = db();

$stmt = $pdo->prepare('
    SELECT id, jk_name, manager_name, checked_at, houses,
           score_total, score_territory, score_containers, score_cleaning, status, created_at
    FROM checklists WHERE id = ?
');
$stmt->execute([$id]);
$row = $stmt->fetch();

if (!$row) {
    json_response(['success' => false, 'error' => 'Not found'], 404);
}

$row['houses'] = json_decode($row['houses'] ?? '[]', true) ?: [];
$row['score_total'] = (float)$row['score_total'];
$row['score_territory'] = (float)$row['score_territory'];
$row['score_containers'] = (float)$row['score_containers'];
$row['score_cleaning'] = (float)$row['score_cleaning'];

$itemStmt = $pdo->prepare('
    SELECT zone_id, item_num, value, floors, comment
    FROM checklist_items WHERE checklist_id = ?
    ORDER BY id
');
$itemStmt->execute([$id]);
$items = $itemStmt->fetchAll();
foreach ($items as &$it) {
    $it['floors'] = json_decode($it['floors'] ?? '[]', true) ?: [];
}
unset($it);

$photoStmt = $pdo->prepare('
    SELECT item_num, filepath FROM checklist_photos WHERE checklist_id = ?
');
$photoStmt->execute([$id]);
$photos = $photoStmt->fetchAll();

$baseUrl = (isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https' : 'http')
    . '://' . ($_SERVER['HTTP_HOST'] ?? 'api.cleansyst.ru')
    . '/checklist/';

foreach ($photos as &$ph) {
    $ph['url'] = $baseUrl . $ph['filepath'];
}
unset($ph);

$row['items'] = $items;
$row['photos'] = $photos;

json_response(['success' => true, 'checklist' => $row]);
