<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
start_app_session();
require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if ((string) ($_POST['action'] ?? '') !== 'delete') {
        http_response_code(400);
        exit('Unknown action.');
    }
    delete_product((int) ($_POST['id'] ?? 0));
    flash('Product deleted.');
    redirect('/admin/index.php');
}

$products = list_products();

ob_start();
echo admin_tabs('products');
?>
<div class="stats">
  <span><?= count($products) ?> products</span>
  <span><?= count_orders() ?> orders</span>
  <span><?= count_sessions() ?> sessions in MySQL</span>
</div>
<?php if ($products === []): ?>
  <p class="empty">No products yet. <a href="/admin/product.php">Add one.</a></p>
<?php else: ?>
  <table>
    <thead>
      <tr>
        <th></th>
        <th>Name</th>
        <th class="num">Price</th>
        <th class="num">Stock</th>
        <th></th>
      </tr>
    </thead>
    <tbody>
      <?php foreach ($products as $product): ?>
        <?php $id = (int) $product['id']; ?>
        <tr>
          <td><?= product_image($id, has_image($product), (string) $product['name'], 'mini') ?></td>
          <td><a href="/admin/product.php?id=<?= $id ?>"><?= h($product['name']) ?></a></td>
          <td class="num"><?= money($product['price']) ?></td>
          <td class="num"><?= (int) $product['stock'] ?></td>
          <td class="num">
            <form method="post" action="/admin/index.php" class="inline-form" onsubmit="return confirm('Delete this product?');">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="delete">
              <input type="hidden" name="id" value="<?= $id ?>">
              <button type="submit" class="danger">Delete</button>
            </form>
          </td>
        </tr>
      <?php endforeach; ?>
    </tbody>
  </table>
<?php endif; ?>
<?php
render('Products', (string) ob_get_clean());
