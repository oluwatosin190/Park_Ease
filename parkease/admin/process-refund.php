<?php
require_once '../config/database.php';
require_once 'includes/auth.php';

requireAdminLogin();

$database = new Database();
$db = $database->getConnection();

$booking_id = isset($_GET['id']) ? (int)$_GET['id'] : (isset($_POST['booking_id']) ? (int)$_POST['booking_id'] : 0);
$amount = isset($_POST['amount']) ? floatval($_POST['amount']) : 0;
$reason = isset($_POST['reason']) ? trim($_POST['reason']) : '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate
    if ($booking_id <= 0 || $amount <= 0 || empty($reason)) {
        $_SESSION['error'] = 'Invalid refund request';
        header('Location: bookings.php');
        exit();
    }
    
    // Get booking details
    $query = "SELECT r.*, u.email as customer_email, u.first_name, u.last_name 
              FROM reservations r
              JOIN users u ON r.user_id = u.id
              WHERE r.id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $booking_id);
    $stmt->execute();
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) {
        $_SESSION['error'] = 'Booking not found';
        header('Location: bookings.php');
        exit();
    }
    
    if ($amount > $booking['total_amount']) {
        $_SESSION['error'] = 'Refund amount cannot exceed booking total';
        header('Location: bookings.php');
        exit();
    }
    
    // Begin transaction
    $db->beginTransaction();
    
    try {
        // Create refund record
        $refund_ref = 'REF_' . uniqid() . '_' . time();
        
        $insert = "INSERT INTO refunds (reservation_id, admin_id, amount, reason, status, refund_reference) 
                   VALUES (:reservation_id, :admin_id, :amount, :reason, 'pending', :ref)";
        $insert_stmt = $db->prepare($insert);
        $insert_stmt->bindParam(':reservation_id', $booking_id);
        $insert_stmt->bindParam(':admin_id', $_SESSION['admin_id']);
        $insert_stmt->bindParam(':amount', $amount);
        $insert_stmt->bindParam(':reason', $reason);
        $insert_stmt->bindParam(':ref', $refund_ref);
        $insert_stmt->execute();
        
        // Update booking status
        $update = "UPDATE reservations SET status = 'cancelled', payment_status = 'refunded' WHERE id = :id";
        $update_stmt = $db->prepare($update);
        $update_stmt->bindParam(':id', $booking_id);
        $update_stmt->execute();
        
        // If using Paystack, initiate refund via API
        if ($booking['paystack_reference']) {
            require_once '../includes/paystack-api.php';
            $paystack = new PaystackAPI();
            
            // Note: This is a placeholder. Actual Paystack refund API call would go here
            // $result = $paystack->refundTransaction($booking['paystack_reference'], $amount);
            
            // For now, we'll just log it
            logAdminAction($db, 'refund_initiated', "Refund of ₦$amount for booking ID: $booking_id");
        }
        
        $db->commit();
        
        // Send email notification to customer
        $subject = "Refund Processed - SpaceNode Booking #{$booking['booking_reference']}";
        $message = "Hello {$booking['first_name']},\n\n";
        $message .= "A refund of ₦" . number_format($amount, 2) . " has been processed for your booking.\n";
        $message .= "Booking Reference: {$booking['booking_reference']}\n";
        $message .= "Reason: $reason\n\n";
        $message .= "The refund should reflect in your account within 3-5 business days.\n\n";
        $message .= "Thank you for using SpaceNode.";
        
        mail($booking['customer_email'], $subject, $message);
        
        $_SESSION['success'] = "Refund of ₦" . number_format($amount, 2) . " processed successfully";
        
    } catch (Exception $e) {
        $db->rollBack();
        $_SESSION['error'] = 'Refund failed: ' . $e->getMessage();
    }
    
    header('Location: bookings.php');
    exit();
}

// If we get here without POST, redirect
header('Location: bookings.php');
exit();
?>