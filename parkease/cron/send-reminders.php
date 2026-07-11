<?php


set_time_limit(0);
error_reporting(E_ALL);
ini_set('display_errors', 1);

define('CRON_LOG', __DIR__ . '/reminder_log.txt');

function writeLog($message) {
    $timestamp = date('Y-m-d H:i:s');
    file_put_contents(CRON_LOG, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

writeLog("=== CRON: Sending All Notifications ===");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notification-manager.php';

$database = new Database();
$db = $database->getConnection();
$notifier = new NotificationManager($db);

$now = time();
$results = [
    'reminder_24h' => 0,
    'reminder_1h' => 0,
    'timer_started' => 0,
    'reminder_30' => 0,
    'reminder_15' => 0,
    'reminder_5' => 0,
    'arrival' => 0,
    'departure' => 0
];

// ============================================
// PART 1: Send 24-hour reminders BEFORE start time
// ============================================
writeLog("Checking for bookings starting in 24 hours...");

$query = "SELECT r.*, u.email, u.first_name, u.last_name, 
          ps.name as parking_name, ps.address
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

foreach ($reminders_24h as $booking) {
    $notifier->sendStartingSoonReminder($booking['id'], 24);
    
    $update = $db->prepare("UPDATE reservations SET reminder_24h_sent = 1 WHERE id = :id");
    $update->execute([':id' => $booking['id']]);
    
    $results['reminder_24h']++;
    writeLog("Sent 24-hour reminder for booking #{$booking['id']}");
}

// ============================================
// PART 2: Send 1-hour reminders BEFORE start time
// ============================================
writeLog("Checking for bookings starting in 1 hour...");

$query = "SELECT r.*, u.email, u.first_name, u.last_name, 
          ps.name as parking_name, ps.address
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

foreach ($reminders_1h as $booking) {
    $notifier->sendStartingSoonReminder($booking['id'], 1);
    
    $update = $db->prepare("UPDATE reservations SET reminder_1h_sent = 1 WHERE id = :id");
    $update->execute([':id' => $booking['id']]);
    
    $results['reminder_1h']++;
    writeLog("Sent 1-hour reminder for booking #{$booking['id']}");
}

// ============================================
// PART 3: Send timer started notification (when session becomes active)
// ============================================
writeLog("Checking for newly started sessions to notify users...");

$query = "SELECT r.id FROM reservations r
          WHERE r.timer_status = 'active'
          AND r.actual_start_time > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
          AND r.timer_started_notified = 0";

$stmt = $db->prepare($query);
$stmt->execute();
$started = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($started as $session) {
    $notifier->sendTimerStarted($session['id']);
    
    $update = $db->prepare("UPDATE reservations SET timer_started_notified = 1 WHERE id = :id");
    $update->execute([':id' => $session['id']]);
    
    $results['timer_started']++;
    writeLog("Sent timer started notification for booking #{$session['id']}");
}

// ============================================
// PART 4: Send owner arrival notifications
// ============================================
writeLog("Checking for new arrivals to notify owners...");

$query = "SELECT r.id FROM reservations r
          WHERE r.timer_status = 'active'
          AND r.actual_start_time > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
          AND r.owner_arrival_notified = 0";

$stmt = $db->prepare($query);
$stmt->execute();
$arrivals = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($arrivals as $arrival) {
    $notifier->sendOwnerArrival($arrival['id']);
    
    $update = $db->prepare("UPDATE reservations SET owner_arrival_notified = 1 WHERE id = :id");
    $update->execute([':id' => $arrival['id']]);
    
    $results['arrival']++;
    writeLog("Sent arrival notification for booking #{$arrival['id']}");
}

// ============================================
// PART 5: Send owner departure notifications (handled by check-expired.php)
// ============================================
writeLog("Checking for new departures to notify owners...");

$query = "SELECT r.id, r.overstay_charge FROM reservations r
          WHERE r.status = 'completed'
          AND r.updated_at > DATE_SUB(NOW(), INTERVAL 2 MINUTE)
          AND r.owner_departure_notified = 0";

$stmt = $db->prepare($query);
$stmt->execute();
$departures = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($departures as $departure) {
    $notifier->sendOwnerDeparture($departure['id'], $departure['overstay_charge']);
    
    $update = $db->prepare("UPDATE reservations SET owner_departure_notified = 1 WHERE id = :id");
    $update->execute([':id' => $departure['id']]);
    
    $results['departure']++;
    writeLog("Sent departure notification for booking #{$departure['id']}");
}

// ============================================
// PART 6: Send time reminders to customers (during active session)
// ============================================
writeLog("Checking for active bookings needing reminders...");

$query = "SELECT r.*, u.email, u.first_name, u.last_name,
          TIMESTAMPDIFF(MINUTE, NOW(), r.actual_end_time) as minutes_left
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          WHERE r.timer_status = 'active'
          AND r.actual_end_time > NOW()";

$stmt = $db->prepare($query);
$stmt->execute();
$active = $stmt->fetchAll(PDO::FETCH_ASSOC);

foreach ($active as $booking) {
    $minutes_left = $booking['minutes_left'];
    $booking_id = $booking['id'];
    
    // 30-minute reminder
    if ($minutes_left <= 32 && $minutes_left > 28 && !$booking['reminder_30_sent']) {
        $notifier->sendReminder($booking_id, 30);
        $update = $db->prepare("UPDATE reservations SET reminder_30_sent = 1 WHERE id = :id");
        $update->execute([':id' => $booking_id]);
        $results['reminder_30']++;
        writeLog("Sent 30-min reminder for booking #{$booking_id}");
    }
    
    // 15-minute reminder
    if ($minutes_left <= 17 && $minutes_left > 13 && !$booking['reminder_15_sent']) {
        $notifier->sendReminder($booking_id, 15);
        $update = $db->prepare("UPDATE reservations SET reminder_15_sent = 1 WHERE id = :id");
        $update->execute([':id' => $booking_id]);
        $results['reminder_15']++;
        writeLog("Sent 15-min reminder for booking #{$booking_id}");
    }
    
    // 5-minute reminder
    if ($minutes_left <= 7 && $minutes_left > 3 && !$booking['reminder_5_sent']) {
        $notifier->sendReminder($booking_id, 5);
        $update = $db->prepare("UPDATE reservations SET reminder_5_sent = 1 WHERE id = :id");
        $update->execute([':id' => $booking_id]);
        $results['reminder_5']++;
        writeLog("Sent 5-min reminder for booking #{$booking_id}");
    }
}

writeLog("=== CRON Complete: 24h:{$results['reminder_24h']}, 1h:{$results['reminder_1h']}, Started:{$results['timer_started']}, 30-min:{$results['reminder_30']}, 15-min:{$results['reminder_15']}, 5-min:{$results['reminder_5']}, Arrivals:{$results['arrival']}, Departures:{$results['departure']} ===");
?>