<?php include('C:\xampp\htdocs\northit\connection\connect.php');
$id=$_GET['id'];
$sql = "DELETE FROM suplier WHERE id=$id";
if(mysqli_query($conn,$sql)){
	header("Location:createsuplier.php");
 }else{
	echo"not deleted";
}

?>