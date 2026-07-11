<?php
session_start(); // Start session at the beginning
require_once 'includes/user-access.php';
require_once 'config/database.php';

// Function to sanitize output (for logging)
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Function to log actions
function logAction($db, $user_id, $action, $details = null) {
    try {
        // Check if admin_logs table exists, create if not
        $table_check = $db->query("SHOW TABLES LIKE 'admin_logs'");
        if ($table_check->rowCount() == 0) {
            $create_table = "CREATE TABLE IF NOT EXISTS admin_logs (
                id INT AUTO_INCREMENT PRIMARY KEY,
                user_id INT,
                action VARCHAR(100),
                details TEXT,
                ip_address VARCHAR(45),
                created_at DATETIME DEFAULT CURRENT_TIMESTAMP
            )";
            $db->exec($create_table);
        }
        
        $log_query = "INSERT INTO admin_logs (user_id, action, details, ip_address, created_at) 
                      VALUES (:user_id, :action, :details, :ip, NOW())";
        $log_stmt = $db->prepare($log_query);
        $log_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $log_stmt->bindParam(':action', $action, PDO::PARAM_STR);
        $log_stmt->bindParam(':details', $details, PDO::PARAM_STR);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $log_stmt->bindParam(':ip', $ip, PDO::PARAM_STR);
        $log_stmt->execute();
    } catch (Exception $e) {
        error_log("Failed to log action: " . $e->getMessage());
    }
}

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'owner') {
    $_SESSION['error'] = 'Access denied. Only parking space owners can delete spaces.';
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Validate ID
if ($id <= 0) {
    $_SESSION['error'] = 'Invalid parking space ID.';
    header('Location: dashboard.php');
    exit();
}

try {
    // Start transaction for data consistency
    $db->beginTransaction();
    
    // First, check if the space exists and belongs to the owner
    $query = "SELECT id, name, images, owner_id FROM parking_spaces WHERE id = :id AND owner_id = :owner_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $stmt->bindParam(':owner_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $space = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$space) {
        $db->rollBack();
        $_SESSION['error'] = 'Parking space not found or you do not have permission to delete it.';
        header('Location: dashboard.php');
        exit();
    }
    
    // Check if there are any active reservations for this space
    $check_reservations = "SELECT COUNT(*) as count FROM reservations 
                           WHERE parking_id = :parking_id 
                           AND status IN ('confirmed', 'active', 'pending')
                           AND end_date > NOW()";
    $res_stmt = $db->prepare($check_reservations);
    $res_stmt->bindParam(':parking_id', $id, PDO::PARAM_INT);
    $res_stmt->execute();
    $active_reservations = $res_stmt->fetch(PDO::FETCH_ASSOC)['count'];
    
    if ($active_reservations > 0) {
        $db->rollBack();
        $_SESSION['error'] = "Cannot delete this parking space because it has $active_reservations active reservation(s). Please wait for all reservations to complete first.";
        header('Location: dashboard.php');
        exit();
    }
    
    // Delete images from server
    $deleted_images = 0;
    $failed_images = 0;
    
    if (!empty($space['images'])) {
        $images = json_decode($space['images'], true);
        if (is_array($images) && !empty($images)) {
            foreach ($images as $image) {
                $file_path = 'uploads/parking/' . $image;
                if (file_exists($file_path)) {
                    if (unlink($file_path)) {
                        $deleted_images++;
                    } else {
                        $failed_images++;
                        error_log("Failed to delete image: $file_path for space ID: $id");
                    }
                }
            }
        }
    }
    
    // Delete from database
    $delete_query = "DELETE FROM parking_spaces WHERE id = :id AND owner_id = :owner_id";
    $delete_stmt = $db->prepare($delete_query);
    $delete_stmt->bindParam(':id', $id, PDO::PARAM_INT);
    $delete_stmt->bindParam(':owner_id', $_SESSION['user_id'], PDO::PARAM_INT);
    
    if ($delete_stmt->execute()) {
        $db->commit();
        
        // Log the deletion
        $log_details = "Parking space deleted: ID {$space['id']}, Name: {$space['name']}, Images deleted: $deleted_images, Failed: $failed_images";
        logAction($db, $_SESSION['user_id'], 'delete_parking_space', $log_details);
        
        // Set success message with details
        $_SESSION['success'] = "Parking space '{$space['name']}' has been deleted successfully.";
        if ($deleted_images > 0) {
            $_SESSION['success'] .= " $deleted_images image(s) removed.";
        }
        if ($failed_images > 0) {
            $_SESSION['warning'] = "Warning: $failed_images image(s) could not be deleted from the server.";
        }
        
        error_log("Parking space deleted - ID: {$space['id']}, Name: {$space['name']}, Owner: {$_SESSION['user_id']}");
        
    } else {
        $db->rollBack();
        error_log("Failed to delete parking space ID: $id, Owner: {$_SESSION['user_id']}");
        $_SESSION['error'] = 'Failed to delete parking space. Please try again.';
    }
    
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Database error in delete-parking.php: " . $e->getMessage());
    $_SESSION['error'] = 'A database error occurred. Please try again later.';
    
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Unexpected error in delete-parking.php: " . $e->getMessage());
    $_SESSION['error'] = 'An unexpected error occurred. Please try again later.';
}

// Redirect back to dashboard
header('Location: dashboard.php');
exit();
?>