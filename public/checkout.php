<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
start_app_session();

if (isset($_GET['placed'])) {
    $order = order_by_id((int) ($_SESSION['last_order_id'] ?? 0));
    if ($order === null) {
        redirect('/cart.php');
    }

    ob_start();
    ?>
    <h1>Order <?= (int) $order['id'] ?> is placed.</h1>
    <p>A copy of the lines is stored in MySQL, so any instance can show this page.</p>
    <p><?= h($order['customer_name']) ?> · <?= h($order['customer_email']) ?></p>
    <table>
      <thead>
        <tr><th>Product</th><th class="num">Qty</th><th class="num">Price</th></tr>
      </thead>
      <tbody>
        <?php foreach ($order['items'] as $item): ?>
          <tr>
            <td><?= h($item['product_name']) ?></td>
            <td class="num"><?= (int) $item['quantity'] ?></td>
            <td class="num"><?= money($item['price']) ?></td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <p class="price">Total <?= money($order['total']) ?></p>
    <p class="small muted">Recorded <?= h($order['created_at']) ?> UTC.</p>
    <p><a href="/">Back to the shop</a></p>
    <?php
    render('Order placed', (string) ob_get_clean());
    exit;
}

$errors = [];
$name = '';
$email = '';
$lines = cart_lines();
$sellable = [];
foreach ($lines as $line) {
    if (empty($line['missing'])) {
        $sellable[] = $line;
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $name = trim((string) ($_POST['name'] ?? ''));
    $email = trim((string) ($_POST['email'] ?? ''));

    if ($name === '' || text_length($name) > 200) {
        $errors[] = 'Enter a name, up to 200 characters.';
    }
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || text_length($email) > 200) {
        $errors[] = 'Enter a valid email address.';
    }

    if ($errors === []) {
        try {
            $orderId = place_order($name, $email);
            $_SESSION['last_order_id'] = $orderId;
            redirect('/checkout.php?placed=1');
        } catch (RuntimeException $e) {
            if ($e instanceof PDOException) {
                throw $e;
            }
            $errors[] = $e->getMessage();
            $lines = cart_lines();
            $sellable = [];
            foreach ($lines as $line) {
                if (empty($line['missing'])) {
                    $sellable[] = $line;
                }
            }
        }
    }
}

ob_start();
?>
<h1>Checkout</h1>
<?php if ($sellable === []): ?>
  <p class="empty">Nothing to check out. <a href="/">Browse the shop.</a></p>
<?php else: ?>
  <?php if ($errors !== []): ?>
    <ul class="errors">
      <?php foreach ($errors as $error): ?>
        <li><?= h($error) ?></li>
      <?php endforeach; ?>
    </ul>
  <?php endif; ?>

  <div class="split">
    <form method="post" action="/checkout.php" class="stack">
      <?= csrf_field() ?>
      <div class="field">
        <label for="name">Name</label>
        <input id="name" name="name" autocomplete="name" required maxlength="200" value="<?= h($name) ?>">
      </div>
      <div class="field">
        <label for="email">Email</label>
        <input id="email" name="email" type="email" autocomplete="email" required maxlength="200" value="<?= h($email) ?>">
      </div>
      <button type="submit">Place order</button>
    </form>
    <aside class="summary">
      <h2>From the database</h2>
      <ul class="plain">
        <?php foreach ($sellable as $line): ?>
          <li>
            <span><?= (int) $line['qty'] ?> × <?= h($line['name']) ?></span>
            <span><?= money($line['line_total']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="price">Total <?= money(cart_subtotal($sellable)) ?></p>
      <p class="small muted">Stock is locked in MySQL while the order is written, so two instances cannot sell the last item twice.</p>
    </aside>
  </div>
<?php endif; ?>
<?php
render('Checkout', (string) ob_get_clean());
