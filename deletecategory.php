<?php include('C:\xampp\htdocs\northit\connection\connect.php');
$id=$_GET['id'];
$sql = "DELETE FROM topic WHERE id=$id";
if(mysqli_query($conn,$sql)){
	header("Location:createcategory.php");
 }else{
	echo"not deleted";
}

?>





