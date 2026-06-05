<?php
declare(strict_types=1);

/**
 * Проверка формы ответа при пустом кэше: HTTP 202 + {"warming":true}
 */
$root = dirname(__DIR__);
$_SERVER['REQUEST_METHOD'] = 'GET';
define('TB_PROXY_CLI_FUNCTIONS_ONLY', true);
require_once $root . '/cache.php';
require_once $root . '/warm_cache.php';
require_once $root . '/tb_multi.php';
require_once $root . '/proxy.php';

$tmpdir = sys_get_temp_dir() . '/hb_empty_cache_' . getmypid();
mkdir($tmpdir, 0775, true);
$cache = new Cache($tmpdir);
$key = 'house_breakdown_test_empty';

$_GET = ['action' => 'house_breakdown', 'date' => '2026-06-04'];
$_SERVER['REQUEST_METHOD'] = 'GET';

ob_start();
proxy_serve_cached($cache, $key, static fn () => ['houses' => []]);
$body = ob_get_clean();
$code = http_response_code();
$json = json_decode($body, true);

$ok = $code === 202
    && is_array($json)
    && count($json) === 1
    && array_key_exists('warming', $json)
    && $json['warming'] === true;

echo json_encode([
    'http_code' => $code,
    'body'      => $body,
    'shape_ok'  => $ok,
], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . "\n";

array_map('unlink', glob($tmpdir . '/*') ?: []);
@rmdir($tmpdir);

exit($ok ? 0 : 1);
