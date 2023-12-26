<?php include('C:\xampp\htdocs\northit\connection\connect.php');?>
<?php
// define variables and set to empty values

$topicname = "";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  $topicname = test_input($_POST["topicname"]);
}
//if(isset($_POST['submit'])) {}
function test_input($data) {
  $data = trim($data);
  $data = stripslashes($data);
  $data = htmlspecialchars($data);
  return $data;
}

$sql="INSERT INTO topic VALUES (NULL,'$topicname')";
$result= mysqli_query($conn,$sql);
header("Location:createcategory.php");

?>