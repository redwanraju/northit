<?php
// pos/auth_helper.php - সিম্পল অথ হেল্পার
require_once __DIR__ . '/tenant_guard.php';

// Database Connection
include_once '../config/db.php';

// ============================================================
// LOGIN REQUIRED
// ============================================================

function requireLogin() {
    requireTenantSession(false);
}

// ============================================================
// USER FUNCTIONS
// ============================================================

function getUserById($id) {
    global $conn;
    $id = (int)$id;
    
    $stmt = $conn->prepare("SELECT * FROM users WHERE id = ? AND shop_id = ?");
    $shop_id = tenantShopId();
    $stmt->bind_param("ii", $id, $shop_id);
    $stmt->execute();
    return $stmt->get_result()->fetch_assoc();
}

function getAllUsers() {
    global $conn;
    $shop_id = tenantShopId();
    $stmt = $conn->prepare("SELECT * FROM users WHERE shop_id = ? ORDER BY id DESC");
    $stmt->bind_param("i", $shop_id);
    $stmt->execute();
    return $stmt->get_result();
}

function createUser($name, $email, $password, $role) {
    global $conn;
    $hashed = password_hash($password, PASSWORD_BCRYPT);
    
    $stmt = $conn->prepare("INSERT INTO users (shop_id, name, email, password, role) VALUES (?, ?, ?, ?, ?)");
    $shop_id = tenantShopId();
    $stmt->bind_param("issss", $shop_id, $name, $email, $hashed, $role);
    return $stmt->execute();
}

function updateUser($id, $name, $email, $role, $password = null) {
    global $conn;
    
    if($password) {
        $hashed = password_hash($password, PASSWORD_BCRYPT);
        $stmt = $conn->prepare("UPDATE users SET name=?, email=?, password=?, role=? WHERE id=? AND shop_id=?");
        $shop_id = tenantShopId();
        $stmt->bind_param("ssssii", $name, $email, $hashed, $role, $id, $shop_id);
    } else {
        $stmt = $conn->prepare("UPDATE users SET name=?, email=?, role=? WHERE id=? AND shop_id=?");
        $shop_id = tenantShopId();
        $stmt->bind_param("sssii", $name, $email, $role, $id, $shop_id);
    }
    return $stmt->execute();
}

function deleteUser($id) {
    global $conn;
    $stmt = $conn->prepare("DELETE FROM users WHERE id=? AND shop_id=?");
    $shop_id = tenantShopId();
    $stmt->bind_param("ii", $id, $shop_id);
    return $stmt->execute();
}

function isAdmin() {
    return isset($_SESSION['role']) && $_SESSION['role'] == 'admin';
}
?>