<?php

declare(strict_types=1);

ini_set('display_errors', '0');
ini_set('log_errors', '1');

function h(mixed $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function env_value(string $key): ?string
{
    $candidates = [];
    if (array_key_exists($key, $_SERVER)) {
        $candidates[] = $_SERVER[$key];
    }
    $fromEnv = getenv($key);
    if ($fromEnv !== false) {
        $candidates[] = $fromEnv;
    }

    foreach ($candidates as $value) {
        if ($value === null) {
            continue;
        }
        $value = (string) $value;
        if ($value !== '') {
            return $value;
        }
    }

    return null;
}

function config(): array
{
    static $config = null;
    if ($config !== null) {
        return $config;
    }

    /** @var array<string, mixed> $file */
    $file = require dirname(__DIR__) . '/config.php';
    $config = [
        'db_host' => env_value('DB_HOST') ?? (string) $file['db_host'],
        'db_port' => env_value('DB_PORT') ?? (string) $file['db_port'],
        'db_name' => env_value('DB_NAME') ?? (string) $file['db_name'],
        'db_user' => env_value('DB_USER') ?? (string) $file['db_user'],
        'db_pass' => env_value('DB_PASS') ?? (string) $file['db_pass'],
    ];

    return $config;
}

function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }

    if (!extension_loaded('pdo_mysql')) {
        throw new RuntimeException(
            'The pdo_mysql extension is not installed. On Ubuntu: sudo apt install -y php-mysql'
        );
    }

    $c = config();
    $dsn = sprintf(
        'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
        $c['db_host'],
        $c['db_port'],
        $c['db_name']
    );

    // Native prepares keep image bytes intact. Emulated prepares would escape them as text.
    $options = [
        PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES => false,
    ];
    // Added in PHP 8.4. A short connect timeout lets a blocked security group
    // fail the health check instead of hanging the PHP worker.
    if (defined('PDO::MYSQL_ATTR_CONNECT_TIMEOUT')) {
        $options[PDO::MYSQL_ATTR_CONNECT_TIMEOUT] = 3;
    }

    $pdo = new PDO($dsn, $c['db_user'], $c['db_pass'], $options);
    $pdo->exec("SET time_zone = '+00:00'");

    return $pdo;
}

function start_app_session(): void
{
    if (session_status() === PHP_SESSION_ACTIVE) {
        throw new RuntimeException(
            'PHP already started a session with the file handler (session.auto_start is On). Set session.auto_start = 0 and restart PHP-FPM (sudo systemctl restart php*-fpm) so logins are stored in MySQL.'
        );
    }

    $lifetime = 60 * 60 * 48;

    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    ini_set('session.use_cookies', '1');
    ini_set('session.use_trans_sid', '0');
    ini_set('session.cookie_httponly', '1');
    ini_set('session.gc_maxlifetime', (string) $lifetime);
    ini_set('session.gc_probability', '1');
    ini_set('session.gc_divisor', '100');
    ini_set('session.serialize_handler', 'php');

    $https = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off')
        || strtolower((string) ($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';

    session_name('SHOPSESSID');
    session_set_cookie_params([
        'lifetime' => $lifetime,
        'path' => '/',
        'secure' => $https,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);

    // Keep a reference so shutdown can still write the row after this function returns.
    static $handler = null;
    $handler = new DbSessionHandler(db(), $lifetime);
    session_set_save_handler($handler, true);
    session_start();

    if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
        $_SESSION['cart'] = [];
    }
}

function current_user(): ?array
{
    if (empty($_SESSION['user_id'])) {
        return null;
    }

    return [
        'id' => (int) $_SESSION['user_id'],
        'username' => (string) ($_SESSION['username'] ?? ''),
    ];
}

function require_admin(): void
{
    if (current_user() === null) {
        flash('Log in to manage products.', 'error');
        redirect('/login.php');
    }
}

function redirect(string $to): never
{
    header('Location: ' . $to);
    exit;
}

function local_path(string $value, string $fallback): string
{
    if ($value === '' || $value[0] !== '/') {
        return $fallback;
    }
    if (str_starts_with($value, '//') || str_contains($value, "\n") || str_contains($value, "\r")) {
        return $fallback;
    }

    return $value;
}

function flash(string $message, string $type = 'ok'): void
{
    $_SESSION['flash'] = ['message' => $message, 'type' => $type];
}

function take_flash(): ?array
{
    if (empty($_SESSION['flash']) || !is_array($_SESSION['flash'])) {
        return null;
    }

    $flash = $_SESSION['flash'];
    unset($_SESSION['flash']);

    return $flash;
}

function csrf_token(): string
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        throw new RuntimeException('Session has not been started.');
    }
    if (empty($_SESSION['csrf']) || !is_string($_SESSION['csrf'])) {
        $_SESSION['csrf'] = bin2hex(random_bytes(32));
    }

    return $_SESSION['csrf'];
}

