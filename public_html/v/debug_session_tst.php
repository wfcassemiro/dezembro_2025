<?php
session_start();
require_once __DIR__ . '/../config/database.php';

header('Content-Type: application/json');

// Debug da sessão para watchlist
$debug_data = [
    'session_status' => session_status(),
    'session_id' => session_id(),
    'session_data' => $_SESSION,
    'user_id' => $_SESSION['user_id'] ?? 'não definido',
    'is_logged_in' => isLoggedIn(),
    'function_exists' => function_exists('isLoggedIn'),
    'cookies' => $_COOKIE,
    'server_info' => [
        'request_method' => $_SERVER['REQUEST_METHOD'],
        'user_agent' => $_SERVER['HTTP_USER_AGENT'] ?? 'não definido',
        'remote_addr' => $_SERVER['REMOTE_ADDR'] ?? 'não definido'
    ]
];

echo json_encode($debug_data, JSON_PRETTY_PRINT);
?>