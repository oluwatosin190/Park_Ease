<?php
session_start();

// Log logout if admin was logged in
if (isset($_SESSION['admin_id']) && isset($_SESSION['admin_name'])) {
    require_once '../config/database.php';
    $database = new Database();
    $db = $database->getConnection();
    
    $log = "INSERT INTO admin_logs (admin_id, action, ip_address) 
            VALUES (:id, 'logout', :ip)";
    $stmt = $db->prepare($log);
    $stmt->bindParam(':id', $_SESSION['admin_id']);
    $stmt->bindParam(':ip', $_SERVER['REMOTE_ADDR']);
    $stmt->execute();
}

// Destroy session
session_destroy();

// Redirect to login
header('Location: login.php');
exit();
?>