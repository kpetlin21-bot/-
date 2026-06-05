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

function warm_cache_run(): void
{
    $tz    = new DateTimeZone('Europe/Moscow');
    $today = (new DateTime('now', $tz))->format('Y-m-d');
    $base  = rtrim(getenv('PROXY_BASE_URL') ?: 'https://api.cleansyst.ru/proxy.php', '?');
    $sec   = rawurlencode(WARM_SECRET);

    $jobs = [
        ['label' => 'dashboard_today', 'query' => "action=dashboard&date=" . rawurlencode($today) . "&_warm={$sec}", 'timeout' => 300],
        ['label' => 'history_30', 'query' => "action=history&days=30&_warm={$sec}", 'timeout' => 300],
        ['label' => 'house_breakdown_today', 'query' => "action=house_breakdown&date=" . rawurlencode($today) . "&_warm={$sec}", 'timeout' => 600],
    ];

    $report = [];
    foreach ($jobs as $job) {
        $t0   = microtime(true);
        $url  = $base . (strpos($base, '?') !== false ? '&' : '?') . $job['query'];
        $res  = warm_cache_http_get($url, $job['timeout']);
        $ms   = round((microtime(true) - $t0) * 1000);
        $ok   = $res['status'] === 200;
        $json = $ok ? json_decode($res['body'], true) : null;
        $report[] = [
            'label'  => $job['label'],
            'status' => $res['status'],
            'ok'     => $ok && is_array($json) && !empty($json['ok']),
            'ms'     => $ms,
        ];
        fwrite(STDOUT, sprintf(
            "%s HTTP %d %s %d ms\n",
            $job['label'],
            $res['status'],
            ($ok && is_array($json) && !empty($json['ok'])) ? 'OK' : 'FAIL',
            $ms
        ));
    }

    fwrite(STDOUT, json_encode(['warmed' => $report, 'date' => $today], JSON_UNESCAPED_UNICODE) . "\n");
}

if (warm_cache_is_cli()) {
    warm_cache_run();
}
