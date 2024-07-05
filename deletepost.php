<?php include('C:\xampp\htdocs\northit\connection\connect.php');
$id=$_GET['id'];
$sql = "DELETE FROM post WHERE id=$id";
if(mysqli_query($conn,$sql)){
	header("Location:createpost.php");
 }else{
	echo"not deleted";
}

?>