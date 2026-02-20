<?php
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header('Location: index.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];
$parking_id = $_POST['parking_id'];
$start_datetime = $_POST['start_datetime'];
$end_datetime = $_POST['end_datetime'];
$vehicle_number = $_POST['vehicle_number'] ?? '';
$vehicle_model = $_POST['vehicle_model'] ?? '';
$special_requests = $_POST['special_requests'] ?? '';
$payment_method = $_POST['payment_method'];

// Validate inputs
if (empty($start_datetime) || empty($end_datetime)) {
    $_SESSION['error'] = 'Please select start and end times.';
    header('Location: book.php?id=' . $parking_id);
    exit();
}

if (empty($payment_method)) {
    $_SESSION['error'] = 'Please select a payment method.';
    header('Location: book.php?id=' . $parking_id);
    exit();
}

// Get parking space details and owner info
$query = "SELECT ps.*, u.id as owner_id 
          FROM parking_spaces ps
          JOIN users u ON ps.owner_id = u.id
          WHERE ps.id = :id AND ps.is_active = 1";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $parking_id);
$stmt->execute();
$space = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$space) {
    $_SESSION['error'] = 'Parking space not found.';
    header('Location: index.php');
    exit();
}

// Check if user is booking their own space
if ($space['owner_id'] == $user_id) {
    $_SESSION['error'] = 'You cannot book your own parking space.';
    header('Location: parking-details.php?id=' . $parking_id);
    exit();
}

// Check if space is available
$check_query = "SELECT COUNT(*) as count FROM reservations 
                WHERE parking_id = :parking_id 
                AND status IN ('pending', 'confirmed', 'active')
                AND (
                    (start_date <= :end_date AND end_date >= :start_date)
                )";
$check_stmt = $db->prepare($check_query);
$check_stmt->bindParam(':parking_id', $parking_id);
$check_stmt->bindParam(':start_date', $start_datetime);
$check_stmt->bindParam(':end_date', $end_datetime);
$check_stmt->execute();
$result = $check_stmt->fetch(PDO::FETCH_ASSOC);

if ($result['count'] > 0) {
    $_SESSION['error'] = 'This time slot is already booked. Please choose different times.';
    header('Location: book.php?id=' . $parking_id);
    exit();
}

// Validate that end time is after start time
$start = new DateTime($start_datetime);
$end = new DateTime($end_datetime);

if ($end <= $start) {
    $_SESSION['error'] = 'End time must be after start time.';
    header('Location: book.php?id=' . $parking_id);
    exit();
}

// Calculate duration
$interval = $start->diff($end);
$hours = $interval->h + ($interval->days * 24);
$minutes = $interval->i;
$total_hours = $hours + ($minutes / 60);

// Calculate total amount based on duration
$total_amount = 0;
$rate_type = 'hourly';
$rate_amount = $space['hourly_rate'];

// Check if daily rate applies and is better value
if ($space['daily_rate'] && $total_hours >= 24) {
    $days = ceil($total_hours / 24);
    $daily_total = $days * $space['daily_rate'];
    $hourly_total = $total_hours * $space['hourly_rate'];
    
    // Use whichever is cheaper for the customer
    if ($daily_total <= $hourly_total) {
        $total_amount = $daily_total;
        $rate_type = 'daily';
        $rate_amount = $space['daily_rate'];
    } else {
        $total_amount = $hourly_total;
    }
} else {
    $total_amount = $total_hours * $space['hourly_rate'];
}

// Round to 2 decimal places
$total_amount = round($total_amount, 2);

// Function to generate unique booking reference
function generateBookingReference($db) {
    $prefix = 'PK';
    $attempts = 0;
    $maxAttempts = 10;
    
    do {
        // Generate reference: PK + 8 random chars + YYMM
        $random = strtoupper(substr(md5(uniqid(mt_rand(), true)), 0, 8));
        $date = date('ym');
        $reference = $prefix . $random . $date;
        
        // Check if reference exists
        $check = $db->prepare("SELECT id FROM reservations WHERE booking_reference = ?");
        $check->execute([$reference]);
        $exists = $check->fetch();
        
        $attempts++;
        if ($attempts >= $maxAttempts) {
            // Fallback to timestamp-based reference
            $reference = $prefix . date('YmdHis') . rand(100, 999);
            break;
        }
    } while ($exists);
    
    return $reference;
}

