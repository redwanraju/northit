<?php include('C:\xampp\htdocs\northit\connection\connect.php');
$id=$_GET['id'];
$sql = "DELETE FROM lbs WHERE id=$id";
if(mysqli_query($conn,$sql)){
	header("Location:createlbs.php");
 }else{
	echo"not deleted";
}

?>