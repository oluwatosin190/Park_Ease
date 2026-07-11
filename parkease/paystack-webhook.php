<?php
/**
 * Paystack Webhook Handler
 * This file receives payment confirmations from Paystack
 * 
 * IMPORTANT: This file should be placed in a secure location and
 * only accessible by Paystack servers. Consider adding IP whitelisting.
 */

// Set error reporting for webhook (log errors but don't display)
error_reporting(E_ALL);
ini_set('display_errors', 0);
ini_set('log_errors', 1);

require_once 'config/database.php';
require_once 'includes/paystack-api.php';
require_once 'includes/commission-functions.php';
require_once 'includes/email-functions.php';

// Define log file paths
define('WEBHOOK_LOG_FILE', __DIR__ . '/logs/paystack_webhook.log');
define('WEBHOOK_ERROR_LOG', __DIR__ . '/logs/paystack_errors.log');

// Create logs directory if it doesn't exist
$log_dir = dirname(WEBHOOK_LOG_FILE);
if (!file_exists($log_dir)) {
    mkdir($log_dir, 0755, true);
}

/**
 * Log webhook events for debugging
 */
function logWebhook($message, $data = null) {
    $log_entry = date('Y-m-d H:i:s') . " - " . $message;
    if ($data !== null) {
        $log_entry .= " - Data: " . json_encode($data);
    }
    $log_entry .= "\n";
    file_put_contents(WEBHOOK_LOG_FILE, $log_entry, FILE_APPEND);
}

/**
 * Log errors separately
 */
function logWebhookError($message, $data = null) {
    $log_entry = date('Y-m-d H:i:s') . " - ERROR: " . $message;
    if ($data !== null) {
        $log_entry .= " - Data: " . json_encode($data);
    }
    $log_entry .= "\n";
    file_put_contents(WEBHOOK_ERROR_LOG, $log_entry, FILE_APPEND);
}

/**
 * Verify Paystack webhook signature
 */
function verifyPaystackSignature($payload, $signature_header) {
    $paystack_secret = defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '';
    
    if (empty($paystack_secret) || empty($signature_header)) {
        logWebhookError("Missing Paystack secret or signature header");
        return false;
    }
    
    $computed_signature = hash_hmac('sha512', $payload, $paystack_secret);
    return hash_equals($computed_signature, $signature_header);
}

// Get webhook payload
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

// Log incoming webhook (sanitize sensitive data)
logWebhook("Webhook received", ['signature_present' => !empty($signature)]);

// Verify signature (required for production)
if (empty($signature)) {
    logWebhookError("Missing Paystack signature header");
    http_response_code(401);
    exit('Missing signature');
}

if (!verifyPaystackSignature($payload, $signature)) {
    logWebhookError("Invalid webhook signature");
    http_response_code(401);
    exit('Invalid signature');
}

// Parse JSON payload
$event = json_decode($payload);

if (!$event || !isset($event->event)) {
    logWebhookError('Invalid webhook payload received');
    http_response_code(400);
    exit('Invalid payload');
}

logWebhook("Webhook processed: {$event->event}", ['reference' => $event->data->reference ?? 'unknown']);

$database = new Database();
$db = $database->getConnection();

// Handle different event types
switch ($event->event) {
    case 'charge.success':
        handleSuccessfulCharge($db, $event->data);
        break;
        
    case 'charge.dispute.create':
        handleDisputeCreated($db, $event->data);
        break;
        
    case 'charge.dispute.resolve':
        handleDisputeResolved($db, $event->data);
        break;
        
    case 'transfer.success':
        handleSuccessfulTransfer($db, $event->data);
        break;
        
    case 'transfer.failed':
        handleFailedTransfer($db, $event->data);
        break;
        
    default:
        logWebhook("Unhandled event type: {$event->event}");
        break;
}

http_response_code(200);
exit('Webhook processed');

/**
 * Handle successful payment charge
 */
