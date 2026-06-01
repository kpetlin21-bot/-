<?php
declare(strict_types=1);

require_once dirname(__DIR__) . '/config.php';
cors_preflight();

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    json_response(['success' => false, 'error' => 'POST required'], 405);
}

$raw = $_POST['data'] ?? '';
if ($raw === '') {
    json_response(['success' => false, 'error' => 'Missing data field'], 400);
}

$payload = json_decode($raw, true);
if (!is_array($payload)) {
    json_response(['success' => false, 'error' => 'Invalid JSON in data'], 400);
}

$pdo = db();

try {
    $pdo->beginTransaction();

    $stmt = $pdo->prepare('
        INSERT INTO checklists (
            jk_name, manager_name, checked_at, houses,
            score_total, score_territory, score_containers, score_cleaning, status
        ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)
    ');

    $checkedAt = $payload['checked_at'] ?? date('Y-m-d H:i:s');
    $status = ($payload['status'] ?? 'submitted') === 'draft' ? 'draft' : 'submitted';

    $stmt->execute([
        $payload['jk_name'] ?? '',
        $payload['manager_name'] ?? '',
        $checkedAt,
        json_encode($payload['houses'] ?? [], JSON_UNESCAPED_UNICODE),
        (float)($payload['score_total'] ?? 0),
        (float)($payload['score_territory'] ?? 0),
        (float)($payload['score_containers'] ?? 0),
        (float)($payload['score_cleaning'] ?? 0),
        $status,
    ]);

    $checklistId = (int)$pdo->lastInsertId();

    $itemStmt = $pdo->prepare('
        INSERT INTO checklist_items (checklist_id, zone_id, item_num, value, floors, entrances, comment)
        VALUES (?, ?, ?, ?, ?, ?, ?)
    ');

    foreach ($payload['items'] ?? [] as $item) {
        $value = $item['value'] ?? 'ok';
        if (!in_array($value, ['ok', 'fail', 'na'], true)) {
            $value = 'ok';
        }
        $itemStmt->execute([
            $checklistId,
            $item['zone_id'] ?? '',
            $item['item_num'] ?? '',
            $value,
            json_encode($item['floors'] ?? [], JSON_UNESCAPED_UNICODE),
            json_encode($item['entrances'] ?? [], JSON_UNESCAPED_UNICODE),
            $item['comment'] ?? '',
        ]);
    }

    $uploadDir = UPLOADS_DIR . '/' . $checklistId;
    if (!is_dir($uploadDir)) {
        mkdir($uploadDir, 0755, true);
    }

    $photoStmt = $pdo->prepare('
        INSERT INTO checklist_photos (checklist_id, item_num, filepath)
        VALUES (?, ?, ?)
    ');

    foreach ($_FILES as $key => $file) {
        if (!str_starts_with($key, 'photo_')) {
            continue;
        }
        if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
            continue;
        }

        $suffix = substr($key, 6);
        if (preg_match('/^(\d+)_(\d+)/', $suffix, $m)) {
            $itemNum = $m[1] . '.' . $m[2];
        } else {
            $itemNum = str_replace('_', '.', $suffix);
        }
        $ext = 'jpg';
        $mime = mime_content_type($file['tmp_name']) ?: '';
        if (str_contains($mime, 'png')) {
            $ext = 'png';
        } elseif (str_contains($mime, 'webp')) {
            $ext = 'webp';
        }

        $filename = str_replace('.', '_', $itemNum) . '_' . time() . '_' . bin2hex(random_bytes(3)) . '.' . $ext;
        $dest = $uploadDir . '/' . $filename;

        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            continue;
        }

        $relative = 'uploads/' . $checklistId . '/' . $filename;
        $photoStmt->execute([$checklistId, $itemNum, $relative]);
    }

    $pdo->commit();
    json_response(['success' => true, 'checklist_id' => $checklistId]);
} catch (Throwable $e) {
    if ($pdo->inTransaction()) {
        $pdo->rollBack();
    }
    json_response(['success' => false, 'error' => $e->getMessage()], 500);
}
