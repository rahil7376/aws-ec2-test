<?php

declare(strict_types=1);

// Same values on every instance. The database is the shared state.
// DB_HOST, DB_PORT, DB_NAME, DB_USER, and DB_PASS override these when set
// in the environment or by the web server.

return [
    'db_host' => '127.0.0.1',
    'db_port' => '3306',
    'db_name' => 'shop',
    'db_user' => 'root',
    'db_pass' => '',
];
