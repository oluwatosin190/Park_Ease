<?php
session_start();
require_once '../includes/user-access.php';
require_once '../config/database.php';
require_once '../includes/pin-functions.php';

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: ../login.php');
    exit();
}

// Create database connection FIRST
$database = new Database();
$db = $database->getConnection();

$force_pending = $db->prepare("UPDATE reservations 
                                SET timer_status = 'pending_checkout',
                                    checkout_status = 'pending'
                                WHERE timer_status = 'active' 
                                AND actual_end_time < NOW()");
$force_pending->execute();

// Update any sessions that should be in pending_checkout
$check_expired = $db->prepare("UPDATE reservations SET 
                                timer_status = 'pending_checkout',
                                checkout_status = 'pending'
                                WHERE timer_status = 'active' 
                                AND actual_end_time < NOW()");
$check_expired->execute();

$pinManager = new PinManager($db);

$owner_id = $_SESSION['user_id'];

// Handle end session request with reason
if (isset($_POST['confirm_end_session'])) {
    $session_id = $_POST['session_id'];
    $end_reason = $_POST['end_reason'];
    $other_reason_text = isset($_POST['other_reason_text']) ? trim($_POST['other_reason_text']) : '';
    
    // If reason is "other", use the custom text
    if ($end_reason === 'other' && !empty($other_reason_text)) {
        $end_reason = 'Other: ' . $other_reason_text;
    }
    
    // Verify ownership
    $check = $db->prepare("SELECT r.id FROM reservations r
                            JOIN parking_spaces ps ON r.parking_id = ps.id
                            WHERE r.id = :id AND ps.owner_id = :oid");
    $check->execute([':id' => $session_id, ':oid' => $owner_id]);
    
    if ($check->fetch()) {
        // End the session early
        $update = $db->prepare("UPDATE reservations SET 
                                 timer_status = 'completed',
                                 status = 'completed',
                                 checkout_status = 'confirmed',
                                 actual_end_time = NOW(),
                                 early_end_reason = :reason
                                 WHERE id = :id");
        $update->execute([
            ':id' => $session_id,
            ':reason' => $end_reason
        ]);
        
        $_SESSION['success'] = 'Session ended successfully';
    }
    header('Location: active-sessions.php');
    exit();
}

$sessions = $pinManager->getOwnerActiveSessions($owner_id);
$success = $_SESSION['success'] ?? '';
$error = $_SESSION['error'] ?? '';
unset($_SESSION['success'], $_SESSION['error']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Active Sessions - ParkEase Owner</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'DM Sans', sans-serif;
            background: radial-gradient(ellipse at 0% 0%, #1a1a2e 0%, #16213e 40%, #0f0f23 100%);
            min-height: 100vh;
            padding: 40px 20px;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Animated mesh gradient overlay */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 50%, rgba(79,110,247,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(124,58,237,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 40% 20%, rgba(236,72,153,0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        
        .container {
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }
        
        /* Glassmorphism Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(24px, 5vw, 32px);
            font-weight: 700;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }
        
        .header-buttons {
            display: flex;
            gap: 12px;
        }
        
        .btn-glass {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 50px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-glass:hover {
            background: rgba(255,255,255,0.15);
            transform: translateY(-2px);
            border-color: rgba(255,255,255,0.3);
        }
        
        /* Glassmorphism Alerts */
        .alert-success {
            background: rgba(34,197,94,0.15);
            color: #4ade80;
            padding: 16px 20px;
            border-radius: 20px;
            margin-bottom: 24px;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(34,197,94,0.3);
            animation: slideDown 0.4s ease;
        }
        
        .alert-error {
            background: rgba(239,68,68,0.15);
            color: #f87171;
            padding: 16px 20px;
            border-radius: 20px;
            margin-bottom: 24px;
            backdrop-filter: blur(20px);
            border: 1px solid rgba(239,68,68,0.3);
            animation: slideDown 0.4s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Glassmorphism Sessions Grid */
        .sessions-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 24px;
        }
        
        .session-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 24px;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .session-card:nth-child(1) { animation-delay: 0.05s; }
        .session-card:nth-child(2) { animation-delay: 0.1s; }
        .session-card:nth-child(3) { animation-delay: 0.15s; }
        .session-card:nth-child(4) { animation-delay: 0.2s; }
        
        .session-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.3);
            box-shadow: 0 20px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        .session-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 16px;
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .customer-name {
            font-family: 'Outfit', sans-serif;
            font-size: 18px;
            font-weight: 600;
            color: white;
        }
        
        /* Glassmorphism Badges */
        .timer-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }
        
        .badge-active {
            background: rgba(34,197,94,0.15);
            color: #4ade80;
            border: 1px solid rgba(34,197,94,0.2);
        }
        
        .badge-pending {
            background: rgba(245,158,11,0.15);
            color: #fbbf24;
            border: 1px solid rgba(245,158,11,0.2);
        }
        
        .parking-name {
            font-size: 15px;
            margin-bottom: 12px;
            color: #a5b4fc;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Glassmorphism Timer Display */
        .timer-display {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            padding: 20px;
            border-radius: 20px;
            text-align: center;
            margin: 16px 0;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .time-remaining {
            font-family: 'Outfit', sans-serif;
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .time-expired {
            font-family: 'Outfit', sans-serif;
            font-size: 28px;
            font-weight: 800;
            color: #fbbf24;
        }
        
        .time-label {
            font-size: 12px;
            color: rgba(255,255,255,0.5);
            margin-top: 8px;
        }
        
        /* Session Details */
        .session-details {
            margin-top: 16px;
            padding-top: 16px;
            border-top: 1px solid rgba(255,255,255,0.1);
            font-size: 13px;
            display: flex;
            flex-direction: column;
            gap: 8px;
        }
        
        .session-details div {
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255,255,255,0.7);
        }
        
        .overstay-charge {
            color: #f87171 !important;
            font-weight: 600;
            padding: 8px;
            background: rgba(239,68,68,0.1);
            border-radius: 12px;
            margin-top: 4px;
        }
        
        /* Glassmorphism Buttons */
        .btn-end {
            width: 100%;
            padding: 12px;
            background: rgba(239,68,68,0.15);
            color: #f87171;
            border: 1px solid rgba(239,68,68,0.3);
            border-radius: 50px;
            font-weight: 600;
            margin-top: 16px;
            cursor: pointer;
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            font-family: 'Outfit', sans-serif;
        }
        
        .btn-end:hover {
            background: rgba(239,68,68,0.25);
            transform: translateY(-2px);
        }
        
        .btn-confirm {
            width: 100%;
            padding: 12px;
            background: linear-gradient(135deg, #10B981, #059669);
            color: white;
            border: none;
            border-radius: 50px;
            font-weight: 600;
            margin-top: 16px;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-family: 'Outfit', sans-serif;
            box-shadow: 0 4px 15px rgba(16,185,129,0.3);
        }
        
        .btn-confirm:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16,185,129,0.4);
        }
        
        /* Glassmorphism Empty State */
        .empty-state {
            text-align: center;
            padding: 80px 40px;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 28px;
            grid-column: 1 / -1;
        }
        
        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.4;
            stroke: rgba(255,255,255,0.5);
        }
        
        .empty-state h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 22px;
            color: white;
            margin-bottom: 10px;
        }
        
        .empty-state p {
            color: rgba(255,255,255,0.6);
        }
        
        /* Glassmorphism Modal */
        .modal-overlay {
            display: none;
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: rgba(0, 0, 0, 0.7);
            backdrop-filter: blur(8px);
            z-index: 1000;
            align-items: center;
            justify-content: center;
        }
        
        .modal-overlay.active {
            display: flex;
        }
        
        .modal-content {
            background: rgba(26,26,46,0.95);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 32px;
            padding: 32px;
            max-width: 500px;
            width: 90%;
            max-height: 85vh;
            overflow-y: auto;
            box-shadow: 0 20px 60px rgba(0,0,0,0.5);
        }
        
        .modal-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
        }
        
        .modal-header h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            font-weight: 600;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .modal-close {
            background: rgba(255,255,255,0.1);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 50%;
            width: 36px;
            height: 36px;
            font-size: 20px;
            cursor: pointer;
            color: rgba(255,255,255,0.8);
            transition: all 0.3s ease;
        }
        
        .modal-close:hover {
            background: rgba(255,255,255,0.2);
            transform: rotate(90deg);
        }
        
        .reason-options {
            display: flex;
            flex-direction: column;
            gap: 12px;
            margin-bottom: 20px;
        }
        
        .reason-option {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 14px 16px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 60px;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .reason-option:hover {
            border-color: rgba(165,180,252,0.4);
            background: rgba(255,255,255,0.08);
        }
        
        .reason-option input[type="radio"] {
            width: 18px;
            height: 18px;
            accent-color: #4F6EF7;
        }
        
        .reason-option label {
            font-size: 14px;
            color: rgba(255,255,255,0.9);
            cursor: pointer;
            flex: 1;
        }
        
        .other-reason-input {
            display: none;
            margin-top: 16px;
        }
        
        .other-reason-input.active {
            display: block;
        }
        
        .other-reason-input textarea {
            width: 100%;
            padding: 14px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 20px;
            font-family: 'DM Sans', sans-serif;
            font-size: 14px;
            color: white;
            resize: vertical;
            min-height: 100px;
        }
        
        .other-reason-input textarea::placeholder {
            color: rgba(255,255,255,0.4);
        }
        
        .other-reason-input textarea:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.08);
        }
        
        .modal-actions {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }
        
        .modal-btn {
            flex: 1;
            padding: 14px;
            border: none;
            border-radius: 60px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
            font-family: 'Outfit', sans-serif;
        }
        
        .modal-btn-primary {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
        }
        
        .modal-btn-primary:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79,110,247,0.4);
        }
        
        .modal-btn-primary:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        .modal-btn-secondary {
            background: rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.9);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .modal-btn-secondary:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 20px 15px;
            }
            
            .sessions-grid {
                grid-template-columns: 1fr;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .header-buttons {
                width: 100%;
            }
            
            .btn-glass {
                flex: 1;
                justify-content: center;
            }
            
            .time-remaining {
                font-size: 28px;
            }
            
            .modal-content {
                padding: 24px;
            }
        }
        
        @media (max-width: 480px) {
            .session-card {
                padding: 20px;
            }
            
            .time-remaining {
                font-size: 24px;
            }
            
            .customer-name {
                font-size: 16px;
            }
            
            .modal-actions {
                flex-direction: column;
            }
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: rgba(165,180,252,0.4);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(165,180,252,0.6);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-clock"></i> Active Parking Sessions</h1>
            <div class="header-buttons">
                <a href="enter-pin.php" class="btn-glass"><i class="fas fa-key"></i> Enter PIN</a>
                <a href="../dashboard.php" class="btn-glass"><i class="fas fa-tachometer-alt"></i> Dashboard</a>
            </div>
        </div>
        
        <?php if ($success): ?>
            <div class="alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <?php if (empty($sessions)): ?>
            <div class="empty-state">
                <i class="fas fa-hourglass-empty fa-4x" style="color: rgba(255,255,255,0.4); margin-bottom: 20px;"></i>
                <h3>No Active Sessions</h3>
                <p>There are no active or pending checkout sessions at the moment.</p>
            </div>
        <?php else: ?>
            <div class="sessions-grid">
                <?php foreach ($sessions as $session): 
                    $start_time = new DateTime($session['actual_start_time']);
                    $end_time = new DateTime($session['actual_end_time']);
                    $duration = round($session['total_hours'] * 60);
                    $is_pending_checkout = ($session['timer_status'] == 'pending_checkout');
                ?>
                <div class="session-card" 
                     data-session-id="<?php echo $session['id']; ?>" 
                     data-end-time="<?php echo $end_time->getTimestamp(); ?>"
                     data-status="<?php echo $session['timer_status']; ?>">
                    
                    <div class="session-header">
                        <span class="customer-name">
                            <i class="fas fa-user-circle"></i> <?php echo $session['first_name'] . ' ' . $session['last_name']; ?>
                        </span>
                        <?php if ($is_pending_checkout): ?>
                            <span class="timer-badge badge-pending"><i class="fas fa-clock"></i> PENDING CHECKOUT</span>
                        <?php else: ?>
                            <span class="timer-badge badge-active"><i class="fas fa-play-circle"></i> ACTIVE</span>
                        <?php endif; ?>
                    </div>
                    
                    <div class="parking-name">
                        <i class="fas fa-parking"></i> <?php echo $session['parking_name']; ?>
                    </div>
                    
                    <div class="timer-display">
                        <?php if ($is_pending_checkout): ?>
                            <div class="time-expired">
                                <i class="fas fa-hourglass-end"></i> Time Expired
                            </div>
                            <div class="time-label">Waiting for Checkout Confirmation</div>
                        <?php else: ?>
                            <div class="time-remaining" id="timer-<?php echo $session['id']; ?>">
                                Loading...
                            </div>
                            <div class="time-label"><i class="fas fa-hourglass-half"></i> Time Remaining</div>
                        <?php endif; ?>
                    </div>
                    
                    <div class="session-details">
                        <div><i class="fas fa-phone"></i> <?php echo $session['phone'] ?: 'No phone'; ?></div>
                        <div><i class="fas fa-map-marker-alt"></i> <?php echo $session['address']; ?></div>
                        <div><i class="fas fa-play"></i> Started: <?php echo $start_time->format('h:i A'); ?></div>
                        <div><i class="fas <?php echo $is_pending_checkout ? 'fa-stop' : 'fa-hourglass-end'; ?>"></i> <?php echo $is_pending_checkout ? 'Ended:' : 'Ends:'; ?> 
                            <span class="end-time"><?php echo $end_time->format('h:i A'); ?></span>
                        </div>
                        <?php if ($is_pending_checkout && $session['overstay_charge'] > 0): ?>
                            <div class="overstay-charge">
                                <i class="fas fa-exclamation-triangle"></i> Overstay Charge: ₦<?php echo number_format($session['overstay_charge'], 2); ?>
                            </div>
                        <?php endif; ?>
                    </div>
                    
                    <?php if ($is_pending_checkout): ?>
                        <a href="confirm-checkout.php?id=<?php echo $session['id']; ?>" 
                           class="btn-confirm"
                           onclick="return confirm('Confirm that customer has left the parking space?')">
                            <i class="fas fa-check-circle"></i> Confirm Checkout
                        </a>
                    <?php else: ?>
                        <button onclick="openEndSessionModal(<?php echo $session['id']; ?>)" class="btn-end">
                            <i class="fas fa-stop-circle"></i> End Session Early
                        </button>
                    <?php endif; ?>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
    
    <!-- Glassmorphism End Session Modal -->
    <div class="modal-overlay" id="endSessionModal">
        <div class="modal-content">
            <div class="modal-header">
                <h2><i class="fas fa-ban"></i> End Session Early</h2>
                <button class="modal-close" onclick="closeModal()">&times;</button>
            </div>
            
            <form id="endSessionForm" method="POST">
                <input type="hidden" name="session_id" id="modalSessionId">
                <input type="hidden" name="confirm_end_session" value="1">
                
                <p style="color: rgba(255,255,255,0.7); margin-bottom: 20px;">
                    <i class="fas fa-info-circle"></i> Please select a reason for ending this session early:
                </p>
                
                <div class="reason-options">
                    <div class="reason-option">
                        <input type="radio" name="end_reason" id="reason1" value="Customer requested to leave early" required>
                        <label for="reason1">Customer requested to leave early</label>
                    </div>
                    
                    <div class="reason-option">
                        <input type="radio" name="end_reason" id="reason2" value="Vehicle moved/damaged">
                        <label for="reason2">Vehicle moved/damaged</label>
                    </div>
                    
                    <div class="reason-option">
                        <input type="radio" name="end_reason" id="reason3" value="Payment issue detected">
                        <label for="reason3">Payment issue detected</label>
                    </div>
                    
                    <div class="reason-option">
                        <input type="radio" name="end_reason" id="reason4" value="Space needed for maintenance">
                        <label for="reason4">Space needed for maintenance</label>
                    </div>
                    
                    <div class="reason-option">
                        <input type="radio" name="end_reason" id="reason5" value="Wrong vehicle/space assigned">
                        <label for="reason5">Wrong vehicle/space assigned</label>
                    </div>
                    
                    <div class="reason-option">
                        <input type="radio" name="end_reason" id="reason6" value="Customer not responding">
                        <label for="reason6">Customer not responding</label>
                    </div>
                    
                    <div class="reason-option">
                        <input type="radio" name="end_reason" id="reasonOther" value="other">
                        <label for="reasonOther">Other (please specify)</label>
                    </div>
                </div>
                
                <div class="other-reason-input" id="otherReasonContainer">
                    <textarea name="other_reason_text" id="otherReasonText" placeholder="Please explain why you need to end this session early..."></textarea>
                </div>
                
                <div class="modal-actions">
                    <button type="button" class="modal-btn modal-btn-secondary" onclick="closeModal()">
                        <i class="fas fa-times"></i> Cancel
                    </button>
                    <button type="submit" class="modal-btn modal-btn-primary" id="submitEndSession" disabled>
                        <i class="fas fa-check"></i> End Session
                    </button>
                </div>
            </form>
        </div>
    </div>
    
    <script>
        // Update timers every second
        function updateTimers() {
            const now = Math.floor(Date.now() / 1000);
            let anyExpired = false;
            
            document.querySelectorAll('[data-end-time]').forEach(card => {
                const sessionId = card.dataset.sessionId;
                const status = card.dataset.status;
                
                if (status !== 'active') return;
                
                const endTime = parseInt(card.dataset.endTime);
                const remaining = endTime - now;
                const timerElement = document.getElementById('timer-' + sessionId);
                
                if (!timerElement) return;
                
                if (remaining <= 0) {
                    timerElement.innerHTML = '0m 0s';
                    timerElement.style.background = 'linear-gradient(135deg, #f87171, #dc2626)';
                    timerElement.style.webkitBackgroundClip = 'text';
                    timerElement.style.webkitTextFillColor = 'transparent';
                    anyExpired = true;
                } else {
                    const minutes = Math.floor(remaining / 60);
                    const seconds = remaining % 60;
                    timerElement.innerHTML = minutes + 'm ' + seconds + 's';
                    
                    if (remaining < 300) {
                        timerElement.style.background = 'linear-gradient(135deg, #f87171, #dc2626)';
                        timerElement.style.webkitBackgroundClip = 'text';
                        timerElement.style.webkitTextFillColor = 'transparent';
                    } else if (remaining < 600) {
                        timerElement.style.background = 'linear-gradient(135deg, #fbbf24, #f59e0b)';
                        timerElement.style.webkitBackgroundClip = 'text';
                        timerElement.style.webkitTextFillColor = 'transparent';
                    } else {
                        timerElement.style.background = 'linear-gradient(135deg, #a5b4fc, #c4b5fd)';
                        timerElement.style.webkitBackgroundClip = 'text';
                        timerElement.style.webkitTextFillColor = 'transparent';
                    }
                }
            });
            
            if (anyExpired) {
                setTimeout(() => {
                    location.reload();
                }, 3000);
            }
        }
        
        function openEndSessionModal(sessionId) {
            document.getElementById('modalSessionId').value = sessionId;
            document.getElementById('endSessionModal').classList.add('active');
        }
        
        function closeModal() {
            document.getElementById('endSessionModal').classList.remove('active');
            document.querySelectorAll('input[name="end_reason"]').forEach(radio => radio.checked = false);
            document.getElementById('otherReasonText').value = '';
            document.getElementById('otherReasonContainer').classList.remove('active');
            document.getElementById('submitEndSession').disabled = true;
        }
        
        document.querySelectorAll('input[name="end_reason"]').forEach(radio => {
            radio.addEventListener('change', function() {
                const submitBtn = document.getElementById('submitEndSession');
                const otherContainer = document.getElementById('otherReasonContainer');
                const otherText = document.getElementById('otherReasonText');
                
                if (this.value === 'other') {
                    otherContainer.classList.add('active');
                    submitBtn.disabled = otherText.value.trim() === '';
                } else {
                    otherContainer.classList.remove('active');
                    submitBtn.disabled = false;
                }
            });
        });
        
        document.getElementById('otherReasonText').addEventListener('input', function() {
            const submitBtn = document.getElementById('submitEndSession');
            const otherRadio = document.getElementById('reasonOther');
            
            if (otherRadio.checked) {
                submitBtn.disabled = this.value.trim() === '';
            }
        });
        
        document.getElementById('endSessionForm').addEventListener('submit', function(e) {
            const otherRadio = document.getElementById('reasonOther');
            const otherText = document.getElementById('otherReasonText');
            
            if (otherRadio.checked && otherText.value.trim() === '') {
                e.preventDefault();
                alert('Please specify a reason for ending the session early.');
            }
        });
        
        document.getElementById('endSessionModal').addEventListener('click', function(e) {
            if (e.target === this) {
                closeModal();
            }
        });
        
        updateTimers();
        setInterval(updateTimers, 1000);
    </script>
</body>
</html>