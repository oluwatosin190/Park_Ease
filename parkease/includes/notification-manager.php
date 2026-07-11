<?php
/**
 * Notification Manager for SpaceNode
 * Handles all email notifications for timer events
 */

require_once __DIR__ . '/email-functions.php';
require_once __DIR__ . '/../config/email-config.php';

class NotificationManager {
    private $db;
    private $emailer;
    
    public function __construct($db) {
        $this->db = $db;
        $this->emailer = new EmailNotifications($db);
    }
    
    /**
     * Send timer started notification to customer
     */
    public function sendTimerStarted($reservation_id) {
        $query = "SELECT r.*, u.email, u.first_name, u.last_name, 
                  ps.name as parking_name, ps.address
                  FROM reservations r
                  JOIN users u ON r.user_id = u.id
                  JOIN parking_spaces ps ON r.parking_id = ps.id
                  WHERE r.id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $reservation_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) return false;
        
        $subject = "✅ Your Parking Session Has Started - SpaceNode";
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; }
                .header { background: #10B981; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 20px; background: #F9FAFB; }
                .details { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #10B981; }
                .button { background: #10B981; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>✅ Session Started!</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$booking['first_name']} {$booking['last_name']}</strong>,</p>
                    <p>Your parking session has started.</p>
                    
                    <div class='details'>
                        <p><strong>📍 Location:</strong> {$booking['parking_name']}</p>
                        <p><strong>📌 Address:</strong> {$booking['address']}</p>
                        <p><strong>⏱️ Started at:</strong> " . date('h:i A', strtotime($booking['actual_start_time'])) . "</p>
                        <p><strong>⏰ Ends at:</strong> " . date('h:i A', strtotime($booking['actual_end_time'])) . "</p>
                    </div>
                    
                    <p>Enjoy your parking!</p>
                    
                    <p style='text-align: center;'>
                        <a href='http://localhost/Park_Ease/spacenode/reservation-details.php?id={$booking['id']}' class='button'>View Details</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
        
        return $this->sendEmail($booking['email'], $subject, $message, 'timer_started', $reservation_id, 'customer');
    }
    
    /**
     * Send arrival notification to owner
     */
    public function sendOwnerArrival($reservation_id) {
        $query = "SELECT r.*, u.first_name, u.last_name, u.email as customer_email, u.phone,
                  ps.name as parking_name, ps.address, o.email as owner_email, o.first_name as owner_first_name
                  FROM reservations r
                  JOIN users u ON r.user_id = u.id
                  JOIN parking_spaces ps ON r.parking_id = ps.id
                  JOIN users o ON ps.owner_id = o.id
                  WHERE r.id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $reservation_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) return false;
        
        $subject = "🚗 Customer Has Arrived - SpaceNode";
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; }
                .header { background: #3B82F6; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 20px; background: #F9FAFB; }
                .details { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #3B82F6; }
                .button { background: #3B82F6; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>🚗 Customer Arrived</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$booking['owner_first_name']}</strong>,</p>
                    <p>A customer has arrived and started their parking session.</p>
                    
                    <div class='details'>
                        <p><strong>👤 Customer:</strong> {$booking['first_name']} {$booking['last_name']}</p>
                        <p><strong>📞 Phone:</strong> {$booking['phone']}</p>
                        <p><strong>📍 Space:</strong> {$booking['parking_name']}</p>
                        <p><strong>📌 Address:</strong> {$booking['address']}</p>
                        <p><strong>⏱️ Started at:</strong> " . date('h:i A') . "</p>
                        <p><strong>⏰ Ends at:</strong> " . date('h:i A', strtotime($booking['actual_end_time'])) . "</p>
                    </div>
                    
                    <p style='text-align: center;'>
                        <a href='http://localhost/Park_Ease/spacenode/owner/active-sessions.php' class='button'>View Active Sessions</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
        
        return $this->sendEmail($booking['owner_email'], $subject, $message, 'owner_arrival', $reservation_id, 'owner');
    }
    
    /**
     * Send departure notification to owner
     */
    public function sendOwnerDeparture($reservation_id, $overstay_charge = 0) {
        $query = "SELECT r.*, u.first_name, u.last_name, 
                  ps.name as parking_name, o.email as owner_email, o.first_name as owner_first_name
                  FROM reservations r
                  JOIN users u ON r.user_id = u.id
                  JOIN parking_spaces ps ON r.parking_id = ps.id
                  JOIN users o ON ps.owner_id = o.id
                  WHERE r.id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $reservation_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) return false;
        
        $subject = "✅ Space Now Available - SpaceNode";
        $charge_text = $overstay_charge > 0 ? " (₦" . number_format($overstay_charge, 2) . " overstay charge applied)" : "";
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; }
                .header { background: #6B7280; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 20px; background: #F9FAFB; }
                .details { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #6B7280; }
                .charge { background: #FEE2E2; color: #DC2626; padding: 15px; border-radius: 8px; margin: 15px 0; }
                .button { background: #6B7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>✅ Space Now Available</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$booking['owner_first_name']}</strong>,</p>
                    <p>A customer has left the parking space.</p>
                    
                    <div class='details'>
                        <p><strong>👤 Customer:</strong> {$booking['first_name']} {$booking['last_name']}</p>
                        <p><strong>📍 Space:</strong> {$booking['parking_name']}</p>
                        <p><strong>⏱️ Left at:</strong> " . date('h:i A') . "</p>
                    </div>";
        
        if ($overstay_charge > 0) {
            $message .= "<div class='charge'>💰 Overstay charge of ₦" . number_format($overstay_charge, 2) . " has been applied.</div>";
        }
        
        $message .= "<p>The space is now available for new customers.</p>
                    
                    <p style='text-align: center;'>
                        <a href='http://localhost/Park_Ease/spacenode/owner-reservations.php' class='button'>View Bookings</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
        
        return $this->sendEmail($booking['owner_email'], $subject, $message, 'owner_departure', $reservation_id, 'owner');
    }
    
    /**
     * Send reminder email during active session
     */
    public function sendReminder($reservation_id, $minutes_left) {
        $query = "SELECT r.*, u.email, u.first_name, u.last_name, ps.name as parking_name, ps.address
                  FROM reservations r
                  JOIN users u ON r.user_id = u.id
                  JOIN parking_spaces ps ON r.parking_id = ps.id
                  WHERE r.id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $reservation_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) return false;
        
        if ($minutes_left <= 5) {
            $subject = "⚠️ URGENT: Your Parking Ends in 5 Minutes!";
            $color = '#DC2626';
            $title = '⚠️ Time Almost Up!';
        } elseif ($minutes_left <= 15) {
            $subject = "⏰ Your Parking Ends in 15 Minutes";
            $color = '#F59E0B';
            $title = '⏰ 15 Minutes Remaining';
        } else {
            $subject = "⏱️ Your Parking Ends in 30 Minutes";
            $color = '#3B82F6';
            $title = '⏱️ 30 Minutes Remaining';
        }
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; }
                .header { background: {$color}; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 20px; background: #F9FAFB; }
                .details { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid {$color}; }
                .timer { font-size: 36px; font-weight: bold; text-align: center; padding: 20px; color: {$color}; }
                .button { background: {$color}; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>{$title}</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$booking['first_name']} {$booking['last_name']}</strong>,</p>
                    <p>Your parking session ends in <strong>{$minutes_left} minutes</strong>.</p>
                    
                    <div class='timer'>{$minutes_left} minutes remaining</div>
                    
                    <div class='details'>
                        <p><strong>📍 Location:</strong> {$booking['parking_name']}</p>
                        <p><strong>📌 Address:</strong> {$booking['address']}</p>
                    </div>
                    
                    <p>Please make your way back to your vehicle.</p>
                    <p>If you need more time, please contact the parking owner immediately.</p>
                    
                    <p style='text-align: center;'>
                        <a href='http://localhost/Park_Ease/spacenode/reservation-details.php?id={$booking['id']}' class='button'>View Details</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
        
        return $this->sendEmail($booking['email'], $subject, $message, "reminder_{$minutes_left}", $reservation_id, 'customer');
    }
    
    /**
     * Send expired notification to customer
     */
    public function sendExpired($reservation_id, $overstay_charge = 0) {
        $query = "SELECT r.*, u.email, u.first_name, u.last_name, ps.name as parking_name, ps.address
                  FROM reservations r
                  JOIN users u ON r.user_id = u.id
                  JOIN parking_spaces ps ON r.parking_id = ps.id
                  WHERE r.id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $reservation_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) return false;
        
        $subject = "Your Parking Session Has Ended - SpaceNode";
        $charge_text = $overstay_charge > 0 ? "<div style='background: #FEE2E2; color: #DC2626; padding: 15px; border-radius: 8px; margin: 15px 0;'><strong>💰 Overstay Charge:</strong> ₦" . number_format($overstay_charge, 2) . "</div>" : "";
        
        $message = "
        <html>
        <head>
            <style>
                body { font-family: Arial, sans-serif; }
                .container { max-width: 600px; margin: 0 auto; }
                .header { background: #6B7280; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { padding: 20px; background: #F9FAFB; }
                .details { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid #6B7280; }
                .button { background: #6B7280; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Session Ended</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$booking['first_name']} {$booking['last_name']}</strong>,</p>
                    <p>Your parking session at <strong>{$booking['parking_name']}</strong> has ended.</p>
                    
                    <div class='details'>
                        <p><strong>📍 Location:</strong> {$booking['address']}</p>
                        <p><strong>⏱️ Ended at:</strong> " . date('h:i A') . "</p>
                    </div>
                    
                    {$charge_text}
                    
                    <p>Thank you for using SpaceNode!</p>
                    
                    <p style='text-align: center;'>
                        <a href='http://localhost/Park_Ease/spacenode/index.php' class='button'>Book Again</a>
                    </p>
                </div>
            </div>
        </body>
        </html>";
        
        return $this->sendEmail($booking['email'], $subject, $message, 'expired', $reservation_id, 'customer');
    }

    /**
     * Send starting soon reminder (24h and 1h) to customer
     */
    public function sendStartingSoonReminder($reservation_id, $hours_before) {
        $query = "SELECT r.*, u.email, u.first_name, u.last_name, 
                  ps.name as parking_name, ps.address, ps.city
                  FROM reservations r
                  JOIN users u ON r.user_id = u.id
                  JOIN parking_spaces ps ON r.parking_id = ps.id
                  WHERE r.id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([':id' => $reservation_id]);
        $booking = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$booking) return false;
        
        $start_time = new DateTime($booking['start_date']);
        
        if ($hours_before == 24) {
            $subject = "📅 Your Parking Starts Tomorrow - SpaceNode";
            $color = '#3B82F6';
            $title = '📅 Reminder: Your Parking Starts Tomorrow';
            $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; }
                    .header { background: {$color}; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { padding: 20px; background: #F9FAFB; }
                    .details { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid {$color}; }
                    .button { background: {$color}; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>{$title}</h1>
                    </div>
                    <div class='content'>
                        <p>Hello <strong>{$booking['first_name']} {$booking['last_name']}</strong>,</p>
                        <p>This is a friendly reminder that your parking session starts tomorrow.</p>
                        
                        <div class='details'>
                            <p><strong>📍 Location:</strong> {$booking['parking_name']}</p>
                            <p><strong>📌 Address:</strong> {$booking['address']}, {$booking['city']}</p>
                            <p><strong>📅 Date:</strong> " . $start_time->format('l, F d, Y') . "</p>
                            <p><strong>⏰ Time:</strong> " . $start_time->format('h:i A') . "</p>
                            <p><strong>🔢 Reference:</strong> {$booking['booking_reference']}</p>
                        </div>
                        
                        <p>Please arrive on time. You have a 30-minute grace period.</p>
                        <p>Your access PIN will be available when you check in.</p>
                        
                        <p style='text-align: center;'>
                            <a href='http://localhost/Park_Ease/spacenode/reservation-details.php?id={$booking['id']}' class='button'>View Booking Details</a>
                        </p>
                    </div>
                </div>
            </body>
            </html>";
        } else {
            $subject = "⚠️ Your Parking Starts in 1 Hour - SpaceNode";
            $color = '#F59E0B';
            $title = '⏰ Your Parking Starts in 1 Hour';
            $message = "
            <html>
            <head>
                <style>
                    body { font-family: Arial, sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; }
                    .header { background: {$color}; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { padding: 20px; background: #F9FAFB; }
                    .details { background: white; padding: 15px; border-radius: 8px; margin: 15px 0; border-left: 4px solid {$color}; }
                    .pin-box { background: {$color}; color: white; padding: 15px; border-radius: 8px; text-align: center; font-size: 32px; letter-spacing: 8px; font-weight: bold; margin: 15px 0; }
                    .button { background: {$color}; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>{$title}</h1>
                    </div>
                    <div class='content'>
                        <p>Hello <strong>{$booking['first_name']} {$booking['last_name']}</strong>,</p>
                        <p>Your parking session starts in 1 hour. Please be ready!</p>
                        
                        <div class='details'>
                            <p><strong>📍 Location:</strong> {$booking['parking_name']}</p>
                            <p><strong>📌 Address:</strong> {$booking['address']}, {$booking['city']}</p>
                            <p><strong>⏰ Time:</strong> " . $start_time->format('h:i A') . "</p>
                        </div>
                        
                        <div class='pin-box'>
                            {$booking['access_pin']}
                        </div>
                        <p style='text-align: center; font-weight: bold; margin-top: 5px;'>Your Access PIN - Show this to the owner</p>
                        
                        <p style='text-align: center;'>
                            <a href='http://localhost/Park_Ease/spacenode/reservation-details.php?id={$booking['id']}' class='button'>View Booking Details</a>
                        </p>
                    </div>
                </div>
            </body>
            </html>";
        }
        
        return $this->sendEmail($booking['email'], $subject, $message, "starting_soon_{$hours_before}h", $reservation_id, 'customer');
    }
    
   
    /**
 * Send email using PHPMailer
 */
private function sendEmail($to, $subject, $message, $type, $reservation_id, $recipient_type = 'customer') {
    try {
        // Use PHPMailer from your config
        $mail = EmailConfig::getMailer();
        $mail->addAddress($to);
        $mail->Subject = $subject;
        $mail->Body = $message;
        $mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $message));
        
        $result = $mail->send();
        
        if ($result) {
            // Log the notification
            try {
                $log = $this->db->prepare("INSERT INTO notification_log (reservation_id, recipient_type, notification_type) VALUES (?, ?, ?)");
                $log->execute([$reservation_id, $recipient_type, $type]);
            } catch (Exception $e) {
                // Log table might not exist, ignore
            }
        }
        
        return $result;
        
    } catch (Exception $e) {
        error_log("Email Error: " . $e->getMessage());
        return false;
    }
}

/**
 * Send time up notification (when timer expires but before checkout)
 */
public function sendTimeUpNotification($reservation_id, $overstay_charge = 0) {
    $query = "SELECT r.*, u.email, u.first_name, u.last_name, ps.name as parking_name
              FROM reservations r
              JOIN users u ON r.user_id = u.id
              JOIN parking_spaces ps ON r.parking_id = ps.id
              WHERE r.id = :id";
    
    $stmt = $this->db->prepare($query);
    $stmt->execute([':id' => $reservation_id]);
    $booking = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$booking) return false;
    
    $subject = "⏰ Your Parking Time Has Ended - Please Check Out";
    $charge_text = $overstay_charge > 0 ? "<p style='color: #DC2626;'><strong>Overstay charge:</strong> ₦" . number_format($overstay_charge, 2) . "</p>" : "";
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; }
            .header { background: #F59E0B; color: white; padding: 20px; text-align: center; border-radius: 10px 10px 0 0; }
            .content { padding: 20px; background: #F9FAFB; }
            .button { background: #4F6EF7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 5px; display: inline-block; margin-top: 15px; }
        </style>
    </head>
    <body>
        <div class='container'>
            <div class='header'>
                <h1>⏰ Your Parking Time Has Ended</h1>
            </div>
            <div class='content'>
                <p>Hello <strong>{$booking['first_name']}</strong>,</p>
                <p>Your parking session at <strong>{$booking['parking_name']}</strong> has ended.</p>
                <p>Please proceed to the exit. The owner has been notified to confirm your checkout.</p>
                {$charge_text}
                <p>Thank you for using SpaceNode!</p>
            </div>
        </div>
    </body>
    </html>";
    
    return $this->sendEmail($booking['email'], $subject, $message, 'time_up', $reservation_id, 'customer');
}
}
?>