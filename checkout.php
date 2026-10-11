<?php
session_start();

error_reporting(E_ALL);
ini_set('display_errors', 1);
ini_set('log_errors', 1);

include "../config/db.php";

/* ================= OPTIONAL AUTH ================= */
if (file_exists("auth.php")) {
    include "auth.php";
    if (function_exists("requireLogin")) {
        requireLogin();
    }
}

/* ================= MONEY HELPER ================= */
function moneyToFloat($value) {
    if ($value === null || $value === '') return 0.0;

    $value = (string)$value;

    // Bangla digits to English digits
    $bnDigits = ['০','১','২','৩','৪','৫','৬','৭','৮','৯'];
    $enDigits = ['0','1','2','3','4','5','6','7','8','9'];
    $value = str_replace($bnDigits, $enDigits, $value);

    // Remove currency, comma, spaces
    $value = str_replace(['৳', ',', ' '], '', $value);
    $value = preg_replace('/[^0-9.\-]/', '', $value);

    if ($value === '' || $value === '-' || $value === '.') return 0.0;

    // Keep only first decimal point
    $parts = explode('.', $value);
    if (count($parts) > 2) {
        $value = array_shift($parts) . '.' . implode('', $parts);
    }

    return is_numeric($value) ? (float)$value : 0.0;
}

/* ================= SESSION CHECK ================= */
$shop_id = tenantShopId();
$user_id = (int)($_SESSION['user_id'] ?? 0);

if ($user_id <= 0) {
    die("User session expired. Please login again.");
}

/* ================= CSRF CHECK ================= */
if (isset($_SESSION['csrf_token'])) {
    $posted_token = $_POST['csrf_token'] ?? '';
    if ($posted_token === '' || !hash_equals($_SESSION['csrf_token'], $posted_token)) {
        die("Invalid security token. Please refresh POS page.");
    }
}

/* ================= CART CHECK ================= */
if (empty($_SESSION['cart']) || !is_array($_SESSION['cart'])) {
    die("Cart is empty");
}

/* ================= INPUT ================= */
$customer_id = (int)($_POST['customer_id'] ?? 0);

/*
    POS hidden form আগে name="paid" পাঠাচ্ছিল,
    checkout.php paid_amount ধরছিল।
    তাই দুইটাই support করা হলো।
*/
$discount        = moneyToFloat($_POST['discount'] ?? $_SESSION['discount_amount'] ?? 0);
$delivery_cost   = moneyToFloat($_POST['delivery_cost'] ?? 0);
$group_unit_cost = moneyToFloat($_POST['group_unit_cost'] ?? 0);
$paid_amount     = moneyToFloat($_POST['paid_amount'] ?? $_POST['paid'] ?? $_SESSION['paid_amount'] ?? 0);
$payment_method  = trim($_POST['payment_method'] ?? 'cash');

if ($payment_method === '') {
    $payment_method = 'cash';
}

/* ================= SR ID ================= */
$sr_id = !empty($_POST['sr_id']) ? (int)$_POST['sr_id'] : 0;

/*
    যদি POS থেকে sr_id না আসে,
    selected customer-এর sr_id customers table থেকে নেওয়া হবে।
*/
if ($sr_id <= 0 && $customer_id > 0) {
    $stmt_sr = $conn->prepare("SELECT sr_id FROM customers WHERE id = ? AND shop_id = ? LIMIT 1");
    if ($stmt_sr) {
        $stmt_sr->bind_param("ii", $customer_id, $shop_id);
        $stmt_sr->execute();
        $sr_row = $stmt_sr->get_result()->fetch_assoc();
        if ($sr_row && !empty($sr_row['sr_id'])) {
            $sr_id = (int)$sr_row['sr_id'];
        }
        $stmt_sr->close();
    }
}

/* ================= SERVER SIDE TOTAL CALCULATION ================= */
$subtotal = 0.0;
$valid_items = [];

foreach ($_SESSION['cart'] as $product_id => $item) {
    $product_id = (int)$product_id;
    $qty   = moneyToFloat($item['qty'] ?? 0);
    $price = moneyToFloat($item['price'] ?? 0);

    if ($product_id <= 0 || $qty <= 0 || $price < 0) {
        continue;
    }

    $line_total = $qty * $price;
    $subtotal += $line_total;

    $valid_items[] = [
        'product_id' => $product_id,
        'qty'        => $qty,
        'price'      => $price,
        'subtotal'   => $line_total
    ];
}

