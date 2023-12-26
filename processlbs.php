<?php include('C:\xampp\htdocs\northit\connection\connect.php');?>
<?php
// define variables and set to empty values

$kg = "";
$ltr = "";
$gm = "";
$bosta = "";
if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $kg = test_input($_POST["kg"]);
   $ltr = test_input($_POST["ltr"]);
   $gm = test_input($_POST["gm"]);
   $bosta = test_input($_POST["bosta"]);

}
//if(isset($_POST['submit'])) {}
function test_input($data) {
  $data = trim($data);
  $data = stripslashes($data);
  $data = htmlspecialchars($data);
  return $data;
}

$sql="INSERT INTO lbs VALUES (NULL,'$kg','$ltr','$gm','$bosta')";
$result= mysqli_query($conn,$sql);
header("Location:createlbs.php");

?>