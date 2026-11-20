<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
start_app_session();
require_admin();

$orders = list_orders();

ob_start();
echo admin_tabs('orders');
?>
<h1>Orders</h1>
<?php if ($orders === []): ?>
  <p class="empty">No orders yet. They appear here no matter which instance handled checkout.</p>
<?php else: ?>
  <?php foreach ($orders as $order): ?>
    <section class="order">
      <header>
        <strong>Order <?= (int) $order['id'] ?></strong>
        <span class="muted"><?= h($order['created_at']) ?> UTC</span>
      </header>
      <p><?= h($order['customer_name']) ?> · <?= h($order['customer_email']) ?></p>
      <ul class="plain">
        <?php foreach ($order['items'] as $item): ?>
          <li>
            <span><?= (int) $item['quantity'] ?> × <?= h($item['product_name']) ?></span>
            <span><?= money($item['price']) ?></span>
          </li>
        <?php endforeach; ?>
      </ul>
      <p class="price">Total <?= money($order['total']) ?></p>
    </section>
  <?php endforeach; ?>
<?php endif; ?>
<?php
render('Orders', (string) ob_get_clean());