function csrf_field(): string
{
    return '<input type="hidden" name="csrf" value="' . h(csrf_token()) . '">';
}

function csrf_check(): void
{
    $sent = (string) ($_POST['csrf'] ?? '');
    $good = (string) ($_SESSION['csrf'] ?? '');
    if ($sent === '' || $good === '' || !hash_equals($good, $sent)) {
        http_response_code(419);
        header('Content-Type: text/plain; charset=utf-8');
        echo "This form expired. Go back, refresh, and submit it again.\n";
        exit;
    }
}

function money(mixed $amount): string
{
    return '$' . number_format((float) $amount, 2);
}

function text_length(string $value): int
{
    if (function_exists('mb_strlen')) {
        return mb_strlen($value, 'UTF-8');
    }

    return strlen($value);
}

function excerpt(string $text, int $length = 140): string
{
    $text = trim(preg_replace('/\s+/', ' ', $text) ?? '');
    if (function_exists('mb_strimwidth')) {
        return mb_strimwidth($text, 0, $length, '…', 'UTF-8');
    }
    if (strlen($text) <= $length) {
        return $text;
    }

    return rtrim(substr($text, 0, $length - 3)) . '...';
}

function normalize_price(string $raw): ?string
{
    $raw = trim($raw);
    if (str_starts_with($raw, '$')) {
        $raw = trim(substr($raw, 1));
    }
    if (!preg_match('/^\d+(\.\d{1,2})?$/', $raw)) {
        return null;
    }
    if ((float) $raw > 999999.99) {
        return null;
    }

    return number_format((float) $raw, 2, '.', '');
}

function instance_name(): string
{
    $name = gethostname();

    return ($name === false || $name === '') ? 'unknown' : $name;
}

function instance_address(): string
{
    $addr = (string) ($_SERVER['SERVER_ADDR'] ?? '');
    if ($addr !== '' && $addr !== '127.0.0.1' && $addr !== '::1') {
        return $addr;
    }

    $resolved = gethostbyname(instance_name());
    if ($resolved !== '' && $resolved !== instance_name()) {
        return $resolved;
    }

    return $addr !== '' ? $addr : 'unknown';
}

function product_image(int $id, bool $hasImage, string $name, string $class): string
{
    if (!$hasImage) {
        $letter = strtoupper(substr($name, 0, 1));

        return '<span class="placeholder ' . h($class) . '" aria-hidden="true">' . h($letter) . '</span>';
    }

    return '<img class="' . h($class) . '" src="/image.php?id=' . $id . '" alt="' . h($name) . '">';
}

function admin_tabs(string $active): string
{
    $tabs = [
        'products' => ['/admin/index.php', 'Products'],
        'new' => ['/admin/product.php', 'Add product'],
        'orders' => ['/admin/orders.php', 'Orders'],
    ];

    $html = '<nav class="tabs">';
    foreach ($tabs as $key => [$href, $label]) {
        $class = $key === $active ? ' class="is-active"' : '';
        $html .= '<a' . $class . ' href="' . h($href) . '">' . h($label) . '</a>';
    }
    $html .= '</nav>';

    return $html;
}

