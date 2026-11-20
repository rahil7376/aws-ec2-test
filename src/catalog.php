<?php

declare(strict_types=1);

function list_products(): array
{
    $stmt = db()->query(
        'SELECT id, name, description, price, stock,
                (image IS NOT NULL AND OCTET_LENGTH(image) > 0) AS has_image,
                created_at
         FROM products
         ORDER BY id DESC'
    );

    return $stmt->fetchAll();
}

function product_by_id(int $id): ?array
{
    if ($id < 1) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT id, name, description, price, stock,
                (image IS NOT NULL AND OCTET_LENGTH(image) > 0) AS has_image,
                created_at
         FROM products
         WHERE id = ?'
    );
    $stmt->execute([$id]);
    $row = $stmt->fetch();

    return $row === false ? null : $row;
}

function has_image(array $product): bool
{
    return (int) ($product['has_image'] ?? 0) === 1;
}

function insert_product(string $name, string $description, string $price, int $stock, ?array $image): int
{
    $stmt = db()->prepare(
        'INSERT INTO products (name, description, price, stock, image, image_type)
         VALUES (?, ?, ?, ?, ?, ?)'
    );
    $stmt->bindValue(1, $name, PDO::PARAM_STR);
    $stmt->bindValue(2, $description, PDO::PARAM_STR);
    $stmt->bindValue(3, $price, PDO::PARAM_STR);
    $stmt->bindValue(4, $stock, PDO::PARAM_INT);
    if ($image === null) {
        $stmt->bindValue(5, null, PDO::PARAM_NULL);
        $stmt->bindValue(6, null, PDO::PARAM_NULL);
    } else {
        $stmt->bindValue(5, $image['bytes'], PDO::PARAM_LOB);
        $stmt->bindValue(6, $image['mime'], PDO::PARAM_STR);
    }
    $stmt->execute();

    return (int) db()->lastInsertId();
}

function update_product(
    int $id,
    string $name,
    string $description,
    string $price,
    int $stock,
    ?array $image,
    bool $removeImage
): void {
    $stmt = db()->prepare(
        'UPDATE products SET name = ?, description = ?, price = ?, stock = ? WHERE id = ?'
    );
    $stmt->execute([$name, $description, $price, $stock, $id]);

    if ($image !== null) {
        $img = db()->prepare('UPDATE products SET image = ?, image_type = ? WHERE id = ?');
        $img->bindValue(1, $image['bytes'], PDO::PARAM_LOB);
        $img->bindValue(2, $image['mime'], PDO::PARAM_STR);
        $img->bindValue(3, $id, PDO::PARAM_INT);
        $img->execute();

        return;
    }

    if ($removeImage) {
        $clear = db()->prepare('UPDATE products SET image = NULL, image_type = NULL WHERE id = ?');
        $clear->execute([$id]);
    }
}

function delete_product(int $id): void
{
    $stmt = db()->prepare('DELETE FROM products WHERE id = ?');
    $stmt->execute([$id]);
    unset($_SESSION['cart'][$id]);
}

function cart_count(): int
{
    $count = 0;
    foreach ($_SESSION['cart'] ?? [] as $qty) {
        $count += max(0, (int) $qty);
    }

    return $count;
}

function add_to_cart(int $id, int $qty): ?string
{
    if ($qty < 1) {
        $qty = 1;
    }
    if ($qty > 99) {
        $qty = 99;
    }

    $product = product_by_id($id);
    if ($product === null) {
        return 'That product is not in the catalogue.';
    }

    $stock = (int) $product['stock'];
    if ($stock < 1) {
        return 'That product is out of stock.';
    }

    $next = (int) ($_SESSION['cart'][$id] ?? 0) + $qty;
    if ($next > $stock) {
        $_SESSION['cart'][$id] = $stock;

        return 'Only ' . $stock . ' left. The cart was adjusted.';
    }

    $_SESSION['cart'][$id] = $next;

    return null;
}

