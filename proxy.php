<?php
// ============================================================
//  ThroneBaron API Proxy
// ============================================================

define('TB_API_KEY',  'de4oWlnBgj8|IumZlKGCOaIrlAI36RFdcHi4BwDMsU2SpiX9pzXy0aadc85b');
define('TB_PROJECT',  2);
define('TB_BASE_URL', 'https://api.thronebaron.com/v1');

// CORS — разрешаем запросы с любого домена (Netlify, Платрум и др.)
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: GET, POST, OPTIONS');
header('Access-Control-Allow-Headers: Content-Type, Authorization, apikey');
header('Content-Type: application/json; charset=utf-8');

// Preflight OPTIONS запрос от браузера — отвечаем 200 и выходим
if ($_SERVER['REQUEST_METHOD'] === 'OPTIONS') {
    http_response_code(200);
    exit;
}

// ============================================================
//  Вспомогательная функция: выполнить запрос к ThroneBaron
// ============================================================
function tb_get(string $path, array $params = []): array {
    $url = TB_BASE_URL . $path;
    if ($params) {
        $url .= '?' . http_build_query($params);
    }
    $ctx = stream_context_create(['http' => [
        'method'  => 'GET',
        'header'  => implode("\r\n", [
            'Authorization: Api-Key ' . TB_API_KEY,
            'Accept: application/json',
        ]),
        'timeout' => 15,
        'ignore_errors' => true,
    ]]);
    $body = @file_get_contents($url, false, $ctx);
    return $body !== false ? json_decode($body, true) : ['error' => 'Не удалось подключиться'];
}

// ============================================================
//  Преобразовать ISO-время (UTC, c 'Z') в DateTime по Москве.
//  Важно: используем явный часовой пояс, а НЕ ручное прибавление
//  секунд + date(), т.к. date() зависит от часового пояса сервера
//  (на IHC он Europe/Moscow), что давало бы двойной сдвиг.
// ============================================================
function msk_dt(?string $iso): ?DateTime {
    if (empty($iso)) return null;
    try { $d = new DateTime($iso); }
    catch (Exception $e) { return null; }
    $d->setTimezone(new DateTimeZone('Europe/Moscow'));
    return $d;
}

// ============================================================
//  Пройти все страницы пагинации и собрать все записи
// ============================================================
function tb_get_all(string $path, array $params = [], ?bool &$complete = null): array {
    $all = [];
    $params['limit'] = 250;  // максимум на страницу
    $url = TB_BASE_URL . $path . '?' . http_build_query($params);
    $page = 0;
    $complete = true;
    while ($url && $page < 200) {  // до 50 000 задач
        // Каждую страницу пробуем до 6 раз. Повторяем как при сетевых
        // таймаутах, так и при ответе без ключа 'data' (например, троттлинг
        // 429 отдаёт валидный JSON без данных) — иначе выборка молча обрежется.
        $json = null;
        for ($attempt = 0; $attempt < 6; $attempt++) {
            $ctx = stream_context_create(['http' => [
                'method'  => 'GET',
                'header'  => 'Authorization: Api-Key ' . TB_API_KEY . "\r\nAccept: application/json",
                'timeout' => 25,
                'ignore_errors' => true,
            ]]);
            $body = @file_get_contents($url, false, $ctx);
            if ($body !== false) {
                $decoded = json_decode($body, true);
                if (is_array($decoded) && array_key_exists('data', $decoded)) { $json = $decoded; break; }
                // Валидный ответ без 'data' — обычно троттлинг (429): ждём подольше
                sleep(1 + $attempt * 2); // 1,3,5,7,9,11с
            } else {
                // Таймаут/обрыв соединения — уже ждали timeout, короткая пауза
                usleep(500000 * ($attempt + 1));
            }
        }
        if ($json === null) { $complete = false; break; }
        $all = array_merge($all, $json['data']);
        $url = $json['next_page_url'] ?? null;
        $page++;
        usleep(120000); // лёгкая пауза между страницами, чтобы не упираться в лимит API
    }
    // Не дошли до конца (остался next_page_url, но упёрлись в лимит страниц)
    if ($url) $complete = false;
    return $all;
}

// ============================================================
//  Получить ВСЕ локации (с кешем на 1 час в файл)
// ============================================================
function get_all_locations(): array {
    $cacheFile = sys_get_temp_dir() . '/tb_locations_all.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3600) {
        return json_decode(file_get_contents($cacheFile), true) ?: [];
    }
    $all = [];
    $url = TB_BASE_URL . '/locations?limit=250';
    $page = 0;
    while ($url && $page < 20) {
        $ctx = stream_context_create(['http' => [
            'method'  => 'GET',
            'header'  => 'Authorization: Api-Key ' . TB_API_KEY . "\r\nAccept: application/json",
            'timeout' => 15,
            'ignore_errors' => true,
        ]]);
        $body = @file_get_contents($url, false, $ctx);
        if (!$body) break;
        $json = json_decode($body, true);
        if (!isset($json['data'])) break;
        $all = array_merge($all, $json['data']);
        $url = $json['next_page_url'] ?? null;
        $page++;
    }
    if (!empty($all)) file_put_contents($cacheFile, json_encode($all));
    return $all;
}

// ============================================================
//  Карта «дом -> локация двора (ПДТ)».
//  ПДТ (придомовая территория) живёт не внутри дома, а в корне
//  «Территория» как дочерние локации «Двор …». Сопоставляем их
//  с домами по адресу из названия:
//    «Двор 43/3» -> дом 43к3 ; «Двор Б10» -> дом 10 (Балканская).
//  Возвращает [houseId => yardLocationId] и id корня «Территория».
// ============================================================
function get_yard_map(array $allLocs): array {
    $terrId = null;
    foreach ($allLocs as $l) {
        if (($l['project_id'] ?? null) == TB_PROJECT
            && ($l['parent_id'] ?? null) === null
            && strpos($l['name'] ?? '', 'Территори') !== false) {
            $terrId = $l['id']; break;
        }
    }
    $map = ['_terr' => $terrId];
    if ($terrId === null) return $map;
    foreach ($allLocs as $l) {
        if ((int)($l['parent_id'] ?? 0) !== (int)$terrId) continue;
        $part = trim(preg_replace('/^\s*Двор\s*/u', '', $l['name'] ?? '')); // «43/3» или «Б10»
        if ($part === '') continue;
        if (strpos($part, 'Б') === 0) {                 // Балканская: «Б10» -> «10»
            $hid = substr($part, strlen('Б'));
        } else {                                        // «43/3» -> «43к3»
            $hid = str_replace('/', 'к', $part);
        }
        if ($hid !== '') $map[$hid] = $l['id'];
    }
    return $map;
}

$action = $_GET['action'] ?? 'help';
$tz_msk = new DateTimeZone('Europe/Moscow');
$today  = (new DateTime('now', $tz_msk))->format('Y-m-d');

