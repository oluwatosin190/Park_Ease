<?php
// Manual test for expiry checker
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notification-manager.php';

echo "<h2>Testing Expiry Checker</h2>";

$database = new Database();
$db = $database->getConnection();

// Check for expired active bookings
$query = "SELECT r.*, u.first_name, u.last_name, u.email,
          ps.name as parking_name
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          JOIN parking_spaces ps ON r.parking_id = ps.id
          WHERE r.timer_status = 'active' 
          AND r.actual_end_time < NOW()";

$stmt = $db->prepare($query);
$stmt->execute();
$expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Found " . count($expired) . " expired active bookings</p>";

if (count($expired) > 0) {
    echo "<ul>";
    foreach ($expired as $booking) {
        echo "<li>Booking #{$booking['id']}: {$booking['first_name']} {$booking['last_name']} - {$booking['parking_name']}</li>";
    }
    echo "</ul>";
}

// Check for pending reminders
echo "<h3>Checking send-reminders.php manually...</h3>";
include __DIR__ . '/send-reminders.php';

echo "<p><a href='../owner/active-sessions.php'>Go to Active Sessions</a></p>";
?>