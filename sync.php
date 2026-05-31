<?php
/**
 * Синхронизация ThroneBaron → api_cache.
 *
 * CLI:
 *   php sync.php --mode=fast   (по умолчанию) — dashboard, shifts, tasks
 *   php sync.php --mode=full   — fast + house_breakdown
 *
 * HTTP (cron):
 *   sync.php?mode=fast&secret=cleansyst2026
 *   sync.php?mode=full&secret=cleansyst2026
 */
require_once __DIR__ . '/cache_db.php';
require_once __DIR__ . '/projects_lib.php';

define('SYNC_SECRET', 'cleansyst2026');
define('SYNC_BASE_URL', 'https://api.cleansyst.ru/proxy.php');

/** Все ЖК ThroneBaron (ID 8 отсутствует в API) */
$projects = [1, 2, 3, 4, 5, 6, 7, 9, 10, 11, 12, 13, 14, 15];

$project_slugs = [
    1  => 'astrid',
    2  => 'novoe-kolpino',
    3  => 'zhivi-v-rybatskom-1',
    4  => 'kurortny',
    5  => 'zhivi-v-rybatskom-2-3',
    6  => 'kosmonavtov-11',
    7  => 'iset-park',
    9  => 'utes',
    10 => 'aston-dvizhenie',
    11 => 'aston-reforma',
    12 => 'noksa-park',
    13 => 'tvoya-privilegiya',
    14 => 'mily-dom',
    15 => 'river-park',
];

$PROJECT_NAMES = projects_names_by_id();

$FAST_ENDPOINTS = [
    ['dashboard', 'date=today'],
    ['shifts',    'date=today'],
    ['tasks',     'date=today'],
];

$FULL_EXTRA_ENDPOINTS = [
    ['house_breakdown', 'date=today'],
];

function sync_is_cli(): bool {
    return php_sapi_name() === 'cli';
}

function sync_resolve_mode(): string {
    if (sync_is_cli()) {
        global $argv;
        foreach ($argv ?? [] as $arg) {
            if (strpos($arg, '--mode=') === 0) {
                $m = strtolower(substr($arg, 7));
                return in_array($m, ['fast', 'full'], true) ? $m : 'fast';
            }
        }
        return 'fast';
    }
    $m = strtolower((string)($_GET['mode'] ?? 'fast'));
    return in_array($m, ['fast', 'full'], true) ? $m : 'fast';
}

function sync_auth_ok(): bool {
    if (sync_is_cli()) {
        return true;
    }
    return (($_GET['secret'] ?? '') === SYNC_SECRET);
}

function sync_endpoints_for_mode(string $mode): array {
    global $FAST_ENDPOINTS, $FULL_EXTRA_ENDPOINTS;
    if ($mode === 'full') {
        return array_merge($FAST_ENDPOINTS, $FULL_EXTRA_ENDPOINTS);
    }
    return $FAST_ENDPOINTS;
}

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

function sync_timeout_for_action(string $action): int {
    return $action === 'house_breakdown' ? 600 : 120;
}

// --- HTTP: secret обязателен
if (!sync_is_cli()) {
    if (!sync_auth_ok()) {
        http_response_code(403);
        header('Content-Type: text/plain; charset=utf-8');
        echo "forbidden\n";
        exit(1);
    }
    header('Content-Type: text/plain; charset=utf-8');
}

$mode      = sync_resolve_mode();
$endpoints = sync_endpoints_for_mode($mode);
$tStart    = microtime(true);

echo "Sync mode={$mode} start " . date('Y-m-d H:i:s') . " MSK\n";
echo "Endpoints: " . implode(', ', array_column($endpoints, 0)) . "\n";

$summary = [];

foreach ($projects as $projectId) {
    $tProj   = microtime(true);
    $errCnt  = 0;
    echo "\n=== project_id={$projectId} ===\n";
    foreach ($endpoints as [$action, $params]) {
        $entity = "p{$projectId}:{$action}:{$mode}";
        $url    = SYNC_BASE_URL . '?action=' . rawurlencode($action)
            . '&' . $params
            . '&project=' . (int)$projectId
            . '&force_refresh=1'
            . '&internal_key=' . rawurlencode(SYNC_SECRET);

        $t0  = microtime(true);
        $res = sync_http_get($url, sync_timeout_for_action($action));
        $sec = round(microtime(true) - $t0, 1);

        if ($res['ok']) {
            sync_log_write($entity, 'ok', null);
            $len = strlen($res['body'] ?? '');
            echo "  OK  {$action} ({$sec}s, {$len} bytes)\n";
        } else {
            $err = $res['error'] ?? 'unknown';
            sync_log_write($entity, 'error', $err);
            echo "  ERR {$action} ({$sec}s): {$err}\n";
            $errCnt++;
        }
    }
    $summary[] = [
        'id'     => $projectId,
        'name'   => $PROJECT_NAMES[$projectId] ?? ('project ' . $projectId),
        'sec'    => round(microtime(true) - $tProj, 1),
        'status' => $errCnt > 0 ? 'ERROR' : 'OK',
    ];
}

$totalSec = round(microtime(true) - $tStart, 1);
echo "\nSync mode={$mode} done " . date('Y-m-d H:i:s') . " (total {$totalSec}s)\n";

echo "\n--- Summary ---\n";
echo str_pad('project_id', 12) . str_pad('название', 36) . str_pad('время', 8) . "статус\n";
foreach ($summary as $row) {
    echo str_pad((string)$row['id'], 12)
        . str_pad($row['name'], 36)
        . str_pad($row['sec'] . 's', 8)
        . $row['status'] . "\n";
}