// Generate booking reference
$booking_reference = generateBookingReference($db);

// Begin transaction
$db->beginTransaction();

try {
    // Insert reservation with booking_reference
    $insert_query = "INSERT INTO reservations 
                     (booking_reference, parking_id, user_id, owner_id, start_date, end_date, 
                      vehicle_number, vehicle_model, total_hours, total_amount, 
                      rate_type, rate_amount, status, payment_status, special_requests, payment_method) 
                     VALUES 
                     (:booking_reference, :parking_id, :user_id, :owner_id, :start_date, :end_date,
                      :vehicle_number, :vehicle_model, :total_hours, :total_amount,
                      :rate_type, :rate_amount, 'pending', 'pending', :special_requests, :payment_method)";
    
    $insert_stmt = $db->prepare($insert_query);
    $insert_stmt->bindParam(':booking_reference', $booking_reference);
    $insert_stmt->bindParam(':parking_id', $parking_id);
    $insert_stmt->bindParam(':user_id', $user_id);
    $insert_stmt->bindParam(':owner_id', $space['owner_id']);
    $insert_stmt->bindParam(':start_date', $start_datetime);
    $insert_stmt->bindParam(':end_date', $end_datetime);
    $insert_stmt->bindParam(':vehicle_number', $vehicle_number);
    $insert_stmt->bindParam(':vehicle_model', $vehicle_model);
    $insert_stmt->bindParam(':total_hours', $total_hours);
    $insert_stmt->bindParam(':total_amount', $total_amount);
    $insert_stmt->bindParam(':rate_type', $rate_type);
    $insert_stmt->bindParam(':rate_amount', $rate_amount);
    $insert_stmt->bindParam(':special_requests', $special_requests);
    $insert_stmt->bindParam(':payment_method', $payment_method);
    
    $insert_stmt->execute();
    $reservation_id = $db->lastInsertId();
    
    // Insert payment record
    $payment_query = "INSERT INTO payments (reservation_id, transaction_id, amount, payment_method, payment_status) 
                      VALUES (:reservation_id, :transaction_id, :amount, :payment_method, 'pending')";
    $payment_stmt = $db->prepare($payment_query);
    $payment_stmt->bindParam(':reservation_id', $reservation_id);
    $payment_stmt->bindParam(':transaction_id', $booking_reference);
    $payment_stmt->bindParam(':amount', $total_amount);
    $payment_stmt->bindParam(':payment_method', $payment_method);
    $payment_stmt->execute();
    
    // Commit transaction
    $db->commit();
    
    // Set success message and redirect
    $_SESSION['success'] = 'Booking created successfully! Your booking reference is: ' . $booking_reference;
    // After successful booking, send email notification
        require_once 'includes/email-functions.php';
        $emailer = new EmailNotifications($db);
        $emailer->sendBookingConfirmation($reservation_id);
    header('Location: reservation-details.php?id=' . $reservation_id);
    exit();
    
} catch (PDOException $e) {
    // Rollback transaction on error
    $db->rollBack();
    
    // Log error (you might want to log to a file in production)
    error_log("Booking Error: " . $e->getMessage());
    
    // Set friendly error message
    $_SESSION['error'] = 'Failed to create booking. Please try again.';
    header('Location: book.php?id=' . $parking_id);
    exit();
} catch (Exception $e) {
    // Rollback transaction on error
    $db->rollBack();
    
    error_log("Booking Error: " . $e->getMessage());
    $_SESSION['error'] = 'An unexpected error occurred. Please try again.';
    header('Location: book.php?id=' . $parking_id);
    exit();
}
?>