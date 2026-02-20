<?php
require_once __DIR__ . '/../config/email-config.php';

class EmailNotifications {
    private $db;
    private $mail;
    
    public function __construct($db) {
        $this->db = $db;
        $this->mail = EmailConfig::getMailer();
    }
    
    /**
     * Send booking confirmation email to customer
     */
    public function sendBookingConfirmation($booking_id) {
        try {
            // Get booking details
            $query = "SELECT r.*, 
                      u.email as customer_email, 
                      u.first_name, 
                      u.last_name,
                      u.phone,
                      ps.name as parking_name,
                      ps.address,
                      ps.city,
                      owner.email as owner_email,
                      owner.first_name as owner_first_name,
                      owner.phone as owner_phone
                      FROM reservations r
                      JOIN users u ON r.user_id = u.id
                      JOIN parking_spaces ps ON r.parking_id = ps.id
                      JOIN users owner ON ps.owner_id = owner.id
                      WHERE r.id = :id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $booking_id);
            $stmt->execute();
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) return false;
            
            // Send to customer
            $this->mail->clearAddresses();
            $this->mail->addAddress($booking['customer_email'], $booking['first_name'] . ' ' . $booking['last_name']);
            $this->mail->addReplyTo($booking['owner_email'], $booking['owner_first_name']);
            
            // Email subject
            $this->mail->Subject = "Booking Confirmation - ParkEase #{$booking['booking_reference']}";
            
