
<?php include('C:\xampp\htdocs\northit\connection\connect.php');?>

<script>
function sum() {
            var txtFirstNumberValue = document.getElementById('txt1').value;
            var txtSecondNumberValue = document.getElementById('txt2').value;
            var result = parseInt(txtFirstNumberValue) - parseInt(txtSecondNumberValue);
            if (!isNaN(result)) {
                document.getElementById('txt3').value = result;
				
            }
			
			 var txtFirstNumberValue = document.getElementById('txt11').value;
            var result = parseInt(txtFirstNumberValue);
            if (!isNaN(result)) {
                document.getElementById('txt22').value = result;				
            }
			
			 var txtFirstNumberValue = document.getElementById('txt11').value;
            var txtSecondNumberValue = document.getElementById('txt33').value;
            var result = parseInt(txtFirstNumberValue) + parseInt(txtSecondNumberValue);
            if (!isNaN(result)) {
                document.getElementById('txt55').value = result;
				
            }
			
			 var txtFirstNumberValue = document.getElementById('txt4').value;
			 var result = parseInt(txtFirstNumberValue);
            if (!isNaN(result)) {
                document.getElementById('txt5').value = result;
				}
			
        }
</script>
<!doctype html>
<html lang="en">
  <head>
    <!-- Required meta tags -->
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <!-- Bootstrap CSS -->
    <link href="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/css/bootstrap.min.css" rel="stylesheet" integrity="sha384-EVSTQN3/azprG1Anm3QDgpJLIm9Nao0Yz1ztcQTwFspd3yD65VohhpuuCOmLASjC" crossorigin="anonymous">

    <title>Hello, world!</title>
  </head>
  <body>
  <nav class="navbar navbar-expand-lg navbar-light bg-info">
  <div class="container-fluid">
    <a class="navbar-brand" href="#">North-it</a>
    <button class="navbar-toggler" type="button" data-bs-toggle="collapse" data-bs-target="#navbarSupportedContent" aria-controls="navbarSupportedContent" aria-expanded="false" aria-label="Toggle navigation">
      <span class="navbar-toggler-icon"></span>
    </button>
    <div class="collapse navbar-collapse" id="navbarSupportedContent">
      <ul class="navbar-nav me-auto mb-2 mb-lg-0">
        <li class="nav-item">
          <a class="nav-link active" aria-current="page" href="../index.php">DASHBOARD</a>
        </li>

        <li class="nav-item dropdown">
          <a class="nav-link dropdown-toggle" href="#" id="navbarDropdown" role="button" data-bs-toggle="dropdown" aria-expanded="false">
            add
          </a>
          <ul class="dropdown-menu" aria-labelledby="navbarDropdown">
            <li>
              <a class="dropdown-item" href="../category\createcategory.php">category</a>
		            </li>
					      <li><hr class="dropdown-divider"></li>
		            <li><a class="dropdown-item" href="../suplier/createsuplier.php">suplier</a></li>
		            <li><hr class="dropdown-divider"></li>
		            <li><a class="dropdown-item" href="../lbs/createlbs.php">lbs</a></li>
		          </ul>
		        </li>

      </ul>
      <form class="d-flex">
        <input class="form-control me-2" type="search" placeholder="Search" aria-label="Search">
        <button class="btn btn-outline-success" type="submit">Search</button>
      </form>
    </div>
  </div>