/** Светофор: 100% ok, 80–99% warn, ≤79% crit */
function traffic_status(int $pct, int $total = -1): string {
    if ($total === 0) {
        return 'ok';
    }
    if ($pct >= 100) {
        return 'ok';
    }
    if ($pct >= 80) {
        return 'warn';
    }
    return 'crit';
}

/** today/yesterday → Y-m-d (MSK); иначе дата как есть */
function normalize_report_date(string $date, DateTimeZone $tz, string $todayStr): string {
    $d = strtolower(trim($date));
    if ($d === 'today') {
        return $todayStr;
    }
    if ($d === 'yesterday') {
        return (new DateTime($todayStr, $tz))->modify('-1 day')->format('Y-m-d');
    }
    return $date;
}

/** done/missed/total за диапазон дат по локации (сумма по дням) */
function location_period_counts(string $dateRange, int $locId): array {
    $complete = true;
    $all      = tb_get_all('/reports/tasks', [
        'date'     => $dateRange,
        'project'  => TB_PROJECT,
        'location' => $locId . '*',
    ], $complete);
    $done = 0;
    $missed = 0;
    foreach ($all as $t) {
        $st = $t['status'] ?? '';
        if ($st === 'done') {
            $done++;
        } elseif ($st === 'missed') {
            $missed++;
        }
    }
    $total = $done + $missed;
    return [
        'done'     => $done,
        'missed'   => $missed,
        'total'    => $total,
        'pct'      => $total > 0 ? round($done / $total * 100) : 0,
        'complete' => $complete,
    ];
}

/** МОП за период (неделя/месяц) — сумма по секциям */
function mop_stats_for_house_period(int $houseLocId, string $dateRange, array $allLocs): array {
    $sections = array_values(array_filter($allLocs, function ($l) use ($houseLocId) {
        return (int)$l['parent_id'] === $houseLocId;
    }));

    $mopDone = 0;
    $mopMiss = 0;
    $zones   = [];

    if ($sections) {
        foreach ($sections as $sec) {
            $sid    = (int)$sec['id'];
            $bundle = location_period_counts($dateRange, $sid);
            $d      = $bundle['done'];
            $m      = $bundle['missed'];
            $mopDone += $d;
            $mopMiss += $m;
            $zones[] = [
                'name' => $sec['name'], 'id' => $sid,
                'done' => $d, 'missed' => $m, 'total' => $bundle['total'],
                'pct'  => $bundle['pct'],
            ];
        }
    } else {
        $bundle  = location_period_counts($dateRange, $houseLocId);
        $mopDone = $bundle['done'];
        $mopMiss = $bundle['missed'];
    }

    return ['done' => $mopDone, 'missed' => $mopMiss, 'zones' => $zones];
}

/** ПДТ за период */
function pdt_stats_for_yard_period(?int $yardId, string $dateRange): array {
    if ($yardId === null) {
        return ['done' => 0, 'missed' => 0];
    }
    $c = location_period_counts($dateRange, $yardId);
    return ['done' => $c['done'], 'missed' => $c['missed']];
}

/** Задачи по локации (поддерево location=id*) */
function count_location_tasks(string $date, int $locId, string $status): int {
    $r = tb_get('/reports/tasks', [
        'date'     => $date,
        'project'  => TB_PROJECT,
        'status'   => $status,
        'location' => $locId . '*',
        'limit'    => 250,
    ]);
    return count($r['data'] ?? []);
}

/**
 * МОП дома: сумма по секциям (как в house_detail).
 * Если секций нет — запрос по корню дома.
 */
function mop_stats_for_house(int $houseLocId, string $date, array $allLocs, bool $withTasks = false): array {
    $sections = array_values(array_filter($allLocs, function ($l) use ($houseLocId) {
        return (int)$l['parent_id'] === $houseLocId;
    }));

    $mopDone = 0;
    $mopMiss = 0;
    $zones   = [];

    $rootTasks = [];

    if ($sections) {
        foreach ($sections as $sec) {
            $sid = (int)$sec['id'];
            if ($withTasks) {
                $bundle  = location_tasks_bundle($sid, $date);
                $d       = $bundle['done'];
                $m       = $bundle['missed'];
                $zones[] = [
                    'name' => $sec['name'], 'id' => $sid,
                    'done' => $d, 'missed' => $m, 'total' => $bundle['total'],
                    'pct'  => $bundle['pct'], 'tasks' => $bundle['tasks'],
                ];
            } else {
                $d = count_location_tasks($date, $sid, 'done');
                $m = count_location_tasks($date, $sid, 'missed');
                $zones[] = [
                    'name' => $sec['name'], 'id' => $sid,
                    'done' => $d, 'missed' => $m, 'total' => $d + $m,
                    'pct'  => ($d + $m) > 0 ? round($d / ($d + $m) * 100) : 0,
                ];
            }
            $mopDone += $d;
            $mopMiss += $m;
        }
    } else {
        if ($withTasks) {
            $bundle    = location_tasks_bundle($houseLocId, $date);
            $mopDone   = $bundle['done'];
            $mopMiss   = $bundle['missed'];
            $rootTasks = $bundle['tasks'];
        } else {
            $mopDone = count_location_tasks($date, $houseLocId, 'done');
            $mopMiss = count_location_tasks($date, $houseLocId, 'missed');
        }
    }

    $out = ['done' => $mopDone, 'missed' => $mopMiss, 'zones' => $zones];
    if ($withTasks && $rootTasks) {
        $out['tasks'] = $rootTasks;
    }
    return $out;
}

/** ПДТ — двор из «Территории» */
function pdt_stats_for_yard(?int $yardId, string $date): array {
    if ($yardId === null) {
        return ['done' => 0, 'missed' => 0];
    }
    return [
        'done'   => count_location_tasks($date, $yardId, 'done'),
        'missed' => count_location_tasks($date, $yardId, 'missed'),
    ];
}

/** Все задачи по локации за дату (без фильтра статуса) */
function fetch_location_tasks_raw(string $date, int $locId): array {
    $r = tb_get('/reports/tasks', [
        'date'     => $date,
        'project'  => TB_PROJECT,
        'location' => $locId . '*',
        'limit'    => 250,
    ]);
    return $r['data'] ?? [];
}

/** Строка задачи для дашборда: название, статус, время (MSK) */
function format_report_task_row(array $t): array {
    $dt = msk_dt($t['started_at'] ?? null);
    return [
        'name'   => ($t['task']['name'] ?? null) ?: '—',
        'status' => $t['status'] ?? '',
        'time'   => $dt ? $dt->format('H:i') : null,
    ];
}

