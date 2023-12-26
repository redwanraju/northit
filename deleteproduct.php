<?php include('C:\xampp\htdocs\northit\connection\connect.php');
$id=$_GET['id'];
$sql = "DELETE FROM product WHERE id=$id";
if(mysqli_query($conn,$sql)){
	header("Location:addproduct.php");
 }else{
	echo"not deleted";
}

?>