function handleSuccessfulCharge($db, $data) {
    $reference = $data->reference ?? '';
    $amount = isset($data->amount) ? $data->amount / 100 : 0;
    $paid_at = $data->paid_at ?? date('Y-m-d H:i:s');
    $currency = $data->currency ?? 'NGN';
    
    if (empty($reference)) {
        logWebhookError('Charge.success: Missing reference');
        return;
    }
    
    logWebhook("Processing charge.success for reference: $reference, amount: ₦$amount");
    
    try {
        // Start transaction
        $db->beginTransaction();
        
        // Find reservation
        $query = "SELECT r.*, u.email, u.first_name, u.last_name, ps.name as parking_name 
                  FROM reservations r
                  JOIN users u ON r.user_id = u.id
                  JOIN parking_spaces ps ON r.parking_id = ps.id
                  WHERE r.paystack_reference = :ref";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':ref', $reference, PDO::PARAM_STR);
        $stmt->execute();
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reservation) {
            logWebhookError("Charge.success: No reservation found for reference: $reference");
            $db->rollBack();
            return;
        }
        
        // Check if payment is already processed
        if ($reservation['payment_verified'] == 1) {
            logWebhook("Charge.success: Payment already processed for reference: $reference");
            $db->rollBack();
            return;
        }
        
        // Verify amount matches
        $expected_amount = $reservation['total_amount'];
        if (abs($amount - $expected_amount) > 0.01) {
            logWebhookError("Charge.success: Amount mismatch for reservation {$reservation['id']}. Expected: ₦$expected_amount, Received: ₦$amount");
            $db->rollBack();
            return;
        }
        
        // Update reservation
        $update = "UPDATE reservations SET 
                   payment_status = 'paid',
                   payment_date = :paid_at,
                   payment_verified = 1,
                   paystack_response = :response,
                   status = 'confirmed'
                   WHERE id = :id";
        $update_stmt = $db->prepare($update);
        $response_json = json_encode($data);
        $update_stmt->bindParam(':paid_at', $paid_at, PDO::PARAM_STR);
        $update_stmt->bindParam(':response', $response_json, PDO::PARAM_STR);
        $update_stmt->bindParam(':id', $reservation['id'], PDO::PARAM_INT);
        $update_stmt->execute();
        
        // Update available spots
        $update_spots = $db->prepare("UPDATE parking_spaces SET available_spots = available_spots - 1 WHERE id = :id");
        $update_spots->bindParam(':id', $reservation['parking_id'], PDO::PARAM_INT);
        $update_spots->execute();
        
        $db->commit();
        
        logWebhook("Charge.success: Successfully processed payment for reservation {$reservation['id']}, reference: $reference");
        
        // Send confirmation email (outside transaction)
        try {
            $emailer = new EmailNotifications($db);
            $emailer->sendPaymentConfirmation($reservation['id']);
            logWebhook("Confirmation email sent for reservation {$reservation['id']}");
        } catch (Exception $e) {
            logWebhookError("Failed to send confirmation email: " . $e->getMessage());
        }
        
        // Generate PIN
        try {
            require_once 'includes/pin-functions.php';
            $pinManager = new PinManager($db);
            $pin = $pinManager->createAndSavePin($reservation['id']);
            $emailer->sendPinEmail($reservation['id']);
            logWebhook("PIN generated and sent for reservation {$reservation['id']}");
        } catch (Exception $e) {
            logWebhookError("Failed to generate/send PIN: " . $e->getMessage());
        }
        
        // Notify owner
        try {
            notifyOwner($db, $reservation);
        } catch (Exception $e) {
            logWebhookError("Failed to notify owner: " . $e->getMessage());
        }
        
    } catch (PDOException $e) {
        $db->rollBack();
        logWebhookError("Charge.success: Database error - " . $e->getMessage());
    } catch (Exception $e) {
        $db->rollBack();
        logWebhookError("Charge.success: Unexpected error - " . $e->getMessage());
    }
}

/**
 * Handle dispute created events
 */
function handleDisputeCreated($db, $data) {
    $reference = $data->reference ?? '';
    
    logWebhook("Dispute created for reference: $reference");
    
    try {
        $query = "INSERT INTO payment_disputes (reference, transaction_id, reason, status, created_at) 
                  VALUES (:ref, :transaction_id, :reason, 'open', NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':ref', $reference, PDO::PARAM_STR);
        $stmt->bindParam(':transaction_id', $data->transaction_id ?? '', PDO::PARAM_STR);
        $stmt->bindParam(':reason', $data->reason ?? 'Unknown', PDO::PARAM_STR);
        $stmt->execute();
        
        // Notify admin
        notifyAdminOfDispute($db, $reference, $data->reason ?? 'Unknown');
    } catch (Exception $e) {
        logWebhookError("Failed to log dispute: " . $e->getMessage());
    }
}

