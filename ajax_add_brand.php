<?php
require_once __DIR__ . '/tenant_guard.php';
requireTenantSession(true);


include "../config/db.php";

$shop_id = tenantShopId();$name=trim($_POST['brand_name']);

$stmt=$conn->prepare("
INSERT INTO brands
(shop_id,brand_name,status)
VALUES(?,?,1)
");

$stmt->bind_param("is",$shop_id,$name);

$stmt->execute();

$id=$stmt->insert_id;

echo json_encode([

"id"=>$id,

"name"=>$name

]);