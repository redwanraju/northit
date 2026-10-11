<?php
session_start();
error_reporting(0);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

include "auth.php";
requireLogin();
$shop_id = tenantShopId();
include "../config/db.php";

if (method_exists($conn, 'set_charset')) {
    $conn->set_charset('utf8mb4');
}

/* =========================================================
   MASTER POS REPORT PAGE
   Works with your existing tables:
   orders, order_items, products, customers, users,
   purchases, purchase_items, suppliers, supplier_payments,
   payments, expenses, brands, categories, sales_representatives,
   stock_movements, staff_salary.
   ========================================================= */

function h($v){ return htmlspecialchars((string)($v ?? ''), ENT_QUOTES, 'UTF-8'); }
function taka($v){ return '৳' . number_format((float)($v ?? 0), 2); }
function n2($v){ return number_format((float)($v ?? 0), 2); }
function onlyDate($d){ return preg_match('/^\d{4}-\d{2}-\d{2}$/', (string)$d) ? $d : ''; }
function onlyMonth($m){ return preg_match('/^\d{4}-\d{2}$/', (string)$m) ? $m : ''; }
function gi($key){ return isset($_GET[$key]) ? (int)$_GET[$key] : 0; }
function gs($conn,$key){ return isset($_GET[$key]) ? $conn->real_escape_string(trim($_GET[$key])) : ''; }
function selected($a,$b){ return ((string)$a === (string)$b) ? 'selected' : ''; }

function tableExists($conn, $table){
    $table = $conn->real_escape_string($table);
    $r = $conn->query("SHOW TABLES LIKE '$table'");
    return ($r && $r->num_rows > 0);
}

function colExists($conn, $table, $col){
    $table = $conn->real_escape_string($table);
    $col = $conn->real_escape_string($col);
    $r = $conn->query("SHOW COLUMNS FROM `$table` LIKE '$col'");
    return ($r && $r->num_rows > 0);
}

function fetchAllRows($conn, $sql){
    $rows = [];
    $res = $conn->query($sql);
    if(!$res){
        return ['__error' => $conn->error, '__sql' => $sql];
    }
    while($row = $res->fetch_assoc()){
        $rows[] = $row;
    }
    return $rows;
}

function firstValue($conn, $sql, $field='total'){
    $res = $conn->query($sql);
    if(!$res) return 0;
    $row = $res->fetch_assoc();
    return $row[$field] ?? 0;
}

function sumColumn($rows, $key){
    $sum = 0;
    foreach($rows as $r){ $sum += (float)($r[$key] ?? 0); }
    return $sum;
}

function dateWhere($alias, $col, $from, $to){
    $w = '';
    if($from && $to){
        $w .= " AND DATE($alias.$col) BETWEEN '$from' AND '$to'";
    } elseif($from){
        $w .= " AND DATE($alias.$col) >= '$from'";
    } elseif($to){
        $w .= " AND DATE($alias.$col) <= '$to'";
    }
    return $w;
}

function orderFilter($conn, $oa='o', $ca='c'){
    global $shop_id;
    $from = onlyDate($_GET['from'] ?? '');
    $to = onlyDate($_GET['to'] ?? '');
    $user_id = gi('user_id');
    $sr_id = gi('sr_id');
    $customer_id = gi('customer_id');
    $area_group = gs($conn,'area_group');
    $customer_group = gs($conn,'customer_group');
    $payment_status = gs($conn,'payment_status');
    $payment_method = gs($conn,'payment_method');

    $w = " WHERE $oa.shop_id = " . (int)$shop_id . " AND $oa.order_source = 'pos' ";
    $w .= dateWhere($oa, 'created_at', $from, $to);
    if($user_id > 0) $w .= " AND $oa.user_id = $user_id";
    if($sr_id > 0) $w .= " AND $oa.sr_id = $sr_id";
    if($customer_id > 0) $w .= " AND $oa.customer_id = $customer_id";
    if($area_group !== '') $w .= " AND $ca.area_group = '$area_group'";
    if($customer_group !== '') $w .= " AND $ca.customer_group = '$customer_group'";
    if($payment_status !== '') $w .= " AND $oa.payment_status = '$payment_status'";
    if($payment_method !== '') $w .= " AND $oa.payment_method = '$payment_method'";
    return $w;
}

function productFilter($conn, $pa='p'){
    global $shop_id;
    $product_id = gi('product_id');
    $brand_id = gi('brand_id');
    $category_id = gi('category_id');
    $q = gs($conn,'q');
    $w = " AND $pa.shop_id = " . (int)$shop_id;
    if($product_id > 0) $w .= " AND $pa.id = $product_id";
    if($brand_id > 0) $w .= " AND $pa.brand_id = $brand_id";
    if($category_id > 0) $w .= " AND $pa.category_id = $category_id";
    if($q !== '') $w .= " AND ($pa.product_name LIKE '%$q%' OR $pa.sku LIKE '%$q%' OR $pa.barcode LIKE '%$q%')";
    return $w;
}

function purchaseFilter($conn, $pa='pu', $sa='s'){
    global $shop_id;
    $from = onlyDate($_GET['from'] ?? '');
    $to = onlyDate($_GET['to'] ?? '');
    $supplier_id = gi('supplier_id');
    $sr_id = gi('sr_id');
    $q = gs($conn,'q');
    $w = " WHERE $sa.shop_id = " . (int)$shop_id . " ";
    $w .= dateWhere($pa, 'created_at', $from, $to);
    if($supplier_id > 0) $w .= " AND $pa.supplier_id = $supplier_id";
    if($sr_id > 0) $w .= " AND $sa.sr_id = $sr_id";
    if($q !== '') $w .= " AND ($pa.invoice_no LIKE '%$q%' OR $sa.supplier_name LIKE '%$q%')";
    return $w;
}

function limitSql(){
    $limit = gi('limit');
    if($limit <= 0) $limit = 500;
    if($limit > 5000) $limit = 5000;
    return " LIMIT $limit";
}

function getOptions($conn, $sql){
    $data = [];
    $res = $conn->query($sql);
    if($res){ while($r=$res->fetch_assoc()){ $data[]=$r; } }
    return $data;
}

$reports = [
    'monthly_report'            => 'Monthly Report',
    'invoice_wise_report'       => 'Invoice Wise Report',
    'item_wise_profit_report'   => 'Item Wise Profit Report',
    'due_customer_list'         => 'Due Customer List',
    'due_supplier_list'         => 'Due Supplier List',
    'customer_payment_report'   => 'Customer Payment Report',
    'supplier_payment_report'   => 'Supplier Payment Report',
    'item_stock_alert_report'   => 'Item Stock Alert Report',
    'item_stock_report'         => 'Item Stock Report',
    'damage_add_remove_report'  => 'Damage Add/Remove Report',
    'damage_item_report'        => 'Damage Item Report',
    'item_purchase_report'      => 'Item Purchase Report',
    'item_sales_report'         => 'Item Sales Report',
    'purchase_report'           => 'Purchase Report',
    'supplier_item_report'      => 'Supplier Item Report',
    'staff_sales_item_report'   => 'Staff Sales Item Report',
    'top_sales_item_report'     => 'Top Sales Item Report',
    'low_sales_item_report'     => 'Low Sales Item Report',
    'staff_report'              => 'Staff Report',
    'staff_monthly_sales_report'=> 'Staff Monthly Sales Report',
    'brand_wise_sales_report'   => 'Brand Wise Sales Report',
    'agent_commission_report'   => 'Agent Commission Report',
    'expense_report'            => 'Expense Report',
];

$report = $_GET['report'] ?? 'invoice_wise_report';
if(!isset($reports[$report])) $report = 'invoice_wise_report';

$from_date = onlyDate($_GET['from'] ?? '');
$to_date = onlyDate($_GET['to'] ?? '');
$low_stock = gi('low_stock');
if($low_stock <= 0) $low_stock = 10;
$top_limit = gi('top_limit');
if($top_limit <= 0) $top_limit = 20;
if($top_limit > 200) $top_limit = 200;

