<?php
declare(strict_types=1);

/**
 * diag.php — диагностика производительности дашборда api.cleansyst.ru
 *
 * Кладётся в ~/www/api.cleansyst.ru/diag.php
 * Открывается так:  https://api.cleansyst.ru/diag.php?key=ПОМЕНЯЙ_МЕНЯ
 *
 * Скрипт НИЧЕГО не меняет, только читает и замеряет время.
 * После диагностики файл удалить (или сменить ключ), чтобы не висел наружу.
 */

// ───────────────────────────────────────────────────────────
//  НАСТРОЙКИ (из checklist/api/config.php и index.html)
// ───────────────────────────────────────────────────────────
$SECRET = 'ПОМЕНЯЙ_МЕНЯ';          // секрет для доступа к скрипту — сменить перед выкладкой

$DB_HOST = 'p837136.mysql.ihc.ru';
$DB_NAME = 'p837136_dashbrd';
$DB_USER = 'p837136_dashbrd';
$DB_PASS = 'Dashboard123';

// ThroneBaron project_id дашборда «Новое Колпино» (proxy.php TB_PROJECT)
$TB_PROJECT = 2;

// Запросы proxy.php, которые index.html делает при загрузке (см. loadToday / loadHistory / loadHouseBreakdown)
$PROXY_BASE = 'https://api.cleansyst.ru/proxy.php';

// Таблицы чек-листа (главная index.html в MariaDB не ходит — только ThroneBaron через proxy)
$KEY_TABLES = ['checklists', 'checklist_items', 'checklist_photos'];

// ───────────────────────────────────────────────────────────
//  ЗАЩИТА И ВЫВОД
// ───────────────────────────────────────────────────────────
if (($_GET['key'] ?? '') !== $SECRET) {
    http_response_code(403);
    exit('forbidden');
}
header('Content-Type: text/plain; charset=utf-8');
ini_set('max_execution_time', '300');

function ms(float $start): string
{
    return number_format((microtime(true) - $start) * 1000, 1) . ' ms';
}

$tzMsk = new DateTimeZone('Europe/Moscow');
$today = (new DateTime('now', $tzMsk))->format('Y-m-d');

$proxyCalls = [
    'dashboard (сегодня)' => '?action=dashboard&date=' . rawurlencode($today),
    'history 30 дн.'      => '?action=history&days=30',
    'house_breakdown'     => '?action=house_breakdown&date=' . rawurlencode($today),
];

$totalStart = microtime(true);

// ───────────────────────────────────────────────────────────
//  1. PHP / ОКРУЖЕНИЕ
// ───────────────────────────────────────────────────────────
echo "================= PHP / ОКРУЖЕНИЕ =================\n";
echo "PHP version        : " . PHP_VERSION . "\n";
echo "memory_limit       : " . ini_get('memory_limit') . "\n";
echo "max_execution_time : " . ini_get('max_execution_time') . "\n";
$opcache = function_exists('opcache_get_status') ? @opcache_get_status() : null;
echo "OPcache            : " . ($opcache && !empty($opcache['opcache_enabled']) ? 'ON' : 'OFF (!)') . "\n";
echo "curl               : " . (function_exists('curl_init') ? 'yes' : 'НЕТ (!)') . "\n";
echo "date (MSK)         : {$today}\n";
echo "TB project_id      : {$TB_PROJECT}\n";
echo "sys_get_temp_dir   : " . sys_get_temp_dir() . "\n";
echo "\n";

// ───────────────────────────────────────────────────────────
//  2. БАЗА ДАННЫХ (чек-лист)
// ───────────────────────────────────────────────────────────
echo "================= MariaDB (чек-лист) =================\n";
$dbTotal = 0.0;
$t = microtime(true);
$mysqli = @new mysqli($DB_HOST, $DB_USER, $DB_PASS, $DB_NAME);
if ($mysqli->connect_errno) {
    echo "ПОДКЛЮЧЕНИЕ НЕ УДАЛОСЬ: {$mysqli->connect_error}\n\n";
} else {
    $connTime = (microtime(true) - $t) * 1000;
    $dbTotal += $connTime;
    echo "connect            : " . number_format($connTime, 1) . " ms\n\n";

    echo "-- таблицы (по объёму) --\n";
    $sql = "SELECT table_name, table_rows,
                   ROUND((data_length + index_length) / 1024 / 1024, 2) AS mb
            FROM information_schema.tables
            WHERE table_schema = ?
            ORDER BY (data_length + index_length) DESC";
    $stmt = $mysqli->prepare($sql);
    $stmt->bind_param('s', $DB_NAME);
    $stmt->execute();
    $res = $stmt->get_result();
    while ($row = $res->fetch_assoc()) {
        printf(
            "  %-32s %10s rows  %8s MB\n",
            $row['table_name'],
            (string)$row['table_rows'],
            (string)$row['mb']
        );
    }
    echo "\n";

    $queries = [];
    foreach ($KEY_TABLES as $tbl) {
        $queries["COUNT {$tbl}"] = "SELECT COUNT(*) AS c FROM `{$tbl}`";
        $queries["SELECT {$tbl} LIMIT 1000"] = "SELECT * FROM `{$tbl}` LIMIT 1000";
    }

    echo "-- время запросов (ключевые таблицы чек-листа) --\n";
    foreach ($queries as $label => $q) {
        $qs = microtime(true);
        $qr = $mysqli->query($q);
        $qt = (microtime(true) - $qs) * 1000;
        $dbTotal += $qt;
        $rows = '-';
        if ($qr instanceof mysqli_result) {
            if (stripos($q, 'COUNT') !== false && ($row = $qr->fetch_assoc())) {
                $rows = (string)($row['c'] ?? $qr->num_rows);
            } else {
                $rows = (string)$qr->num_rows;
            }
            $qr->free();
        }
        printf("  %-40s %9s ms   (%s rows)\n", $label, number_format($qt, 1), $rows);
    }
    echo "\n";
    $mysqli->close();
}