if (empty($valid_items)) {
    die("No valid cart item found.");
}

/* Discount subtotal এর বেশি হতে পারবে না */
if ($discount < 0) {
    $discount = 0;
}
if ($discount > $subtotal) {
    $discount = $subtotal;
}

if ($delivery_cost < 0) {
    $delivery_cost = 0;
}
if ($group_unit_cost < 0) {
    $group_unit_cost = 0;
}
if ($paid_amount < 0) {
    $paid_amount = 0;
}

$total_amount = $subtotal - $discount + $delivery_cost + $group_unit_cost;

if ($total_amount < 0) {
    $total_amount = 0;
}

/*
    Customer row transaction-এর ভিতরে lock করে previous due নেওয়া হবে।
    Browser থেকে পাঠানো previous_due/due accounting source হিসেবে ব্যবহার করা হবে না।
*/
$previous_due = 0.0;
$due_amount = 0.0;
$closing_due = 0.0;
$order_status = 'completed';
$payment_status = 'pending';

/* ================= INVOICE NO ================= */
$invoice_no = 'RV-' . date('Ymd') . '-' . strtoupper(substr(uniqid(), -6));

/* ================= DATABASE TRANSACTION ================= */
$conn->begin_transaction();

try {

    if ($customer_id > 0) {
        $customerCheck = $conn->prepare("
            SELECT id, sr_id, due_amount
            FROM customers
            WHERE id = ? AND shop_id = ?
            LIMIT 1
            FOR UPDATE
        ");

        if (!$customerCheck) {
            throw new Exception("Customer check prepare failed: " . $conn->error);
        }

        $customerCheck->bind_param("ii", $customer_id, $shop_id);
        $customerCheck->execute();
        $customerRow = $customerCheck->get_result()->fetch_assoc();
        $customerCheck->close();

        if (!$customerRow) {
            throw new Exception("Selected customer does not belong to your shop.");
        }

        $previous_due = max(0, moneyToFloat($customerRow['due_amount'] ?? 0));

        if ($sr_id <= 0 && !empty($customerRow['sr_id'])) {
            $sr_id = (int)$customerRow['sr_id'];
        }
    }

    /*
        Current invoice due এবং customer closing due আলাদা রাখা হচ্ছে।
        Paid amount current sale-এর চেয়ে বেশি হলে অতিরিক্ত অংশ previous due কমাবে।
    */
    $due_amount = max(0, $total_amount - $paid_amount);
    $closing_due = max(0, $previous_due + $total_amount - $paid_amount);
    $payment_status = ($due_amount <= 0) ? 'paid' : 'pending';
    $order_source = 'pos';

    if ($sr_id > 0) {
        $srCheck = $conn->prepare("SELECT id FROM sales_representatives WHERE id = ? AND shop_id = ? AND status = 1 LIMIT 1");
        $srCheck->bind_param("ii", $sr_id, $shop_id);
        $srCheck->execute();
        if (!$srCheck->get_result()->fetch_assoc()) {
            throw new Exception("Selected SR does not belong to your shop.");
        }
    }

    $productCheck = $conn->prepare("SELECT id FROM products WHERE id = ? AND shop_id = ? LIMIT 1 FOR UPDATE");
    foreach ($valid_items as $validItem) {
        $validProductId = (int)$validItem['product_id'];
        $productCheck->bind_param("ii", $validProductId, $shop_id);
        $productCheck->execute();
        if (!$productCheck->get_result()->fetch_assoc()) {
            throw new Exception("One or more cart products do not belong to your shop.");
        }
    }

    /* ================= INSERT ORDER ================= */
    $sql = "INSERT INTO orders (
        shop_id,
        customer_id,
        invoice_no,
        subtotal,
        delivery_cost,
        group_unit_cost,
        total_amount,
        discount,
        order_status,
        payment_status,
        paid_amount,
        due_amount,
        previous_due,
        closing_due,
        payment_method,
        order_source,
        user_id,
        sr_id
    ) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?)";

    $stmt = $conn->prepare($sql);

    if (!$stmt) {
        throw new Exception("Order prepare failed: " . $conn->error);
    }

    $stmt->bind_param(
        "iisdddddssddddssii",
        $shop_id,
        $customer_id,
        $invoice_no,
        $subtotal,
        $delivery_cost,
        $group_unit_cost,
        $total_amount,
        $discount,
        $order_status,
        $payment_status,
        $paid_amount,
        $due_amount,
        $previous_due,
        $closing_due,
        $payment_method,
        $order_source,
        $user_id,
        $sr_id
    );

    if (!$stmt->execute()) {
        throw new Exception("Order execute failed: " . $stmt->error);
    }

    $order_id = $stmt->insert_id;
    $stmt->close();

    /* ================= INSERT ORDER ITEMS + STOCK UPDATE ================= */
    foreach ($valid_items as $item) {

        $product_id = (int)$item['product_id'];
        $qty        = (float)$item['qty'];
        $price      = (float)$item['price'];
        $sub_total  = (float)$item['subtotal'];

        $sql_item = "INSERT INTO order_items 
            (order_id, product_id, qty, price, subtotal)
            VALUES (?, ?, ?, ?, ?)";

        $stmt_item = $conn->prepare($sql_item);

        if (!$stmt_item) {
            throw new Exception("Order item prepare failed: " . $conn->error);
        }

        $stmt_item->bind_param(
            "iiddd",
            $order_id,
            $product_id,
            $qty,
            $price,
            $sub_total
        );

        if (!$stmt_item->execute()) {
            throw new Exception("Order item execute failed: " . $stmt_item->error);
        }

        $stmt_item->close();

        /* Product stock কমানো */
        $stmt_stock = $conn->prepare("
            UPDATE products 
            SET stock = stock - ? 
            WHERE id = ? AND shop_id = ?
        ");

        if (!$stmt_stock) {
            throw new Exception("Stock update prepare failed: " . $conn->error);
        }

        $stmt_stock->bind_param("dii", $qty, $product_id, $shop_id);

        if (!$stmt_stock->execute()) {
            throw new Exception("Stock update failed: " . $stmt_stock->error);
        }

        $stmt_stock->close();

        /* Stock movement history */
        $movement_type = 'out';
        $movement_note = 'POS Sale Invoice: ' . $invoice_no;

        $stmt_move = $conn->prepare("
            INSERT INTO stock_movements (product_id, type, qty, note)
            VALUES (?, ?, ?, ?)
        ");

        if ($stmt_move) {
            $stmt_move->bind_param("isds", $product_id, $movement_type, $qty, $movement_note);
            $stmt_move->execute();
            $stmt_move->close();
        }
    }

    /* ================= CUSTOMER DUE UPDATE ================= */
    if ($customer_id > 0) {
        $stmt_due = $conn->prepare("
            UPDATE customers
            SET due_amount = ?
            WHERE id = ? AND shop_id = ?
        ");

        if (!$stmt_due) {
            throw new Exception("Customer due update prepare failed: " . $conn->error);
        }

        $stmt_due->bind_param("dii", $closing_due, $customer_id, $shop_id);

        if (!$stmt_due->execute()) {
            throw new Exception("Customer due update failed: " . $stmt_due->error);
        }

        $stmt_due->close();
    }
	
	/* ================= UPDATE CASH ASSET ================= */
if ($paid_amount > 0 && $payment_method == 'cash') {
    // cash অ্যাসেট আপডেট (বাড়ানো)
    $stmt_cash = $conn->prepare("
        UPDATE assets 
        SET amount = amount + ? 
        WHERE shop_id = ? AND asset_type = 'cash' AND status = 'active' 
        ORDER BY id ASC LIMIT 1
    ");
    
    if (!$stmt_cash) {
        throw new Exception("Cash asset update prepare failed: " . $conn->error);
    }
    
    $stmt_cash->bind_param("di", $paid_amount, $shop_id);
    
    if (!$stmt_cash->execute()) {
        throw new Exception("Cash asset update failed: " . $stmt_cash->error);
    }
    
    $stmt_cash->close();
}

    /* ================= COMMIT ================= */
    $conn->commit();

    /* ================= CLEAR SESSION DATA ================= */
    $_SESSION['cart'] = [];
    unset($_SESSION['discount_amount']);
    unset($_SESSION['paid_amount']);

    /* ================= REDIRECT INVOICE ================= */
    header("Location: invoice.php?id=" . $order_id);
    exit;

} catch (Exception $e) {

    $conn->rollback();

    error_log("Checkout Error: " . $e->getMessage());

    die("Checkout Error: " . $e->getMessage());
}

$conn->close();
?>