<?php
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once 'config/database.php';
require_once 'includes/commission-functions.php'; 

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

// Check if it's a POST request
if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header('Location: index.php');
    exit();
}

// Get POST data
$user_id = $_SESSION['user_id'];
$parking_id = $_POST['parking_id'] ?? 0;
$start_datetime = $_POST['start_datetime'] ?? '';
$end_datetime = $_POST['end_datetime'] ?? '';
$vehicle_number = $_POST['vehicle_number'] ?? '';
$vehicle_model = $_POST['vehicle_model'] ?? '';
$special_requests = $_POST['special_requests'] ?? '';
$payment_method = $_POST['payment_method'] ?? '';

// Validate required fields
if (!$parking_id || !$start_datetime || !$end_datetime || !$payment_method) {
    $_SESSION['error'] = 'Missing required fields';
    header('Location: book.php?id=' . $parking_id);
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    die("Database connection failed");
}

// Get parking space details
$query = "SELECT ps.*, u.id as owner_id 
          FROM parking_spaces ps
          JOIN users u ON ps.owner_id = u.id
          WHERE ps.id = :id AND ps.is_active = 1";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $parking_id);
$stmt->execute();
$space = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$space) {
    $_SESSION['error'] = 'Parking space not found';
    header('Location: index.php');
    exit();
}

if ($space['available_spots'] <= 0) {
    $_SESSION['error'] = 'Sorry, this parking space is currently fully booked.';
    header('Location: parking-details.php?id=' . $parking_id);
    exit();
}

// Check if user is booking their own space
if ($space['owner_id'] == $user_id) {
    $_SESSION['error'] = 'You cannot book your own parking space';
    header('Location: parking-details.php?id=' . $parking_id);
    exit();
}

// Format dates properly for MySQL
$start = date('Y-m-d H:i:s', strtotime($start_datetime));
$end = date('Y-m-d H:i:s', strtotime($end_datetime));

// Calculate hours
$start_obj = new DateTime($start);
$end_obj = new DateTime($end);
$interval = $start_obj->diff($end_obj);
$hours = $interval->h + ($interval->days * 24);
$minutes = $interval->i;
$total_hours = $hours + ($minutes / 60);

// =====  Calculate amount based on owner's rates =====
$total_amount = 0;
$rate_type = 'hourly';
$rate_amount = $space['hourly_rate'];

// Calculate hourly total
$hourly_total = $total_hours * $space['hourly_rate'];

// Initialize daily and monthly totals
$daily_total = PHP_INT_MAX; // Very high number
$monthly_total = PHP_INT_MAX;

// Check if daily rate exists and calculate
if (!empty($space['daily_rate']) && $space['daily_rate'] > 0) {
    $days = ceil($total_hours / 24);
    $daily_total = $days * $space['daily_rate'];
}

// Check if monthly rate exists and calculate (30 days)
if (!empty($space['monthly_rate']) && $space['monthly_rate'] > 0 && $total_hours >= 720) { // 30 days
    $months = ceil($total_hours / 720);
    $monthly_total = $months * $space['monthly_rate'];
}

// Choose the cheapest option for the customer
if ($monthly_total <= $daily_total && $monthly_total <= $hourly_total) {
    $total_amount = $monthly_total;
    $rate_type = 'monthly';
    $rate_amount = $space['monthly_rate'];
} elseif ($daily_total <= $hourly_total) {
    $total_amount = $daily_total;
    $rate_type = 'daily';
    $rate_amount = $space['daily_rate'];
} else {
    $total_amount = $hourly_total;
    $rate_type = 'hourly';
    $rate_amount = $space['hourly_rate'];
}

$total_amount = round($total_amount, 2);

// Calculate commission
$commission_manager = new CommissionManager($db);
$commission_details = $commission_manager->calculateCommission($total_amount);

// Generate booking reference
$booking_reference = 'PK' . strtoupper(substr(md5(uniqid()), 0, 8)) . date('ym');

// Begin transaction
$db->beginTransaction();

try {
    // Insert reservation
    $insert_query = "INSERT INTO reservations 
                     (booking_reference, parking_id, user_id, owner_id, start_date, end_date, 
                      vehicle_number, vehicle_model, total_hours, total_amount,
                      gross_amount, commission_amount, commission_rate, owner_payout,
                      rate_type, rate_amount, status, payment_status, payment_method) 
                     VALUES 
                     (:booking_reference, :parking_id, :user_id, :owner_id, :start_date, :end_date,
                      :vehicle_number, :vehicle_model, :total_hours, :total_amount,
                      :gross_amount, :commission_amount, :commission_rate, :owner_payout,
                      :rate_type, :rate_amount, 'pending', 'pending', :payment_method)";
    
    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->execute([
        ':booking_reference' => $booking_reference,
        ':parking_id' => $parking_id,
        ':user_id' => $user_id,
        ':owner_id' => $space['owner_id'],
        ':start_date' => $start,
        ':end_date' => $end,
        ':vehicle_number' => $vehicle_number,
        ':vehicle_model' => $vehicle_model,
        ':total_hours' => $total_hours,
        ':total_amount' => $total_amount,
        ':gross_amount' => $commission_details['gross_amount'],
        ':commission_amount' => $commission_details['commission_amount'],
        ':commission_rate' => $commission_details['commission_rate'],
        ':owner_payout' => $commission_details['owner_payout'],
        ':rate_type' => $rate_type,
        ':rate_amount' => $rate_amount,
        ':payment_method' => $payment_method
    ]);
    
    $reservation_id = $db->lastInsertId();
    
    // Record platform earnings
    $commission_manager->recordPlatformEarnings($reservation_id, $commission_details);
    
    // Insert payment record
    $payment_query = "INSERT INTO payments (reservation_id, transaction_id, amount, payment_method, payment_status) 
                      VALUES (:reservation_id, :transaction_id, :amount, :payment_method, 'pending')";
    $payment_stmt = $db->prepare($payment_query);
    $payment_stmt->execute([
        ':reservation_id' => $reservation_id,
        ':transaction_id' => $booking_reference,
        ':amount' => $total_amount,
        ':payment_method' => $payment_method
    ]);
    
    $db->commit();
    
    // Set success message
    $_SESSION['success'] = 'Booking created successfully! Reference: ' . $booking_reference;

    // After successful booking creation, decrement available spots
$update_spots = "UPDATE parking_spaces SET available_spots = available_spots - 1 WHERE id = :id AND available_spots > 0";
$update_stmt = $db->prepare($update_spots);
$update_stmt->bindParam(':id', $parking_id);
$update_stmt->execute();
    
    // Send email notification
    require_once 'includes/email-functions.php';
    $emailer = new EmailNotifications($db);
    $emailer->sendBookingConfirmation($reservation_id);
    
    // Redirect to payment
    header('Location: process-payment.php?id=' . $reservation_id);
    exit();
    
} catch (PDOException $e) {
    $db->rollBack();
    die("Database Error: " . $e->getMessage());
} catch (Exception $e) {
    $db->rollBack();
    die("General Error: " . $e->getMessage());
}
?>