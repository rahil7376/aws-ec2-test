<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
start_app_session();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $action = (string) ($_POST['action'] ?? '');

    if ($action === 'add') {
        $notice = add_to_cart((int) ($_POST['id'] ?? 0), (int) ($_POST['qty'] ?? 1));
        if ($notice !== null) {
            flash($notice, 'warn');
        } else {
            flash('Added to the cart.');
        }
        redirect(local_path((string) ($_POST['return'] ?? ''), '/cart.php'));
    }

    if ($action === 'update') {
        $qty = $_POST['qty'] ?? [];
        $notice = set_cart_quantities(is_array($qty) ? $qty : []);
        flash($notice ?? 'Cart updated.', $notice === null ? 'ok' : 'warn');
        redirect('/cart.php');
    }

    if ($action === 'remove') {
        remove_from_cart((int) ($_POST['id'] ?? 0));
        flash('Removed from the cart.');
        redirect('/cart.php');
    }

    http_response_code(400);
    exit('Unknown cart action.');
}

$lines = cart_lines();
$subtotal = cart_subtotal($lines);
$sellable = false;
foreach ($lines as $line) {
    if (empty($line['missing'])) {
        $sellable = true;
        break;
    }
}

ob_start();
?>
<h1>Cart</h1>
<?php if ($lines === []): ?>
  <p class="empty">The cart is empty. <a href="/">Browse the shop.</a></p>
<?php else: ?>
  <form method="post" action="/cart.php">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="update">
    <table>
      <thead>
        <tr>
          <th>Product</th>
          <th class="num">Price</th>
          <th>Qty</th>
          <th class="num">Line</th>
          <th></th>
        </tr>
      </thead>
      <tbody>
        <?php foreach ($lines as $line): ?>
          <tr>
            <?php if (!empty($line['missing'])): ?>
              <td>A product was removed from the catalogue.</td>
              <td class="num">—</td>
              <td><?= (int) $line['qty'] ?></td>
              <td class="num">—</td>
            <?php else: ?>
              <td>
                <a href="/product.php?id=<?= (int) $line['id'] ?>"><?= h($line['name']) ?></a>
                <?php if ((int) $line['qty'] > (int) $line['stock']): ?>
                  <div class="small warn-text">Only <?= (int) $line['stock'] ?> left.</div>
                <?php endif; ?>
              </td>
              <td class="num"><?= money($line['price']) ?></td>
              <td>
                <input class="qty" type="number" min="0" max="99" name="qty[<?= (int) $line['id'] ?>]" value="<?= (int) $line['qty'] ?>">
              </td>
              <td class="num"><?= money($line['line_total']) ?></td>
            <?php endif; ?>
            <td class="num">
              <button class="linkish" form="remove-<?= (int) $line['id'] ?>" type="submit">Remove</button>
            </td>
          </tr>
        <?php endforeach; ?>
      </tbody>
    </table>
    <div class="cart-actions">
      <button type="submit" class="secondary">Update quantities</button>
      <strong>Subtotal <?= money($subtotal) ?></strong>
    </div>
  </form>
  <?php foreach ($lines as $line): ?>
    <form id="remove-<?= (int) $line['id'] ?>" method="post" action="/cart.php">
      <?= csrf_field() ?>
      <input type="hidden" name="action" value="remove">
      <input type="hidden" name="id" value="<?= (int) $line['id'] ?>">
    </form>
  <?php endforeach; ?>
  <?php if ($sellable): ?>
    <p><a class="button" href="/checkout.php">Checkout</a></p>
  <?php endif; ?>
  <p class="small muted">Setting a quantity to 0 and updating removes that line. Prices are read from MySQL at checkout, not from this page.</p>
<?php endif; ?>
<?php
render('Cart', (string) ob_get_clean());
