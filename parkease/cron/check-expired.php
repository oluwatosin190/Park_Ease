<?php
// Prevent multiple instances
$lock_file = __DIR__ . '/cron.lock';

// Check if lock file exists and is less than 5 minutes old
if (file_exists($lock_file)) {
    $lock_time = filemtime($lock_file);
    if (time() - $lock_time < 300) { // 5 minutes
        // Another instance is running, exit
        exit(0);
    }
}

// Create lock file
file_put_contents($lock_file, date('Y-m-d H:i:s'));

// Remove lock file when script ends
register_shutdown_function(function() use ($lock_file) {
    if (file_exists($lock_file)) {
        unlink($lock_file);
    }
});



// Prevent timeout for long-running processes
set_time_limit(0);

// Error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Define log file
define('CRON_LOG_FILE', __DIR__ . '/expiry_log.txt');

// Start execution
$timestamp = date('Y-m-d H:i:s');
writeLog("=== CRON JOB STARTED: Check Expired Bookings ===");

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/notification-manager.php';

/**
 * Write to log file
 */
function writeLog($message) {
    global $timestamp;
    $log_entry = "[$timestamp] $message\n";
    file_put_contents(CRON_LOG_FILE, $log_entry, FILE_APPEND);
    echo $log_entry;
}

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        writeLog("ERROR: Database connection failed");
        exit(1);
    }
    
    writeLog("Database connected successfully");
    
    $notifier = new NotificationManager($db);
    
    $now = date('Y-m-d H:i:s');
    $results = [
        'expired_active' => 0,
        'no_shows' => 0,
        'notifications' => 0
    ];
    
    // ============================================
    // PART 1: Check ACTIVE bookings that have expired
    // ============================================
    writeLog("Checking ACTIVE bookings that have passed their end time...");
    
    $query = "SELECT r.*, 
              u.email as customer_email, 
              u.first_name, 
              u.last_name,
              u.phone,
              ps.name as parking_name,
              ps.address,
              ps.city,
              o.email as owner_email,
              o.first_name as owner_first_name,
              o.last_name as owner_last_name
              FROM reservations r
              JOIN users u ON r.user_id = u.id
              JOIN parking_spaces ps ON r.parking_id = ps.id
              JOIN users o ON ps.owner_id = o.id
              WHERE r.timer_status = 'active' 
              AND r.actual_end_time < :now";
    
    $stmt = $db->prepare($query);
    $stmt->execute([':now' => $now]);
    $expired_active = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    writeLog("Found " . count($expired_active) . " expired active bookings");
    
    // Get overstay settings
    $flat_fee = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'overstay_flat_fee'")->fetchColumn();
    $flat_fee = $flat_fee ?: 500;
    
    $max_charge = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'overstay_max_charge'")->fetchColumn();
    $max_charge = $max_charge ?: 5000;
    
    foreach ($expired_active as $booking) {
        $booking_id = $booking['id'];
        
        // Calculate overstay minutes
        $actual_end = new DateTime($booking['actual_end_time']);
        $current = new DateTime();
        
        if ($current > $actual_end) {
            $overstay = $current->diff($actual_end);
            $overstay_minutes = ($overstay->days * 24 * 60) + ($overstay->h * 60) + $overstay->i;
            
            // Calculate overstay charge (flat fee, capped)
            $overstay_charge = min($flat_fee, $max_charge);
            
            writeLog("Booking #{$booking_id}: {$booking['first_name']} {$booking['last_name']} - Overstay: {$overstay_minutes} mins, Charge: ₦{$overstay_charge}");
        } else {
            $overstay_minutes = 0;
            $overstay_charge = 0;
            writeLog("Booking #{$booking_id}: {$booking['first_name']} {$booking['last_name']} - Session ended on time");
        }
        
        //  Update booking to PENDING CHECKOUT (not completed) =====
        $update = $db->prepare("UPDATE reservations SET 
                                 timer_status = 'pending_checkout',
                                 checkout_status = 'pending',
                                 overstay_minutes = :minutes,
                                 overstay_charge = :charge,
                                 owner_departure_notified = 0
                                 WHERE id = :id");
        $update->execute([
            ':minutes' => $overstay_minutes,
            ':charge' => $overstay_charge,
            ':id' => $booking_id
        ]);
        
        $results['expired_active']++;
        
        // Send notification to customer that time is up (not completed yet)
        if (method_exists($notifier, 'sendTimeUpNotification')) {
            $notifier->sendTimeUpNotification($booking_id, $overstay_charge);
        } else {
            // Fallback to expired notification if method doesn't exist
            $notifier->sendExpired($booking_id, $overstay_charge);
        }
        
        $results['notifications']++;
        
        // Send SMS if phone exists
        if (!empty($booking['phone'])) {
            $sms_message = "Your parking at {$booking['parking_name']} has ended. Please check out with the owner.";
            if ($overstay_charge > 0) {
                $sms_message .= " Overstay charge: ₦{$overstay_charge}";
            }
            // You'll need to implement SMS sending
            // $sms->sendSMS($booking['phone'], $sms_message);
        }
    }
    
    // ============================================
    // PART 2: Check for NO-SHOW bookings (past grace period)
    // ============================================
    writeLog("Checking for NO-SHOW bookings (past grace period)...");
    
    $grace_minutes = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'grace_period_minutes'")->fetchColumn();
    $grace_minutes = $grace_minutes ?: 30;
    
    $cancel_minutes = $db->query("SELECT setting_value FROM system_settings WHERE setting_key = 'no_show_cancel_minutes'")->fetchColumn();
    $cancel_minutes = $cancel_minutes ?: 60;
    
    $query = "SELECT r.*, 
              u.email as customer_email, 
              u.first_name, 
              u.last_name,
              u.phone,
              ps.name as parking_name,
              ps.address,
              o.email as owner_email,
              o.first_name as owner_first_name
              FROM reservations r
              JOIN users u ON r.user_id = u.id
              JOIN parking_spaces ps ON r.parking_id = ps.id
              JOIN users o ON ps.owner_id = o.id
              WHERE r.payment_status = 'paid'
              AND r.timer_status = 'pending'
              AND TIMESTAMPDIFF(MINUTE, r.start_date, :now) > :cancel_minutes";
    
    $stmt = $db->prepare($query);
    $stmt->execute([
        ':now' => $now,
        ':cancel_minutes' => $cancel_minutes
    ]);
    $no_shows = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    writeLog("Found " . count($no_shows) . " no-show bookings (after {$cancel_minutes} minutes)");
    
    foreach ($no_shows as $booking) {
        $booking_id = $booking['id'];
        
        writeLog("Booking #{$booking_id}: {$booking['first_name']} {$booking['last_name']} - No-show, cancelling");
        
        // Update booking status
        $update = $db->prepare("UPDATE reservations SET 
                                 timer_status = 'cancelled',
                                 status = 'cancelled',
                                 no_show = 1
                                 WHERE id = :id");
        $update->execute([':id' => $booking_id]);
        
        $results['no_shows']++;
        
        // Send notification to customer
        $subject = "Booking Cancelled - No Show - ParkEase";
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; }
                .header { background: #DC2626; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #F9FAFB; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Booking Cancelled</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$booking['first_name']}</strong>,</p>
                    <p>Your booking at <strong>{$booking['parking_name']}</strong> has been cancelled because you didn't arrive within {$cancel_minutes} minutes of your scheduled time.</p>
                    <p>If you still need parking, please make a new booking.</p>
                    <p><a href='http://localhost/Park_Ease/parkease/index.php' style='background: #4F6EF7; color: white; padding: 10px 20px; text-decoration: none; border-radius: 5px;'>Book Again</a></p>
                </div>
            </div>
        </body>
        </html>";
        
        $headers = "MIME-Version: 1.0\r\n";
        $headers .= "Content-type:text/html;charset=UTF-8\r\n";
        $headers .= "From: ParkEase <noreply@parkease.com>\r\n";
        mail($booking['customer_email'], $subject, $message, $headers);
        
        // Notify owner
        $owner_subject = "No-Show: Space Now Available - ParkEase";
        $owner_message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; }
                .header { background: #10B981; color: white; padding: 20px; text-align: center; }
                .content { padding: 20px; background: #F9FAFB; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Space Now Available</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$booking['owner_first_name']}</strong>,</p>
                    <p>The booking for <strong>{$booking['first_name']} {$booking['last_name']}</strong> at <strong>{$booking['parking_name']}</strong> has been cancelled due to no-show.</p>
                    <p>The space is now available for other customers.</p>
                </div>
            </div>
        </body>
        </html>";
        
        mail($booking['owner_email'], $owner_subject, $owner_message, $headers);
    }
    
    // ============================================
    // PART 3: Summary
    // ============================================
    writeLog("=== CRON JOB SUMMARY ===");
    writeLog("Active bookings expired (now pending checkout): {$results['expired_active']}");
    writeLog("No-show bookings cancelled: {$results['no_shows']}");
    writeLog("Notifications triggered: {$results['notifications']}");
    writeLog("=== CRON JOB COMPLETED SUCCESSFULLY ===\n");
    
} catch (Exception $e) {
    writeLog("ERROR: " . $e->getMessage());
    writeLog("File: " . $e->getFile() . " Line: " . $e->getLine());
    writeLog("Stack trace: " . $e->getTraceAsString());
    exit(1);
}
?>