<?php
declare(strict_types=1);

/**
 * Валидация tb_house_breakdown.
 *
 * php scripts/hb_compare.php --save-baseline-week   # legacy e879ecd, только закрытая неделя
 * php scripts/hb_compare.php --compare               # self-consistency день + неделя vs baseline
 *
 * Exit: 0 = green/merge | 1 = real diff/investigate | 2 = throttled/rerun
 */
$root = dirname(__DIR__);

$tz = new DateTimeZone('Europe/Moscow');
$now = new DateTime('now', $tz);
$yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');

$mondayThisWeek = (clone $now);
$mondayThisWeek->modify('-' . ((int)$mondayThisWeek->format('N') - 1) . ' day');
$lastSunday = (clone $mondayThisWeek)->modify('-1 day');
$lastMonday = (clone $lastSunday)->modify('-6 days');
$weekRange = $lastMonday->format('Y-m-d') . ',' . $lastSunday->format('Y-m-d');

const HB_LEGACY_COMMIT = 'e879ecd';

$outDir = $root . '/scripts/hb_baseline';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

function hb_run_isolated(string $commit, string $rawDate): array
{
    global $root;
    $cmd = sprintf(
        'php %s/scripts/hb_isolated_run.php %s %s 2>/dev/null',
        escapeshellarg($root),
        escapeshellarg($commit),
        escapeshellarg($rawDate)
    );
    $json = shell_exec($cmd);
    if ($json === null || $json === '') {
        throw new RuntimeException("hb_isolated_run failed for commit={$commit} date={$rawDate}");
    }
    $data = json_decode($json, true);
    if (!is_array($data)) {
        throw new RuntimeException('invalid JSON from isolated run: ' . substr((string)$json, 0, 200));
    }
    return $data;
}

function hb_run_current(string $label, string $rawDate, int $conc): array
{
    global $root;
    $_SERVER['REQUEST_METHOD'] = 'GET';
    if (!defined('TB_PROXY_CLI_FUNCTIONS_ONLY')) {
        define('TB_PROXY_CLI_FUNCTIONS_ONLY', true);
    }

    putenv('TB_MULTI_CONCURRENCY=' . $conc);

    require_once $root . '/cache.php';
    require_once $root . '/warm_cache.php';
    require_once $root . '/tb_multi.php';
    require_once $root . '/proxy.php';

    $tz = new DateTimeZone('Europe/Moscow');
    $GLOBALS['tz_msk'] = $tz;
    $GLOBALS['today']  = (new DateTime('now', $tz))->format('Y-m-d');

    $t0 = microtime(true);
    $data = tb_house_breakdown($rawDate);
    $ms = round((microtime(true) - $t0) * 1000);
    $locInc = (int)($data['locIncomplete'] ?? 0);
    $partial = !empty($data['partial']);
    fwrite(STDOUT, sprintf(
        "%s [%s] conc=%d wall-time: %d ms partial=%s locIncomplete=%d\n",
        $label,
        $rawDate,
        $conc,
        $ms,
        $partial ? 'true' : 'false',
        $locInc
    ));

    return $data;
}

/** Сравнение только по полям домов (без partial/locIncomplete/cached). */
function hb_houses_only(array $data): array
{
    return $data['houses'] ?? [];
}

function hb_diff_houses(array $a, array $b): string
{
    $ha = hb_houses_only($a);
    $hb = hb_houses_only($b);
    if ($ha === $hb) {
        return 'IDENTICAL';
    }
    $lines = ['DIFF'];
    $idsA = array_column($ha, 'id');
    $idsB = array_column($hb, 'id');
    $onlyA = array_diff($idsA, $idsB);
    $onlyB = array_diff($idsB, $idsA);
    if ($onlyA) {
        $lines[] = '  only A ids: ' . implode(', ', $onlyA);
    }
    if ($onlyB) {
        $lines[] = '  only B ids: ' . implode(', ', $onlyB);
    }
    foreach ($ha as $i => $rowA) {
        $id = $rowA['id'] ?? $i;
        $rowB = null;
        foreach ($hb as $rb) {
            if (($rb['id'] ?? '') === $id) {
                $rowB = $rb;
                break;
            }
        }
        if ($rowB !== null && $rowA !== $rowB) {
            $lines[] = "  house {$id}: A done={$rowA['done']} B done={$rowB['done']}";
        }
    }
    return implode("\n", $lines);
}

function hb_diff_full(array $a, array $b): string
{
    if ($a === $b) {
        return 'IDENTICAL';
    }
    $houseCmp = hb_diff_houses($a, $b);
    if ($houseCmp === 'IDENTICAL') {
        return 'DIFF (meta only)';
    }
    return $houseCmp;
}

function hb_is_partial(array $data): bool
{
    return !empty($data['partial']);
}

/** @param array<int,array> $liveRuns */
function hb_loc_incomplete_total(array $liveRuns): int
{
    $n = 0;
    foreach ($liveRuns as $r) {
        $n += (int)($r['locIncomplete'] ?? 0);
    }
    return $n;
}

