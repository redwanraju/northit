<?php
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(false);


if (file_exists("permission.php")) {
    require_once "permission.php";
}

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);
mysqli_report(MYSQLI_REPORT_ERROR | MYSQLI_REPORT_STRICT);

require_once "../config/db.php";

$shop_id = tenantShopId();
$user_role = $_SESSION['role'] ?? 'sr'; // admin or sr

function json_response($data) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode($data, JSON_UNESCAPED_UNICODE);
    exit;
}

function clean_date($date) {
    $date = trim((string)$date);
    return preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) ? $date : '';
}


/* ============================================================
 * SALES REVERSAL HELPERS — matched to riceval1_db schema
 * ============================================================ */
function payment_asset_type($payment_method) {
    $method = strtolower(trim((string)$payment_method));

    if ($method === 'cash' || $method === 'cod') return 'cash';
    if (strpos($method, 'bkash') !== false) return 'bkash';
    if (strpos($method, 'nagad') !== false) return 'nagad';
    if (in_array($method, ['bank', 'card', 'cheque', 'check'], true)) return 'bank';
    if ($method === 'other') return 'other';

    return null;
}

function reverse_asset_payment($conn, $shop_id, $payment_method, $amount, $context = '') {
    $amount = (float)$amount;
    if ($amount <= 0) return;

    $asset_type = payment_asset_type($payment_method);
    if ($asset_type === null) {
        throw new Exception('Unsupported payment method for asset reversal: ' . (string)$payment_method);
    }

    /* Checkout uses the first active asset of the matching type. */
    $stmt = $conn->prepare("
        SELECT id, amount
        FROM assets
        WHERE shop_id = ?
          AND asset_type = ?
          AND status = 'active'
        ORDER BY id ASC
        LIMIT 1
        FOR UPDATE
    ");
    $stmt->bind_param('is', $shop_id, $asset_type);
    $stmt->execute();
    $asset = $stmt->get_result()->fetch_assoc();

    if (!$asset) {
        throw new Exception('Active ' . $asset_type . ' asset not found. ' . $context);
    }

    $asset_id = (int)$asset['id'];
    $stmt = $conn->prepare("
        UPDATE assets
        SET amount = COALESCE(amount, 0) - ?
        WHERE id = ? AND shop_id = ?
    ");
    $stmt->bind_param('dii', $amount, $asset_id, $shop_id);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new Exception('Asset reversal failed. ' . $context);
    }
}

function reverse_customer_due($conn, $shop_id, $customer_id, $amount) {
    $customer_id = (int)$customer_id;
    $amount = (float)$amount;
    if ($customer_id <= 0 || $amount <= 0) return;

    $stmt = $conn->prepare("
        UPDATE customers
        SET due_amount = GREATEST(0, COALESCE(due_amount, 0) - ?)
        WHERE id = ? AND shop_id = ?
    ");
    $stmt->bind_param('dii', $amount, $customer_id, $shop_id);
    $stmt->execute();

    if ($stmt->affected_rows > 1) {
        throw new Exception('Unexpected customer due update result.');
    }
}

function fetch_order_items_with_returns($conn, $order_id, $shop_id, $lock = false) {
    $sql = "
        SELECT
            oi.id AS order_item_id,
            oi.product_id,
            COALESCE(oi.qty, 0) AS sold_qty,
            COALESCE(oi.price, 0) AS price,
            COALESCE(oi.subtotal, 0) AS subtotal,
            COALESCE(oi.cost_price, 0) AS cost_price,
            COALESCE(p.product_name, CONCAT('Product #', oi.product_id)) AS product_name,
            p.unit,
            p.stock AS current_stock
        FROM order_items oi
        INNER JOIN orders o ON o.id = oi.order_id
        LEFT JOIN products p ON p.id = oi.product_id AND p.shop_id = o.shop_id
        WHERE oi.order_id = ? AND o.shop_id = ? AND o.order_source = 'pos'
    ";
    if ($lock) $sql .= ' FOR UPDATE';

    $stmt = $conn->prepare($sql);
    $stmt->bind_param('ii', $order_id, $shop_id);
    $stmt->execute();
    $items = $stmt->get_result()->fetch_all(MYSQLI_ASSOC);

    if (empty($items)) return [];

    $returned_stmt = $conn->prepare("
        SELECT COALESCE(SUM(ri.return_qty), 0) AS returned_qty
        FROM return_items ri
        INNER JOIN return_invoices rinv ON rinv.id = ri.return_id
        WHERE ri.order_item_id = ?
          AND rinv.original_order_id = ?
          AND rinv.shop_id = ?
          AND rinv.return_status = 'completed'
    ");

    foreach ($items as &$item) {
        $order_item_id = (int)$item['order_item_id'];
        $returned_stmt->bind_param('iii', $order_item_id, $order_id, $shop_id);
        $returned_stmt->execute();
        $row = $returned_stmt->get_result()->fetch_assoc();
        $returned_qty = (float)($row['returned_qty'] ?? 0);
        $sold_qty = (float)$item['sold_qty'];

        $item['returned_qty'] = $returned_qty;
        $item['remaining_qty'] = max(0, $sold_qty - $returned_qty);
        /* Front-end expects qty to be the maximum currently returnable qty. */
        $item['qty'] = $item['remaining_qty'];
    }
    unset($item);

    return $items;
}

function add_product_stock($conn, $shop_id, $product_id, $qty, $note) {
    $product_id = (int)$product_id;
    $qty = (float)$qty;
    if ($product_id <= 0 || $qty <= 0) return;

    $stmt = $conn->prepare("
        UPDATE products
        SET stock = COALESCE(stock, 0) + ?
        WHERE id = ? AND shop_id = ?
    ");
    $stmt->bind_param('dii', $qty, $product_id, $shop_id);
    $stmt->execute();

    if ($stmt->affected_rows !== 1) {
        throw new Exception('Product not found while restoring stock. Product ID: ' . $product_id);
    }

    $movement = $conn->prepare("
        INSERT INTO stock_movements (product_id, type, qty, note)
        VALUES (?, 'in', ?, ?)
    ");
    $movement->bind_param('ids', $product_id, $qty, $note);
    $movement->execute();
}

function apply_financial_return($conn, $shop_id, $order, $return_amount, $context = '') {
    $return_amount = max(0, (float)$return_amount);
    $current_due = max(0, (float)($order['due_amount'] ?? 0));
    $current_paid = max(0, (float)($order['paid_amount'] ?? 0));

    $due_reversal = min($current_due, $return_amount);
    $remaining_refund = max(0, $return_amount - $due_reversal);
    $paid_reversal = min($current_paid, $remaining_refund);

    reverse_customer_due(
        $conn,
        $shop_id,
        (int)($order['customer_id'] ?? 0),
        $due_reversal
    );

    reverse_asset_payment(
        $conn,
        $shop_id,
        (string)($order['payment_method'] ?? ''),
        $paid_reversal,
        $context
    );

    return [
        'due_reversal' => $due_reversal,
        'paid_reversal' => $paid_reversal,
        'new_due' => max(0, $current_due - $due_reversal),
        'new_paid' => max(0, $current_paid - $paid_reversal),
    ];
}

function build_order_filter($shop_id, &$params, &$types) {
    $q = trim($_GET['q'] ?? $_GET['search'] ?? '');
    $from = clean_date($_GET['from'] ?? '');
    $to = clean_date($_GET['to'] ?? '');
    $payment_status = trim($_GET['payment_status'] ?? '');
    $order_status = trim($_GET['order_status'] ?? '');

    $where = " WHERE o.shop_id = ? AND o.order_source = 'pos' ";
    $params = [$shop_id];
    $types = "i";

    if ($q !== '') {
        $where .= " AND (
            c.name LIKE ?
            OR c.phone LIKE ?
            OR c.shop_name LIKE ?
            OR o.invoice_no LIKE ?
        ) ";
        $like = "%{$q}%";
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $params[] = $like;
        $types .= "ssss";
    }

    if ($from !== '') {
        $where .= " AND o.created_at >= ? ";
        $params[] = $from . " 00:00:00";
        $types .= "s";
    }

    if ($to !== '') {
        $where .= " AND o.created_at <= ? ";
        $params[] = $to . " 23:59:59";
        $types .= "s";
    }

    $allowed_payment = ['pending','paid','failed','refunded'];
    if (in_array($payment_status, $allowed_payment, true)) {
        $where .= " AND o.payment_status = ? ";
        $params[] = $payment_status;
        $types .= "s";
    }

    $allowed_order = ['pending','processing','completed','cancelled'];
    if (in_array($order_status, $allowed_order, true)) {
        $where .= " AND o.order_status = ? ";
        $params[] = $order_status;
        $types .= "s";
    }

    return $where;
}

// ============================================================
// AJAX: FULL RETURN
// ============================================================
if (isset($_POST['ajax']) && $_POST['ajax'] === 'return_full') {
    try {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $user_id = tenantUserId();

        if ($order_id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid Order ID']);
        }

        $conn->begin_transaction();

        $stmt = $conn->prepare("
            SELECT *
            FROM orders
            WHERE id = ? AND shop_id = ? AND order_source = 'pos'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('ii', $order_id, $shop_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();

        if (!$order) throw new Exception('Order not found.');
        if (($order['order_status'] ?? '') === 'cancelled') {
            throw new Exception('This invoice is already cancelled.');
        }

        $items = fetch_order_items_with_returns($conn, $order_id, $shop_id, true);
        $remaining_items = [];
        $remaining_item_value = 0.0;

        foreach ($items as $item) {
            $qty = (float)$item['remaining_qty'];
            if ($qty <= 0) continue;

            $item['return_qty'] = $qty;
            $item['return_total'] = $qty * (float)$item['price'];
            $remaining_items[] = $item;
            $remaining_item_value += $item['return_total'];
        }

        if (empty($remaining_items)) {
            throw new Exception('No remaining item is available for return.');
        }

        $current_total = max(0, (float)($order['total_amount'] ?? 0));
        $financial_return = $current_total > 0 ? $current_total : $remaining_item_value;
        $return_invoice_no = 'RET-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

        $stmt = $conn->prepare("
            INSERT INTO return_invoices
            (shop_id, original_order_id, return_type, return_invoice_no, total_return_amount, return_reason, return_status, created_by)
            VALUES (?, ?, 'full', ?, ?, ?, 'completed', ?)
        ");
        $stmt->bind_param('iisdsi', $shop_id, $order_id, $return_invoice_no, $financial_return, $reason, $user_id);
        $stmt->execute();
        $return_id = $conn->insert_id;

        $insert_item = $conn->prepare("
            INSERT INTO return_items
            (return_id, order_item_id, product_id, product_name, return_qty, return_price, total_return, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($remaining_items as $item) {
            $order_item_id = (int)$item['order_item_id'];
            $product_id = (int)$item['product_id'];
            $product_name = (string)$item['product_name'];
            $return_qty = (float)$item['return_qty'];
            $return_price = (float)$item['price'];
            $line_total = (float)$item['return_total'];

            $insert_item->bind_param(
                'iiisddds',
                $return_id,
                $order_item_id,
                $product_id,
                $product_name,
                $return_qty,
                $return_price,
                $line_total,
                $reason
            );
            $insert_item->execute();

            add_product_stock(
                $conn,
                $shop_id,
                $product_id,
                $return_qty,
                'Full return: ' . $return_invoice_no . ' / ' . $order['invoice_no']
            );
        }

        /* Full return reverses the complete remaining due and paid balance. */
        reverse_customer_due($conn, $shop_id, (int)($order['customer_id'] ?? 0), (float)($order['due_amount'] ?? 0));
        reverse_asset_payment(
            $conn,
            $shop_id,
            (string)($order['payment_method'] ?? ''),
            (float)($order['paid_amount'] ?? 0),
            'Full return: ' . $order['invoice_no']
        );

        $stmt = $conn->prepare("
            UPDATE orders
            SET order_status = 'cancelled',
                payment_status = 'refunded',
                paid_amount = 0,
                due_amount = 0,
                closing_due = COALESCE(previous_due, 0)
            WHERE id = ? AND shop_id = ? AND order_source = 'pos'
        ");
        $stmt->bind_param('ii', $order_id, $shop_id);
        $stmt->execute();

        $conn->commit();

        json_response([
            'success' => true,
            'message' => 'Full return completed. Stock, customer due and asset were updated.',
            'return_invoice' => $return_invoice_no,
            'return_amount' => number_format($financial_return, 2)
        ]);

    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        json_response(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================================
// AJAX: PARTIAL RETURN
// ============================================================
if (isset($_POST['ajax']) && $_POST['ajax'] === 'return_partial') {
    try {
        $order_id = (int)($_POST['order_id'] ?? 0);
        $return_items = json_decode((string)($_POST['items'] ?? '[]'), true);
        $reason = trim((string)($_POST['reason'] ?? ''));
        $user_id = tenantUserId();

        if ($order_id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid Order ID']);
        }
        if (!is_array($return_items) || empty($return_items)) {
            json_response(['success' => false, 'message' => 'No items selected for return']);
        }

        $conn->begin_transaction();

        $stmt = $conn->prepare("
            SELECT *
            FROM orders
            WHERE id = ? AND shop_id = ? AND order_source = 'pos'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('ii', $order_id, $shop_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();

        if (!$order) throw new Exception('Order not found.');
        if (($order['order_status'] ?? '') === 'cancelled') {
            throw new Exception('This invoice is already cancelled.');
        }

        $db_items = fetch_order_items_with_returns($conn, $order_id, $shop_id, true);
        $item_map = [];
        foreach ($db_items as $item) {
            $item_map[(int)$item['order_item_id']] = $item;
        }

        $return_data = [];
        $raw_return_total = 0.0;
        $seen = [];

        foreach ($return_items as $return_item) {
            $order_item_id = (int)($return_item['order_item_id'] ?? 0);
            $return_qty = (float)($return_item['qty'] ?? 0);

            if ($return_qty <= 0) continue;
            if ($order_item_id <= 0 || !isset($item_map[$order_item_id])) {
                throw new Exception('Invalid order item selected.');
            }
            if (isset($seen[$order_item_id])) {
                throw new Exception('The same order item was selected more than once.');
            }
            $seen[$order_item_id] = true;

            $db_item = $item_map[$order_item_id];
            $remaining_qty = (float)$db_item['remaining_qty'];
            if ($return_qty > $remaining_qty + 0.00001) {
                throw new Exception('Return quantity exceeds the remaining returnable quantity for ' . $db_item['product_name']);
            }

            $line_total = $return_qty * (float)$db_item['price'];
            $return_data[] = [
                'order_item_id' => $order_item_id,
                'product_id' => (int)$db_item['product_id'],
                'product_name' => (string)$db_item['product_name'],
                'qty' => $return_qty,
                'price' => (float)$db_item['price'],
                'total' => $line_total,
            ];
            $raw_return_total += $line_total;
        }

        if (empty($return_data)) throw new Exception('No valid item was selected for return.');

        $current_total = max(0, (float)($order['total_amount'] ?? 0));
        $financial_return = min($raw_return_total, $current_total);
        if ($financial_return <= 0) {
            throw new Exception('This invoice has no remaining financial amount to return.');
        }

        $return_invoice_no = 'RET-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));
        $stmt = $conn->prepare("
            INSERT INTO return_invoices
            (shop_id, original_order_id, return_type, return_invoice_no, total_return_amount, return_reason, return_status, created_by)
            VALUES (?, ?, 'partial', ?, ?, ?, 'completed', ?)
        ");
        $stmt->bind_param('iisdsi', $shop_id, $order_id, $return_invoice_no, $financial_return, $reason, $user_id);
        $stmt->execute();
        $return_id = $conn->insert_id;

        $insert_item = $conn->prepare("
            INSERT INTO return_items
            (return_id, order_item_id, product_id, product_name, return_qty, return_price, total_return, reason)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?)
        ");

        foreach ($return_data as $item) {
            $order_item_id = (int)$item['order_item_id'];
            $product_id = (int)$item['product_id'];
            $product_name = (string)$item['product_name'];
            $qty = (float)$item['qty'];
            $price = (float)$item['price'];
            $line_total = (float)$item['total'];

            $insert_item->bind_param(
                'iiisddds',
                $return_id,
                $order_item_id,
                $product_id,
                $product_name,
                $qty,
                $price,
                $line_total,
                $reason
            );
            $insert_item->execute();

            add_product_stock(
                $conn,
                $shop_id,
                $product_id,
                $qty,
                'Partial return: ' . $return_invoice_no . ' / ' . $order['invoice_no']
            );
        }

        $financial = apply_financial_return(
            $conn,
            $shop_id,
            $order,
            $financial_return,
            'Partial return: ' . $order['invoice_no']
        );

        $new_total = max(0, $current_total - $financial_return);
        $is_fully_returned = $new_total <= 0.005;
        $new_order_status = $is_fully_returned ? 'cancelled' : 'completed';
        $new_payment_status = $is_fully_returned
            ? 'refunded'
            : (($financial['new_due'] <= 0.005) ? 'paid' : 'pending');

        if ($is_fully_returned) {
            $new_total = 0;
            $financial['new_due'] = 0;
            $financial['new_paid'] = 0;
        }

        $new_closing_due = max(0, (float)($order['previous_due'] ?? 0) + (float)$financial['new_due']);

        $stmt = $conn->prepare("
            UPDATE orders
            SET total_amount = ?,
                paid_amount = ?,
                due_amount = ?,
                closing_due = ?,
                order_status = ?,
                payment_status = ?
            WHERE id = ? AND shop_id = ? AND order_source = 'pos'
        ");
        $stmt->bind_param(
            'ddddssii',
            $new_total,
            $financial['new_paid'],
            $financial['new_due'],
            $new_closing_due,
            $new_order_status,
            $new_payment_status,
            $order_id,
            $shop_id
        );
        $stmt->execute();

        $conn->commit();

        json_response([
            'success' => true,
            'message' => 'Partial return completed. Stock, customer due and asset were updated.',
            'return_invoice' => $return_invoice_no,
            'return_amount' => number_format($financial_return, 2)
        ]);

    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        json_response(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================================
// AJAX: GET ORDER ITEMS (for partial return)
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_order_items') {
    try {
        $order_id = (int)($_GET['order_id'] ?? 0);
        if ($order_id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid Order ID']);
        }

        $items = fetch_order_items_with_returns($conn, $order_id, $shop_id, false);
        $items = array_values(array_filter($items, function ($item) {
            return (float)($item['remaining_qty'] ?? 0) > 0;
        }));

        json_response(['success' => true, 'items' => $items]);
    } catch (Throwable $e) {
        json_response(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================================
// AJAX: CANCEL INVOICE
// ============================================================
if (isset($_POST['ajax']) && $_POST['ajax'] === 'cancel_invoice') {
    try {
        $invoice_id = (int)($_POST['id'] ?? 0);
        if ($invoice_id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid Invoice ID']);
        }

        $conn->begin_transaction();

        $stmt = $conn->prepare("
            SELECT *
            FROM orders
            WHERE id = ? AND shop_id = ? AND order_source = 'pos'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('ii', $invoice_id, $shop_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();

        if (!$order) throw new Exception('Invoice not found.');
        if (($order['order_status'] ?? '') === 'cancelled') {
            throw new Exception('Invoice is already cancelled.');
        }

        $items = fetch_order_items_with_returns($conn, $invoice_id, $shop_id, true);
        foreach ($items as $item) {
            $restore_qty = (float)$item['remaining_qty'];
            if ($restore_qty <= 0) continue;

            add_product_stock(
                $conn,
                $shop_id,
                (int)$item['product_id'],
                $restore_qty,
                'Invoice cancelled/restocked: ' . $order['invoice_no']
            );
        }

        reverse_customer_due($conn, $shop_id, (int)($order['customer_id'] ?? 0), (float)($order['due_amount'] ?? 0));
        reverse_asset_payment(
            $conn,
            $shop_id,
            (string)($order['payment_method'] ?? ''),
            (float)($order['paid_amount'] ?? 0),
            'Invoice cancellation: ' . $order['invoice_no']
        );

        $stmt = $conn->prepare("
            UPDATE orders
            SET order_status = 'cancelled',
                payment_status = 'refunded',
                paid_amount = 0,
                due_amount = 0,
                closing_due = COALESCE(previous_due, 0)
            WHERE id = ? AND shop_id = ? AND order_source = 'pos'
        ");
        $stmt->bind_param('ii', $invoice_id, $shop_id);
        $stmt->execute();

        $conn->commit();
        json_response([
            'success' => true,
            'message' => 'Invoice cancelled. Stock, customer due and asset were reversed.'
        ]);

    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        json_response(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================================
// AJAX: DELETE INVOICE (admin only + complete reversal)
// ============================================================
if (isset($_POST['ajax']) && $_POST['ajax'] === 'delete_invoice') {
    try {
        $invoice_id = (int)($_POST['id'] ?? 0);
        if ($invoice_id <= 0) {
            json_response(['success' => false, 'message' => 'Invalid Invoice ID']);
        }
        if ($user_role !== 'admin') {
            throw new Exception('Only admin can delete an invoice.');
        }

        $conn->begin_transaction();

        $stmt = $conn->prepare("
            SELECT *
            FROM orders
            WHERE id = ? AND shop_id = ? AND order_source = 'pos'
            LIMIT 1
            FOR UPDATE
        ");
        $stmt->bind_param('ii', $invoice_id, $shop_id);
        $stmt->execute();
        $order = $stmt->get_result()->fetch_assoc();

        if (!$order) throw new Exception('Invoice not found.');

        $items = fetch_order_items_with_returns($conn, $invoice_id, $shop_id, true);
        $stock_already_restored = (($order['order_status'] ?? '') === 'cancelled');

        if (!$stock_already_restored) {
            foreach ($items as $item) {
                $restore_qty = (float)$item['remaining_qty'];
                if ($restore_qty <= 0) continue;

                add_product_stock(
                    $conn,
                    $shop_id,
                    (int)$item['product_id'],
                    $restore_qty,
                    'Invoice deleted/restocked: ' . $order['invoice_no']
                );
            }
        }

        /* Cancel/full-return actions already zero these fields, preventing double reversal. */
        reverse_customer_due($conn, $shop_id, (int)($order['customer_id'] ?? 0), (float)($order['due_amount'] ?? 0));
        reverse_asset_payment(
            $conn,
            $shop_id,
            (string)($order['payment_method'] ?? ''),
            (float)($order['paid_amount'] ?? 0),
            'Invoice deletion: ' . $order['invoice_no']
        );

        $stmt = $conn->prepare("
            DELETE ri
            FROM return_items ri
            INNER JOIN return_invoices rinv ON rinv.id = ri.return_id
            WHERE rinv.original_order_id = ? AND rinv.shop_id = ?
        ");
        $stmt->bind_param('ii', $invoice_id, $shop_id);
        $stmt->execute();

        $stmt = $conn->prepare("
            DELETE FROM return_invoices
            WHERE original_order_id = ? AND shop_id = ?
        ");
        $stmt->bind_param('ii', $invoice_id, $shop_id);
        $stmt->execute();

        $stmt = $conn->prepare("
            DELETE oi
            FROM order_items oi
            INNER JOIN orders o ON o.id = oi.order_id
            WHERE oi.order_id = ? AND o.shop_id = ? AND o.order_source = 'pos'
        ");
        $stmt->bind_param('ii', $invoice_id, $shop_id);
        $stmt->execute();

        $stmt = $conn->prepare("
            DELETE FROM orders
            WHERE id = ? AND shop_id = ? AND order_source = 'pos'
        ");
        $stmt->bind_param('ii', $invoice_id, $shop_id);
        $stmt->execute();

        if ($stmt->affected_rows !== 1) {
            throw new Exception('Invoice delete failed. No data was changed.');
        }

        $conn->commit();
        json_response([
            'success' => true,
            'message' => 'Invoice deleted. Stock, customer due and asset were reversed.'
        ]);

    } catch (Throwable $e) {
        try { $conn->rollback(); } catch (Throwable $ignore) {}
        json_response(['success' => false, 'message' => $e->getMessage()]);
    }
}

// ============================================================
// AJAX: LOAD INVOICES (existing)
// ============================================================
if (isset($_GET['ajax']) && $_GET['ajax'] === 'get_invoices') {
    try {
        $limit = (int)($_GET['limit'] ?? 20);
        $offset = (int)($_GET['offset'] ?? 0);

        if ($limit <= 0) $limit = 20;
        if ($limit > 100) $limit = 100;
        if ($offset < 0) $offset = 0;

        $params = [];
        $types = "";
        $where = build_order_filter($shop_id, $params, $types);

        $fromJoin = "
            FROM orders o
            LEFT JOIN customers c ON c.id = o.customer_id AND c.shop_id = o.shop_id
            LEFT JOIN users u ON u.id = o.user_id AND u.shop_id = o.shop_id
            LEFT JOIN sales_representatives sr ON sr.id = o.sr_id AND sr.shop_id = o.shop_id
        ";

        $summarySql = "
            SELECT 
                COUNT(o.id) AS total_invoice,
                COALESCE(SUM(CASE WHEN o.order_status <> 'cancelled' THEN o.subtotal ELSE 0 END),0) AS subtotal_sum,
                COALESCE(SUM(CASE WHEN o.order_status <> 'cancelled' THEN o.discount ELSE 0 END),0) AS discount_sum,
                COALESCE(SUM(CASE WHEN o.order_status <> 'cancelled' THEN o.delivery_cost ELSE 0 END),0) AS delivery_sum,
                COALESCE(SUM(CASE WHEN o.order_status <> 'cancelled' THEN o.total_amount ELSE 0 END),0) AS total_amount_sum,
                COALESCE(SUM(CASE WHEN o.order_status <> 'cancelled' THEN o.paid_amount ELSE 0 END),0) AS paid_amount_sum,
                COALESCE(SUM(CASE WHEN o.order_status <> 'cancelled' THEN o.due_amount ELSE 0 END),0) AS due_amount_sum
            {$fromJoin}
            {$where}
        ";

        $summaryStmt = $conn->prepare($summarySql);
        $summaryStmt->bind_param($types, ...$params);
        $summaryStmt->execute();
        $summary = $summaryStmt->get_result()->fetch_assoc();

        $dataSql = "
            SELECT 
                o.id,
                o.shop_id,
                o.customer_id,
                o.invoice_no,
                o.subtotal,
                o.delivery_cost,
                o.group_unit_cost,
                o.total_amount,
                o.discount,
                o.tax,
                o.order_status,
                o.payment_status,
                o.paid_amount,
                o.due_amount,
                o.payment_method,
                o.created_at,
                o.user_id,
                o.sr_id,
                c.shop_name,
                c.name AS customer_name,
                c.phone AS customer_phone,
                c.address AS customer_address,
                c.area_group,
                c.customer_group,
                u.name AS user_name,
                sr.sr_name,
                COALESCE((
                    SELECT COUNT(*) 
                    FROM order_items oi 
                    WHERE oi.order_id = o.id
                ),0) AS item_count,
                COALESCE((
                    SELECT SUM(oi.qty)
                    FROM order_items oi
                    WHERE oi.order_id = o.id
                ),0) AS total_qty
            {$fromJoin}
            {$where}
            ORDER BY o.id DESC
            LIMIT ? OFFSET ?
        ";

        $dataParams = $params;
        $dataTypes = $types . "ii";
        $dataParams[] = $limit;
        $dataParams[] = $offset;

        $stmt = $conn->prepare($dataSql);
        $stmt->bind_param($dataTypes, ...$dataParams);
        $stmt->execute();
        $result = $stmt->get_result();

        $data = [];
        while ($row = $result->fetch_assoc()) {
            foreach (['subtotal', 'delivery_cost', 'group_unit_cost', 'total_amount', 'discount', 'tax', 'paid_amount', 'due_amount', 'total_qty'] as $numKey) {
                $row[$numKey] = (float)($row[$numKey] ?? 0);
            }
            $row['item_count'] = (int)($row['item_count'] ?? 0);
            $data[] = $row;
        }

        json_response([
            'success' => true,
            'data' => $data,
            'summary' => [
                'total_invoice' => (int)($summary['total_invoice'] ?? 0),
                'subtotal_sum' => (float)($summary['subtotal_sum'] ?? 0),
                'discount_sum' => (float)($summary['discount_sum'] ?? 0),
                'delivery_sum' => (float)($summary['delivery_sum'] ?? 0),
                'total_amount_sum' => (float)($summary['total_amount_sum'] ?? 0),
                'paid_amount_sum' => (float)($summary['paid_amount_sum'] ?? 0),
                'due_amount_sum' => (float)($summary['due_amount_sum'] ?? 0),
            ],
            'limit' => $limit,
            'offset' => $offset
        ]);

    } catch (Exception $e) {
        json_response([
            'success' => false,
            'message' => $e->getMessage()
        ]);
    }
}

/* ================= PAGE DATA ================= */
$q = trim($_GET['q'] ?? $_GET['search'] ?? '');
$from = clean_date($_GET['from'] ?? '');
$to = clean_date($_GET['to'] ?? '');
$payment_status = trim($_GET['payment_status'] ?? '');
$order_status = trim($_GET['order_status'] ?? '');

include "header.php";
include "sidebar.php";
?>

<style>
.main { flex: 1; padding: 20px; background: #f8fafc; }
.card-box { background:#fff; padding:15px; border-radius:12px; box-shadow:0 2px 10px rgba(0,0,0,.05); margin-bottom:15px; }
.table td, .table th { vertical-align: middle; }
.action-btn { padding:4px 8px; border-radius:6px; font-size:12px; border:none; cursor:pointer; margin:2px; text-decoration:none; display:inline-block; white-space:nowrap; }
.btn-view { background:#3b82f6; color:#fff; }
.btn-edit { background:#f59e0b; color:#fff; }
.btn-print { background:#0f172a; color:#fff; }
.btn-pdf { background:#ef4444; color:#fff; }
.btn-view:hover { background:#2563eb; color:#fff; }
.btn-edit:hover { background:#d97706; color:#fff; }
.btn-print:hover { background:#020617; color:#fff; }
.btn-pdf:hover { background:#dc2626; color:#fff; }
.no-data { text-align:center; padding:45px; color:#94a3b8; font-size:16px; }
.summary-card { border-radius:12px; padding:12px 14px; color:#fff; min-height:72px; }
.summary-card h6 { margin:0; font-size:12px; opacity:.9; }
.summary-card h4 { margin:5px 0 0; font-size:19px; }
.bg-blue { background:#2563eb; }
.bg-green { background:#059669; }
.bg-red { background:#dc2626; }
.bg-darkx { background:#0f172a; }
.bg-purple { background:#7c3aed; }
.status-badge { padding:4px 8px; border-radius:20px; font-size:11px; color:#fff; }
.status-paid, .status-completed { background:#059669; }
.status-pending, .status-processing { background:#f59e0b; }
.status-failed, .status-cancelled { background:#dc2626; }
.status-refunded { background:#6366f1; }
.status-returned { background:#8b5cf6; }
.search-box { position:relative; }
.search-clear { position:absolute; right:10px; top:50%; transform:translateY(-50%); cursor:pointer; color:#64748b; display:none; }
.view-row { display:flex; justify-content:space-between; gap:12px; padding:9px 0; border-bottom:1px solid #e2e8f0; }
.view-label { font-weight:600; color:#64748b; }
.view-value { font-weight:500; color:#0f172a; text-align:right; }
.table-action-cell { min-width:320px; }
.btn-delete {
    background:#dc2626;
    color:#fff;
}
.btn-delete:hover {
    background:#991b1b;
    color:#fff;
}
.btn-cancel {
    background:#f59e0b;
    color:#fff;
}
.btn-cancel:hover {
    background:#d97706;
    color:#fff;
}
.btn-cancel.cancelled {
    background:#94a3b8;
    color:#fff;
    cursor: not-allowed;
    opacity: 0.7;
}
.btn-cancel.cancelled:hover {
    background:#94a3b8;
}
.btn-return-full {
    background:#8b5cf6;
    color:#fff;
}
.btn-return-full:hover {
    background:#7c3aed;
    color:#fff;
}
.btn-return-partial {
    background:#06b6d4;
    color:#fff;
}
.btn-return-partial:hover {
    background:#0891b2;
    color:#fff;
}
.dropdown-actions {
    position: relative;
    display: inline-block;
}
.dropdown-actions .dropdown-menu {
    min-width: 200px;
    box-shadow: 0 4px 12px rgba(0,0,0,0.15);
    border: 1px solid #e2e8f0;
    border-radius: 8px;
    padding: 8px 0;
}
.dropdown-actions .dropdown-item {
    padding: 8px 16px;
    font-size: 13px;
    display: flex;
    align-items: center;
    gap: 10px;
}
.dropdown-actions .dropdown-item:hover {
    background: #f1f5f9;
}
.dropdown-actions .dropdown-item i {
    width: 18px;
    text-align: center;
}
.dropdown-actions .dropdown-toggle {
    background: #e2e8f0;
    border: none;
    border-radius: 6px;
    padding: 4px 12px;
    font-size: 12px;
    color: #0f172a;
}
.dropdown-actions .dropdown-toggle:hover {
    background: #cbd5e1;
}
.dropdown-actions .dropdown-toggle::after {
    margin-left: 8px;
}
.table-action-cell {
    min-width: 100px;
}
.btn-sm {
    padding: 2px 8px;
    font-size: 11px;
    border-radius: 4px;
}

/* Return Modal Styles */
.return-item-row {
    display: flex;
    align-items: center;
    gap: 10px;
    padding: 8px 0;
    border-bottom: 1px solid #f1f5f9;
}
.return-item-row .item-check {
    flex: 0 0 30px;
}
.return-item-row .item-name {
    flex: 1;
    font-weight: 500;
}
.return-item-row .item-qty {
    flex: 0 0 80px;
}
.return-item-row .item-price {
    flex: 0 0 80px;
    text-align: right;
}
.return-item-row .item-return-qty {
    flex: 0 0 100px;
}
.return-item-row .item-return-qty input {
    width: 80px;
    padding: 4px 8px;
    border: 1px solid #e2e8f0;
    border-radius: 4px;
    text-align: center;
}
.return-item-row .item-return-total {
    flex: 0 0 80px;
    text-align: right;
    font-weight: 600;
}
</style>

<div class="main">

    <div class="d-flex justify-content-between align-items-center mb-3 flex-wrap gap-2">
        <h3 class="mb-0">🧾 Invoice List</h3>
        <div>
            <a href="pos.php" class="btn btn-success">
                <i class="fas fa-plus"></i> New Sale
            </a>
        </div>
    </div>

    <div class="row" id="summaryCards">
        <div class="col-md-2 mb-3"><div class="summary-card bg-darkx"><h6>Total Invoice</h6><h4 id="sumInvoice">0</h4></div></div>
        <div class="col-md-2 mb-3"><div class="summary-card bg-blue"><h6>Total Sales</h6><h4 id="sumSales">৳0.00</h4></div></div>
        <div class="col-md-2 mb-3"><div class="summary-card bg-green"><h6>Total Paid</h6><h4 id="sumPaid">৳0.00</h4></div></div>
        <div class="col-md-2 mb-3"><div class="summary-card bg-red"><h6>Total Due</h6><h4 id="sumDue">৳0.00</h4></div></div>
        <div class="col-md-2 mb-3"><div class="summary-card bg-purple"><h6>Discount</h6><h4 id="sumDiscount">৳0.00</h4></div></div>
        <div class="col-md-2 mb-3"><div class="summary-card bg-darkx"><h6>Delivery</h6><h4 id="sumDelivery">৳0.00</h4></div></div>
    </div>

    <div class="card-box">
        <form id="filterForm" class="row g-2 align-items-end" onsubmit="return false;">
            <div class="col-md-4">
                <label class="form-label">Customer Name / Mobile / Invoice</label>
                <div class="search-box">
                    <input type="text"
                           id="searchInput"
                           class="form-control"
                           placeholder="Customer name or mobile number লিখুন..."
                           value="<?= htmlspecialchars($q) ?>"
                           autocomplete="off">
                    <span class="search-clear" id="clearSearch">✕</span>
                </div>
            </div>

            <div class="col-md-2">
                <label class="form-label">From</label>
                <input type="date" id="fromDate" class="form-control" value="<?= htmlspecialchars($from) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">To</label>
                <input type="date" id="toDate" class="form-control" value="<?= htmlspecialchars($to) ?>">
            </div>

            <div class="col-md-2">
                <label class="form-label">Payment</label>
                <select id="paymentStatus" class="form-control">
                    <option value="">All Payment</option>
                    <option value="pending" <?= $payment_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="paid" <?= $payment_status === 'paid' ? 'selected' : '' ?>>Paid</option>
                    <option value="failed" <?= $payment_status === 'failed' ? 'selected' : '' ?>>Failed</option>
                    <option value="refunded" <?= $payment_status === 'refunded' ? 'selected' : '' ?>>Refunded</option>
                </select>
            </div>

            <div class="col-md-2">
                <label class="form-label">Order</label>
                <select id="orderStatus" class="form-control">
                    <option value="">All Order</option>
                    <option value="pending" <?= $order_status === 'pending' ? 'selected' : '' ?>>Pending</option>
                    <option value="processing" <?= $order_status === 'processing' ? 'selected' : '' ?>>Processing</option>
                    <option value="completed" <?= $order_status === 'completed' ? 'selected' : '' ?>>Completed</option>
                    <option value="cancelled" <?= $order_status === 'cancelled' ? 'selected' : '' ?>>Cancelled</option>
                </select>
            </div>

            <div class="col-md-12 mt-2">
                <button type="button" class="btn btn-primary" id="searchBtn">
                    <i class="fas fa-search"></i> Search
                </button>
                <button type="button" class="btn btn-secondary" id="resetBtn">
                    <i class="fas fa-undo"></i> Reset
                </button>
            </div>
        </form>
    </div>

    <div class="card-box">
        <div class="table-responsive">
            <table class="table table-hover table-bordered align-middle">
                <thead class="table-light">
                    <tr>
                        <th>ID</th>
                        <th>Date</th>
                        <th>Invoice</th>
                        <th>Customer</th>
                        <th>Mobile</th>
                        <th>Items</th>
                        <th>Total</th>
                        <th>Paid</th>
                        <th>Due</th>
                        <th>Payment</th>
                        <th>Order</th>
                        <th class="table-action-cell">Actions</th>
                    </tr>
                </thead>
                <tbody id="invoiceBody">
                    <tr>
                        <td colspan="12" class="text-center py-4">
                            <div class="spinner-border text-primary" role="status"></div>
                            <p class="mt-2 text-muted">Loading invoices...</p>
                        </td>
                    </tr>
                </tbody>
            </table>
        </div>

        <div class="text-center mt-3" id="loadMoreContainer" style="display:none;">
            <button class="btn btn-outline-primary" id="loadMoreBtn" type="button">
                <i class="fas fa-chevron-down"></i> Load More
            </button>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: View Invoice (existing) -->
<!-- ============================================================ -->
<div class="modal fade" id="viewInvoiceModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-primary text-white">
                <h5 class="modal-title">👁 Invoice Details</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body" id="viewInvoiceBody"></div>
            <div class="modal-footer">
                <a href="#" id="viewCartEditLink" class="btn btn-warning">Edit Cart</a>
                <a href="#" id="viewPrintLink" class="btn btn-dark">80mm Print</a>
                <a href="#" id="viewPdfLink" class="btn btn-danger">PDF</a>
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Close</button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: Full Return -->
<!-- ============================================================ -->
<div class="modal fade" id="fullReturnModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered">
        <div class="modal-content">
            <div class="modal-header bg-purple text-white">
                <h5 class="modal-title">🔄 Full Return Confirmation</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="fullReturnOrderId">
                <div class="alert alert-warning">
                    <i class="fas fa-exclamation-triangle"></i>
                    <strong>Warning:</strong> This will return ALL items from this invoice.
                </div>
                <div class="row mb-2">
                    <div class="col-6"><strong>Invoice:</strong> <span id="fullReturnInvoice" class="text-primary"></span></div>
                    <div class="col-6"><strong>Customer:</strong> <span id="fullReturnCustomer"></span></div>
                </div>
                <div class="row mb-3">
                    <div class="col-6"><strong>Total Amount:</strong> <span id="fullReturnTotal" class="fw-bold"></span></div>
                    <div class="col-6"><strong>Return Amount:</strong> <span id="fullReturnAmount" class="fw-bold text-purple"></span></div>
                </div>
                <div class="mb-3">
                    <label class="form-label fw-bold">Return Reason</label>
                    <textarea id="fullReturnReason" class="form-control" rows="2" placeholder="Why is this being returned?"></textarea>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-return-full" id="confirmFullReturn">
                    <i class="fas fa-check"></i> Confirm Full Return
                </button>
            </div>
        </div>
    </div>
</div>

<!-- ============================================================ -->
<!-- MODAL: Partial Return -->
<!-- ============================================================ -->
<div class="modal fade" id="partialReturnModal" tabindex="-1">
    <div class="modal-dialog modal-dialog-centered modal-lg">
        <div class="modal-content">
            <div class="modal-header bg-cyan text-white" style="background:#06b6d4;">
                <h5 class="modal-title">🔄 Partial Return</h5>
                <button type="button" class="btn-close btn-close-white" data-bs-dismiss="modal"></button>
            </div>
            <div class="modal-body">
                <input type="hidden" id="partialReturnOrderId">
                <div class="row mb-2">
                    <div class="col-6"><strong>Invoice:</strong> <span id="partialReturnInvoice" class="text-primary"></span></div>
                    <div class="col-6"><strong>Customer:</strong> <span id="partialReturnCustomer"></span></div>
                </div>
                <hr>
                <div id="partialReturnItems">
                    <div class="text-center py-3">
                        <div class="spinner-border text-primary" role="status"></div>
                        <p>Loading items...</p>
                    </div>
                </div>
                <hr>
                <div class="row">
                    <div class="col-6">
                        <label class="form-label fw-bold">Total Return Amount</label>
                        <h4 class="text-purple" id="partialReturnTotal">৳0.00</h4>
                    </div>
                    <div class="col-6">
                        <label class="form-label fw-bold">Reason</label>
                        <textarea id="partialReturnReason" class="form-control" rows="2" placeholder="Return reason..."></textarea>
                    </div>
                </div>
            </div>
            <div class="modal-footer">
                <button type="button" class="btn btn-secondary" data-bs-dismiss="modal">Cancel</button>
                <button type="button" class="btn btn-return-partial" id="confirmPartialReturn">
                    <i class="fas fa-check"></i> Confirm Partial Return
                </button>
            </div>
        </div>
    </div>
</div>

<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.0/dist/js/bootstrap.bundle.min.js"></script>

<script>
let invoiceData = [];
let currentOffset = 0;
let currentLimit = 20;
let totalInvoices = 0;
let hasMore = true;
let isLoading = false;
let ajaxRequest = null;
let searchTimer = null;

$(document).ready(function() {
    toggleClearButton();
    loadInvoices(true);

    $('#searchInput').on('input', function() {
        clearTimeout(searchTimer);
        searchTimer = setTimeout(function() {
            loadInvoices(true);
        }, 350);
        toggleClearButton();
    });

    $('#fromDate, #toDate, #paymentStatus, #orderStatus').on('change', function() {
        loadInvoices(true);
    });

    $('#searchBtn').on('click', function() {
        loadInvoices(true);
    });

    $('#resetBtn').on('click', function() {
        $('#searchInput').val('');
        $('#fromDate').val('');
        $('#toDate').val('');
        $('#paymentStatus').val('');
        $('#orderStatus').val('');
        toggleClearButton();
        loadInvoices(true);
    });

    $('#clearSearch').on('click', function() {
        $('#searchInput').val('');
        toggleClearButton();
        loadInvoices(true);
    });

    $('#loadMoreBtn').on('click', function() {
        if (hasMore && !isLoading) {
            currentOffset += currentLimit;
            loadInvoices(false);
        }
    });

    // Full Return
    $('#confirmFullReturn').on('click', function() {
        const orderId = $('#fullReturnOrderId').val();
        const reason = $('#fullReturnReason').val();
        confirmFullReturn(orderId, reason);
    });

    // Partial Return
    $('#confirmPartialReturn').on('click', function() {
        const orderId = $('#partialReturnOrderId').val();
        const reason = $('#partialReturnReason').val();
        confirmPartialReturn(orderId, reason);
    });
});

function loadInvoices(reset = true) {
    if (isLoading && ajaxRequest) {
        ajaxRequest.abort();
    }

    if (reset) {
        currentOffset = 0;
        invoiceData = [];
        hasMore = true;
        $('#loadMoreContainer').hide();
        $('#invoiceBody').html(`
            <tr>
                <td colspan="12" class="text-center py-4">
                    <div class="spinner-border text-primary" role="status"></div>
                    <p class="mt-2 text-muted">Loading invoices...</p>
                </td>
            </tr>
        `);
    }

    isLoading = true;

    ajaxRequest = $.ajax({
        url: window.location.pathname,
        type: 'GET',
        dataType: 'json',
        cache: false,
        timeout: 15000,
        data: {
            ajax: 'get_invoices',
            q: $('#searchInput').val(),
            from: $('#fromDate').val(),
            to: $('#toDate').val(),
            payment_status: $('#paymentStatus').val(),
            order_status: $('#orderStatus').val(),
            limit: currentLimit,
            offset: currentOffset
        },
        success: function(response) {
            if (response.success) {
                totalInvoices = parseInt(response.summary.total_invoice || 0);
                const rows = response.data || [];

                if (reset) {
                    invoiceData = rows;
                    renderInvoices(rows);
                } else {
                    invoiceData = invoiceData.concat(rows);
                    appendInvoices(rows);
                }

                hasMore = (currentOffset + rows.length) < totalInvoices;
                $('#loadMoreContainer').toggle(hasMore);
                updateSummary(response.summary);
            } else {
                showTableError(response.message || 'Failed to load invoices');
            }
        },
        error: function(xhr, status) {
            if (status !== 'abort') {
                console.error(xhr.responseText);
                showTableError('Error loading invoices. Please check console.');
            }
        },
        complete: function() {
            isLoading = false;
            ajaxRequest = null;
        }
    });
}

function renderInvoices(rows) {
    if (!rows || rows.length === 0) {
        $('#invoiceBody').html(`
            <tr>
                <td colspan="12" class="no-data">
                    <i class="fas fa-file-invoice fa-3x mb-3"></i><br>
                    No invoice found.<br>
                    <small>Customer name/mobile number দিয়ে search করুন।</small>
                </td>
            </tr>
        `);
        return;
    }

    $('#invoiceBody').html(invoiceRowsHtml(rows));
}

function appendInvoices(rows) {
    if (!rows || rows.length === 0) return;
    $('#invoiceBody').append(invoiceRowsHtml(rows));
}

function invoiceRowsHtml(rows) {
    let html = '';
    const isAdmin = '<?= $user_role ?>' === 'admin';

    rows.forEach(function(row) {
        const id = parseInt(row.id || 0);
        const paymentStatus = row.payment_status || 'pending';
        const orderStatus = row.order_status || 'pending';
        const isCancelled = orderStatus === 'cancelled';

        html += `
            <tr>
                <td>${id}</td>
                <td>${formatDateTime(row.created_at)}</td>
                <td><strong>${escapeHtml(row.invoice_no || '')}</strong></td>
                <td>
                    <strong>${escapeHtml(row.customer_name || 'Walk In Customer')}</strong>
                    ${row.shop_name ? `<div class="text-muted small">${escapeHtml(row.shop_name)}</div>` : ''}
                </td>
                <td>${escapeHtml(row.customer_phone || '')}</td>
                <td>${parseInt(row.item_count || 0)} / Qty: ${formatQty(row.total_qty)}</td>
                <td class="text-end fw-bold">৳${formatMoney(row.total_amount)}</td>
                <td class="text-end text-success fw-bold">৳${formatMoney(row.paid_amount)}</td>
                <td class="text-end text-danger fw-bold">৳${formatMoney(row.due_amount)}</td>
                <td><span class="status-badge status-${escapeHtml(paymentStatus)}">${escapeHtml(statusText(paymentStatus))}</span></td>
                <td><span class="status-badge status-${escapeHtml(orderStatus)}">${escapeHtml(statusText(orderStatus))}</span></td>
                <td>
                    <div class="dropdown-actions">
                        <button class="dropdown-toggle" type="button" data-bs-toggle="dropdown" aria-expanded="false">
                            <i class="fas fa-ellipsis-v"></i> Actions
                        </button>
                        <ul class="dropdown-menu dropdown-menu-end">
                            <li>
                                <button type="button" class="dropdown-item" onclick="viewInvoice(${id})">
                                    <i class="fas fa-eye text-primary"></i> View
                                </button>
                            </li>
                            ${!isCancelled ? `
                            <li>
                                <a class="dropdown-item" href="edit_invoice_cart.php?order_id=${id}">
                                    <i class="fas fa-shopping-cart text-warning"></i> Edit Cart
                                </a>
                            </li>
                            ` : ''}
                            <li>
                                <a class="dropdown-item" href="invoice_print.php?id=${id}" target="_blank">
                                    <i class="fas fa-print text-dark"></i> 80mm Print
                                </a>
                            </li>
                            <li>
                                <a class="dropdown-item" href="invoice_pdf.php?id=${id}" target="_blank">
                                    <i class="fas fa-file-pdf text-danger"></i> PDF
                                </a>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            ${!isCancelled ? `
                            <li>
                                <button type="button" class="dropdown-item text-purple" onclick="openFullReturn(${id})">
                                    <i class="fas fa-undo-alt"></i> Full Return
                                </button>
                            </li>
                            <li>
                                <button type="button" class="dropdown-item text-cyan" onclick="openPartialReturn(${id})" style="color:#06b6d4 !important;">
                                    <i class="fas fa-exchange-alt"></i> Partial Return
                                </button>
                            </li>
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button type="button" class="dropdown-item text-warning" onclick="cancelInvoice(${id})">
                                    <i class="fas fa-ban"></i> Cancel & Restock
                                </button>
                            </li>
                            ` : ''}
                            ${isAdmin ? `
                            <li><hr class="dropdown-divider"></li>
                            <li>
                                <button type="button" class="dropdown-item text-danger" onclick="deleteInvoice(${id})">
                                    <i class="fas fa-trash"></i> Delete (Admin)
                                </button>
                            </li>
                            ` : ''}
                        </ul>
                    </div>
                </td>
            </tr>
        `;
    });

    return html;
}

function updateSummary(summary) {
    $('#sumInvoice').text(summary.total_invoice || 0);
    $('#sumSales').text('৳' + formatMoney(summary.total_amount_sum));
    $('#sumPaid').text('৳' + formatMoney(summary.paid_amount_sum));
    $('#sumDue').text('৳' + formatMoney(summary.due_amount_sum));
    $('#sumDiscount').text('৳' + formatMoney(summary.discount_sum));
    $('#sumDelivery').text('৳' + formatMoney(summary.delivery_sum));
}

// ============================================================
// VIEW INVOICE (existing)
// ============================================================
window.viewInvoice = function(id) {
    const row = findInvoice(id);
    if (!row) {
        alert('Invoice not found.');
        return;
    }

    $('#viewInvoiceBody').html(`
        <div class="view-row"><span class="view-label">Invoice</span><span class="view-value">${escapeHtml(row.invoice_no || '')}</span></div>
        <div class="view-row"><span class="view-label">Date</span><span class="view-value">${formatDateTime(row.created_at)}</span></div>
        <div class="view-row"><span class="view-label">Customer</span><span class="view-value">${escapeHtml(row.customer_name || 'Walk In Customer')}</span></div>
        <div class="view-row"><span class="view-label">Mobile</span><span class="view-value">${escapeHtml(row.customer_phone || '')}</span></div>
        <div class="view-row"><span class="view-label">Address</span><span class="view-value">${escapeHtml(row.customer_address || '')}</span></div>
        <div class="view-row"><span class="view-label">Subtotal</span><span class="view-value">৳${formatMoney(row.subtotal)}</span></div>
        <div class="view-row"><span class="view-label">Discount</span><span class="view-value">৳${formatMoney(row.discount)}</span></div>
        <div class="view-row"><span class="view-label">Delivery</span><span class="view-value">৳${formatMoney(row.delivery_cost)}</span></div>
        <div class="view-row"><span class="view-label">Total</span><span class="view-value">৳${formatMoney(row.total_amount)}</span></div>
        <div class="view-row"><span class="view-label">Paid</span><span class="view-value text-success">৳${formatMoney(row.paid_amount)}</span></div>
        <div class="view-row"><span class="view-label">Due</span><span class="view-value text-danger">৳${formatMoney(row.due_amount)}</span></div>
        <div class="view-row"><span class="view-label">Payment</span><span class="view-value">${escapeHtml(statusText(row.payment_status || ''))}</span></div>
        <div class="view-row"><span class="view-label">Order</span><span class="view-value">${escapeHtml(statusText(row.order_status || ''))}</span></div>
    `);

    $('#viewCartEditLink').attr('href', 'edit_invoice_cart.php?order_id=' + id);
    $('#viewPrintLink').attr('href', 'invoice_print.php?id=' + id);
    $('#viewPdfLink').attr('href', 'invoice_pdf.php?id=' + id);

    showModal('viewInvoiceModal');
};

// ============================================================
// FULL RETURN
// ============================================================
window.openFullReturn = function(orderId) {
    const row = findInvoice(orderId);
    if (!row) {
        alert('Invoice not found.');
        return;
    }

    if (row.order_status === 'cancelled') {
        alert('This invoice is already cancelled.');
        return;
    }

    $('#fullReturnOrderId').val(orderId);
    $('#fullReturnInvoice').text(row.invoice_no || '');
    $('#fullReturnCustomer').text(row.customer_name || 'Walk In Customer');
    $('#fullReturnTotal').text('৳' + formatMoney(row.total_amount));
    $('#fullReturnAmount').text('৳' + formatMoney(row.total_amount));
    $('#fullReturnReason').val('');

    showModal('fullReturnModal');
};

window.confirmFullReturn = function(orderId, reason) {
    if (!confirm('Are you sure you want to return ALL items from this invoice?')) {
        return;
    }

    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        dataType: 'json',
        data: {
            ajax: 'return_full',
            order_id: orderId,
            reason: reason
        },
        success: function(res) {
            if (res.success) {
                alert('✅ Full Return Completed!\n' + 
                      'Return Invoice: ' + res.return_invoice + '\n' +
                      'Return Amount: ৳' + res.return_amount);
                $('#fullReturnModal').modal('hide');
                loadInvoices(true);
            } else {
                alert('❌ ' + res.message);
            }
        },
        error: function() {
            alert('Return failed. Please try again.');
        }
    });
};

// ============================================================
// PARTIAL RETURN
// ============================================================
window.openPartialReturn = function(orderId) {
    const row = findInvoice(orderId);
    if (!row) {
        alert('Invoice not found.');
        return;
    }

    if (row.order_status === 'cancelled') {
        alert('This invoice is already cancelled.');
        return;
    }

    $('#partialReturnOrderId').val(orderId);
    $('#partialReturnInvoice').text(row.invoice_no || '');
    $('#partialReturnCustomer').text(row.customer_name || 'Walk In Customer');
    $('#partialReturnReason').val('');
    $('#partialReturnTotal').text('৳0.00');

    // Load items
    $('#partialReturnItems').html(`
        <div class="text-center py-3">
            <div class="spinner-border text-primary" role="status"></div>
            <p>Loading items...</p>
        </div>
    `);

    showModal('partialReturnModal');

    $.ajax({
        url: window.location.pathname,
        type: 'GET',
        dataType: 'json',
        data: {
            ajax: 'get_order_items',
            order_id: orderId
        },
        success: function(res) {
            if (res.success && res.items.length > 0) {
                renderPartialReturnItems(res.items);
            } else {
                $('#partialReturnItems').html(`
                    <div class="alert alert-danger">${res.message || 'No items found'}</div>
                `);
            }
        },
        error: function() {
            $('#partialReturnItems').html(`
                <div class="alert alert-danger">Failed to load items.</div>
            `);
        }
    });
};

function renderPartialReturnItems(items) {
    let html = `
        <div class="mb-2">
            <div class="row fw-bold text-muted small">
                <div class="col-4">Product</div>
                <div class="col-2">Qty</div>
                <div class="col-2">Price</div>
                <div class="col-2">Return Qty</div>
                <div class="col-2 text-end">Total</div>
            </div>
        </div>
    `;

    items.forEach(function(item, index) {
        const maxQty = parseFloat(item.qty || 0);
        const price = parseFloat(item.price || 0);
        const productName = item.product_name || 'Unknown Product';

        html += `
            <div class="return-item-row" data-index="${index}" data-order-item-id="${parseInt(item.order_item_id || 0)}">
                <div class="item-check">
                    <input type="checkbox" class="form-check-input return-item-check" data-max="${maxQty}" checked>
                </div>
                <div class="item-name">${escapeHtml(productName)}</div>
                <div class="item-qty">${maxQty}</div>
                <div class="item-price">৳${formatMoney(price)}</div>
                <div class="item-return-qty">
                    <input type="number" class="form-control form-control-sm return-qty-input" 
                           value="${maxQty}" min="0" max="${maxQty}" step="0.01">
                </div>
                <div class="item-return-total return-total-display">৳${formatMoney(maxQty * price)}</div>
            </div>
        `;
    });

    $('#partialReturnItems').html(html);

    // Event listeners for partial return
    $('.return-item-check, .return-qty-input').on('change input', function() {
        calculatePartialReturnTotal();
    });

    calculatePartialReturnTotal();
}

function calculatePartialReturnTotal() {
    let total = 0;
    let hasSelected = false;

    $('.return-item-row').each(function() {
        const checked = $(this).find('.return-item-check').is(':checked');
        const qty = parseFloat($(this).find('.return-qty-input').val()) || 0;
        const price = parseFloat($(this).find('.item-price').text().replace('৳', '').replace(/,/g, '')) || 0;

        if (checked && qty > 0) {
            const itemTotal = qty * price;
            total += itemTotal;
            $(this).find('.return-total-display').text('৳' + formatMoney(itemTotal));
            hasSelected = true;
        } else {
            $(this).find('.return-total-display').text('৳0.00');
        }
    });

    $('#partialReturnTotal').text('৳' + formatMoney(total));
    
    // Enable/disable confirm button
    $('#confirmPartialReturn').prop('disabled', !hasSelected || total <= 0);
}

window.confirmPartialReturn = function(orderId, reason) {
    const items = [];
    let hasValidItem = false;

    $('.return-item-row').each(function() {
        const checked = $(this).find('.return-item-check').is(':checked');
        const qty = parseFloat($(this).find('.return-qty-input').val()) || 0;

        if (checked && qty > 0) {
            const orderItemId = parseInt($(this).attr('data-order-item-id') || 0)
            items.push({
                order_item_id: orderItemId,
                qty: qty
            });
            hasValidItem = true;
        }
    });

    if (!hasValidItem) {
        alert('Please select at least one item to return.');
        return;
    }

    if (!confirm('Are you sure you want to return selected items?')) {
        return;
    }

    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        dataType: 'json',
        data: {
            ajax: 'return_partial',
            order_id: orderId,
            items: JSON.stringify(items),
            reason: reason
        },
        success: function(res) {
            if (res.success) {
                alert('✅ Partial Return Completed!\n' + 
                      'Return Invoice: ' + res.return_invoice + '\n' +
                      'Return Amount: ৳' + res.return_amount);
                $('#partialReturnModal').modal('hide');
                loadInvoices(true);
            } else {
                alert('❌ ' + res.message);
            }
        },
        error: function() {
            alert('Return failed. Please try again.');
        }
    });
};

// ============================================================
// CANCEL INVOICE (existing)
// ============================================================
window.cancelInvoice = function(id) {
    if (!confirm('আপনি কি এই Invoice Cancel করতে চান? স্টক আপডেট হবে।')) {
        return;
    }

    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        dataType: 'json',
        data: {
            ajax: 'cancel_invoice',
            id: id
        },
        success: function(res) {
            if (res.success) {
                alert('✅ Invoice Cancelled Successfully. Stock updated.');
                loadInvoices(true);
            } else {
                alert('❌ ' + res.message);
            }
        },
        error: function() {
            alert('Cancel failed');
        }
    });
};

// ============================================================
// DELETE INVOICE (existing - for admin only)
// ============================================================
window.deleteInvoice = function(id) {
    if (!confirm('⚠️ আপনি কি এই Invoice Delete করতে চান? Stock, Customer Due এবং Cash Asset reverse হবে।')) {
        return;
    }

    if (!confirm('এটি স্থায়ীভাবে মুছে যাবে। নিশ্চিত?')) {
        return;
    }

    $.ajax({
        url: window.location.pathname,
        type: 'POST',
        dataType: 'json',
        data: {
            ajax: 'delete_invoice',
            id: id
        },
        success: function(res) {
            if (res.success) {
                alert('✅ Invoice Deleted Successfully. Stock, Customer Due এবং Cash Asset reset হয়েছে।');
                loadInvoices(true);
            } else {
                alert('❌ ' + res.message);
            }
        },
        error: function() {
            alert('Delete failed');
        }
    });
};

// ============================================================
// HELPER FUNCTIONS
// ============================================================
function findInvoice(id) {
    return invoiceData.find(function(row) {
        return parseInt(row.id) === parseInt(id);
    });
}

function showTableError(message) {
    $('#invoiceBody').html(`
        <tr>
            <td colspan="12" class="text-center text-danger py-4">
                <i class="fas fa-exclamation-triangle fa-2x d-block mb-2"></i>
                ${escapeHtml(message)}
            </td>
        </tr>
    `);
}

function showModal(modalId) {
    const el = document.getElementById(modalId);

    if (window.bootstrap && bootstrap.Modal) {
        bootstrap.Modal.getOrCreateInstance(el).show();
        return;
    }

    if (window.jQuery && typeof $('#' + modalId).modal === 'function') {
        $('#' + modalId).modal('show');
        return;
    }

    alert('Bootstrap modal JS is not loaded.');
}

function toggleClearButton() {
    $('#clearSearch').toggle($('#searchInput').val().length > 0);
}

function statusText(text) {
    if (!text) return '';
    return String(text).replace(/_/g, ' ').replace(/\b\w/g, function(l) {
        return l.toUpperCase();
    });
}

function formatDateTime(value) {
    if (!value) return '';
    return String(value).replace('T', ' ').substring(0, 19);
}

function formatQty(value) {
    const num = Number(value || 0);
    return num.toLocaleString('en-US', {
        minimumFractionDigits: 0,
        maximumFractionDigits: 2
    });
}

function formatMoney(value) {
    return Number(value || 0).toLocaleString('en-US', {
        minimumFractionDigits: 2,
        maximumFractionDigits: 2
    });
}

function escapeHtml(text) {
    if (text === null || text === undefined) return '';
    const div = document.createElement('div');
    div.textContent = String(text);
    return div.innerHTML;
}
</script>

<?php include "footer.php"; ?>