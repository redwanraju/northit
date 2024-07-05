
<?php include('C:\xampp\htdocs\northit\connection\connect.php');?>
<?php
// define variables and set to empty values


$nameErr = $emailErr = $genderErr = $websiteErr = $passErr = $con_passwordErr="";
$name = $email = $gender = $comment = $website = $password=$con_password="";

if ($_SERVER["REQUEST_METHOD"] == "POST") {
  if (empty($_POST["name"])) {
    $nameErr = "Name is required";
  } else {
    $name = test_input($_POST["name"]);
  }
  if (empty($_POST["password"])) {
    $passErr = "password is required";
  } else {
    $password = test_input($_POST["password"]);
  }
  if (empty($_POST["con_password"])) {
    $con_passwordErr = "con_password is required";
  } else {
    $con_password = test_input($_POST["con_password"]);
  }
  if (empty($_POST["email"])) {
    $emailErr = "Email is required";
  } else {
    $email = test_input($_POST["email"]);
  }
  
  if (empty($_POST["website"])) {
    $website = "";
  } else {
    $website = test_input($_POST["website"]);
  }
  
  if (empty($_POST["comment"])) {
    $comment = "";
  } else {
    $comment = test_input($_POST["comment"]);
  }
  
  if (empty($_POST["gender"])) {
    $genderErr = "Gender is required";
  } else {
    $gender = test_input($_POST["gender"]);
  }
}

//if(isset($_POST['submit'])) {}
function test_input($data) {
  $data = trim($data);
  $data = stripslashes($data);
  $data = htmlspecialchars($data);
 return $data;
}


?>
<script>
    if ( window.history.replaceState ) {
        window.history.replaceState( null, null, window.location.href );
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
            <li><a class="dropdown-item" href="../lbs/createlbs.php">lbs</a></li>

            <li><a class="dropdown-item" href="../product\addproduct.php">product</a></li>
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
			<div class="row">
				<div class="col">
				  <!-- Content here -->
				  <form action=""method="POST">

					Name: <input type="text" name="name">
					<span value="<?php echo $name;?>" class="error">* <?php echo $nameErr;?></span>
					<br><br>
					E-mail:
					<input type="text" name="email">
					<span value="<?php echo $email;?>" class="error">* <?php echo $emailErr;?></span>
					<br><br>
					Password:
					<input type="text" name="email">
					<span value="<?php echo $password;?>" class="error">* <?php echo $passErr;?></span>
					<br><br>
					confirm Password:
					<input type="text" name="con_password">
					<span value="<?php echo $con_password;?>" class="error">* <?php echo $con_passwordErr;?></span>
					<br><br>
					Website:
					<input type="text" name="website">
					<span value="<?php echo $website;?>" class="error"><?php echo $websiteErr;?></span>
					<br><br>
					Comment: <textarea name="comment" rows="5" cols="40"><?php echo $comment;?></textarea>
					<br><br>
					Gender:
					<input type="radio" name="gender"<?php if (isset($gender) && $gender=="female") echo "checked";?>
					 value="female">Female
					<input type="radio" name="gender" <?php if (isset($gender) && $gender=="male") echo "checked";?>
					value="male">Male
					<input type="radio" name="gender"<?php if (isset($gender) && $gender=="other") echo "checked";?>
					 value="other">Other
					<span class="error">* <?php echo $genderErr;?></span>
					<br><br>
					<input type="submit" name="submit" value="Submit">

          <p>Alredy have an account? <a href="login.php">Login</a></p>
				</form>
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