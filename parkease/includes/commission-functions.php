<?php
/**
 * Commission Functions for SpaceNode
 * Handles all commission calculations and owner balance management
 */

class CommissionManager {
    private $db;
    private $commission_rate = 15.00;
    private $min_commission = 100;
    private $max_commission = 50000;
    
    public function __construct($db) {
        $this->db = $db;
        $this->loadCommissionSettings();
    }
    
    /**
     * Load current commission settings from database
     */
    private function loadCommissionSettings() {
        $query = "SELECT * FROM commission_settings WHERE is_active = 1 ORDER BY id DESC LIMIT 1";
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $settings = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($settings) {
            $this->commission_rate = $settings['commission_rate'];
            $this->min_commission = $settings['min_commission'];
            $this->max_commission = $settings['max_commission'];
        }
    }
    
    /**
     * Calculate commission for a booking
     * @param float $amount The total booking amount
     * @return array Commission details
     */
    public function calculateCommission($amount) {
        // Calculate raw commission
        $raw_commission = $amount * ($this->commission_rate / 100);
        
        // Apply minimum commission
        if ($raw_commission < $this->min_commission) {
            $commission = $this->min_commission;
        } 
        // Apply maximum cap
        elseif ($raw_commission > $this->max_commission) {
            $commission = $this->max_commission;
        } 
        else {
            $commission = $raw_commission;
        }
        
        // Round to nearest whole naira 
        $commission = ceil($commission);
        
        $owner_payout = $amount - $commission;
        
        return [
            'gross_amount' => $amount,
            'commission_rate' => $this->commission_rate,
            'raw_commission' => round($raw_commission, 2),
            'commission_amount' => $commission,
            'owner_payout' => $owner_payout,
            'min_applied' => ($raw_commission < $this->min_commission),
            'max_applied' => ($raw_commission > $this->max_commission)
        ];
    }
    
    /**
     * Update reservation with commission details
     * @param int $reservation_id
     * @param array $commission_details
     * @return bool
     */
    public function updateReservationCommission($reservation_id, $commission_details) {
        $query = "UPDATE reservations SET 
                  gross_amount = :gross_amount,
                  commission_amount = :commission_amount,
                  commission_rate = :commission_rate,
                  owner_payout = :owner_payout,
                  payout_status = 'pending'
                  WHERE id = :id";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':gross_amount', $commission_details['gross_amount']);
        $stmt->bindParam(':commission_amount', $commission_details['commission_amount']);
        $stmt->bindParam(':commission_rate', $commission_details['commission_rate']);
        $stmt->bindParam(':owner_payout', $commission_details['owner_payout']);
        $stmt->bindParam(':id', $reservation_id);
        
        return $stmt->execute();
    }
    
    /**
     * Record platform earnings
     * @param int $reservation_id
     * @param array $commission_details
     * @return bool
     */
    public function recordPlatformEarnings($reservation_id, $commission_details) {
        $query = "INSERT INTO platform_earnings 
                  (reservation_id, gross_amount, commission_amount, commission_rate, owner_payout) 
                  VALUES 
                  (:reservation_id, :gross_amount, :commission_amount, :commission_rate, :owner_payout)";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':reservation_id', $reservation_id);
        $stmt->bindParam(':gross_amount', $commission_details['gross_amount']);
        $stmt->bindParam(':commission_amount', $commission_details['commission_amount']);
        $stmt->bindParam(':commission_rate', $commission_details['commission_rate']);
        $stmt->bindParam(':owner_payout', $commission_details['owner_payout']);
        
        return $stmt->execute();
    }
    
    /**
     * Get owner balance details
     * @param int $owner_id
     * @return array
     */
    public function getOwnerBalance($owner_id) {
        $query = "SELECT * FROM owner_balances WHERE owner_id = :owner_id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':owner_id', $owner_id);
        $stmt->execute();
        $balance = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$balance) {
            // Initialize balance for new owner
            return [
                'current_balance' => 0,
                'pending_balance' => 0,
                'total_earned' => 0,
                'total_commission' => 0,
                'total_withdrawn' => 0
            ];
        }
        
