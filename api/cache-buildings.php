<?php
// Запускается вручную или по cron раз в месяц (здания меняются редко)
// Использование: php cache-buildings.php  или  GET /api/cache-buildings.php?secret=XXXX

define('SECRET', getenv('CACHE_SECRET') ?: 'cleansyst2025');
if (php_sapi_name() !== 'cli' && ($_GET['secret'] ?? '') !== SECRET) {
    http_response_code(403);
    exit('Forbidden');
}

$complexes = [
    'kolpino'           => '59.768,30.595,59.782,30.612',
    'astrid'            => '59.742,30.598,59.755,30.613',
    'kurortny'          => '60.111,30.195,60.124,30.210',
    'kosmonavtov-11'    => '56.858,60.599,56.872,60.613',
    'iset-park'         => '56.797,60.636,56.810,60.650',
    'utes'              => '56.779,60.651,56.793,60.666',
    'aston-dvizhenie'   => '56.868,60.532,56.882,60.546',
    'aston-reforma'     => '56.819,60.648,56.832,60.661',
    'noksa-park'        => '55.800,49.218,55.813,49.231',
    'tvoya-privilegiya' => '56.759,60.528,56.773,60.541',
    'mily-dom'          => '56.791,60.581,56.806,60.597',
    'river-park'        => '56.832,60.613,56.846,60.628',
];

$cacheDir = __DIR__ . '/../data/buildings';
if (!is_dir($cacheDir)) {
    mkdir($cacheDir, 0755, true);
}

$overpassUrl = 'https://overpass-api.de/api/interpreter';

foreach ($complexes as $slug => $bbox) {
    echo "[$slug] запрос... ";

    // Здания
    $query = "[out:json][timeout:30];way[building]($bbox);out body;>;out skel qt;";
    $result = fetchOverpass($overpassUrl, $query);
    if ($result) {
        file_put_contents("$cacheDir/$slug-buildings.json", $result);
        echo "здания ✓ ";
    } else {
        echo "здания ОШИБКА ";
    }

    sleep(3);

    // Дороги
    $query = "[out:json][timeout:30];way[highway~\"footway|path|service|residential\"]($bbox);out body;>;out skel qt;";
    $result = fetchOverpass($overpassUrl, $query);
    if ($result) {
        file_put_contents("$cacheDir/$slug-roads.json", $result);
        echo "дороги ✓";
    } else {
        echo "дороги ОШИБКА";
    }

    echo "\n";
    sleep(3);
}

echo "\nГотово. Файлы в $cacheDir\n";

function fetchOverpass($url, $query)
{
    $ctx = stream_context_create(['http' => [
        'method'  => 'POST',
        'header'  => "Content-Type: application/x-www-form-urlencoded\r\nUser-Agent: cleansyst-dashboard/1.0\r\n",
        'content' => 'data=' . rawurlencode($query),
        'timeout' => 35,
    ]]);
    $resp = @file_get_contents($url, false, $ctx);
    if (!$resp || !str_starts_with(trim($resp), '{')) {
        return null;
    }
    return $resp;
}
