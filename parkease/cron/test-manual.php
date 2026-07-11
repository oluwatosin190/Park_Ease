<?php
// Manual test for cron job
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notification-manager.php';

echo "<h2>Manual Cron Job Test</h2>";

$database = new Database();
$db = $database->getConnection();

// Get all active bookings that have expired
$query = "SELECT r.*, u.first_name, u.last_name 
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          WHERE r.timer_status = 'active' 
          AND r.actual_end_time < NOW()";

$stmt = $db->prepare($query);
$stmt->execute();
$expired = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Found " . count($expired) . " expired bookings</p>";

if (count($expired) > 0) {
    echo "<h3>Expired Bookings:</h3>";
    echo "<pre>";
    print_r($expired);
    echo "</pre>";
    
    // Update them manually
    foreach ($expired as $booking) {
        $update = $db->prepare("UPDATE reservations SET 
                                 timer_status = 'completed',
                                 status = 'completed'
                                 WHERE id = ?");
        $update->execute([$booking['id']]);
        echo "<p>✅ Updated booking #{$booking['id']} to completed</p>";
    }
} else {
    echo "<p>✅ No expired bookings found</p>";
}

// Check for no-shows
$cancel_minutes = 60;
$query = "SELECT r.* FROM reservations r
          WHERE r.payment_status = 'paid'
          AND r.timer_status = 'pending'
          AND TIMESTAMPDIFF(MINUTE, r.start_date, NOW()) > ?";

$stmt = $db->prepare($query);
$stmt->execute([$cancel_minutes]);
$no_shows = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Found " . count($no_shows) . " no-show bookings</p>";

echo "<p><a href='../owner/active-sessions.php'>Go to Active Sessions</a></p>";
?>