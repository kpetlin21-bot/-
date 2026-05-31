<?php
define('DB_HOST', 'p837136.mysql.ihc.ru');
define('DB_NAME', 'p837136_dashbrd');
define('DB_USER', 'p837136_dashbrd');
define('DB_PASS', 'Dashboard123');

function db_connect() {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
         PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]
    );
    return $pdo;
}
