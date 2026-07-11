<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';
require_once 'includes/paystack-api.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get reservation ID from URL
$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// If no ID in URL, try to get the most recent pending reservation
if ($reservation_id == 0) {
    $query = "SELECT id FROM reservations 
              WHERE user_id = :user_id AND payment_status = 'pending' 
              ORDER BY id DESC LIMIT 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id']);
    $stmt->execute();
    $recent = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($recent) {
        $reservation_id = $recent['id'];
    } else {
        die('No pending reservation found. <a href="dashboard.php">Go to Dashboard</a>');
    }
}

// Get reservation details
$query = "SELECT r.*, u.email, u.first_name, u.last_name, ps.name as parking_name 
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          JOIN parking_spaces ps ON r.parking_id = ps.id
          WHERE r.id = :id AND r.user_id = :user_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $reservation_id);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    die('Reservation not found. <a href="dashboard.php">Go to Dashboard</a>');
}

// Check if already paid
if ($reservation['payment_status'] == 'paid') {
    header('Location: reservation-details.php?id=' . $reservation_id);
    exit();
}

// Initialize Paystack
$paystack = new PaystackAPI();

// Generate unique reference
$reference = 'PAY_' . $reservation['booking_reference'] . '_' . time();

// Prepare metadata
$metadata = [
    'reservation_id' => $reservation_id,
    'user_id' => $_SESSION['user_id'],
    'booking_reference' => $reservation['booking_reference']
];

// Initialize transaction
$result = $paystack->initializeTransaction(
    $reservation['email'],
    $reservation['total_amount'],
    $reference,
    $metadata
);

if ($result['status']) {
    // Save Paystack reference
    $update = "UPDATE reservations SET paystack_reference = :ref, paystack_access_code = :code WHERE id = :id";
    $update_stmt = $db->prepare($update);
    $update_stmt->bindParam(':ref', $reference);
    $update_stmt->bindParam(':code', $result['access_code']);
    $update_stmt->bindParam(':id', $reservation_id);
    $update_stmt->execute();
    
    // Redirect to Paystack
    header('Location: ' . $result['authorization_url']);
    exit();
} else {
    die('Payment initialization failed: ' . $result['message']);
}
?>