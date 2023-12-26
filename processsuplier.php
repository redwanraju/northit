<?php include('C:\xampp\htdocs\northit\connection\connect.php');?>
<?php
// define variables and set to empty values

$name = "";
$address = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $name = test_input($_POST["name"]);
  $address = test_input($_POST["address"]);
}
//if(isset($_POST['submit'])) {}
function test_input($data) {
  $data = trim($data);
  $data = stripslashes($data);
  $data = htmlspecialchars($data);
  return $data;
}

$sql="INSERT INTO suplier VALUES (NULL,'$name','$address')";
$result= mysqli_query($conn,$sql);
header("Location:createsuplier.php");

?>