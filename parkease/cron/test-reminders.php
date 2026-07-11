<?php
// Manual test for reminder sender
require_once __DIR__ . '/../config/database.php';

echo "<h2>Testing Reminder Sender</h2>";

// Check what reminders would be sent
$database = new Database();
$db = $database->getConnection();

// Check 24h reminders
$query = "SELECT r.*, u.email, u.first_name, u.last_name, 
          ps.name as parking_name
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          JOIN parking_spaces ps ON r.parking_id = ps.id
          WHERE r.payment_status = 'paid'
          AND r.timer_status = 'pending'
          AND TIMESTAMPDIFF(HOUR, NOW(), r.start_date) BETWEEN 23 AND 25
          AND r.reminder_24h_sent = 0";

$stmt = $db->prepare($query);
$stmt->execute();
$reminders_24h = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>24h reminders ready to send: " . count($reminders_24h) . "</p>";

// Check 1h reminders
$query = "SELECT r.*, u.email, u.first_name, u.last_name, 
          ps.name as parking_name
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          JOIN parking_spaces ps ON r.parking_id = ps.id
          WHERE r.payment_status = 'paid'
          AND r.timer_status = 'pending'
          AND TIMESTAMPDIFF(MINUTE, NOW(), r.start_date) BETWEEN 55 AND 65
          AND r.reminder_1h_sent = 0";

$stmt = $db->prepare($query);
$stmt->execute();
$reminders_1h = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>1h reminders ready to send: " . count($reminders_1h) . "</p>";

// Check active session reminders
$query = "SELECT r.*, TIMESTAMPDIFF(MINUTE, NOW(), r.actual_end_time) as minutes_left
          FROM reservations r
          WHERE r.timer_status = 'active'
          AND r.actual_end_time > NOW()";

$stmt = $db->prepare($query);
$stmt->execute();
$active = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "<p>Active sessions needing reminders: " . count($active) . "</p>";

if (count($active) > 0) {
    echo "<ul>";
    foreach ($active as $a) {
        echo "<li>Booking #{$a['id']}: {$a['minutes_left']} minutes left</li>";
    }
    echo "</ul>";
}

echo "<p><a href='send-reminders.php'>Run send-reminders.php manually</a></p>";
?>