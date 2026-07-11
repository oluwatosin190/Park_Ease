<?php
session_start(); // Start session for logging
require_once 'config/database.php';
require_once 'includes/paystack-api.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Function to log verification attempts
function logVerification($reference, $status, $message = '', $db = null) {
    $log_file = __DIR__ . '/logs/payment_verification.log';
    $log_dir = dirname($log_file);
    
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $user_id = isset($_SESSION['user_id']) ? $_SESSION['user_id'] : 'guest';
    $log_entry = "[$timestamp] User: $user_id | Reference: $reference | Status: $status | Message: $message | IP: $ip\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

// Set JSON content type
header('Content-Type: application/json');

// Rate limiting - prevent abuse
session_start();
$rate_limit_key = 'verify_payment_' . ($_SESSION['user_id'] ?? $_SERVER['REMOTE_ADDR']);
if (isset($_SESSION[$rate_limit_key]) && $_SESSION[$rate_limit_key] > time() - 10) {
    http_response_code(429);
    echo json_encode([
        'status' => 'error',
        'message' => 'Too many requests. Please wait a moment.'
    ]);
    exit();
}
$_SESSION[$rate_limit_key] = time();

// Get reference from request
$reference = isset($_GET['reference']) ? trim($_GET['reference']) : '';

if (empty($reference)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'No payment reference provided'
    ]);
    logVerification('', 'error', 'No reference provided');
    exit();
}

// Validate reference format (Paystack references are alphanumeric)
if (!preg_match('/^[A-Za-z0-9_-]+$/', $reference)) {
    echo json_encode([
        'status' => 'error',
        'message' => 'Invalid payment reference format'
    ]);
    logVerification($reference, 'error', 'Invalid reference format');
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    error_log("Database connection failed in verify-payment.php");
    echo json_encode([
        'status' => 'error',
        'message' => 'System error. Please try again later.'
    ]);
    exit();
}

// Initialize Paystack
try {
    $paystack = new PaystackAPI();
} catch (Exception $e) {
    error_log("Paystack initialization error: " . $e->getMessage());
    echo json_encode([
        'status' => 'error',
        'message' => 'Payment system temporarily unavailable. Please try again later.'
    ]);
    exit();
}

// Check if this reference already exists in database
try {
    $check_query = "SELECT id, payment_status, payment_verified, status FROM reservations WHERE paystack_reference = :ref";
    $check_stmt = $db->prepare($check_query);
    $check_stmt->bindParam(':ref', $reference, PDO::PARAM_STR);
    $check_stmt->execute();
    $existing = $check_stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($existing && $existing['payment_verified'] == 1) {
        // Already verified
        echo json_encode([
            'status' => 'success',
            'payment_status' => $existing['payment_status'],
            'reservation_status' => $existing['status'],
            'already_verified' => true,
            'message' => 'Payment already verified'
        ]);
        logVerification($reference, 'success', 'Already verified');
        exit();
    }
} catch (PDOException $e) {
    error_log("Database check error in verify-payment.php: " . $e->getMessage());
    // Continue with verification even if database check fails
}

// Verify payment with Paystack
try {
    $verification = $paystack->verifyTransaction($reference);
    
    if ($verification['status']) {
        $payment_status = $verification['payment_status'];
        $amount = isset($verification['amount']) ? $verification['amount'] / 100 : 0;
        
        // Log successful verification
        logVerification($reference, $payment_status, "Amount: ₦$amount");
        
        // Update database if payment is successful
        if ($payment_status == 'success') {
            try {
                // Update reservation if found
                $update_query = "UPDATE reservations SET 
                                 payment_status = 'paid',
                                 payment_verified = 1,
                                 payment_date = NOW(),
                                 paystack_response = :response
                                 WHERE paystack_reference = :ref";
                $update_stmt = $db->prepare($update_query);
                $response_json = json_encode($verification);
                $update_stmt->bindParam(':response', $response_json, PDO::PARAM_STR);
                $update_stmt->bindParam(':ref', $reference, PDO::PARAM_STR);
                $update_stmt->execute();
                
                // Also update the status if it's still pending
                if ($update_stmt->rowCount() > 0) {
                    $status_update = "UPDATE reservations SET status = 'confirmed' 
                                      WHERE paystack_reference = :ref AND status = 'pending'";
                    $status_stmt = $db->prepare($status_update);
                    $status_stmt->bindParam(':ref', $reference, PDO::PARAM_STR);
                    $status_stmt->execute();
                }
                
                error_log("Payment verified and updated for reference: $reference");
                
            } catch (PDOException $e) {
                error_log("Failed to update database after payment verification: " . $e->getMessage());
                // Don't fail the response - payment is still verified
            }
        }
        
        echo json_encode([
            'status' => 'success',
            'payment_status' => $payment_status,
            'amount' => $amount,
            'reference' => $reference
        ]);
        
    } else {
        $error_message = $verification['message'] ?? 'Verification failed';
        logVerification($reference, 'failed', $error_message);
        
        echo json_encode([
            'status' => 'error',
            'message' => $error_message,
            'reference' => $reference
        ]);
    }
    
} catch (Exception $e) {
    error_log("Paystack verification exception: " . $e->getMessage());
    logVerification($reference, 'exception', $e->getMessage());
    
    echo json_encode([
        'status' => 'error',
        'message' => 'Payment verification encountered an error. Please contact support.',
        'reference' => $reference
    ]);
}
?>