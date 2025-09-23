<?php

declare(strict_types=1);
//starting the session snd puting some cookies in the user mouth
if (session_status() === PHP_SESSION_NONE) {
    session_start([
        'cookie_httponly' => true,
        'cookie_samesite' => 'Lax',
    ]);
}

//function PDO
function pdo() : PDO {
    static $pdo = null;
    if($pdo)return $pdo;
    $dbHost = 'localhost';
    $dbName = 'mesmtf';
    $dbUser = 'root';
    $dbPass = '';
    $dsn= "mysql:host={$dbHost};dbname={$dbName};charset=utf8mb4";
    $pdo = new PDO($dsn,$dbUser,$dbPass,[
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ]);
    return $pdo;
}