        return $balance;
    }
    
    /**
     * Get owner's transaction history
     * @param int $owner_id
     * @param int $limit
     * @return array
     */
    public function getOwnerTransactions($owner_id, $limit = 20) {
        $query = "SELECT r.id, r.booking_reference, r.gross_amount, r.commission_amount, 
                         r.owner_payout, r.status, r.payout_status, r.start_date, r.end_date,
                         ps.name as parking_name
                  FROM reservations r
                  JOIN parking_spaces ps ON r.parking_id = ps.id
                  WHERE r.owner_id = :owner_id
                  ORDER BY r.created_at DESC
                  LIMIT :limit";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':owner_id', $owner_id);
        $stmt->bindParam(':limit', $limit, PDO::PARAM_INT);
        $stmt->execute();
        
        return $stmt->fetchAll(PDO::FETCH_ASSOC);
    }
    
    /**
     * Get pending payouts for an owner
     * @param int $owner_id
     * @return float
     */
    public function getPendingPayouts($owner_id) {
        $query = "SELECT SUM(owner_payout) as total 
                  FROM reservations 
                  WHERE owner_id = :owner_id 
                  AND status IN ('completed') 
                  AND payout_status = 'pending'";
        
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':owner_id', $owner_id);
        $stmt->execute();
        $result = $stmt->fetch(PDO::FETCH_ASSOC);
        
        return $result['total'] ?? 0;
    }
    
    /**
     * Process cancellation refund with commission logic
     * @param int $reservation_id
     * @param string $cancelled_by (user or owner)
     * @return array Refund details
     */
    public function processCancellation($reservation_id, $cancelled_by) {
        // Get reservation details
        $query = "SELECT r.*, ps.owner_id FROM reservations r
                  JOIN parking_spaces ps ON r.parking_id = ps.id
                  WHERE r.id = :id";
        $stmt = $this->db->prepare($query);
        $stmt->bindParam(':id', $reservation_id);
        $stmt->execute();
        $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if (!$reservation) {
            return ['success' => false, 'message' => 'Reservation not found'];
        }
        
        $refund_amount = 0;
        $commission_handling = '';
        $owner_refund = 0;
        
        // Check cancellation timing
        $start_time = new DateTime($reservation['start_date']);
        $now = new DateTime();
        $hours_until_start = ($start_time->getTimestamp() - $now->getTimestamp()) / 3600;
        
        if ($cancelled_by == 'owner') {
            // Owner cancelled - refund full amount to user, platform keeps NO commission
            $refund_amount = $reservation['total_amount'];
            $owner_refund = 0;
            $commission_handling = 'Owner cancelled - Platform keeps no commission';
            
            // Update reservation
            $update = "UPDATE reservations SET 
                       status = 'cancelled', 
                       payout_status = 'cancelled',
                       notes = CONCAT(IFNULL(notes, ''), ' | Cancelled by owner. Full refund issued.')
                       WHERE id = :id";
            
        } elseif ($cancelled_by == 'user') {
            if ($hours_until_start >= 1) {
                // User cancelled more than 1 hour before - refund owner's portion only
                $refund_amount = $reservation['owner_payout']; // Refund only what owner would have gotten
                $owner_refund = 0;
                $commission_handling = 'User cancelled before 1 hour - Platform keeps commission';
                
                $update = "UPDATE reservations SET 
                           status = 'cancelled', 
                           payout_status = 'cancelled',
                           notes = CONCAT(IFNULL(notes, ''), ' | Cancelled by user. Owner portion refunded.')
                           WHERE id = :id";
            } else {
                // User cancelled after start - no refund, platform keeps everything
                $refund_amount = 0;
                $owner_refund = 0;
                $commission_handling = 'User cancelled after start - No refund, platform keeps all';
                
                $update = "UPDATE reservations SET 
                           status = 'cancelled', 
                           payout_status = 'cancelled',
                           notes = CONCAT(IFNULL(notes, ''), ' | Cancelled by user after start. No refund.')
                           WHERE id = :id";
            }
        }
        
        $update_stmt = $this->db->prepare($update);
        $update_stmt->bindParam(':id', $reservation_id);
        $update_stmt->execute();
        
        return [
            'success' => true,
            'refund_amount' => $refund_amount,
            'commission_handling' => $commission_handling,
            'owner_refund' => $owner_refund,
            'message' => 'Cancellation processed successfully'
        ];
    }
    
    /**
     * Process daily payouts for owners
     * @return array Processing results
     */
    public function processDailyPayouts() {
        // Get all completed reservations with pending payouts
        $query = "SELECT r.*, u.id as owner_id, u.email, u.first_name, u.last_name,
                         ob.current_balance
                  FROM reservations r
                  JOIN users u ON r.owner_id = u.id
                  LEFT JOIN owner_balances ob ON u.id = ob.owner_id
                  WHERE r.status = 'completed' 
                  AND r.payout_status = 'pending'
                  AND r.owner_payout > 0
                  GROUP BY r.owner_id";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        $owners = $stmt->fetchAll(PDO::FETCH_ASSOC);
        
        $results = [];
        
        foreach ($owners as $owner) {
            // Get all pending payouts for this owner
            $payout_query = "SELECT SUM(owner_payout) as total, GROUP_CONCAT(id) as reservation_ids
                            FROM reservations 
                            WHERE owner_id = :owner_id 
                            AND status = 'completed' 
                            AND payout_status = 'pending'";
            $payout_stmt = $this->db->prepare($payout_query);
            $payout_stmt->bindParam(':owner_id', $owner['owner_id']);
            $payout_stmt->execute();
            $payout_data = $payout_stmt->fetch(PDO::FETCH_ASSOC);
            
            $total_payout = $payout_data['total'] ?? 0;
            $reservation_ids = explode(',', $payout_data['reservation_ids']);
            
            if ($total_payout >= 100) { // Minimum payout
                // Create payout record
                $ref = 'PO_' . uniqid() . '_' . time();
                
                $insert = "INSERT INTO owner_payouts 
                          (owner_id, amount, status, reference, notes) 
                          VALUES 
                          (:owner_id, :amount, 'processing', :reference, 'Daily automatic payout')";
                $insert_stmt = $this->db->prepare($insert);
                $insert_stmt->bindParam(':owner_id', $owner['owner_id']);
                $insert_stmt->bindParam(':amount', $total_payout);
                $insert_stmt->bindParam(':reference', $ref);
                $insert_stmt->execute();
                
                // Update reservations
                $update = "UPDATE reservations SET payout_status = 'processing', payout_date = NOW() 
                          WHERE id IN (" . implode(',', $reservation_ids) . ")";
                $this->db->exec($update);
                
                $results[] = [
                    'owner_id' => $owner['owner_id'],
                    'owner_name' => $owner['first_name'] . ' ' . $owner['last_name'],
                    'amount' => $total_payout,
                    'reference' => $ref,
                    'status' => 'processing'
                ];
            }
        }
        
        return $results;
    }
    
    /**
     * Get platform earnings summary
     * @param string $period (today, week, month, year)
     * @return array
     */
    public function getPlatformEarnings($period = 'month') {
        $date_filter = '';
        switch ($period) {
            case 'today':
                $date_filter = "DATE(recorded_date) = CURDATE()";
                break;
            case 'week':
                $date_filter = "YEARWEEK(recorded_date) = YEARWEEK(CURDATE())";
                break;
            case 'month':
                $date_filter = "MONTH(recorded_date) = MONTH(CURDATE()) AND YEAR(recorded_date) = YEAR(CURDATE())";
                break;
            case 'year':
                $date_filter = "YEAR(recorded_date) = YEAR(CURDATE())";
                break;
        }
        
        $query = "SELECT 
                  COUNT(*) as total_bookings,
                  SUM(gross_amount) as total_gross,
                  SUM(commission_amount) as total_commission,
                  SUM(owner_payout) as total_owner_payouts
                  FROM platform_earnings
                  WHERE $date_filter";
        
        $stmt = $this->db->prepare($query);
        $stmt->execute();
        
        return $stmt->fetch(PDO::FETCH_ASSOC);
    }
}

/**
 * Helper function to format currency
 */
function format_currency($amount) {
    return '₦' . number_format($amount, 2);
}

/**
 * Helper function to get status badge HTML
 */
function get_status_badge($status) {
    $colors = [
        'pending' => 'bg-yellow-100 text-yellow-800',
        'processing' => 'bg-blue-100 text-blue-800',
        'completed' => 'bg-green-100 text-green-800',
        'cancelled' => 'bg-red-100 text-red-800',
        'paid' => 'bg-green-100 text-green-800'
    ];
    
    $color = $colors[$status] ?? 'bg-gray-100 text-gray-800';
    return "<span class='status-badge $color'>" . ucfirst($status) . "</span>";
}
?>