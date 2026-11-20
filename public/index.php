<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
start_app_session();

$products = list_products();
$admin = current_user() !== null;

ob_start();
?>
<section class="hero">
  <p class="eyebrow">Shared catalogue</p>
  <h1>Any server can sell what is in MySQL.</h1>
  <p class="lede">Products added in the admin show up here for every instance behind the load balancer. Photos are stored in the database, so a second instance can still serve them.</p>
</section>

<?php if ($admin): ?>
  <div class="admin-strip">
    <span>Signed in as <?= h(current_user()['username']) ?>.</span>
    <a class="button" href="/admin/product.php">Add a product</a>
    <a class="button secondary" href="/admin/index.php">Edit the catalogue</a>
  </div>
<?php endif; ?>

<?php if ($products === []): ?>
  <p class="empty">No products yet.<?php if ($admin): ?> Add the first one from the admin.<?php else: ?> An admin can add some after logging in.<?php endif; ?></p>
<?php else: ?>
  <div class="grid">
    <?php foreach ($products as $product): ?>
      <?php $id = (int) $product['id']; ?>
      <article class="card">
        <a class="thumb" href="/product.php?id=<?= $id ?>">
          <?= product_image($id, has_image($product), (string) $product['name'], 'photo') ?>
        </a>
        <h2><a href="/product.php?id=<?= $id ?>"><?= h($product['name']) ?></a></h2>
        <p class="muted small"><?= h(excerpt((string) $product['description'])) ?></p>
        <div class="buy">
          <div>
            <div class="price"><?= money($product['price']) ?></div>
            <div class="small muted"><?= (int) $product['stock'] ?> in stock</div>
          </div>
          <?php if ((int) $product['stock'] > 0): ?>
            <form method="post" action="/cart.php">
              <?= csrf_field() ?>
              <input type="hidden" name="action" value="add">
              <input type="hidden" name="id" value="<?= $id ?>">
              <input type="hidden" name="qty" value="1">
              <input type="hidden" name="return" value="/">
              <button type="submit">Add</button>
            </form>
          <?php else: ?>
            <button type="button" disabled>Sold out</button>
          <?php endif; ?>
        </div>
      </article>
    <?php endforeach; ?>
  </div>
<?php endif; ?>
<?php
render('Shop', (string) ob_get_clean());
