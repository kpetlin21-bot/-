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
        foreach ($allTasks as $t) {
            $st = $t['status'] ?? '';
            if      ($st === 'done')        $doneCnt++;
            elseif  ($st === 'in_progress') $inProgressCnt++;
            elseif  ($st === 'missed')      $missedCnt++;
        }
        $rate = $totalCnt > 0 ? round($doneCnt / $totalCnt * 100) : 0;

        header('X-Complete: ' . ($complete ? '1' : '0'));
        $result = json_encode([
            'date'  => $date,
            'tasks' => ['total'=>$totalCnt,'done'=>$doneCnt,'in_progress'=>$inProgressCnt,'missed'=>$missedCnt,'rate'=>$rate],
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

        echo json_encode([
            'warmed'              => $report,
            'history_days_warmed' => $histWarmed,
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
    //  Детализация задач по домам (кеш 15 мин)
    //  ?action=house_breakdown&date=2026-05-29
    // ----------------------------------------------------------
    case 'house_breakdown':
        set_time_limit(120);
        $date = normalize_report_date($_GET['date'] ?? $today, $tz_msk, $today);
        $cacheFile = sys_get_temp_dir() . '/tb_hb_' . TB_PROJECT . '_' . $date . '.json';

        if (file_exists($cacheFile) && (time() - filemtime($cacheFile)) < 900) {
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

            // МОП — всё внутри дома (подъезды/этажи)
            $done   = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$locId.'*','limit'=>250]);
            $missed = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$locId.'*','limit'=>250]);
            $mopDone = count($done['data'] ?? []);
            $mopMiss = count($missed['data'] ?? []);

            // ПДТ — двор этого дома из «Территории»
            $pdtDone = 0; $pdtMiss = 0; $yardId = $yardMap[$houseId] ?? null;
            if ($yardId !== null) {
                $yd = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$yardId.'*','limit'=>250]);
                $ym = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$yardId.'*','limit'=>250]);
                $pdtDone = count($yd['data'] ?? []);
                $pdtMiss = count($ym['data'] ?? []);
            }

            // Светофор дома = МОП + ПДТ вместе
            $dc = $mopDone + $pdtDone;
            $mc = $mopMiss + $pdtMiss;
            $total = $dc + $mc;
            $rate  = $total > 0 ? round($dc/$total*100) : 100; // нет задач = 100%
            $status = $total === 0 ? 'ok' : ($rate>=90 ? 'ok' : ($rate>=70 ? 'warn' : 'crit'));

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
        $result = json_encode(['date'=>$date,'houses'=>$houses,'cached'=>false], JSON_UNESCAPED_UNICODE);
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
        set_time_limit(120);
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

        // Секции (прямые дети дома)
        $sections = array_values(array_filter($allLocs, function($l) use ($locId) {
            return (int)$l['parent_id'] === $locId;
        }));

        // МОП = задачи по каждой секции (с wildcard = включая этажи)
        $mopZones = []; $mopDone = 0; $mopMiss = 0;
        foreach ($sections as $sec) {
            $sd = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$sec['id'].'*','limit'=>250]);
            $sm = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$sec['id'].'*','limit'=>250]);
            $d  = count($sd['data'] ?? []);
            $m  = count($sm['data'] ?? []);
            $mopDone += $d; $mopMiss += $m;
            $t  = $d + $m;
            $mopZones[] = ['name'=>$sec['name'],'id'=>$sec['id'],'done'=>$d,'missed'=>$m,'total'=>$t,'pct'=> $t>0?round($d/$t*100):0];
        }
        $mopTot = $mopDone + $mopMiss;

        // ПДТ = двор этого дома из «Территории»
        $pdtDone = 0; $pdtMiss = 0;
        if ($yardId !== null) {
            $yd = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'done','location'=>$yardId.'*','limit'=>250]);
            $ym = tb_get('/reports/tasks', ['date'=>$date,'project'=>TB_PROJECT,'status'=>'missed','location'=>$yardId.'*','limit'=>250]);
            $pdtDone = count($yd['data'] ?? []);
            $pdtMiss = count($ym['data'] ?? []);
        }
        $pdtTot  = $pdtDone + $pdtMiss;

        $houseDone = $mopDone + $pdtDone;
        $houseMiss = $mopMiss + $pdtMiss;
        $houseTot  = $houseDone + $houseMiss;

        echo json_encode([
            'date'   => $date,
            'locId'  => $locId,
            'total'  => ['done'=>$houseDone,'missed'=>$houseMiss,'total'=>$houseTot],
            'mop'    => ['done'=>$mopDone,'missed'=>$mopMiss,'total'=>$mopTot,'pct'=>$mopTot>0?round($mopDone/$mopTot*100):0,'zones'=>$mopZones],
            'pdt'    => ['done'=>$pdtDone,'missed'=>$pdtMiss,'total'=>$pdtTot,'pct'=>$pdtTot>0?round($pdtDone/$pdtTot*100):0,'yardId'=>$yardId,'hasYard'=>$yardId!==null],
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
                '?action=tasks&date=today'      => 'Задачи: выполнено / пропущено',
                '?action=users'                 => 'Список активных сотрудников',
            ]
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE);
}