function render(string $title, string $body): void
{
    if (!headers_sent()) {
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store, no-cache, must-revalidate');
        header('Pragma: no-cache');
        header('X-Content-Type-Options: nosniff');
    }

    $flash = take_flash();
    $user = current_user();
    $count = cart_count();
    $sessionId = session_id();

    echo '<!DOCTYPE html><html lang="en"><head>';
    echo '<meta charset="utf-8">';
    echo '<meta name="viewport" content="width=device-width, initial-scale=1">';
    echo '<meta name="robots" content="noindex">';
    echo '<title>' . h($title) . ' · Stateless Goods</title>';
    echo '<link rel="icon" href="/favicon.svg" type="image/svg+xml">';
    echo '<link rel="stylesheet" href="/assets/app.css">';
    echo '</head><body>';
    echo '<header class="top"><div class="wrap site-header">';
    echo '<a class="brand" href="/">Stateless Goods</a><nav>';
    echo '<a href="/">Shop</a>';
    echo '<a href="/cart.php">Cart (' . $count . ')</a>';
    if ($user !== null) {
        echo '<a href="/admin/index.php">Admin</a>';
        echo '<a href="/logout.php">Log out</a>';
    } else {
        echo '<a href="/login.php">Admin login</a>';
    }
    echo '</nav></div></header>';
    echo '<main class="wrap">';
    if ($flash !== null) {
        $type = (string) ($flash['type'] ?? 'ok');
        if (!in_array($type, ['ok', 'warn', 'error'], true)) {
            $type = 'ok';
        }
        echo '<p class="flash ' . h($type) . '">' . h($flash['message'] ?? '') . '</p>';
    }
    echo $body;
    echo '</main>';
    echo '<footer class="instance"><div class="wrap">';
    echo '<p><strong>Served by</strong> ' . h(instance_name()) . ' · ' . h(instance_address()) . '</p>';
    echo '<p>Session <code>' . h($sessionId) . '</code> is a row in MySQL <code>sessions</code>, not a file on this instance.</p>';
    echo '<p>Refresh. With two or more targets and stickiness off, this hostname changes. The cart and the login stay.</p>';
    echo '</div></footer></body></html>';
}

function db_error_hint(Throwable $e): string
{
    $message = $e->getMessage();
    if (str_contains($message, 'pdo_mysql')) {
        return 'Install php-mysql and restart PHP-FPM. On Ubuntu: sudo apt install -y php-mysql && sudo systemctl restart php*-fpm';
    }
    if (!$e instanceof PDOException) {
        return '';
    }
    if (str_contains($message, '[2002]') || str_contains($message, '[2003]') || str_contains($message, 'timed out')) {
        return 'This instance cannot open a TCP connection to MySQL. Check the RDS endpoint in config.php and the security group that allows the instance to reach RDS on port 3306.';
    }
    if (str_contains($message, '[1045]')) {
        return 'MySQL rejected the username or password from config.php.';
    }
    if (str_contains($message, '[1049]')) {
        return 'That database does not exist yet. Create it, then import sql/schema.sql.';
    }
    if (str_contains($message, '[1146]') || str_contains($message, "doesn't exist") || str_contains($message, '42S02')) {
        return 'The shop tables are missing. Import sql/schema.sql into this database once.';
    }
    if (str_contains($message, 'session.auto_start')) {
        return '';
    }

    return '';
}

set_exception_handler(static function (Throwable $e): void {
    if (PHP_SAPI === 'cli') {
        fwrite(STDERR, $e->getMessage() . PHP_EOL);
        exit(1);
    }

    $isDb = $e instanceof PDOException || str_contains($e->getMessage(), 'pdo_mysql');
    $title = $isDb
        ? 'The shop cannot reach its database.'
        : 'This page hit an error.';
    $hint = db_error_hint($e);

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
        header('Cache-Control: no-store');
    }

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8"><title>Shop unavailable</title>';
    echo '<style>body{font:16px/1.5 system-ui,sans-serif;background:#f4f1ea;color:#221e1a;margin:0}main{max-width:40rem;margin:10vh auto;padding:0 1.25rem}pre{white-space:pre-wrap;background:#fff;border:1px solid #e3daca;padding:.75rem;border-radius:8px}</style>';
    echo '</head><body><main><h1>' . h($title) . '</h1>';
    if ($hint !== '') {
        echo '<p>' . h($hint) . '</p>';
    }
    echo '<pre>' . h($e->getMessage()) . '</pre>';
    echo '<p>On the instance, run <code>php bin/check.php</code> from the project directory.</p>';
    echo '</main></body></html>';
    exit(1);
});

require_once __DIR__ . '/session.php';
require_once __DIR__ . '/catalog.php';
