<?php

declare(strict_types=1);

// Load balancer health check.
// Do not start a session here. A health check has no cookie, and writing a
// session row on every probe would fill the sessions table.
// Point the target group at GET /health.php and accept HTTP 200.
// The body starts with "ok". Do not match on the hostname: it is different
// on every instance, which is what a curl loop against the load balancer shows.

require dirname(__DIR__) . '/src/bootstrap.php';

try {
    db()->query('SELECT 1');
} catch (Throwable $e) {
    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/plain; charset=utf-8');
        header('Cache-Control: no-store');
    }
    echo "db unavailable\n";
    echo 'host: ' . instance_name() . "\n";
    $hint = db_error_hint($e);
    if ($hint !== '') {
        echo $hint . "\n";
    }
    echo $e->getMessage() . "\n";
    exit;
}

header('Content-Type: text/plain; charset=utf-8');
header('Cache-Control: no-store');
echo "ok\n";
echo 'host: ' . instance_name() . "\n";
echo 'address: ' . instance_address() . "\n";
echo "db: ok\n";
