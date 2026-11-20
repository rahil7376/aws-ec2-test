<?php

declare(strict_types=1);

// Photos are bytes in MySQL, not files under this directory.
// Any instance can answer /image.php?id=4 because they share the database.
// This script does not start a session: a session lock here would block the page that requested the image.

require dirname(__DIR__) . '/src/bootstrap.php';

$id = (int) ($_GET['id'] ?? 0);
$stmt = db()->prepare('SELECT image, image_type FROM products WHERE id = ?');
$stmt->execute([$id]);
$row = $stmt->fetch();
if ($row === false) {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "no image\n";
    exit;
}

$bytes = $row['image'];
if (is_resource($bytes)) {
    $bytes = stream_get_contents($bytes);
}
if (!is_string($bytes) || $bytes === '') {
    http_response_code(404);
    header('Content-Type: text/plain; charset=utf-8');
    echo "no image\n";
    exit;
}

$allowed = [
    'image/jpeg' => true,
    'image/png' => true,
    'image/gif' => true,
    'image/webp' => true,
];
$mime = (string) ($row['image_type'] ?? '');
if (!isset($allowed[$mime])) {
    $mime = 'application/octet-stream';
}

header('Content-Type: ' . $mime);
header('X-Content-Type-Options: nosniff');
header('Cache-Control: public, max-age=60');
echo $bytes;
