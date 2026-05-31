<?php
/**
 * MySQL-кэш ответов proxy (api_cache) + sync_log.
 */
require_once __DIR__ . '/db.php';

const API_CACHE_TTL_SEC = 300; // 5 минут

function api_cache_pdo(): PDO {
    static $pdo = null;
    if ($pdo === null) {
        $pdo = db_connect();
    }
    return $pdo;
}

/** period: пустая строка вместо NULL для UNIQUE */
function api_cache_norm_period(?string $period): string {
    return $period ?? '';
}

function api_cache_get(
    int $projectId,
    string $action,
    ?string $cacheDate,
    ?string $period,
    int $maxAgeSec = API_CACHE_TTL_SEC
): ?array {
    $pdo = api_cache_pdo();
    $period = api_cache_norm_period($period);
    $stmt = $pdo->prepare(
        'SELECT payload, cached_at, UNIX_TIMESTAMP(cached_at) AS ts
         FROM api_cache
         WHERE project_id = ? AND action = ? AND cache_date <=> ? AND period = ?'
    );
    $stmt->execute([$projectId, $action, $cacheDate, $period]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    $age = time() - (int)$row['ts'];
    if ($age > $maxAgeSec) {
        return null;
    }
    return [
        'payload'   => $row['payload'],
        'cached_at' => $row['cached_at'],
        'age_sec'   => $age,
    ];
}

/** Последний срез без ограничения по TTL (fallback при падении TB) */
function api_cache_get_stale(
    int $projectId,
    string $action,
    ?string $cacheDate,
    ?string $period
): ?array {
    $pdo = api_cache_pdo();
    $period = api_cache_norm_period($period);
    $stmt = $pdo->prepare(
        'SELECT payload, cached_at FROM api_cache
         WHERE project_id = ? AND action = ? AND cache_date <=> ? AND period = ?
         ORDER BY cached_at DESC LIMIT 1'
    );
    $stmt->execute([$projectId, $action, $cacheDate, $period]);
    $row = $stmt->fetch();
    if (!$row) {
        return null;
    }
    return ['payload' => $row['payload'], 'cached_at' => $row['cached_at']];
}

function api_cache_set(
    int $projectId,
    string $action,
    ?string $cacheDate,
    ?string $period,
    string $payload
): void {
    $pdo = api_cache_pdo();
    $period = api_cache_norm_period($period);
    $stmt = $pdo->prepare(
        'INSERT INTO api_cache (project_id, action, cache_date, period, payload)
         VALUES (?, ?, ?, ?, ?)
         ON DUPLICATE KEY UPDATE payload = VALUES(payload), cached_at = CURRENT_TIMESTAMP'
    );
    $stmt->execute([$projectId, $action, $cacheDate, $period, $payload]);
}

function sync_log_write(string $entity, string $status, ?string $error = null): void {
    $pdo = api_cache_pdo();
    $stmt = $pdo->prepare(
        'INSERT INTO sync_log (entity, status, error) VALUES (?, ?, ?)'
    );
    $stmt->execute([$entity, $status, $error]);
}
