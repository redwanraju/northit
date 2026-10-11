<?php
// ============================================================
// EDIT INVOICE CART - POS STYLE
// Based on your POS cart page, but isolated for invoice edit.
// It uses a separate session cart key per order: edit_cart_{ORDER_ID}
// ============================================================

if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

if (file_exists("permission.php")) {
    include "permission.php";
}
if (file_exists("auth.php")) {
    include "auth.php";
    if (function_exists('requireLogin')) {
        requireLogin();
    }
}

include "../config/db.php";

$shop_id = tenantShopId();
$user_id = (int)($_SESSION['user_id'] ?? 0);
$user_name = $_SESSION['user_name'] ?? 'User';

if ($user_id == 0) {
    // If your system has no user_id session, change this to: $user_id = 1;
    die("Session expired. Please login again.");
}

$order_id = (int)($_GET['order_id'] ?? $_GET['id'] ?? $_POST['order_id'] ?? 0);

$cartKey     = "edit_cart_" . $order_id;
$discountKey = "edit_discount_" . $order_id;
$paidKey     = "edit_paid_" . $order_id;
$deliveryKey = "edit_delivery_" . $order_id;
$groupKey    = "edit_group_cost_" . $order_id;
$taxKey      = "edit_tax_" . $order_id;

// CSRF Token
if (!isset($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
$csrf_token = $_SESSION['csrf_token'];

// ============================================================
// HELPERS
// ============================================================
function h($value) {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

// Converts money text safely: 5000, 5,000, ৳5,000, ৫০০০ => 5000.00
function moneyToFloat($value) {
    if ($value === null || $value === '') return 0.0;

    $value = (string)$value;
    $bnDigits = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
    $enDigits = ['0','1','2','3','4','5','6','7','8','9'];
    $value = str_replace($bnDigits, $enDigits, $value);
    $value = str_replace(['৳', ',', ' '], '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);

    if ($value === '' || $value === '-' || $value === '.') return 0.0;

    $parts = explode('.', $value);
    if (count($parts) > 2) {
        $value = array_shift($parts) . '.' . implode('', $parts);
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

function cartSubtotal($cart) {
    $subtotal = 0.0;
    foreach ($cart as $item) {
        $qty = moneyToFloat($item['qty'] ?? 0);
        $price = moneyToFloat($item['price'] ?? 0);
        if ($qty > 0) {
            $subtotal += ($price * $qty);
        }
    }
    return $subtotal;
}

function safeDiscount($discount, $subtotal) {
    $discount = max(0, moneyToFloat($discount));
    return min($discount, max(0, moneyToFloat($subtotal)));
}

function verifyCsrfToken() {
    $posted = $_POST['csrf_token'] ?? '';
    $session = $_SESSION['csrf_token'] ?? '';

    if ($session === '' || $posted === '' || !hash_equals($session, $posted)) {
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(["success" => false, "message" => "Invalid security token. Please refresh the page."], JSON_UNESCAPED_UNICODE);
        exit;
    }
}

function jsonResponse($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function updateProductStock($conn, $shop_id, $product_id, $qty_delta) {
    $product_id = (int)$product_id;
    $qty_delta = (float)$qty_delta;

    if ($product_id <= 0 || abs($qty_delta) < 0.00001) {
        return;
    }

    $stmt = $conn->prepare("
        UPDATE products
        SET stock = GREATEST(COALESCE(stock,0) + ?, 0)
        WHERE id = ? AND shop_id = ?
    ");
    $stmt->bind_param("dii", $qty_delta, $product_id, $shop_id);
    $stmt->execute();
}

function loadOrderToEditSession($conn, $shop_id, $order_id, $force = false) {
    global $cartKey, $discountKey, $paidKey, $deliveryKey, $groupKey, $taxKey;

    if ($order_id <= 0) return null;

    $stmt = $conn->prepare("
        SELECT 
            o.*,
            c.name AS customer_name,
            c.phone AS customer_phone,
            c.shop_name,
            c.address AS customer_address,
            c.area_group
        FROM orders o
        LEFT JOIN customers c ON c.id = o.customer_id AND c.shop_id = o.shop_id
        WHERE o.id = ? AND o.shop_id = ? AND o.order_source = 'pos'
        LIMIT 1
    ");
    $stmt->bind_param("ii", $order_id, $shop_id);
    $stmt->execute();
    $order = $stmt->get_result()->fetch_assoc();

    if (!$order) {
        return null;
    }

    if ($force || !isset($_SESSION[$cartKey])) {
        $_SESSION[$cartKey] = [];

        $itemStmt = $conn->prepare("
            SELECT 
                oi.id,
                oi.product_id,
                oi.qty,
                oi.price,
                oi.subtotal,
                p.product_name,
                p.sale_price,
                p.unit
            FROM order_items oi
            INNER JOIN orders oo ON oo.id = oi.order_id
            LEFT JOIN products p ON p.id = oi.product_id AND p.shop_id = oo.shop_id
            WHERE oi.order_id = ? AND oo.shop_id = ? AND oo.order_source = 'pos'
            ORDER BY oi.id ASC
        ");
        $itemStmt->bind_param("ii", $order_id, $shop_id);
        $itemStmt->execute();
        $items = $itemStmt->get_result();

        while ($it = $items->fetch_assoc()) {
            $pid = (int)$it['product_id'];
            if ($pid <= 0) continue;

            if (!isset($_SESSION[$cartKey][$pid])) {
                $_SESSION[$cartKey][$pid] = [
                    "name" => $it['product_name'] ?: ("Product #" . $pid),
                    "price" => (float)($it['price'] ?? $it['sale_price'] ?? 0),
                    "qty" => (float)($it['qty'] ?? 0)
                ];
            } else {
                $_SESSION[$cartKey][$pid]['qty'] += (float)($it['qty'] ?? 0);
            }
        }

        $_SESSION[$discountKey] = (float)($order['discount'] ?? 0);
        $_SESSION[$paidKey]     = (float)($order['paid_amount'] ?? 0);
        $_SESSION[$deliveryKey] = (float)($order['delivery_cost'] ?? 0);
        $_SESSION[$groupKey]    = (float)($order['group_unit_cost'] ?? 0);
        $_SESSION[$taxKey]      = (float)($order['tax'] ?? 0);
    }

    return $order;
}

// ============================================================
// HELPER: Render Edit Cart Items - Same style as POS cart
// ============================================================
function renderEditCartItems($cart) {
    global $conn, $shop_id;

    if (empty($cart)) {
        return '<div class="text-center py-10 text-gray-500">
                    <i class="fas fa-shopping-cart text-4xl block mb-2"></i>
                    <p>Cart is empty</p>
                </div>';
    }

    $html = '';
    foreach ($cart as $id => $item) {
        $id = (int)$id;
        $qty = moneyToFloat($item['qty'] ?? 0);
        $price = moneyToFloat($item['price'] ?? 0);
        $total = $price * $qty;
        $isZero = ($qty <= 0);

        $unit = '';
        $stmt = $conn->prepare("SELECT unit FROM products WHERE id = ? AND shop_id = ? LIMIT 1");
        $stmt->bind_param("ii", $id, $shop_id);
        $stmt->execute();
        $result = $stmt->get_result();
        if ($row = $result->fetch_assoc()) {
            $unit = h($row['unit']);
        }
        $stmt->close();

        $html .= '<div class="grid grid-cols-12 gap-2 py-2.5 border-b border-[#2d2d44] items-center ' . ($isZero ? 'opacity-50' : '') . '" data-id="' . $id . '">
                    <div class="col-span-2 font-medium text-sm truncate ' . ($isZero ? 'text-gray-500' : 'text-gray-200') . '">' . h($item['name']) . '</div>
                    <div class="col-span-3">
                        <input type="text" value="' . h(number_format($price, 2, '.', '')) . '"
                               class="w-full px-2 py-1 bg-[#0f0f1a] border border-[#2d2d44] rounded text-sm text-white text-right focus:ring-2 focus:ring-blue-500 focus:border-blue-500 price-input"
                               data-id="' . $id . '"
                               oninput="handlePriceInput(this, ' . $id . ')">
                    </div>
                    <div class="col-span-2 text-center">
                        <input type="text"
                               value="' . h(rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.')) . '"
                               inputmode="decimal"
                               class="w-12 px-1 py-1 bg-[#0f0f1a] border border-[#2d2d44] rounded text-center text-sm text-white focus:ring-2 focus:ring-blue-500 focus:border-blue-500 ' . ($isZero ? 'border-red-500' : '') . ' qty-input"
                               data-id="' . $id . '"
                               oninput="handleQtyInput(this, ' . $id . ')">
                    </div>
                    <div class="col-span-1 text-xs text-gray-400 text-center">' . $unit . '</div>
                    <div class="col-span-3 font-semibold text-sm ' . ($isZero ? 'text-gray-500' : 'text-blue-400') . ' text-right item-total" data-id="' . $id . '">৳' . number_format($total, 2) . '</div>
                    <div class="col-span-1 text-center">
                        <button type="button" onclick="removeItem(' . $id . ')" class="text-red-400 hover:text-red-300 hover:bg-red-900/30 rounded-full p-1 transition" title="Remove from cart">
                            <i class="fas fa-trash-alt text-sm"></i>
                        </button>
                    </div>
                </div>';
    }

    return $html;
}

// ============================================================
// AJAX HANDLERS
// ============================================================
if (isset($_GET['ajax'])) {
    if ($order_id <= 0) {
        jsonResponse(["success" => false, "message" => "Invalid order ID"]);
    }

    loadOrderToEditSession($conn, $shop_id, $order_id, false);

    // BARCODE SCAN
    if ($_GET['ajax'] === 'barcode_scan') {
        $barcode = trim($_GET['barcode'] ?? '');

        if ($barcode === '') {
            jsonResponse(["success" => false, "message" => "Invalid barcode"]);
        }

        $stmt = $conn->prepare("SELECT * FROM products WHERE barcode = ? AND shop_id = ? LIMIT 1");
        $stmt->bind_param("si", $barcode, $shop_id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();

        if (!$product) {
            jsonResponse(["success" => false, "message" => "Product Not Found"]);
        }

        $id = (int)$product['id'];

        if (!isset($_SESSION[$cartKey][$id])) {
            $_SESSION[$cartKey][$id] = [
                "name" => $product['product_name'],
                "price" => (float)$product['sale_price'],
                "qty" => 1
            ];
        } else {
            $_SESSION[$cartKey][$id]['qty'] += 1;
        }

        $subtotal = cartSubtotal($_SESSION[$cartKey]);

        jsonResponse([
            "success" => true,
            "product_name" => $product['product_name'],
            "subtotal" => number_format($subtotal, 2),
            "total_amount" => number_format($subtotal, 2),
            "cart_html" => renderEditCartItems($_SESSION[$cartKey])
        ]);
    }

    // SEARCH PRODUCTS
    if ($_GET['ajax'] === 'search_products') {
        $term = trim($_GET['term'] ?? '');

        $sql = "SELECT p.* FROM products p
                WHERE p.shop_id = ?
                AND (p.product_name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?)
                ORDER BY p.product_name ASC LIMIT 20";

        $search_param = '%' . $term . '%';
        $stmt = $conn->prepare($sql);
        $stmt->bind_param("isss", $shop_id, $search_param, $search_param, $search_param);
        $stmt->execute();
        $result = $stmt->get_result();

        $products = [];
        while ($row = $result->fetch_assoc()) {
            $products[] = $row;
        }

        jsonResponse(['success' => true, 'products' => $products]);
    }
}

if (isset($_POST['ajax_cart']) && $_POST['ajax_cart'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    verifyCsrfToken();

    if ($order_id <= 0) {
        jsonResponse(["success" => false, "message" => "Invalid order ID"]);
    }

    loadOrderToEditSession($conn, $shop_id, $order_id, false);

    $action = $_POST['action'] ?? '';

    if ($action === 'add') {
        $id = (int)($_POST['product_id'] ?? 0);

        if ($id <= 0) {
            jsonResponse(["success" => false, "message" => "Invalid product"]);
        }

        $stmt = $conn->prepare("SELECT * FROM products WHERE id = ? AND shop_id = ? LIMIT 1");
        $stmt->bind_param("ii", $id, $shop_id);
        $stmt->execute();
        $product = $stmt->get_result()->fetch_assoc();

        if (!$product) {
            jsonResponse(["success" => false, "message" => "Product not found"]);
        }

        if (!isset($_SESSION[$cartKey][$id])) {
            $_SESSION[$cartKey][$id] = [
                "name" => $product['product_name'],
                "price" => (float)$product['sale_price'],
                "qty" => 1
            ];
        } else {
            $_SESSION[$cartKey][$id]['qty'] += 1;
        }
    }
    elseif ($action === "update") {
        $id = (int)($_POST['id'] ?? 0);
        $field = $_POST['field'] ?? '';
        $value = moneyToFloat($_POST['value'] ?? 0);

        if (isset($_SESSION[$cartKey][$id])) {
            if ($field === "qty") {
                $_SESSION[$cartKey][$id]['qty'] = max(0, $value);
            }
            if ($field === "price") {
                $_SESSION[$cartKey][$id]['price'] = max(0, $value);
            }
        }
    }
    elseif ($action === "remove") {
        $id = (int)($_POST['id'] ?? 0);
        unset($_SESSION[$cartKey][$id]);
    }
    elseif ($action === "clear") {
        $_SESSION[$cartKey] = [];
        unset($_SESSION[$discountKey], $_SESSION[$paidKey]);
    }
    elseif ($action === "reload_original") {
        loadOrderToEditSession($conn, $shop_id, $order_id, true);
    }

    $subtotal = cartSubtotal($_SESSION[$cartKey]);

    jsonResponse([
        "success" => true,
        "subtotal" => number_format($subtotal, 2),
        "total_amount" => number_format($subtotal, 2),
        "cart_html" => renderEditCartItems($_SESSION[$cartKey])
    ]);
}

// SAVE TOTALS TO EDIT SESSION
if (isset($_POST['ajax_totals']) && $_POST['ajax_totals'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    verifyCsrfToken();

    if ($order_id <= 0) {
        jsonResponse(["success" => false, "message" => "Invalid order ID"]);
    }

    loadOrderToEditSession($conn, $shop_id, $order_id, false);

    $_SESSION[$discountKey] = moneyToFloat($_POST['discount'] ?? 0);
    $_SESSION[$paidKey] = moneyToFloat($_POST['paid'] ?? 0);
    $_SESSION[$deliveryKey] = moneyToFloat($_POST['delivery'] ?? ($_SESSION[$deliveryKey] ?? 0));
    $_SESSION[$groupKey] = moneyToFloat($_POST['group_cost'] ?? ($_SESSION[$groupKey] ?? 0));
    $_SESSION[$taxKey] = moneyToFloat($_POST['tax'] ?? ($_SESSION[$taxKey] ?? 0));

    $subtotal = cartSubtotal($_SESSION[$cartKey]);
    $discount = safeDiscount($_SESSION[$discountKey], $subtotal);
    $_SESSION[$discountKey] = $discount;

    $paid = max(0, moneyToFloat($_SESSION[$paidKey] ?? 0));
    $delivery = max(0, moneyToFloat($_SESSION[$deliveryKey] ?? 0));
    $groupCost = max(0, moneyToFloat($_SESSION[$groupKey] ?? 0));
    $tax = max(0, moneyToFloat($_SESSION[$taxKey] ?? 0));

    $total = max(0, $subtotal + $delivery + $groupCost + $tax - $discount);
    if ($paid > $total) {
        $paid = $total;
        $_SESSION[$paidKey] = $paid;
    }
    $due = $total - $paid;

    jsonResponse([
        "success" => true,
        "subtotal" => round($subtotal, 2),
        "discount" => round($discount, 2),
        "delivery" => round($delivery, 2),
        "group_cost" => round($groupCost, 2),
        "tax" => round($tax, 2),
        "paid" => round($paid, 2),
        "total_amount" => round($total, 2),
        "due_amount" => round($due, 2)
    ]);
}

// SAVE CUSTOMER
if (isset($_POST['ajax_save_customer']) && $_POST['ajax_save_customer'] == '1') {
    header('Content-Type: application/json; charset=utf-8');
    verifyCsrfToken();

    $customer_name = trim($_POST['name'] ?? '');
    $customer_phone = trim($_POST['phone'] ?? '');

    if ($customer_name === '') {
        jsonResponse(["success" => false, "message" => "Name required"]);
    }

    $stmt = $conn->prepare("INSERT INTO customers (name, phone, shop_id, created_at) VALUES (?, ?, ?, NOW())");
    $stmt->bind_param("ssi", $customer_name, $customer_phone, $shop_id);

    if ($stmt->execute()) {
        jsonResponse([
            "success" => true,
            "customer" => [
                "id" => $stmt->insert_id,
                "name" => $customer_name,
                "phone" => $customer_phone
            ]
        ]);
    }

    jsonResponse(["success" => false, "message" => "Failed to save"]);
}

// ============================================================
// FINAL SAVE: UPDATE INVOICE FROM EDIT CART
// ============================================================
if (isset($_POST['save_edit_order']) && $_POST['save_edit_order'] == '1') {
    verifyCsrfToken();

    $order_id = (int)($_POST['order_id'] ?? 0);

    try {
        if ($order_id <= 0) {
            throw new Exception("Invalid order ID.");
        }

        loadOrderToEditSession($conn, $shop_id, $order_id, false);

        $cart = $_SESSION[$cartKey] ?? [];
        $validItems = [];
        foreach ($cart as $pid => $item) {
            $qty = moneyToFloat($item['qty'] ?? 0);
            $price = moneyToFloat($item['price'] ?? 0);
            if ($pid > 0 && $qty > 0 && $price >= 0) {
                $validItems[(int)$pid] = [
                    'name' => $item['name'] ?? '',
                    'qty' => $qty,
                    'price' => $price,
                    'subtotal' => $qty * $price
                ];
            }
        }

        if (empty($validItems)) {
            throw new Exception("Valid cart item নেই।");
        }

        $conn->begin_transaction();

        $orderStmt = $conn->prepare("
            SELECT id, customer_id, due_amount
            FROM orders
            WHERE id = ? AND shop_id = ? AND order_source = 'pos'
            LIMIT 1
            FOR UPDATE
        ");
        $orderStmt->bind_param("ii", $order_id, $shop_id);
        $orderStmt->execute();
        $oldOrder = $orderStmt->get_result()->fetch_assoc();

        if (!$oldOrder) {
            throw new Exception("Invoice/order not found.");
        }

        $old_customer_id = (int)($oldOrder['customer_id'] ?? 0);
        $old_due = (float)($oldOrder['due_amount'] ?? 0);
        $new_customer_id = (int)($_POST['customer_id'] ?? 0);

        if ($new_customer_id > 0) {
            $customerCheck = $conn->prepare("SELECT id FROM customers WHERE id = ? AND shop_id = ? LIMIT 1");
            $customerCheck->bind_param("ii", $new_customer_id, $shop_id);
            $customerCheck->execute();
            if (!$customerCheck->get_result()->fetch_assoc()) {
                throw new Exception("Selected customer does not belong to your shop.");
            }
        }

        $productCheck = $conn->prepare("SELECT id FROM products WHERE id = ? AND shop_id = ? LIMIT 1");
        foreach (array_keys($validItems) as $validProductId) {
            $validProductId = (int)$validProductId;
            $productCheck->bind_param("ii", $validProductId, $shop_id);
            $productCheck->execute();
            if (!$productCheck->get_result()->fetch_assoc()) {
                throw new Exception("One or more cart products do not belong to your shop.");
            }
        }

        // Return old item stock first
        $oldItemsStmt = $conn->prepare("
            SELECT oi.product_id, oi.qty
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE oi.order_id = ? AND o.shop_id = ? AND o.order_source = 'pos' 
        ");
        $oldItemsStmt->bind_param("ii", $order_id, $shop_id);
        $oldItemsStmt->execute();
        $oldItems = $oldItemsStmt->get_result();
        while ($old = $oldItems->fetch_assoc()) {
            updateProductStock($conn, $shop_id, (int)$old['product_id'], (float)$old['qty']);
        }

        // Delete old order items
        $deleteStmt = $conn->prepare("
            DELETE oi FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE oi.order_id = ? AND o.shop_id = ? AND o.order_source = 'pos'
        ");
        $deleteStmt->bind_param("ii", $order_id, $shop_id);
        $deleteStmt->execute();

        // Insert new items and reduce stock
        $subtotal = 0.0;
        foreach ($validItems as $pid => $item) {
            $lineSubtotal = $item['subtotal'];
            $subtotal += $lineSubtotal;

            $insert = $conn->prepare("
                INSERT INTO order_items (order_id, product_id, qty, price, subtotal)
                VALUES (?, ?, ?, ?, ?)
            ");
            $insert->bind_param("iiddd", $order_id, $pid, $item['qty'], $item['price'], $lineSubtotal);
            $insert->execute();

            updateProductStock($conn, $shop_id, $pid, -$item['qty']);
        }

        $discount = safeDiscount($_POST['discount'] ?? ($_SESSION[$discountKey] ?? 0), $subtotal);
        $delivery = max(0, moneyToFloat($_POST['delivery'] ?? ($_SESSION[$deliveryKey] ?? 0)));
        $groupCost = max(0, moneyToFloat($_POST['group_cost'] ?? ($_SESSION[$groupKey] ?? 0)));
        $tax = max(0, moneyToFloat($_POST['tax'] ?? ($_SESSION[$taxKey] ?? 0)));
        $paid = max(0, moneyToFloat($_POST['paid'] ?? ($_SESSION[$paidKey] ?? 0)));

        $total = max(0, $subtotal + $delivery + $groupCost + $tax - $discount);

        if ($paid > $total) {
            $paid = $total;
        }

        $due = max(0, $total - $paid);

        $payment_status = $due <= 0 ? 'paid' : 'pending';
        $order_status = trim($_POST['order_status'] ?? 'completed');
        $payment_method = trim($_POST['payment_method'] ?? 'cash');

        $allowed_order = ['pending','processing','completed','cancelled'];
        if (!in_array($order_status, $allowed_order, true)) {
            $order_status = 'completed';
        }

        $updateOrder = $conn->prepare("
            UPDATE orders
            SET
                customer_id = ?,
                subtotal = ?,
                delivery_cost = ?,
                group_unit_cost = ?,
                discount = ?,
                tax = ?,
                total_amount = ?,
                paid_amount = ?,
                due_amount = ?,
                payment_method = ?,
                order_status = ?,
                payment_status = ?
            WHERE id = ? AND shop_id = ? AND order_source = 'pos'
        ");
        $updateOrder->bind_param(
            "iddddddddsssii",
            $new_customer_id,
            $subtotal,
            $delivery,
            $groupCost,
            $discount,
            $tax,
            $total,
            $paid,
            $due,
            $payment_method,
            $order_status,
            $payment_status,
            $order_id,
            $shop_id
        );
        $updateOrder->execute();

        // Customer due sync
        if ($old_customer_id > 0 && $old_customer_id !== $new_customer_id && $old_due > 0) {
            $custOld = $conn->prepare("
                UPDATE customers
                SET due_amount = GREATEST(COALESCE(due_amount,0) - ?, 0)
                WHERE id = ? AND shop_id = ?
            ");
            $custOld->bind_param("dii", $old_due, $old_customer_id, $shop_id);
            $custOld->execute();
        }

        if ($new_customer_id > 0) {
            if ($old_customer_id === $new_customer_id) {
                $dueDelta = $due - $old_due;
                if (abs($dueDelta) > 0.00001) {
                    $cust = $conn->prepare("
                        UPDATE customers
                        SET due_amount = GREATEST(COALESCE(due_amount,0) + ?, 0)
                        WHERE id = ? AND shop_id = ?
                    ");
                    $cust->bind_param("dii", $dueDelta, $new_customer_id, $shop_id);
                    $cust->execute();
                }
            } else {
                if ($due > 0) {
                    $custNew = $conn->prepare("
                        UPDATE customers
                        SET due_amount = GREATEST(COALESCE(due_amount,0) + ?, 0)
                        WHERE id = ? AND shop_id = ?
                    ");
                    $custNew->bind_param("dii", $due, $new_customer_id, $shop_id);
                    $custNew->execute();
                }
            }
        }

        $conn->commit();

					// Clear edit cart session after successful invoice update.
					// After this we redirect to invoice print page, not back to edit cart.

			// Update successful flag
			$_SESSION['edit_order_updated_' . $order_id] = 1;

			// Clear edit cart session after successful invoice update
			unset(
				$_SESSION[$cartKey],
				$_SESSION[$discountKey],
				$_SESSION[$paidKey],
				$_SESSION[$deliveryKey],
				$_SESSION[$groupKey],
				$_SESSION[$taxKey]
			);

			$_SESSION['success'] = "Invoice cart updated successfully!";

			// চাইলে invoice.php রাখুন, তবে print page হলে ভালো
			header("Location: invoice.php?id=" . $order_id . "&updated=1");
			exit;

    } catch (Exception $e) {
        try { $conn->rollback(); } catch (Exception $ignored) {}
        $_SESSION['error'] = "Failed to update invoice cart: " . $e->getMessage();
        header("Location: edit_invoice_cart.php?order_id=" . $order_id);
        exit;
    }
}

// ============================================================
// PREVENT BACK TO EDIT CART AFTER SUCCESSFUL UPDATE
// ============================================================
$updatedFlagKey = 'edit_order_updated_' . $order_id;

if (
    $_SERVER['REQUEST_METHOD'] === 'GET'
    && $order_id > 0
    && !isset($_GET['allow_edit'])
    && isset($_SESSION[$updatedFlagKey])
) {
    unset(
        $_SESSION[$cartKey],
        $_SESSION[$discountKey],
        $_SESSION[$paidKey],
        $_SESSION[$deliveryKey],
        $_SESSION[$groupKey],
        $_SESSION[$taxKey],
        $_SESSION[$updatedFlagKey]
    );

    header("Location: invoice_list_cart_edit.php?updated=1");
    exit;
}
// ============================================================
// LOAD PAGE DATA
// ============================================================
if ($order_id <= 0) {
    die("Invalid order ID.");
}

// Always reload original cart on first normal page load, unless AJAX/POST already handled.
$order = loadOrderToEditSession($conn, $shop_id, $order_id, true);

if (!$order) {
    die("Invoice/order not found.");
}

$brand_filter = isset($_GET['brand']) ? (int)$_GET['brand'] : 0;
$cat_filter = isset($_GET['cat']) ? (int)$_GET['cat'] : 0;
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

$brands = $conn->query("SELECT id, brand_name FROM brands WHERE shop_id = $shop_id ORDER BY brand_name");
$categories = $conn->query("SELECT id, category_name FROM categories WHERE shop_id = $shop_id ORDER BY category_name");

$product_sql = "SELECT p.* FROM products p WHERE p.shop_id = ?";
$product_types = "i";
$product_params = [$shop_id];

if ($brand_filter > 0) {
    $product_sql .= " AND p.brand_id = ?";
    $product_types .= "i";
    $product_params[] = $brand_filter;
}

if ($cat_filter > 0) {
    $product_sql .= " AND p.category_id = ?";
    $product_types .= "i";
    $product_params[] = $cat_filter;
}

if ($search !== '') {
    $product_sql .= " AND (p.product_name LIKE ? OR p.barcode LIKE ? OR p.sku LIKE ?)";
    $product_types .= "sss";
    $search_param = '%' . $search . '%';
    $product_params[] = $search_param;
    $product_params[] = $search_param;
    $product_params[] = $search_param;
}

$product_sql .= " ORDER BY p.product_name ASC LIMIT 50";
$product_stmt = $conn->prepare($product_sql);
$product_stmt->bind_param($product_types, ...$product_params);
$product_stmt->execute();
$products = $product_stmt->get_result();

$customers = $conn->query("
    SELECT id, name, phone, shop_name
    FROM customers
    WHERE shop_id = $shop_id
    ORDER BY name ASC
");

$cart = $_SESSION[$cartKey] ?? [];
$subtotal = cartSubtotal($cart);
$discount = safeDiscount($_SESSION[$discountKey] ?? 0, $subtotal);
$_SESSION[$discountKey] = $discount;

$delivery = max(0, moneyToFloat($_SESSION[$deliveryKey] ?? 0));
$groupCost = max(0, moneyToFloat($_SESSION[$groupKey] ?? 0));
$tax = max(0, moneyToFloat($_SESSION[$taxKey] ?? 0));
$paid = max(0, moneyToFloat($_SESSION[$paidKey] ?? 0));

$total = max(0, $subtotal + $delivery + $groupCost + $tax - $discount);
if ($paid > $total) {
    $paid = $total;
    $_SESSION[$paidKey] = $paid;
}
$due = $total - $paid;

$selected_customer_id = (int)($order['customer_id'] ?? 0);
?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">

<title>Edit Invoice Cart - <?= h($order['invoice_no'] ?? $order_id) ?></title>

<script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.0/css/all.min.css">
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet" />
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
body { background: #0f0f1a; }
::-webkit-scrollbar { width: 6px; height: 6px; }
::-webkit-scrollbar-track { background: #1a1a2e; border-radius: 3px; }
::-webkit-scrollbar-thumb { background: #3b82f6; border-radius: 3px; }
::-webkit-scrollbar-thumb:hover { background: #60a5fa; }
input[type=text] { outline: none; }
.select2-container { width: 100% !important; }
.select2-container--default .select2-selection--single {
    background-color: #0f0f1a !important;
    border: 1px solid #2d2d44 !important;
    border-radius: 0.5rem !important;
    height: 42px !important;
    padding: 4px 8px !important;
}
.select2-container--default .select2-selection--single .select2-selection__rendered {
    color: #d1d5db !important;
    line-height: 34px !important;
    padding-left: 8px !important;
}
.select2-container--default .select2-selection--single .select2-selection__arrow { height: 40px !important; right: 8px !important; }
.select2-dropdown {
    background-color: #1a1a2e !important;
    border: 1px solid #2d2d44 !important;
    border-radius: 0.5rem !important;
    margin-top: 4px !important;
}
.select2-container--default .select2-results__option {
    color: #d1d5db !important;
    padding: 8px 12px !important;
    background-color: transparent !important;
}
.select2-container--default .select2-results__option--highlighted[aria-selected] {
    background-color: #2563eb !important;
    color: white !important;
}
.select2-container--default .select2-search--dropdown .select2-search__field {
    background-color: #0f0f1a !important;
    border: 1px solid #2d2d44 !important;
    color: #d1d5db !important;
    border-radius: 0.5rem !important;
    padding: 8px 12px !important;
    width: calc(100% - 24px) !important;
    margin: 8px 12px !important;
}
.barcode-scanning {
    border-color: #3b82f6 !important;
    box-shadow: 0 0 0 3px rgba(59, 130, 246, 0.3) !important;
}
.toast-notification { box-shadow: 0 4px 30px rgba(0,0,0,0.5); }
</style>
</head>

<body class="bg-[#0f0f1a]">

<div id="scannerStatus" class="fixed top-4 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-lg text-sm font-medium hidden shadow-lg">
    <i class="fas fa-qrcode mr-2"></i>
    <span id="scannerStatusText">Scanner ready...</span>
</div>

<div class="max-w-7xl mx-auto px-3 py-3">

    <?php if(isset($_SESSION['error'])): ?>
        <div class="mb-3 bg-red-900/40 border border-red-700 text-red-200 px-4 py-3 rounded-lg">
            <?= h($_SESSION['error']) ?>
        </div>
        <?php unset($_SESSION['error']); ?>
    <?php endif; ?>

    <?php if(isset($_SESSION['success'])): ?>
        <div class="mb-3 bg-green-900/40 border border-green-700 text-green-200 px-4 py-3 rounded-lg">
            <?= h($_SESSION['success']) ?>
        </div>
        <?php unset($_SESSION['success']); ?>
    <?php endif; ?>

    <div class="mb-3 bg-[#1a1a2e] border border-[#2d2d44] rounded-xl p-3 flex flex-col md:flex-row md:items-center md:justify-between gap-2">
        <div>
            <h1 class="text-xl font-bold text-white">
                <i class="fas fa-shopping-cart text-blue-400 mr-2"></i>
                Edit Invoice Cart
            </h1>
            <div class="text-sm text-gray-400">
                Invoice: <span class="text-blue-300 font-semibold"><?= h($order['invoice_no'] ?? $order_id) ?></span>
                | Customer: <?= h($order['customer_name'] ?? 'Walk In') ?>
                <?= !empty($order['customer_phone']) ? ' | ' . h($order['customer_phone']) : '' ?>
            </div>
        </div>
        <div class="flex gap-2">
            <a href="invoice_list_cart_edit.php" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg font-semibold hover:bg-gray-700 transition text-sm">
                <i class="fas fa-arrow-left"></i> Invoice List
            </a>
            <a href="invoice_print.php?id=<?= (int)$order_id ?>" target="_blank" class="px-4 py-2 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition text-sm">
                <i class="fas fa-print"></i> Print
            </a>
        </div>
    </div>

    <div class="grid grid-cols-1 lg:grid-cols-5 gap-3 bg-[#1a1a2e] rounded-xl shadow-2xl border border-[#2d2d44] overflow-hidden">

        <!-- LEFT PANEL -->
        <div class="lg:col-span-3 p-4 md:p-5 overflow-y-auto max-h-[calc(100vh-135px)]">

            <div class="flex items-center justify-between mb-4">
                <h2 class="text-2xl font-bold text-white">
                    <i class="fas fa-cash-register text-blue-400 mr-2"></i> Products
                </h2>
                <div class="flex items-center gap-2">
                    <span id="scannerBadge" class="flex items-center gap-1.5 px-3 py-1.5 bg-green-900/30 text-green-400 rounded-full text-xs font-semibold border border-green-800/50">
                        <span class="relative flex h-2 w-2">
                            <span class="animate-ping absolute inline-flex h-full w-full rounded-full bg-green-400 opacity-75"></span>
                            <span class="relative inline-flex rounded-full h-2 w-2 bg-green-500"></span>
                        </span>
                        Scanner Ready
                    </span>
                    <div class="flex items-center gap-2 px-3 py-1.5 bg-[#0f0f1a] rounded-full text-sm text-gray-300 border border-[#2d2d44]">
                        <i class="fas fa-user-circle text-blue-400 text-lg"></i>
                        <span class="font-medium"><?= h($user_name) ?></span>
                    </div>
                </div>
            </div>

            <div class="flex flex-wrap gap-2 mb-3">
                <select onchange="applyFilters()" id="brandFilter"
                        class="px-3 py-2 bg-[#0f0f1a] border border-[#2d2d44] rounded-lg text-sm text-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 min-w-[120px]">
                    <option value="0">All Brands</option>
                    <?php while($b = $brands->fetch_assoc()): ?>
                        <option value="<?= (int)$b['id'] ?>" <?= $brand_filter == $b['id'] ? 'selected' : '' ?>>
                            <?= h($b['brand_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>

                <select onchange="applyFilters()" id="categoryFilter"
                        class="px-3 py-2 bg-[#0f0f1a] border border-[#2d2d44] rounded-lg text-sm text-gray-300 focus:ring-2 focus:ring-blue-500 focus:border-blue-500 min-w-[120px]">
                    <option value="0">All Categories</option>
                    <?php while($c = $categories->fetch_assoc()): ?>
                        <option value="<?= (int)$c['id'] ?>" <?= $cat_filter == $c['id'] ? 'selected' : '' ?>>
                            <?= h($c['category_name']) ?>
                        </option>
                    <?php endwhile; ?>
                </select>

                <div class="flex-1 min-w-[150px] relative">
                    <input type="text" id="searchInput"
                           placeholder="Search products or scan barcode..."
                           value="<?= h($search) ?>"
                           oninput="handleSearchInput(this.value)"
                           autofocus
                           class="w-full px-3 py-2 bg-[#0f0f1a] border border-[#2d2d44] rounded-lg text-sm text-gray-300 placeholder-gray-500 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                    <i class="fas fa-barcode absolute right-3 top-3 text-gray-500 cursor-pointer hover:text-blue-400 transition"
                       id="barcodeIcon" title="Barcode scanner ready"></i>
                </div>
            </div>

            <div id="searchResults" class="hidden mb-3 bg-[#1a1a2e] border border-[#2d2d44] rounded-lg shadow-xl max-h-72 overflow-y-auto"></div>

            <div class="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 gap-2" id="productGrid">
                <?php if($products && $products->num_rows > 0): ?>
                    <?php while($p = $products->fetch_assoc()): 
                        $low_stock = ((float)($p['stock'] ?? 0)) < 10;
                    ?>
                        <div onclick="addToCart(<?= (int)$p['id'] ?>)"
                             class="bg-[#0f0f1a] border border-[#2d2d44] rounded-lg p-3 text-center cursor-pointer hover:border-blue-500 hover:shadow-lg hover:-translate-y-0.5 transition-all duration-200 group">
                            <div class="text-2xl text-gray-600 group-hover:text-gray-400 mb-1 transition">
                                <i class="fas fa-box"></i>
                            </div>
                            <div class="text-xs font-semibold leading-tight mb-0.5 text-gray-300 line-clamp-2"><?= h($p['product_name']) ?></div>
                            <div class="text-base font-bold text-blue-400">৳<?= number_format((float)$p['sale_price'], 2) ?></div>
                            <div class="text-xs <?= $low_stock ? 'text-red-400' : 'text-gray-500' ?>">
                                Stock: <?= h($p['stock'] ?? 0) ?> <?= h($p['unit'] ?? '') ?>
                            </div>
                            <?php if(!empty($p['barcode'])): ?>
                                <div class="text-[10px] text-gray-600 mt-0.5 font-mono"><?= h($p['barcode']) ?></div>
                            <?php endif; ?>
                        </div>
                    <?php endwhile; ?>
                <?php else: ?>
                    <div class="col-span-full text-center py-12 text-gray-500">
                        <i class="fas fa-box-open text-5xl block mb-3"></i>
                        <p class="text-lg">No products found</p>
                    </div>
                <?php endif; ?>
            </div>
        </div>

        <!-- RIGHT PANEL - EDIT CART -->
        <div class="lg:col-span-2 bg-[#0f0f1a] p-4 md:p-5 border-t lg:border-t-0 lg:border-l border-[#2d2d44] flex flex-col max-h-[calc(100vh-135px)]">

            <div class="mb-3">
                <div class="flex gap-2">
                    <div class="flex-1 min-w-0">
                        <select id="customerSelect" onchange="updateCustomer()" class="w-full">
                            <option value="0">👤 Walk In</option>
                            <?php while($cu = $customers->fetch_assoc()): ?>
                                <option value="<?= (int)$cu['id'] ?>" <?= $selected_customer_id == $cu['id'] ? 'selected' : '' ?>>
                                    <?= h($cu['name']) ?>
                                    <?php if(!empty($cu['shop_name'])): ?> - <?= h($cu['shop_name']) ?><?php endif; ?>
                                    <?php if(!empty($cu['phone'])): ?> (<?= h($cu['phone']) ?>)<?php endif; ?>
                                </option>
                            <?php endwhile; ?>
                        </select>
                    </div>
                    <button type="button" onclick="openCustomerModal()"
                            class="px-3 py-2 bg-blue-600 text-white rounded-lg text-sm font-semibold hover:bg-blue-700 transition whitespace-nowrap flex-shrink-0">
                        <i class="fas fa-plus"></i>
                    </button>
                </div>
            </div>

            <div class="grid grid-cols-12 gap-2 px-1 pb-1.5 text-xs font-semibold uppercase tracking-wider text-gray-500 border-b-2 border-[#2d2d44]">
                <div class="col-span-2">Product</div>
                <div class="col-span-3 text-right">Price</div>
                <div class="col-span-2 text-center">Qty</div>
                <div class="col-span-1 text-center">Unit</div>
                <div class="col-span-3 text-right">Total</div>
                <div class="col-span-1"></div>
            </div>

            <div class="flex-1 overflow-y-auto mb-3" id="cartItems">
                <?= renderEditCartItems($cart) ?>
            </div>

            <div class="border-t-2 border-[#2d2d44] pt-3 space-y-1.5">
                <div class="flex justify-between text-sm">
                    <span class="text-gray-400">Subtotal</span>
                    <span class="font-semibold text-gray-200" id="subtotalDisplay">৳<?= number_format($subtotal, 2) ?></span>
                </div>

                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-400">Delivery (৳)</span>
                    <input type="text" id="deliveryInput"
                           value="<?= $delivery > 0 ? h(number_format($delivery, 2, '.', '')) : '' ?>"
                           inputmode="decimal" placeholder="0.00" oninput="liveCalculate()"
                           class="w-24 px-2 py-1 bg-[#0f0f1a] border border-[#2d2d44] rounded text-right text-sm text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-400">Group Cost (৳)</span>
                    <input type="text" id="groupCostInput"
                           value="<?= $groupCost > 0 ? h(number_format($groupCost, 2, '.', '')) : '' ?>"
                           inputmode="decimal" placeholder="0.00" oninput="liveCalculate()"
                           class="w-24 px-2 py-1 bg-[#0f0f1a] border border-[#2d2d44] rounded text-right text-sm text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-400">Tax (৳)</span>
                    <input type="text" id="taxInput"
                           value="<?= $tax > 0 ? h(number_format($tax, 2, '.', '')) : '' ?>"
                           inputmode="decimal" placeholder="0.00" oninput="liveCalculate()"
                           class="w-24 px-2 py-1 bg-[#0f0f1a] border border-[#2d2d44] rounded text-right text-sm text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-400">Discount (৳)</span>
                    <input type="text" id="discountInput"
                           value="<?= $discount > 0 ? h(number_format($discount, 2, '.', '')) : '' ?>"
                           inputmode="decimal" placeholder="0.00" oninput="liveCalculate()"
                           class="w-24 px-2 py-1 bg-[#0f0f1a] border border-[#2d2d44] rounded text-right text-sm text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex justify-between items-center text-sm">
                    <span class="text-gray-400">Paid (৳)</span>
                    <input type="text" id="paidInput"
                           value="<?= $paid > 0 ? h(number_format($paid, 2, '.', '')) : '' ?>"
                           inputmode="decimal" placeholder="0.00" oninput="liveCalculate()"
                           class="w-24 px-2 py-1 bg-[#0f0f1a] border border-[#2d2d44] rounded text-right text-sm text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
                </div>

                <div class="flex justify-between text-lg font-bold pt-1.5 border-t-2 border-[#2d2d44]">
                    <span class="text-gray-300">Total</span>
                    <span class="text-blue-400" id="totalDisplay">৳<?= number_format($total, 2) ?></span>
                </div>

                <div class="flex justify-between text-base font-bold">
                    <span class="text-gray-400">Due</span>
                    <span class="text-red-400" id="dueDisplay">৳<?= number_format($due, 2) ?></span>
                </div>
            </div>
        </div>
    </div>

    <div class="mt-3 bg-[#1a1a2e] border border-[#2d2d44] rounded-xl p-3 flex flex-col sm:flex-row justify-between items-center gap-2">
        <div class="text-lg font-bold text-gray-200">
            Total: <span class="text-blue-400" id="bottomTotal">৳<?= number_format($total, 2) ?></span>
        </div>

        <div class="flex flex-wrap gap-2">
            <button type="button" onclick="reloadOriginalCart()" class="px-4 py-2 bg-yellow-900/40 text-yellow-300 rounded-lg font-semibold hover:bg-yellow-900/60 transition text-sm flex items-center gap-1.5 border border-yellow-800/30">
                <i class="fas fa-rotate-left"></i> Reload Original
            </button>
            <button type="button" onclick="clearCart()" class="px-4 py-2 bg-red-900/30 text-red-400 rounded-lg font-semibold hover:bg-red-900/50 transition text-sm flex items-center gap-1.5 border border-red-800/30">
                <i class="fas fa-trash"></i> Clear
            </button>
            <button type="button" onclick="window.open('invoice_print.php?id=<?= (int)$order_id ?>','_blank')" class="px-4 py-2 bg-gray-800/50 text-gray-300 rounded-lg font-semibold hover:bg-gray-700/50 transition text-sm flex items-center gap-1.5 border border-gray-700/30">
                <i class="fas fa-print"></i> Print
            </button>
            <button type="button" onclick="processCheckout()" class="px-5 py-2 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 hover:shadow-lg transition text-sm flex items-center gap-1.5">
                <i class="fas fa-save"></i> Update Invoice
            </button>
        </div>
    </div>
</div>

<!-- CUSTOMER MODAL -->
<div class="fixed inset-0 bg-black/70 backdrop-blur-sm z-50 hidden items-center justify-center" id="customerModal">
    <div class="bg-[#1a1a2e] border border-[#2d2d44] rounded-xl p-6 w-[380px] max-w-[92%] max-h-[90vh] overflow-y-auto">
        <h3 class="text-xl font-bold mb-4 text-white flex items-center gap-2">
            <i class="fas fa-user-plus text-blue-400"></i> Add Customer
        </h3>

        <div class="mb-3">
            <label class="block text-sm font-semibold text-gray-300 mb-1">Name *</label>
            <input type="text" id="newCustomerName" placeholder="Enter name"
                   class="w-full px-3 py-2 bg-[#0f0f1a] border border-[#2d2d44] rounded-lg text-sm text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div class="mb-3">
            <label class="block text-sm font-semibold text-gray-300 mb-1">Phone</label>
            <input type="text" id="newCustomerPhone" placeholder="01XXXXXXXXX"
                   class="w-full px-3 py-2 bg-[#0f0f1a] border border-[#2d2d44] rounded-lg text-sm text-gray-200 focus:ring-2 focus:ring-blue-500 focus:border-blue-500">
        </div>

        <div class="flex gap-2 justify-end mt-5 pt-3 border-t border-[#2d2d44]">
            <button type="button" onclick="closeCustomerModal()" class="px-4 py-2 bg-gray-800 text-gray-300 rounded-lg font-semibold hover:bg-gray-700 transition text-sm">
                Cancel
            </button>
            <button type="button" onclick="saveCustomer()" class="px-4 py-2 bg-blue-600 text-white rounded-lg font-semibold hover:bg-blue-700 transition text-sm">
                <i class="fas fa-save"></i> Save
            </button>
        </div>
    </div>
</div>

<!-- HIDDEN UPDATE FORM -->
<form id="checkoutForm" method="POST" action="edit_invoice_cart.php?order_id=<?= (int)$order_id ?>" class="hidden">
    <input type="hidden" name="csrf_token" value="<?= h($csrf_token) ?>">
    <input type="hidden" name="save_edit_order" value="1">
    <input type="hidden" name="order_id" value="<?= (int)$order_id ?>">
    <input type="hidden" name="customer_id" id="checkoutCustomerId" value="<?= (int)$selected_customer_id ?>">
    <input type="hidden" name="subtotal" id="checkoutSubtotal" value="<?= h(number_format($subtotal, 2, '.', '')) ?>">
    <input type="hidden" name="delivery" id="checkoutDelivery" value="<?= h(number_format($delivery, 2, '.', '')) ?>">
    <input type="hidden" name="group_cost" id="checkoutGroupCost" value="<?= h(number_format($groupCost, 2, '.', '')) ?>">
    <input type="hidden" name="tax" id="checkoutTax" value="<?= h(number_format($tax, 2, '.', '')) ?>">
    <input type="hidden" name="discount" id="checkoutDiscount" value="<?= h(number_format($discount, 2, '.', '')) ?>">
    <input type="hidden" name="total" id="checkoutTotal" value="<?= h(number_format($total, 2, '.', '')) ?>">
    <input type="hidden" name="paid" id="checkoutPaid" value="<?= h(number_format($paid, 2, '.', '')) ?>">
    <input type="hidden" name="due" id="checkoutDue" value="<?= h(number_format($due, 2, '.', '')) ?>">
    <input type="hidden" name="payment_method" id="checkoutPaymentMethod" value="<?= h($order['payment_method'] ?? 'cash') ?>">
    <input type="hidden" name="order_status" id="checkoutOrderStatus" value="<?= h($order['order_status'] ?? 'completed') ?>">
</form>

<script>
const csrfToken = "<?= h($csrf_token) ?>";
const orderId = <?= (int)$order_id ?>;
const posEndpoint = 'edit_invoice_cart.php?order_id=' + orderId;

let searchTimeout = null;
let totalsTimeout = null;
let barcodeTimeout = null;
let isProcessingBarcode = false;
let isProcessingBarcodeInput = false;
let updateTimeouts = {};

function moneyToNumber(value) {
    value = String(value ?? '');

    const bnDigits = {'০':'0','১':'1','২':'2','৩':'3','৪':'4','৫':'5','৬':'6','৭':'7','৮':'8','৯':'9'};
    value = value.replace(/[০-৯]/g, d => bnDigits[d]);
    value = value.replace(/[৳,\s]/g, '');
    value = value.replace(/[^0-9.\-]/g, '');

    const parts = value.split('.');
    if (parts.length > 2) value = parts[0] + '.' + parts.slice(1).join('');

    const num = parseFloat(value);
    return isNaN(num) ? 0 : num;
}

function setMoneyDisplays(subtotal, discount, paid, delivery = null, groupCost = null, tax = null) {
    subtotal = Math.max(0, moneyToNumber(subtotal));
    discount = Math.min(Math.max(0, moneyToNumber(discount)), subtotal);
    paid = Math.max(0, moneyToNumber(paid));

    delivery = delivery === null ? moneyToNumber(document.getElementById('deliveryInput').value) : moneyToNumber(delivery);
    groupCost = groupCost === null ? moneyToNumber(document.getElementById('groupCostInput').value) : moneyToNumber(groupCost);
    tax = tax === null ? moneyToNumber(document.getElementById('taxInput').value) : moneyToNumber(tax);

    delivery = Math.max(0, delivery);
    groupCost = Math.max(0, groupCost);
    tax = Math.max(0, tax);

    const total = Math.max(0, subtotal + delivery + groupCost + tax - discount);

    if (paid > total) paid = total;

    const due = total - paid;

    document.getElementById('subtotalDisplay').textContent = '৳' + subtotal.toFixed(2);
    document.getElementById('totalDisplay').textContent = '৳' + total.toFixed(2);
    document.getElementById('dueDisplay').textContent = '৳' + due.toFixed(2);
    document.getElementById('bottomTotal').textContent = '৳' + total.toFixed(2);

    document.getElementById('checkoutSubtotal').value = subtotal.toFixed(2);
    document.getElementById('checkoutDelivery').value = delivery.toFixed(2);
    document.getElementById('checkoutGroupCost').value = groupCost.toFixed(2);
    document.getElementById('checkoutTax').value = tax.toFixed(2);
    document.getElementById('checkoutDiscount').value = discount.toFixed(2);
    document.getElementById('checkoutPaid').value = paid.toFixed(2);
    document.getElementById('checkoutTotal').value = total.toFixed(2);
    document.getElementById('checkoutDue').value = due.toFixed(2);
}

function escapeHtml(value) {
    const div = document.createElement('div');
    div.textContent = String(value ?? '');
    return div.innerHTML;
}

function handleSearchInput(value) {
    if (isProcessingBarcodeInput) return;

    const trimmed = value.trim();

    if (trimmed.length >= 4 && isBarcodeFormat(trimmed)) {
        if (isProcessingBarcode) return;

        showScannerStatus('scanning', 'Scanning barcode...');
        clearTimeout(barcodeTimeout);

        barcodeTimeout = setTimeout(() => {
            processBarcode(trimmed);
            document.getElementById('searchInput').value = '';
            document.getElementById('searchInput').focus();

            const input = document.getElementById('searchInput');
            input.classList.add('barcode-scanning');
            setTimeout(() => input.classList.remove('barcode-scanning'), 1000);
        }, 300);
    } else {
        searchProducts(trimmed);
    }
}

function handlePriceInput(input, id) {
    const value = moneyToNumber(input.value);
    updateLocalDisplay(id, 'price', value);

    clearTimeout(updateTimeouts['price_' + id]);
    updateTimeouts['price_' + id] = setTimeout(function() {
        updateCart(id, 'price', value);
    }, 600);
}

function handleQtyInput(input, id) {
    const value = moneyToNumber(input.value);
    updateLocalDisplay(id, 'qty', value);

    clearTimeout(updateTimeouts['qty_' + id]);
    updateTimeouts['qty_' + id] = setTimeout(() => {
        updateCart(id, 'qty', value);
    }, 500);
}

function updateLocalDisplay(id, field, value) {
    const item = document.querySelector(`.grid[data-id="${id}"]`);
    if (!item) return;

    const qtyInput = item.querySelector('.qty-input');
    const priceInput = item.querySelector('.price-input');

    const qty = moneyToNumber(qtyInput.value);
    const price = moneyToNumber(priceInput.value);

    const total = qty * price;
    const totalSpan = item.querySelector('.item-total');

    if (totalSpan) {
        totalSpan.textContent = '৳' + total.toFixed(2);
    }

    if (field === 'qty') {
        if (qty <= 0) {
            item.classList.add('opacity-50');
            qtyInput.classList.add('border-red-500');
        } else {
            item.classList.remove('opacity-50');
            qtyInput.classList.remove('border-red-500');
        }
    }

    updateSubtotalDisplay();
}

function updateSubtotalDisplay() {
    let subtotal = 0;
    document.querySelectorAll('#cartItems .grid').forEach(item => {
        const qtyInput = item.querySelector('.qty-input');
        const priceInput = item.querySelector('.price-input');
        if (qtyInput && priceInput) {
            const qty = moneyToNumber(qtyInput.value);
            const price = moneyToNumber(priceInput.value);
            if (qty > 0) subtotal += (price * qty);
        }
    });

    document.getElementById('subtotalDisplay').textContent = '৳' + subtotal.toFixed(2);
    document.getElementById('checkoutSubtotal').value = subtotal.toFixed(2);
    liveCalculate();
}

function liveCalculate() {
    clearTimeout(totalsTimeout);

    totalsTimeout = setTimeout(() => {
        const discount = moneyToNumber(document.getElementById('discountInput').value);
        const paid = moneyToNumber(document.getElementById('paidInput').value);
        const delivery = moneyToNumber(document.getElementById('deliveryInput').value);
        const groupCost = moneyToNumber(document.getElementById('groupCostInput').value);
        const tax = moneyToNumber(document.getElementById('taxInput').value);
        const subtotal = moneyToNumber(document.getElementById('subtotalDisplay').textContent);

        setMoneyDisplays(subtotal, discount, paid, delivery, groupCost, tax);
        saveTotalsToSession(discount, paid, delivery, groupCost, tax);
    }, 300);
}

function saveTotalsToSession(discount, paid, delivery, groupCost, tax) {
    const formData = new FormData();
    formData.append('ajax_totals', '1');
    formData.append('order_id', orderId);
    formData.append('discount', Number(discount || 0).toFixed(2));
    formData.append('paid', Number(paid || 0).toFixed(2));
    formData.append('delivery', Number(delivery || 0).toFixed(2));
    formData.append('group_cost', Number(groupCost || 0).toFixed(2));
    formData.append('tax', Number(tax || 0).toFixed(2));
    formData.append('csrf_token', csrfToken);

    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            setMoneyDisplays(
                moneyToNumber(data.subtotal),
                moneyToNumber(data.discount),
                moneyToNumber(data.paid),
                moneyToNumber(data.delivery),
                moneyToNumber(data.group_cost),
                moneyToNumber(data.tax)
            );
        }
    })
    .catch(err => console.error('Save totals error:', err));
}

function updateCart(id, field, value) {
    const formData = new FormData();
    formData.append('ajax_cart', '1');
    formData.append('order_id', orderId);
    formData.append('action', 'update');
    formData.append('id', id);
    formData.append('field', field);
    formData.append('value', value);
    formData.append('csrf_token', csrfToken);

    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (!data.success) {
            showToast('❌ ' + data.message, 'error');
            return;
        }
        updateTotals();
    })
    .catch(err => console.error(err));
}

function isBarcodeFormat(text) {
    const cleaned = text.replace(/[^0-9A-Z-]/gi, '');
    const numericCount = (cleaned.match(/[0-9]/g) || []).length;
    const totalLength = cleaned.length;

    if (totalLength >= 6 && totalLength <= 20 && (numericCount / totalLength) >= 0.7) return true;
    if (/^[0-9]{8}$/.test(text) || /^[0-9]{12}$/.test(text) || /^[0-9]{13}$/.test(text)) return true;

    return false;
}

function processBarcode(barcode) {
    if (isProcessingBarcode) return;
    if (!barcode || barcode.length < 2) {
        showScannerStatus('error', 'Invalid barcode');
        return;
    }

    isProcessingBarcode = true;
    isProcessingBarcodeInput = true;

    showScannerStatus('processing', 'Processing: ' + barcode);

    fetch(`${posEndpoint}&ajax=barcode_scan&barcode=${encodeURIComponent(barcode)}`)
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                document.getElementById('cartItems').innerHTML = data.cart_html;
                updateTotals();
                showScannerStatus('success', '✅ ' + data.product_name + ' added');
                showToast('✅ ' + data.product_name + ' added to cart', 'success');
            } else {
                showScannerStatus('error', '❌ ' + data.message);
                showToast('❌ ' + data.message, 'error');
            }

            isProcessingBarcode = false;
            isProcessingBarcodeInput = false;
        })
        .catch(err => {
            console.error('Barcode error:', err);
            showScannerStatus('error', '❌ Scanner error');
            isProcessingBarcode = false;
            isProcessingBarcodeInput = false;
        });
}

function showScannerStatus(type, message) {
    const status = document.getElementById('scannerStatus');
    const text = document.getElementById('scannerStatusText');
    const badge = document.getElementById('scannerBadge');

    status.className = 'fixed top-4 left-1/2 -translate-x-1/2 z-50 px-4 py-2 rounded-lg text-sm font-medium shadow-lg transition-all duration-300';

    if (type === 'success') {
        status.classList.add('bg-green-900/50', 'text-green-300', 'border', 'border-green-700');
        status.style.display = 'block';
        badge.className = 'flex items-center gap-1.5 px-3 py-1.5 bg-green-900/30 text-green-400 rounded-full text-xs font-semibold border border-green-800/50';
        setTimeout(() => { status.style.display = 'none'; }, 2000);
    } else if (type === 'error') {
        status.classList.add('bg-red-900/50', 'text-red-300', 'border', 'border-red-700');
        status.style.display = 'block';
        setTimeout(() => { status.style.display = 'none'; }, 3000);
    } else if (type === 'processing') {
        status.classList.add('bg-yellow-900/50', 'text-yellow-300', 'border', 'border-yellow-700');
        status.style.display = 'block';
    } else {
        status.classList.add('bg-blue-900/50', 'text-blue-300', 'border', 'border-blue-700');
        status.style.display = 'block';
    }

    text.textContent = message;
}

function applyFilters() {
    const brand = document.getElementById('brandFilter').value;
    const cat = document.getElementById('categoryFilter').value;
    const search = document.getElementById('searchInput').value;

    let url = 'edit_invoice_cart.php?order_id=' + orderId + '&';
    if (brand > 0) url += 'brand=' + brand + '&';
    if (cat > 0) url += 'cat=' + cat + '&';
    if (search) url += 'search=' + encodeURIComponent(search);

    window.location.href = url;
}

function searchProducts(term) {
    clearTimeout(searchTimeout);

    if (term.length < 1) {
        document.getElementById('searchResults').classList.add('hidden');
        return;
    }

    searchTimeout = setTimeout(() => {
        fetch(`${posEndpoint}&ajax=search_products&term=${encodeURIComponent(term)}`)
            .then(r => r.json())
            .then(data => {
                const resultsDiv = document.getElementById('searchResults');

                if (!data.success || !data.products.length) {
                    resultsDiv.innerHTML = '<div class="p-3 text-gray-500 text-center text-sm">No products found</div>';
                    resultsDiv.classList.remove('hidden');
                    return;
                }

                let html = '';
                data.products.forEach(p => {
                    html += `
                        <div class="flex justify-between items-center px-3 py-2 border-b border-[#2d2d44] cursor-pointer hover:bg-[#0f0f1a] transition text-sm"
                             onclick="addToCart(${p.id})">
                            <span class="font-medium text-gray-200">${escapeHtml(p.product_name)}</span>
                            <span class="font-bold text-blue-400">৳${parseFloat(p.sale_price).toFixed(2)}</span>
                        </div>
                    `;
                });

                resultsDiv.innerHTML = html;
                resultsDiv.classList.remove('hidden');
            })
            .catch(err => console.error('Search error:', err));
    }, 300);
}

document.addEventListener('click', function(e) {
    if (!e.target.closest('#searchInput') && !e.target.closest('#searchResults')) {
        document.getElementById('searchResults').classList.add('hidden');
    }
});

function addToCart(productId) {
    const formData = new FormData();
    formData.append('ajax_cart', '1');
    formData.append('order_id', orderId);
    formData.append('action', 'add');
    formData.append('product_id', productId);
    formData.append('csrf_token', csrfToken);

    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('cartItems').innerHTML = data.cart_html;
            updateTotals();
            showToast('✅ Added to cart', 'success');
            document.getElementById('searchResults').classList.add('hidden');
            document.getElementById('searchInput').value = '';
            document.getElementById('searchInput').focus();
        } else {
            showToast('❌ ' + data.message, 'error');
        }
    })
    .catch(err => console.error('Add error:', err));
}

function removeItem(id) {
    if (!confirm('Remove this item from cart?')) return;

    const formData = new FormData();
    formData.append('ajax_cart', '1');
    formData.append('order_id', orderId);
    formData.append('action', 'remove');
    formData.append('id', id);
    formData.append('csrf_token', csrfToken);

    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('cartItems').innerHTML = data.cart_html;
            updateTotals();
            showToast('🗑️ Item removed', 'info');
        }
    })
    .catch(err => console.error('Remove error:', err));
}

function clearCart() {
    if (!confirm('Clear entire cart?')) return;

    const formData = new FormData();
    formData.append('ajax_cart', '1');
    formData.append('order_id', orderId);
    formData.append('action', 'clear');
    formData.append('csrf_token', csrfToken);

    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            document.getElementById('cartItems').innerHTML = data.cart_html;
            document.getElementById('discountInput').value = '';
            document.getElementById('paidInput').value = '';
            updateTotals();
            showToast('🗑️ Cart cleared', 'info');
            document.getElementById('searchInput').focus();
        }
    })
    .catch(err => console.error('Clear error:', err));
}

function reloadOriginalCart() {
    if (!confirm('Reload original invoice cart? Unsaved changes will be lost.')) return;

    const formData = new FormData();
    formData.append('ajax_cart', '1');
    formData.append('order_id', orderId);
    formData.append('action', 'reload_original');
    formData.append('csrf_token', csrfToken);

    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            window.location.href = 'edit_invoice_cart.php?order_id=' + orderId;
        }
    });
}

function updateTotals() {
    const discount = moneyToNumber(document.getElementById('discountInput').value);
    const paid = moneyToNumber(document.getElementById('paidInput').value);
    const delivery = moneyToNumber(document.getElementById('deliveryInput').value);
    const groupCost = moneyToNumber(document.getElementById('groupCostInput').value);
    const tax = moneyToNumber(document.getElementById('taxInput').value);

    const formData = new FormData();
    formData.append('ajax_totals', '1');
    formData.append('order_id', orderId);
    formData.append('discount', Number(discount || 0).toFixed(2));
    formData.append('paid', Number(paid || 0).toFixed(2));
    formData.append('delivery', Number(delivery || 0).toFixed(2));
    formData.append('group_cost', Number(groupCost || 0).toFixed(2));
    formData.append('tax', Number(tax || 0).toFixed(2));
    formData.append('csrf_token', csrfToken);

    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            setMoneyDisplays(
                moneyToNumber(data.subtotal),
                moneyToNumber(data.discount),
                moneyToNumber(data.paid),
                moneyToNumber(data.delivery),
                moneyToNumber(data.group_cost),
                moneyToNumber(data.tax)
            );
        }
    })
    .catch(err => console.error('Update totals error:', err));
}

function updateCustomer() {
    document.getElementById('checkoutCustomerId').value = document.getElementById('customerSelect').value;
}

function openCustomerModal() {
    document.getElementById('customerModal').classList.remove('hidden');
    document.getElementById('customerModal').classList.add('flex');
}

function closeCustomerModal() {
    document.getElementById('customerModal').classList.add('hidden');
    document.getElementById('customerModal').classList.remove('flex');
    document.getElementById('newCustomerName').value = '';
    document.getElementById('newCustomerPhone').value = '';
    document.getElementById('searchInput').focus();
}

function saveCustomer() {
    const name = document.getElementById('newCustomerName').value.trim();
    const phone = document.getElementById('newCustomerPhone').value.trim();

    if (!name) {
        showToast('❌ Name required', 'error');
        return;
    }

    const formData = new FormData();
    formData.append('ajax_save_customer', '1');
    formData.append('name', name);
    formData.append('phone', phone);
    formData.append('csrf_token', csrfToken);

    fetch(posEndpoint, {
        method: 'POST',
        body: formData
    })
    .then(r => r.json())
    .then(data => {
        if (data.success) {
            const select = document.getElementById('customerSelect');
            const option = document.createElement('option');
            option.value = data.customer.id;
            option.textContent = data.customer.name + (data.customer.phone ? ' (' + data.customer.phone + ')' : '');
            select.appendChild(option);
            select.value = data.customer.id;
            document.getElementById('checkoutCustomerId').value = data.customer.id;

            $('#customerSelect').select2('destroy');
            initSelect2();
            $('#customerSelect').val(data.customer.id).trigger('change');

            closeCustomerModal();
            showToast('✅ Customer saved', 'success');
        } else {
            showToast('❌ ' + data.message, 'error');
        }
    })
    .catch(err => {
        console.error('Save customer error:', err);
        showToast('❌ Failed to save', 'error');
    });
}

function processCheckout() {
    let validItem = false;

    document.querySelectorAll('#cartItems .grid').forEach(item => {
        const qtyInput = item.querySelector('.qty-input');
        const priceInput = item.querySelector('.price-input');
        const qty = moneyToNumber(qtyInput ? qtyInput.value : 0);
        const price = moneyToNumber(priceInput ? priceInput.value : 0);
        if (qty > 0 && price >= 0) validItem = true;
    });

    if (!validItem) {
        showToast('❌ Valid cart item নেই', 'error');
        return;
    }

    clearTimeout(totalsTimeout);

    const discount = moneyToNumber(document.getElementById('discountInput').value);
    const paid = moneyToNumber(document.getElementById('paidInput').value);
    const delivery = moneyToNumber(document.getElementById('deliveryInput').value);
    const groupCost = moneyToNumber(document.getElementById('groupCostInput').value);
    const tax = moneyToNumber(document.getElementById('taxInput').value);
    const subtotal = moneyToNumber(document.getElementById('subtotalDisplay').textContent);

    setMoneyDisplays(subtotal, discount, paid, delivery, groupCost, tax);

    if (!confirm('Update this invoice with current cart?')) return;
	sessionStorage.setItem('edit_order_updated_' + orderId, '1');
    setTimeout(() => document.getElementById('checkoutForm').submit(), 80);
}

function showToast(message, type = 'info') {
    const existing = document.querySelector('.toast-notification');
    if (existing) existing.remove();

    const colors = {
        success: 'bg-emerald-600',
        error: 'bg-red-600',
        info: 'bg-blue-600'
    };

    const toast = document.createElement('div');
    toast.className = `toast-notification fixed top-4 right-4 z-50 ${colors[type] || colors.info} text-white px-4 py-2.5 rounded-lg shadow-lg flex items-center gap-2 text-sm font-medium transition-all duration-300`;
    toast.textContent = message;
    document.body.appendChild(toast);

    setTimeout(() => {
        toast.style.opacity = '0';
        toast.style.transform = 'translateX(20px)';
        setTimeout(() => toast.remove(), 300);
    }, 3000);
}

document.addEventListener('keydown', function(e) {
    if (e.key === 'F2') {
        e.preventDefault();
        document.getElementById('searchInput').focus();
    }
    if (e.key === 'F9') {
        e.preventDefault();
        processCheckout();
    }
    if (e.key === 'F8') {
        e.preventDefault();
        clearCart();
    }
    if (e.key === 'Escape') {
        closeCustomerModal();
    }
});

function initSelect2() {
    $('#customerSelect').select2({
        placeholder: "🔍 Search Customer",
        width: '100%',
        theme: 'default',
        language: {
            noResults: function() {
                return "No customer found. Click + to add new.";
            }
        }
    });
}

$(document).ready(function () {
    initSelect2();
    updateCustomer();
    document.getElementById('searchInput').focus();
});
</script>

</body>
</html>
