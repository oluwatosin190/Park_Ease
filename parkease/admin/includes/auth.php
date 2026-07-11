<?php
/**
 * Admin Authentication
 */

// Only start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in as admin
function isAdminLoggedIn() {
    return isset($_SESSION['admin_id']) && isset($_SESSION['admin_role']);
}

// Require admin login
function requireAdminLogin() {
    if (!isAdminLoggedIn()) {
        header('Location: login.php');
        exit();
    }
}

// Check if admin has specific role
function hasRole($roles) {
    if (!isAdminLoggedIn()) return false;
    
    if (is_array($roles)) {
        return in_array($_SESSION['admin_role'], $roles);
    }
    
    return $_SESSION['admin_role'] == $roles;
}

// Log admin action
function logAdminAction($db, $action, $details = '') {
    if (!isset($_SESSION['admin_id'])) return false;
    
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    
    $query = "INSERT INTO admin_logs (admin_id, action, details, ip_address) 
              VALUES (:admin_id, :action, :details, :ip)";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':admin_id', $_SESSION['admin_id']);
    $stmt->bindParam(':action', $action);
    $stmt->bindParam(':details', $details);
    $stmt->bindParam(':ip', $ip);
    return $stmt->execute();
}
?>