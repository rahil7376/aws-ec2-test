<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
start_app_session();

$_SESSION = [];

$params = session_get_cookie_params();
setcookie(session_name(), '', [
    'expires' => time() - 42000,
    'path' => $params['path'] ?: '/',
    'domain' => $params['domain'] ?? '',
    'secure' => (bool) $params['secure'],
    'httponly' => (bool) $params['httponly'],
    'samesite' => $params['samesite'] ?? 'Lax',
]);

session_destroy();
redirect('/');
