<?php
$conn = mysqli_connect('localhost', 'root','' ,'mydb');
// Check connection
if (!$conn) {
 die("Connection failed: ".$mysqli_error());
} 
?>