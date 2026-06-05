<?php
declare(strict_types=1);

/**
 * Запуск tb_house_breakdown из указанного коммита proxy.php (отдельный процесс).
 * php scripts/hb_isolated_run.php e879ecd 2026-06-04
 */
if ($argc < 3) {
    fwrite(STDERR, "Usage: php scripts/hb_isolated_run.php <git-commit> <rawDate>\n");
    exit(2);
}

$commit  = $argv[1];
$rawDate = $argv[2];
$root    = dirname(__DIR__);

if (!preg_match('/^[a-f0-9]{7,40}$/i', $commit)) {
    fwrite(STDERR, "Invalid commit\n");
    exit(2);
}

$proxyPath = $root . '/proxy.php';
$backup    = $root . '/.proxy.php.hb_backup';
$exported  = shell_exec('git -C ' . escapeshellarg($root) . ' show ' . escapeshellarg($commit) . ':proxy.php 2>/dev/null');
if ($exported === null || $exported === '') {
    fwrite(STDERR, "git show failed for commit {$commit}\n");
    exit(1);
}

if (!is_file($backup) && !copy($proxyPath, $backup)) {
    fwrite(STDERR, "backup proxy.php failed\n");
    exit(1);
}

file_put_contents($proxyPath, $exported);

try {
    chdir($root);
    require $proxyPath;
    echo json_encode(tb_house_breakdown($rawDate), JSON_UNESCAPED_UNICODE);
} finally {
    if (is_file($backup)) {
        copy($backup, $proxyPath);
        unlink($backup);
    }
}