/**
 * @param array<int|string, mixed> $input
 */
function set_cart_quantities(array $input): ?string
{
    $notice = null;
    $cart = [];

    foreach ($input as $rawId => $rawQty) {
        $id = (int) $rawId;
        $qty = (int) $rawQty;
        if ($id < 1 || $qty < 1) {
            continue;
        }
        if ($qty > 99) {
            $qty = 99;
        }

        $product = product_by_id($id);
        if ($product === null) {
            continue;
        }

        $stock = (int) $product['stock'];
        if ($qty > $stock) {
            $qty = $stock;
            $notice = 'Some quantities were reduced to what is in stock.';
        }
        if ($qty < 1) {
            continue;
        }

        $cart[$id] = $qty;
    }

    $_SESSION['cart'] = $cart;

    return $notice;
}

function remove_from_cart(int $id): void
{
    unset($_SESSION['cart'][$id]);
}

function cart_lines(): array
{
    $cart = $_SESSION['cart'] ?? [];
    if ($cart === []) {
        return [];
    }

    $ids = [];
    foreach (array_keys($cart) as $id) {
        $id = (int) $id;
        if ($id > 0) {
            $ids[] = $id;
        }
    }
    $ids = array_values(array_unique($ids));
    if ($ids === []) {
        return [];
    }

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $stmt = db()->prepare(
        "SELECT id, name, price, stock,
                (image IS NOT NULL AND OCTET_LENGTH(image) > 0) AS has_image
         FROM products
         WHERE id IN ($placeholders)"
    );
    $stmt->execute($ids);

    $found = [];
    foreach ($stmt->fetchAll() as $row) {
        $found[(int) $row['id']] = $row;
    }

    $lines = [];
    foreach ($cart as $rawId => $rawQty) {
        $id = (int) $rawId;
        $qty = (int) $rawQty;
        if ($id < 1 || $qty < 1) {
            continue;
        }
        if (!isset($found[$id])) {
            $lines[] = [
                'id' => $id,
                'missing' => true,
                'qty' => $qty,
            ];
            continue;
        }

        $product = $found[$id];
        $lines[] = [
            'id' => $id,
            'missing' => false,
            'name' => $product['name'],
            'price' => $product['price'],
            'stock' => (int) $product['stock'],
            'qty' => $qty,
            'has_image' => has_image($product),
            'line_total' => (float) $product['price'] * $qty,
        ];
    }

    return $lines;
}

function cart_subtotal(array $lines): float
{
    $total = 0.0;
    foreach ($lines as $line) {
        if (empty($line['missing'])) {
            $total += (float) $line['line_total'];
        }
    }

    return $total;
}

