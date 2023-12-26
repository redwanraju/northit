<?php include('C:\xampp\htdocs\northit\connection\connect.php');?>
<?php
// define variables and set to empty values

$date = "";
$name = "";
$topic_id = "";
$suplier_id = "";
$qty = "";
$price = "";
$totalprice = "";
$stock = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $date = test_input($_POST["date"]);
  $name = test_input($_POST["name"]);
  $topic_id = test_input($_POST["topic_id"]);
  $suplier_id = test_input($_POST["suplier_id"]);
  $qty = test_input($_POST["qty"]);
  $price = test_input($_POST["price"]);
  $totalprice = test_input($_POST["totalprice"]);
  $stock = test_input($_POST["stock"]);
}

function test_input($data) {
  $data = trim($data);
  $data = stripslashes($data);
  $data = htmlspecialchars($data);
  return $data;
}



$sql="INSERT INTO `product`VALUES (NULL, '$date', '$name', '$topic_id', '$suplier_id', '$qty', '$price', '$totalprice', '$stock', '');";

$result= mysqli_query($conn,$sql);

header("Location:addproduct.php");

?>