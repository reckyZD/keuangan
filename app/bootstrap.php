<?php
define('APP_PATH', __DIR__);
require APP_PATH . '/config.php';

session_start();
date_default_timezone_set('Asia/Jakarta');

try {
    $pdo = new PDO(
        'mysql:host=' . DB_HOST . ';dbname=' . DB_NAME . ';charset=utf8mb4',
        DB_USER, DB_PASS,
        [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        ]
    );
} catch (PDOException $e) {
    die('Koneksi database gagal. Periksa app/config.php');
}

require APP_PATH . '/functions.php';