/**
 * Handle dispute resolved events
 */
function handleDisputeResolved($db, $data) {
    $reference = $data->reference ?? '';
    $resolution = $data->resolution ?? '';
    
    logWebhook("Dispute resolved for reference: $reference, resolution: $resolution");
    
    try {
        $update = "UPDATE payment_disputes SET 
                   status = 'resolved',
                   resolution = :resolution,
                   resolved_at = NOW()
                   WHERE reference = :ref";
        $stmt = $db->prepare($update);
        $stmt->bindParam(':resolution', $resolution, PDO::PARAM_STR);
        $stmt->bindParam(':ref', $reference, PDO::PARAM_STR);
        $stmt->execute();
    } catch (Exception $e) {
        logWebhookError("Failed to update dispute resolution: " . $e->getMessage());
    }
}

/**
 * Handle successful transfer events (owner payouts)
 */
function handleSuccessfulTransfer($db, $data) {
    $transfer_code = $data->transfer_code ?? '';
    $amount = isset($data->amount) ? $data->amount / 100 : 0;
    $reference = $data->reference ?? '';
    
    if (empty($transfer_code)) {
        logWebhookError('Transfer.success: Missing transfer code');
        return;
    }
    
    logWebhook("Transfer success for code: $transfer_code, amount: ₦$amount");
    
    try {
        $db->beginTransaction();
        
        // Update transfer status
        $update = "UPDATE payouts SET 
                   status = 'completed',
                   completed_at = NOW(),
                   transaction_reference = :ref
                   WHERE paystack_transfer_ref = :transfer_ref";
        $stmt = $db->prepare($update);
        $stmt->bindParam(':ref', $reference, PDO::PARAM_STR);
        $stmt->bindParam(':transfer_ref', $transfer_code, PDO::PARAM_STR);
        $stmt->execute();
        
        // Get owner info for email notification
        $query = "SELECT p.*, u.email, u.first_name, u.last_name 
                  FROM payouts p
                  JOIN users u ON p.owner_id = u.id
                  WHERE p.paystack_transfer_ref = :transfer_ref";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':transfer_ref', $transfer_code, PDO::PARAM_STR);
        $stmt->execute();
        $payout = $stmt->fetch(PDO::FETCH_ASSOC);
        
        $db->commit();
        
        logWebhook("Payout updated for transfer: $transfer_code");
        
        // Send email notification to owner
        if ($payout) {
            sendPayoutEmail($payout['email'], $payout['first_name'], $amount, 'success', $payout['reference'] ?? '');
        }
        
    } catch (Exception $e) {
        $db->rollBack();
        logWebhookError("Failed to update payout: " . $e->getMessage());
    }
}

/**
 * Handle failed transfer events
 */
function handleFailedTransfer($db, $data) {
    $transfer_code = $data->transfer_code ?? '';
    $reason = $data->reason ?? 'Unknown error';
    
    logWebhook("Transfer failed for code: $transfer_code, reason: $reason");
    
    try {
        // Update transfer status
        $update = "UPDATE payouts SET 
                   status = 'failed',
                   failure_reason = :reason,
                   failed_at = NOW()
                   WHERE paystack_transfer_ref = :transfer_ref";
        $stmt = $db->prepare($update);
        $stmt->bindParam(':reason', $reason, PDO::PARAM_STR);
        $stmt->bindParam(':transfer_ref', $transfer_code, PDO::PARAM_STR);
        $stmt->execute();
        
        // Get owner info
        $query = "SELECT p.*, u.email, u.first_name, u.last_name 
                  FROM payouts p
                  JOIN users u ON p.owner_id = u.id
                  WHERE p.paystack_transfer_ref = :transfer_ref";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':transfer_ref', $transfer_code, PDO::PARAM_STR);
        $stmt->execute();
        $payout = $stmt->fetch(PDO::FETCH_ASSOC);
        
        // Notify owner of failed transfer
        if ($payout) {
            sendPayoutEmail($payout['email'], $payout['first_name'], $payout['amount'] ?? 0, 'failed', $payout['reference'] ?? '');
        }
        
        // Notify admin
        notifyAdminOfFailedTransfer($db, $transfer_code, $reason);
        
    } catch (Exception $e) {
        logWebhookError("Failed to update failed transfer: " . $e->getMessage());
    }
}

