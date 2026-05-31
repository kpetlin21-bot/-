<?php
/**
 * Фоновая синхронизация ThroneBaron → api_cache (cron каждые 5 мин).
 * CLI: php sync.php
 */
if (php_sapi_name() !== 'cli') {
    http_response_code(403);
    echo "CLI only\n";
    exit(1);
}

require_once __DIR__ . '/cache_db.php';

define('SYNC_INTERNAL_KEY', 'cleansyst2026');
define('SYNC_BASE_URL', 'https://api.cleansyst.ru/proxy.php');

/** Пока тест — один ЖК; далее все 14 */
$projects = [2];

$tz    = new DateTimeZone('Europe/Moscow');
$today = (new DateTime('now', $tz))->format('Y-m-d');

$endpoints = [
    ['dashboard',       'date=today'],
    ['house_breakdown', 'date=today'],
    ['shifts',          'date=today'],
    ['tasks',           'date=today'],
];

function sync_http_get(string $url, int $timeoutSec = 300): array {
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'header'        => "Accept: application/json\r\n",
        'timeout'       => $timeoutSec,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    if ($body === false || $body === '') {
        return ['ok' => false, 'error' => 'HTTP failed', 'body' => null];
    }
    $json = json_decode($body, true);
    if (!is_array($json) && json_last_error() !== JSON_ERROR_NONE) {
        return ['ok' => false, 'error' => 'Invalid JSON', 'body' => substr($body, 0, 200)];
    }
    if (is_array($json) && isset($json['error'])) {
        return ['ok' => false, 'error' => (string)$json['error'], 'body' => $body];
    }
    return ['ok' => true, 'error' => null, 'body' => $body];
}

echo "Sync start " . date('Y-m-d H:i:s') . " MSK\n";

foreach ($projects as $projectId) {
    echo "\n=== project_id={$projectId} ===\n";
    foreach ($endpoints as [$action, $params]) {
        $entity = "p{$projectId}:{$action}";
        $url    = SYNC_BASE_URL . '?action=' . rawurlencode($action)
            . '&' . $params
            . '&project=' . (int)$projectId
            . '&force_refresh=1'
            . '&internal_key=' . rawurlencode(SYNC_INTERNAL_KEY);

        $t0  = microtime(true);
        $res = sync_http_get($url);
        $sec = round(microtime(true) - $t0, 1);

        if ($res['ok']) {
            sync_log_write($entity, 'ok', null);
            $len = strlen($res['body'] ?? '');
            echo "  OK  {$action} ({$sec}s, {$len} bytes)\n";
        } else {
            $err = $res['error'] ?? 'unknown';
            sync_log_write($entity, 'error', $err);
            echo "  ERR {$action} ({$sec}s): {$err}\n";
        }
    }
}

echo "\nSync done " . date('Y-m-d H:i:s') . "\n";
