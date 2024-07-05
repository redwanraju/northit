<?php include('C:\xampp\htdocs\northit\connection\connect.php');?>
<?php
// define variables and set to empty values

$fname="";
$lname="";
$email = "";
$password = "";
$password_again = "";

if(isset($_POST['submit'])) {
if ($_SERVER["REQUEST_METHOD"] == "POST") {
	$fname = test_input($_POST["fname"]);
	$lname = test_input($_POST["lname"]);
	$email = test_input($_POST["email"]);
    $password = test_input($_POST["password"]);
    $password = test_input($_POST["password"]);

}
if(empty(fname)){
    $deliver_error_message='fill up this field';
}
}


//$description=$_POST['description'];
  //$category_id=$_POST['category_id'];
 

//if(isset($_POST['submit'])) {}
function test_input($data) {
  $data = trim($data);
  $data = stripslashes($data);
  $data = htmlspecialchars($data);
 return $data;
}

$sql="INSERT INTO `post` VALUES (NULL,'$title','$description','$category_id')";
$result= mysqli_query($conn,$sql);
header("Location:createpost.php");

?>