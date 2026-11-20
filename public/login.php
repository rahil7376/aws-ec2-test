<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
start_app_session();

if (current_user() !== null) {
    redirect('/admin/index.php');
}

$error = null;
$username = '';

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $username = trim((string) ($_POST['username'] ?? ''));
    $password = (string) ($_POST['password'] ?? '');

    $stmt = db()->prepare('SELECT id, username, password_hash FROM users WHERE username = ?');
    $stmt->execute([$username]);
    $user = $stmt->fetch();

    if ($user === false || !password_verify($password, (string) $user['password_hash'])) {
        $error = 'Unknown username or password.';
    } else {
        session_regenerate_id(true);
        $_SESSION['user_id'] = (int) $user['id'];
        $_SESSION['username'] = (string) $user['username'];
        if (!isset($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
            $_SESSION['cart'] = [];
        }
        redirect('/admin/index.php');
    }
}

ob_start();
?>
<h1>Admin login</h1>
<p class="lede">The session cookie is only an id. The login itself is the matching row in <code>sessions</code>, so the next instance can read it.</p>
<?php if ($error !== null): ?>
  <p class="flash error"><?= h($error) ?></p>
<?php endif; ?>
<form method="post" action="/login.php" class="stack narrow">
  <?= csrf_field() ?>
  <div class="field">
    <label for="username">Username</label>
    <input id="username" name="username" autocomplete="username" required value="<?= h($username) ?>">
  </div>
  <div class="field">
    <label for="password">Password</label>
    <input id="password" name="password" type="password" autocomplete="current-password" required>
  </div>
  <button type="submit">Log in</button>
</form>
<p class="lab-note">Lab account: <code>admin</code> / <code>admin123</code>. Change this before the site is public.</p>
<?php
render('Log in', (string) ob_get_clean());