/** Список задач по локации (секция, двор, дом) */
function location_task_list(int $locId, string $date): array {
    $raw = fetch_location_tasks_raw($date, $locId);
    $order = ['done' => 0, 'in_progress' => 1, 'pending' => 2, 'available' => 3, 'missed' => 4];
    usort($raw, function ($a, $b) use ($order) {
        $sa = $a['status'] ?? '';
        $sb = $b['status'] ?? '';
        $oa = $order[$sa] ?? 5;
        $ob = $order[$sb] ?? 5;
        if ($oa !== $ob) {
            return $oa - $ob;
        }
        $ta = $a['started_at'] ?? '';
        $tb = $b['started_at'] ?? '';
        if ($ta !== $tb) {
            if ($ta === '') return 1;
            if ($tb === '') return -1;
            return strcmp($ta, $tb);
        }
        return strcmp(($a['task']['name'] ?? ''), ($b['task']['name'] ?? ''));
    });
    $items = [];
    foreach ($raw as $t) {
        $st = $t['status'] ?? '';
        if (!in_array($st, ['done', 'missed', 'in_progress', 'pending', 'available'], true)) {
            continue;
        }
        $items[] = format_report_task_row($t);
    }
    return $items;
}

/** Группы статусов для KPI / task_breakdown */
function task_breakdown_group_statuses(string $group): array {
    switch ($group) {
        case 'planned':
            return ['available', 'pending'];
        case 'in_progress':
            return ['in_progress'];
        case 'missed':
            return ['missed'];
        case 'done':
            return ['done'];
        default:
            return [];
    }
}

/** Все задачи проекта за день и статус (кеш 15 мин — для KPI breakdown) */
function get_project_tasks_by_status(string $date, string $status): array {
    $cacheFile = sys_get_temp_dir() . '/tb_st_' . TB_PROJECT . '_' . $date . '_' . $status . '.json';
    if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
        $data = json_decode(file_get_contents($cacheFile), true);
        return is_array($data) ? $data : [];
    }
    $complete = true;
    $all = tb_get_all('/reports/tasks', [
        'date'    => $date,
        'project' => TB_PROJECT,
        'status'  => $status,
    ], $complete);
    if ($complete && !empty($all)) {
        file_put_contents($cacheFile, json_encode($all));
    }
    return $all;
}

/** Число задач по локации и статусам (быстро: одна страница; при 250+ — полная пагинация) */
function count_location_tasks_statuses(string $date, int $locId, array $statuses): int {
    static $mem = [];
    $key = $date . ':' . $locId . ':' . implode(',', $statuses);
    if (isset($mem[$key])) {
        return $mem[$key];
    }
    $n = 0;
    foreach ($statuses as $st) {
        $r = tb_get('/reports/tasks', [
            'date'     => $date,
            'project'  => TB_PROJECT,
            'status'   => $st,
            'location' => $locId . '*',
            'limit'    => 250,
        ]);
        $chunk = $r['data'] ?? [];
        $cnt   = count($chunk);
        if ($cnt >= 250) {
            $complete = true;
            $all = tb_get_all('/reports/tasks', [
                'date'     => $date,
                'project'  => TB_PROJECT,
                'status'   => $st,
                'location' => $locId . '*',
            ], $complete);
            $cnt = count($all);
        }
        $n += $cnt;
    }
    $mem[$key] = $n;
    return $n;
}

/** Список задач по локации и статусам */
function location_task_list_statuses(int $locId, string $date, array $statuses): array {
    $raw = [];
    foreach ($statuses as $st) {
        $complete = true;
        $all = tb_get_all('/reports/tasks', [
            'date'     => $date,
            'project'  => TB_PROJECT,
            'status'   => $st,
            'location' => $locId . '*',
        ], $complete);
        $raw = array_merge($raw, $all);
    }
    $order = ['done' => 0, 'in_progress' => 1, 'pending' => 2, 'available' => 3, 'missed' => 4];
    usort($raw, function ($a, $b) use ($order) {
        $sa = $a['status'] ?? '';
        $sb = $b['status'] ?? '';
        $oa = $order[$sa] ?? 5;
        $ob = $order[$sb] ?? 5;
        if ($oa !== $ob) {
            return $oa - $ob;
        }
        $ta = $a['started_at'] ?? '';
        $tb = $b['started_at'] ?? '';
        if ($ta !== $tb) {
            if ($ta === '') return 1;
            if ($tb === '') return -1;
            return strcmp($ta, $tb);
        }
        return strcmp(($a['task']['name'] ?? ''), ($b['task']['name'] ?? ''));
    });
    $items = [];
    foreach ($raw as $t) {
        $items[] = format_report_task_row($t);
    }
    return $items;
}

/** МОП дома: счётчик задач по статусам (по секциям) */
function mop_breakdown_for_house(int $houseLocId, string $date, array $allLocs, array $statuses, bool $withTasks = false): array {
    $sections = array_values(array_filter($allLocs, function ($l) use ($houseLocId) {
        return (int)$l['parent_id'] === $houseLocId;
    }));
    $count = 0;
    $zones = [];
    if ($sections) {
        foreach ($sections as $sec) {
            $sid = (int)$sec['id'];
            $c   = count_location_tasks_statuses($date, $sid, $statuses);
            $zone = ['name' => $sec['name'], 'id' => $sid, 'count' => $c];
            if ($withTasks && $c > 0) {
                $zone['tasks'] = location_task_list_statuses($sid, $date, $statuses);
            }
            $zones[] = $zone;
            $count += $c;
        }
    } else {
        $count = count_location_tasks_statuses($date, $houseLocId, $statuses);
        if ($withTasks && $count > 0) {
            return ['count' => $count, 'zones' => [], 'tasks' => location_task_list_statuses($houseLocId, $date, $statuses)];
        }
    }
    return ['count' => $count, 'zones' => $zones];
}

/** Задачи + агрегаты done/missed/total для одной локации */
function location_tasks_bundle(int $locId, string $date): array {
    $tasks  = location_task_list($locId, $date);
    $done   = 0;
    $missed = 0;
    foreach ($tasks as $t) {
        if (($t['status'] ?? '') === 'done') {
            $done++;
        } elseif (($t['status'] ?? '') === 'missed') {
            $missed++;
        }
    }
    $total = $done + $missed;
    return [
        'tasks'  => $tasks,
        'done'   => $done,
        'missed' => $missed,
        'total'  => $total,
        'pct'    => $total > 0 ? round($done / $total * 100) : 0,
    ];
}

