<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';

$c = config();
echo 'DB host: ' . $c['db_host'] . PHP_EOL;
echo 'DB name: ' . $c['db_name'] . PHP_EOL;
echo 'DB user: ' . $c['db_user'] . PHP_EOL;

$version = (string) db()->query('SELECT VERSION()')->fetchColumn();
echo 'MySQL: ' . $version . PHP_EOL;

$tables = db()->query('SHOW TABLES')->fetchAll(PDO::FETCH_COLUMN);
echo 'Tables: ' . ($tables === [] ? 'none' : implode(', ', $tables)) . PHP_EOL;

$expected = ['users', 'products', 'orders', 'order_items', 'sessions'];
$missing = array_values(array_diff($expected, $tables));
if ($missing !== []) {
    fwrite(STDERR, 'Missing: ' . implode(', ', $missing) . PHP_EOL);
    fwrite(STDERR, "Import sql/schema.sql into this database once." . PHP_EOL);
    exit(1);
}

$products = (int) db()->query('SELECT COUNT(*) FROM products')->fetchColumn();
$sessions = (int) db()->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
echo 'Products: ' . $products . PHP_EOL;
echo 'Sessions: ' . $sessions . PHP_EOL;
echo "ok" . PHP_EOL;
