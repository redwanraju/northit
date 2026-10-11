<?php
/**
 * RICEVALY - Production Checkout
 *
 * Requirements:
 * - PHP 8.1+
 * - mysqli connection in config/db.php as $conn
 * - Existing tables: site_products, site_categories, customers, customer_addresses, orders,
 *   order_items, order_shipping_addresses, carts, cart_items,
 *   shipping_methods, area_groups, coupons, coupon_usages
 *
 * IMPORTANT:
 * The current homepage cart is browser-side only. This checkout accepts a
 * signed-in/session cart when available, and also accepts a JSON cart payload
 * from the homepage. Server-side prices/stock are ALWAYS revalidated.
 */

declare(strict_types=1);

error_reporting(E_ALL);
ini_set('display_errors', '0');
ini_set('log_errors', '1');

// Frontend customer session is intentionally isolated from POS/Admin.
if (session_status() !== PHP_SESSION_ACTIVE) {
    session_name('RVSITESESSID');
    $checkoutHttps = (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (string)($_SERVER['SERVER_PORT'] ?? '') === '443'
        || strtolower((string)($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '')) === 'https';
    ini_set('session.use_strict_mode', '1');
    ini_set('session.use_only_cookies', '1');
    session_set_cookie_params([
        'lifetime' => 0,
        'path' => '/',
        'domain' => '',
        'secure' => $checkoutHttps,
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    session_start();
}

require_once dirname(__DIR__, 2) . '/config/db.php';

if (!isset($conn) || !($conn instanceof mysqli)) {
    http_response_code(500);
    exit('Database connection unavailable.');
}
$conn->set_charset('utf8mb4');
require_once __DIR__ . '/referral_engine.php';

const RV_SHOP_ID = 1;
const RV_MAX_CART_ITEMS = 100;
const RV_MAX_QTY = 9999;

function e($value): string {
    return htmlspecialchars((string)$value, ENT_QUOTES, 'UTF-8');
}

function money(float $amount): string {
    return '৳' . number_format($amount, 2, '.', ',');
}

/**
 * Convert a website product image value into one consistent browser URL.
 *
 * site_products.site_images may contain a full URL, an absolute path,
 * uploads/..., site_products/..., or just a filename.  The checkout must
 * use the same rule for both the initial PHP render and the JS cart render.
 */
function productImageUrl($value): string {
    $value = trim((string)$value);
    if ($value === '') return '';

    if (preg_match('~^https?://~i', $value)) {
        return $value;
    }

    // Keep an already-root-relative URL untouched.
    if (isset($value[0]) && $value[0] === '/') {
        return $value;
    }

    $value = ltrim($value, '/');

    // Normalize common values stored by upload/admin code.
    if (preg_match('~^(?:uploads/)?site_products/~i', $value)) {
        $value = preg_replace('~^(?:uploads/)?site_products/~i', '', $value);
        return '/uploads/site_products/' . ltrim((string)$value, '/');
    }

    if (preg_match('~^uploads/~i', $value)) {
        return '/' . $value;
    }

    return '/uploads/site_products/' . $value;
}

function jsonResponse(bool $ok, string $message, array $extra = []) {
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra), JSON_UNESCAPED_UNICODE);
    exit;
}

function csrfToken(): string {
    if (empty($_SESSION['rv_checkout_csrf'])) {
        $_SESSION['rv_checkout_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['rv_checkout_csrf'];
}

function verifyCsrf(): void {
    $token = (string)($_POST['csrf_token'] ?? '');
    if (!hash_equals((string)($_SESSION['rv_checkout_csrf'] ?? ''), $token)) {
        jsonResponse(false, 'নিরাপত্তা যাচাই ব্যর্থ হয়েছে। পেজটি রিফ্রেশ করে আবার চেষ্টা করুন।');
    }
}

function stmt(mysqli $db, string $sql, string $types = '', array $params = []): mysqli_stmt {
    $s = $db->prepare($sql);
    if (!$s) {
        throw new RuntimeException('Database prepare failed.');
    }
    if ($types !== '') {
        $refs = [$types];
        foreach ($params as $k => $v) {
            $refs[] = &$params[$k];
        }
        if (!call_user_func_array([$s, 'bind_param'], $refs)) {
            $s->close();
            throw new RuntimeException('Database bind failed.');
        }
    }
    if (!$s->execute()) {
        $s->close();
        throw new RuntimeException('Database execute failed.');
    }
    return $s;
}

/**
 * Fetch rows from a prepared statement without mysqli_stmt::get_result().
 * This works on shared hosting even when mysqli is built without mysqlnd.
 */
function stmtRows(mysqli_stmt $s): array {
    $meta = $s->result_metadata();
    if (!$meta) {
        return [];
    }

    $fields = $meta->fetch_fields();
    $row = [];
    $bind = [];

    foreach ($fields as $field) {
        $name = $field->name;
        $row[$name] = null;
        $bind[] = &$row[$name];
    }

    if ($bind) {
        call_user_func_array([$s, 'bind_result'], $bind);
    }

    $rows = [];
    while ($s->fetch()) {
        $copy = [];
        foreach ($row as $key => $value) {
            $copy[$key] = $value;
        }
        $rows[] = $copy;
    }

    $meta->free();
    return $rows;
}

function stmtRow(mysqli_stmt $s): ?array {
    $rows = stmtRows($s);
    return $rows[0] ?? null;
}

function tableExists(mysqli $db, string $table): bool {
    static $cache = [];
    if (isset($cache[$table])) return $cache[$table];
    $safe = $db->real_escape_string($table);
    $r = $db->query("SHOW TABLES LIKE '{$safe}'");
    return $cache[$table] = (bool)($r && $r->num_rows);
}

function getCartPayload(): array {
    $raw = $_POST['cart_json'] ?? $_GET['cart_json'] ?? '';
    if (is_string($raw) && $raw !== '') {
        $decoded = json_decode($raw, true);
        if (is_array($decoded)) return $decoded;
    }
    if (!empty($_SESSION['rv_checkout_cart']) && is_array($_SESSION['rv_checkout_cart'])) {
        return $_SESSION['rv_checkout_cart'];
    }
    return [];
}

function normaliseCart(array $raw): array {
    $out = [];
    foreach ($raw as $item) {
        if (!is_array($item)) continue;
        $id = filter_var($item['id'] ?? $item['product_id'] ?? null, FILTER_VALIDATE_INT);
        $qty = (float)($item['qty'] ?? $item['quantity'] ?? 0);
        if (!$id || $id < 1 || $qty <= 0) continue;
        $qty = min($qty, RV_MAX_QTY);
        // Preserve fractional quantities because the POS/product schema supports decimals.
        $out[$id] = ($out[$id] ?? 0) + $qty;
        if (count($out) >= RV_MAX_CART_ITEMS) break;
    }
    $result = [];
    foreach ($out as $id => $qty) {
        $result[] = ['id' => (int)$id, 'qty' => round((float)$qty, 3)];
    }
    return $result;
}

function loadProducts(mysqli $db, array $cart): array {
    if (!$cart) return [];
    if (!tableExists($db, 'site_products')) return [];

    $ids = array_values(array_unique(array_map(static fn($x) => (int)$x['id'], $cart)));
    if (!$ids) return [];

    $placeholders = implode(',', array_fill(0, count($ids), '?'));
    $types = str_repeat('i', count($ids));
    $products = [];

    /*
     * Website checkout source of truth: site_products only.
     * Every cart id is a site_products.id. No POS/products table matching,
     * SKU/name fallback, or legacy product-id resolution is allowed here.
     */
    $sql = "SELECT
                sp.id AS site_id,
                sp.product_name,
                sp.slug,
                sp.site_category_id AS category_id,
                sp.brand_id,
                sp.sku,
                sp.barcode,
                sp.unit,
                sp.site_price,
                sp.site_old_price,
                sp.site_stock,
                sp.purchase_price,
                sp.site_status,
                sp.site_visible,
                sp.site_short_description,
                sp.site_description,
                sp.site_images,
                sp.is_featured,
                sp.is_new,
                sp.is_top_selling,
                sp.sort_order,
                c.category_name
            FROM site_products sp
            LEFT JOIN site_categories c ON c.id = sp.site_category_id
            WHERE sp.site_status = 1
              AND sp.site_visible = 1
              AND sp.id IN ($placeholders)
            ORDER BY sp.sort_order, sp.id";

    try {
        $st = stmt($db, $sql, $types, $ids);
        foreach (stmtRows($st) as $row) {
            $siteId = (int)$row['site_id'];
            if ($siteId <= 0) continue;

            $images = trim((string)($row['site_images'] ?? ''));
            $image = '';
            if ($images !== '') {
                $parts = array_values(array_filter(array_map('trim', explode(',', $images))));
                $image = $parts[0] ?? '';
            }

            $products[$siteId] = [
                'id' => $siteId,
                'site_id' => $siteId,
                'product_name' => (string)$row['product_name'],
                'slug' => (string)($row['slug'] ?? ''),
                'sale_price' => (float)$row['site_price'],
                'compare_price' => (float)($row['site_old_price'] ?? 0),
                'stock' => max(0, (float)$row['site_stock']),
                'unit' => (string)($row['unit'] ?? 'PIECE'),
                'image' => $image,
                'image_url' => productImageUrl($image),
                'sku' => (string)($row['sku'] ?? ''),
                'barcode' => (string)($row['barcode'] ?? ''),
                'status' => (int)$row['site_status'],
                'website_visible' => (int)$row['site_visible'],
                'category_id' => (int)($row['category_id'] ?? 0),
                'category_name' => (string)($row['category_name'] ?? ''),
                'short_description' => (string)($row['site_short_description'] ?? ''),
                'description' => (string)($row['site_description'] ?? ''),
                'featured' => (int)$row['is_featured'],
                'is_new' => (int)$row['is_new'],
                'is_top_selling' => (int)$row['is_top_selling'],
                'sort_order' => (int)$row['sort_order'],
                'purchase_price' => (float)($row['purchase_price'] ?? 0),
            ];
        }
        $st->close();
    } catch (Throwable $e) {
        error_log('Ricevaly site_products load: ' . $e->getMessage());
        return [];
    }

    return $products;
}

function loadShippingMethods(mysqli $db): array {
    if (!tableExists($db, 'shipping_methods')) return [];
    $rows = [];
    $s = stmt($db, "SELECT id,name,description,cost,free_above,minimum_order,estimated_days
                    FROM shipping_methods
                    WHERE (shop_id=? OR shop_id IS NULL) AND status=1
                    ORDER BY sort_order,id", 'i', [RV_SHOP_ID]);
    $rows = stmtRows($s);
    $s->close();
    return $rows;
}

function defaultShipping(array $methods): array {
    if ($methods) return $methods[0];
    return [
        'id' => 0,
        'name' => 'Home Delivery',
        'description' => 'ঢাকার মধ্যে হোম ডেলিভারি',
        'cost' => 60,
        'free_above' => null,
        'minimum_order' => 0,
        'estimated_days' => '1–3 দিন'
    ];
}

function calculateShipping(array $method, float $subtotal): float {
    $minimum = (float)($method['minimum_order'] ?? 0);
    if ($subtotal < $minimum) return -1;
    $freeAbove = $method['free_above'] !== null ? (float)$method['free_above'] : null;
    if ($freeAbove !== null && $freeAbove > 0 && $subtotal >= $freeAbove) return 0.0;
    return max(0, (float)$method['cost']);
}

function couponDiscount(mysqli $db, string $code, float $subtotal, ?int $customerId = null): array {
    if ($code === '' || !tableExists($db, 'coupons')) return [null, 0.0, ''];
    $s = stmt($db, "SELECT id,code,description,discount_type,discount_value,minimum_order,maximum_discount,usage_limit,usage_per_customer,used_count,start_at,end_at
                    FROM coupons
                    WHERE shop_id=? AND code=? AND status=1 LIMIT 1", 'is', [RV_SHOP_ID, $code]);
    $coupon = stmtRow($s);
    $s->close();
    if (!$coupon) return [null, 0.0, 'কুপনটি সঠিক নয় বা সক্রিয় নেই।'];
    $now = date('Y-m-d H:i:s');
    if (!empty($coupon['start_at']) && $coupon['start_at'] > $now) return [null, 0.0, 'এই কুপনটি এখনও কার্যকর হয়নি।'];
    if (!empty($coupon['end_at']) && $coupon['end_at'] < $now) return [null, 0.0, 'কুপনের মেয়াদ শেষ হয়েছে।'];
    if ($subtotal < (float)$coupon['minimum_order']) return [null, 0.0, 'এই কুপনের জন্য ন্যূনতম অর্ডার ' . money((float)$coupon['minimum_order']) . '।'];
    if ($coupon['usage_limit'] !== null && (int)$coupon['used_count'] >= (int)$coupon['usage_limit']) return [null, 0.0, 'এই কুপনের ব্যবহার সীমা শেষ হয়েছে।'];
    if ($customerId && tableExists($db, 'coupon_usages')) {
        $s = stmt($db, "SELECT COUNT(*) c FROM coupon_usages WHERE coupon_id=? AND customer_id=?", 'ii', [(int)$coupon['id'], $customerId]);
        $countRow = stmtRow($s);
        $count = (int)($countRow['c'] ?? 0);
        $s->close();
        if ((int)$coupon['usage_per_customer'] > 0 && $count >= (int)$coupon['usage_per_customer']) return [null, 0.0, 'আপনি এই কুপনটি ইতোমধ্যে ব্যবহার করেছেন।'];
    }
    if ($coupon['discount_type'] === 'percentage') {
        $discount = $subtotal * ((float)$coupon['discount_value'] / 100);
        if ($coupon['maximum_discount'] !== null) $discount = min($discount, (float)$coupon['maximum_discount']);
    } else {
        $discount = (float)$coupon['discount_value'];
    }
    $discount = min(max(0, $discount), $subtotal);
    return [$coupon, round($discount, 2), 'কুপন সফলভাবে প্রয়োগ হয়েছে।'];
}

function createOrFindCustomer(mysqli $db, string $name, string $phone, string $address, string $city, string $areaName, string $email): int {
    $s = stmt($db, "SELECT id FROM customers WHERE shop_id=? AND phone=? ORDER BY id DESC LIMIT 1", 'is', [RV_SHOP_ID, $phone]);
    $row = stmtRow($s);
    $s->close();
    if ($row) {
        $id = (int)$row['id'];
        $s = stmt($db, "UPDATE customers SET name=?,address=?,area_group=?,customer_group='home_delivery',email=? WHERE id=? AND shop_id=?", 'ssssii', [$name,$address,$areaName,$email,$id,RV_SHOP_ID]);
        $s->close();
        return $id;
    }
    $s = stmt($db, "INSERT INTO customers (shop_id,shop_name,name,phone,address,area_group,customer_group,user_id,email)
                    VALUES (?,?,?,?,?,?, 'home_delivery',1,?)", 'issssss', [RV_SHOP_ID,'Ricevaly Online Customer',$name,$phone,$address,$areaName,$email]);
    $id = (int)$db->insert_id;
    $s->close();
    return $id;
}

function createOrder(mysqli $db, array $cart, array $products, array $data): array {
    if (!$cart) throw new RuntimeException('কার্ট খালি।');
    if (!$products) throw new RuntimeException('কার্টের কোনো পণ্য পাওয়া যায়নি।');

    $subtotal = 0.0;
    $items = [];

    foreach ($cart as $item) {
        $cartId = (int)$item['id'];
        $qty = (float)$item['qty'];
        if ($qty <= 0) continue;
        if (!isset($products[$cartId])) {
            throw new RuntimeException('কার্টের একটি পণ্য বর্তমানে পাওয়া যাচ্ছে না। পেজ রিফ্রেশ করে আবার চেষ্টা করুন।');
        }

        $p = $products[$cartId];
        $stock = (float)$p['stock'];
        if ($stock <= 0 || $qty > $stock) {
            throw new RuntimeException('“' . $p['product_name'] . '” পণ্যের পর্যাপ্ত স্টক নেই।');
        }

        $price = round((float)$p['sale_price'], 2);
        if ($price < 0) throw new RuntimeException('“' . $p['product_name'] . '” পণ্যের মূল্য সঠিক নয়।');
        $line = round($price * $qty, 2);
        $subtotal += $line;

        $siteProductId = (int)($p['site_id'] ?? $cartId);
        if ($siteProductId <= 0 || $siteProductId !== $cartId) {
            throw new RuntimeException('“' . $p['product_name'] . '” সাইট পণ্যের আইডি সঠিক নয়।');
        }

        $items[] = [
            'cart_id'=>$cartId,
            'site_product_id'=>$siteProductId,
            'site_id'=>$siteProductId,
            'qty'=>$qty,
            'price'=>$price,
            'subtotal'=>$line,
            'cost_price'=>(float)($p['purchase_price'] ?? 0),
            'name'=>(string)$p['product_name']
        ];
    }

    if (!$items) throw new RuntimeException('কার্টে বৈধ কোনো পণ্য নেই।');
    $subtotal = round($subtotal, 2);

    $methods = loadShippingMethods($db);
    $selectedMethod = null;
    $shippingId = (int)($data['shipping_method_id'] ?? 0);
    foreach ($methods as $m) {
        if ((int)$m['id'] === $shippingId) { $selectedMethod = $m; break; }
    }
    if (!$selectedMethod) $selectedMethod = defaultShipping($methods);

    $delivery = calculateShipping($selectedMethod, $subtotal);
    if ($delivery < 0) throw new RuntimeException('এই অর্ডারের জন্য নির্বাচিত ডেলিভারি পদ্ধতির ন্যূনতম অর্ডার পূরণ হয়নি।');

    // Website identity is linked separately from legacy orders.user_id.
    // Guest checkout stays NULL; logged-in active customer accounts use users.id.
    $accountUserId = rvReferralSessionAccountUserId($db, RV_SHOP_ID);

    $db->begin_transaction();
    try {
        $customerId = createOrFindCustomer($db, $data['name'], $data['phone'], $data['address'], $data['city'], $data['area_name'], $data['email']);

        [$coupon, $discount, $couponMessage] = couponDiscount(
            $db,
            strtoupper(trim((string)($data['coupon_code'] ?? ''))),
            $subtotal,
            $customerId
        );

        $total = max(0, round($subtotal + $delivery - $discount, 2));
        $paymentMethod = (string)$data['payment_method'];
        $paid = 0.0;
        $due = $total;
        $paymentStatus = 'pending';
        $invoice = 'RV-' . date('Ymd') . '-' . strtoupper(bin2hex(random_bytes(3)));

        /* Re-check and lock stock from site_products only. */
        foreach ($items as &$it) {
            $s = stmt($db,
                "SELECT id,site_stock,site_price,purchase_price,site_status,site_visible
                 FROM site_products
                 WHERE id=? AND site_status=1 AND site_visible=1
                 FOR UPDATE",
                'i', [$it['site_id']]
            );
            $row = stmtRow($s);
            $s->close();
            if (!$row || (float)$row['site_stock'] < $it['qty']) {
                throw new RuntimeException('“' . $it['name'] . '” এর স্টক পরিবর্তিত হয়েছে। আবার চেষ্টা করুন।');
            }
            $it['price'] = round((float)$row['site_price'], 2);
            $it['subtotal'] = round($it['price'] * $it['qty'], 2);
            $it['cost_price'] = (float)($row['purchase_price'] ?? 0);
        }
        unset($it);

        /* Recalculate totals from the locked server-side prices. */
        $subtotal = 0.0;
        foreach ($items as $it) $subtotal += $it['subtotal'];
        $subtotal = round($subtotal, 2);
        $delivery = calculateShipping($selectedMethod, $subtotal);
        if ($delivery < 0) throw new RuntimeException('এই অর্ডারের জন্য নির্বাচিত ডেলিভারি পদ্ধতির ন্যূনতম অর্ডার পূরণ হয়নি।');

        /* Coupon is recalculated after the final locked subtotal. */
        [$coupon, $discount, $couponMessage] = couponDiscount(
            $db,
            strtoupper(trim((string)($data['coupon_code'] ?? ''))),
            $subtotal,
            $customerId
        );
        $total = max(0, round($subtotal + $delivery - $discount, 2));
        $due = $total;

        $adminNote = null;
        $tax = 0.0;
        $previousDue = null;
        $closingDue = null;
        $couponId = $coupon ? (int)$coupon['id'] : null;
        $shippingMethodId = (int)$selectedMethod['id'];

        $s = stmt($db, "INSERT INTO orders
            (shop_id,customer_id,account_user_id,shipping_address_id,invoice_no,subtotal,delivery_cost,shipping_method_id,group_unit_cost,total_amount,discount,coupon_id,customer_note,admin_note,tax,order_status,payment_status,paid_amount,due_amount,previous_due,closing_due,payment_method,order_source,user_id,sr_id)
            VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?,'pending',?,?,?,?,?,?,'online',NULL,NULL)",
            'iiiisddidddissdsdddds', [
                RV_SHOP_ID,$customerId,$accountUserId,null,$invoice,$subtotal,$delivery,$shippingMethodId,0.0,$total,$discount,
                $couponId,$data['note'],$adminNote,$tax,$paymentStatus,$paid,$due,$previousDue,$closingDue,$paymentMethod
            ]
        );
        $orderId = (int)$db->insert_id;
        $s->close();
        if ($orderId <= 0) throw new RuntimeException('অর্ডার তৈরি করা যায়নি।');

        foreach ($items as $it) {
            $siteProductIdForOrder = (int)$it['site_product_id'];
            $s = stmt($db,
                "INSERT INTO order_items
                    (order_id,product_id,site_product_id,qty,price,subtotal,variant_id,cost_price)
                 VALUES (?,NULL,?,?,?,?,NULL,?)",
                'iidddd', [$orderId,$siteProductIdForOrder,$it['qty'],$it['price'],$it['subtotal'],$it['cost_price']]
            );
            $s->close();

            $s = stmt($db,
                "UPDATE site_products SET site_stock=site_stock-? WHERE id=? AND site_stock>=?",
                'did', [$it['qty'],$it['site_id'],$it['qty']]
            );
            if ($s->affected_rows !== 1) {
                $s->close();
                throw new RuntimeException('“' . $it['name'] . '” এর স্টক আপডেট করা যায়নি।');
            }
            $s->close();
        }

        $areaId = null;
        if (tableExists($db, 'area_groups') && trim((string)$data['area_name']) !== '') {
            $s = stmt($db, "SELECT id FROM area_groups WHERE shop_id=? AND area_name=? LIMIT 1", 'is', [RV_SHOP_ID,$data['area_name']]);
            $areaRow = stmtRow($s);
            $s->close();
            if ($areaRow) $areaId = (int)$areaRow['id'];
        }

        $s = stmt($db,
            "INSERT INTO order_shipping_addresses (order_id,recipient_name,phone,address,area_group_id,city,postal_code) VALUES (?,?,?,?,?,?,?)",
            'isssiss', [$orderId,$data['name'],$data['phone'],$data['address'],$areaId,$data['city'],$data['postal_code']]
        );
        $addressId = (int)$db->insert_id;
        $s->close();
        if ($addressId <= 0) throw new RuntimeException('ডেলিভারি ঠিকানা সংরক্ষণ করা যায়নি।');

        $s = stmt($db, "UPDATE orders SET shipping_address_id=? WHERE id=?", 'ii', [$addressId,$orderId]);
        $s->close();

        if ($coupon) {
            $s = stmt($db, "UPDATE coupons SET used_count=used_count+1 WHERE id=? AND shop_id=? AND status=1", 'ii', [(int)$coupon['id'],RV_SHOP_ID]);
            $s->close();
            if (tableExists($db, 'coupon_usages')) {
                $s = stmt($db,
                    "INSERT INTO coupon_usages (coupon_id,customer_id,order_id,discount_amount) VALUES (?,?,?,?)",
                    'iiid', [(int)$coupon['id'],$customerId,$orderId,$discount]
                );
                $s->close();
            }
        }

        // Idempotent V3 credit sync. A newly placed order is normally pending,
        // so wallet credit usually posts later when admin marks it Completed + Paid.
        $creditResult = rvReferralSyncOrder($db, RV_SHOP_ID, $orderId);

        $db->commit();
        $_SESSION['rv_last_order_id'] = $orderId;
        $_SESSION['rv_last_invoice'] = $invoice;
        $_SESSION['rv_checkout_cart'] = [];

        return [
            'id'=>$orderId,
            'invoice'=>$invoice,
            'total'=>$total,
            'subtotal'=>$subtotal,
            'discount'=>$discount,
            'delivery'=>$delivery,
            'coupon_message'=>$couponMessage,
            'account_user_id'=>$accountUserId,
            'credit_state'=>(string)($creditResult['state'] ?? 'skipped')
        ];
    } catch (Throwable $e) {
        if ($db->errno || true) {
            try { $db->rollback(); } catch (Throwable $ignored) {}
        }
        throw $e;
    }
}

// AJAX: sync the browser cart into the PHP session before opening checkout.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'sync_cart') {
    $synced = normaliseCart(getCartPayload());
    $_SESSION['rv_checkout_cart'] = $synced;
    jsonResponse(true, 'কার্ট সিঙ্ক হয়েছে।', ['count'=>count($synced)]);
}

// AJAX: validate coupon without placing an order.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'coupon') {
    verifyCsrf();
    try {
        $cart = normaliseCart(getCartPayload());
        $products = loadProducts($conn, $cart);
        $subtotal = 0.0;
        foreach ($cart as $it) if (isset($products[$it['id']])) $subtotal += (float)$products[$it['id']]['sale_price'] * $it['qty'];
        [$coupon,$discount,$message] = couponDiscount($conn, strtoupper(trim((string)($_POST['coupon_code'] ?? ''))), round($subtotal,2), null);
        if (!$coupon) jsonResponse(false, $message ?: 'কুপন প্রযোজ্য নয়।');
        jsonResponse(true, $message, ['discount'=>$discount,'subtotal'=>round($subtotal,2)]);
    } catch (Throwable $e) {
        jsonResponse(false, 'কুপন যাচাই করা যায়নি।');
    }
}

// Place order.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'place_order') {
    verifyCsrf();
    try {
        $name = trim((string)($_POST['name'] ?? ''));
        $phone = preg_replace('/\s+/', '', (string)($_POST['phone'] ?? ''));
        $address = trim((string)($_POST['address'] ?? ''));
        $city = trim((string)($_POST['city'] ?? 'Dhaka'));
        $postal = trim((string)($_POST['postal_code'] ?? ''));
        $area = trim((string)($_POST['area_name'] ?? ''));
        $email = trim((string)($_POST['email'] ?? ''));
        $note = trim((string)($_POST['note'] ?? ''));
        $payment = (string)($_POST['payment_method'] ?? 'cod');
        $allowedPayments = ['cod','bkash','nagad'];

        if (mb_strlen($name) < 2 || mb_strlen($name) > 150) throw new RuntimeException('আপনার নাম সঠিকভাবে লিখুন।');
        if (!preg_match('/^(?:\+?8801|01)[3-9]\d{8}$/', $phone)) throw new RuntimeException('সঠিক বাংলাদেশি মোবাইল নম্বর দিন।');
        if (mb_strlen($address) < 8 || mb_strlen($address) > 1000) throw new RuntimeException('পূর্ণ ডেলিভারি ঠিকানা লিখুন।');
        if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) throw new RuntimeException('ইমেইল ঠিকানা সঠিক নয়।');
        if (!in_array($payment, $allowedPayments, true)) throw new RuntimeException('পেমেন্ট পদ্ধতি সঠিক নয়।');

        $cart = normaliseCart(getCartPayload());
        if (!$cart) throw new RuntimeException('আপনার কার্ট খালি। প্রথমে পণ্য যোগ করুন।');
        $_SESSION['rv_checkout_cart'] = $cart;
        $products = loadProducts($conn, $cart);
        $result = createOrder($conn, $cart, $products, [
            'name'=>$name,'phone'=>$phone,'address'=>$address,'city'=>$city,'postal_code'=>$postal,
            'area_name'=>$area,'email'=>$email,'note'=>$note,'payment_method'=>$payment,
            'shipping_method_id'=>(int)($_POST['shipping_method_id'] ?? 0),'coupon_code'=>strtoupper(trim((string)($_POST['coupon_code'] ?? '')))
        ]);
        jsonResponse(true, 'অর্ডার সফলভাবে গ্রহণ করা হয়েছে।', ['order'=>$result]);
    } catch (Throwable $e) {
        error_log('Ricevaly checkout: ' . $e->getMessage());
        jsonResponse(false, $e instanceof RuntimeException ? $e->getMessage() : 'অর্ডার সম্পন্ন করা যায়নি। আবার চেষ্টা করুন।');
    }
}

// Load initial cart from session. The homepage can set this session using the small JS bridge below.
$cart = normaliseCart(getCartPayload());
$products = loadProducts($conn, $cart);
$shippingMethods = loadShippingMethods($conn);
if (!$shippingMethods) $shippingMethods = [defaultShipping([])];
$defaultMethod = $shippingMethods[0];

$areas = [];
if (tableExists($conn, 'area_groups')) {
    $s = stmt($conn, "SELECT id,area_name FROM area_groups WHERE shop_id=? ORDER BY area_name", 'i', [RV_SHOP_ID]);
    $areas = stmtRows($s);
    $s->close();
}

$items = [];
$subtotal = 0.0;
foreach ($cart as $it) {
    if (!isset($products[$it['id']])) continue;
    $p = $products[$it['id']];
    $qty = min((float)$it['qty'], (float)$p['stock']);
    if ($qty <= 0) continue;
    $price = (float)$p['sale_price'];
    $line = round($price * $qty, 2);
    $subtotal += $line;
    $items[] = ['id'=>(int)$p['id'],'name'=>$p['product_name'],'price'=>$price,'compare_price'=>(float)$p['compare_price'],'qty'=>$qty,'stock'=>(float)$p['stock'],'unit'=>$p['unit'],'image'=>$p['image'],'image_url'=>productImageUrl($p['image']),'category'=>$p['category_name']];
}
$subtotal = round($subtotal,2);
$initialShipping = calculateShipping($defaultMethod, $subtotal);
if ($initialShipping < 0) $initialShipping = 0;
$initialTotal = round($subtotal + $initialShipping,2);
$csrf = csrfToken();

?><!doctype html>
<html lang="bn">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width,initial-scale=1,viewport-fit=cover">
<title>Checkout — Ricevaly</title>
<meta name="description" content="Ricevaly secure checkout">
<meta name="robots" content="noindex,nofollow">
<link rel="preconnect" href="https://fonts.googleapis.com">
<link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
<link href="https://fonts.googleapis.com/css2?family=Hind+Siliguri:wght@400;500;600;700&family=Open+Sans:wght@400;500;600;700&display=swap" rel="stylesheet">
<style>
:root{--orange:#f48721;--green:#042524;--green2:#0b4b43;--ink:#17201f;--muted:#687572;--line:#e4e9e7;--bg:#f6f8f7;--white:#fff;--danger:#c43b3b;--success:#16804a;--shadow:0 10px 35px rgba(15,40,35,.08)}
*{box-sizing:border-box}html{scroll-behavior:smooth}body{margin:0;background:var(--bg);color:var(--ink);font-family:"Hind Siliguri","Open Sans",sans-serif;font-size:16px;line-height:1.55}button,input,select,textarea{font:inherit}button{cursor:pointer}.container{width:min(1180px,calc(100% - 32px));margin:auto}
.header{background:#fff;border-bottom:1px solid var(--line)}.header-inner{height:78px;display:flex;align-items:center;justify-content:space-between;gap:20px}.logo{display:flex;align-items:center;gap:10px;color:var(--orange);text-decoration:none}.logo-mark{width:44px;height:44px;border:4px solid var(--orange);border-radius:13px 13px 19px 19px;display:grid;place-items:center;font-weight:700;font-size:24px}.logo b{display:block;font-size:20px;line-height:.9}.logo small{display:block;color:#60706b;font-size:9px;letter-spacing:1.7px;margin-top:5px}.secure{display:flex;align-items:center;gap:8px;color:var(--muted);font-size:14px}.secure-icon{width:32px;height:32px;border:1px solid #dce4e1;border-radius:50%;display:grid;place-items:center}
.page{padding:28px 0 60px}.crumb{font-size:13px;color:var(--muted);margin-bottom:8px}.title-row{display:flex;align-items:end;justify-content:space-between;gap:20px;margin-bottom:22px}.title-row h1{margin:0;font-size:30px;line-height:1.15}.back{color:var(--green2);text-decoration:none;font-size:14px;font-weight:600}.layout{display:grid;grid-template-columns:minmax(0,1fr) 390px;gap:24px;align-items:start}.card{background:#fff;border:1px solid var(--line);border-radius:14px;box-shadow:var(--shadow)}.card+.card{margin-top:18px}.card-head{padding:19px 22px;border-bottom:1px solid var(--line);display:flex;align-items:center;gap:12px}.step{width:30px;height:30px;border-radius:50%;background:#edf5f2;color:var(--green2);display:grid;place-items:center;font-weight:700}.card-head h2{font-size:19px;margin:0}.card-body{padding:22px}.grid2{display:grid;grid-template-columns:1fr 1fr;gap:15px}.field{margin-bottom:15px}.field:last-child{margin-bottom:0}.field label{display:block;font-size:14px;font-weight:600;margin-bottom:7px}.field label span{color:var(--danger)}input,select,textarea{width:100%;border:1px solid #d8e0dd;border-radius:8px;background:#fff;padding:11px 12px;outline:none;color:var(--ink);transition:border .15s,box-shadow .15s}input:focus,select:focus,textarea:focus{border-color:var(--orange);box-shadow:0 0 0 3px rgba(244,135,33,.1)}textarea{min-height:92px;resize:vertical}.hint{font-size:12px;color:var(--muted);margin-top:5px}
.shipping-list{display:grid;gap:10px}.ship-option{position:relative;display:flex;gap:12px;align-items:flex-start;border:1px solid #dce4e1;border-radius:10px;padding:13px;cursor:pointer}.ship-option.active{border-color:var(--orange);background:#fffaf5}.ship-option input{width:auto;margin-top:4px;accent-color:var(--orange)}.ship-main{flex:1}.ship-main b{display:block;font-size:15px}.ship-main span{display:block;color:var(--muted);font-size:12px}.ship-price{font-weight:700;color:var(--green2);white-space:nowrap}
.payment-list{display:grid;grid-template-columns:repeat(3,1fr);gap:10px}.pay{border:1px solid #dce4e1;border-radius:10px;padding:13px;cursor:pointer;background:#fff}.pay.active{border-color:var(--orange);background:#fffaf5}.pay input{position:absolute;opacity:0;pointer-events:none}.pay b{display:block;font-size:15px}.pay span{font-size:12px;color:var(--muted)}.manual-box{margin-top:12px;background:#f7faf8;border:1px solid #e0e8e4;border-radius:9px;padding:12px;font-size:13px;color:#52605c}.manual-box strong{color:var(--green2)}
.summary{position:sticky;top:18px}.summary-head{padding:19px 20px;border-bottom:1px solid var(--line)}.summary-head h2{font-size:19px;margin:0}.summary-body{padding:15px 20px}.item{display:flex;gap:11px;padding:11px 0;border-bottom:1px solid #edf0ef}.item:last-child{border-bottom:0}.thumb{width:62px;height:62px;flex:0 0 62px;border-radius:9px;background:#f4f7f5;border:1px solid #e4e9e7;display:grid;place-items:center;overflow:hidden;font-size:28px}.thumb img{width:100%;height:100%;object-fit:cover}.item-main{min-width:0;flex:1}.item-main b{display:block;font-size:14px;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}.item-main span{display:block;color:var(--muted);font-size:12px}.item-total{font-size:14px;font-weight:700;white-space:nowrap}.qty{display:inline-flex;align-items:center;border:1px solid #dce3e0;border-radius:6px;overflow:hidden;margin-top:5px}.qty button{width:25px;height:25px;border:0;background:#fff}.qty span{min-width:27px;text-align:center;font-size:12px}.coupon{display:flex;gap:7px;margin:15px 0}.coupon input{min-width:0}.coupon button{border:1px solid var(--green2);background:#fff;color:var(--green2);border-radius:8px;padding:0 14px;font-weight:700}.coupon-msg{font-size:12px;margin:-8px 0 12px}.totals{border-top:1px solid var(--line);padding-top:13px}.tr{display:flex;justify-content:space-between;gap:15px;margin:9px 0;color:#56635f;font-size:14px}.tr strong{color:var(--ink)}.grand{font-size:19px;font-weight:700;color:var(--ink);padding-top:12px;border-top:1px dashed #d9e0dd}.grand b:last-child{color:var(--orange)}.order-btn{width:100%;border:0;border-radius:9px;background:var(--orange);color:#fff;padding:14px;font-weight:700;font-size:17px;margin-top:16px;box-shadow:0 7px 18px rgba(244,135,33,.22)}.order-btn:disabled{opacity:.55;cursor:not-allowed;box-shadow:none}.terms{font-size:11px;color:var(--muted);text-align:center;margin:10px 5px 0}.trust{display:grid;grid-template-columns:1fr 1fr;gap:8px;margin-top:13px}.trust div{border:1px solid var(--line);border-radius:8px;padding:9px;text-align:center;font-size:12px;color:#56635f;background:#fbfcfc}.empty{padding:30px;text-align:center;color:var(--muted)}.empty a{display:inline-block;margin-top:10px;color:var(--green2);font-weight:700}.alert{display:none;border-radius:9px;padding:11px 13px;font-size:14px;margin-bottom:15px}.alert.show{display:block}.alert.error{background:#fff1f1;border:1px solid #f1cccc;color:#9e2f2f}.alert.success{background:#effaf4;border:1px solid #cbe9d7;color:#176a41}.invalid{border-color:#d75a5a!important;background:#fffafa}.success-page{text-align:center;padding:38px 25px}.success-icon{width:70px;height:70px;border-radius:50%;background:#e9f8ef;color:var(--success);display:grid;place-items:center;font-size:36px;margin:0 auto 15px}.success-page h2{margin:0 0 8px;font-size:26px}.invoice{font-size:20px;font-weight:700;color:var(--green2);margin:10px 0}.success-actions{display:flex;justify-content:center;gap:10px;margin-top:18px}.success-actions a{display:inline-block;padding:10px 16px;border-radius:8px;text-decoration:none;font-weight:700}.home-btn{background:var(--green);color:#fff}.print-btn{border:1px solid var(--green2);color:var(--green2)}
.footer{padding:28px 0;text-align:center;color:#77827f;font-size:12px}.mobile-submit{display:none}
@media(max-width:900px){.layout{grid-template-columns:1fr}.summary{position:static}.mobile-submit{display:block}.desktop-submit{display:none}}
@media(max-width:600px){.container{width:min(100% - 20px,1180px)}.header-inner{height:68px}.page{padding:18px 0 35px}.title-row{align-items:flex-start}.title-row h1{font-size:25px}.layout{gap:14px}.card-head{padding:15px}.card-body{padding:15px}.grid2{grid-template-columns:1fr;gap:0}.payment-list{grid-template-columns:1fr}.summary-body{padding:12px 14px}.summary-head{padding:15px 14px}.trust{grid-template-columns:1fr 1fr}.secure span{display:none}}
</style>
<meta name="theme-color" content="#0a5b4f">
<link rel="stylesheet" href="mobile_checkout_app.css?v=20260910-app1">
<style id="gold-curve-ui-override">
:root{
  --gold-1:#fff1ae;
  --gold-2:#ffd45c;
  --gold-3:#f6b400;
  --gold-4:#d18b00;
  --gold-5:#8f5d00;
  --glass:#ffffffb8;
  --glass-strong:#ffffffe3;
}
html,body{min-height:100%}
body{
  position:relative;
  background:
    radial-gradient(circle at top left, rgba(255, 213, 92, .30), transparent 28%),
    radial-gradient(circle at top right, rgba(22, 128, 74, .12), transparent 24%),
    linear-gradient(180deg,#f8faf8 0%,#f4f7f5 46%,#eff4f2 100%);
}
body::before,body::after{
  content:"";
  position:fixed;
  z-index:0;
  pointer-events:none;
  border-radius:50%;
  filter:blur(0px);
}
body::before{
  width:460px;height:460px;
  left:-140px;top:88px;
  background:radial-gradient(circle at 35% 35%, rgba(255,255,255,.55), rgba(255,215,97,.18) 42%, rgba(255,215,97,0) 68%);
  transform:rotate(-12deg);
}
body::after{
  width:520px;height:520px;
  right:-180px;bottom:-90px;
  background:radial-gradient(circle at 50% 50%, rgba(255,255,255,.42), rgba(244,135,33,.12) 40%, rgba(244,135,33,0) 70%);
}
header,main,.footer{position:relative;z-index:1}
.header{
  background:linear-gradient(180deg, rgba(255,255,255,.86), rgba(255,255,255,.72));
  backdrop-filter:blur(16px);
  border-bottom:1px solid rgba(211,181,91,.28);
  box-shadow:0 14px 40px rgba(15,40,35,.05);
}
.secure-icon{
  border:1px solid rgba(209,139,0,.28);
  background:linear-gradient(180deg, rgba(255,250,234,.95), rgba(255,236,174,.72));
  box-shadow:inset 0 1px 0 rgba(255,255,255,.7);
}
.crumb{
  display:inline-flex;align-items:center;gap:6px;
  padding:9px 15px;
  border:1px solid rgba(214,223,219,.85);
  border-radius:999px;
  background:rgba(255,255,255,.64);
  backdrop-filter:blur(10px);
  box-shadow:0 10px 24px rgba(15,40,35,.04);
}
.card{
  background:linear-gradient(180deg, var(--glass-strong), var(--glass));
  border:1px solid rgba(222,230,227,.9);
  box-shadow:0 18px 44px rgba(15,40,35,.08);
  backdrop-filter:blur(14px);
  overflow:hidden;
}
.card::before{
  content:"";
  position:absolute;
  inset:auto -25% 100% auto;
}
.card-head,.summary-head{
  background:linear-gradient(180deg, rgba(255,255,255,.76), rgba(248,250,248,.55));
}
.step{
  background:linear-gradient(180deg,#fff5cd,#ffd869);
  color:#7b5200;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.85), 0 4px 10px rgba(255,196,0,.16);
}
.ship-option,.pay,.manual-box,.trust div,.thumb,.qty{
  background:rgba(255,255,255,.72);
  backdrop-filter:blur(8px);
}
.ship-option.active,.pay.active{
  border-color:#d9a116;
  background:linear-gradient(180deg, rgba(255,250,235,.98), rgba(255,244,201,.72));
  box-shadow:0 10px 24px rgba(240,179,0,.12);
}
.ship-option.active .ship-price,.pay.active b{color:#7b5200}
input:focus,select:focus,textarea:focus{
  border-color:#d89b0a !important;
  box-shadow:0 0 0 4px rgba(244,190,49,.16) !important;
}
.qty{
  border-color:rgba(209,139,0,.35);
  border-radius:999px;
  overflow:hidden;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.75);
}
.qty span{min-width:30px;font-weight:700;color:#6f5200}
.order-btn,.coupon button,.qty button,.success-actions a{
  position:relative;
  border:1px solid var(--gold-5) !important;
  color:#533500 !important;
  font-weight:800;
  background:linear-gradient(180deg, var(--gold-1) 0%, var(--gold-2) 36%, var(--gold-3) 70%, #e0a100 100%) !important;
  box-shadow:
    inset 0 2px 0 rgba(255,255,255,.85),
    inset 0 -2px 0 rgba(143,93,0,.18),
    0 8px 18px rgba(241,181,9,.22);
  text-shadow:0 1px 0 rgba(255,255,255,.45);
  transition:transform .18s ease, box-shadow .18s ease, filter .18s ease;
}
.order-btn:hover,.coupon button:hover,.qty button:hover,.success-actions a:hover{
  transform:translateY(-1px);
  box-shadow:
    inset 0 2px 0 rgba(255,255,255,.9),
    inset 0 -2px 0 rgba(143,93,0,.2),
    0 12px 22px rgba(241,181,9,.28);
  filter:saturate(1.03);
}
.order-btn:active,.coupon button:active,.qty button:active,.success-actions a:active{
  transform:translateY(0);
  box-shadow:
    inset 0 2px 0 rgba(255,255,255,.78),
    inset 0 -1px 0 rgba(143,93,0,.18),
    0 6px 12px rgba(241,181,9,.18);
}
.order-btn{
  border-radius:12px;
  padding:15px 16px;
  letter-spacing:.1px;
}
.order-btn:disabled{
  opacity:.58;
  filter:grayscale(.12);
  box-shadow:none;
}
.coupon button{
  border-radius:10px;
  min-width:92px;
}
.qty button{
  width:30px;height:30px;
  font-size:17px;
  font-weight:800;
  padding:0;
}
.qty button + span,.qty span + button{border-left:1px solid rgba(143,93,0,.18)}
.home-btn,.print-btn{
  min-width:136px;
  text-align:center;
  border-radius:12px !important;
}
.print-btn{color:#533500 !important}
.back{
  display:inline-flex;align-items:center;gap:8px;
  padding:10px 16px;
  border-radius:999px;
  border:1px solid rgba(209,139,0,.28);
  background:rgba(255,255,255,.62);
  backdrop-filter:blur(10px);
  box-shadow:0 8px 18px rgba(15,40,35,.04);
}
.back:hover{
  border-color:rgba(209,139,0,.5);
  color:#7b5200;
}
.trust div,.manual-box{
  border-color:rgba(219,227,224,.95);
}
.success-icon{
  background:linear-gradient(180deg,#fff6d8,#ffe183);
  color:#7d5500;
  box-shadow:inset 0 2px 0 rgba(255,255,255,.82), 0 12px 24px rgba(241,181,9,.18);
}
@media(max-width:600px){
  body::before{width:320px;height:320px;left:-120px;top:110px}
  body::after{width:360px;height:360px;right:-150px;bottom:-70px}
  .crumb{display:flex;margin-bottom:10px}
  .coupon button{min-width:82px;padding:0 12px}
}
</style>

<style id="ricevaly-glass-checkout-v2">
:root{
  --rv-glass-bg:rgba(10,35,29,.42);
  --rv-glass-bg-soft:rgba(255,255,255,.10);
  --rv-glass-line:rgba(255,255,255,.28);
  --rv-glass-line-strong:rgba(255,225,132,.55);
  --rv-glass-text:#ffffff;
  --rv-glass-muted:rgba(255,255,255,.72);
  --rv-gold-a:#fff3b0;
  --rv-gold-b:#ffd45f;
  --rv-gold-c:#efb51a;
  --rv-gold-d:#b87700;
}

/* Transparent scenic-style glass background */
html,body{min-height:100%}
body{
  position:relative;
  color:var(--rv-glass-text);
  background:
    radial-gradient(circle at 84% 8%, rgba(255,210,91,.34), transparent 24%),
    radial-gradient(circle at 15% 88%, rgba(34,128,79,.34), transparent 30%),
    radial-gradient(circle at 42% 20%, rgba(255,255,255,.10), transparent 22%),
    linear-gradient(135deg,#0a2a22 0%,#174d3e 38%,#62723c 68%,#b18832 100%);
  background-attachment:fixed;
  overflow-x:hidden;
}
body::before,
body::after{
  content:"";
  position:fixed;
  pointer-events:none;
  z-index:0;
  border-radius:50%;
  filter:blur(10px);
}
body::before{
  width:440px;height:440px;
  left:-180px;top:90px;
  background:radial-gradient(circle,rgba(255,220,105,.22),rgba(255,220,105,0) 70%);
}
body::after{
  width:520px;height:520px;
  right:-220px;bottom:-120px;
  background:radial-gradient(circle,rgba(28,117,74,.30),rgba(28,117,74,0) 70%);
}
.header,.page,.footer{position:relative;z-index:1}

/* Minimal glass header */
.header{
  background:rgba(5,27,22,.22)!important;
  border-bottom:1px solid rgba(255,255,255,.15)!important;
  box-shadow:0 10px 30px rgba(0,0,0,.10)!important;
  backdrop-filter:blur(18px) saturate(135%);
  -webkit-backdrop-filter:blur(18px) saturate(135%);
}
.logo{color:#fff!important}
.logo small,.secure{color:rgba(255,255,255,.72)!important}
.logo-mark{
  color:#6b4900!important;
  border-color:#ffe483!important;
  background:linear-gradient(180deg,#fff2b0,#eeb41a)!important;
  box-shadow:inset 0 2px 0 rgba(255,255,255,.75),0 8px 20px rgba(0,0,0,.15);
}
.secure-icon{
  background:rgba(255,255,255,.10)!important;
  border-color:rgba(255,255,255,.22)!important;
  color:#ffe78d;
}

/* Page heading */
.crumb,
.title-row h1,
.back{color:#fff!important}
.crumb{
  background:rgba(255,255,255,.08)!important;
  border:1px solid rgba(255,255,255,.16)!important;
  box-shadow:none!important;
  backdrop-filter:blur(12px);
}
.back{
  background:rgba(255,255,255,.08)!important;
  border-color:rgba(255,255,255,.20)!important;
}
.back:hover{background:rgba(255,255,255,.14)!important}

/* Core glass cards */
.card{
  position:relative;
  background:
    linear-gradient(145deg,rgba(255,255,255,.16),rgba(255,255,255,.07))!important;
  border:1px solid var(--rv-glass-line)!important;
  border-radius:22px!important;
  box-shadow:
    0 28px 70px rgba(0,0,0,.22),
    inset 0 1px 0 rgba(255,255,255,.30)!important;
  backdrop-filter:blur(24px) saturate(145%);
  -webkit-backdrop-filter:blur(24px) saturate(145%);
  overflow:hidden;
}
.card::after{
  content:"";
  position:absolute;
  inset:0;
  pointer-events:none;
  border-radius:inherit;
  background:linear-gradient(135deg,rgba(255,255,255,.14),transparent 30%,transparent 68%,rgba(255,215,98,.08));
}
.card-head,
.summary-head{
  position:relative;
  z-index:1;
  background:rgba(255,255,255,.055)!important;
  border-bottom:1px solid rgba(255,255,255,.14)!important;
}
.card-body,
.summary-body{position:relative;z-index:1}
.card-head h2,
.summary-head h2,
.field label,
.item-main b,
.item-total,
.tr strong,
.grand,
.invoice{color:#fff!important}

.step{
  color:#614000!important;
  background:linear-gradient(180deg,#fff4b9,#f1bd31)!important;
  box-shadow:inset 0 2px 0 rgba(255,255,255,.78),0 8px 18px rgba(0,0,0,.15)!important;
}

/* Inputs */
input,
select,
textarea{
  color:#fff!important;
  background:rgba(4,30,24,.18)!important;
  border:1px solid rgba(255,255,255,.28)!important;
  border-radius:13px!important;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.07);
}
input::placeholder,
textarea::placeholder{color:rgba(255,255,255,.54)!important}
select option{color:#17201f;background:#fff}
input:focus,
select:focus,
textarea:focus{
  border-color:#ffe083!important;
  box-shadow:0 0 0 4px rgba(255,210,80,.16)!important;
  background:rgba(8,38,30,.28)!important;
}
.hint,.terms,.item-main span,.pay span,.ship-main span,.tr,.manual-box,.trust div{
  color:var(--rv-glass-muted)!important;
}

/* Shipping / payment cards */
.ship-option,
.pay,
.manual-box,
.trust div,
.thumb,
.qty{
  background:rgba(255,255,255,.08)!important;
  border-color:rgba(255,255,255,.18)!important;
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
}
.ship-option,
.pay{
  border-radius:15px!important;
  transition:transform .18s ease,border-color .18s ease,background .18s ease,box-shadow .18s ease;
}
.ship-option:hover,
.pay:hover{
  transform:translateY(-1px);
  background:rgba(255,255,255,.12)!important;
  border-color:rgba(255,227,139,.38)!important;
}
.ship-option.active,
.pay.active{
  background:linear-gradient(145deg,rgba(255,226,126,.19),rgba(255,255,255,.10))!important;
  border-color:var(--rv-glass-line-strong)!important;
  box-shadow:0 12px 28px rgba(0,0,0,.12),inset 0 1px 0 rgba(255,255,255,.25)!important;
}
.ship-main b,.pay b{color:#fff!important}
.ship-price,.grand b:last-child{color:#ffe17c!important}

/* Items */
.item{border-bottom-color:rgba(255,255,255,.12)!important}
.thumb{border-radius:13px!important}
.thumb img{object-fit:cover}
.qty{
  border-radius:999px!important;
  overflow:hidden;
}
.qty span{
  color:#fff!important;
  font-weight:700;
}
.qty button{
  color:#553700!important;
  background:linear-gradient(180deg,#fff1aa,#edb512)!important;
  border:0!important;
  box-shadow:inset 0 1px 0 rgba(255,255,255,.75)!important;
}

/* Gold CTA buttons */
.order-btn,
.coupon button,
.success-actions a{
  color:#513500!important;
  border:1px solid #8b5a00!important;
  background:
    linear-gradient(180deg,var(--rv-gold-a) 0%,var(--rv-gold-b) 32%,var(--rv-gold-c) 72%,var(--rv-gold-d) 100%)!important;
  border-radius:13px!important;
  box-shadow:
    inset 0 2px 0 rgba(255,255,255,.86),
    inset 0 -2px 0 rgba(104,64,0,.18),
    0 10px 26px rgba(0,0,0,.16)!important;
  text-shadow:0 1px 0 rgba(255,255,255,.45);
  transition:transform .18s ease,box-shadow .18s ease,filter .18s ease;
}
.order-btn:hover,
.coupon button:hover,
.success-actions a:hover{
  transform:translateY(-1px);
  filter:saturate(1.06);
  box-shadow:
    inset 0 2px 0 rgba(255,255,255,.9),
    inset 0 -2px 0 rgba(104,64,0,.20),
    0 14px 30px rgba(0,0,0,.20)!important;
}
.order-btn:disabled{
  opacity:.52;
  filter:grayscale(.25);
  box-shadow:none!important;
}

/* Coupon */
.coupon input{
  background:rgba(4,30,24,.20)!important;
}
.coupon button{min-width:92px}

/* Totals */
.totals{border-top-color:rgba(255,255,255,.15)!important}
.grand{border-top-color:rgba(255,225,120,.34)!important}

/* Alerts */
.alert{
  backdrop-filter:blur(12px);
  -webkit-backdrop-filter:blur(12px);
}
.alert.error{
  background:rgba(126,23,23,.38)!important;
  border-color:rgba(255,175,175,.35)!important;
  color:#ffe6e6!important;
}
.alert.success{
  background:rgba(20,104,65,.38)!important;
  border-color:rgba(183,255,215,.30)!important;
  color:#eafff2!important;
}
.invalid{
  border-color:#ff9b9b!important;
  background:rgba(130,30,30,.20)!important;
}

/* Success state */
.success-icon{
  color:#5f3e00!important;
  background:linear-gradient(180deg,#fff5bd,#eeb61d)!important;
  box-shadow:inset 0 2px 0 rgba(255,255,255,.82),0 14px 28px rgba(0,0,0,.16)!important;
}
.success-page p{color:rgba(255,255,255,.78)!important}

/* Footer */
.footer{color:rgba(255,255,255,.60)!important}

/* Keep page elegant on mobile */
@media(max-width:900px){
  .layout{gap:16px}
  .summary{position:static}
}
@media(max-width:600px){
  .page{padding-top:16px}
  .card{border-radius:18px!important}
  .card-head,.summary-head{padding:15px!important}
  .card-body,.summary-body{padding:15px!important}
  .title-row{margin-bottom:15px}
  .title-row h1{font-size:24px!important}
  .crumb{border-radius:13px!important}
  .payment-list{gap:9px}
  .ship-option,.pay{padding:12px!important}
}
</style>

</head>
<body>
<header class="header">
  <div class="container header-inner">
    <a class="logo" href="/">
      <span class="logo-mark">R</span><span><b>Ricevaly</b><small>PURE • FRESH • DAILY</small></span>
    </a>
    <div class="secure"><span class="secure-icon">🔒</span><span>নিরাপদ Checkout</span></div>
  </div>
</header>

<main class="page container">
  <div class="crumb">হোম › কার্ট › Checkout</div>
  <div class="title-row"><h1>অর্ডার সম্পন্ন করুন</h1><a class="back" href="/">← শপিং চালিয়ে যান</a></div>
  <div id="alert" class="alert"></div>

  <div id="checkoutLayout" class="layout">
    <section>
      <form id="orderForm" novalidate>
        <input type="hidden" name="csrf_token" value="<?=e($csrf)?>">
        <input type="hidden" name="action" value="place_order">
        <input type="hidden" name="cart_json" id="cartJson">
        <input type="hidden" name="coupon_code" id="couponCodeHidden">

        <div class="card">
          <div class="card-head"><span class="step">1</span><h2>ডেলিভারি তথ্য</h2></div>
          <div class="card-body">
            <div class="grid2">
              <div class="field"><label>নাম <span>*</span></label><input name="name" id="name" autocomplete="name" maxlength="150" required placeholder="আপনার পূর্ণ নাম"></div>
              <div class="field"><label>মোবাইল নম্বর <span>*</span></label><input name="phone" id="phone" inputmode="tel" autocomplete="tel" maxlength="15" required placeholder="01XXXXXXXXX"><div class="hint">ডেলিভারির প্রয়োজনে এই নম্বরে যোগাযোগ করা হবে।</div></div>
            </div>
            <div class="grid2">

              
            </div>
            <div class="grid2">

      
            </div>
            <div class="field"><label>সম্পূর্ণ ঠিকানা <span>*</span></label><textarea name="address" id="address" required maxlength="1000" placeholder="বাড়ি/ফ্ল্যাট, রোড, ব্লক/সেক্টর, ল্যান্ডমার্ক ইত্যাদি লিখুন"></textarea></div>
           
          </div>
        </div>

        <div class="card">
          <div class="card-head"><span class="step">2</span><h2>ডেলিভারি পদ্ধতি</h2></div>
          <div class="card-body">
            <div class="shipping-list" id="shippingList">
              <?php foreach($shippingMethods as $i=>$m): ?>
              <label class="ship-option <?= $i===0?'active':'' ?>">
                <input type="radio" name="shipping_method_id" value="<?=e($m['id'])?>" data-cost="<?=e($m['cost'])?>" data-free="<?=e($m['free_above'] ?? '')?>" data-min="<?=e($m['minimum_order'])?>" <?=$i===0?'checked':''?>>
                <span class="ship-main"><b><?=e($m['name'])?></b><span><?=e($m['description'] ?: 'নিরাপদ হোম ডেলিভারি')?><?= $m['estimated_days'] ? ' • '.e($m['estimated_days']) : '' ?></span></span>
                <span class="ship-price" data-ship-price>৳<?=number_format((float)$m['cost'],2)?></span>
              </label>
              <?php endforeach; ?>
            </div>
          </div>
        </div>

        <div class="card">
          <div class="card-head"><span class="step">3</span><h2>পেমেন্ট পদ্ধতি</h2></div>
          <div class="card-body">
            <div class="payment-list">
              <label class="pay active"><input type="radio" name="payment_method" value="cod" checked><b>💵 Cash on Delivery</b><span>পণ্য হাতে পেয়ে পেমেন্ট</span></label>
              <label class="pay"><input type="radio" name="payment_method" value="bkash"><b>📱 bKash</b><span>ম্যানুয়াল পেমেন্ট</span></label>
              <label class="pay"><input type="radio" name="payment_method" value="nagad"><b>📱 Nagad</b><span>ম্যানুয়াল পেমেন্ট</span></label>
            </div>
            <div id="manualPayment" class="manual-box" style="display:none"><strong>নোট:</strong> bKash/Nagad নির্বাচন করলে অর্ডারটি payment verification-এর জন্য pending থাকবে। আপনার পেমেন্টের নির্দেশনা অর্ডার নিশ্চিত হওয়ার পর দেখানো যাবে।</div>
          </div>
        </div>

        <div class="mobile-submit card">
          <div class="card-body"><button type="submit" class="order-btn" id="mobileOrderBtn">অর্ডার কনফার্ম করুন</button><div class="terms">অর্ডার করার মাধ্যমে আপনি Ricevaly-এর শর্তাবলি মেনে নিচ্ছেন।</div></div>
        </div>
      </form>
    </section>

    <aside class="card summary">
      <div class="summary-head"><h2>আপনার অর্ডার</h2></div>
      <div class="summary-body">
        <div id="itemsBox">
        <?php if (!$items): ?>
          <div class="empty">আপনার কার্ট খালি।<br><a href="/">শপিং শুরু করুন →</a></div>
        <?php else: foreach($items as $it): ?>
          <div class="item">
            <div class="thumb"><?php if(trim((string)($it['image_url'] ?? ''))!==''): ?><img src="<?=e($it['image_url'])?>" alt="<?=e($it['name'])?>" loading="lazy" decoding="async" onerror="this.onerror=null;this.style.display='none';this.parentElement.classList.add('img-error')"><?php else: ?>🛒<?php endif; ?></div>
            <div class="item-main"><b><?=e($it['name'])?></b><span><?=money($it['price'])?> / <?=e($it['unit'])?></span><div class="qty"><button type="button" data-local-qty="<?=$it['id']?>" data-delta="-1">−</button><span><?=e($it['qty'])?></span><button type="button" data-local-qty="<?=$it['id']?>" data-delta="1">+</button></div></div>
            <div class="item-total"><?=money($it['price']*$it['qty'])?></div>
          </div>
        <?php endforeach; endif; ?>
        </div>

        <div class="coupon"><input id="couponInput" maxlength="100" placeholder="কুপন কোড"><button type="button" id="couponBtn">প্রয়োগ</button></div>
        <div id="couponMsg" class="coupon-msg"></div>

        <div class="totals">
          <div class="tr"><span>পণ্যের মূল্য</span><strong id="subtotal">৳<?=number_format($subtotal,2)?></strong></div>
          <div class="tr"><span>ডেলিভারি</span><strong id="delivery">৳<?=number_format($initialShipping,2)?></strong></div>
          <div class="tr" id="discountRow" style="display:none"><span>ডিসকাউন্ট</span><strong id="discount">-৳0.00</strong></div>
          <div class="tr grand"><b>সর্বমোট</b><b id="grandTotal">৳<?=number_format($initialTotal,2)?></b></div>
        </div>
        <button type="submit" form="orderForm" class="order-btn desktop-submit" id="orderBtn" <?=!$items?'disabled':''?>>অর্ডার কনফার্ম করুন</button>
        <div class="terms">🔒 আপনার তথ্য নিরাপদে সংরক্ষণ করা হবে।</div>
        <div class="trust"><div>✓ কোয়ালিটি নিশ্চয়তা</div><div>🚚 দ্রুত ডেলিভারি</div><div>↺ সহজ রিটার্ন</div><div>💳 নিরাপদ পেমেন্ট</div></div>
      </div>
    </aside>
  </div>
</main>
<footer class="footer">© <?=date('Y')?> Ricevaly — Pure • Fresh • Daily</footer>

<script>
(() => {
  const serverCart = <?=json_encode(array_map(fn($x)=>['id'=>$x['id'],'qty'=>$x['qty']], $items), JSON_UNESCAPED_UNICODE)?>;

  // CART RECOVERY:
  // Normally cart_json/session is authoritative. If checkout is opened without
  // either (common on mobile navigation), recover the existing browser cart
  // once and reload with cart_json so PHP can revalidate products/prices/stock.
  if (!serverCart.length) {
    try {
      const attempted = sessionStorage.getItem('rv_checkout_restore_attempted') === '1';
      const stored = JSON.parse(localStorage.getItem('ricevaly_cart') || '[]');
      const recovered = Array.isArray(stored)
        ? stored.map(v => ({
            id: Number(v?.id ?? v?.product_id ?? 0),
            qty: Number(v?.qty ?? v?.quantity ?? 0)
          })).filter(v => v.id > 0 && v.qty > 0)
        : [];

      if (!attempted && recovered.length) {
        sessionStorage.setItem('rv_checkout_restore_attempted', '1');
        const url = new URL(window.location.href);
        url.searchParams.set('cart_json', JSON.stringify(recovered));
        window.location.replace(url.toString());
        return;
      }
    } catch (e) {
      console.warn('Checkout cart recovery skipped:', e);
    }
  } else {
    try { sessionStorage.removeItem('rv_checkout_restore_attempted'); } catch (e) {}
  }

  let cart = serverCart;
  const productMap = <?=json_encode(array_column($items,null,'id'), JSON_UNESCAPED_UNICODE)?>;
  const shippingMethods = <?=json_encode(array_map(fn($m)=>['id'=>(int)$m['id'],'name'=>$m['name'],'cost'=>(float)$m['cost'],'free'=>(float)($m['free_above'] ?? 0),'min'=>(float)$m['minimum_order']], $shippingMethods), JSON_UNESCAPED_UNICODE)?>;
  let discount = 0;
  let couponCode = '';
  const money = n => '৳' + Number(n || 0).toLocaleString('en-US',{minimumFractionDigits:2,maximumFractionDigits:2});

  function saveCart(){ document.getElementById('cartJson').value=JSON.stringify(cart); }
  function selectedShipping(){ const el=document.querySelector('input[name="shipping_method_id"]:checked'); return shippingMethods.find(x=>String(x.id)===String(el?.value)) || shippingMethods[0]; }
  function calc(){
    let subtotal=0;
    cart.forEach(x=>{const p=productMap[x.id]; if(p) subtotal += Number(p.price)*Number(x.qty);});
    const m=selectedShipping() || {cost:0,free:0,min:0};
    let ship=Number(m.cost)||0;
    if(Number(m.min)>subtotal && Number(m.min)>0) ship=Number(m.cost)||0;
    if(Number(m.free)>0 && subtotal>=Number(m.free)) ship=0;
    const total=Math.max(0,subtotal+ship-discount);
    document.getElementById('subtotal').textContent=money(subtotal);
    document.getElementById('delivery').textContent=money(ship);
    document.getElementById('discount').textContent='-' + money(discount);
    document.getElementById('grandTotal').textContent=money(total);
    document.getElementById('discountRow').style.display=discount>0?'flex':'none';
    document.querySelectorAll('[data-ship-price]').forEach((el,i)=>{
      const input=document.querySelectorAll('input[name="shipping_method_id"]')[i]; if(!input)return;
      const mm=shippingMethods.find(x=>String(x.id)===String(input.value)); if(!mm)return;
      let c=Number(mm.cost)||0; if(Number(mm.free)>0&&subtotal>=Number(mm.free))c=0; el.textContent=money(c);
    });
  }
  function renderItems(){
    const box=document.getElementById('itemsBox');
    if(!cart.length){ box.innerHTML='<div class="empty">আপনার কার্ট খালি।<br><a href="/">শপিং শুরু করুন →</a></div>'; document.getElementById('orderBtn').disabled=true; document.getElementById('mobileOrderBtn').disabled=true; calc(); return; }
    let html='';
    cart.forEach(x=>{
      const p=productMap[x.id]; if(!p)return;
      html += `<div class="item"><div class="thumb">${p.image_url?`<img src="${escapeHtml(p.image_url)}" alt="${escapeHtml(p.name)}" loading="lazy" decoding="async" onerror="this.onerror=null;this.style.display='none';this.parentElement.classList.add('img-error')">`:'🛒'}</div><div class="item-main"><b>${escapeHtml(p.name)}</b><span>${money(p.price)} / ${escapeHtml(p.unit||'unit')}</span><div class="qty"><button type="button" data-local-qty="${x.id}" data-delta="-1">−</button><span>${x.qty}</span><button type="button" data-local-qty="${x.id}" data-delta="1">+</button></div></div><div class="item-total">${money(Number(p.price)*Number(x.qty))}</div></div>`;
    });
    box.innerHTML=html || '<div class="empty">কার্টের পণ্য পাওয়া যায়নি।<br><a href="/">শপিং শুরু করুন →</a></div>';
    document.getElementById('orderBtn').disabled=!cart.length; document.getElementById('mobileOrderBtn').disabled=!cart.length;
    calc();
  }
  function escapeHtml(x){return String(x??'').replace(/[&<>'"]/g,m=>({'&':'&amp;','<':'&lt;','>':'&gt;',"'":'&#39;','"':'&quot;'}[m]));}
  function showAlert(msg,type='error'){const a=document.getElementById('alert');a.className='alert show '+type;a.textContent=msg;window.scrollTo({top:0,behavior:'smooth'});}
  function clearAlert(){const a=document.getElementById('alert');a.className='alert';a.textContent='';}

  // The server/query/session cart is authoritative. Do not replace it with stale browser storage.
  saveCart(); renderItems();

  document.addEventListener('click', e=>{
    const q=e.target.closest('[data-local-qty]');
    if(q){ const id=Number(q.dataset.localQty), d=Number(q.dataset.delta); const x=cart.find(v=>Number(v.id)===id); if(x){x.qty+=d;if(x.qty<=0)cart=cart.filter(v=>Number(v.id)!==id);saveCart();renderItems();} }
  });
  document.querySelectorAll('input[name="shipping_method_id"]').forEach(r=>r.addEventListener('change',()=>{document.querySelectorAll('.ship-option').forEach(x=>x.classList.remove('active'));r.closest('.ship-option')?.classList.add('active');calc();}));
  document.querySelectorAll('input[name="payment_method"]').forEach(r=>r.addEventListener('change',()=>{document.querySelectorAll('.pay').forEach(x=>x.classList.remove('active'));r.closest('.pay')?.classList.add('active');document.getElementById('manualPayment').style.display=r.value==='cod'?'none':'block';}));

  document.getElementById('couponBtn').addEventListener('click', async()=>{
    const code=document.getElementById('couponInput').value.trim().toUpperCase();
    if(!code){discount=0;couponCode='';document.getElementById('couponMsg').textContent='';document.getElementById('discountRow').style.display='none';calc();return;}
    const fd=new FormData(); fd.append('action','coupon');fd.append('csrf_token','<?=e($csrf)?>');fd.append('coupon_code',code);fd.append('cart_json',JSON.stringify(cart));
    const btn=document.getElementById('couponBtn');btn.disabled=true;btn.textContent='যাচাই...';
    try{const r=await fetch(window.location.pathname,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const text=await r.text();let j;try{j=JSON.parse(text);}catch(parseErr){throw new Error('সার্ভার থেকে সঠিক উত্তর পাওয়া যায়নি। error_log চেক করুন।');}if(j.ok){discount=Number(j.discount)||0;couponCode=code;document.getElementById('couponCodeHidden').value=code;document.getElementById('couponMsg').textContent=j.message;document.getElementById('couponMsg').style.color='#16804a';}else{discount=0;couponCode='';document.getElementById('couponCodeHidden').value='';document.getElementById('couponMsg').textContent=j.message||'কুপন প্রযোজ্য নয়।';document.getElementById('couponMsg').style.color='#b53c3c';}calc();}catch(e){document.getElementById('couponMsg').textContent='কুপন যাচাই করা যায়নি।';document.getElementById('couponMsg').style.color='#b53c3c';}finally{btn.disabled=false;btn.textContent='প্রয়োগ';}
  });

  document.getElementById('orderForm').addEventListener('submit', async e=>{
    e.preventDefault(); clearAlert();
    saveCart();
    if(!cart.length){showAlert('আপনার কার্ট খালি।');return;}
    const required=[['name','নাম'],['phone','মোবাইল নম্বর'],['address','ঠিকানা']];
    for(const [id,label] of required){const el=document.getElementById(id);if(!el.value.trim()){el.classList.add('invalid');el.focus();showAlert(label+' পূরণ করুন।');return;}el.classList.remove('invalid');}
    const btn=document.getElementById('orderBtn'), mobile=document.getElementById('mobileOrderBtn');btn.disabled=true;mobile.disabled=true;btn.textContent='অর্ডার প্রসেস হচ্ছে...';mobile.textContent='অর্ডার প্রসেস হচ্ছে...';
    const fd=new FormData(e.target);fd.set('cart_json',JSON.stringify(cart));fd.set('coupon_code',couponCode);
    try{
      const r=await fetch(window.location.pathname,{method:'POST',body:fd,credentials:'same-origin',headers:{'X-Requested-With':'XMLHttpRequest'}});const text=await r.text();let j;try{j=JSON.parse(text);}catch(parseErr){throw new Error('সার্ভার থেকে সঠিক উত্তর পাওয়া যায়নি। error_log চেক করুন।');}
      if(!j.ok) throw new Error(j.message||'অর্ডার সম্পন্ন করা যায়নি।');
      localStorage.removeItem('ricevaly_cart');
      try { sessionStorage.removeItem('rv_checkout_restore_attempted'); } catch (e) {}
      const o=j.order;
      document.getElementById('checkoutLayout').innerHTML=`<section class="card success-page" style="grid-column:1/-1"><div class="success-icon">✓</div><h2>অর্ডার সফলভাবে গ্রহণ করা হয়েছে!</h2><p>আপনার অর্ডারটি Ricevaly-তে রেকর্ড হয়েছে।</p><div class="invoice">অর্ডার: ${escapeHtml(o.invoice)}</div><p>সর্বমোট: <strong>${money(o.total)}</strong></p><div class="success-actions"><a class="home-btn" href="/">হোমে ফিরুন</a><a class="print-btn" href="javascript:window.print()">প্রিন্ট করুন</a></div></section>`;
      window.scrollTo({top:0,behavior:'smooth'});
    }catch(err){showAlert(err.message||'অর্ডার সম্পন্ন করা যায়নি। আবার চেষ্টা করুন।');btn.disabled=false;mobile.disabled=false;btn.textContent='অর্ডার কনফার্ম করুন';mobile.textContent='অর্ডার কনফার্ম করুন';}
  });
})();
</script>
</body>
</html>