/**
 * Вердикт кейса: partial у любого live-участника → INCONCLUSIVE (не DIFF/IDENTICAL).
 *
 * @param array<int,array> $liveRuns прогоны с locIncomplete (baseline без partial = complete)
 */
function hb_case_verdict(string $housesDiff, bool $anyPartial, array $liveRuns): string
{
    if ($anyPartial) {
        return 'INCONCLUSIVE (locIncomplete=' . hb_loc_incomplete_total($liveRuns)
            . ', throttled, rerun)';
    }
    return $housesDiff === 'IDENTICAL' ? 'IDENTICAL' : 'DIFF';
}

function hb_single_run_verdict(array $data): string
{
    if (hb_is_partial($data)) {
        return 'INCONCLUSIVE (locIncomplete=' . (int)($data['locIncomplete'] ?? 0)
            . ', throttled, rerun)';
    }
    return 'IDENTICAL';
}

function hb_verdict_bucket(string $verdict): string
{
    if (str_starts_with($verdict, 'INCONCLUSIVE')) {
        return 'INCONCLUSIVE';
    }
    return $verdict;
}

$saveBaselineWeek = in_array('--save-baseline-week', $argv, true);
$compare = in_array('--compare', $argv, true);

if ($saveBaselineWeek) {
    echo "Legacy week baseline commit: " . HB_LEGACY_COMMIT . "\n";
    echo "Closed week: {$weekRange}\n\n";
    $t0 = microtime(true);
    $data = hb_run_isolated(HB_LEGACY_COMMIT, $weekRange);
    $ms = round((microtime(true) - $t0) * 1000);
    fwrite(STDOUT, "baseline week wall-time: {$ms} ms\n");
    $path = $outDir . '/baseline_week.json';
    file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
    echo "saved {$path}\n";
    exit(0);
}

if ($compare) {
    $hasDiff = false;
    $hasInconclusive = false;

    echo "=== yesterday self-consistency (conc=1 vs conc=10) ===\n";
    echo "date: {$yesterday}\n\n";

    $d1 = hb_run_current('yesterday conc=1', $yesterday, 1);
    sleep(5);
    $d10 = hb_run_current('yesterday conc=10', $yesterday, 10);

    $yCmp = hb_diff_houses($d1, $d10);
    $yPartial = hb_is_partial($d1) || hb_is_partial($d10);
    $yVerdict = hb_case_verdict($yCmp, $yPartial, [$d1, $d10]);
    echo "yesterday: {$yVerdict}\n";
    if (hb_verdict_bucket($yVerdict) === 'DIFF') {
        echo $yCmp . "\n";
    }
    if (hb_verdict_bucket($yVerdict) === 'DIFF') {
        $hasDiff = true;
    } elseif (hb_verdict_bucket($yVerdict) === 'INCONCLUSIVE') {
        $hasInconclusive = true;
    }

    echo "\n=== closed week vs legacy baseline ===\n";
    echo "range: {$weekRange}\n\n";

    $basePath = $outDir . '/baseline_week.json';
    if (!is_file($basePath)) {
        echo "week: DIFF (missing {$basePath}, run --save-baseline-week)\n";
        $hasDiff = true;
    } else {
        sleep(5);
        $weekNew = hb_run_current('week default conc=4', $weekRange, 4);
        $baseline = json_decode(file_get_contents($basePath), true);
        $wCmp = hb_diff_houses($baseline, $weekNew);
        $wPartial = hb_is_partial($weekNew);
        $wVerdict = hb_case_verdict($wCmp, $wPartial, [$weekNew]);
        echo "week: {$wVerdict}\n";
        if (hb_verdict_bucket($wVerdict) === 'DIFF') {
            echo $wCmp . "\n";
        }
        if (hb_verdict_bucket($wVerdict) === 'DIFF') {
            $hasDiff = true;
        } elseif (hb_verdict_bucket($wVerdict) === 'INCONCLUSIVE') {
            $hasInconclusive = true;
        }
    }

    echo "\n=== warm-day check (complete, default conc=4) ===\n";
    sleep(5);
    $warmDay = hb_run_current('warm-day', $yesterday, 4);
    $warmVerdict = hb_single_run_verdict($warmDay);
    echo "warm-day: {$warmVerdict}\n";
    if (hb_verdict_bucket($warmVerdict) === 'DIFF') {
        $hasDiff = true;
    } elseif (hb_verdict_bucket($warmVerdict) === 'INCONCLUSIVE') {
        $hasInconclusive = true;
    }

    echo "\nexit 0 = green/merge | 1 = real diff/investigate | 2 = throttled/rerun\n";

    if ($hasDiff) {
        exit(1);
    }
    if ($hasInconclusive) {
        exit(2);
    }
    exit(0);
}

echo "Usage:\n";
echo "  php scripts/hb_compare.php --save-baseline-week\n";
echo "  php scripts/hb_compare.php --compare\n";
