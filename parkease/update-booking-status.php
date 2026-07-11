<?php
session_start(); // Start session at the beginning
require_once 'config/database.php';
require_once 'includes/email-functions.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Function to log status changes
function logStatusChange($db, $booking_id, $old_status, $new_status, $user_id) {
    $log_file = __DIR__ . '/logs/booking_status.log';
    $log_dir = dirname($log_file);
    
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $log_entry = "[$timestamp] Booking: $booking_id | User: $user_id | Status: $old_status → $new_status | IP: $ip\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
    
    // Also log to database if table exists
    try {
        $log_query = "INSERT INTO booking_status_logs (booking_id, old_status, new_status, changed_by, ip_address, created_at) 
                      VALUES (:booking_id, :old_status, :new_status, :changed_by, :ip, NOW())";
        $log_stmt = $db->prepare($log_query);
        $log_stmt->bindParam(':booking_id', $booking_id, PDO::PARAM_INT);
        $log_stmt->bindParam(':old_status', $old_status, PDO::PARAM_STR);
        $log_stmt->bindParam(':new_status', $new_status, PDO::PARAM_STR);
        $log_stmt->bindParam(':changed_by', $user_id, PDO::PARAM_INT);
        $log_stmt->bindParam(':ip', $ip, PDO::PARAM_STR);
        $log_stmt->execute();
    } catch (Exception $e) {
        // Silently fail - logging is not critical
        error_log("Failed to log status change to database: " . $e->getMessage());
    }
}

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'owner') {
    $_SESSION['error'] = 'Access denied. Only parking owners can update booking status.';
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    error_log("Database connection failed in update-booking-status.php");
    $_SESSION['error'] = 'System error. Please try again later.';
    header('Location: owner-reservations.php');
    exit();
}

$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$new_status = isset($_GET['status']) ? trim($_GET['status']) : '';

// Validate booking ID
if ($booking_id <= 0) {
    $_SESSION['error'] = 'Invalid booking ID.';
    header('Location: owner-reservations.php');
    exit();
}

// Validate status
$allowed_statuses = ['confirmed', 'active', 'completed', 'cancelled'];
if (!in_array($new_status, $allowed_statuses)) {
    $_SESSION['error'] = 'Invalid status update. Allowed statuses: confirmed, active, completed, cancelled.';
    header('Location: owner-reservations.php');
    exit();
}

// Verify that this booking belongs to one of the owner's spaces
$check_query = "SELECT r.*, ps.owner_id, u.email as customer_email, u.first_name, u.last_name, u.phone,
                ps.name as parking_name, ps.address, ps.city
                FROM reservations r
                JOIN parking_spaces ps ON r.parking_id = ps.id
                JOIN users u ON r.user_id = u.id
                WHERE r.id = :id AND ps.owner_id = :owner_id";

try {
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':id', $booking_id, PDO::PARAM_INT);
    $check_stmt->bindParam(':owner_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $check_stmt->execute();
    $booking = $check_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Booking verification query error: " . $e->getMessage());
    $_SESSION['error'] = 'Database error. Please try again.';
    header('Location: owner-reservations.php');
    exit();
}

if (!$booking) {
    $_SESSION['error'] = 'Booking not found or you do not have permission to update it.';
    header('Location: owner-reservations.php');
    exit();
}

$old_status = $booking['status'];

// Validate status transition
$valid_transitions = [
    'pending' => ['confirmed', 'cancelled'],
    'confirmed' => ['active', 'cancelled'],
    'active' => ['completed', 'cancelled'],
    'completed' => [],
    'cancelled' => []
];

if (!in_array($new_status, $valid_transitions[$old_status] ?? [])) {
    $_SESSION['error'] = "Cannot change status from '$old_status' to '$new_status'. Invalid transition.";
    error_log("Invalid status transition attempt: Booking $booking_id, $old_status → $new_status");
    header('Location: owner-reservations.php');
    exit();
}

// Additional validation for specific status changes
if ($new_status == 'active' && empty($booking['access_pin'])) {
    $_SESSION['error'] = 'Cannot mark as active without generating a PIN first. Please enter PIN first.';
    header('Location: owner/enter-pin.php?reservation=' . $booking_id);
    exit();
}

if ($new_status == 'completed' && $booking['payment_status'] != 'paid') {
    $_SESSION['error'] = 'Cannot mark as completed. Payment has not been confirmed.';
    header('Location: owner-reservations.php');
    exit();
}

// Start transaction
$db->beginTransaction();

try {
    // Update status
    $update_query = "UPDATE reservations SET status = :status, updated_at = NOW() WHERE id = :id";
    $update_stmt = $db->prepare($update_query);
    $update_stmt->bindParam(':status', $new_status, PDO::PARAM_STR);
    $update_stmt->bindParam(':id', $booking_id, PDO::PARAM_INT);
    
    if (!$update_stmt->execute()) {
        throw new Exception("Failed to update booking status");
    }
    
    // If marking as active, also update timer_status
    if ($new_status == 'active') {
        $update_timer = "UPDATE reservations SET timer_status = 'active' WHERE id = :id";
        $timer_stmt = $db->prepare($update_timer);
        $timer_stmt->bindParam(':id', $booking_id, PDO::PARAM_INT);
        $timer_stmt->execute();
    }
    
    // If marking as completed, also update payout status if applicable
    if ($new_status == 'completed' && $booking['owner_payout'] > 0) {
        $update_payout = "UPDATE reservations SET payout_status = 'pending', payout_eligible = 1 WHERE id = :id";
        $payout_stmt = $db->prepare($update_payout);
        $payout_stmt->bindParam(':id', $booking_id, PDO::PARAM_INT);
        $payout_stmt->execute();
    }
    
    $db->commit();
    
    // Log the status change
    logStatusChange($db, $booking_id, $old_status, $new_status, $_SESSION['user_id']);
    
    // Send email notification
    try {
        $emailer = new EmailNotifications($db);
        $emailer->sendStatusUpdate($booking_id, $old_status, $new_status);
        error_log("Status update email sent for booking $booking_id");
    } catch (Exception $e) {
        error_log("Failed to send status update email: " . $e->getMessage());
        // Don't fail the status update if email fails
    }
    
    // Send SMS notification if available (optional)
    if (!empty($booking['phone'])) {
        try {
            // Placeholder for SMS integration
            // $sms = new SmsService();
            // $sms->sendStatusUpdate($booking['phone'], $booking['booking_reference'], $new_status);
            error_log("SMS would be sent to {$booking['phone']} for booking $booking_id");
        } catch (Exception $e) {
            error_log("Failed to send SMS: " . $e->getMessage());
        }
    }
    
    $_SESSION['success'] = "Booking #{$booking['booking_reference']} has been updated from " . ucfirst($old_status) . " to " . ucfirst($new_status) . ".";
    
} catch (PDOException $e) {
    $db->rollBack();
    error_log("Database error in update-booking-status.php: " . $e->getMessage());
    $_SESSION['error'] = 'Database error occurred. Please try again.';
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("General error in update-booking-status.php: " . $e->getMessage());
    $_SESSION['error'] = 'An error occurred. Please try again.';
}

// Redirect based on where the request came from
$referer = isset($_SERVER['HTTP_REFERER']) ? $_SERVER['HTTP_REFERER'] : '';
if (strpos($referer, 'reservation-details.php') !== false) {
    header('Location: reservation-details.php?id=' . $booking_id);
} else {
    header('Location: owner-reservations.php');
}
exit();
?>