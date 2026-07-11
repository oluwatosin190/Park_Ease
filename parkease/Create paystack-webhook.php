<?php
/**
 * Paystack Webhook Handler
 * This file receives payment confirmations from Paystack
 * 
 * IMPORTANT: This file should be placed in a secure location and
 * only accessible by Paystack servers. Consider adding IP whitelisting.
 */

session_start(); // Start session for logging purposes

require_once 'config/database.php';
require_once 'includes/paystack-api.php';
require_once 'includes/commission-functions.php';
require_once 'includes/email-functions.php';

// Define log file path
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
 * Verify Paystack webhook signature (optional but recommended)
 */
function verifyPaystackSignature($payload, $signature_header) {
    $paystack_secret = defined('PAYSTACK_SECRET_KEY') ? PAYSTACK_SECRET_KEY : '';
    
    if (empty($paystack_secret) || empty($signature_header)) {
        return false;
    }
    
    $computed_signature = hash_hmac('sha512', $payload, $paystack_secret);
    return hash_equals($computed_signature, $signature_header);
}

// Get webhook payload
$payload = file_get_contents('php://input');
$signature = $_SERVER['HTTP_X_PAYSTACK_SIGNATURE'] ?? '';

// Verify signature (optional but recommended for production)
if (!empty($signature)) {
    if (!verifyPaystackSignature($payload, $signature)) {
        logWebhookError('Invalid webhook signature');
        http_response_code(401);
        echo json_encode(['status' => 'error', 'message' => 'Invalid signature']);
        exit();
    }
}

// Parse JSON payload
$event = json_decode($payload);

if (!$event || !isset($event->event)) {
    logWebhookError('Invalid webhook payload received', ['payload' => $payload]);
    http_response_code(400);
    echo json_encode(['status' => 'error', 'message' => 'Invalid payload']);
    exit();
}

// Log the webhook event
logWebhook("Webhook received: {$event->event}", ['reference' => $event->data->reference ?? 'unknown']);

$database = new Database();
$db = $database->getConnection();

// Handle different event types
switch ($event->event) {
    case 'charge.success':
        handleChargeSuccess($db, $event->data);
        break;
        
    case 'charge.refund':
        handleChargeRefund($db, $event->data);
        break;
        
    case 'charge.dispute.create':
        handleDisputeCreated($db, $event->data);
        break;
        
    case 'transfer.success':
        handleTransferSuccess($db, $event->data);
        break;
        
    default:
        logWebhook("Unhandled event type: {$event->event}");
        break;
}

http_response_code(200);
echo json_encode(['status' => 'success']);
exit();

/**
 * Handle successful payment charge
 */
function handleChargeSuccess($db, $data) {
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
        if (abs($amount - $expected_amount) > 0.01) { // Allow small floating point differences
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
        
        // Commit transaction
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
        
    } catch (PDOException $e) {
        $db->rollBack();
        logWebhookError("Charge.success: Database error - " . $e->getMessage());
    } catch (Exception $e) {
        $db->rollBack();
        logWebhookError("Charge.success: Unexpected error - " . $e->getMessage());
    }
}

/**
 * Handle refund events
 */
function handleChargeRefund($db, $data) {
    $reference = $data->reference ?? '';
    $refund_amount = isset($data->amount) ? $data->amount / 100 : 0;
    
    if (empty($reference)) {
        logWebhookError('Charge.refund: Missing reference');
        return;
    }
    
    logWebhook("Processing charge.refund for reference: $reference");
    
    try {
        // Find reservation
        $query = "SELECT id FROM reservations WHERE paystack_reference = :ref";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':ref', $reference, PDO::PARAM_STR);
        $stmt->execute();
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($reservation) {
            $update = "UPDATE reservations SET 
                       payment_status = 'refunded',
                       refund_amount = :amount,
                       refund_date = NOW()
                       WHERE id = :id";
            $update_stmt = $db->prepare($update);
            $update_stmt->bindParam(':amount', $refund_amount, PDO::PARAM_STR);
            $update_stmt->bindParam(':id', $reservation['id'], PDO::PARAM_INT);
            $update_stmt->execute();
            
            logWebhook("Refund processed for reservation {$reservation['id']}, amount: ₦$refund_amount");
        }
    } catch (Exception $e) {
        logWebhookError("Charge.refund error: " . $e->getMessage());
    }
}

/**
 * Handle dispute created events
 */
function handleDisputeCreated($db, $data) {
    $reference = $data->reference ?? '';
    
    logWebhook("Dispute created for reference: $reference");
    
    // Log dispute for admin review
    try {
        $query = "INSERT INTO payment_disputes (reference, transaction_id, reason, created_at) 
                  VALUES (:ref, :transaction_id, :reason, NOW())";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':ref', $reference, PDO::PARAM_STR);
        $stmt->bindParam(':transaction_id', $data->transaction_id ?? '', PDO::PARAM_STR);
        $stmt->bindParam(':reason', $data->reason ?? 'Unknown', PDO::PARAM_STR);
        $stmt->execute();
    } catch (Exception $e) {
        logWebhookError("Failed to log dispute: " . $e->getMessage());
    }
}

/**
 * Handle transfer success events (for owner payouts)
 */
function handleTransferSuccess($db, $data) {
    $reference = $data->reference ?? '';
    $amount = isset($data->amount) ? $data->amount / 100 : 0;
    
    logWebhook("Transfer success for reference: $reference, amount: ₦$amount");
    
    // Update payout record
    try {
        $update = "UPDATE payouts SET 
                   status = 'completed',
                   completed_at = NOW(),
                   transaction_reference = :ref
                   WHERE paystack_transfer_ref = :transfer_ref";
        $stmt = $db->prepare($update);
        $stmt->bindParam(':ref', $reference, PDO::PARAM_STR);
        $stmt->bindParam(':transfer_ref', $reference, PDO::PARAM_STR);
        $stmt->execute();
        
        logWebhook("Payout updated for reference: $reference");
    } catch (Exception $e) {
        logWebhookError("Failed to update payout: " . $e->getMessage());
    }
}
?>