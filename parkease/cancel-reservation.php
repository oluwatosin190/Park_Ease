<?php
session_start(); // Start session at the beginning
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Function to log actions
function logAction($db, $user_id, $action, $details = null) {
    try {
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

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please log in to cancel a reservation.';
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($reservation_id <= 0) {
    $_SESSION['error'] = 'Invalid reservation ID.';
    header('Location: my-reservations.php');
    exit();
}

// Start transaction for data consistency
try {
    $db->beginTransaction();
    
    // Get reservation details to check ownership and cancellation eligibility
    $query = "SELECT r.*, 
              u.first_name, u.last_name, u.email,
              ps.name as parking_name,
              ps.owner_id,
              owner.email as owner_email,
              owner.first_name as owner_first_name
              FROM reservations r
              JOIN users u ON r.user_id = u.id
              JOIN parking_spaces ps ON r.parking_id = ps.id
              JOIN users owner ON ps.owner_id = owner.id
              WHERE r.id = :id AND r.user_id = :user_id";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $reservation_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reservation) {
        $db->rollBack();
        $_SESSION['error'] = 'Reservation not found or you do not have permission to cancel it.';
        header('Location: my-reservations.php');
        exit();
    }
    
    // Check if reservation can be cancelled (only pending or confirmed, and not already cancelled/completed)
    $allowed_statuses = ['pending', 'confirmed'];
    if (!in_array($reservation['status'], $allowed_statuses)) {
        $db->rollBack();
        $_SESSION['error'] = 'This reservation cannot be cancelled because it is already ' . $reservation['status'] . '.';
        header('Location: reservation-details.php?id=' . $reservation_id);
        exit();
    }
    
    // Check if cancellation is allowed (within 1 hour of start time)
    $start = new DateTime($reservation['start_date']);
    $now = new DateTime();
    $cancellation_deadline = clone $start;
    $cancellation_deadline->modify('-1 hour'); // Can cancel up to 1 hour before start
    
    if ($now > $cancellation_deadline) {
        $db->rollBack();
        $_SESSION['error'] = 'This reservation cannot be cancelled. Cancellations are allowed up to 1 hour before the start time.';
        header('Location: reservation-details.php?id=' . $reservation_id);
        exit();
    }
    
    // Check if timer has already started
    if ($reservation['timer_status'] == 'active' || $reservation['timer_status'] == 'pending_checkout') {
        $db->rollBack();
        $_SESSION['error'] = 'Cannot cancel a reservation after the parking session has started.';
        header('Location: reservation-details.php?id=' . $reservation_id);
        exit();
    }
    
    // Calculate refund amount (if applicable)
    $refund_amount = $reservation['total_amount'];
    $commission_rate = $reservation['commission_rate'] ?? 15;
    $platform_fee = $reservation['commission_amount'] ?? ($refund_amount * $commission_rate / 100);
    
    // Update reservation status with cancellation timestamp
    $update_query = "UPDATE reservations SET 
                      status = 'cancelled', 
                      payment_status = 'refunded',
                      cancelled_at = NOW(),
                      cancellation_reason = :reason,
                      refund_amount = :refund_amount,
                      platform_fee_retained = :platform_fee
                      WHERE id = :id";
    
    $update_stmt = $db->prepare($update_query);
    $cancellation_reason = isset($_POST['cancellation_reason']) ? substr(trim($_POST['cancellation_reason']), 0, 500) : 'Cancelled by user';
    $update_stmt->bindParam(':reason', $cancellation_reason, PDO::PARAM_STR);
    $update_stmt->bindParam(':refund_amount', $refund_amount, PDO::PARAM_STR);
    $update_stmt->bindParam(':platform_fee', $platform_fee, PDO::PARAM_STR);
    $update_stmt->bindParam(':id', $reservation_id, PDO::PARAM_INT);
    
    if ($update_stmt->execute()) {
        // Update available spots for the parking space
        $update_spots = $db->prepare("UPDATE parking_spaces SET available_spots = available_spots + 1 WHERE id = :id");
        $update_spots->bindParam(':id', $reservation['parking_id'], PDO::PARAM_INT);
        $update_spots->execute();
        
        $db->commit();
        
        // Log the cancellation
        $log_details = "Reservation #{$reservation['booking_reference']} cancelled by user. Refund amount: ₦" . number_format($refund_amount, 2);
        logAction($db, $_SESSION['user_id'], 'cancel_reservation', $log_details);
        
        $_SESSION['success'] = 'Reservation cancelled successfully. A refund of ₦' . number_format($refund_amount, 2) . ' will be processed within 2-3 business days.';
        
        // Send cancellation email (if email class exists)
        if (class_exists('EmailNotifications')) {
            try {
                require_once 'includes/email-functions.php';
                $emailer = new EmailNotifications($db);
                $emailer->sendStatusUpdate($reservation_id, $reservation['status'], 'cancelled');
                error_log("Cancellation email sent for reservation {$reservation_id}");
            } catch (Exception $e) {
                error_log("Failed to send cancellation email: " . $e->getMessage());
            }
        }
        
    } else {
        $db->rollBack();
        error_log("Failed to cancel reservation: " . print_r($update_stmt->errorInfo(), true));
        $_SESSION['error'] = 'Failed to cancel reservation. Please try again or contact support.';
    }
    
} catch (PDOException $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Database error in cancel-reservation.php: " . $e->getMessage());
    $_SESSION['error'] = 'A database error occurred. Please try again later.';
} catch (Exception $e) {
    if ($db->inTransaction()) {
        $db->rollBack();
    }
    error_log("Unexpected error in cancel-reservation.php: " . $e->getMessage());
    $_SESSION['error'] = 'An unexpected error occurred. Please try again later.';
}

// Redirect back to reservation details page
header('Location: reservation-details.php?id=' . $reservation_id);
exit();
?>