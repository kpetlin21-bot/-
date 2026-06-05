<?php
declare(strict_types=1);

/**
 * Секрет прогрева (тот же проверяется в proxy.php через ?_warm=).
 * Cron: php /path/to/warm_cache.php
 */
if (!defined('WARM_SECRET')) {
    define('WARM_SECRET', 'cleansyst_warm_2026');
}

function warm_cache_is_cli(): bool
{
    if (php_sapi_name() !== 'cli' || !isset($_SERVER['argv'][0])) {
        return false;
    }
    $self = realpath(__FILE__);
    $arg0 = realpath($_SERVER['argv'][0]);
    return $self !== false && $arg0 !== false && $self === $arg0;
}

function warm_cache_http_get(string $url, int $timeoutSec): array
{
    $ctx = stream_context_create(['http' => [
        'method'        => 'GET',
        'timeout'       => $timeoutSec,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    $status = 0;
    if (isset($http_response_header[0]) && preg_match('/\s(\d{3})\s/', $http_response_header[0], $m)) {
        $status = (int)$m[1];
    }
    return ['status' => $status, 'body' => $body !== false ? $body : ''];
}

function warm_cache_label_from_url(string $url): string
{
    $q = [];
    parse_str((string)(parse_url($url, PHP_URL_QUERY) ?? ''), $q);
    $action = (string)($q['action'] ?? '?');
    if ($action === 'history') {
        return bd_key('history', isset($q['days']) ? (string)$q['days'] : null);
    }
    $date = isset($q['date']) ? (string)$q['date'] : null;
    if ($date === '') {
        $date = null;
    }
    return bd_key($action, $date);
}

function warm_cache_run(): void
{
    require_once __DIR__ . '/cache.php';

    $tz    = new DateTimeZone('Europe/Moscow');
    $now   = new DateTime('now', $tz);
    $today = $now->format('Y-m-d');

    $BASE = rtrim(getenv('PROXY_BASE_URL') ?: 'https://api.cleansyst.ru/proxy.php', '?');
    $WARM_SECRET = rawurlencode(WARM_SECRET);

    // Диапазоны как в index.html getDateParam (неделя / месяц)
    $monday = (clone $now);
    $monday->modify('-' . ((int)$monday->format('N') - 1) . ' day');
    $weekRange = $monday->format('Y-m-d') . ',' . $today;

    $firstOfMonth = (clone $now)->modify('first day of this month')->format('Y-m-d');
    $monthRange = $firstOfMonth . ',' . $today;

    $targets = [
        "{$BASE}?action=dashboard&date={$today}&_warm={$WARM_SECRET}",
        "{$BASE}?action=dashboard&_warm={$WARM_SECRET}",            // dashboard_auto
        "{$BASE}?action=history&days=30&_warm={$WARM_SECRET}",
        // "{$BASE}?action=history&days=7&_warm={$WARM_SECRET}",     // если появится в UI
        "{$BASE}?action=house_breakdown&date={$today}&_warm={$WARM_SECRET}",
        "{$BASE}?action=house_breakdown&date=" . rawurlencode($weekRange) . "&_warm={$WARM_SECRET}",
        "{$BASE}?action=house_breakdown&date=" . rawurlencode($monthRange) . "&_warm={$WARM_SECRET}",
    ];

    $report = [];
    foreach ($targets as $url) {
        $label   = warm_cache_label_from_url($url);
        $timeout = (strpos($url, 'house_breakdown') !== false) ? 600 : 300;
        $t0      = microtime(true);
        $res     = warm_cache_http_get($url, $timeout);
        $ms      = round((microtime(true) - $t0) * 1000);
        $ok      = $res['status'] === 200;
        $json    = $ok ? json_decode($res['body'], true) : null;
        $success = $ok && is_array($json) && !empty($json['ok']);
        $report[] = [
            'label'  => $label,
            'status' => $res['status'],
            'ok'     => $success,
            'ms'     => $ms,
        ];
        fwrite(STDOUT, sprintf(
            "%s HTTP %d %s %d ms\n",
            $label,
            $res['status'],
            $success ? 'OK' : 'FAIL',
            $ms
        ));
    }

    fwrite(STDOUT, json_encode(['warmed' => $report, 'date' => $today], JSON_UNESCAPED_UNICODE) . "\n");
}

if (warm_cache_is_cli()) {
    warm_cache_run();
}