function place_order(string $name, string $email): int
{
    $cart = [];
    foreach ($_SESSION['cart'] ?? [] as $rawId => $rawQty) {
        $id = (int) $rawId;
        $qty = (int) $rawQty;
        if ($id > 0 && $qty > 0) {
            $cart[$id] = $qty;
        }
    }

    if ($cart === []) {
        throw new RuntimeException('Your cart is empty.');
    }

    $pdo = db();
    $pdo->beginTransaction();

    try {
        $ids = array_keys($cart);
        sort($ids);
        $placeholders = implode(',', array_fill(0, count($ids), '?'));

        // Lock in primary-key order so two checkouts cannot deadlock on each other.
        $stmt = $pdo->prepare(
            "SELECT id, name, price, stock FROM products WHERE id IN ($placeholders) FOR UPDATE"
        );
        $stmt->execute($ids);

        $products = [];
        foreach ($stmt->fetchAll() as $row) {
            $products[(int) $row['id']] = $row;
        }

        $lines = [];
        $totalCents = 0;
        foreach ($ids as $id) {
            $qty = $cart[$id];
            if (!isset($products[$id])) {
                continue;
            }

            $stock = (int) $products[$id]['stock'];
            if ($qty > $stock) {
                $label = (string) $products[$id]['name'];
                throw new RuntimeException($label . ' only has ' . $stock . ' left.');
            }

            $cents = (int) round((float) $products[$id]['price'] * 100);
            $totalCents += $cents * $qty;
            $lines[] = [
                'id' => $id,
                'name' => (string) $products[$id]['name'],
                'price' => number_format($cents / 100, 2, '.', ''),
                'qty' => $qty,
            ];
        }

        if ($lines === []) {
            throw new RuntimeException('Your cart is empty.');
        }

        $order = $pdo->prepare(
            'INSERT INTO orders (customer_name, customer_email, total) VALUES (?, ?, ?)'
        );
        $order->execute([
            $name,
            $email,
            number_format($totalCents / 100, 2, '.', ''),
        ]);
        $orderId = (int) $pdo->lastInsertId();

        $item = $pdo->prepare(
            'INSERT INTO order_items (order_id, product_id, product_name, price, quantity)
             VALUES (?, ?, ?, ?, ?)'
        );
        $stockUpdate = $pdo->prepare(
            'UPDATE products SET stock = stock - ? WHERE id = ? AND stock >= ?'
        );

        foreach ($lines as $line) {
            $item->execute([$orderId, $line['id'], $line['name'], $line['price'], $line['qty']]);
            $stockUpdate->execute([$line['qty'], $line['id'], $line['qty']]);
            if ($stockUpdate->rowCount() !== 1) {
                throw new RuntimeException('Stock changed while the order was placed. Try again.');
            }
        }

        $pdo->commit();
        $_SESSION['cart'] = [];

        return $orderId;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        throw $e;
    }
}

function order_by_id(int $id): ?array
{
    if ($id < 1) {
        return null;
    }

    $stmt = db()->prepare(
        'SELECT o.id, o.customer_name, o.customer_email, o.total, o.created_at,
                i.product_name, i.price AS item_price, i.quantity
         FROM orders o
         JOIN order_items i ON i.order_id = o.id
         WHERE o.id = ?
         ORDER BY i.id ASC'
    );
    $stmt->execute([$id]);
    $rows = $stmt->fetchAll();
    if ($rows === []) {
        return null;
    }

    $order = [
        'id' => (int) $rows[0]['id'],
        'customer_name' => $rows[0]['customer_name'],
        'customer_email' => $rows[0]['customer_email'],
        'total' => $rows[0]['total'],
        'created_at' => $rows[0]['created_at'],
        'items' => [],
    ];
    foreach ($rows as $row) {
        $order['items'][] = [
            'product_name' => $row['product_name'],
            'price' => $row['item_price'],
            'quantity' => $row['quantity'],
        ];
    }

    return $order;
}

function list_orders(): array
{
    $stmt = db()->query(
        'SELECT o.id, o.customer_name, o.customer_email, o.total, o.created_at,
                i.product_name, i.price AS item_price, i.quantity
         FROM orders o
         JOIN order_items i ON i.order_id = o.id
         ORDER BY o.id DESC, i.id ASC'
    );

    $orders = [];
    foreach ($stmt->fetchAll() as $row) {
        $id = (int) $row['id'];
        if (!isset($orders[$id])) {
            $orders[$id] = [
                'id' => $id,
                'customer_name' => $row['customer_name'],
                'customer_email' => $row['customer_email'],
                'total' => $row['total'],
                'created_at' => $row['created_at'],
                'items' => [],
            ];
        }
        $orders[$id]['items'][] = [
            'product_name' => $row['product_name'],
            'price' => $row['item_price'],
            'quantity' => $row['quantity'],
        ];
    }

    return array_values($orders);
}

function count_sessions(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM sessions')->fetchColumn();
}

function count_orders(): int
{
    return (int) db()->query('SELECT COUNT(*) FROM orders')->fetchColumn();
}
