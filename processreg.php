<?php include('C:\xampp\htdocs\northit\connection\connect.php');?>
<?php
// define variables and set to empty values

$name = $email = $gender = $comment = $website = $password=$con_password="";

if(isset($_POST['submit'])) {
if ($_SERVER["REQUEST_METHOD"] == "POST") {
	$name = test_input($_POST["name"]);
	$email = test_input($_POST["email"]);
	$gender = test_input($_POST["gender"]);
    $comment = test_input($_POST["comment"]);
    $website = test_input($_POST["website"]);
	$password = test_input($_POST["password"]);
	$con_password = test_input($_POST["con_password"]);

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

$sql="INSERT INTO `user` VALUES (NULL,'$name','$email','$gender','$comment','$website','$password','$con_password')";
$result= mysqli_query($conn,$sql);
header("Location:register.php");

?>