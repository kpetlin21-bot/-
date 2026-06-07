<?php

function projects_cfg(): array {
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/projects_config.php';
    }
    return $cfg;
}

function projects_slugs_by_id(): array {
    return projects_cfg()['slugs'];
}

function projects_names_by_id(): array {
    return projects_cfg()['names'];
}

function projects_slug_to_id(string $slug): ?int {
    $slug = trim($slug);
    if ($slug === '') {
        return null;
    }
    foreach (projects_slugs_by_id() as $id => $s) {
        if ($s === $slug) {
            return (int)$id;
        }
    }
    return null;
}

function projects_id_to_slug(int $projectId): string {
    return projects_slugs_by_id()[$projectId] ?? '';
}

function projects_list_for_api(): array {
    $out = [];
    foreach (projects_slugs_by_id() as $id => $slug) {
        $out[] = [
            'id'   => (int)$id,
            'slug' => $slug,
            'name' => projects_names_by_id()[$id] ?? ('ЖК #' . $id),
        ];
    }
    return $out;
}
