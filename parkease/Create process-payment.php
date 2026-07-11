<?php
session_start(); // Start session at the beginning
require_once 'config/database.php';
require_once 'includes/paystack-api.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    $_SESSION['error'] = 'Please log in to make a payment.';
    header('Location: login.php?redirect=process-payment.php?id=' . (isset($_GET['id']) ? (int)$_GET['id'] : ''));
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

// Get reservation details with validation
$query = "SELECT r.*, u.email, u.first_name, u.last_name, u.phone, 
          ps.name as parking_name, ps.owner_id
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          JOIN parking_spaces ps ON r.parking_id = ps.id
          WHERE r.id = :id AND r.user_id = :user_id AND r.payment_status = 'pending'";

try {
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $reservation_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Process payment query error: " . $e->getMessage());
    $_SESSION['error'] = 'Unable to process payment. Please try again.';
    header('Location: my-reservations.php');
    exit();
}

if (!$reservation) {
    $_SESSION['error'] = 'Reservation not found or already paid.';
    header('Location: my-reservations.php');
    exit();
}

// Check if reservation start time is in the past
$start_date = new DateTime($reservation['start_date']);
$now = new DateTime();

if ($now > $start_date) {
    $_SESSION['error'] = 'This reservation has already passed. Cannot process payment.';
    header('Location: reservation-details.php?id=' . $reservation_id);
    exit();
}

// Check if payment already has a pending reference
if (!empty($reservation['paystack_reference']) && $reservation['payment_verified'] != 1) {
    // Check if the existing reference is still valid (optional)
    // You could add logic to check the status with Paystack
    error_log("Reservation {$reservation_id} already has Paystack reference: {$reservation['paystack_reference']}");
}

// Initialize Paystack
try {
    $paystack = new PaystackAPI();
} catch (Exception $e) {
    error_log("Paystack initialization error: " . $e->getMessage());
    $_SESSION['error'] = 'Payment system temporarily unavailable. Please try again later.';
    header('Location: reservation-details.php?id=' . $reservation_id);
    exit();
}

// Generate unique reference
$reference = 'PAY_' . $reservation['booking_reference'] . '_' . time() . '_' . bin2hex(random_bytes(4));
$reference = substr($reference, 0, 100); // Ensure reference doesn't exceed length limits

// Calculate amount (ensure it's in kobo for Paystack)
$amount = $reservation['total_amount'];
$amount_in_kobo = $amount * 100;

// Prepare metadata
$metadata = [
    'reservation_id' => $reservation_id,
    'user_id' => $_SESSION['user_id'],
    'booking_reference' => $reservation['booking_reference'],
    'parking_name' => $reservation['parking_name'],
    'custom_fields' => [
        [
            'display_name' => 'Booking Reference',
            'variable_name' => 'booking_reference',
            'value' => $reservation['booking_reference']
        ],
        [
            'display_name' => 'Parking Space',
            'variable_name' => 'parking_name',
            'value' => $reservation['parking_name']
        ]
    ]
];

// Log payment attempt
error_log("Payment attempt - User: {$_SESSION['user_id']}, Reservation: {$reservation_id}, Amount: ₦{$amount}, Reference: {$reference}");

// Initialize transaction with Paystack
$result = $paystack->initializeTransaction(
    $reservation['email'],
    $amount_in_kobo,
    $reference,
    $metadata
);

// Check if the transaction was initialized successfully
if ($result['status'] && isset($result['authorization_url'])) {
    try {
        // Begin transaction for database update
        $db->beginTransaction();
        
        // Save Paystack reference and access code
        $update = "UPDATE reservations SET 
                   paystack_reference = :ref, 
                   paystack_access_code = :code,
                   payment_attempts = payment_attempts + 1,
                   last_payment_attempt = NOW()
                   WHERE id = :id";
        $update_stmt = $db->prepare($update);
        $access_code = $result['access_code'] ?? null;
        $update_stmt->bindParam(':ref', $reference, PDO::PARAM_STR);
        $update_stmt->bindParam(':code', $access_code, PDO::PARAM_STR);
        $update_stmt->bindParam(':id', $reservation_id, PDO::PARAM_INT);
        $update_stmt->execute();
        
        // Check if the update was successful
        if ($update_stmt->rowCount() === 0) {
            throw new Exception("Failed to update reservation with Paystack reference");
        }
        
        // Commit transaction
        $db->commit();
        
        // Log successful initialization
        error_log("Payment initialized successfully - Reference: {$reference}, URL: {$result['authorization_url']}");
        
        // Redirect to Paystack payment page
        header('Location: ' . $result['authorization_url']);
        exit();
        
    } catch (PDOException $e) {
        $db->rollBack();
        error_log("Database error during payment initialization: " . $e->getMessage());
        $_SESSION['error'] = 'Failed to save payment information. Please try again.';
        header('Location: reservation-details.php?id=' . $reservation_id);
        exit();
    } catch (Exception $e) {
        $db->rollBack();
        error_log("Error during payment initialization: " . $e->getMessage());
        $_SESSION['error'] = 'An error occurred. Please try again.';
        header('Location: reservation-details.php?id=' . $reservation_id);
        exit();
    }
    
} else {
    // Log the error details
    $error_message = $result['message'] ?? 'Unknown error';
    $error_code = $result['code'] ?? 'N/A';
    
    error_log("Paystack initialization failed - Reference: {$reference}, Error: {$error_message}, Code: {$error_code}");
    
    // Check for specific error types
    if (strpos(strtolower($error_message), 'email') !== false) {
        $_SESSION['error'] = 'Invalid email address. Please update your profile and try again.';
    } elseif (strpos(strtolower($error_message), 'amount') !== false) {
        $_SESSION['error'] = 'Invalid payment amount. Please contact support.';
    } else {
        $_SESSION['error'] = 'Payment initialization failed: ' . $error_message;
    }
    
    // Redirect back to reservation details
    header('Location: reservation-details.php?id=' . $reservation_id);
    exit();
}
?>