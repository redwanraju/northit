<?php include('C:\xampp\htdocs\northit\connection\connect.php');?>
<?php
// define variables and set to empty values

$category_id="";
$title="";
$description = "";


if ($_SERVER["REQUEST_METHOD"] == "POST") {
	$category_id = test_input($_POST["category_id"]);
	$title = test_input($_POST["title"]);
	$description = test_input($_POST["description"]);
	
  //$description=$_POST['description'];
  //$category_id=$_POST['category_id'];
 
}

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