</nav>
		<div class="container">
			<div class="">
				<div class="col">

				  <!-- Content here -->
				  <form action="http://localhost/northit/product/processproduct.php"method="POST">
				  <div class="row">
				  	<div class="col">
				    <p><span class="text-danger">* required </span></p>
				    <label for="">date</label>
				    <input type="date" class="form-control" id="" aria-describedby="" placeholder="Enter date" name="date" required>
				    </div>
				  
				  	<div class="col">
				    <p><span class="text-danger">* required </span></p>
				    <label for="">product Name</label>
				    <input type="text" class="form-control" id="" aria-describedby="" placeholder="product name" name="name" required>
				    </div>
				  
  
				  	<div class="col">
				  	<p><span class="text-danger">* required </span></p>
				    <label for="exampleFormControlSelect1"> select category</label>
				    <select name="topic_id"  class="form-control" id="exampleFormControlSelect1">
    						<?php 
						  	$sql="select * from topic";
								$result= mysqli_query($conn,$sql);
						  	while ($row=mysqli_fetch_assoc($result)){?>
                <option><?php echo $row['topicname']; ?></option>
                <?php  } ?>
				    </select>
				    </div>
				 

    
				  	<div class="col">
				  		<p><span class="text-danger">* required </span></p>
					    <label for="exampleFormControlSelect1">select suplier</label>
					    <select name="suplier_id"class="form-control" id="exampleFormControlSelect1">
          			<?php 
						  	$sql="select * from suplier";
								$result= mysqli_query($conn,$sql);
						  	while ($row=mysqli_fetch_assoc($result)){?>
                <option><?php echo $row['name']; ?></option>
                <?php  } ?>

				    </select>
				   </div>
				 
				  	<div class="col">
				    <p><span class="text-danger">* required</span></p>
				    <label for="">qty</label>
				    <input type="number" class="form-control" id="" aria-describedby="" placeholder="Enter qty" name="qty" required>
				  </div>
				  	
				  	<div class="col">
				    <p><span class="text-danger">* required </span></p>
				    <label for="">price</label>
				    <input type="number" class="form-control" id="" aria-describedby="" placeholder="Enter price" name="price" required>
				    </div>
				  	<div class="col">
				    <p><span class="text-danger">* required </span></p>
				    <label for="">total price</label>
				    <input type="number" class="form-control" id="" aria-describedby="" placeholder="readonly" name="" readonly >
				    </div>
				 
				  	

				   </div>
				  <button type="submit" class="btn btn-primary">Submit</button>
				</form>
			</div>
	      </div>
		</div>


		<div class="container">
			<div class="row">
				<div class="col">
					<div class="table-responsive-sm">
					  <table class="table table-hover table-bordered">
						  <thead>
						  
						    <tr>
						      <th scope="col">তারিখ </th>
						      <th scope="col">পণ্যের নাম</th>
						      <th scope="col">ক্যাটেগরি  </th>
						      <th scope="col">সরবরহকারি </th>
						      <th scope="col">পরিমাণ </th>
						      <th scope="col">দর </th>
						      <th scope="col">মোট দাম </th>
						      <th scope="col">ষ্টক </th>
						      <th scope="col">Action</th>
						    </tr>
						  </thead>
						  <tbody>
						  <?php 
						    //$qty = $_POST["qty"];
  							//$price = $_POST["price"];
						  	$sql="select * from product";
								$result= mysqli_query($conn,$sql);
						  	while ($row=mysqli_fetch_assoc($result)){?>
						    <tr>
						      <td><?php echo $row['date']?></td>
						      <td><?php echo $row['name']?></td>
						      <td><?php echo $row['topic_id']?></td>
						      <td><?php echo $row['suplier_id']?></td>
						      <td><?php echo $row['qty']?></td>
						      <td><?php echo $row['price']?></td>
						      <td><?php echo $row['totalprice']?></td>
						      <td><?php echo $row['qty']?></td>
						      <td>
						      	<a class="btn-btn-danger" href="deleteproduct.php?id=<?php echo $row['id']?>"><button>delete</button></a>
						      	
						     </td>
						    </tr>
						     <?php  } ?>
						  </tbody>
						</table>
					</div>
				</div>
	    </div>
		</div>
    <!-- Option 1: Bootstrap Bundle with Popper -->
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.bundle.min.js" integrity="sha384-MrcW6ZMFYlzcLA8Nl+NtUVF0sA7MsXsP1UyJoMp4YLEuNSfAP+JcXn/tWtIaxVXM" crossorigin="anonymous"></script>

    <!-- Option 2: Separate Popper and Bootstrap JS -->
    <!--
    <script src="https://cdn.jsdelivr.net/npm/@popperjs/core@2.9.2/dist/umd/popper.min.js" integrity="sha384-IQsoLXl5PILFhosVNubq5LC7Qb9DXgDA9i+tQ8Zj3iwWAwPtgFTxbJ8NT4GN1R8p" crossorigin="anonymous"></script>
    <script src="https://cdn.jsdelivr.net/npm/bootstrap@5.0.2/dist/js/bootstrap.min.js" integrity="sha384-cVKIPhGWiC2Al4u+LWgxfKTRIcfu0JTxR+EQDz/bgldoEyl4H0zUF0QKbrJ0EcQF" crossorigin="anonymous"></script>
    -->
  </body>
</html>