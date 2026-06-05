<?php
declare(strict_types=1);

/**
 * Сравнение legacy (e879ecd) и нового tb_house_breakdown.
 *
 * php scripts/hb_compare.php --save-baseline   # legacy, вчера + неделя
 * php scripts/hb_compare.php --save-new        # текущий код
 * php scripts/hb_compare.php --compare
 */
$root = dirname(__DIR__);

$tz = new DateTimeZone('Europe/Moscow');
$now = new DateTime('now', $tz);
$yesterday = (clone $now)->modify('-1 day')->format('Y-m-d');
$today = $now->format('Y-m-d');

$monday = (clone $now);
$monday->modify('-' . ((int)$monday->format('N') - 1) . ' day');
$weekRange = $monday->format('Y-m-d') . ',' . $today;

/** Эталон — код до параллелизации (без tb_multi в house_breakdown). */
const HB_LEGACY_COMMIT = 'e879ecd';

$outDir = $root . '/scripts/hb_baseline';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

$cases = [
    'yesterday' => $yesterday,
    'week'      => $weekRange,
];

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
        throw new RuntimeException("invalid JSON from isolated run: " . substr($json, 0, 200));
    }
    return $data;
}

function hb_run_current(string $label, string $rawDate): array
{
    global $root;
    require_once $root . '/cache.php';
    require_once $root . '/warm_cache.php';
    require_once $root . '/tb_multi.php';
    require_once $root . '/proxy.php';

    $t0 = microtime(true);
    $data = tb_house_breakdown($rawDate);
    $ms = round((microtime(true) - $t0) * 1000);
    fwrite(STDOUT, sprintf("%s [%s] wall-time: %d ms\n", $label, $rawDate, $ms));
    return $data;
}

function hb_diff_summary(array $a, array $b): string
{
    if ($a === $b) {
        return 'IDENTICAL';
    }
    $lines = ['DIFF'];
    if (($a['houses'] ?? null) !== ($b['houses'] ?? null)) {
        $lines[] = '  houses array differs';
        $ha = $a['houses'] ?? [];
        $hb = $b['houses'] ?? [];
        $idsA = array_column($ha, 'id');
        $idsB = array_column($hb, 'id');
        $onlyA = array_diff($idsA, $idsB);
        $onlyB = array_diff($idsB, $idsA);
        if ($onlyA) {
            $lines[] = '  only baseline ids: ' . implode(', ', $onlyA);
        }
        if ($onlyB) {
            $lines[] = '  only new ids: ' . implode(', ', $onlyB);
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
                $lines[] = "  house {$id}: baseline done={$rowA['done']} new done={$rowB['done']}";
            }
        }
    }
    foreach (['date', 'dateFrom', 'dateTo', 'periodRange'] as $k) {
        if (($a[$k] ?? null) !== ($b[$k] ?? null)) {
            $lines[] = "  {$k}: " . json_encode($a[$k] ?? null) . ' vs ' . json_encode($b[$k] ?? null);
        }
    }
    return implode("\n", $lines);
}

$saveBaseline = in_array('--save-baseline', $argv, true);
$saveNew = in_array('--save-new', $argv, true);
$compare = in_array('--compare', $argv, true);

if ($saveBaseline) {
    echo "Legacy commit: " . HB_LEGACY_COMMIT . "\n";
    echo "Single-day case uses yesterday ({$yesterday}), not today\n\n";
    foreach ($cases as $name => $raw) {
        $t0 = microtime(true);
        $data = hb_run_isolated(HB_LEGACY_COMMIT, $raw);
        $ms = round((microtime(true) - $t0) * 1000);
        fwrite(STDOUT, sprintf("baseline %s wall-time: %d ms\n", $name, $ms));
        $path = $outDir . '/baseline_' . $name . '.json';
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo "saved {$path}\n";
    }
    exit(0);
}

if ($saveNew) {
    echo "Single-day case uses yesterday ({$yesterday}), not today\n\n";
    foreach ($cases as $name => $raw) {
        $data = hb_run_current('new ' . $name, $raw);
        $path = $outDir . '/new_' . $name . '.json';
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo "saved {$path}\n";
    }
    exit(0);
}

if ($compare) {
    $ok = true;
    foreach (array_keys($cases) as $name) {
        $basePath = $outDir . '/baseline_' . $name . '.json';
        $newPath  = $outDir . '/new_' . $name . '.json';
        if (!is_file($basePath) || !is_file($newPath)) {
            echo "{$name}: missing files (run --save-baseline and --save-new)\n";
            $ok = false;
            continue;
        }
        $a = json_decode(file_get_contents($basePath), true);
        $b = json_decode(file_get_contents($newPath), true);
        echo "{$name}: " . hb_diff_summary($a, $b) . "\n";
        if ($a !== $b) {
            $ok = false;
        }
    }
    exit($ok ? 0 : 1);
}

echo "Usage:\n";
echo "  php scripts/hb_compare.php --save-baseline\n";
echo "  php scripts/hb_compare.php --save-new\n";
echo "  php scripts/hb_compare.php --compare\n";
