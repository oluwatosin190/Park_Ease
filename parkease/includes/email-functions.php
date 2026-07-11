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
     * Send welcome email to new users
     * 
     * @param int $user_id The user ID
     * @return bool Success status
     */
    public function sendWelcomeEmail($user_id) {
        try {
            // Get user details
            $query = "SELECT first_name, last_name, email, user_type FROM users WHERE id = :id";
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
            $stmt->execute();
            $user = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$user) return false;
            
            $this->mail->clearAddresses();
            $this->mail->addAddress($user['email'], $user['first_name'] . ' ' . $user['last_name']);
            $this->mail->Subject = "Welcome to SpaceNode! 🅿️";
            
            // HTML email body
            $htmlBody = $this->getWelcomeEmailHTML($user);
            $this->mail->isHTML(true);
            $this->mail->Body = $htmlBody;
            $this->mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $htmlBody));
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Welcome Email Error: " . $e->getMessage());
            return false;
        }
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
            
            $this->mail->Subject = "Booking Confirmation - SpaceNode #{$booking['booking_reference']}";
            
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
     * Send payment confirmation email after successful payment
     */
    public function sendPaymentConfirmation($booking_id) {
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
                      owner.first_name as owner_first_name
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
            
            $this->mail->Subject = "Payment Confirmed - SpaceNode #{$booking['booking_reference']}";
            
            $htmlBody = $this->getPaymentConfirmationHTML($booking);
            $this->mail->isHTML(true);
            $this->mail->Body = $htmlBody;
            $this->mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $htmlBody));
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("Payment Confirmation Email Error: " . $e->getMessage());
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
                'completed' => 'Your parking session has been completed. Thank you for choosing SpaceNode! ⭐',
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
            $this->mail->Subject = "Booking Status Update - SpaceNode #{$booking['booking_reference']}";
            
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
            $this->mail->Subject = "Reminder: Your Parking Starts Soon! - SpaceNode";
            
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
     * Send PIN via email
     */
    public function sendPinEmail($booking_id) {
        try {
            $query = "SELECT r.*, 
                      u.email as customer_email, 
                      u.first_name, 
                      u.last_name,
                      u.phone,
                      ps.name as parking_name,
                      ps.address,
                      ps.city
                      FROM reservations r
                      JOIN users u ON r.user_id = u.id
                      JOIN parking_spaces ps ON r.parking_id = ps.id
                      WHERE r.id = :id";
            
            $stmt = $this->db->prepare($query);
            $stmt->bindParam(':id', $booking_id);
            $stmt->execute();
            $booking = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if (!$booking) return false;
            
            $this->mail->clearAddresses();
            $this->mail->addAddress($booking['customer_email'], $booking['first_name'] . ' ' . $booking['last_name']);
            $this->mail->Subject = "Your Access PIN - SpaceNode #{$booking['booking_reference']}";
            
            $htmlBody = $this->getPinEmailHTML($booking);
            $this->mail->isHTML(true);
            $this->mail->Body = $htmlBody;
            $this->mail->AltBody = strip_tags(str_replace(['<br>', '</p>'], ["\n", "\n\n"], $htmlBody));
            
            $this->mail->send();
            return true;
            
        } catch (Exception $e) {
            error_log("PIN Email Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * Send timer started notification
     */
    public function sendTimerStartedEmail($booking_id) {
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
            
            $this->mail->clearAddresses();
            $this->mail->addAddress($booking['email'], $booking['first_name'] . ' ' . $booking['last_name']);
            $this->mail->Subject = "Your Parking Session Has Started - SpaceNode";
            
            $htmlBody = "
            <html>
            <head>
                <style>
                    body { font-family: 'Inter', sans-serif; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: #10B981; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>Session Started! 🚗</h1>
                    </div>
                    <div class='content'>
                        <p>Hello <strong>{$booking['first_name']}</strong>,</p>
                        <p>Your parking session at <strong>{$booking['parking_name']}</strong> has started.</p>
                        <p>Started at: " . date('h:i A', strtotime($booking['actual_start_time'])) . "</p>
                        <p>Ends at: " . date('h:i A', strtotime($booking['actual_end_time'])) . "</p>
                        <p>Enjoy your parking!</p>
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
            error_log("Timer Started Email Error: " . $e->getMessage());
            return false;
        }
    }
    
    /**
     * HTML template for welcome email
     */
    private function getWelcomeEmailHTML($user) {
        $user_type_text = ($user['user_type'] == 'owner') ? 'parking space owner' : 'parker';
        
        return "
        <html>
        <head>
            <style>
                body { font-family: 'Inter', sans-serif; background: #F9FAFB; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #4F6EF7, #7C3AED); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
                .button { display: inline-block; background: #4F6EF7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; margin-top: 20px; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB; text-align: center; color: #6B7280; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>Welcome to SpaceNode! 🎉</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$user['first_name']} {$user['last_name']}</strong>,</p>
                    <p>Thank you for joining SpaceNode! We're thrilled to have you as a new {$user_type_text}.</p>
                    
                    <p>With SpaceNode, you can:</p>
                    <ul>
                        <li>🔍 Find and book parking spaces in seconds</li>
                        <li>💰 Compare prices and choose the best rates</li>
                        <li>🔒 Park securely with verified spaces</li>
                        " . ($user['user_type'] == 'owner' ? "<li>💵 List your parking spaces and start earning</li>" : "") . "
                    </ul>
                    
                    <div style='text-align: center;'>
                        <a href='https://spacenode.com/dashboard.php' class='button'>Go to Dashboard</a>
                    </div>
                    
                    <div class='footer'>
                        <p>Need help? Contact us at support@spacenode.com</p>
                        <p>© " . date('Y') . " SpaceNode. All rights reserved.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";
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
                .booking-ref { background: #F3F4F6; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 20px; text-align: center; margin: 20px 0; }
                .detail-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #E5E7EB; }
                .label { color: #6B7280; font-weight: 500; }
                .value { color: #111827; font-weight: 600; }
                .total { font-size: 24px; color: #4F6EF7; font-weight: 700; margin: 20px 0; text-align: right; }
                .button { display: inline-block; background: #4F6EF7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; margin-top: 20px; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB; text-align: center; color: #6B7280; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>Booking Confirmed! 🎉</h1>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$booking['first_name']} {$booking['last_name']}</strong>,</p>
                    <p>Your parking booking has been confirmed.</p>
                    
                    <div class='booking-ref'>
                        <strong>{$booking['booking_reference']}</strong>
                    </div>
                    
                    <div class='detail-row'>
                        <span class='label'>Parking Space:</span>
                        <span class='value'>{$booking['parking_name']}</span>
                    </div>
                    <div class='detail-row'>
                        <span class='label'>Location:</span>
                        <span class='value'>{$booking['address']}, {$booking['city']}</span>
                    </div>
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
                    
                    <div class='total'>
                        Total Amount: ₦" . number_format($booking['total_amount'], 2) . "
                    </div>
                    
                    <div style='text-align: center;'>
                        <a href='https://spacenode.com/reservation-details.php?id={$booking['id']}' class='button'>View Booking Details</a>
                    </div>
                    
                    <div class='footer'>
                        <p>Need help? Contact the parking owner: {$booking['owner_email']}</p>
                        <p>© " . date('Y') . " SpaceNode. All rights reserved.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * HTML template for payment confirmation
     */
    private function getPaymentConfirmationHTML($booking) {
        return "
        <html>
        <head>
            <style>
                body { font-family: 'Inter', sans-serif; background: #F9FAFB; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: #10B981; color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
                .payment-success { background: #DCFCE7; color: #166534; padding: 15px; border-radius: 8px; text-align: center; margin: 20px 0; }
                .booking-ref { background: #F3F4F6; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 20px; text-align: center; margin: 20px 0; }
                .detail-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #E5E7EB; }
                .total { font-size: 24px; color: #059669; font-weight: 700; margin: 20px 0; text-align: right; }
                .button { display: inline-block; background: #059669; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; margin-top: 20px; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB; text-align: center; color: #6B7280; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>Payment Successful! 💰</h1>
                </div>
                <div class='content'>
                    <div class='payment-success'>
                        ✓ Payment Received Successfully
                    </div>
                    
                    <p>Hello <strong>{$booking['first_name']} {$booking['last_name']}</strong>,</p>
                    <p>Thank you for your payment. Your booking is now confirmed.</p>
                    
                    <div class='booking-ref'>
                        <strong>{$booking['booking_reference']}</strong>
                    </div>
                    
                    <div class='detail-row'>
                        <span class='label'>Parking Space:</span>
                        <span class='value'>{$booking['parking_name']}</span>
                    </div>
                    <div class='detail-row'>
                        <span class='label'>Check-in:</span>
                        <span class='value'>" . date('l, F d, Y - h:i A', strtotime($booking['start_date'])) . "</span>
                    </div>
                    <div class='detail-row'>
                        <span class='label'>Check-out:</span>
                        <span class='value'>" . date('l, F d, Y - h:i A', strtotime($booking['end_date'])) . "</span>
                    </div>
                    
                    <div class='total'>
                        Amount Paid: ₦" . number_format($booking['total_amount'], 2) . "
                    </div>
                    
                    <div style='text-align: center;'>
                        <a href='https://spacenode.com/reservation-details.php?id={$booking['id']}' class='button'>View Booking Details</a>
                    </div>
                    
                    <div class='footer'>
                        <p>Need help? Contact support: support@spacenode.com</p>
                        <p>© " . date('Y') . " SpaceNode. All rights reserved.</p>
                    </div>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * HTML template for PIN email
     */
    private function getPinEmailHTML($booking) {
        return "
        <html>
        <head>
            <style>
                body { font-family: 'Inter', sans-serif; background: #F9FAFB; }
                .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                .header { background: linear-gradient(135deg, #4F6EF7, #7C3AED); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
                .pin-box { background: linear-gradient(135deg, #4F6EF7, #7C3AED); color: white; padding: 30px; border-radius: 12px; text-align: center; margin: 20px 0; }
                .pin-number { font-size: 48px; font-weight: 800; letter-spacing: 8px; font-family: monospace; }
                .button { display: inline-block; background: #4F6EF7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; margin-top: 20px; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB; text-align: center; color: #6B7280; font-size: 12px; }
            </style>
        </head>
        <body>
            <div class='container'>
                <div class='header'>
                    <h1 style='margin: 0;'>Your Access PIN</h1>
                    <p style='margin-top: 10px;'>Booking #{$booking['booking_reference']}</p>
                </div>
                <div class='content'>
                    <p>Hello <strong>{$booking['first_name']} {$booking['last_name']}</strong>,</p>
                    <p>Your payment has been confirmed. Here's your access PIN:</p>
                    
                    <div class='pin-box'>
                        <div class='pin-number'>{$booking['access_pin']}</div>
                    </div>
                    
                    <p style='text-align: center; color: #DC2626; font-weight: 600;'>
                        ⚠️ Keep this PIN confidential. Show it only to the parking owner.
                    </p>
                    
                    <div style='text-align: center;'>
                        <a href='https://spacenode.com/reservation-details.php?id={$booking['id']}' class='button'>View Booking Details</a>
                    </div>
                    
                    <div class='footer'>
                        <p>When you arrive at the parking space, give this PIN to the owner to start your session.</p>
                        <p>© " . date('Y') . " SpaceNode. All rights reserved.</p>
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
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB; text-align: center; color: #6B7280; font-size: 12px; }
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
                    
                    <a href='https://spacenode.com/reservation-details.php?id={$booking['id']}' class='button'>View Booking</a>
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
                .button { display: inline-block; background: #F59E0B; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; margin-top: 20px; }
                .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB; text-align: center; color: #6B7280; font-size: 12px; }
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
                    
                    <a href='https://spacenode.com/reservation-details.php?id={$booking['id']}' class='button'>Manage Booking →</a>
                </div>
            </div>
        </body>
        </html>";
    }
    
    /**
     * Send notification to owner about new booking
     */
    private function sendOwnerNotification($booking) {
        try {
            $this->mail->clearAddresses();
            $this->mail->addAddress($booking['owner_email'], $booking['owner_first_name']);
            $this->mail->Subject = "New Booking Received - SpaceNode #{$booking['booking_reference']}";
            
            $htmlBody = "
            <html>
            <head>
                <style>
                    body { font-family: 'Inter', sans-serif; background: #F9FAFB; }
                    .container { max-width: 600px; margin: 0 auto; padding: 20px; }
                    .header { background: linear-gradient(135deg, #4F6EF7, #7C3AED); color: white; padding: 30px; text-align: center; border-radius: 10px 10px 0 0; }
                    .content { background: white; padding: 30px; border-radius: 0 0 10px 10px; }
                    .booking-ref { background: #F3F4F6; padding: 15px; border-radius: 8px; font-family: monospace; font-size: 20px; text-align: center; margin: 20px 0; }
                    .detail-row { display: flex; justify-content: space-between; padding: 12px 0; border-bottom: 1px solid #E5E7EB; }
                    .button { display: inline-block; background: #4F6EF7; color: white; padding: 12px 24px; text-decoration: none; border-radius: 8px; margin-top: 20px; }
                    .footer { margin-top: 30px; padding-top: 20px; border-top: 1px solid #E5E7EB; text-align: center; color: #6B7280; font-size: 12px; }
                </style>
            </head>
            <body>
                <div class='container'>
                    <div class='header'>
                        <h1>New Booking!</h1>
                    </div>
                    <div class='content'>
                        <p>Hello <strong>{$booking['owner_first_name']}</strong>,</p>
                        <p>You have received a new booking.</p>
                        
                        <div class='booking-ref'>
                            Reference: <strong>{$booking['booking_reference']}</strong>
                        </div>
                        
                        <div class='detail-row'>
                            <span class='label'>Customer:</span>
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
                        
                        <div style='text-align: center;'>
                            <a href='https://spacenode.com/owner-reservations.php' class='button'>View in Dashboard</a>
                        </div>
                        
                        <div class='footer'>
                            <p>© " . date('Y') . " SpaceNode. All rights reserved.</p>
                        </div>
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