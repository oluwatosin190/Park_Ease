<?php
require_once 'config/database.php';
require_once 'includes/notification-manager.php';

echo "<h2>Force Update Expired Bookings</h2>";

$database = new Database();
$db = $database->getConnection();

// Get all active bookings that have expired
$query = "SELECT r.*, u.first_name, u.last_name, u.email,
          ps.name as parking_name, o.email as owner_email
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          JOIN parking_spaces ps ON r.parking_id = ps.id
          JOIN users o ON ps.owner_id = o.id
          WHERE r.timer_status = 'active' 
          AND r.actual_end_time < NOW()";

$stmt = $db->prepare($query);
$stmt->execute();
$expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Found " . count($expired) . " expired bookings</p>";

if (count($expired) > 0) {
    echo "<ul>";
    foreach ($expired as $booking) {
        echo "<li>Booking #{$booking['id']}: {$booking['first_name']} {$booking['last_name']} - {$booking['parking_name']}</li>";
        
        // Update the booking
        $update = $db->prepare("UPDATE reservations SET 
                                 timer_status = 'completed',
                                 status = 'completed',
                                 overstay_minutes = 0,
                                 overstay_charge = 0
                                 WHERE id = ?");
        $update->execute([$booking['id']]);
        
        echo " → Updated to COMPLETED<br>";
    }
    echo "</ul>";
    echo "<p style='color:green; font-weight:bold;'>✅ " . count($expired) . " bookings updated to completed</p>";
} else {
    echo "<p style='color:green;'>✅ No expired bookings found</p>";
}

// Check for pending no-shows
$cancel_minutes = 60;
$query = "SELECT r.* FROM reservations r
          WHERE r.payment_status = 'paid'
          AND r.timer_status = 'pending'
          AND TIMESTAMPDIFF(MINUTE, r.start_date, NOW()) > ?";

$stmt = $db->prepare($query);
$stmt->execute([$cancel_minutes]);
$no_shows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<h3>No-Shows (after {$cancel_minutes} minutes)</h3>";
echo "<p>Found " . count($no_shows) . " no-show bookings</p>";

if (count($no_shows) > 0) {
    foreach ($no_shows as $booking) {
        $update = $db->prepare("UPDATE reservations SET 
                                 timer_status = 'cancelled',
                                 status = 'cancelled',
                                 no_show = 1
                                 WHERE id = ?");
        $update->execute([$booking['id']]);
    }
}

echo "<hr>";
echo "<p><a href='owner/active-sessions.php'>Go to Active Sessions</a></p>";
echo "<p><a href='owner-reservations.php'>Go to Manage Bookings</a></p>";
?>