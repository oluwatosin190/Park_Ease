<?php
// Cron job status checker
require_once __DIR__ . '/../config/database.php';

$database = new Database();
$db = $database->getConnection();

echo "<h1>ParkEase Cron Job Status</h1>";

// Check expiry log
$expiry_log = __DIR__ . '/expiry_log.txt';
if (file_exists($expiry_log)) {
    $modified = filemtime($expiry_log);
    $minutes_ago = round((time() - $modified) / 60);
    
    echo "<h2>Expiry Checker</h2>";
    echo "Last run: " . date('Y-m-d H:i:s', $modified) . "<br>";
    
    if ($minutes_ago < 5) {
        echo "<span style='color:green; font-weight:bold;'>✅ RUNNING (last run {$minutes_ago} min ago)</span><br>";
    } else {
        echo "<span style='color:red; font-weight:bold;'>❌ NOT RUNNING (last run {$minutes_ago} min ago)</span><br>";
    }
    
    echo "<h3>Last 5 log entries:</h3>";
    echo "<pre>";
    $lines = file($expiry_log);
    $last_lines = array_slice($lines, -5);
    foreach ($last_lines as $line) {
        echo htmlspecialchars($line);
    }
    echo "</pre>";
}

// Check reminder log
$reminder_log = __DIR__ . '/reminder_log.txt';
if (file_exists($reminder_log)) {
    $modified = filemtime($reminder_log);
    $minutes_ago = round((time() - $modified) / 60);
    
    echo "<h2>Reminder Sender</h2>";
    echo "Last run: " . date('Y-m-d H:i:s', $modified) . "<br>";
    
    if ($minutes_ago < 5) {
        echo "<span style='color:green; font-weight:bold;'>✅ RUNNING (last run {$minutes_ago} min ago)</span><br>";
    } else {
        echo "<span style='color:red; font-weight:bold;'>❌ NOT RUNNING (last run {$minutes_ago} min ago)</span><br>";
    }
    
    echo "<h3>Last 5 log entries:</h3>";
    echo "<pre>";
    $lines = file($reminder_log);
    $last_lines = array_slice($lines, -5);
    foreach ($last_lines as $line) {
        echo htmlspecialchars($line);
    }
    echo "</pre>";
}

// Check for upcoming reminders
echo "<h2>Upcoming Reminders</h2>";

// 24h reminders
$query = "SELECT COUNT(*) as count FROM reservations 
          WHERE payment_status = 'paid' 
          AND timer_status = 'pending'
          AND TIMESTAMPDIFF(HOUR, NOW(), start_date) BETWEEN 23 AND 25
          AND reminder_24h_sent = 0";
$stmt = $db->query($query);
$count = $stmt->fetchColumn();
echo "<p>24h reminders pending: {$count}</p>";

// 1h reminders
$query = "SELECT COUNT(*) as count FROM reservations 
          WHERE payment_status = 'paid' 
          AND timer_status = 'pending'
          AND TIMESTAMPDIFF(MINUTE, NOW(), start_date) BETWEEN 55 AND 65
          AND reminder_1h_sent = 0";
$stmt = $db->query($query);
$count = $stmt->fetchColumn();
echo "<p>1h reminders pending: {$count}</p>";

// Active session reminders
$query = "SELECT COUNT(*) as count FROM reservations 
          WHERE timer_status = 'active'";
$stmt = $db->query($query);
$active = $stmt->fetchColumn();
echo "<p>Active sessions: {$active}</p>";

echo "<p><a href='test-expiry.php'>Run manual expiry check</a> | <a href='send-reminders.php'>Run manual reminders</a></p>";
?>