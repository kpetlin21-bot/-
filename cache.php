<?php
declare(strict_types=1);

/** Канонический ключ кэша: action_auto или action_YYYY-MM-DD[_YYYY-MM-DD…] */
function bd_key(string $action, ?string $date): string
{
    if ($date === null || $date === '') {
        return $action . '_auto';
    }
    $parts = preg_split('/\s*,\s*/', trim($date));
    $norm  = [];
    foreach ($parts as $p) {
        $d = DateTime::createFromFormat('Y-m-d', trim($p));
        $norm[] = $d ? $d->format('Y-m-d') : trim($p);
    }
    return $action . '_' . implode('_', $norm);
}

/**
 * Файловый кэш ответов proxy (без TTL — отдаём последний прогретый срез).
 */
class Cache
{
    private string $dir;

    public function __construct(?string $dir = null)
    {
        $this->dir = $dir ?? (__DIR__ . '/cache');
        if (!is_dir($this->dir)) {
            mkdir($this->dir, 0775, true);
        }
        if (!is_writable($this->dir)) {
            @chmod($this->dir, 0775);
        }
    }

    /** @return array{data: array, age_sec: int}|null */
    public function get(string $key): ?array
    {
        $path = $this->path($key);
        if (!is_file($path)) {
            return null;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return null;
        }
        $decoded = json_decode($raw, true);
        if (!is_array($decoded)) {
            return null;
        }
        return [
            'data'    => $decoded,
            'age_sec' => time() - (int)filemtime($path),
        ];
    }

    public function put(string $key, array $data): void
    {
        $path = $this->path($key);
        file_put_contents(
            $path,
            json_encode($data, JSON_UNESCAPED_UNICODE),
            LOCK_EX
        );
    }

    private function path(string $key): string
    {
        $safe = preg_replace('/[^a-zA-Z0-9_\-\.,]/', '_', $key);
        return $this->dir . '/' . $safe . '.json';
    }
}
