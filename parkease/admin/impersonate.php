<?php
require_once '../config/database.php';
require_once 'includes/auth.php';

// Only super admin can impersonate
if (!hasRole('super_admin')) {
    header('Location: users.php?error=unauthorized');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$user_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get user details
$query = "SELECT * FROM users WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    header('Location: users.php?error=User not found');
    exit();
}

// Log impersonation start
$log = "INSERT INTO impersonation_logs (admin_id, user_id, action) VALUES (:admin_id, :user_id, 'start')";
$log_stmt = $db->prepare($log);
$log_stmt->bindParam(':admin_id', $_SESSION['admin_id']);
$log_stmt->bindParam(':user_id', $user_id);
$log_stmt->execute();

// Store original admin session
$_SESSION['original_admin_id'] = $_SESSION['admin_id'];
$_SESSION['original_admin_name'] = $_SESSION['admin_name'];
$_SESSION['original_admin_role'] = $_SESSION['admin_role'];

// Set user session
$_SESSION['user_id'] = $user['id'];
$_SESSION['user_type'] = $user['user_type'];
$_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
$_SESSION['user_email'] = $user['email'];
$_SESSION['impersonating'] = true;

// Redirect to user dashboard
header('Location: ../dashboard.php?impersonating=1');
exit();
?>