// ───────────────────────────────────────────────────────────
//  3. ThroneBaron через proxy.php (как index.html при загрузке)
// ───────────────────────────────────────────────────────────
echo "================= ThroneBaron / proxy.php =================\n";
echo "(те же action, что loadToday + loadHistory + loadHouseBreakdown)\n\n";
$apiTotal = 0.0;
$slowest = ['label' => null, 'ms' => 0.0];

foreach ($proxyCalls as $label => $query) {
    $url = $PROXY_BASE . $query;
    $ch = curl_init($url);
    curl_setopt_array($ch, [
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 300,
        CURLOPT_SSL_VERIFYPEER => true,
        CURLOPT_NOBODY         => false,
    ]);
    $qs   = microtime(true);
    $body = curl_exec($ch);
    $qt   = (microtime(true) - $qs) * 1000;
    $http = curl_getinfo($ch, CURLINFO_HTTP_CODE);
    $err  = curl_error($ch);
    curl_close($ch);

    $apiTotal += $qt;
    if ($qt > $slowest['ms']) {
        $slowest = ['label' => $label, 'ms' => $qt];
    }

    $size = $body !== false ? strlen((string)$body) : 0;
    $preview = '';
    if ($body !== false && $http === 200) {
        $j = json_decode((string)$body, true);
        if (is_array($j)) {
            if (isset($j['tasks']['total'])) {
                $preview = sprintf(
                    'tasks=%d done=%d rate=%s%%',
                    (int)($j['tasks']['total'] ?? 0),
                    (int)($j['tasks']['done'] ?? 0),
                    (string)($j['tasks']['rate'] ?? '?')
                );
            } elseif (isset($j['history']) && is_array($j['history'])) {
                $preview = 'history days=' . count($j['history']);
            } elseif (isset($j['houses']) && is_array($j['houses'])) {
                $preview = 'houses=' . count($j['houses']);
            } elseif (isset($j['error'])) {
                $preview = 'error: ' . (string)$j['error'];
            }
        }
    }

    printf(
        "  %-22s  HTTP %-3s  %9s ms  %7d bytes  %s\n",
        $label,
        (string)$http,
        number_format($qt, 1),
        $size,
        $err ? "ERR: {$err}" : ($preview !== '' ? $preview : '')
    );
}
echo "\n";

// ───────────────────────────────────────────────────────────
//  4. ИТОГ — кто виноват
// ───────────────────────────────────────────────────────────
echo "================= ИТОГ =================\n";
printf("Суммарно БД        : %s ms\n", number_format($dbTotal, 1));
printf("Суммарно API       : %s ms\n", number_format($apiTotal, 1));
printf(
    "Самый медленный    : %s (%s ms)\n",
    (string)$slowest['label'],
    number_format($slowest['ms'], 1)
);
printf("Всего по скрипту   : %s\n", ms($totalStart));
echo "\n";

if ($apiTotal > max($dbTotal * 3, 500)) {
    echo ">>> ВЕРДИКТ: тормозит ThroneBaron/proxy.php (dashboard, history, house_breakdown).\n";
    echo "    Главная страница на каждой загрузке тянет API; кеш в " . sys_get_temp_dir() . " (/tb_*.json).\n";
    echo "    Проверь warmup_cache (cron) и не грузи house_breakdown без необходимости.\n";
} elseif ($dbTotal > 1000) {
    echo ">>> ВЕРДИКТ: тормозит MariaDB (чек-лист). Смотри индексы и тяжёлые SELECT выше.\n";
} else {
    echo ">>> ВЕРДИКТ: бэкенд быстрый. Затык, скорее всего, на фронте\n";
    echo "    (Chart.js, SVG-карта, тяжёлые ассеты). Проверь вкладку Network в DevTools.\n";
}
