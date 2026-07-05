<?php
declare(strict_types=1);

session_start();

$dbDir = __DIR__ . DIRECTORY_SEPARATOR . 'data';
if (!is_dir($dbDir)) {
    mkdir($dbDir, 0777, true);
}

$pdo = new PDO('sqlite:' . $dbDir . DIRECTORY_SEPARATOR . 'inventory.sqlite');
$pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
$pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);
$pdo->exec('PRAGMA foreign_keys = ON');

$pdo->exec("
    CREATE TABLE IF NOT EXISTS products (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_name TEXT NOT NULL,
        attribute_name TEXT NOT NULL,
        size TEXT NOT NULL,
        unit TEXT NOT NULL,
        reorder_quantity REAL NOT NULL DEFAULT 0,
        current_quantity REAL NOT NULL DEFAULT 0,
        supplier_name TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP
    );

    CREATE TABLE IF NOT EXISTS stock_movements (
        id INTEGER PRIMARY KEY AUTOINCREMENT,
        product_id INTEGER NOT NULL,
        movement_type TEXT NOT NULL CHECK (movement_type IN ('ADD', 'DEDUCT')),
        quantity REAL NOT NULL,
        order_reference TEXT,
        supplier_name TEXT,
        notes TEXT,
        created_at TEXT NOT NULL DEFAULT CURRENT_TIMESTAMP,
        FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE CASCADE
    );
");

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

function h(?string $value): string
{
    return htmlspecialchars((string) $value, ENT_QUOTES, 'UTF-8');
}

function number_value(mixed $value): float
{
    return round((float) $value, 3);
}

function redirect_with(string $type, string $message): never
{
    $_SESSION['flash'] = ['type' => $type, 'message' => $message];
    header('Location: ' . strtok($_SERVER['REQUEST_URI'], '?'));
    exit;
}

function require_csrf(): void
{
    $token = $_POST['csrf_token'] ?? '';
    if (!hash_equals($_SESSION['csrf_token'], (string) $token)) {
        redirect_with('error', 'Security token expired. Please try again.');
    }
}

function require_positive_quantity(string $field): float
{
    $quantity = filter_input(INPUT_POST, $field, FILTER_VALIDATE_FLOAT);
    if ($quantity === false || $quantity === null || $quantity <= 0) {
        redirect_with('error', 'Quantity must be greater than zero.');
    }

    return (float) $quantity;
}

function require_non_negative_quantity(string $field): float
{
    $quantity = filter_input(INPUT_POST, $field, FILTER_VALIDATE_FLOAT);
    if ($quantity === false || $quantity === null || $quantity < 0) {
        redirect_with('error', 'Initial quantity cannot be negative.');
    }

    return (float) $quantity;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    require_csrf();
    $action = $_POST['action'] ?? '';

    try {
        if ($action === 'add_product') {
            $productName = trim((string) ($_POST['product_name'] ?? ''));
            $attributeName = trim((string) ($_POST['attribute_name'] ?? ''));
            $size = trim((string) ($_POST['size'] ?? ''));
            $unit = trim((string) ($_POST['unit'] ?? ''));
            $supplierName = trim((string) ($_POST['supplier_name'] ?? ''));
            $initialQuantity = require_non_negative_quantity('initial_quantity');
            $reorderQuantity = filter_input(INPUT_POST, 'reorder_quantity', FILTER_VALIDATE_FLOAT);

            if ($productName === '' || $attributeName === '' || $size === '' || $unit === '') {
                redirect_with('error', 'Product name, attribute, size, and unit are required.');
            }

            if ($reorderQuantity === false || $reorderQuantity === null || $reorderQuantity < 0) {
                redirect_with('error', 'Reorder quantity cannot be negative.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('
                INSERT INTO products (product_name, attribute_name, size, unit, reorder_quantity, current_quantity, supplier_name)
                VALUES (:product_name, :attribute_name, :size, :unit, :reorder_quantity, :current_quantity, :supplier_name)
            ');
            $stmt->execute([
                ':product_name' => $productName,
                ':attribute_name' => $attributeName,
                ':size' => $size,
                ':unit' => strtoupper($unit),
                ':reorder_quantity' => (float) $reorderQuantity,
                ':current_quantity' => $initialQuantity,
                ':supplier_name' => $supplierName,
            ]);

            $productId = (int) $pdo->lastInsertId();
            $stmt = $pdo->prepare('
                INSERT INTO stock_movements (product_id, movement_type, quantity, supplier_name, notes)
                VALUES (:product_id, "ADD", :quantity, :supplier_name, :notes)
            ');
            $stmt->execute([
                ':product_id' => $productId,
                ':quantity' => $initialQuantity,
                ':supplier_name' => $supplierName,
                ':notes' => 'Initial stock while adding product',
            ]);
            $pdo->commit();

            redirect_with('success', 'Product added with initial stock.');
        }

        if ($action === 'add_stock') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $quantity = require_positive_quantity('quantity');
            $supplierName = trim((string) ($_POST['supplier_name'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('UPDATE products SET current_quantity = current_quantity + :quantity, supplier_name = COALESCE(NULLIF(:supplier_name, ""), supplier_name) WHERE id = :id');
            $stmt->execute([':quantity' => $quantity, ':supplier_name' => $supplierName, ':id' => $productId]);

            if ($stmt->rowCount() === 0) {
                $pdo->rollBack();
                redirect_with('error', 'Please choose a valid product.');
            }

            $stmt = $pdo->prepare('
                INSERT INTO stock_movements (product_id, movement_type, quantity, supplier_name, notes)
                VALUES (:product_id, "ADD", :quantity, :supplier_name, :notes)
            ');
            $stmt->execute([
                ':product_id' => $productId,
                ':quantity' => $quantity,
                ':supplier_name' => $supplierName,
                ':notes' => $notes,
            ]);
            $pdo->commit();

            redirect_with('success', 'Stock added successfully.');
        }

        if ($action === 'deduct_stock') {
            $productId = (int) ($_POST['product_id'] ?? 0);
            $quantity = require_positive_quantity('quantity');
            $orderReference = trim((string) ($_POST['order_reference'] ?? ''));
            $notes = trim((string) ($_POST['notes'] ?? ''));

            if ($orderReference === '') {
                redirect_with('error', 'Order reference is required for stock deduction.');
            }

            $pdo->beginTransaction();
            $stmt = $pdo->prepare('SELECT current_quantity FROM products WHERE id = :id');
            $stmt->execute([':id' => $productId]);
            $product = $stmt->fetch();

            if (!$product) {
                $pdo->rollBack();
                redirect_with('error', 'Please choose a valid product.');
            }

            if ((float) $product['current_quantity'] < $quantity) {
                $pdo->rollBack();
                redirect_with('error', 'Not enough stock available for this deduction.');
            }

            $stmt = $pdo->prepare('UPDATE products SET current_quantity = current_quantity - :quantity WHERE id = :id');
            $stmt->execute([':quantity' => $quantity, ':id' => $productId]);

            $stmt = $pdo->prepare('
                INSERT INTO stock_movements (product_id, movement_type, quantity, order_reference, notes)
                VALUES (:product_id, "DEDUCT", :quantity, :order_reference, :notes)
            ');
            $stmt->execute([
                ':product_id' => $productId,
                ':quantity' => $quantity,
                ':order_reference' => $orderReference,
                ':notes' => $notes,
            ]);
            $pdo->commit();

            redirect_with('success', 'Stock deducted and order history saved.');
        }
    } catch (Throwable $exception) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        redirect_with('error', 'Something went wrong: ' . $exception->getMessage());
    }

    redirect_with('error', 'Unknown action.');
}

$products = $pdo->query('
    SELECT *,
        CASE WHEN current_quantity <= reorder_quantity THEN 1 ELSE 0 END AS needs_reorder
    FROM products
    ORDER BY product_name, size
')->fetchAll();

$deductions = $pdo->query('
    SELECT sm.*, p.product_name, p.attribute_name, p.size, p.unit
    FROM stock_movements sm
    JOIN products p ON p.id = sm.product_id
    WHERE sm.movement_type = "DEDUCT"
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 100
')->fetchAll();

$movements = $pdo->query('
    SELECT sm.*, p.product_name, p.attribute_name, p.size, p.unit
    FROM stock_movements sm
    JOIN products p ON p.id = sm.product_id
    ORDER BY sm.created_at DESC, sm.id DESC
    LIMIT 100
')->fetchAll();

$totalProducts = count($products);
$lowStock = count(array_filter($products, static fn(array $product): bool => (int) $product['needs_reorder'] === 1));
$stockUnits = array_sum(array_map(static fn(array $product): float => (float) $product['current_quantity'], $products));
$flash = $_SESSION['flash'] ?? null;
unset($_SESSION['flash']);
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Inventory Management System</title>
    <link rel="stylesheet" href="styles.css">
</head>
<body>
    <header class="app-header">
        <div>
            <p class="eyebrow">Inventory</p>
            <h1>Stock Management</h1>
        </div>
        <div class="header-metrics" aria-label="Inventory summary">
            <span><strong><?= h((string) $totalProducts) ?></strong> Products</span>
            <span><strong><?= h((string) number_value($stockUnits)) ?></strong> Stock</span>
            <span class="<?= $lowStock > 0 ? 'danger' : '' ?>"><strong><?= h((string) $lowStock) ?></strong> Reorder</span>
        </div>
    </header>

    <main class="layout">
        <?php if ($flash): ?>
            <div class="flash <?= h($flash['type']) ?>"><?= h($flash['message']) ?></div>
        <?php endif; ?>

        <section class="panel product-panel">
            <div class="section-title">
                <h2>Add Product</h2>
                <span>Example: Compostable Carry bag, Size 13 X 16, Unit KG</span>
            </div>
            <form method="post" class="form-grid">
                <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                <input type="hidden" name="action" value="add_product">

                <label>
                    Product Name
                    <input name="product_name" required placeholder="Compostable Carry bag">
                </label>
                <label>
                    Product Attribute
                    <input name="attribute_name" required placeholder="Carry bag">
                </label>
                <label>
                    Size
                    <input name="size" required placeholder="13 X 16">
                </label>
                <label>
                    Unit
                    <input name="unit" required placeholder="KG">
                </label>
                <label>
                    Initial Quantity Stock
                    <input name="initial_quantity" type="number" min="0" step="0.001" required placeholder="50">
                </label>
                <label>
                    Reorder Quantity
                    <input name="reorder_quantity" type="number" min="0" step="0.001" required placeholder="10">
                </label>
                <label class="wide">
                    Supplier Name
                    <input name="supplier_name" placeholder="Supplier or vendor name">
                </label>
                <button type="submit">Add Product</button>
            </form>
        </section>

        <section class="split">
            <div class="panel">
                <div class="section-title">
                    <h2>Add Stock</h2>
                    <span>Purchase or inward stock</span>
                </div>
                <form method="post" class="stacked-form">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="add_stock">

                    <label>
                        Product
                        <select name="product_id" required>
                            <option value="">Select product</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= h((string) $product['id']) ?>">
                                    <?= h($product['product_name'] . ' - Size ' . $product['size'] . ' Unit ' . $product['unit']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Quantity
                        <input name="quantity" type="number" min="0.001" step="0.001" required>
                    </label>
                    <label>
                        Supplier Name
                        <input name="supplier_name" placeholder="Supplier for this stock">
                    </label>
                    <label>
                        Notes
                        <textarea name="notes" rows="3" placeholder="Invoice number or remarks"></textarea>
                    </label>
                    <button type="submit">Add Stock</button>
                </form>
            </div>

            <div class="panel">
                <div class="section-title">
                    <h2>Subtract Stock</h2>
                    <span>Saved order-wise for history</span>
                </div>
                <form method="post" class="stacked-form">
                    <input type="hidden" name="csrf_token" value="<?= h($_SESSION['csrf_token']) ?>">
                    <input type="hidden" name="action" value="deduct_stock">

                    <label>
                        Product
                        <select name="product_id" required>
                            <option value="">Select product</option>
                            <?php foreach ($products as $product): ?>
                                <option value="<?= h((string) $product['id']) ?>">
                                    <?= h($product['product_name'] . ' - Size ' . $product['size'] . ' Unit ' . $product['unit'] . ' - Available ' . number_value($product['current_quantity'])) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </label>
                    <label>
                        Order Reference
                        <input name="order_reference" required placeholder="Order No / Job No">
                    </label>
                    <label>
                        Quantity
                        <input name="quantity" type="number" min="0.001" step="0.001" required>
                    </label>
                    <label>
                        Notes
                        <textarea name="notes" rows="3" placeholder="Customer or dispatch remarks"></textarea>
                    </label>
                    <button type="submit" class="danger-button">Subtract Stock</button>
                </form>
            </div>
        </section>

        <section class="panel">
            <div class="section-title">
                <h2>Products</h2>
                <span>Current stock and reorder status</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Product</th>
                            <th>Attribute</th>
                            <th>Size</th>
                            <th>Unit</th>
                            <th>Current Qty</th>
                            <th>Reorder Qty</th>
                            <th>Supplier</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$products): ?>
                            <tr><td colspan="8" class="empty">No products added yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($products as $product): ?>
                            <tr>
                                <td><?= h($product['product_name']) ?></td>
                                <td><?= h($product['attribute_name']) ?></td>
                                <td><?= h($product['size']) ?></td>
                                <td><?= h($product['unit']) ?></td>
                                <td><?= h((string) number_value($product['current_quantity'])) ?></td>
                                <td><?= h((string) number_value($product['reorder_quantity'])) ?></td>
                                <td><?= h($product['supplier_name']) ?></td>
                                <td>
                                    <?php if ((int) $product['needs_reorder'] === 1): ?>
                                        <span class="status low">Reorder</span>
                                    <?php else: ?>
                                        <span class="status ok">In Stock</span>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="section-title">
                <h2>Order-wise Stock Deduction History</h2>
                <span>Latest 100 deductions</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Order</th>
                            <th>Product</th>
                            <th>Size</th>
                            <th>Qty Deducted</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$deductions): ?>
                            <tr><td colspan="6" class="empty">No deductions recorded yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($deductions as $deduction): ?>
                            <tr>
                                <td><?= h($deduction['created_at']) ?></td>
                                <td><?= h($deduction['order_reference']) ?></td>
                                <td><?= h($deduction['product_name'] . ' - ' . $deduction['attribute_name']) ?></td>
                                <td><?= h($deduction['size'] . ' ' . $deduction['unit']) ?></td>
                                <td><?= h((string) number_value($deduction['quantity'])) ?></td>
                                <td><?= h($deduction['notes']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>

        <section class="panel">
            <div class="section-title">
                <h2>Complete Stock Movement History</h2>
                <span>Additions and deductions</span>
            </div>
            <div class="table-wrap">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Type</th>
                            <th>Product</th>
                            <th>Qty</th>
                            <th>Order</th>
                            <th>Supplier</th>
                            <th>Notes</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (!$movements): ?>
                            <tr><td colspan="7" class="empty">No stock movements yet.</td></tr>
                        <?php endif; ?>
                        <?php foreach ($movements as $movement): ?>
                            <tr>
                                <td><?= h($movement['created_at']) ?></td>
                                <td><span class="status <?= $movement['movement_type'] === 'ADD' ? 'ok' : 'low' ?>"><?= h($movement['movement_type']) ?></span></td>
                                <td><?= h($movement['product_name'] . ' - Size ' . $movement['size'] . ' Unit ' . $movement['unit']) ?></td>
                                <td><?= h((string) number_value($movement['quantity'])) ?></td>
                                <td><?= h($movement['order_reference']) ?></td>
                                <td><?= h($movement['supplier_name']) ?></td>
                                <td><?= h($movement['notes']) ?></td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        </section>
    </main>
</body>
</html>
