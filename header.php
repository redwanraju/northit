<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1">

<!-- Bootstrap CSS -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/css/bootstrap.min.css" rel="stylesheet">

<!-- Bootstrap Icons -->
<link href="https://cdn.jsdelivr.net/npm/bootstrap-icons@1.11.1/font/bootstrap-icons.css" rel="stylesheet">

<!-- Font Awesome -->
<link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.4.2/css/all.min.css">

<!-- Select2 CSS -->
<link href="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/css/select2.min.css" rel="stylesheet">

<!-- jQuery (এটা আগে থাকবে) -->
<script src="https://code.jquery.com/jquery-3.7.1.min.js"></script>

<!-- Bootstrap JS -->
<script src="https://cdn.jsdelivr.net/npm/bootstrap@5.3.2/dist/js/bootstrap.bundle.min.js"></script>

<!-- Select2 JS -->
<script src="https://cdn.jsdelivr.net/npm/select2@4.1.0-rc.0/dist/js/select2.min.js"></script>

<style>
body{
    margin:0;
    font-family:Segoe UI;
    background:#f5f6f8;
}

.topbar{
    height:60px;
    background:#0f172a;
    color:#fff;
    display:flex;
    justify-content:space-between;
    align-items:center;
    padding:0 15px;
}

.wrapper{
    display:flex;
    min-height:calc(100vh - 60px);
}

/* MOBILE SAFE */
@media(max-width:768px){
    .wrapper{
        display:block;
    }
}
</style>

</head>

<body>

<div class="topbar">
    

    <!-- USER INFO -->
    <div style="display:flex; gap:10px; align-items:center; color:#fff;">

        <?php
        if (session_status() === PHP_SESSION_NONE) {
            session_start();
        }

        $name = $_SESSION['name'] ?? 'Guest';
        $role = $_SESSION['role'] ?? 'User';
        ?>
<!--
        <span style="background:#1f2937; padding:5px 10px; border-radius:20px; font-size:13px;">
            👤 <?= htmlspecialchars($name) ?>
        </span>

        <span style="background:#facc15; color:#000; padding:5px 10px; border-radius:20px; font-size:12px;">
            <?= strtoupper(htmlspecialchars($role)) ?>
        </span>

-->


    </div>

</div>

<div class="wrapper">