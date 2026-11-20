<?php

declare(strict_types=1);

require dirname(__DIR__) . '/src/bootstrap.php';
start_app_session();

$product = product_by_id((int) ($_GET['id'] ?? 0));
if ($product === null) {
    http_response_code(404);
    render(
        'Not found',
        '<h1>That product is not in the catalogue.</h1><p><a href="/">Back to the shop</a></p>'
    );
    exit;
}

$id = (int) $product['id'];
$return = '/product.php?id=' . $id;

ob_start();
?>
<article class="product-layout">
  <div>
    <?= product_image($id, has_image($product), (string) $product['name'], 'photo') ?>
  </div>
  <div>
    <p class="eyebrow">Product</p>
    <h1><?= h($product['name']) ?></h1>
    <p class="price price-lg"><?= money($product['price']) ?></p>
    <p class="muted"><?= (int) $product['stock'] ?> in stock</p>
    <div class="description"><?= nl2br(h((string) $product['description'])) ?></div>
    <?php if ((int) $product['stock'] > 0): ?>
      <form method="post" action="/cart.php" class="add-form">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="add">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="return" value="<?= h($return) ?>">
        <label for="qty">Quantity</label>
        <div class="inline">
          <input class="qty" id="qty" name="qty" type="number" min="1" max="<?= min(99, (int) $product['stock']) ?>" value="1">
          <button type="submit">Add to cart</button>
        </div>
      </form>
    <?php else: ?>
      <p>Sold out.</p>
    <?php endif; ?>
    <p><a href="/">Back to the shop</a></p>
  </div>
</article>
<?php
render((string) $product['name'], (string) ob_get_clean());
