<?php
declare(strict_types=1);

/** Сбросить счётчик 429 (перед sweep / отдельным прогоном). */
function tb_rate_limit_reset(): void
{
    $GLOBALS['_tb_rate_limit_hits'] = 0;
}

/** Число зафиксированных http=429 в ретрай-пути с последнего reset. */
function tb_rate_limit_hits(): int
{
    return (int)($GLOBALS['_tb_rate_limit_hits'] ?? 0);
}

function tb_rate_limit_record(int $http): void
{
    if ($http === 429) {
        $GLOBALS['_tb_rate_limit_hits'] = tb_rate_limit_hits() + 1;
    }
}

/**
 * Один GET с ретраями (как tb_get_all): 429/обрыв не считаем успехом, если нужен ключ data.
 *
 * @return array{ok:bool,http:int,body:string,err:string,attempts:int}
 */
function tb_http_get_with_retry(
    string $url,
    array $headers,
    int $timeout = 30,
    bool $requireDataKey = true,
    int $maxAttempts = 6
): array {
    $last = ['ok' => false, 'http' => 0, 'body' => '', 'err' => 'no attempts', 'attempts' => 0];

    for ($attempt = 0; $attempt < $maxAttempts; $attempt++) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $timeout,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $body = curl_exec($ch);
        $http = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $err  = curl_error($ch);
        curl_close($ch);

        tb_rate_limit_record($http);

        $bodyStr = $body !== false ? $body : '';
        $last = [
            'ok'       => false,
            'http'     => $http,
            'body'     => $bodyStr,
            'err'      => $err !== '' ? $err : ($body === false ? 'curl failed' : ''),
            'attempts' => $attempt + 1,
        ];

        if ($err === '' && $http === 200) {
            if (!$requireDataKey) {
                $last['ok'] = true;
                return $last;
            }
            $decoded = json_decode($bodyStr, true);
            if (is_array($decoded) && array_key_exists('data', $decoded)) {
                $last['ok'] = true;
                return $last;
            }
            // Валидный JSON без data — троттлинг, ждём как tb_get_all
            sleep(1 + $attempt * 2);
            continue;
        }

        if ($body === false || $err !== '') {
            usleep(500000 * ($attempt + 1));
        } else {
            sleep(1 + $attempt * 2);
        }
    }

    error_log(sprintf(
        'tb_http_get_with_retry failed after %d attempts url=%s http=%d err=%s',
        $maxAttempts,
        $url,
        $last['http'],
        $last['err']
    ));

    return $last;
}

/** Успешный ответ TB reports/tasks (или без требования data). */
function tb_multi_response_ok(array $r, bool $requireDataKey = true): bool
{
    if (empty($r['ok'])) {
        return false;
    }
    if (!$requireDataKey) {
        return true;
    }
    $decoded = json_decode($r['body'] ?? '', true);
    return is_array($decoded) && array_key_exists('data', $decoded);
}

/**
 * Параллельные GET с ретраями: сначала batch curl_multi, неуспешные — tb_http_get_with_retry.
 *
 * @param array<string,string> $urls
 * @param list<string>         $headers
 * @return array<string,array{ok:bool,http:int,body:string,err:string,attempts:int}>
 */
function tb_multi_get(array $urls, array $headers, int $concurrency = 10, int $timeout = 30): array
{
    if ($concurrency < 1) {
        $concurrency = 1;
    }
    if ($concurrency > 10) {
        $concurrency = 10;
    }

    $results = [];
    $pending = $urls;

    while ($pending) {
        $batch = array_slice($pending, 0, $concurrency, true);
        $pending = array_slice($pending, $concurrency, null, true);

        $mh = curl_multi_init();
        $handles = [];
        $batchKeys = [];

        foreach ($batch as $key => $url) {
            $ch = curl_init($url);
            curl_setopt_array($ch, [
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_TIMEOUT        => $timeout,
                CURLOPT_HTTPHEADER     => $headers,
                CURLOPT_SSL_VERIFYPEER => true,
            ]);
            $handles[$key] = $ch;
            $batchKeys[$key] = $url;
            curl_multi_add_handle($mh, $ch);
        }

        $running = null;
        do {
            $status = curl_multi_exec($mh, $running);
            if ($running > 0) {
                curl_multi_select($mh, 1.0);
            }
        } while ($running > 0 && $status === CURLM_OK);

        foreach ($handles as $key => $ch) {
            $body = curl_multi_getcontent($ch);
            $attemptResult = [
                'ok'       => false,
                'http'     => (int)curl_getinfo($ch, CURLINFO_HTTP_CODE),
                'body'     => $body !== false ? $body : '',
                'err'      => curl_error($ch),
                'attempts' => 1,
            ];
            if ($attemptResult['err'] === '' && $attemptResult['http'] === 200) {
                $decoded = json_decode($attemptResult['body'], true);
                if (is_array($decoded) && array_key_exists('data', $decoded)) {
                    $attemptResult['ok'] = true;
                }
            }
            curl_multi_remove_handle($mh, $ch);
            curl_close($ch);

            tb_rate_limit_record($attemptResult['http']);

            if ($attemptResult['ok']) {
                $results[$key] = $attemptResult;
            } else {
                $results[$key] = tb_http_get_with_retry($batchKeys[$key], $headers, $timeout, true, 6);
            }
        }

        curl_multi_close($mh);
    }

    return $results;
}
