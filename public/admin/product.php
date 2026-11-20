<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/src/bootstrap.php';
start_app_session();
require_admin();

$id = (int) ($_GET['id'] ?? $_POST['id'] ?? 0);
$existing = $id > 0 ? product_by_id($id) : null;
if ($id > 0 && $existing === null) {
    flash('That product no longer exists.', 'error');
    redirect('/admin/index.php');
}

$errors = [];
$values = [
    'name' => (string) ($existing['name'] ?? ''),
    'description' => (string) ($existing['description'] ?? ''),
    'price' => $existing === null ? '' : number_format((float) $existing['price'], 2, '.', ''),
    'stock' => $existing === null ? '10' : (string) (int) $existing['stock'],
];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if ($_POST === [] && (int) ($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $errors[] = 'PHP dropped this form because it is larger than post_max_size. Use a smaller image, or raise post_max_size and upload_max_filesize, then restart PHP-FPM.';
    } else {
        csrf_check();
        $action = (string) ($_POST['action'] ?? 'save');

        if ($action === 'delete') {
            if ($existing === null) {
                redirect('/admin/index.php');
            }
            delete_product($id);
            flash('Product deleted.');
            redirect('/admin/index.php');
        }

        $values['name'] = trim((string) ($_POST['name'] ?? ''));
        $values['description'] = trim((string) ($_POST['description'] ?? ''));
        $values['price'] = trim((string) ($_POST['price'] ?? ''));
        $values['stock'] = trim((string) ($_POST['stock'] ?? ''));

        if ($values['name'] === '' || text_length($values['name']) > 200) {
            $errors[] = 'Name is required and must be 200 characters or fewer.';
        }
        if (text_length($values['description']) > 5000) {
            $errors[] = 'Description must be 5000 characters or fewer.';
        }
        $price = normalize_price($values['price']);
        if ($price === null) {
            $errors[] = 'Enter a price like 18 or 18.50.';
        }
        if (!preg_match('/^\d+$/', $values['stock']) || (int) $values['stock'] > 1000000) {
            $errors[] = 'Stock must be a whole number from 0 up.';
        }

        $image = null;
        $removeImage = isset($_POST['remove_image']);
        $file = $_FILES['image'] ?? null;
        if (is_array($file) && (string) ($file['name'] ?? '') !== '') {
            $uploadError = (int) ($file['error'] ?? UPLOAD_ERR_NO_FILE);
            if ($uploadError === UPLOAD_ERR_INI_SIZE || $uploadError === UPLOAD_ERR_FORM_SIZE) {
                $errors[] = 'That image is larger than PHP allows. Raise upload_max_filesize in deploy/99-stateless.ini, restart PHP-FPM, or choose a file under 2 MB.';
            } elseif ($uploadError !== UPLOAD_ERR_OK) {
                $errors[] = 'The image upload failed.';
            } elseif ((int) $file['size'] > 2 * 1024 * 1024) {
                $errors[] = 'Images must be 2 MB or smaller. They are stored in MySQL, not on this instance.';
            } elseif (!is_uploaded_file((string) $file['tmp_name'])) {
                $errors[] = 'The image upload was rejected.';
            } else {
                $info = @getimagesize((string) $file['tmp_name']);
                $allowed = [
                    'image/jpeg' => true,
                    'image/png' => true,
                    'image/gif' => true,
                    'image/webp' => true,
                ];
                $mime = is_array($info) ? (string) ($info['mime'] ?? '') : '';
                if (!isset($allowed[$mime])) {
                    $errors[] = 'Use a JPEG, PNG, GIF, or WebP image.';
                } else {
                    $bytes = file_get_contents((string) $file['tmp_name']);
                    if ($bytes === false) {
                        $errors[] = 'Could not read the uploaded image.';
                    } else {
                        $image = ['bytes' => $bytes, 'mime' => $mime];
                    }
                }
            }
        }

        if ($errors === [] && $price !== null) {
            $stock = (int) $values['stock'];
            if ($existing === null) {
                $newId = insert_product($values['name'], $values['description'], $price, $stock, $image);
                flash('Product added. Refresh the shop until the footer hostname changes: the new product should still be there.');
                redirect('/admin/product.php?id=' . $newId);
            }

            update_product($id, $values['name'], $values['description'], $price, $stock, $image, $removeImage);
            flash('Product saved.');
            redirect('/admin/product.php?id=' . $id);
        }
    }
}

ob_start();
echo admin_tabs($existing === null ? 'new' : 'products');
?>
<h1><?= $existing === null ? 'Add a product' : 'Edit product' ?></h1>
<p class="lede">The photo is written into <code>products.image</code>. It is not saved under <code>/var/www</code>, because that directory is private to this instance.</p>
<?php if ($errors !== []): ?>
  <ul class="errors">
    <?php foreach ($errors as $error): ?>
      <li><?= h($error) ?></li>
    <?php endforeach; ?>
  </ul>
<?php endif; ?>
<form method="post" action="/admin/product.php<?= $id > 0 ? '?id=' . $id : '' ?>" enctype="multipart/form-data" class="stack narrow">
  <?= csrf_field() ?>
  <?php if ($id > 0): ?>
    <input type="hidden" name="id" value="<?= $id ?>">
  <?php endif; ?>
  <input type="hidden" name="action" value="save">
  <div class="field">
    <label for="name">Name</label>
    <input id="name" name="name" required maxlength="200" value="<?= h($values['name']) ?>">
  </div>
  <div class="field">
    <label for="description">Description</label>
    <textarea id="description" name="description" rows="5" maxlength="5000"><?= h($values['description']) ?></textarea>
  </div>
  <div class="inline">
    <div class="field">
      <label for="price">Price (USD)</label>
      <input id="price" name="price" required inputmode="decimal" value="<?= h($values['price']) ?>">
    </div>
    <div class="field">
      <label for="stock">Stock</label>
      <input id="stock" name="stock" required inputmode="numeric" value="<?= h($values['stock']) ?>">
    </div>
  </div>
  <div class="field">
    <label for="image">Photo</label>
    <?php if ($existing !== null && has_image($existing)): ?>
      <div class="current-photo">
        <?= product_image($id, true, (string) $existing['name'], 'mini') ?>
        <label class="check"><input type="checkbox" name="remove_image" value="1"> Remove current photo</label>
      </div>
    <?php endif; ?>
    <input id="image" name="image" type="file" accept="image/jpeg,image/png,image/gif,image/webp">
    <p class="small muted">JPEG, PNG, GIF, or WebP. 2 MB maximum.</p>
  </div>
  <button type="submit"><?= $existing === null ? 'Add product' : 'Save product' ?></button>
</form>
<?php if ($existing !== null): ?>
  <form method="post" action="/admin/product.php?id=<?= $id ?>" class="danger-zone" onsubmit="return confirm('Delete this product?');">
    <?= csrf_field() ?>
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="action" value="delete">
    <button type="submit" class="danger">Delete product</button>
  </form>
<?php endif; ?>
<p><a href="/">View the shop</a></p>
<?php
render($existing === null ? 'Add product' : 'Edit product', (string) ob_get_clean());
