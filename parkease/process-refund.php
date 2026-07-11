<?php
session_start(); // Start session at the beginning
require_once 'config/database.php';
require_once 'includes/commission-functions.php';
require_once 'includes/email-functions.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please log in to cancel a reservation.';
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    error_log("Database connection failed in process-refund.php");
    $_SESSION['error'] = 'System error. Please try again later.';
    header('Location: dashboard.php');
    exit();
}

$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$action = isset($_GET['action']) ? sanitize($_GET['action']) : '';
$cancellation_reason = isset($_POST['cancellation_reason']) ? substr(trim($_POST['cancellation_reason']), 0, 500) : '';

// Validate reservation ID
if ($reservation_id <= 0) {
    $_SESSION['error'] = 'Invalid reservation ID.';
    header('Location: dashboard.php');
    exit();
}

// Validate action
if ($action !== 'cancel') {
    $_SESSION['error'] = 'Invalid action.';
    header('Location: dashboard.php');
    exit();
}

// Get reservation details with full information
$query = "SELECT r.*, 
          u.email as user_email, u.first_name as user_first_name, u.last_name as user_last_name,
          o.email as owner_email, o.first_name as owner_first_name, o.last_name as owner_last_name,
          ps.name as parking_name, ps.address, ps.city
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          JOIN users o ON r.owner_id = o.id
          JOIN parking_spaces ps ON r.parking_id = ps.id
          WHERE r.id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $reservation_id, PDO::PARAM_INT);
$stmt->execute();
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    $_SESSION['error'] = 'Reservation not found.';
    header('Location: dashboard.php');
    exit();
}

// Determine who is cancelling
$cancelled_by = '';
$canceller_name = '';

if ($_SESSION['user_id'] == $reservation['user_id']) {
    $cancelled_by = 'user';
    $canceller_name = $reservation['user_first_name'] . ' ' . $reservation['user_last_name'];
} elseif ($_SESSION['user_id'] == $reservation['owner_id']) {
    $cancelled_by = 'owner';
    $canceller_name = $reservation['owner_first_name'] . ' ' . $reservation['owner_last_name'];
} else {
    $_SESSION['error'] = 'You do not have permission to cancel this reservation.';
    header('Location: dashboard.php');
    exit();
}

// Check if reservation can be cancelled based on status
$allowed_statuses = ['pending', 'confirmed'];
if (!in_array($reservation['status'], $allowed_statuses)) {
    $_SESSION['error'] = 'This reservation cannot be cancelled because it is already ' . $reservation['status'] . '.';
    header('Location: reservation-details.php?id=' . $reservation_id);
    exit();
}

// Check if timer has already started
if ($reservation['timer_status'] == 'active' || $reservation['timer_status'] == 'pending_checkout') {
    $_SESSION['error'] = 'Cannot cancel a reservation after the parking session has started.';
    header('Location: reservation-details.php?id=' . $reservation_id);
    exit();
}

// Check if cancellation is allowed (within 1 hour of start time)
$start = new DateTime($reservation['start_date']);
$now = new DateTime();
$cancellation_deadline = clone $start;
$cancellation_deadline->modify('-1 hour'); // Can cancel up to 1 hour before start

if ($now > $cancellation_deadline && $cancelled_by == 'user') {
    $_SESSION['error'] = 'This reservation cannot be cancelled. Cancellations are allowed up to 1 hour before the start time.';
    header('Location: reservation-details.php?id=' . $reservation_id);
    exit();
}

