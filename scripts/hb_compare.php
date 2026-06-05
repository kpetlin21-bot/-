<?php
declare(strict_types=1);

/**
 * Сравнение эталона tb_house_breakdown с новой версией.
 * php scripts/hb_compare.php [--save-baseline] [--compare]
 */
$root = dirname(__DIR__);
require_once $root . '/proxy.php';

$tz    = new DateTimeZone('Europe/Moscow');
$now   = new DateTime('now', $tz);
$today = $now->format('Y-m-d');
$monday = (clone $now)->modify('-' . ((int)$now->format('N') - 1) . ' day');
$weekRange = $monday->format('Y-m-d') . ',' . $today;

$outDir = $root . '/scripts/hb_baseline';
if (!is_dir($outDir)) {
    mkdir($outDir, 0775, true);
}

function hb_run(string $label, string $rawDate): array
{
    $t0 = microtime(true);
    $data = tb_house_breakdown($rawDate);
    $ms = round((microtime(true) - $t0) * 1000);
    fwrite(STDOUT, sprintf("%s wall-time: %d ms\n", $label, $ms));
    return $data;
}

$save = in_array('--save-baseline', $argv, true);
$compare = in_array('--compare', $argv, true) || (!$save && !in_array('--save-new', $argv, true));

if ($save) {
    $cases = [
        'today' => $today,
        'week'  => $weekRange,
    ];
    foreach ($cases as $name => $raw) {
        $data = hb_run('baseline ' . $name, $raw);
        $path = $outDir . '/baseline_' . $name . '.json';
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo "saved {$path}\n";
    }
    exit(0);
}

if (in_array('--save-new', $argv, true)) {
    foreach (['today' => $today, 'week' => $weekRange] as $name => $raw) {
        $data = hb_run('new ' . $name, $raw);
        $path = $outDir . '/new_' . $name . '.json';
        file_put_contents($path, json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        echo "saved {$path}\n";
    }
    exit(0);
}

if ($compare) {
    $ok = true;
    foreach (['today', 'week'] as $name) {
        $basePath = $outDir . '/baseline_' . $name . '.json';
        $newPath  = $outDir . '/new_' . $name . '.json';
        if (!is_file($basePath) || !is_file($newPath)) {
            echo "missing files for {$name}\n";
            $ok = false;
            continue;
        }
        $a = json_decode(file_get_contents($basePath), true);
        $b = json_decode(file_get_contents($newPath), true);
        if ($a === $b) {
            echo "{$name}: IDENTICAL\n";
        } else {
            echo "{$name}: DIFF\n";
            $ok = false;
        }
    }
    exit($ok ? 0 : 1);
}

echo "Usage: php scripts/hb_compare.php --save-baseline | --save-new | --compare\n";