/**
 * Notify owner of new booking
 */
function notifyOwner($db, $reservation) {
    $owner_query = "SELECT email, first_name FROM users WHERE id = :owner_id";
    $owner_stmt = $db->prepare($owner_query);
    $owner_stmt->bindParam(':owner_id', $reservation['owner_id'], PDO::PARAM_INT);
    $owner_stmt->execute();
    $owner = $owner_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($owner) {
        $subject = "New Booking Confirmed - SpaceNode";
        $message = "Hello {$owner['first_name']},\n\n";
        $message .= "A new booking has been confirmed and paid for.\n";
        $message .= "Booking Reference: {$reservation['booking_reference']}\n";
        $message .= "Amount: ₦" . number_format($reservation['total_amount'], 2) . "\n";
        $message .= "Start Time: " . date('M d, Y h:i A', strtotime($reservation['start_date'])) . "\n";
        $message .= "End Time: " . date('M d, Y h:i A', strtotime($reservation['end_date'])) . "\n\n";
        $message .= "Login to your dashboard to view details and enter the PIN when the customer arrives.\n\n";
        $message .= "Thank you for using SpaceNode!";
        
        $headers = "From: SpaceNode <noreply@spacenode.com>\r\n";
        $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
        
        mail($owner['email'], $subject, $message, $headers);
        logWebhook("Owner notification sent to: {$owner['email']}");
    }
}

/**
 * Notify admin of failed transfer
 */
function notifyAdminOfFailedTransfer($db, $transfer_code, $reason) {
    // Get admin emails from settings or config
    $admin_email = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'admin@spacenode.com';
    
    $subject = "⚠️ Payout Transfer Failed - SpaceNode";
    $message = "A payout transfer has failed.\n\n";
    $message .= "Transfer Code: $transfer_code\n";
    $message .= "Reason: $reason\n\n";
    $message .= "Please check the admin dashboard for details.\n";
    $message .= "Time: " . date('Y-m-d H:i:s');
    
    $headers = "From: SpaceNode <noreply@spacenode.com>\r\n";
    
    mail($admin_email, $subject, $message, $headers);
    logWebhook("Admin notification sent for failed transfer: $transfer_code");
}

/**
 * Notify admin of dispute
 */
function notifyAdminOfDispute($db, $reference, $reason) {
    $admin_email = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'admin@spacenode.com';
    
    $subject = "⚠️ Payment Dispute Created - SpaceNode";
    $message = "A payment dispute has been created.\n\n";
    $message .= "Reference: $reference\n";
    $message .= "Reason: $reason\n\n";
    $message .= "Please check the admin dashboard to review.\n";
    $message .= "Time: " . date('Y-m-d H:i:s');
    
    $headers = "From: SpaceNode <noreply@spacenode.com>\r\n";
    
    mail($admin_email, $subject, $message, $headers);
    logWebhook("Admin notification sent for dispute: $reference");
}

/**
 * Send payout email to owner
 */
function sendPayoutEmail($email, $name, $amount, $status, $reference = '') {
    if ($status == 'success') {
        $subject = "✅ Payout Successful - SpaceNode";
        $message = "Hello $name,\n\n";
        $message .= "Your payout of ₦" . number_format($amount, 2) . " has been sent to your bank account.\n";
        $message .= "Reference: $reference\n";
        $message .= "It should reflect within 24 hours.\n\n";
        $message .= "Thank you for using SpaceNode!\n\n";
        $message .= "View your earnings: https://spacenode.com/owner-earnings.php";
    } else {
        $subject = "⚠️ Payout Failed - SpaceNode";
        $message = "Hello $name,\n\n";
        $message .= "Your payout of ₦" . number_format($amount, 2) . " could not be processed.\n";
        $message .= "Reference: $reference\n\n";
        $message .= "Please check your bank details in your dashboard and try again.\n\n";
        $message .= "If you need assistance, please contact support.\n\n";
        $message .= "Update bank details: https://spacenode.com/profile.php#bank-details";
    }
    
    $headers = "From: SpaceNode <noreply@spacenode.com>\r\n";
    $headers .= "Content-Type: text/plain; charset=UTF-8\r\n";
    
    mail($email, $subject, $message, $headers);
    logWebhook("Payout email sent to: $email, status: $status");
}
?>