<?php
/**
 * Диагностика api_cache (только по secret).
 * GET cache_diag.php?secret=cleansyst2026
 * GET cache_diag.php?secret=cleansyst2026&format=table
 */
require_once __DIR__ . '/cache_db.php';

define('CACHE_DIAG_SECRET', 'cleansyst2026');

if (($_GET['secret'] ?? '') !== CACHE_DIAG_SECRET) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo "forbidden\n";
    exit(1);
}

$format = strtolower((string)($_GET['format'] ?? 'json'));
$pdo    = api_cache_pdo();

$stmt = $pdo->query(
    'SELECT project_id, action, cache_date, period, cached_at,
            LEFT(payload, 120) AS payload_preview
     FROM api_cache
     ORDER BY project_id, action, period'
);
$rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

if ($format === 'table') {
    header('Content-Type: text/plain; charset=utf-8');
    echo str_pad('project_id', 12)
        . str_pad('action', 18)
        . str_pad('period', 14)
        . str_pad('cached_at', 22)
        . "payload_preview\n";
    echo str_repeat('-', 120) . "\n";
    foreach ($rows as $r) {
        echo str_pad((string)$r['project_id'], 12)
            . str_pad((string)$r['action'], 18)
            . str_pad((string)$r['period'], 14)
            . str_pad((string)$r['cached_at'], 22)
            . ($r['payload_preview'] ?? '') . "\n";
    }
    echo "\nTotal rows: " . count($rows) . "\n";
    $distinct = $pdo->query('SELECT COUNT(DISTINCT project_id) FROM api_cache')->fetchColumn();
    echo "Distinct project_ids: {$distinct}\n";
    exit(0);
}

header('Content-Type: application/json; charset=utf-8');
$distinct = (int)$pdo->query('SELECT COUNT(DISTINCT project_id) FROM api_cache')->fetchColumn();
echo json_encode([
    'total_rows'            => count($rows),
    'distinct_project_ids'  => $distinct,
    'rows'                  => $rows,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