switch ($action) {

    // ----------------------------------------------------------
    //  ШАГ 1: узнать project_id
    //  Откройте в браузере: https://вашсайт.ru/proxy.php?action=projects
    // ----------------------------------------------------------
    case 'projects':
        echo json_encode(tb_get('/projects'), JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Смены за сегодня (или за переданную дату)
    //  ?action=shifts&date=2026-05-28
    // ----------------------------------------------------------
    case 'shifts':
        $date = $_GET['date'] ?? $today;
        $data = tb_get('/work-shifts', [
            'filter[date]'    => $date,
            'filter[project]' => TB_PROJECT,
        ]);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Отчёты по задачам (выполненные + пропущенные)
    //  ?action=tasks&date=today  или  ?action=tasks&date=2026-05-01,2026-05-28
    // ----------------------------------------------------------
    case 'tasks':
        $date   = $_GET['date'] ?? 'today';
        $done   = tb_get('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT, 'status' => 'done',   'limit' => 100]);
        $missed = tb_get('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT, 'status' => 'missed', 'limit' => 100]);
        $doneCnt   = count($done['data']   ?? []);
        $missedCnt = count($missed['data'] ?? []);
        $total     = $doneCnt + $missedCnt;
        echo json_encode([
            'date'       => $date,
            'total'      => $total,
            'done'       => $doneCnt,
            'missed'     => $missedCnt,
            'rate'       => $total > 0 ? round($doneCnt / $total * 100) : 0,
            'done_raw'   => $done['data']   ?? [],
            'missed_raw' => $missed['data'] ?? [],
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Расписание задач на дату (план на день)
    //  ?action=schedule&date=2026-05-28
    // ----------------------------------------------------------
    case 'schedule':
        $date = $_GET['date'] ?? $today;
        $data = tb_get('/schedule/tasks', ['location' => TB_PROJECT]);
        echo json_encode($data, JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Список пользователей (штат)
    //  ?action=users
    // ----------------------------------------------------------
    case 'users':
        $data = tb_get('/users');
        $active = array_filter($data['data'] ?? [], function($u){ return $u['state'] === 'enabled'; });
        echo json_encode([
            'total'   => count($active),
            'users'   => array_values($active),
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Задачи за диапазон дат (неделя / месяц / вчера)
    //  ?action=period_tasks&date=2026-05-23,2026-05-29
    // ----------------------------------------------------------
    case 'period_tasks':
        set_time_limit(120);
        $date    = $_GET['date'] ?? $today;
        $nocache = isset($_GET['nocache']);

        $cacheKey  = 'tb_pt_' . TB_PROJECT . '_' . md5($date) . '.json';
        $cacheFile = sys_get_temp_dir() . '/' . $cacheKey;

        if ($nocache && file_exists($cacheFile)) { unlink($cacheFile); }

        // TTL 65 минут: почасовой warmup_cache всегда обновляет кеш раньше,
        // поэтому неделя/месяц отдаются мгновенно из кеша.
        if (!$nocache && file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 3900) {
            header('X-Cache: HIT');
            echo file_get_contents($cacheFile);
            break;
        }

        $complete = true;
        $allTasks = tb_get_all('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT], $complete);
        $totalCnt = count($allTasks);
        $doneCnt = 0; $inProgressCnt = 0; $missedCnt = 0;
        $availableCnt = 0; $pendingCnt = 0;
        foreach ($allTasks as $t) {
            $st = $t['status'] ?? '';
            if      ($st === 'done')        $doneCnt++;
            elseif  ($st === 'in_progress') $inProgressCnt++;
            elseif  ($st === 'missed')      $missedCnt++;
            elseif  ($st === 'available')   $availableCnt++;
            elseif  ($st === 'pending')     $pendingCnt++;
        }
        $rate = $totalCnt > 0 ? round($doneCnt / $totalCnt * 100) : 0;
        $plannedCnt = $availableCnt + $pendingCnt;

        header('X-Complete: ' . ($complete ? '1' : '0'));
        $result = json_encode([
            'date'  => $date,
            'tasks' => [
                'total'=>$totalCnt,'done'=>$doneCnt,'in_progress'=>$inProgressCnt,'missed'=>$missedCnt,
                'available'=>$availableCnt,'pending'=>$pendingCnt,'planned'=>$plannedCnt,'rate'=>$rate,
            ],
        ], JSON_UNESCAPED_UNICODE);

        // Кешируем только полную выборку с данными — иначе можно «заморозить»
        // обрезанный частичный ответ на весь TTL.
        if ($complete && $totalCnt > 0) { file_put_contents($cacheFile, $result); }
        echo $result;
        break;

    // ----------------------------------------------------------
    //  История выполнения по дням (замена облачным снимкам).
    //  Строится прямо из ThroneBaron: по дню считаем total/done/missed/rate.
    //  Прошедшие дни кешируются надолго (сутки), сегодня — на 15 минут,
    //  поэтому повторные запросы быстрые.
    //  ?action=history&days=30
    // ----------------------------------------------------------
    case 'history':
        set_time_limit(180);
        $tz       = new DateTimeZone('Europe/Moscow');
        $now      = new DateTime('now', $tz);
        $todayStr = $now->format('Y-m-d');
        $days     = (int)($_GET['days'] ?? 30);
        if ($days < 1)  $days = 30;
        if ($days > 60) $days = 60;

        $out = [];
        for ($i = $days - 1; $i >= 0; $i--) {
            $d = (clone $now)->modify("-{$i} day")->format('Y-m-d');
            $isToday   = ($d === $todayStr);
            $dayCache  = sys_get_temp_dir() . '/tb_day_' . TB_PROJECT . '_' . $d . '.json';
            $ttl       = $isToday ? 900 : 86400;

            $rec = null;
            if (file_exists($dayCache) && (time() - filemtime($dayCache)) < $ttl) {
                $rec = json_decode(file_get_contents($dayCache), true);
            }
            if ($rec === null) {
                $complete = true;
                $tasks = tb_get_all('/reports/tasks', ['date' => $d, 'project' => TB_PROJECT], $complete);
                $tot = count($tasks); $dn = 0; $ms = 0;
                foreach ($tasks as $t) {
                    $st = $t['status'] ?? '';
                    if      ($st === 'done')   $dn++;
                    elseif  ($st === 'missed') $ms++;
                }
                $rec = [
                    'date'   => $d,
                    'tasks'  => $tot,
                    'closed' => $dn,
                    'missed' => $ms,
                    'rate'   => $tot > 0 ? round($dn / $tot * 100) : 0,
                ];
                // Кешируем день только при полной выборке
                if ($complete && $tot > 0) file_put_contents($dayCache, json_encode($rec));
            }
            // Пропускаем дни без задач (выходные/нет плана) — не засоряют график
            if (($rec['tasks'] ?? 0) > 0) $out[] = $rec;
        }

        echo json_encode(['days' => $days, 'history' => $out], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Прогрев кеша (вызывается cron-ом раз в час).
    //  Заранее считает неделю и месяц (period_tasks) и историю по дням,
    //  чтобы пользовательские запросы отдавались из кеша без задержки.
    //  ?action=warmup_cache&secret=cleansyst2026
    // ----------------------------------------------------------
    case 'warmup_cache':
        if (($_GET['secret'] ?? '') !== 'cleansyst2026') {
            http_response_code(403);
            echo json_encode(['error' => 'forbidden']);
            break;
        }
        set_time_limit(300);
        $tz       = new DateTimeZone('Europe/Moscow');
        $now      = new DateTime('now', $tz);
        $todayStr = $now->format('Y-m-d');

        // Текущая неделя (с понедельника) и текущий месяц
        $monday = (clone $now);
        $monday->modify('-' . ((int)$monday->format('N') - 1) . ' day');
        $weekRange    = $monday->format('Y-m-d') . ',' . $todayStr;
        $firstOfMonth = (clone $now)->modify('first day of this month')->format('Y-m-d');
        $monthRange   = $firstOfMonth . ',' . $todayStr;

        $report = [];
        foreach (['week' => $weekRange, 'month' => $monthRange] as $label => $range) {
            $complete = true;
            $allTasks = tb_get_all('/reports/tasks', ['date' => $range, 'project' => TB_PROJECT], $complete);
            $totalCnt = count($allTasks);
            $doneCnt = 0; $inProgressCnt = 0; $missedCnt = 0;
            foreach ($allTasks as $t) {
                $st = $t['status'] ?? '';
                if      ($st === 'done')        $doneCnt++;
                elseif  ($st === 'in_progress') $inProgressCnt++;
                elseif  ($st === 'missed')      $missedCnt++;
            }
            $rate = $totalCnt > 0 ? round($doneCnt / $totalCnt * 100) : 0;
            $result = json_encode([
                'date'  => $range,
                'tasks' => ['total'=>$totalCnt,'done'=>$doneCnt,'in_progress'=>$inProgressCnt,'missed'=>$missedCnt,'rate'=>$rate],
            ], JSON_UNESCAPED_UNICODE);
            // Тот же путь кеша, что и в period_tasks
            $ptCache = sys_get_temp_dir() . '/tb_pt_' . TB_PROJECT . '_' . md5($range) . '.json';
            $cached = false;
            if ($complete && $totalCnt > 0) { file_put_contents($ptCache, $result); $cached = true; }
            $report[$label] = ['range'=>$range,'total'=>$totalCnt,'done'=>$doneCnt,'complete'=>$complete,'cached'=>$cached];
        }

        // Прогрев истории по дням (тот же per-day кеш, что и в action=history)
        $histWarmed = 0;
        for ($i = 29; $i >= 0; $i--) {
            $d        = (clone $now)->modify("-{$i} day")->format('Y-m-d');
            $isToday  = ($d === $todayStr);
            $dayCache = sys_get_temp_dir() . '/tb_day_' . TB_PROJECT . '_' . $d . '.json';
            $ttl      = $isToday ? 900 : 86400;
            if (file_exists($dayCache) && (time() - filemtime($dayCache)) < $ttl) continue;
            $complete = true;
            $tasks = tb_get_all('/reports/tasks', ['date' => $d, 'project' => TB_PROJECT], $complete);
            $tot = count($tasks); $dn = 0; $ms = 0;
            foreach ($tasks as $t) {
                $st = $t['status'] ?? '';
                if      ($st === 'done')   $dn++;
                elseif  ($st === 'missed') $ms++;
            }
            if ($complete && $tot > 0) {
                file_put_contents($dayCache, json_encode([
                    'date'=>$d,'tasks'=>$tot,'closed'=>$dn,'missed'=>$ms,
                    'rate'=>round($dn / $tot * 100),
                ]));
                $histWarmed++;
            }
        }

        // Прогрев кеша задач по статусам (ускоряет KPI → детализация)
        $statusWarm = [];
        foreach (['available', 'pending', 'in_progress', 'missed'] as $st) {
            $statusWarm[$st] = count(get_project_tasks_by_status($todayStr, $st));
        }

        echo json_encode([
            'warmed'              => $report,
            'history_days_warmed' => $histWarmed,
            'status_tasks_today'  => $statusWarm,
            'ts'                  => $now->format('Y-m-d H:i:s'),
        ], JSON_UNESCAPED_UNICODE);
        break;


    case 'dashboard':
        $tz   = new DateTimeZone('Europe/Moscow');
        $now  = new DateTime('now', $tz);
        $date = $_GET['date'] ?? $now->format('Y-m-d');

        // Все задачи за дату без фильтра статуса — даёт точный total
        $complete  = true;
        $allTasks  = tb_get_all('/reports/tasks', ['date' => $date, 'project' => TB_PROJECT], $complete);

        // Если сегодня ещё нет задач (утро) — берём вчера
        $isYesterday = false;
        if (empty($allTasks) && !isset($_GET['date'])) {
            $yesterday = (new DateTime('now', $tz))->modify('-1 day')->format('Y-m-d');
            $allTasks  = tb_get_all('/reports/tasks', ['date' => $yesterday, 'project' => TB_PROJECT], $complete);
            $date      = $yesterday;
            $isYesterday = true;
        }
        header('X-Complete: ' . ($complete ? '1' : '0'));

        // Подсчёт по статусам (как в ThroneBaron)
        // done       = выполнено
        // in_progress = в работе
        // available   = запланированы, ещё не начаты
        // pending     = ждут условий
        // missed      = пропущено
        $totalCnt      = count($allTasks);
        $doneCnt       = 0;
        $inProgressCnt = 0;
        $missedCnt     = 0;
        $availableCnt  = 0;
        $pendingCnt    = 0;
        foreach ($allTasks as $t) {
            $st = $t['status'] ?? '';
            if      ($st === 'done')        $doneCnt++;
            elseif  ($st === 'in_progress') $inProgressCnt++;
            elseif  ($st === 'missed')      $missedCnt++;
            elseif  ($st === 'available')   $availableCnt++;
            elseif  ($st === 'pending')     $pendingCnt++;
        }
        // % как в ThroneBaron: выполнено / всего
        $rate = $totalCnt > 0 ? round($doneCnt / $totalCnt * 100) : 0;

        // Разбор выполнения за выбранный день по 2-часовым корзинам (00:00..22:00, MSK).
        // Учитываем ВСЕ выполненные задачи (status === 'done'):
        //   - started_at в тот же день (MSK) -> в корзину своего 2-часового интервала;
        //   - нет started_at ИЛИ started_at другого дня -> в счётчик $doneNoTime
        //     (например, задачу начали накануне вечером, а закрыли сегодня —
        //      такую нельзя ставить в час сегодняшнего дня).
        // Гарантия: sum($hourlyData) + $doneNoTime === $doneCnt.
        $hourlyHours = array(0,2,4,6,8,10,12,14,16,18,20,22);
        $hourlyData  = array();
        foreach ($hourlyHours as $hh) { $hourlyData[$hh] = 0; }
        $doneNoTime = 0;
        foreach ($allTasks as $t) {
            if (($t['status'] ?? '') !== 'done') continue;
            $dt = msk_dt($t['started_at'] ?? null);
            if ($dt === null) { $doneNoTime++; continue; }                 // нет времени
            if ($dt->format('Y-m-d') !== $date) { $doneNoTime++; continue; } // другой день
            $h = (int)$dt->format('H');
            $bucket = $h - ($h % 2);            // 0,2,4,...,22
            $hourlyData[$bucket]++;
        }

        // Смены — за ту же дату что и задачи
        $shifts     = tb_get('/work-shifts', ['filter[date]' => $date, 'filter[project]' => TB_PROJECT]);
        $shiftsData = $shifts['data'] ?? [];
        $staffTotal = count($shiftsData);
        $came = 0;
        $shiftsFormatted = [];
        foreach ($shiftsData as $s) {
            $hasStarted = !empty($s['started_at']);
            if ($hasStarted) $came++;
            $inDt    = msk_dt($s['started_at'] ?? null);
            $outDt   = msk_dt($s['ended_at'] ?? null);
            $inTime  = $inDt  ? $inDt->format('H:i')  : null;
            $outTime = $outDt ? $outDt->format('H:i') : null;
            $profile  = $s['user']['profile'] ?? [];
            $fullName = trim(($profile['last_name'] ?? '') . ' ' . ($profile['first_name'] ?? ''));
            $pos      = strtolower($profile['position'] ?? '');
            if (strpos($pos, 'двор') !== false) $role = 'Дворник';
            elseif (strpos($pos, 'убор') !== false) $role = 'Уборщица';
            elseif (strpos($pos, 'клин') !== false) $role = 'Клинер';
            else $role = $profile['position'] ?? '—';
            $shiftsFormatted[] = [
                'name' => $fullName ?: ($profile['first_name'] ?? '—'),
                'role' => $role,
                'in'   => $inTime,
                'out'  => $outTime,
                'came' => $hasStarted,
            ];
        }

        echo json_encode([
            'date'        => $date,
            'isYesterday' => $isYesterday,
            'tasks'  => [
                'total'       => $totalCnt,
                'done'        => $doneCnt,
                'in_progress' => $inProgressCnt,
                'missed'      => $missedCnt,
                'available'   => $availableCnt,
                'pending'     => $pendingCnt,
                'planned'     => $availableCnt + $pendingCnt,
                'rate'        => $rate,
                'hourly'        => array_values($hourlyData),
                'hourly_hours'  => $hourlyHours,
                'hourly_notime' => $doneNoTime,
            ],
            'staff'  => ['total' => $staffTotal, 'came' => $came, 'absent' => $staffTotal - $came],
            'shifts' => $shiftsFormatted,
        ], JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  KPI: задачи по домам / локациям для статуса
    //  ?action=task_breakdown&status=planned|in_progress|missed|done&date=2026-05-30
    //  ?action=task_breakdown&status=in_progress&location_id=305&expand=tasks
    // ----------------------------------------------------------
    case 'task_breakdown':
        $group = $_GET['status'] ?? '';
        $statuses = task_breakdown_group_statuses($group);
        if (!$statuses) {
            echo json_encode(['error' => 'status must be planned, in_progress, missed, or done']);
            break;
        }
        $rawDate = $_GET['date'] ?? $today;
        if (strpos($rawDate, ',') !== false) {
            echo json_encode(['error' => 'task_breakdown only for a single day']);
            break;
        }
        $date = normalize_report_date($rawDate, $tz_msk, $today);
        $locExpand = (int)($_GET['location_id'] ?? 0);
        if ($locExpand > 0 && isset($_GET['expand']) && $_GET['expand'] === 'tasks') {
            echo json_encode([
                'date'        => $date,
                'status'      => $group,
                'locationId'  => $locExpand,
                'tasks'       => location_task_list_statuses($locExpand, $date, $statuses),
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        $houseLocExpand = (int)($_GET['house_location_id'] ?? 0);
        if ($houseLocExpand > 0 && ($_GET['expand'] ?? '') === 'zones') {
            $allLocs = get_all_locations();
            $mopB    = mop_breakdown_for_house($houseLocExpand, $date, $allLocs, $statuses, false);
            echo json_encode([
                'date'             => $date,
                'status'           => $group,
                'houseLocationId'=> $houseLocExpand,
                'mop'              => $mopB,
            ], JSON_UNESCAPED_UNICODE);
            break;
        }

        set_time_limit(300);
        $cacheFile = sys_get_temp_dir() . '/tb_tbd2_' . TB_PROJECT . '_'
            . md5($date . '_' . $group) . '.json';
        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
            header('X-Cache: HIT');
            echo file_get_contents($cacheFile);
            break;
        }

        $allLocs  = get_all_locations();
        $yardMap  = get_yard_map($allLocs);
        $terrId   = $yardMap['_terr'] ?? null;
        $rootLocs = array_filter($allLocs, function ($l) {
            return $l['project_id'] == TB_PROJECT && $l['parent_id'] === null;
        });

        $houseSum = 0;
        $houses   = [];
        foreach ($rootLocs as $loc) {
            $locId   = $loc['id'];
            if ($terrId !== null && (int)$locId === (int)$terrId) {
                continue;
            }
            $locName = $loc['name'];
            if (strpos($locName, 'Офис') !== false) {
                continue;
            }
            $parts   = explode(' ', trim($locName), 2);
            $houseId = count($parts) > 1 ? $parts[1] : $locName;
            $yardId  = $yardMap[$houseId] ?? null;

            // Счёт по всему дому (location=id*), без запроса по каждой секции
            $mopCnt  = count_location_tasks_statuses($date, (int)$locId, $statuses);
            $sections = array_values(array_filter($allLocs, function ($l) use ($locId) {
                return (int)$l['parent_id'] === (int)$locId;
            }));
            $mopZones = array_map(function ($sec) {
                return ['name' => $sec['name'], 'id' => (int)$sec['id'], 'lazy' => true];
            }, $sections);
            $pdtCnt  = ($yardId !== null)
                ? count_location_tasks_statuses($date, (int)$yardId, $statuses) : 0;
            $pdtName = null;
            if ($yardId !== null) {
                foreach ($allLocs as $l) {
                    if ((int)$l['id'] === (int)$yardId) {
                        $pdtName = $l['name'] ?? null;
                        break;
                    }
                }
            }
            $cnt = $mopCnt + $pdtCnt;
            $houseSum += $cnt;
            if ($cnt === 0) {
                continue;
            }
            $houses[] = [
                'id'         => $houseId,
                'label'      => $locName,
                'locationId' => (int)$locId,
                'count'      => $cnt,
                'mop'        => [
                    'count'     => $mopCnt,
                    'zones'     => $mopZones,
                    'lazyZones' => count($mopZones) > 0,
                ],
                'pdt'        => [
                    'count'    => $pdtCnt,
                    'yardId'   => $yardId,
                    'yardName' => $pdtName,
                    'hasYard'  => $yardId !== null,
                ],
            ];
        }
        usort($houses, function ($a, $b) {
            return $b['count'] - $a['count'];
        });

        $projectTotal = 0;
        foreach ($statuses as $st) {
            $projectTotal += count(get_project_tasks_by_status($date, $st));
        }
        $other = max(0, $projectTotal - $houseSum);

        $labels = [
            'planned'     => 'Запланировано',
            'in_progress' => 'В работе',
            'missed'      => 'Пропущено',
            'done'        => 'Выполнено',
        ];
        $result = json_encode([
            'date'         => $date,
            'status'       => $group,
            'statusLabel'  => $labels[$group] ?? $group,
            'total'        => $projectTotal,
            'housesSum'    => $houseSum,
            'other'        => ['count' => $other, 'label' => 'Прочие локации (офис, общие зоны)'],
            'houses'       => $houses,
            'dayOnly'      => true,
        ], JSON_UNESCAPED_UNICODE);
        file_put_contents($cacheFile, $result);
        echo $result;
        break;

    // ----------------------------------------------------------
    //  Детализация задач по домам (кеш 15 мин)
    //  ?action=house_breakdown&date=2026-05-29
    // ----------------------------------------------------------
    case 'house_breakdown':
        $rawDate = $_GET['date'] ?? $today;
        $isRange = (strpos($rawDate, ',') !== false);
        $date    = $isRange ? $rawDate : normalize_report_date($rawDate, $tz_msk, $today);
        set_time_limit($isRange ? 600 : 300);

        $cacheFile = sys_get_temp_dir() . '/'
            . ($isRange ? 'tb_hbp_' : 'tb_hb_') . TB_PROJECT . '_'
            . ($isRange ? md5($date) : $date) . '.json';
        $cacheTtl = $isRange ? 3900 : 900;

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < $cacheTtl) {
            header('X-Cache: HIT');
            echo file_get_contents($cacheFile);
            break;
        }

        $allLocs  = get_all_locations();
        $yardMap  = get_yard_map($allLocs);          // [houseId => yardLocationId, _terr => id]
        $terrId   = $yardMap['_terr'] ?? null;
        $rootLocs = array_filter($allLocs, function($l) {
            return $l['project_id'] == TB_PROJECT && $l['parent_id'] === null;
        });

        $houses = [];
        foreach ($rootLocs as $loc) {
            $locId   = $loc['id'];
            // Сам корень «Территория» не дом — его задачи раскладываются по дворам
            if ($terrId !== null && (int)$locId === (int)$terrId) continue;
            $locName = $loc['name'];
            // Нежилые локации (офис) не показываем как дом
            if (strpos($locName, 'Офис') !== false) continue;
            $parts   = explode(' ', trim($locName), 2);
            $houseId = count($parts) > 1 ? $parts[1] : $locName;

            if ($isRange) {
                $mop     = mop_stats_for_house_period((int)$locId, $date, $allLocs);
                $mopDone = $mop['done'];
                $mopMiss = $mop['missed'];
                $yardId  = $yardMap[$houseId] ?? null;
                $pdt     = pdt_stats_for_yard_period($yardId !== null ? (int)$yardId : null, $date);
                $pdtDone = $pdt['done'];
                $pdtMiss = $pdt['missed'];
            } else {
                $mop     = mop_stats_for_house((int)$locId, $date, $allLocs);
                $mopDone = $mop['done'];
                $mopMiss = $mop['missed'];
                $yardId  = $yardMap[$houseId] ?? null;
                $pdt     = pdt_stats_for_yard($yardId !== null ? (int)$yardId : null, $date);
                $pdtDone = $pdt['done'];
                $pdtMiss = $pdt['missed'];
            }

            // Светофор дома = МОП + ПДТ вместе
            $dc = $mopDone + $pdtDone;
            $mc = $mopMiss + $pdtMiss;
            $total = $dc + $mc;
            $rate  = $total > 0 ? round($dc/$total*100) : 100; // нет задач = 100%
            $status = traffic_status($rate, $total);

            $mopTot = $mopDone + $mopMiss;
            $pdtTot = $pdtDone + $pdtMiss;
            $houses[] = [
                'id'=>$houseId,'label'=>$locName,'locationId'=>$locId,
                'done'=>$dc,'missed'=>$mc,'total'=>$total,'pct'=>$rate,'status'=>$status,
                'mop'=>['done'=>$mopDone,'missed'=>$mopMiss,'total'=>$mopTot,'pct'=>$mopTot>0?round($mopDone/$mopTot*100):0],
                'pdt'=>['done'=>$pdtDone,'missed'=>$pdtMiss,'total'=>$pdtTot,'pct'=>$pdtTot>0?round($pdtDone/$pdtTot*100):0,'yardId'=>$yardId],
            ];
        }

        usort($houses, function($a,$b){ return $a['pct'] - $b['pct']; });
        $parts = explode(',', $date);
        $result = json_encode([
            'date'        => $date,
            'dateFrom'    => $parts[0] ?? $date,
            'dateTo'      => $parts[1] ?? ($parts[0] ?? $date),
            'periodRange' => $isRange,
            'periodLabel' => $isRange ? 'сумма по дням' : null,
            'houses'      => $houses,
            'cached'      => false,
        ], JSON_UNESCAPED_UNICODE);
        file_put_contents($cacheFile, $result);
        echo $result;
        break;

    // ----------------------------------------------------------
    //  Детализация одного дома: МОП (по секциям) + ПДТ
    //  ?action=house_detail&location_id=1&date=2026-05-29
    //
    //  Логика:
    //    МОП = сумма задач по всем секциям дома (каждая с wildcard)
    //    ПДТ = задачи соответствующего «Двора» из корня «Территория»
    //    Всего = МОП + ПДТ
    // ----------------------------------------------------------
    case 'house_detail':
        set_time_limit(180);
        $locId = (int)($_GET['location_id'] ?? 0);
        if (!$locId) { echo json_encode(['error'=>'location_id required']); break; }
        $date = normalize_report_date($_GET['date'] ?? $today, $tz_msk, $today);

        $allLocs  = get_all_locations();
        $yardMap  = get_yard_map($allLocs);

        // Имя дома -> houseId (как в house_breakdown) -> двор
        $houseLoc = null;
        foreach ($allLocs as $l) { if ((int)$l['id'] === $locId) { $houseLoc = $l; break; } }
        $houseId  = '';
        if ($houseLoc) { $p = explode(' ', trim($houseLoc['name']), 2); $houseId = count($p)>1 ? $p[1] : $houseLoc['name']; }
        $yardId   = $yardMap[$houseId] ?? null;

        $mop      = mop_stats_for_house($locId, $date, $allLocs, true);
        $mopDone  = $mop['done'];
        $mopMiss  = $mop['missed'];
        $mopZones = $mop['zones'];
        $mopTot   = $mopDone + $mopMiss;
        $mopOut   = [
            'done'=>$mopDone,'missed'=>$mopMiss,'total'=>$mopTot,
            'pct'=>$mopTot>0?round($mopDone/$mopTot*100):0,'zones'=>$mopZones,
        ];
        if (!empty($mop['tasks'])) {
            $mopOut['tasks'] = $mop['tasks'];
        }

        $pdtDone = 0; $pdtMiss = 0; $pdtTot = 0; $pdtTasks = [];
        $pdtYardName = null;
        if ($yardId !== null) {
            $pdtBundle   = location_tasks_bundle((int)$yardId, $date);
            $pdtDone     = $pdtBundle['done'];
            $pdtMiss     = $pdtBundle['missed'];
            $pdtTot      = $pdtBundle['total'];
            $pdtTasks    = $pdtBundle['tasks'];
            foreach ($allLocs as $l) {
                if ((int)$l['id'] === (int)$yardId) {
                    $pdtYardName = $l['name'] ?? null;
                    break;
                }
            }
        }

        $houseDone = $mopDone + $pdtDone;
        $houseMiss = $mopMiss + $pdtMiss;
        $houseTot  = $houseDone + $houseMiss;

        echo json_encode([
            'date'   => $date,
            'locId'  => $locId,
            'total'  => ['done'=>$houseDone,'missed'=>$houseMiss,'total'=>$houseTot],
            'mop'    => $mopOut,
            'pdt'    => [
                'done'=>$pdtDone,'missed'=>$pdtMiss,'total'=>$pdtTot,
                'pct'=>$pdtTot>0?round($pdtDone/$pdtTot*100):0,
                'yardId'=>$yardId,'yardName'=>$pdtYardName,'hasYard'=>$yardId!==null,
                'tasks'=>$pdtTasks,
            ],
        ], JSON_UNESCAPED_UNICODE);
        break;


    case 'date_format_test':
        $formats = [
            'comma'      => ['date' => '2026-05-26,2026-05-29'],
            'from_to'    => ['date_from' => '2026-05-26', 'date_to' => '2026-05-29'],
            'start_end'  => ['start_date' => '2026-05-26', 'end_date' => '2026-05-29'],
            'period'     => ['period' => '2026-05-26,2026-05-29'],
            'from'       => ['from' => '2026-05-26', 'to' => '2026-05-29'],
            'date_range' => ['date' => '2026-05-26', 'date_end' => '2026-05-29'],
        ];
        $out = [];
        foreach ($formats as $name => $params) {
            $params['project'] = TB_PROJECT;
            $params['limit']   = 1;
            $r = tb_get('/reports/tasks', $params);
            $out[$name] = [
                'params'     => $params,
                'data_count' => count($r['data'] ?? []),
                'has_error'  => isset($r['message']) || isset($r['error']),
                'error'      => $r['message'] ?? $r['error'] ?? null,
            ];
        }
        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Команды проекта
    //  ?action=teams
    // ----------------------------------------------------------
    case 'teams':
        $data = tb_get('/teams', ['project' => TB_PROJECT]);
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Диагностика: структура ответа API задач
    //  ?action=task_debug&date=2026-05-29
    // ----------------------------------------------------------
    case 'task_debug':
        $date = $_GET['date'] ?? $today;
        $out  = [];

        // Смотрим структуру первой задачи (без фильтра)
        $r = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'limit'=>3]);
        $out['sample_tasks'] = $r['data'] ?? [];

        // Пробуем разные статусы
        foreach (['done','missed','in_progress'] as $st) {
            $r = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>$st,'limit'=>1]);
            $out['status_'.$st] = [
                'data_count'    => count($r['data'] ?? []),
                'has_next_page' => !empty($r['next_page_url']),
                'sample'        => $r['data'][0] ?? null,
            ];
        }
        // Всего без фильтра (считаем все страницы)
        $all = tb_get_all('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT]);
        $statusCounts = [];
        foreach ($all as $t) {
            $st = $t['status'] ?? $t['state'] ?? '(no_status_field)';
            $statusCounts[$st] = ($statusCounts[$st] ?? 0) + 1;
        }
        $out['total_all_pages']  = count($all);
        $out['status_breakdown'] = $statusCounts;

        echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;



    // ----------------------------------------------------------
    //  Локации
    //  ?action=locations
    // ----------------------------------------------------------
    case 'locations':
        $data = tb_get('/locations');
        echo json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Статистика по домам (командам) за дату
    //  ?action=house_stats&date=2026-05-29
    // ----------------------------------------------------------
    case 'house_stats':
        $tz   = new DateTimeZone('Europe/Moscow');
        $now  = new DateTime('now', $tz);
        $date = $_GET['date'] ?? $now->format('Y-m-d');

        // Получаем все команды проекта
        $teamsData = tb_get('/teams', ['project' => TB_PROJECT]);
        $teams = $teamsData['data'] ?? [];

        // Для каждой команды получаем done/missed задачи по локации
        // Команды named "МОП 28к2" → извлекаем "28к2"
        $houseStats = [];
        foreach ($teams as $team) {
            $tname = $team['name'] ?? '';
            // Извлекаем номер дома из названия команды
            preg_match('/(\d+[\/к]\d+|\d+к\d+|\d+\/\d+)/ui', $tname, $m);
            $houseNum = $m[0] ?? $tname;

            // Задачи команды — фильтр по project, статус done/missed за дату
            // ThroneBaron: assignee t{id} — но в reports нет фильтра по команде
            // Используем location если совпадает, иначе показываем команду как есть
            $houseStats[] = [
                'teamId'   => $team['id'],
                'teamName' => $tname,
                'house'    => $houseNum,
            ];
        }

        echo json_encode([
            'date'   => $date,
            'teams'  => $houseStats,
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
        break;

    // ----------------------------------------------------------
    //  Помощь: список доступных action
    // ----------------------------------------------------------
    default:
        echo json_encode([
            'endpoints' => [
                '?action=projects'              => 'Список проектов (найти project_id)',
                '?action=dashboard&date=today'  => 'Все KPI за дату — основной запрос дашборда',
                '?action=teams'                 => 'Команды проекта (= дома ЖК)',
                '?action=locations'             => 'Локации проекта',
                '?action=house_stats'           => 'Статистика по командам/домам',
                '?action=shifts&date=2026-05-28'=> 'Смены за дату',
                '?action=task_breakdown&status=in_progress&date=today' => 'KPI: задачи по домам (статус)',
                '?action=tasks&date=today'      => 'Задачи: выполнено / пропущено',
                '?action=users'                 => 'Список активных сотрудников',
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
