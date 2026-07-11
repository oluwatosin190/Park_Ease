<?php
/**
 * PIN Functions for SpaceNode
 * Handles PIN generation, validation, and timer management
 */

require_once __DIR__ . '/notification-manager.php';


class PinManager {
    private $db;
    private $overstay_penalty_per_hour = 500; // ₦500 per hour overstay
    
    public function __construct($db) {
        $this->db = $db;
    }
    
    /**
     * Generate a unique 4-digit PIN for a reservation
     */
    public function generatePin($reservation_id) {
        // Generate a random 4-digit number
        do {
            $pin = sprintf("%04d", mt_rand(0, 9999));
            
            // Check if PIN already exists (unlikely but possible)
            $check = $this->db->prepare("SELECT id FROM reservations WHERE access_pin = ? AND id != ?");
            $check->execute([$pin, $reservation_id]);
            $exists = $check->fetch();
        } while ($exists);
        
        return $pin;
    }
    
    /**
     * Generate and save PIN for a reservation
     */
    public function createAndSavePin($reservation_id) {
        $pin = $this->generatePin($reservation_id);
        $now = date('Y-m-d H:i:s');
        
        $query = "UPDATE reservations SET 
                  access_pin = :pin,
                  pin_generated_at = :now,
                  timer_status = 'pending'
                  WHERE id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute([
            ':pin' => $pin,
            ':now' => $now,
            ':id' => $reservation_id
        ]);
        
        return $pin;
    }
    
 
/**
 * Validate PIN and start timer
 */
public function validateAndStartTimer($reservation_id, $entered_pin) {
    // Get reservation details
    $query = "SELECT r.*, u.first_name, u.last_name, u.email, u.phone,
              ps.name as parking_name, ps.address
              FROM reservations r
              JOIN users u ON r.user_id = u.id
              JOIN parking_spaces ps ON r.parking_id = ps.id
              WHERE r.id = :id";
    
    $stmt = $this->db->prepare($query);
    $stmt->execute([':id' => $reservation_id]);
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$reservation) {
        return ['success' => false, 'message' => 'Reservation not found'];
    }
    
    // Check if PIN matches
    if ($reservation['access_pin'] != $entered_pin) {
        // Increment attempt counter
        $attempts = $reservation['pin_attempts'] + 1;
        $update = $this->db->prepare("UPDATE reservations SET pin_attempts = ? WHERE id = ?");
        $update->execute([$attempts, $reservation_id]);
        
        return ['success' => false, 'message' => 'Invalid PIN'];
    }
    
    // Check if PIN has already been used
    if ($reservation['pin_used_at'] !== null) {
        return ['success' => false, 'message' => 'PIN has already been used'];
    }
    
    // Check if booking is already active
    if ($reservation['timer_status'] == 'active') {
        return ['success' => false, 'message' => 'Timer already started'];
    }
    
    // ===== FIXED GRACE PERIOD LOGIC =====
    // Allow starting the timer ANY TIME - no hard expiration
    // Just log a warning if it's late, but still allow start
    
    $scheduled_start = new DateTime($reservation['start_date']);
    $now = new DateTime();
    
    // Calculate how late the start is (if any)
    $diff_seconds = $now->getTimestamp() - $scheduled_start->getTimestamp();
    $minutes_late = round($diff_seconds / 60);
    
    // Log the late start for monitoring
    if ($minutes_late > 30) {
        error_log("WARNING: Timer started LATE for reservation {$reservation_id} - Minutes late: {$minutes_late}");
    }
    
    // Only block if more than 24 hours late (prevents abuse)
    if ($minutes_late > 1440) {
        $this->markAsExpired($reservation_id, 'Missed by more than 24 hours');
        return ['success' => false, 'message' => 'This booking is more than 24 hours late. Please contact support to reactivate.'];
    }
    // ===== END FIXED GRACE PERIOD LOGIC =====
    
    // Start the timer
    $actual_start = $now->format('Y-m-d H:i:s');
    $pin_used_at = $now->format('Y-m-d H:i:s');
    
    // IMPORTANT: Get the original duration from the booking
    $duration_hours = floatval($reservation['total_hours']);
    
    // Calculate end time by adding duration to actual start time
    $actual_end = clone $now;
    $duration_seconds = $duration_hours * 3600;
    $actual_end->modify("+{$duration_seconds} seconds");
    $actual_end_str = $actual_end->format('Y-m-d H:i:s');
    
    // Debug log to check calculations
    error_log("Duration hours: {$duration_hours}, Seconds: {$duration_seconds}");
    error_log("Start: {$actual_start}, End: {$actual_end_str}");
    
