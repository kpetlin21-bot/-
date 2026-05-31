<?php
/**
 * Список ЖК для навигации: GET /projects.php
 */
header('Content-Type: application/json; charset=utf-8');
header('Access-Control-Allow-Origin: *');

require_once __DIR__ . '/projects_lib.php';

echo json_encode(projects_list_for_api(), JSON_UNESCAPED_UNICODE);