// Process cancellation with commission manager
try {
    $commission = new CommissionManager($db);
    $result = $commission->processCancellation($reservation_id, $cancelled_by);
    
    if ($result['success']) {
        // Update available spots (increment back)
        $update_spots = "UPDATE parking_spaces SET available_spots = available_spots + 1 
                         WHERE id = :id";
        $update_stmt = $db->prepare($update_spots);
        $update_stmt->bindParam(':id', $reservation['parking_id'], PDO::PARAM_INT);
        $update_stmt->execute();
        
        // Store cancellation details
        $update_cancel = "UPDATE reservations SET 
                          cancelled_at = NOW(),
                          cancelled_by = :cancelled_by,
                          cancellation_reason = :reason,
                          refund_amount = :refund_amount
                          WHERE id = :id";
        $cancel_stmt = $db->prepare($update_cancel);
        $cancel_stmt->bindParam(':cancelled_by', $cancelled_by, PDO::PARAM_STR);
        $cancel_stmt->bindParam(':reason', $cancellation_reason, PDO::PARAM_STR);
        $cancel_stmt->bindParam(':refund_amount', $result['refund_amount'], PDO::PARAM_STR);
        $cancel_stmt->bindParam(':id', $reservation_id, PDO::PARAM_INT);
        $cancel_stmt->execute();
        
        // Log the cancellation
        error_log("Reservation cancelled - ID: $reservation_id, By: $cancelled_by, Reason: $cancellation_reason");
        
        // Set session messages
        $_SESSION['success'] = $result['message'];
        if ($result['commission_handling']) {
            $_SESSION['info'] = $result['commission_handling'];
        }
        
        // If there's a refund amount, process refund
        if ($result['refund_amount'] > 0) {
            $_SESSION['info'] = 'Refund of ₦' . number_format($result['refund_amount'], 2) . ' will be processed within 2-3 business days.';
            
            // If the payment was made via Paystack, trigger refund
            if (!empty($reservation['paystack_reference']) && $reservation['payment_status'] == 'paid') {
                try {
                    require_once 'includes/paystack-api.php';
                    $paystack = new PaystackAPI();
                    $refund_result = $paystack->refundTransaction(
                        $reservation['paystack_reference'],
                        $result['refund_amount']
                    );
                    
                    if ($refund_result['status']) {
                        $_SESSION['info'] .= ' Refund initiated via Paystack.';
                        error_log("Refund initiated for reservation $reservation_id: ₦" . $result['refund_amount']);
                    } else {
                        error_log("Failed to initiate refund for reservation $reservation_id: " . ($refund_result['message'] ?? 'Unknown error'));
                    }
                } catch (Exception $e) {
                    error_log("Paystack refund error: " . $e->getMessage());
                }
            }
        }
        
        // Send cancellation email
        try {
            $emailer = new EmailNotifications($db);
            $emailer->sendStatusUpdate($reservation_id, $reservation['status'], 'cancelled');
            
            // Also notify the other party
            if ($cancelled_by == 'user') {
                // Notify owner
                $owner_subject = "Booking Cancelled - SpaceNode #{$reservation['booking_reference']}";
                $owner_message = "Hello {$reservation['owner_first_name']},\n\n";
                $owner_message .= "A booking has been cancelled by the customer.\n";
                $owner_message .= "Booking Reference: {$reservation['booking_reference']}\n";
                $owner_message .= "Parking Space: {$reservation['parking_name']}\n";
                $owner_message .= "Customer: {$reservation['user_first_name']} {$reservation['user_last_name']}\n";
                $owner_message .= "Reason: " . ($cancellation_reason ?: 'Cancelled by user') . "\n\n";
                $owner_message .= "The space is now available for other customers.\n\n";
                $owner_message .= "Thank you for using SpaceNode!";
                
                $headers = "From: SpaceNode <noreply@spacenode.com>\r\n";
                mail($reservation['owner_email'], $owner_subject, $owner_message, $headers);
                error_log("Owner notified of cancellation for reservation $reservation_id");
            } else {
                // Notify customer
                $customer_subject = "Booking Cancelled - SpaceNode #{$reservation['booking_reference']}";
                $customer_message = "Hello {$reservation['user_first_name']},\n\n";
                $customer_message .= "Your booking has been cancelled by the parking owner.\n";
                $customer_message .= "Booking Reference: {$reservation['booking_reference']}\n";
                $customer_message .= "Parking Space: {$reservation['parking_name']}\n";
                $customer_message .= "Reason: " . ($cancellation_reason ?: 'Cancelled by owner') . "\n\n";
                
                if ($result['refund_amount'] > 0) {
                    $customer_message .= "A refund of ₦" . number_format($result['refund_amount'], 2) . " will be processed.\n\n";
                }
                
                $customer_message .= "If you have any questions, please contact support.\n\n";
                $customer_message .= "Thank you for using SpaceNode!";
                
                $headers = "From: SpaceNode <noreply@spacenode.com>\r\n";
                mail($reservation['user_email'], $customer_subject, $customer_message, $headers);
                error_log("Customer notified of cancellation for reservation $reservation_id");
            }
        } catch (Exception $e) {
            error_log("Failed to send cancellation email: " . $e->getMessage());
            // Don't fail the cancellation if email fails
        }
        
    } else {
        $_SESSION['error'] = $result['message'];
    }
    
} catch (PDOException $e) {
    error_log("Database error in process-refund.php: " . $e->getMessage());
    $_SESSION['error'] = 'A database error occurred. Please try again later.';
    
} catch (Exception $e) {
    error_log("General error in process-refund.php: " . $e->getMessage());
    $_SESSION['error'] = 'An error occurred while processing the cancellation. Please try again.';
}

// Redirect back to reservation details
header('Location: reservation-details.php?id=' . $reservation_id);
exit();
?>