            // HTML email body
            $htmlBody = $this->getBookingConfirmationHTML($booking);
            $this->mail->isHTML(true);
            $this->mail->Body = $htmlBody;
            $this->mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $htmlBody));
            
            $this->mail->send();
            
            // Also send notification to owner
            $this->sendOwnerNotification($booking);
            
            return true;
            
        } catch (Exception $e) {
            error_log("Email Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send status update email
     */
    public function sendStatusUpdate($booking_id, $old_status, $new_status) {
        try {
            $query = "SELECT r.*, u.email, u.first_name, u.last_name, ps.name as parking_name 
                      FROM reservations r
                      JOIN users u ON r.user_id = u.id
                      JOIN parking_spaces ps ON r.parking_id = ps.id
                      WHERE r.id = :id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $booking_id);
            $stmt->execute();
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) return false;
            
            $status_messages = [
                'confirmed' => 'Your booking has been confirmed! 🎉',
                'active' => 'Your parking session is now active! 🚗',
                'completed' => 'Your parking session has been completed. Thank you for choosing ParkEase! ⭐',
                'cancelled' => 'Your booking has been cancelled as requested.'
            ];
            
            $status_colors = [
                'confirmed' => '#10B981',
                'active' => '#3B82F6',
                'completed' => '#6B7280',
                'cancelled' => '#EF4444'
            ];
            
            $this->mail->clearAddresses();
            $this->mail->addAddress($booking['email'], $booking['first_name'] . ' ' . $booking['last_name']);
            $this->mail->Subject = "Booking Status Update - ParkEase #{$booking['booking_reference']}";
            
            $message = $status_messages[$new_status] ?? "Your booking status has been updated to: $new_status";
            $color = $status_colors[$new_status] ?? '#4F6EF7';
            
            $htmlBody = $this->getStatusUpdateHTML($booking, $message, $color, $new_status);
            
            $this->mail->isHTML(true);
            $this->mail->Body = $htmlBody;
            $this->mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $htmlBody));
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Email Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send reminder email 1 hour before booking
     */
    public function sendReminder($booking_id) {
        try {
            $query = "SELECT r.*, u.email, u.first_name, u.last_name, ps.name as parking_name, ps.address 
                      FROM reservations r
                      JOIN users u ON r.user_id = u.id
                      JOIN parking_spaces ps ON r.parking_id = ps.id
                      WHERE r.id = :id AND r.status IN ('confirmed', 'active')";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $booking_id);
            $stmt->execute();
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) return false;
            
            $this->mail->clearAddresses();
            $this->mail->addAddress($booking['email'], $booking['first_name'] . ' ' . $booking['last_name']);
            $this->mail->Subject = "Reminder: Your Parking Starts Soon! - ParkEase";
            
            $htmlBody = $this->getReminderHTML($booking);
            
            $this->mail->isHTML(true);
            $this->mail->Body = $htmlBody;
            $this->mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $htmlBody));
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Email Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send notification to owner about new booking
     */
    private function sendOwnerNotification($booking) {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($booking['owner_email'], $booking['owner_first_name']);
            $this->mail->Subject = "New Booking Received - ParkEase #{$booking['booking_reference']}";
            
            $htmlBody = "
            <html>
            <head>
                <style>
                    body { font-family: 'Inter', sans-serif; background: #F9FAFB; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #4F6EF7, #7C3AED); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
                    .booking-ref { background: #F3F4F6; padding: 10px; border-radius: 8px; font-family: monospace; font-size: 18px; text-align: center; margin: 20px 0; }
                    .customer-details { background: #F9FAFB; padding: 20px; border-radius: 8px; margin: 20px 0; }
                    .detail-row { display: flex; justify-content: space-between; padding: 10px 0; border-bottom: 1px solid #E5E7EB; }
                    .label { color: #6B7280; font-weight: 500; }
                    .value { color: #111827; font-weight: 600; }
                    .button { display: inline-block; background: #4F6EF7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; margin-top: 20px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>New Booking!</h1>
                    </div>
                    <div class='content'>
                        <h2>You have a new booking</h2>
                        
                        <div class='booking-ref'>
                            Reference: <strong>{$booking['booking_reference']}</strong>
                        </div>
                        
                        <div class='customer-details'>
                            <h3>Customer Details</h3>
                            <div class='detail-row'>
                                <span class='label'>Name:</span>
                                <span class='value'>{$booking['first_name']} {$booking['last_name']}</span>
                            </div>
                            <div class='detail-row'>
                                <span class='label'>Email:</span>
                                <span class='value'>{$booking['customer_email']}</span>
                            </div>
                            <div class='detail-row'>
                                <span class='label'>Phone:</span>
                                <span class='value'>{$booking['phone']}</span>
                            </div>
                        </div>
                        
                        <h3>Booking Details</h3>
                        <div class='detail-row'>
                            <span class='label'>Parking Space:</span>
                            <span class='value'>{$booking['parking_name']}</span>
                        </div>
                        <div class='detail-row'>
                            <span class='label'>Check-in:</span>
                            <span class='value'>" . date('M d, Y h:i A', strtotime($booking['start_date'])) . "</span>
                        </div>
                        <div class='detail-row'>
                            <span class='label'>Check-out:</span>
                            <span class='value'>" . date('M d, Y h:i A', strtotime($booking['end_date'])) . "</span>
                        </div>
                        <div class='detail-row'>
                            <span class='label'>Vehicle:</span>
                            <span class='value'>{$booking['vehicle_number']}</span>
                        </div>
                        <div class='detail-row'>
                            <span class='label'>Total Amount:</span>
                            <span class='value' style='color: #4F6EF7; font-weight: 700;'>₦" . number_format($booking['total_amount'], 2) . "</span>
                        </div>
                        
                        <a href='http://localhost/park_ease/parkease/owner-reservations.php' class='button'>View in Dashboard</a>
                    </div>
                </div>
            </body>
            </html>";
            
            $this->mail->isHTML(true);
            $this->mail->Body = $htmlBody;
            $this->mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $htmlBody));
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Owner Notification Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * HTML template for booking confirmation
     */
    private function getBookingConfirmationHTML($booking) {
        return "
        <html>
        <head>
            <style>
                body { font-family: 'Inter', sans-serif; background: #F9FAFB; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #4F6EF7, #7C3AED); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
                .booking-ref { background: #F3F4F6; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 20px; text-align: center; margin: 20px 0; letter-spacing: 1px; }
                .details { margin: 20px 0; }
                .detail-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #E5E7EB; }
                .label { color: #6B7280; font-weight: 500; }
                .value { color: #111827; font-weight: 600; }
                .total { font-size: 24px; color: #4F6EF7; font-weight: 700; margin: 20px 0; text-align: right; }
                .button { display: inline-block; background: #4F6EF7; color: white; padding: 14px 28px; text-decoration: none; border-radius: 8px; margin-top: 20px; font-weight: 500; }
                .button:hover { background: #3a56d4; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB; text-align: center; color: #6B7280; font-size: 12px; }
                .map-link { color: #4F6EF7; text-decoration: none; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>Booking Confirmed! 🎉</h1>
                    <p style='margin-top: 10px; opacity: 0.9;'>Your parking space is reserved</p>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$booking['first_name']} {$booking['last_name']}</strong>,</p>
                    <p>Your parking booking has been confirmed. Here are the details:</p>
                    
                    <div class='booking-ref'>
                        <strong>{$booking['booking_reference']}</strong>
                    </div>
                    
                    <div class='details'>
                        <h3 style='color: #111827; margin-bottom: 15px;'>📍 Parking Location</h3>
                        <div class='detail-row'>
                            <span class='label'>Space:</span>
                            <span class='value'>{$booking['parking_name']}</span>
                        </div>
                        <div class='detail-row'>
                            <span class='label'>Address:</span>
                            <span class='value'>{$booking['address']}, {$booking['city']}</span>
                        </div>
                        
                        <h3 style='color: #111827; margin: 20px 0 15px;'>📅 Booking Schedule</h3>
                        <div class='detail-row'>
                            <span class='label'>Check-in:</span>
                            <span class='value'>" . date('l, F d, Y - h:i A', strtotime($booking['start_date'])) . "</span>
                        </div>
                        <div class='detail-row'>
                            <span class='label'>Check-out:</span>
                            <span class='value'>" . date('l, F d, Y - h:i A', strtotime($booking['end_date'])) . "</span>
                        </div>
                        <div class='detail-row'>
                            <span class='label'>Duration:</span>
                            <span class='value'>" . number_format($booking['total_hours'], 1) . " hours</span>
                        </div>
                        
                        <h3 style='color: #111827; margin: 20px 0 15px;'>🚗 Vehicle Information</h3>
                        <div class='detail-row'>
                            <span class='label'>Vehicle:</span>
                            <span class='value'>{$booking['vehicle_number']} - {$booking['vehicle_model']}</span>
                        </div>
                        
                        <div class='total'>
                            Total Amount: ₦" . number_format($booking['total_amount'], 2) . "
                        </div>
                    </div>
                    
                    <div style='text-align: center;'>
                        <a href='http://localhost/park_ease/parkease/reservation-details.php?id={$booking['id']}' class='button'>View Booking Details</a>
                    </div>
                    
                    <div class='footer'>
                        <p>Need help? Contact the parking owner: {$booking['owner_email']}</p>
                        <p>© " . date('Y') . " ParkEase. All rights reserved.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * HTML template for status update
     */
    private function getStatusUpdateHTML($booking, $message, $color, $status) {
        return "
        <html>
        <head>
            <style>
                body { font-family: 'Inter', sans-serif; background: #F9FAFB; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: {$color}; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
                .status-badge { display: inline-block; padding: 8px 16px; background: {$color}; color: white; border-radius: 20px; font-weight: 600; margin: 10px 0; }
                .button { display: inline-block; background: {$color}; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; margin-top: 20px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>Booking Status Update</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$booking['first_name']}</strong>,</p>
                    <p>{$message}</p>
                    
                    <div style='text-align: center;'>
                        <span class='status-badge'>Status: " . ucfirst($status) . "</span>
                    </div>
                    
                    <div style='background: #F9FAFB; padding: 20px; border-radius: 8px; margin: 20px 0;'>
                        <p><strong>Booking Reference:</strong> {$booking['booking_reference']}</p>
                        <p><strong>Parking Space:</strong> {$booking['parking_name']}</p>
                        <p><strong>Check-in:</strong> " . date('M d, Y h:i A', strtotime($booking['start_date'])) . "</p>
                    </div>
                    
                    <a href='http://localhost/park_ease/parkease/reservation-details.php?id={$booking['id']}' class='button'>View Booking</a>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * HTML template for reminder
     */
    private function getReminderHTML($booking) {
        return "
        <html>
        <head>
            <style>
                body { font-family: 'Inter', sans-serif; background: #F9FAFB; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #F59E0B; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
                .reminder-box { background: #FEF3C7; padding: 20px; border-radius: 8px; margin: 20px 0; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1>⏰ Reminder</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$booking['first_name']}</strong>,</p>
                    <p>Your parking session starts in 1 hour!</p>
                    
                    <div class='reminder-box'>
                        <p><strong>📍 {$booking['parking_name']}</strong></p>
                        <p>{$booking['address']}</p>
                        <p><strong>Time:</strong> " . date('h:i A', strtotime($booking['start_date'])) . "</p>
                    </div>
                    
                    <p>Please arrive on time. You can cancel up to 30 minutes before start time.</p>
                    
                    <a href='http://localhost/park_ease/parkease/reservation-details.php?id={$booking['id']}'>Manage Booking →</a>
                </div>
            </div>
        </body>
        </html>";
    }
}

// Function to check and send reminders 
function checkAndSendReminders($db) {
    $now = new DateTime();
    $one_hour_from_now = clone $now;
    $one_hour_from_now->modify('+1 hour');
    
    $query = "SELECT id FROM reservations 
              WHERE status IN ('confirmed', 'active') 
              AND start_date BETWEEN :now AND :one_hour
              AND reminder_sent = 0";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':now', $now->format('Y-m-d H:i:s'));
    $stmt->bindParam(':one_hour', $one_hour_from_now->format('Y-m-d H:i:s'));
    $stmt->execute();
    
    $emailer = new EmailNotifications($db);
    
    while ($row = $stmt->fetch(PDO::FETCH_ASSOC)) {
        $emailer->sendReminder($row['id']);
        
        // Mark reminder as sent
        $update = $db->prepare("UPDATE reservations SET reminder_sent = 1 WHERE id = :id");
        $update->bindParam(':id', $row['id']);
        $update->execute();
    }
}
?>