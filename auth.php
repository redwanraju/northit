<?php
require_once __DIR__ . '/tenant_guard.php';

/* ================= LOGIN REQUIRED ================= */
function requireLogin(){
    requireTenantSession(false);
}

/* ================= ROLE CHECK (OPTIONAL) ================= */
function requireRole($role){
    if(!isset($_SESSION['role']) || $_SESSION['role'] != $role){
        header("Location: login.php");
        exit;
    }
}
?>