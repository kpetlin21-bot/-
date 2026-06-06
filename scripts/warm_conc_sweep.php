<?php
declare(strict_types=1);

/**
 * Подбор TB_MULTI_CONCURRENCY для полного цикла house_breakdown без 429.
 *
 * Запускать в тихое окно; первый conc стартует с чистого бюджета только если
 * до этого по проекту не было залпов.
 *
 *   php scripts/warm_conc_sweep.php
 *
 * Для каждого conc из [2,3,4,6] прогоняет today → yesterday → закрытая неделя
 * с sleep(3) между датами (как warm_cache между целями).
 * Между итерациями conc — COOLDOWN_SEC, чтобы поминутное окно TB успело сброситься.
 *
 * Выбирайте дефолт TB_MULTI_CONCURRENCY по НИЖНЕЙ границе нуля 429-hits, а не
 * по минимальному wall-time: отсутствие rate limit важнее лишних секунд в cron.
 */
const COOLDOWN_SEC = 90;

$root = dirname(__DIR__);

$_SERVER['REQUEST_METHOD'] = 'GET';
define('TB_PROXY_CLI_FUNCTIONS_ONLY', true);

require_once $root . '/cache.php';
require_once $root . '/warm_cache.php';
require_once $root . '/tb_multi.php';
require_once $root . '/proxy.php';

$tz = new DateTimeZone('Europe/Moscow');
$now = new DateTime('now', $tz);
$GLOBALS['tz_msk'] = $tz;
$GLOBALS['today']  = $now->format('Y-m-d');

$today     = $GLOBALS['today'];
$yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');

$mondayThisWeek = (clone $now);
$mondayThisWeek->modify('-' . ((int)$mondayThisWeek->format('N') - 1) . ' day');
$lastSunday = (clone $mondayThisWeek)->modify('-1 day');
$lastMonday = (clone $lastSunday)->modify('-6 days');
$weekClosed = $lastMonday->format('Y-m-d') . ',' . $lastSunday->format('Y-m-d');

$cycle = [
    'today'     => $today,
    'yesterday' => $yesterday,
    'week'      => $weekClosed,
];

$concs = [2, 3, 4, 6];

echo "house_breakdown sweep: today={$today} yesterday={$yesterday} week={$weekClosed}\n";
echo 'cooldown between conc: ' . COOLDOWN_SEC . "s\n\n";

$sweepRows = [];

foreach ($concs as $i => $conc) {
    if ($i > 0) {
        echo sprintf("cooldown %ds before conc=%d…\n", COOLDOWN_SEC, $conc);
        sleep(COOLDOWN_SEC);
    }

    putenv('TB_MULTI_CONCURRENCY=' . $conc);
    tb_rate_limit_reset();

    $t0 = microtime(true);
    $step = 0;
    foreach ($cycle as $label => $rawDate) {
        if ($step > 0) {
            sleep(3);
        }
        $stepT0 = microtime(true);
        $data = tb_house_breakdown($rawDate);
        $stepMs = round((microtime(true) - $stepT0) * 1000);
        $partial = !empty($data['partial']);
        echo sprintf(
            "  conc=%d %s [%s] %d ms partial=%s locIncomplete=%d\n",
            $conc,
            $label,
            $rawDate,
            $stepMs,
            $partial ? 'true' : 'false',
            (int)($data['locIncomplete'] ?? 0)
        );
        $step++;
    }

    $totalMs = round((microtime(true) - $t0) * 1000);
    $hits429 = tb_rate_limit_hits();
    $sweepRows[] = ['conc' => $conc, 'hits' => $hits429, 'ms' => $totalMs];
    echo "\n";
}

echo "| conc | 429-hits | wall-time(ms) |\n";
echo "|------|----------|---------------|\n";
foreach ($sweepRows as $row) {
    echo sprintf("| %4d | %8d | %13d |\n", $row['conc'], $row['hits'], $row['ms']);
}

$bestConc = null;
foreach ($sweepRows as $row) {
    if ($row['hits'] === 0) {
        $bestConc = $row['conc'];
    }
}

echo "\n";
if ($bestConc !== null) {
    echo "Рекомендация: TB_MULTI_CONCURRENCY={$bestConc} (максимальный conc с 429-hits=0)\n";
} else {
    echo "нет conc с 0 hits — окно шумное или лимит тугой, перепрогнать тише\n";
}