function buildReport($conn, $report, $reports, $low_stock, $top_limit){
    global $shop_id;
    $title = $reports[$report] ?? 'Report';
    $columns = [];
    $rows = [];
    $cards = [];
    $note = '';

    switch($report){
        case 'monthly_report':
            $ow = orderFilter($conn, 'o', 'c');
            $owNoDate = preg_replace("/ AND DATE\(o\.created_at\).*?(?= AND|$)/", "", $ow);
            $sql = "
                SELECT
                    x.month_name,
                    x.invoice_count,
                    x.total_sales,
                    x.total_paid,
                    x.total_due,
                    x.total_discount,
                    COALESCE((SELECT SUM(pu.grand_total) FROM purchases pu INNER JOIN suppliers ps ON ps.id = pu.supplier_id WHERE ps.shop_id = " . (int)$shop_id . " AND DATE_FORMAT(pu.created_at,'%Y-%m') = x.month_name),0) AS total_purchase,
                    COALESCE((SELECT SUM(ex.amount) FROM expenses ex WHERE ex.shop_id = " . (int)$shop_id . " AND DATE_FORMAT(ex.expense_date,'%Y-%m') = x.month_name),0) AS total_expense,
                    COALESCE((
                        SELECT SUM((oi.price - pr.purchase_price) * oi.qty)
                        FROM order_items oi
                        LEFT JOIN orders oo ON oo.id = oi.order_id
                        LEFT JOIN products pr ON pr.id = oi.product_id AND pr.shop_id = oo.shop_id
                        LEFT JOIN customers cc ON cc.id = oo.customer_id AND cc.shop_id = oo.shop_id
                        WHERE oo.shop_id = " . (int)$shop_id . " AND oo.order_source = 'pos' AND DATE_FORMAT(oo.created_at,'%Y-%m') = x.month_name
                    ),0) AS gross_profit
                FROM (
                    SELECT DATE_FORMAT(o.created_at,'%Y-%m') AS month_name,
                           COUNT(DISTINCT o.id) AS invoice_count,
                           SUM(o.total_amount) AS total_sales,
                           SUM(o.paid_amount) AS total_paid,
                           SUM(o.due_amount) AS total_due,
                           SUM(o.discount) AS total_discount
                    FROM orders o
                    LEFT JOIN customers c ON c.id = o.customer_id AND c.shop_id = o.shop_id
                    $ow
                    GROUP BY DATE_FORMAT(o.created_at,'%Y-%m')
                ) x
                ORDER BY x.month_name DESC
            ";
            $columns = [
                'month_name'=>'Month','invoice_count'=>'Invoices','total_sales'=>'Sales','total_purchase'=>'Purchase',
                'total_expense'=>'Expense','total_discount'=>'Discount','total_paid'=>'Paid','total_due'=>'Due','gross_profit'=>'Gross Profit'
            ];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'invoice_wise_report':
            $w = orderFilter($conn, 'o', 'c');
            $sql = "
                SELECT o.id, o.invoice_no, o.created_at, c.shop_name, c.name AS customer_name,
                       c.area_group, c.customer_group, u.name AS user_name, sr.sr_name,
                       o.subtotal, o.discount, o.delivery_cost, o.total_amount, o.paid_amount,
                       o.due_amount, o.payment_method, o.payment_status,
                       COALESCE((SELECT SUM((oi.price - p.purchase_price) * oi.qty)
                                 FROM order_items oi
                                 LEFT JOIN products p ON p.id = oi.product_id AND p.shop_id = o.shop_id
                                 WHERE oi.order_id = o.id),0) AS profit
                FROM orders o
                LEFT JOIN customers c ON c.id = o.customer_id AND c.shop_id = o.shop_id
                LEFT JOIN users u ON u.id = o.user_id AND u.shop_id = o.shop_id
                LEFT JOIN sales_representatives sr ON sr.id = o.sr_id AND sr.shop_id = o.shop_id
                $w
                ORDER BY o.id DESC
            " . limitSql();
            $columns = [
                'id'=>'ID','created_at'=>'Date','invoice_no'=>'Invoice','shop_name'=>'Shop','customer_name'=>'Customer',
                'area_group'=>'Area','customer_group'=>'Type','user_name'=>'User','sr_name'=>'Staff/SR',
                'total_amount'=>'Total','discount'=>'Discount','paid_amount'=>'Paid','due_amount'=>'Due','profit'=>'Profit','payment_method'=>'Payment'
            ];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'item_wise_profit_report':
            $w = orderFilter($conn, 'o', 'c') . productFilter($conn, 'p');
            $sql = "
                SELECT p.id AS product_id, p.product_name, b.brand_name, cat.category_name,
                       SUM(oi.qty) AS sold_qty,
                       AVG(p.purchase_price) AS avg_cost,
                       AVG(oi.price) AS avg_sale_price,
                       SUM(oi.subtotal) AS total_sales,
                       SUM(p.purchase_price * oi.qty) AS total_cost,
                       SUM((oi.price - p.purchase_price) * oi.qty) AS profit
                FROM order_items oi
                LEFT JOIN orders o ON o.id = oi.order_id
                LEFT JOIN customers c ON c.id = o.customer_id AND c.shop_id = o.shop_id
                LEFT JOIN products p ON p.id = oi.product_id AND p.shop_id = o.shop_id
                LEFT JOIN brands b ON b.id = p.brand_id AND b.shop_id = p.shop_id
                LEFT JOIN categories cat ON cat.id = p.category_id AND cat.shop_id = p.shop_id
                $w
                GROUP BY p.id, p.product_name, b.brand_name, cat.category_name
                ORDER BY profit DESC
            " . limitSql();
            $columns = ['product_id'=>'ID','product_name'=>'Item','brand_name'=>'Brand','category_name'=>'Category','sold_qty'=>'Qty','avg_cost'=>'Avg Cost','avg_sale_price'=>'Avg Sale','total_sales'=>'Sales','total_cost'=>'Cost','profit'=>'Profit'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'due_customer_list':
            $area_group = gs($conn,'area_group');
            $customer_group = gs($conn,'customer_group');
            $customer_id = gi('customer_id');
            $q = gs($conn,'q');
            $w = " WHERE c.shop_id = " . (int)$shop_id . " ";
            if($area_group !== '') $w .= " AND c.area_group = '$area_group'";
            if($customer_group !== '') $w .= " AND c.customer_group = '$customer_group'";
            if($customer_id > 0) $w .= " AND c.id = $customer_id";
            if($q !== '') $w .= " AND (c.name LIKE '%$q%' OR c.shop_name LIKE '%$q%' OR c.phone LIKE '%$q%')";
            $sql = "
                SELECT c.id, c.shop_name, c.name AS customer_name, c.phone, c.area_group, c.customer_group,
                       COALESCE(c.opening_due,0) AS opening_due,
                       COALESCE(c.due_amount,0) AS current_due,
                       COALESCE(SUM(o.total_amount),0) AS total_sale,
                       COALESCE(SUM(o.paid_amount),0) AS total_paid,
                       COALESCE(SUM(o.due_amount),0) AS invoice_due,
                       COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.customer_id=c.id AND p.type IN('customer_due_receive','advance_payment')),0) AS received_payment,
                       (COALESCE(c.opening_due,0) + COALESCE(c.due_amount,0) + COALESCE(SUM(o.due_amount),0) - COALESCE((SELECT SUM(p.amount) FROM payments p WHERE p.customer_id=c.id AND p.type IN('customer_due_receive','advance_payment')),0)) AS total_due
                FROM customers c
                LEFT JOIN orders o ON o.customer_id = c.id AND o.shop_id = c.shop_id AND o.order_source = 'pos'
                $w
                GROUP BY c.id
                HAVING total_due > 0
                ORDER BY total_due DESC
            ";
            $columns = ['id'=>'ID','shop_name'=>'Shop','customer_name'=>'Customer','phone'=>'Phone','area_group'=>'Area','customer_group'=>'Type','opening_due'=>'Opening Due','invoice_due'=>'Invoice Due','received_payment'=>'Payment Received','total_due'=>'Total Due'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'due_supplier_list':
            $supplier_id = gi('supplier_id');
            $sr_id = gi('sr_id');
            $q = gs($conn,'q');
            $w = " WHERE s.shop_id = " . (int)$shop_id . " ";
            if($supplier_id > 0) $w .= " AND s.id = $supplier_id";
            if($sr_id > 0) $w .= " AND s.sr_id = $sr_id";
            if($q !== '') $w .= " AND (s.supplier_name LIKE '%$q%' OR s.phone LIKE '%$q%' OR s.supplier_code LIKE '%$q%')";
            $sql = "
                SELECT s.id, s.supplier_code, s.supplier_name, s.phone, sr.sr_name,
                       COALESCE(s.opening_due,0) AS opening_due,
                       COALESCE(s.current_due,0) AS current_due,
                       COALESCE(SUM(pu.grand_total),0) AS total_purchase,
                       COALESCE(SUM(pu.paid_amount),0) AS total_paid,
                       COALESCE(SUM(pu.due_amount),0) AS purchase_due,
                       COALESCE((SELECT SUM(sp.payment_amount) FROM supplier_payments sp WHERE sp.supplier_id=s.id),0) AS supplier_payment,
                       (COALESCE(s.opening_due,0) + COALESCE(s.current_due,0) + COALESCE(SUM(pu.due_amount),0) - COALESCE((SELECT SUM(sp.payment_amount) FROM supplier_payments sp WHERE sp.supplier_id=s.id),0)) AS total_due
                FROM suppliers s
                LEFT JOIN purchases pu ON pu.supplier_id = s.id
                LEFT JOIN sales_representatives sr ON sr.id = s.sr_id AND sr.shop_id = s.shop_id
                $w
                GROUP BY s.id
                HAVING total_due > 0
                ORDER BY total_due DESC
            ";
            $columns = ['id'=>'ID','supplier_code'=>'Code','supplier_name'=>'Supplier','phone'=>'Phone','sr_name'=>'SR','opening_due'=>'Opening Due','purchase_due'=>'Purchase Due','supplier_payment'=>'Payment','total_due'=>'Total Due'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'customer_payment_report':
            if(!tableExists($conn,'payments')){ $note='payments table পাওয়া যায়নি।'; break; }
            $from = onlyDate($_GET['from'] ?? '');
            $to = onlyDate($_GET['to'] ?? '');
            $customer_id = gi('customer_id');
            $q = gs($conn,'q');
            $w = " WHERE p.type IN('customer_due_receive','advance_payment') AND c.shop_id = " . (int)$shop_id . " ";
            $w .= dateWhere('p','created_at',$from,$to);
            if($customer_id > 0) $w .= " AND p.customer_id = $customer_id";
            if($q !== '') $w .= " AND (c.name LIKE '%$q%' OR c.shop_name LIKE '%$q%' OR c.phone LIKE '%$q%' OR p.note LIKE '%$q%')";
            $sql = "
                SELECT p.id, p.created_at, c.shop_name, c.name AS customer_name, c.phone, p.type, p.amount, p.note, u.name AS received_by
                FROM payments p
                LEFT JOIN customers c ON c.id = p.customer_id
                LEFT JOIN users u ON u.id = p.created_by AND u.shop_id = c.shop_id
                $w
                ORDER BY p.id DESC
            " . limitSql();
            $columns = ['id'=>'ID','created_at'=>'Date','shop_name'=>'Shop','customer_name'=>'Customer','phone'=>'Phone','type'=>'Type','amount'=>'Amount','received_by'=>'By','note'=>'Note'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'supplier_payment_report':
            if(!tableExists($conn,'supplier_payments')){ $note='supplier_payments table পাওয়া যায়নি।'; break; }
            $from = onlyDate($_GET['from'] ?? '');
            $to = onlyDate($_GET['to'] ?? '');
            $supplier_id = gi('supplier_id');
            $q = gs($conn,'q');
            $w = " WHERE s.shop_id = " . (int)$shop_id . " ";
            $w .= dateWhere('sp','payment_date',$from,$to);
            if($supplier_id > 0) $w .= " AND sp.supplier_id = $supplier_id";
            if($q !== '') $w .= " AND (s.supplier_name LIKE '%$q%' OR s.phone LIKE '%$q%' OR sp.note LIKE '%$q%' OR pu.invoice_no LIKE '%$q%')";
            $sql = "
                SELECT sp.id, sp.payment_date, s.supplier_name, s.phone, pu.invoice_no, sp.payment_amount, sp.payment_method, sp.note
                FROM supplier_payments sp
                LEFT JOIN suppliers s ON s.id = sp.supplier_id
                LEFT JOIN purchases pu ON pu.id = sp.purchase_id
                $w
                ORDER BY sp.id DESC
            " . limitSql();
            $columns = ['id'=>'ID','payment_date'=>'Date','supplier_name'=>'Supplier','phone'=>'Phone','invoice_no'=>'Invoice','payment_amount'=>'Amount','payment_method'=>'Method','note'=>'Note'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'item_stock_alert_report':
            $where = " WHERE p.stock <= " . (float)$low_stock . productFilter($conn,'p');
            $sql = "
                SELECT p.id, p.product_name, p.sku, p.barcode, b.brand_name, cat.category_name, p.purchase_price, p.sale_price, p.stock, p.unit, (p.stock*p.purchase_price) AS stock_value
                FROM products p
                LEFT JOIN brands b ON b.id = p.brand_id AND b.shop_id = p.shop_id
                LEFT JOIN categories cat ON cat.id = p.category_id AND cat.shop_id = p.shop_id
                $where
                ORDER BY p.stock ASC, p.product_name ASC
            " . limitSql();
            $columns = ['id'=>'ID','product_name'=>'Item','sku'=>'SKU','barcode'=>'Barcode','brand_name'=>'Brand','category_name'=>'Category','purchase_price'=>'Cost','sale_price'=>'Sale','stock'=>'Stock','unit'=>'Unit','stock_value'=>'Stock Value'];
            $rows = fetchAllRows($conn,$sql);
            $note = "Low stock limit: $low_stock";
            break;

        case 'item_stock_report':
            $where = " WHERE 1 " . productFilter($conn,'p');
            $sql = "
                SELECT p.id, p.product_name, p.sku, p.barcode, b.brand_name, cat.category_name, p.purchase_price, p.sale_price, p.stock, p.unit, (p.stock*p.purchase_price) AS cost_stock_value, (p.stock*p.sale_price) AS sale_stock_value
                FROM products p
                LEFT JOIN brands b ON b.id = p.brand_id AND b.shop_id = p.shop_id
                LEFT JOIN categories cat ON cat.id = p.category_id AND cat.shop_id = p.shop_id
                $where
                ORDER BY p.product_name ASC
            " . limitSql();
            $columns = ['id'=>'ID','product_name'=>'Item','sku'=>'SKU','barcode'=>'Barcode','brand_name'=>'Brand','category_name'=>'Category','purchase_price'=>'Cost','sale_price'=>'Sale','stock'=>'Stock','unit'=>'Unit','cost_stock_value'=>'Cost Value','sale_stock_value'=>'Sale Value'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'damage_add_remove_report':
            if(!tableExists($conn,'stock_movements')){ $note='stock_movements/damage table পাওয়া যায়নি।'; break; }
            $from = onlyDate($_GET['from'] ?? '');
            $to = onlyDate($_GET['to'] ?? '');
            $q = gs($conn,'q');
            $w = " WHERE p.shop_id = " . (int)$shop_id . " ";
            $w .= dateWhere('sm','created_at',$from,$to);
            if($q !== '') $w .= " AND (p.product_name LIKE '%$q%' OR sm.note LIKE '%$q%')";
            $sql = "
                SELECT sm.id, sm.created_at, p.product_name, sm.type, sm.qty, p.unit, sm.note
                FROM stock_movements sm
                LEFT JOIN products p ON p.id = sm.product_id
                $w
                ORDER BY sm.id DESC
            " . limitSql();
            $columns = ['id'=>'ID','created_at'=>'Date','product_name'=>'Item','type'=>'Type','qty'=>'Qty','unit'=>'Unit','note'=>'Note/Reason'];
            $rows = fetchAllRows($conn,$sql);
            $note = "Damage-এর আলাদা table না থাকলে stock_movements থেকে add/remove history দেখাবে।";
            break;

        case 'damage_item_report':
            if(!tableExists($conn,'stock_movements')){ $note='stock_movements/damage table পাওয়া যায়নি।'; break; }
            $from = onlyDate($_GET['from'] ?? '');
            $to = onlyDate($_GET['to'] ?? '');
            $w = " WHERE p.shop_id = " . (int)$shop_id . " AND (sm.note LIKE '%damage%' OR sm.note LIKE '%damag%' OR sm.note LIKE '%Damage%') ";
            $w .= dateWhere('sm','created_at',$from,$to);
            $sql = "
                SELECT p.id AS product_id, p.product_name, b.brand_name, SUM(CASE WHEN sm.type='out' THEN sm.qty ELSE 0 END) AS damage_out_qty,
                       SUM(CASE WHEN sm.type='in' THEN sm.qty ELSE 0 END) AS damage_return_qty,
                       SUM(CASE WHEN sm.type='out' THEN sm.qty*p.purchase_price ELSE 0 END) AS damage_cost_value,
                       SUM(CASE WHEN sm.type='out' THEN sm.qty*p.sale_price ELSE 0 END) AS damage_sale_value
                FROM stock_movements sm
                LEFT JOIN products p ON p.id = sm.product_id
                LEFT JOIN brands b ON b.id = p.brand_id AND b.shop_id = p.shop_id
                $w
                GROUP BY p.id, p.product_name, b.brand_name
                ORDER BY damage_cost_value DESC
            " . limitSql();
            $columns = ['product_id'=>'ID','product_name'=>'Item','brand_name'=>'Brand','damage_out_qty'=>'Damage Out','damage_return_qty'=>'Return/Add','damage_cost_value'=>'Cost Value','damage_sale_value'=>'Sale Value'];
            $rows = fetchAllRows($conn,$sql);
            $note = "Damage report-এর জন্য stock_movements.note-এ damage শব্দ থাকলে group report দেখাবে।";
            break;

        case 'item_purchase_report':
            $w = purchaseFilter($conn,'pu','s') . productFilter($conn,'pr');
            $sql = "
                SELECT pi.product_id, pi.product_name, b.brand_name, cat.category_name, s.supplier_name,
                       SUM(pi.quantity) AS purchase_qty, AVG(pi.purchase_price) AS avg_purchase_price,
                       SUM(pi.total) AS total_purchase
                FROM purchase_items pi
                LEFT JOIN purchases pu ON pu.id = pi.purchase_id
                LEFT JOIN suppliers s ON s.id = pu.supplier_id
                LEFT JOIN products pr ON pr.id = pi.product_id AND pr.shop_id = pi.shop_id
                LEFT JOIN brands b ON b.id = pi.brand_id AND b.shop_id = pi.shop_id
                LEFT JOIN categories cat ON cat.id = pi.category_id AND cat.shop_id = pi.shop_id
                $w
                GROUP BY pi.product_id, pi.product_name, b.brand_name, cat.category_name, s.supplier_name
                ORDER BY total_purchase DESC
            " . limitSql();
            $columns = ['product_id'=>'ID','product_name'=>'Item','brand_name'=>'Brand','category_name'=>'Category','supplier_name'=>'Supplier','purchase_qty'=>'Qty','avg_purchase_price'=>'Avg Price','total_purchase'=>'Total Purchase'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'item_sales_report':
            $w = orderFilter($conn,'o','c') . productFilter($conn,'p');
            $sql = "
                SELECT p.id AS product_id, p.product_name, b.brand_name, cat.category_name,
                       SUM(oi.qty) AS sold_qty, AVG(oi.price) AS avg_sale_price,
                       SUM(oi.subtotal) AS total_sales, SUM((oi.price-p.purchase_price)*oi.qty) AS profit
                FROM order_items oi
                LEFT JOIN orders o ON o.id = oi.order_id
                LEFT JOIN customers c ON c.id = o.customer_id AND c.shop_id = o.shop_id
                LEFT JOIN products p ON p.id = oi.product_id AND p.shop_id = o.shop_id
                LEFT JOIN brands b ON b.id = p.brand_id AND b.shop_id = p.shop_id
                LEFT JOIN categories cat ON cat.id = p.category_id AND cat.shop_id = p.shop_id
                $w
                GROUP BY p.id, p.product_name, b.brand_name, cat.category_name
                ORDER BY total_sales DESC
            " . limitSql();
            $columns = ['product_id'=>'ID','product_name'=>'Item','brand_name'=>'Brand','category_name'=>'Category','sold_qty'=>'Qty','avg_sale_price'=>'Avg Sale','total_sales'=>'Total Sales','profit'=>'Profit'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'purchase_report':
            $w = purchaseFilter($conn,'pu','s');
            $sql = "
                SELECT pu.id, pu.created_at, pu.invoice_no, s.supplier_name, s.phone, sr.sr_name, pu.grand_total, pu.paid_amount, pu.due_amount
                FROM purchases pu
                LEFT JOIN suppliers s ON s.id = pu.supplier_id
                LEFT JOIN sales_representatives sr ON sr.id = s.sr_id AND sr.shop_id = s.shop_id
                $w
                ORDER BY pu.id DESC
            " . limitSql();
            $columns = ['id'=>'ID','created_at'=>'Date','invoice_no'=>'Invoice','supplier_name'=>'Supplier','phone'=>'Phone','sr_name'=>'SR','grand_total'=>'Grand Total','paid_amount'=>'Paid','due_amount'=>'Due'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'supplier_item_report':
            $w = purchaseFilter($conn,'pu','s') . productFilter($conn,'pr');
            $sql = "
                SELECT s.supplier_name, s.phone, pi.product_name, b.brand_name,
                       SUM(pi.quantity) AS qty, AVG(pi.purchase_price) AS avg_price, SUM(pi.total) AS total_amount
                FROM purchase_items pi
                LEFT JOIN purchases pu ON pu.id = pi.purchase_id
                LEFT JOIN suppliers s ON s.id = pu.supplier_id
                LEFT JOIN products pr ON pr.id = pi.product_id AND pr.shop_id = pi.shop_id
                LEFT JOIN brands b ON b.id = pi.brand_id AND b.shop_id = pi.shop_id
                $w
                GROUP BY s.id, pi.product_id, pi.product_name, b.brand_name
                ORDER BY s.supplier_name ASC, total_amount DESC
            " . limitSql();
            $columns = ['supplier_name'=>'Supplier','phone'=>'Phone','product_name'=>'Item','brand_name'=>'Brand','qty'=>'Qty','avg_price'=>'Avg Price','total_amount'=>'Total'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'staff_sales_item_report':
            $w = orderFilter($conn,'o','c') . productFilter($conn,'p');
            $sql = "
                SELECT sr.sr_name, p.product_name, b.brand_name,
                       SUM(oi.qty) AS sold_qty, SUM(oi.subtotal) AS total_sales,
                       SUM((oi.price-p.purchase_price)*oi.qty) AS profit
                FROM order_items oi
                LEFT JOIN orders o ON o.id = oi.order_id
                LEFT JOIN customers c ON c.id = o.customer_id AND c.shop_id = o.shop_id
                LEFT JOIN sales_representatives sr ON sr.id = o.sr_id AND sr.shop_id = o.shop_id
                LEFT JOIN products p ON p.id = oi.product_id AND p.shop_id = o.shop_id
                LEFT JOIN brands b ON b.id = p.brand_id AND b.shop_id = p.shop_id
                $w
                GROUP BY sr.id, p.id, p.product_name, b.brand_name
                ORDER BY sr.sr_name ASC, total_sales DESC
            " . limitSql();
            $columns = ['sr_name'=>'Staff/SR','product_name'=>'Item','brand_name'=>'Brand','sold_qty'=>'Qty','total_sales'=>'Sales','profit'=>'Profit'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'top_sales_item_report':
            $w = orderFilter($conn,'o','c') . productFilter($conn,'p');
            $sql = "
                SELECT p.id AS product_id, p.product_name, b.brand_name,
                       SUM(oi.qty) AS sold_qty, SUM(oi.subtotal) AS total_sales,
                       SUM((oi.price-p.purchase_price)*oi.qty) AS profit
                FROM order_items oi
                LEFT JOIN orders o ON o.id = oi.order_id
                LEFT JOIN customers c ON c.id = o.customer_id AND c.shop_id = o.shop_id
                LEFT JOIN products p ON p.id = oi.product_id AND p.shop_id = o.shop_id
                LEFT JOIN brands b ON b.id = p.brand_id AND b.shop_id = p.shop_id
                $w
                GROUP BY p.id, p.product_name, b.brand_name
                ORDER BY sold_qty DESC, total_sales DESC
                LIMIT $top_limit
            ";
            $columns = ['product_id'=>'ID','product_name'=>'Item','brand_name'=>'Brand','sold_qty'=>'Sold Qty','total_sales'=>'Sales','profit'=>'Profit'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'low_sales_item_report':
            $from = onlyDate($_GET['from'] ?? '');
            $to = onlyDate($_GET['to'] ?? '');
            $dateJoin = "";
            if($from && $to) $dateJoin = " AND DATE(o.created_at) BETWEEN '$from' AND '$to'";
            elseif($from) $dateJoin = " AND DATE(o.created_at) >= '$from'";
            elseif($to) $dateJoin = " AND DATE(o.created_at) <= '$to'";
            $where = " WHERE 1 " . productFilter($conn,'p');
            $sql = "
                SELECT p.id AS product_id, p.product_name, b.brand_name, p.stock,
                       COALESCE(SUM(oi.qty),0) AS sold_qty,
                       COALESCE(SUM(oi.subtotal),0) AS total_sales
                FROM products p
                LEFT JOIN brands b ON b.id = p.brand_id AND b.shop_id = p.shop_id
                LEFT JOIN order_items oi ON oi.product_id = p.id
                LEFT JOIN orders o ON o.id = oi.order_id AND o.shop_id = p.shop_id AND o.order_source = 'pos' $dateJoin
                $where
                GROUP BY p.id, p.product_name, b.brand_name, p.stock
                ORDER BY sold_qty ASC, total_sales ASC, p.product_name ASC
                LIMIT $top_limit
            ";
            $columns = ['product_id'=>'ID','product_name'=>'Item','brand_name'=>'Brand','stock'=>'Current Stock','sold_qty'=>'Sold Qty','total_sales'=>'Sales'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'staff_report':
            $q = gs($conn,'q');
            $sr_id = gi('sr_id');
            $w = " WHERE sr.shop_id = " . (int)$shop_id . " ";
            if($sr_id > 0) $w .= " AND sr.id = $sr_id";
            if($q !== '') $w .= " AND (sr.sr_name LIKE '%$q%' OR sr.phone LIKE '%$q%' OR sr.area_group LIKE '%$q%')";
            $sql = "
                SELECT sr.id, sr.sr_name, sr.phone, sr.area_group, sr.target_amount, sr.commission_rate, sr.basic_salary, sr.status,
                       COALESCE((SELECT COUNT(*) FROM orders o WHERE o.sr_id=sr.id AND o.shop_id=sr.shop_id AND o.order_source='pos'),0) AS invoice_count,
                       COALESCE((SELECT SUM(o.total_amount) FROM orders o WHERE o.sr_id=sr.id AND o.shop_id=sr.shop_id AND o.order_source='pos'),0) AS total_sales,
                       COALESCE((SELECT SUM(o.paid_amount) FROM orders o WHERE o.sr_id=sr.id AND o.shop_id=sr.shop_id AND o.order_source='pos'),0) AS total_collection,
                       COALESCE((SELECT SUM(o.due_amount) FROM orders o WHERE o.sr_id=sr.id AND o.shop_id=sr.shop_id AND o.order_source='pos'),0) AS total_due
                FROM sales_representatives sr
                $w
                ORDER BY sr.id DESC
            ";
            $columns = ['id'=>'ID','sr_name'=>'Staff/SR','phone'=>'Phone','area_group'=>'Area','target_amount'=>'Target','commission_rate'=>'Commission %','basic_salary'=>'Basic Salary','invoice_count'=>'Invoices','total_sales'=>'Sales','total_collection'=>'Collection','total_due'=>'Due','status'=>'Status'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'staff_monthly_sales_report':
            $w = orderFilter($conn,'o','c');
            $sql = "
                SELECT DATE_FORMAT(o.created_at,'%Y-%m') AS month_name, sr.sr_name,
                       COUNT(DISTINCT o.id) AS invoice_count,
                       SUM(o.total_amount) AS total_sales,
                       SUM(o.paid_amount) AS total_collection,
                       SUM(o.due_amount) AS total_due,
                       sr.commission_rate,
                       (SUM(o.total_amount) * COALESCE(sr.commission_rate,0) / 100) AS commission_amount
                FROM orders o
                LEFT JOIN customers c ON c.id = o.customer_id AND c.shop_id = o.shop_id
                LEFT JOIN sales_representatives sr ON sr.id = o.sr_id AND sr.shop_id = o.shop_id
                $w
                GROUP BY DATE_FORMAT(o.created_at,'%Y-%m'), sr.id, sr.sr_name, sr.commission_rate
                ORDER BY month_name DESC, total_sales DESC
            " . limitSql();
            $columns = ['month_name'=>'Month','sr_name'=>'Staff/SR','invoice_count'=>'Invoices','total_sales'=>'Sales','total_collection'=>'Collection','total_due'=>'Due','commission_rate'=>'Commission %','commission_amount'=>'Commission'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'brand_wise_sales_report':
            $w = orderFilter($conn,'o','c') . productFilter($conn,'p');
            $sql = "
                SELECT b.id AS brand_id, b.brand_name,
                       COUNT(DISTINCT o.id) AS invoice_count,
                       SUM(oi.qty) AS sold_qty,
                       SUM(oi.subtotal) AS total_sales,
                       SUM((oi.price-p.purchase_price)*oi.qty) AS profit
                FROM order_items oi
                LEFT JOIN orders o ON o.id = oi.order_id
                LEFT JOIN customers c ON c.id = o.customer_id AND c.shop_id = o.shop_id
                LEFT JOIN products p ON p.id = oi.product_id AND p.shop_id = o.shop_id
                LEFT JOIN brands b ON b.id = p.brand_id AND b.shop_id = p.shop_id
                $w
                GROUP BY b.id, b.brand_name
                ORDER BY total_sales DESC
            " . limitSql();
            $columns = ['brand_id'=>'ID','brand_name'=>'Brand','invoice_count'=>'Invoices','sold_qty'=>'Qty','total_sales'=>'Sales','profit'=>'Profit'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'agent_commission_report':
            $w = orderFilter($conn,'o','c');
            $sql = "
                SELECT sr.id, sr.sr_name AS agent_name, sr.phone, sr.target_amount, sr.commission_rate,
                       COUNT(DISTINCT o.id) AS invoice_count,
                       SUM(o.total_amount) AS total_sales,
                       SUM(o.paid_amount) AS collection,
                       (SUM(o.total_amount) * COALESCE(sr.commission_rate,0) / 100) AS commission_amount
                FROM orders o
                LEFT JOIN customers c ON c.id = o.customer_id AND c.shop_id = o.shop_id
                LEFT JOIN sales_representatives sr ON sr.id = o.sr_id AND sr.shop_id = o.shop_id
                $w
                GROUP BY sr.id, sr.sr_name, sr.phone, sr.target_amount, sr.commission_rate
                ORDER BY commission_amount DESC
            " . limitSql();
            $columns = ['id'=>'ID','agent_name'=>'Agent/SR','phone'=>'Phone','target_amount'=>'Target','commission_rate'=>'Commission %','invoice_count'=>'Invoices','total_sales'=>'Sales','collection'=>'Collection','commission_amount'=>'Commission'];
            $rows = fetchAllRows($conn,$sql);
            break;

        case 'expense_report':
            if(!tableExists($conn,'expenses')){ $note='expenses table পাওয়া যায়নি।'; break; }
            $from = onlyDate($_GET['from'] ?? '');
            $to = onlyDate($_GET['to'] ?? '');
            $q = gs($conn,'q');
            $w = " WHERE e.shop_id = " . (int)$shop_id . " ";
            if($from && $to) $w .= " AND e.expense_date BETWEEN '$from' AND '$to'";
            elseif($from) $w .= " AND e.expense_date >= '$from'";
            elseif($to) $w .= " AND e.expense_date <= '$to'";
            if($q !== '') $w .= " AND (e.category LIKE '%$q%' OR e.description LIKE '%$q%' OR e.note LIKE '%$q%' OR e.reference_no LIKE '%$q%')";
            $sql = "
                SELECT e.id, e.expense_date, e.category, e.description, e.amount, e.payment_method, e.reference_no, e.status, u.name AS created_by_name, e.note
                FROM expenses e
                LEFT JOIN users u ON u.id = e.created_by AND u.shop_id = e.shop_id
                $w
                ORDER BY e.expense_date DESC, e.id DESC
            " . limitSql();
            $columns = ['id'=>'ID','expense_date'=>'Date','category'=>'Category','description'=>'Description','amount'=>'Amount','payment_method'=>'Method','reference_no'=>'Reference','status'=>'Status','created_by_name'=>'By','note'=>'Note'];
            $rows = fetchAllRows($conn,$sql);
            break;
    }

    if(isset($rows['__error'])){
        $note = 'SQL Error: ' . $rows['__error'];
        $rows = [];
    }

    $moneyKeys = ['total_amount','subtotal','discount','delivery_cost','paid_amount','due_amount','profit','total_sales','total_purchase','total_expense','total_discount','total_paid','total_due','gross_profit','avg_cost','avg_sale_price','total_cost','opening_due','current_due','invoice_due','received_payment','supplier_payment','payment_amount','amount','purchase_price','sale_price','stock_value','cost_stock_value','sale_stock_value','damage_cost_value','damage_sale_value','avg_purchase_price','total_purchase','grand_total','avg_price','total_collection','target_amount','commission_amount','basic_salary','collection','total_sale','purchase_due','total_paid','total_due','total_amount'];

    $cards = [
        'Rows' => count($rows),
    ];
    foreach(['total_sales','total_amount','grand_total','profit','gross_profit','paid_amount','due_amount','total_due','amount','commission_amount','stock_value','cost_stock_value'] as $k){
        $v = sumColumn($rows,$k);
        if($v != 0) $cards[ucwords(str_replace('_',' ', $k))] = taka($v);
    }

    return [
        'title'=>$title,
        'columns'=>$columns,
        'rows'=>$rows,
        'cards'=>$cards,
        'note'=>$note,
        'moneyKeys'=>$moneyKeys
    ];
}

$data = buildReport($conn, $report, $reports, $low_stock, $top_limit);

/* ================= CSV EXPORT ================= */
if(isset($_GET['export']) && $_GET['export'] === 'csv'){
    $filename = preg_replace('/[^A-Za-z0-9_\-]/','_', $report) . '_' . date('Ymd_His') . '.csv';
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename='.$filename);
    echo "\xEF\xBB\xBF";
    $out = fopen('php://output', 'w');
    fputcsv($out, array_values($data['columns']));
    foreach($data['rows'] as $r){
        $line = [];
        foreach(array_keys($data['columns']) as $k){ $line[] = $r[$k] ?? ''; }
        fputcsv($out, $line);
    }
    fclose($out);
    exit;
}

/* ================= DROPDOWN DATA ================= */
$users = getOptions($conn, "SELECT id, name FROM users WHERE shop_id = $shop_id ORDER BY name ASC");
$srs = tableExists($conn,'sales_representatives') ? getOptions($conn, "SELECT id, sr_name FROM sales_representatives WHERE shop_id = $shop_id ORDER BY sr_name ASC") : [];
$customers = getOptions($conn, "SELECT id, CONCAT(COALESCE(shop_name,''),' - ',COALESCE(name,'')) AS customer_name FROM customers WHERE shop_id = $shop_id ORDER BY shop_name ASC, name ASC LIMIT 1000");
$suppliers = tableExists($conn,'suppliers') ? getOptions($conn, "SELECT id, supplier_name FROM suppliers WHERE shop_id = $shop_id ORDER BY supplier_name ASC") : [];
$brands = tableExists($conn,'brands') ? getOptions($conn, "SELECT id, brand_name FROM brands WHERE shop_id = $shop_id ORDER BY brand_name ASC") : [];
$categories = tableExists($conn,'categories') ? getOptions($conn, "SELECT id, category_name FROM categories WHERE shop_id = $shop_id ORDER BY category_name ASC") : [];
$areas = getOptions($conn, "SELECT DISTINCT area_group FROM customers WHERE shop_id = $shop_id AND area_group IS NOT NULL AND area_group!='' ORDER BY area_group ASC");
$methods = getOptions($conn, "SELECT DISTINCT payment_method FROM orders WHERE shop_id = $shop_id AND order_source='pos' AND payment_method IS NOT NULL AND payment_method!='' ORDER BY payment_method ASC");

include "header.php";
include "sidebar.php";
?>

<style>
:root{
    --rv-teal:#009b85;
    --rv-teal-dark:#00836f;
    --rv-teal-light:#e8f6f3;
    --rv-bg:#eaf3ef;
    --rv-card:#ffffff;
    --rv-border:#dfe7e3;
    --rv-text:#20232a;
    --rv-muted:#69737a;
    --rv-green:#279848;
    --rv-blue:#147bd1;
    --rv-red:#d83a54;
    --rv-shadow:0 2px 8px rgba(15,23,42,.08);
}
.main{
    flex:1;
    min-height:100vh;
    background:var(--rv-bg);
    font-family:'Segoe UI', Arial, sans-serif;
    color:var(--rv-text);
    padding:0;
}
.report-mobile-topbar{
    height:56px;
    background:var(--rv-teal);
    color:#fff;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 14px;
    position:sticky;
    top:0;
    z-index:50;
    box-shadow:0 2px 6px rgba(0,0,0,.12);
}
.report-mobile-topbar .brand-title{
    display:flex;
    align-items:center;
    gap:10px;
    font-size:21px;
    font-weight:700;
    letter-spacing:.2px;
}
.report-mobile-topbar .top-actions{
    display:flex;
    align-items:center;
    gap:8px;
}
.report-icon-btn{
    width:36px;
    height:36px;
    border:0;
    border-radius:10px;
    background:rgba(255,255,255,.15);
    color:#fff;
    display:inline-flex;
    align-items:center;
    justify-content:center;
    cursor:pointer;
    font-size:19px;
    text-decoration:none;
}
.report-app{
    display:grid;
    grid-template-columns:310px minmax(0,1fr);
    gap:0;
    min-height:calc(100vh - 56px);
}
.report-drawer{
    background:#edf8f5;
    border-right:1px solid #d1e4df;
    position:sticky;
    top:56px;
    height:calc(100vh - 56px);
    overflow:auto;
    z-index:30;
}
.report-menu-title{
    background:var(--rv-teal);
    color:#fff;
    min-height:50px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    padding:0 16px;
    font-size:22px;
    font-weight:700;
    letter-spacing:.2px;
}
.report-menu-title small{
    font-size:13px;
    opacity:.9;
    font-weight:500;
}
.report-nav{
    padding:8px 0 14px;
}
.report-nav a{
    display:flex;
    align-items:center;
    gap:13px;
    padding:13px 18px;
    color:#222831;
    text-decoration:none;
    font-size:15.5px;
    font-weight:500;
    line-height:1.35;
    border-left:4px solid transparent;
    transition:.15s;
}
.report-nav a:hover{
    background:#fff;
    color:var(--rv-teal-dark);
}
.report-nav a.active{
    background:#fff;
    color:var(--rv-teal-dark);
    border-left-color:var(--rv-teal);
    box-shadow:inset 0 1px 0 rgba(0,0,0,.02), inset 0 -1px 0 rgba(0,0,0,.02);
}
.report-nav .menu-ico{
    width:22px;
    color:inherit;
    opacity:.9;
    text-align:center;
    font-size:16px;
}
.report-drawer-bottom{
    border-top:1px solid #cddbd7;
    background:#f6f7f7;
    padding:15px 16px 22px;
    color:#1f2933;
}
.drawer-user-row{
    display:flex;
    align-items:center;
    justify-content:space-between;
    font-size:17px;
    font-weight:600;
    margin-bottom:13px;
}
.drawer-power-row{
    display:flex;
    align-items:center;
    gap:15px;
    margin:12px 0 16px;
}
.drawer-mini-btn{
    width:54px;
    height:44px;
    border-radius:7px;
    border:0;
    display:flex;
    align-items:center;
    justify-content:center;
    color:#fff;
    font-size:20px;
    text-decoration:none;
}
.drawer-mini-btn.green{background:#16a34a;}
.drawer-mini-btn.red{background:#df2f4b;}
.drawer-meta{
    font-size:14px;
    line-height:1.6;
    color:#333;
}
.drawer-meta strong{display:block;margin-top:8px;}
.report-content{
    min-width:0;
    padding:13px 15px 78px;
}
.page-title-card{
    background:var(--rv-card);
    border-radius:6px;
    box-shadow:var(--rv-shadow);
    padding:15px 16px;
    margin-bottom:12px;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:12px;
    flex-wrap:wrap;
    border-top:4px solid var(--rv-teal);
}
.page-title-card h3{
    margin:0;
    font-size:22px;
    font-weight:800;
    letter-spacing:.2px;
}
.page-title-card p{
    margin:4px 0 0;
    color:var(--rv-muted);
    font-size:13px;
}
.action-row{
    display:flex;
    gap:7px;
    align-items:center;
    flex-wrap:wrap;
}
.btn,.filter-btn,button{
    display:inline-flex;
    align-items:center;
    justify-content:center;
    gap:6px;
    text-decoration:none;
    padding:9px 12px;
    border:none;
    background:var(--rv-teal);
    color:#fff;
    border-radius:7px;
    cursor:pointer;
    font-size:13px;
    font-weight:700;
    line-height:1;
}
.btn-blue{background:#147bd1}.btn-gray{background:#64748b}.btn-red{background:#dc2626}.btn-dark{background:#111827}.btn-green{background:#16a34a}
.filter-card{
    background:var(--rv-card);
    border-radius:6px;
    box-shadow:var(--rv-shadow);
    border:1px solid var(--rv-border);
    margin-bottom:12px;
    overflow:hidden;
}
.filter-card-head{
    background:#bfefff;
    color:#106277;
    padding:9px 14px;
    font-weight:800;
    display:flex;
    align-items:center;
    justify-content:space-between;
}
.filter-grid{
    display:grid;
    grid-template-columns:repeat(5, minmax(150px, 1fr));
    gap:10px;
    padding:13px;
    align-items:end;
}
.filter-grid label{
    display:block;
    font-size:12px;
    color:#52616a;
    margin-bottom:4px;
    font-weight:700;
}
input,select{
    width:100%;
    padding:9px 9px;
    border:1px solid #d8e1df;
    border-radius:6px;
    background:#fff;
    box-sizing:border-box;
    color:#111827;
    outline:none;
}
input:focus,select:focus{border-color:var(--rv-teal);box-shadow:0 0 0 3px rgba(0,155,133,.12);}
.summary-stack{
    display:grid;
    grid-template-columns:repeat(4, minmax(160px,1fr));
    gap:12px;
    margin-bottom:12px;
}
.summary-card{
    background:#fff;
    border-radius:6px;
    box-shadow:var(--rv-shadow);
    border:1px solid #e2e8e4;
    overflow:hidden;
    min-height:86px;
}
.summary-card .summary-label{
    background:#fff;
    padding:11px 13px 2px;
    color:#20232a;
    font-size:15px;
    font-weight:800;
    text-transform:capitalize;
}
.summary-card .summary-value{
    padding:5px 13px 14px;
    font-size:21px;
    font-weight:900;
    color:var(--rv-green);
    word-break:break-word;
}
.summary-card:nth-child(2n) .summary-value{color:var(--rv-blue);}
.summary-card:nth-child(3n) .summary-value{color:#0f9498;}
.summary-card:nth-child(4n) .summary-value{color:var(--rv-red);}
.note{
    padding:11px 13px;
    border-radius:6px;
    background:#fff7ed;
    color:#9a3412;
    margin-bottom:12px;
    border:1px solid #fed7aa;
    box-shadow:var(--rv-shadow);
}
.report-table-card{
    background:#fff;
    border-radius:6px;
    box-shadow:var(--rv-shadow);
    border:1px solid var(--rv-border);
    overflow:hidden;
}
.table-title-bar{
    background:#bfefff;
    color:#0d69a8;
    padding:10px 14px;
    font-size:15px;
    font-weight:800;
    display:flex;
    align-items:center;
    justify-content:space-between;
    gap:8px;
}
.table-title-bar span:last-child{
    color:#0f766e;
    font-size:12px;
    font-weight:700;
}
.table-wrap{overflow:auto;max-width:100%;}
table{
    width:100%;
    border-collapse:collapse;
    background:#fff;
    min-width:940px;
}
table th,table td{
    border:1px solid #e1e5e8;
    padding:9px 10px;
    font-size:13px;
    vertical-align:top;
    white-space:nowrap;
}
th{
    background:#f2f5f4;
    position:sticky;
    top:0;
    z-index:1;
    text-align:left;
    color:#1f2937;
    font-weight:800;
}
tbody tr:nth-child(even){background:#fbfcfc;}
tbody tr:hover{background:#f0fbf8;}
tfoot td{font-weight:900;background:#f8fafc;color:#111827;}
.mobile-bottom-nav{
    display:none;
    position:fixed;
    left:0;
    right:0;
    bottom:0;
    background:var(--rv-teal);
    color:#fff;
    z-index:80;
    min-height:62px;
    box-shadow:0 -2px 12px rgba(0,0,0,.16);
    border-radius:18px 18px 0 0;
    overflow:hidden;
}
.mobile-bottom-nav a{
    flex:1;
    color:#fff;
    text-decoration:none;
    text-align:center;
    display:flex;
    flex-direction:column;
    align-items:center;
    justify-content:center;
    gap:2px;
    font-size:12px;
    font-weight:600;
}
.mobile-bottom-nav b{font-size:21px;line-height:1;}
.drawer-overlay{display:none;}
@media(max-width:1280px){
    .filter-grid{grid-template-columns:repeat(3, minmax(150px,1fr));}
    .summary-stack{grid-template-columns:repeat(3, minmax(150px,1fr));}
}
@media(max-width:960px){
    .report-app{grid-template-columns:1fr;}
    .report-drawer{
        position:fixed;
        left:0;
        top:56px;
        bottom:0;
        width:310px;
        height:auto;
        transform:translateX(-105%);
        transition:.22s ease;
        box-shadow:6px 0 20px rgba(0,0,0,.22);
        z-index:90;
    }
    body.report-drawer-open .report-drawer{transform:translateX(0);}
    .drawer-overlay{
        display:block;
        position:fixed;
        inset:56px 0 0 0;
        background:rgba(0,0,0,.32);
        z-index:85;
        opacity:0;
        pointer-events:none;
        transition:.2s;
    }
    body.report-drawer-open .drawer-overlay{opacity:1;pointer-events:auto;}
    .summary-stack{grid-template-columns:repeat(2, minmax(150px,1fr));}
    .mobile-bottom-nav{display:flex;}
}
@media(max-width:620px){
    .report-content{padding:10px 8px 78px;}
    .page-title-card{padding:12px;}
    .page-title-card h3{font-size:18px;}
    .filter-grid{grid-template-columns:1fr;padding:10px;}
    .summary-stack{grid-template-columns:1fr;gap:9px;}
    .summary-card{min-height:78px;}
    .summary-card .summary-value{font-size:20px;}
    .report-drawer{width:86vw;max-width:330px;}
    table{min-width:850px;}
    table th,table td{font-size:12px;padding:8px;}
    .action-row .btn{padding:8px 9px;font-size:12px;}
}
@media print{
    .sidebar,.header,.report-mobile-topbar,.report-drawer,.drawer-overlay,.filter-card,.no-print,.mobile-bottom-nav,.action-row{display:none!important;}
    .main,.report-content{padding:0!important;background:#fff!important;}
    .report-app{display:block;}
    .page-title-card,.report-table-card,.summary-card{box-shadow:none;border:0;}
    .summary-stack{grid-template-columns:repeat(4,1fr);}
    table th,table td{font-size:11px;padding:5px;}
}
</style>

<div class="main">
    <div class="report-mobile-topbar no-print">
        <div class="brand-title">
            <button class="report-icon-btn" type="button" onclick="toggleReportDrawer()">☰</button>
            <span>▣ Report</span>
        </div>
        <div class="top-actions">
            <a class="report-icon-btn" href="?">↻</a>
            <button class="report-icon-btn" type="button" onclick="window.print()">🖨</button>
        </div>
    </div>

    <div class="drawer-overlay" onclick="toggleReportDrawer(false)"></div>

    <div class="report-app">
        <aside class="report-drawer no-print">
            <div class="report-menu-title">
                <span>▣ Report</span>
                <small>v2.0</small>
            </div>
            <nav class="report-nav">
                <?php foreach($reports as $key=>$label){
                    $params = $_GET;
                    $params['report'] = $key;
                    unset($params['export']);
                    $url = '?' . http_build_query($params);
                ?>
                    <a href="<?= h($url) ?>" class="<?= $report==$key ? 'active' : '' ?>">
                        <span class="menu-ico">☰</span>
                        <span><?= h($label) ?></span>
                    </a>
                <?php } ?>
            </nav>
            <div class="report-drawer-bottom">
                <div class="drawer-user-row"><span>👤 Admin User</span><span>⌄</span></div>
                <div class="drawer-user-row"><span>⚙ Settings</span><span>⌄</span></div>
                <div class="drawer-power-row">
                    <a class="drawer-mini-btn green" href="index.php">👤</a>
                    <a class="drawer-mini-btn red" href="logout.php">⏻</a>
                </div>
                <div class="drawer-meta">
                    Version: 2.0<br>
                    <span style="color:#15803d;">License: Active</span>
                    <strong>Support:</strong>
                    01619-999966<br>
                    01620-666677
                </div>
            </div>
        </aside>

        <section class="report-content">
            <div class="page-title-card">
                <div>
                    <h3>📊 <?= h($data['title']) ?></h3>
                    <p>Filter, print, CSV export and live summary for selected POS report.</p>
                </div>
                <div class="action-row no-print">
                    <a class="btn btn-gray" href="?">Reset</a>
                    <button class="btn btn-dark" type="button" onclick="window.print()">Print</button>
                    <a class="btn btn-blue" href="?<?= h(http_build_query(array_merge($_GET, ['export'=>'csv']))) ?>">Excel CSV</a>
                </div>
            </div>

            <div class="filter-card no-print">
                <div class="filter-card-head">
                    <span>🔎 Report Filter</span>
                    <span><?= h($data['title']) ?></span>
                </div>
                <form method="GET">
                    <div class="filter-grid">
                        <div>
                            <label>Report Type</label>
                            <select name="report">
                                <?php foreach($reports as $key=>$label){ ?>
                                    <option value="<?= h($key) ?>" <?= selected($report,$key) ?>><?= h($label) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label>From</label>
                            <input type="date" name="from" value="<?= h($from_date) ?>">
                        </div>
                        <div>
                            <label>To</label>
                            <input type="date" name="to" value="<?= h($to_date) ?>">
                        </div>
                        <div>
                            <label>User/Cashier</label>
                            <select name="user_id">
                                <option value="">All Users</option>
                                <?php foreach($users as $u){ ?>
                                    <option value="<?= (int)$u['id'] ?>" <?= selected($_GET['user_id'] ?? '', $u['id']) ?>><?= h($u['name']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label>Staff/SR/Agent</label>
                            <select name="sr_id">
                                <option value="">All Staff/SR</option>
                                <?php foreach($srs as $s){ ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= selected($_GET['sr_id'] ?? '', $s['id']) ?>><?= h($s['sr_name']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label>Area</label>
                            <select name="area_group">
                                <option value="">All Area</option>
                                <?php foreach($areas as $a){ ?>
                                    <option value="<?= h($a['area_group']) ?>" <?= selected($_GET['area_group'] ?? '', $a['area_group']) ?>><?= h($a['area_group']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label>Customer Type</label>
                            <select name="customer_group">
                                <option value="">All Type</option>
                                <option value="retail" <?= selected($_GET['customer_group'] ?? '', 'retail') ?>>Retail</option>
                                <option value="wholesale" <?= selected($_GET['customer_group'] ?? '', 'wholesale') ?>>Wholesale</option>
                                <option value="home_delivery" <?= selected($_GET['customer_group'] ?? '', 'home_delivery') ?>>Home Delivery</option>
                            </select>
                        </div>
                        <div>
                            <label>Customer</label>
                            <select name="customer_id">
                                <option value="">All Customer</option>
                                <?php foreach($customers as $c){ ?>
                                    <option value="<?= (int)$c['id'] ?>" <?= selected($_GET['customer_id'] ?? '', $c['id']) ?>><?= h($c['customer_name']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label>Supplier</label>
                            <select name="supplier_id">
                                <option value="">All Supplier</option>
                                <?php foreach($suppliers as $s){ ?>
                                    <option value="<?= (int)$s['id'] ?>" <?= selected($_GET['supplier_id'] ?? '', $s['id']) ?>><?= h($s['supplier_name']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label>Brand</label>
                            <select name="brand_id">
                                <option value="">All Brand</option>
                                <?php foreach($brands as $b){ ?>
                                    <option value="<?= (int)$b['id'] ?>" <?= selected($_GET['brand_id'] ?? '', $b['id']) ?>><?= h($b['brand_name']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label>Category</label>
                            <select name="category_id">
                                <option value="">All Category</option>
                                <?php foreach($categories as $cat){ ?>
                                    <option value="<?= (int)$cat['id'] ?>" <?= selected($_GET['category_id'] ?? '', $cat['id']) ?>><?= h($cat['category_name']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label>Payment Status</label>
                            <select name="payment_status">
                                <option value="">All Status</option>
                                <option value="pending" <?= selected($_GET['payment_status'] ?? '', 'pending') ?>>Pending</option>
                                <option value="paid" <?= selected($_GET['payment_status'] ?? '', 'paid') ?>>Paid</option>
                                <option value="failed" <?= selected($_GET['payment_status'] ?? '', 'failed') ?>>Failed</option>
                                <option value="refunded" <?= selected($_GET['payment_status'] ?? '', 'refunded') ?>>Refunded</option>
                            </select>
                        </div>
                        <div>
                            <label>Payment Method</label>
                            <select name="payment_method">
                                <option value="">All Method</option>
                                <?php foreach($methods as $m){ ?>
                                    <option value="<?= h($m['payment_method']) ?>" <?= selected($_GET['payment_method'] ?? '', $m['payment_method']) ?>><?= h($m['payment_method']) ?></option>
                                <?php } ?>
                            </select>
                        </div>
                        <div>
                            <label>Search</label>
                            <input type="text" name="q" value="<?= h($_GET['q'] ?? '') ?>" placeholder="item, invoice, phone...">
                        </div>
                        <div>
                            <label>Low Stock Limit</label>
                            <input type="number" name="low_stock" value="<?= (int)$low_stock ?>">
                        </div>
                        <div>
                            <label>Top/Low Limit</label>
                            <input type="number" name="top_limit" value="<?= (int)$top_limit ?>">
                        </div>
                        <div>
                            <label>Row Limit</label>
                            <input type="number" name="limit" value="<?= h($_GET['limit'] ?? 500) ?>">
                        </div>
                        <div>
                            <button class="filter-btn" type="submit">Filter</button>
                        </div>
                    </div>
                </form>
            </div>

            <?php if($data['note']){ ?>
                <div class="note"><?= h($data['note']) ?></div>
            <?php } ?>

            <div class="summary-stack">
                <?php foreach($data['cards'] as $k=>$v){ ?>
                    <div class="summary-card">
                        <div class="summary-label"><?= h($k) ?></div>
                        <div class="summary-value"><?= h($v) ?></div>
                    </div>
                <?php } ?>
            </div>

            <div class="report-table-card">
                <div class="table-title-bar">
                    <span><?= h($data['title']) ?> Details</span>
                    <span>Rows: <?= count($data['rows']) ?></span>
                </div>
                <div class="table-wrap">
                    <table>
                        <thead>
                            <tr>
                                <?php foreach($data['columns'] as $label){ ?>
                                    <th><?= h($label) ?></th>
                                <?php } ?>
                            </tr>
                        </thead>
                        <tbody>
                            <?php if(empty($data['rows'])){ ?>
                                <tr><td colspan="<?= count($data['columns']) ?>">No data found</td></tr>
                            <?php } ?>
                            <?php foreach($data['rows'] as $row){ ?>
                                <tr>
                                    <?php foreach(array_keys($data['columns']) as $key){ ?>
                                        <td>
                                            <?php
                                            $val = $row[$key] ?? '';
                                            if(in_array($key, $data['moneyKeys'], true)) echo taka($val);
                                            else echo h($val);
                                            ?>
                                        </td>
                                    <?php } ?>
                                </tr>
                            <?php } ?>
                        </tbody>
                        <?php if(!empty($data['rows'])){ ?>
                        <tfoot>
                            <tr>
                                <?php $i=0; foreach(array_keys($data['columns']) as $key){ ?>
                                    <td>
                                        <?php
                                        if($i==0) echo 'Total';
                                        elseif(in_array($key, $data['moneyKeys'], true)) echo taka(sumColumn($data['rows'],$key));
                                        elseif(in_array($key, ['sold_qty','purchase_qty','qty','stock','invoice_count','damage_out_qty','damage_return_qty'], true)) echo n2(sumColumn($data['rows'],$key));
                                        else echo '';
                                        $i++;
                                        ?>
                                    </td>
                                <?php } ?>
                            </tr>
                        </tfoot>
                        <?php } ?>
                    </table>
                </div>
            </div>
        </section>
    </div>

    <div class="mobile-bottom-nav no-print">
        <a href="index.php"><b>⌂</b><span>Home</span></a>
        <a href="pos.php"><b>＋</b><span>Sale</span></a>
        <a href="reports.php"><b>▣</b><span>Report</span></a>
    </div>
</div>

<script>
function toggleReportDrawer(force){
    if(force === false){ document.body.classList.remove('report-drawer-open'); return; }
    document.body.classList.toggle('report-drawer-open');
}
document.addEventListener('keydown', function(e){
    if(e.key === 'Escape') document.body.classList.remove('report-drawer-open');
});
</script>

<?php include "footer.php"; ?>
