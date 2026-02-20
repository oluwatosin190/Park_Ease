<?php
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get reservation details to check ownership and cancellation eligibility
$query = "SELECT r.*, 
          u.first_name, u.last_name,
          ps.name as parking_name
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
    $_SESSION['error'] = 'Reservation not found.';
    header('Location: my-reservations.php');
    exit();
}

// Check if cancellation is allowed (within 1 hour of start time)
$start = new DateTime($reservation['start_date']);
$now = new DateTime();
$can_cancel = ($reservation['status'] == 'pending' || $reservation['status'] == 'confirmed') && 
               ($now < $start->sub(new DateInterval('PT1H')));

if (!$can_cancel) {
    $_SESSION['error'] = 'This reservation cannot be cancelled.';
    header('Location: reservation-details.php?id=' . $reservation_id);
    exit();
}

// Update reservation status
$update_query = "UPDATE reservations SET status = 'cancelled', payment_status = 'refunded' WHERE id = :id";
$update_stmt = $db->prepare($update_query);
$update_stmt->bindParam(':id', $reservation_id);

if ($update_stmt->execute()) {
    $_SESSION['success'] = 'Reservation cancelled successfully.';
} else {
    $_SESSION['error'] = 'Failed to cancel reservation.';
}

header('Location: reservation-details.php?id=' . $reservation_id);
exit();
?>