    // Update both timer_status AND status
    $update = $this->db->prepare("UPDATE reservations SET 
                                   pin_used_at = :pin_used,
                                   actual_start_time = :actual_start,
                                   actual_end_time = :actual_end,
                                   timer_status = 'active',
                                   status = 'active',
                                   checkout_status = 'pending'
                                   WHERE id = :id");
    
    $update->execute([
        ':pin_used' => $pin_used_at,
        ':actual_start' => $actual_start,
        ':actual_end' => $actual_end_str,
        ':id' => $reservation_id
    ]);
    
    // ===== FIX: ADD EMAIL NOTIFICATION HERE =====
    // Send email notification to customer that timer started
    try {
        require_once __DIR__ . '/email-functions.php';
        $emailer = new EmailNotifications($this->db);
        $emailer->sendTimerStartedEmail($reservation_id);
        
        // Also send notification to owner
        $notificationManager = new NotificationManager($this->db);
        $notificationManager->sendOwnerArrival($reservation_id);
        
        error_log("Timer started emails sent for reservation: {$reservation_id}");
    } catch (Exception $e) {
        error_log("Failed to send timer started email: " . $e->getMessage());
        // Don't fail the timer start if email fails
    }
    // ===== END FIX =====
    
    $success_message = 'Timer started successfully';
    if ($minutes_late > 0) {
        $success_message .= ' (Started ' . $minutes_late . ' minutes late)';
    }
    
    return [
        'success' => true,
        'message' => $success_message,
        'actual_start' => $actual_start,
        'actual_end' => $actual_end_str,
        'duration' => $duration_hours,
        'duration_minutes' => round($duration_hours * 60),
        'reservation' => $reservation
    ];
}
    
/**
 * Check for expired/overstay bookings 
 */
public function checkExpiredBookings() {
    $now = date('Y-m-d H:i:s');
    $results = [];
    
    // Get all active bookings that have passed their actual end time
    $query = "SELECT r.*, u.email, u.first_name, u.phone,
              ps.name as parking_name, ps.address, o.email as owner_email,
              o.first_name as owner_first_name
              FROM reservations r
              JOIN users u ON r.user_id = u.id
              JOIN parking_spaces ps ON r.parking_id = ps.id
              JOIN users o ON ps.owner_id = o.id
              WHERE r.timer_status = 'active' 
              AND r.actual_end_time < :now";
    
    $stmt = $this->db->prepare($query);
    $stmt->execute([':now' => $now]);
    $expired = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    foreach ($expired as $booking) {
        // Calculate overstay minutes (if any)
        $actual_end = new DateTime($booking['actual_end_time']);
        $current = new DateTime();
        
        // If current time is after end time, calculate overstay
        $overstay_minutes = 0;
        $overstay_charge = 0;
        
        if ($current > $actual_end) {
            $overstay = $current->diff($actual_end);
            $overstay_minutes = ($overstay->days * 24 * 60) + ($overstay->h * 60) + $overstay->i;
            
            // Calculate overstay charge (₦500 per hour or part thereof)
            $overstay_hours = ceil($overstay_minutes / 60);
            $overstay_charge = $overstay_hours * $this->overstay_penalty_per_hour;
        }
        
        // Update booking - set to PENDING CHECKOUT (not completed)
        $update = $this->db->prepare("UPDATE reservations SET 
                                       timer_status = 'pending_checkout',
                                       checkout_status = 'pending',
                                       overstay_minutes = :minutes,
                                       overstay_charge = :charge
                                       WHERE id = :id");
        $update->execute([
            ':minutes' => $overstay_minutes,
            ':charge' => $overstay_charge,
            ':id' => $booking['id']
        ]);
        
        error_log("Booking {$booking['id']} moved to pending_checkout - Overstay: {$overstay_minutes} minutes, Charge: ₦{$overstay_charge}");
        
        $results[] = [
            'booking_id' => $booking['id'],
            'customer' => $booking['first_name'] . ' ' . $booking['last_name'],
            'overstay_minutes' => $overstay_minutes,
            'overstay_charge' => $overstay_charge
        ];
        
        // Send email notification to customer that time is up
        $this->sendTimeUpNotification($booking, $overstay_charge);
    }
    
    return $results;
}

/**
 * Send time up notification (different from expired)
 */
private function sendTimeUpNotification($booking, $overstay_charge) {
    $to = $booking['email'];
    $subject = "⏰ Your Parking Time Has Ended - Please Check Out";
    
    $message = "
    <html>
    <head>
        <style>
            body { font-family: Arial, sans-serif; }
            .container { max-width: 600px; margin: 0 auto; }
            .header { background: #F59E0B; color: white; padding: 20px; text-align: center; }
            .content { padding: 20px; }
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
                <p>Please proceed to the exit. The owner has been notified to confirm your checkout.</p>";
    
    if ($overstay_charge > 0) {
        $message .= "<p style='color: #DC2626;'><strong>Note:</strong> An overstay charge of ₦" . number_format($overstay_charge, 2) . " will be applied upon checkout.</p>";
    }
    
    $message .= "
                <p>Thank you for using SpaceNode!</p>
            </div>
        </div>
    </body>
    </html>";
    
    $headers = "MIME-Version: 1.0\r\n";
    $headers .= "Content-type:text/html;charset=UTF-8\r\n";
    $headers .= "From: SpaceNode <noreply@spacenode.com>\r\n";
    
    mail($to, $subject, $message, $headers);
}
    
    /**
     * Mark booking as expired
     */
    public function markAsExpired($reservation_id, $reason = '') {
        $update = $this->db->prepare("UPDATE reservations SET 
                                       timer_status = 'expired'
                                       WHERE id = :id");
        return $update->execute([':id' => $reservation_id]);
    }
    
/**
 * Get active sessions for an owner - INCLUDES PENDING CHECKOUT
 */
public function getOwnerActiveSessions($owner_id) {
    $query = "SELECT r.*, u.first_name, u.last_name, u.phone,
              ps.name as parking_name, ps.address,
              CASE 
                  WHEN r.timer_status = 'active' AND r.actual_end_time < NOW() THEN 'pending_checkout'
                  ELSE r.timer_status
              END as actual_status,
              r.actual_end_time
              FROM reservations r
              JOIN parking_spaces ps ON r.parking_id = ps.id
              JOIN users u ON r.user_id = u.id
              WHERE ps.owner_id = :owner_id
              AND (r.timer_status = 'active' OR r.timer_status = 'pending_checkout')
              ORDER BY 
                CASE 
                    WHEN r.timer_status = 'active' THEN 1
                    WHEN r.timer_status = 'pending_checkout' THEN 2
                END,
                r.actual_start_time DESC";
    
    $stmt = $this->db->prepare($query);
    $stmt->execute([':owner_id' => $owner_id]);
    $sessions = $stmt->fetchAll(PDO::FETCH_ASSOC);
    
    // Calculate remaining time for each active session
    foreach ($sessions as &$session) {
        if ($session['timer_status'] == 'active') {
            // Check if the session has actually expired
            $end_time = new DateTime($session['actual_end_time']);
            $now = new DateTime();
            
            if ($now > $end_time) {
                // This session should be in pending_checkout
                $session['timer_status'] = 'pending_checkout';
                $session['remaining_seconds'] = 0;
                $session['remaining_formatted'] = '0m 0s';
            } else {
                $remaining = $this->getRemainingTime($session);
                $session['remaining_seconds'] = $remaining['total_seconds'] ?? 0;
                $session['remaining_formatted'] = $remaining['formatted'] ?? '0s';
            }
        }
    }
    
    return $sessions;
}
    
    /**
     * Get booking status for display
     */
    public function getBookingStatus($reservation) {
        $status = $reservation['timer_status'];
        $now = new DateTime();
        
        if ($status == 'active') {
            $end = new DateTime($reservation['actual_end_time']);
            if ($now > $end) {
                return 'overstay';
            }
            return 'active';
        }
        
        if ($status == 'pending') {
            $start = new DateTime($reservation['start_date']);
            $grace = clone $start;
            $grace->modify('+30 minutes');
            
            if ($now > $grace) {
                return 'expired';
            }
            return 'pending';
        }
        
        if ($status == 'pending_checkout') {
            return 'pending_checkout';
        }
        
        return $status;
    }
    
    
/**
 * Get remaining time for active booking
 */
public function getRemainingTime($reservation) {
    if ($reservation['timer_status'] != 'active') {
        return null;
    }
    
    $now = new DateTime();
    $end = new DateTime($reservation['actual_end_time']);
    
    if ($now > $end) {
        return [
            'hours' => 0,
            'minutes' => 0,
            'seconds' => 0,
            'total_seconds' => 0,
            'formatted' => '0m 0s'
        ];
    }
    
    $interval = $now->diff($end);
    $total_seconds = ($interval->days * 86400) + ($interval->h * 3600) + ($interval->i * 60) + $interval->s;
    
    $hours = $interval->h + ($interval->days * 24);
    $minutes = $interval->i;
    $seconds = $interval->s;
    
    // Format nicely
    if ($hours > 0) {
        $formatted = "{$hours}h {$minutes}m {$seconds}s";
    } elseif ($minutes > 0) {
        $formatted = "{$minutes}m {$seconds}s";
    } else {
        $formatted = "{$seconds}s";
    }
    
    return [
        'hours' => $hours,
        'minutes' => $minutes,
        'seconds' => $seconds,
        'total_seconds' => $total_seconds,
        'formatted' => $formatted
    ];
}
}
?>