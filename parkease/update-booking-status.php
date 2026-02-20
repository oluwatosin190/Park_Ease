<?php
require_once 'config/database.php';

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$new_status = isset($_GET['status']) ? $_GET['status'] : '';

// Validate status
$allowed_statuses = ['confirmed', 'active', 'completed', 'cancelled'];
if (!in_array($new_status, $allowed_statuses)) {
    $_SESSION['error'] = 'Invalid status update.';
    header('Location: owner-reservations.php');
    exit();
}

// Verify that this booking belongs to one of the owner's spaces
$check_query = "SELECT r.*, ps.owner_id, u.email as customer_email, u.first_name, u.last_name 
                FROM reservations r
                JOIN parking_spaces ps ON r.parking_id = ps.id
                JOIN users u ON r.user_id = u.id
                WHERE r.id = :id AND ps.owner_id = :owner_id";
$check_stmt = $db->prepare($check_query);
$check_stmt->bindParam(':id', $booking_id);
$check_stmt->bindParam(':owner_id', $_SESSION['user_id']);
$check_stmt->execute();
$booking = $check_stmt->fetch(PDO::FETCH_ASSOC);

if (!$booking) {
    $_SESSION['error'] = 'Booking not found.';
    header('Location: owner-reservations.php');
    exit();
}

// Update status
$update_query = "UPDATE reservations SET status = :status WHERE id = :id";
$update_stmt = $db->prepare($update_query);
$update_stmt->bindParam(':status', $new_status);
$update_stmt->bindParam(':id', $booking_id);

if ($update_stmt->execute()) {
    $_SESSION['success'] = "Booking #{$booking['booking_reference']} has been {$new_status}.";
    
    // After updating status, send email notification
require_once 'includes/email-functions.php';
$emailer = new EmailNotifications($db);
$emailer->sendStatusUpdate($booking_id, $old_status, $new_status);
} else {
    $_SESSION['error'] = 'Failed to update booking status.';
}

header('Location: owner-reservations.php');
exit();
?>