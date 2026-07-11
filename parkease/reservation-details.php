<?php
session_start();
require_once 'config/database.php';
require_once 'includes/commission-functions.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Function to format duration in user-friendly way
function formatDuration($start_date, $end_date) {
    try {
        $start = new DateTime($start_date);
        $end = new DateTime($end_date);
        
        $totalMinutes = ($end->getTimestamp() - $start->getTimestamp()) / 60;
        
        $minutesInHour = 60;
        $minutesInDay = 24 * $minutesInHour;
        $minutesInMonth = 30 * $minutesInDay;
        
        if ($totalMinutes < $minutesInHour) {
            return round($totalMinutes) . ' ' . (round($totalMinutes) == 1 ? 'minute' : 'minutes');
        } 
        elseif ($totalMinutes < $minutesInDay) {
            $hours = floor($totalMinutes / $minutesInHour);
            $minutes = $totalMinutes % $minutesInHour;
            $result = $hours . ' ' . ($hours == 1 ? 'hour' : 'hours');
            if ($minutes > 0) {
                $result .= ' ' . round($minutes) . ' ' . (round($minutes) == 1 ? 'minute' : 'minutes');
            }
            return $result;
        } 
        elseif ($totalMinutes < $minutesInMonth) {
            $days = floor($totalMinutes / $minutesInDay);
            $remainingMinutes = $totalMinutes % $minutesInDay;
            $result = $days . ' ' . ($days == 1 ? 'day' : 'days');
            if ($remainingMinutes > 0) {
                $hours = floor($remainingMinutes / $minutesInHour);
                if ($hours > 0) {
                    $result .= ' ' . $hours . ' ' . ($hours == 1 ? 'hour' : 'hours');
                }
                $minutes = $remainingMinutes % $minutesInHour;
                if ($minutes > 0) {
                    $result .= ' ' . round($minutes) . ' ' . (round($minutes) == 1 ? 'minute' : 'minutes');
                }
            }
            return $result;
        } 
        else {
            $months = floor($totalMinutes / $minutesInMonth);
            $remainingMinutes = $totalMinutes % $minutesInMonth;
            $days = floor($remainingMinutes / $minutesInDay);
            $result = $months . ' ' . ($months == 1 ? 'month' : 'months');
            if ($days > 0) {
                $result .= ' ' . $days . ' ' . ($days == 1 ? 'day' : 'days');
            }
            return $result;
        }
    } catch (Exception $e) {
        error_log("Duration formatting error: " . $e->getMessage());
        return 'Invalid date';
    }
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    error_log("Database connection failed in reservation-details.php");
    $_SESSION['error'] = 'System error. Please try again later.';
    header('Location: dashboard.php');
    exit();
}

$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($reservation_id <= 0) {
    $_SESSION['error'] = 'Invalid reservation ID.';
    header('Location: dashboard.php');
    exit();
}

// Get reservation details with proper joins
$query = "SELECT r.*, 
          ps.name as parking_name, ps.address, ps.city, ps.images,
          u.first_name, u.last_name, u.email, u.phone,
          owner.first_name as owner_first_name, owner.last_name as owner_last_name, owner.email as owner_email,
          p.transaction_id, p.payment_method as payment_method_detail
          FROM reservations r
          JOIN parking_spaces ps ON r.parking_id = ps.id
          JOIN users u ON r.user_id = u.id
          JOIN users owner ON r.owner_id = owner.id
          LEFT JOIN payments p ON r.id = p.reservation_id
          WHERE r.id = :id AND (r.user_id = :user_id OR r.owner_id = :user_id)";

try {
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $reservation_id, PDO::PARAM_INT);
    $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Reservation details query error: " . $e->getMessage());
    $_SESSION['error'] = 'Unable to load reservation details.';
    header('Location: dashboard.php');
    exit();
}

if (!$reservation) {
    $_SESSION['error'] = 'Reservation not found or you do not have permission to view it.';
    header('Location: dashboard.php');
    exit();
}

// Get images safely
$images = !empty($reservation['images']) ? json_decode($reservation['images'], true) : [];
$main_image = !empty($images) ? ('uploads/parking/' . $images[0]) : 'img/parking-placeholder.jpg';
if (!file_exists($main_image)) {
    $main_image = 'img/parking-placeholder.jpg';
}

// Check if user can cancel (within 1 hour of start time)
$start = new DateTime($reservation['start_date']);
$now = new DateTime();
$cancellation_deadline = clone $start;
$cancellation_deadline->modify('-1 hour');
$can_cancel = (in_array($reservation['status'], ['pending', 'confirmed'])) && 
               ($now < $cancellation_deadline) &&
               ($reservation['timer_status'] != 'active') &&
               ($reservation['timer_status'] != 'pending_checkout');

// Get commission manager for helper functions
$commission = new CommissionManager($db);

// Calculate duration for display
$duration_display = formatDuration($reservation['start_date'], $reservation['end_date']);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="robots" content="noindex, nofollow">
    <title>Reservation Details - SpaceNode</title>
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
            max-width: 1000px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }
        
        /* Glassmorphism Back Link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 10px 20px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.15);
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,0.15);
            transform: translateY(-2px);
            border-color: rgba(255,255,255,0.3);
        }
        
        /* Glassmorphism Reservation Card */
        .reservation-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 32px;
            overflow: hidden;
            transition: all 0.4s ease;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
        }
        
        .reservation-card:hover {
            background: rgba(255,255,255,0.08);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        /* Glassmorphism Header */
        .reservation-header {
            background: linear-gradient(135deg, rgba(79,110,247,0.15), rgba(124,58,237,0.15));
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 32px;
        }
        
        .reservation-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }
        
        .booking-reference {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            padding: 8px 18px;
            border-radius: 60px;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 1px;
            color: rgba(255,255,255,0.9);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .reservation-body {
            padding: 32px;
        }
        
        /* Glassmorphism Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 6px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
            backdrop-filter: blur(10px);
        }
        
        .status-pending { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.2); }
        .status-confirmed { background: rgba(34,197,94,0.15); color: #4ade80; border: 1px solid rgba(34,197,94,0.2); }
        .status-active { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.2); }
        .status-completed { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.2); }
        .status-cancelled { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.2); }
        
        /* Glassmorphism Info Sections */
        .info-section {
            background: rgba(255,255,255,0.04);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 20px;
            padding: 24px;
            transition: all 0.3s ease;
        }
        
        .info-section:hover {
            background: rgba(255,255,255,0.06);
            border-color: rgba(255,255,255,0.2);
        }
        
        .info-section h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 18px;
            font-weight: 600;
            color: white;
            margin-bottom: 18px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-section h3 i {
            color: #a5b4fc;
        }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 24px;
            margin: 24px 0;
        }
        
        .info-row {
            display: flex;
            margin-bottom: 12px;
            font-size: 14px;
            flex-wrap: wrap;
        }
        
        .info-label {
            width: 110px;
            color: rgba(255,255,255,0.6);
        }
        
        .info-value {
            flex: 1;
            color: white;
            font-weight: 500;
            word-break: break-word;
        }
        
        /* Glassmorphism Amount Display */
        .amount-large {
            font-family: 'Outfit', sans-serif;
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 10px 0;
        }
        
        .amount-large::before {
            content: '₦';
            font-size: 24px;
            margin-right: 5px;
        }
        
        /* Glassmorphism Commission Breakdown */
        .commission-breakdown {
            background: rgba(255,255,255,0.04);
            border-radius: 24px;
            padding: 24px;
            margin: 20px 0;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .commission-grid {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
        }
        
        .commission-item {
            text-align: center;
            padding: 18px;
            border-radius: 20px;
            backdrop-filter: blur(10px);
        }
        
        .commission-item.gross { background: rgba(79,110,247,0.1); border: 1px solid rgba(79,110,247,0.2); }
        .commission-item.commission { background: rgba(245,158,11,0.1); border: 1px solid rgba(245,158,11,0.2); }
        .commission-item.net { background: rgba(16,185,129,0.1); border: 1px solid rgba(16,185,129,0.2); }
        
        .commission-label {
            font-size: 13px;
            color: rgba(255,255,255,0.7);
            margin-bottom: 8px;
        }
        
        .commission-value {
            font-family: 'Outfit', sans-serif;
            font-size: 26px;
            font-weight: 800;
        }
        
        .commission-value.gross { color: #a5b4fc; }
        .commission-value.commission { color: #fbbf24; }
        .commission-value.net { color: #4ade80; }
        
        .commission-note {
            font-size: 11px;
            color: rgba(255,255,255,0.5);
            margin-top: 6px;
        }
        
        /* Glassmorphism Badges */
        .info-badge {
            background: rgba(79,110,247,0.1);
            backdrop-filter: blur(10px);
            color: #a5b4fc;
            padding: 10px 16px;
            border-radius: 60px;
            font-size: 12px;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-top: 12px;
            border: 1px solid rgba(79,110,247,0.2);
        }
        
        /* Glassmorphism Parking Image */
        .parking-image {
            width: 100%;
            height: 180px;
            border-radius: 16px;
            overflow: hidden;
            margin-top: 15px;
            box-shadow: 0 8px 20px rgba(0,0,0,0.2);
        }
        
        .parking-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.3s ease;
        }
        
        .parking-image img:hover {
            transform: scale(1.02);
        }
        
        /* Glassmorphism Payment Pending Box */
        .payment-pending-box {
            margin: 20px 0;
            padding: 24px;
            background: rgba(245,158,11,0.1);
            border-radius: 20px;
            text-align: center;
            border: 1px solid rgba(245,158,11,0.2);
            backdrop-filter: blur(10px);
        }
        
        .payment-pending-box p {
            color: #fbbf24;
            margin-bottom: 15px;
            font-weight: 500;
        }
        
        .btn-pay {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            background: linear-gradient(135deg, #10B981, #059669);
            color: white;
            padding: 12px 30px;
            border-radius: 60px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(16,185,129,0.3);
        }
        
        .btn-pay:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16,185,129,0.4);
        }
        
        /* Glassmorphism Action Buttons */
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 60px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 8px;
            transition: all 0.3s ease;
            font-family: 'Outfit', sans-serif;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            box-shadow: 0 4px 15px rgba(79,110,247,0.3);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79,110,247,0.4);
        }
        
        .btn-danger {
            background: rgba(239,68,68,0.15);
            color: #f87171;
            border: 1px solid rgba(239,68,68,0.3);
        }
        
        .btn-danger:hover {
            background: rgba(239,68,68,0.25);
            transform: translateY(-2px);
        }
        
        .btn-secondary {
            background: rgba(255,255,255,0.1);
            color: rgba(255,255,255,0.9);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }
        
        .btn-success {
            background: rgba(34,197,94,0.15);
            color: #4ade80;
            border: 1px solid rgba(34,197,94,0.3);
        }
        
        .btn-success:hover {
            background: rgba(34,197,94,0.25);
            transform: translateY(-2px);
        }
        
        /* Glassmorphism Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 20px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            backdrop-filter: blur(20px);
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
        
        .alert-success {
            background: rgba(34,197,94,0.15);
            color: #4ade80;
            border: 1px solid rgba(34,197,94,0.3);
        }
        
        .alert-info {
            background: rgba(59,130,246,0.15);
            color: #60a5fa;
            border: 1px solid rgba(59,130,246,0.3);
        }
        
        .alert-error {
            background: rgba(239,68,68,0.15);
            color: #f87171;
            border: 1px solid rgba(239,68,68,0.3);
        }
        
        /* Cancellation Policy */
        .cancellation-policy {
            margin-top: 20px;
            padding: 16px;
            background: rgba(255,255,255,0.04);
            border-radius: 16px;
            font-size: 12px;
            color: rgba(255,255,255,0.5);
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 20px 15px;
            }
            
            .commission-grid {
                grid-template-columns: 1fr;
                gap: 12px;
            }
            
            .action-buttons {
                flex-direction: column;
            }
            
            .btn {
                width: 100%;
                justify-content: center;
            }
            
            .info-grid {
                grid-template-columns: 1fr;
                gap: 16px;
            }
            
            .info-row {
                flex-direction: column;
            }
            
            .info-label {
                width: 100%;
                margin-bottom: 4px;
            }
            
            .reservation-header {
                padding: 24px;
            }
            
            .reservation-body {
                padding: 24px;
            }
            
            .reservation-header h1 {
                font-size: 24px;
            }
            
            .amount-large {
                font-size: 28px;
            }
        }
        
        @media (max-width: 480px) {
            .reservation-header {
                padding: 20px;
            }
            
            .reservation-body {
                padding: 20px;
            }
            
            .commission-value {
                font-size: 22px;
            }
            
            .info-section {
                padding: 18px;
            }
        }
        
        /* Animation for cards */
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
        
        .reservation-card {
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
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
        <a href="dashboard.php" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to Dashboard
        </a>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo sanitize($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['info'])): ?>
            <div class="alert alert-info"><i class="fas fa-info-circle"></i> <?php echo sanitize($_SESSION['info']); unset($_SESSION['info']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo sanitize($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <div class="reservation-card">
            <div class="reservation-header">
                <h1>Reservation Details</h1>
                <span class="booking-reference">
                    <i class="fas fa-ticket-alt"></i> <?php echo sanitize($reservation['booking_reference']); ?>
                </span>
            </div>
            
            <div class="reservation-body">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 24px; flex-wrap: wrap; gap: 12px;">
                    <span class="status-badge status-<?php echo $reservation['status']; ?>">
                        <i class="fas fa-circle"></i> <?php echo ucfirst(sanitize($reservation['status'])); ?>
                    </span>
                    <span class="status-badge" style="background: rgba(79,110,247,0.15); color: #a5b4fc;">
                        <i class="fas fa-credit-card"></i> Payment: <?php echo ucfirst(sanitize($reservation['payment_status'])); ?>
                    </span>
                </div>
                
                <!-- PAYMENT PENDING SECTION -->
                <?php if ($_SESSION['user_id'] == $reservation['user_id'] && $reservation['payment_status'] == 'pending'): ?>
                    <div class="payment-pending-box">
                        <p><i class="fas fa-exclamation-triangle"></i> This booking requires payment to be confirmed.</p>
                        <a href="process-payment.php?id=<?php echo (int)$reservation_id; ?>" class="btn-pay">
                            <i class="fas fa-credit-card"></i> Pay Now with Paystack
                        </a>
                    </div>
                <?php endif; ?>
                
                <!-- Glassmorphism Commission Breakdown Section -->
                <?php if ($_SESSION['user_id'] == $reservation['owner_id']): ?>
                    <div class="commission-breakdown">
                        <h3 style="margin-bottom: 18px; display: flex; align-items: center; gap: 10px; color: white; font-family: 'Outfit', sans-serif;">
                            <i class="fas fa-chart-line"></i> Earnings Breakdown
                        </h3>
                        <div class="commission-grid">
                            <div class="commission-item gross">
                                <div class="commission-label"><i class="fas fa-shopping-cart"></i> Gross Amount</div>
                                <div class="commission-value gross">₦<?php echo number_format($reservation['gross_amount'] ?? $reservation['total_amount'], 2); ?></div>
                                <div class="commission-note">What customer paid</div>
                            </div>
                            <div class="commission-item commission">
                                <div class="commission-label"><i class="fas fa-percent"></i> Platform Commission (<?php echo (int)($reservation['commission_rate'] ?? 15); ?>%)</div>
                                <div class="commission-value commission">-₦<?php echo number_format($reservation['commission_amount'] ?? 0, 2); ?></div>
                                <div class="commission-note">
                                    <?php 
                                    $gross = $reservation['gross_amount'] ?? $reservation['total_amount'];
                                    $raw = $gross * 0.15;
                                    if ($raw < 100) echo "Minimum commission applied";
                                    elseif ($raw > 50000) echo "Maximum cap applied";
                                    ?>
                                </div>
                            </div>
                            <div class="commission-item net">
                                <div class="commission-label"><i class="fas fa-wallet"></i> Your Earnings</div>
                                <div class="commission-value net">₦<?php echo number_format($reservation['owner_payout'] ?? $reservation['total_amount'], 2); ?></div>
                                <div class="commission-note">Net amount you receive</div>
                            </div>
                        </div>
                        
                        <?php if (($reservation['commission_amount'] ?? 0) == 50000): ?>
                            <div class="info-badge">
                                <i class="fas fa-info-circle"></i> Maximum commission cap (₦50,000) applied to this booking
                            </div>
                        <?php elseif (($reservation['gross_amount'] ?? 0) * 0.15 < 100): ?>
                            <div class="info-badge">
                                <i class="fas fa-info-circle"></i> Minimum commission (₦100) applied to this booking
                            </div>
                        <?php endif; ?>
                    </div>
                <?php else: ?>
                    <!-- CUSTOMER VIEW - Shows only total amount -->
                    <div style="background: rgba(255,255,255,0.04); border-radius: 20px; padding: 24px; margin: 20px 0; border: 1px solid rgba(255,255,255,0.1);">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                            <div>
                                <div style="color: rgba(255,255,255,0.6); font-size: 14px;"><i class="fas fa-money-bill-wave"></i> Total Amount</div>
                                <div class="amount-large"><?php echo number_format($reservation['total_amount'], 2); ?></div>
                            </div>
                            <div style="text-align: right;">
                                <div style="color: rgba(255,255,255,0.6); font-size: 14px;"><i class="fas fa-credit-card"></i> Payment Method</div>
                                <div style="font-weight: 600; color: white;"><?php echo ucfirst(sanitize($reservation['payment_method_detail'] ?: $reservation['payment_method'])); ?></div>
                            </div>
                        </div>
                    </div>
                <?php endif; ?>

                <!-- For owners - PIN entry option -->
                <?php if ($_SESSION['user_id'] == $reservation['owner_id'] && $reservation['payment_status'] == 'paid'): ?>
                    <div style="background: rgba(255,255,255,0.04); border-radius: 20px; padding: 24px; margin: 20px 0; border: 1px solid rgba(255,255,255,0.1);">
                        <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
                            <div>
                                <div style="color: rgba(255,255,255,0.6); font-size: 13px;"><i class="fas fa-hourglass-half"></i> Booking Status</div>
                                <div style="margin-top: 8px;">
                                    <span class="status-badge status-<?php echo $reservation['timer_status']; ?>">
                                        <i class="fas fa-clock"></i> <?php echo ucfirst(sanitize($reservation['timer_status'] ?? 'pending')); ?>
                                    </span>
                                </div>
                            </div>
                        </div>
                        
                        <?php if ($reservation['timer_status'] == 'pending'): ?>
                            <div style="margin-top: 20px; padding: 16px; background: rgba(79,110,247,0.1); border-radius: 16px; border: 1px solid rgba(79,110,247,0.2);">
                                <p style="color: #a5b4fc; margin-bottom: 15px;">
                                    <i class="fas fa-hourglass-start"></i> Customer has paid and is waiting to start their session.
                                </p>
                                <div style="display: flex; gap: 12px; flex-wrap: wrap;">
                                    <a href="owner/enter-pin.php?reservation=<?php echo (int)$reservation_id; ?>" class="btn btn-primary">
                                        <i class="fas fa-key"></i> Enter PIN to Start Timer
                                    </a>
                                    <a href="owner/enter-pin.php" class="btn btn-secondary">
                                        <i class="fas fa-arrow-right"></i> Go to PIN Entry Page
                                    </a>
                                </div>
                            </div>
                        <?php elseif ($reservation['timer_status'] == 'active' && !empty($reservation['actual_start_time'])): ?>
                            <div style="margin-top: 20px; padding: 16px; background: rgba(34,197,94,0.1); border-radius: 16px; border: 1px solid rgba(34,197,94,0.2);">
                                <p style="color: #4ade80;">
                                    <i class="fas fa-check-circle"></i> Session active. Started at: <?php echo date('h:i A', strtotime($reservation['actual_start_time'])); ?>
                                </p>
                            </div>
                        <?php endif; ?>
                    </div>
                <?php endif; ?>
                
                <!-- Glassmorphism Info Grid -->
                <div class="info-grid">
                    <div class="info-section">
                        <h3><i class="fas fa-calendar-alt"></i> Booking Details</h3>
                        <div class="info-row">
                            <span class="info-label">Check-in</span>
                            <span class="info-value"><?php echo date('M d, Y - h:i A', strtotime($reservation['start_date'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Check-out</span>
                            <span class="info-value"><?php echo date('M d, Y - h:i A', strtotime($reservation['end_date'])); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Duration</span>
                            <span class="info-value" id="duration-display">
                                <?php echo $duration_display; ?>
                            </span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Rate Type</span>
                            <span class="info-value"><?php echo ucfirst(sanitize($reservation['rate_type'])); ?> (₦<?php echo number_format($reservation['rate_amount'], 0); ?>)</span>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h3><i class="fas fa-user-circle"></i> <?php echo ($_SESSION['user_id'] == $reservation['owner_id']) ? 'Customer Details' : 'Your Details'; ?></h3>
                        <div class="info-row">
                            <span class="info-label">Name</span>
                            <span class="info-value"><?php echo sanitize($reservation['first_name'] . ' ' . $reservation['last_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Email</span>
                            <span class="info-value"><?php echo sanitize($reservation['email']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Phone</span>
                            <span class="info-value"><?php echo sanitize($reservation['phone'] ?: 'Not provided'); ?></span>
                        </div>
                        
                        <?php if (!empty($reservation['vehicle_number'])): ?>
                        <div class="info-row">
                            <span class="info-label">Vehicle</span>
                            <span class="info-value"><?php echo sanitize($reservation['vehicle_number'] . ' - ' . ($reservation['vehicle_model'] ?? 'Not specified')); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <!-- Parking Space Info -->
                <div class="info-section" style="margin-top: 20px;">
                    <h3><i class="fas fa-parking"></i> Parking Space</h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <div class="info-row">
                                <span class="info-label">Name</span>
                                <span class="info-value"><?php echo sanitize($reservation['parking_name']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Location</span>
                                <span class="info-value"><?php echo sanitize($reservation['address'] . ', ' . $reservation['city']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Owner</span>
                                <span class="info-value"><?php echo sanitize($reservation['owner_first_name'] . ' ' . $reservation['owner_last_name']); ?></span>
                            </div>
                        </div>
                        <div class="parking-image">
                            <img src="<?php echo sanitize($main_image); ?>" alt="<?php echo sanitize($reservation['parking_name']); ?>" onerror="this.src='img/parking-placeholder.jpg'">
                        </div>
                    </div>
                </div>
                
                <?php if (!empty($reservation['special_requests'])): ?>
                <div class="info-section" style="margin-top: 20px;">
                    <h3><i class="fas fa-comment"></i> Special Requests</h3>
                    <p style="color: rgba(255,255,255,0.8);"><?php echo nl2br(sanitize($reservation['special_requests'])); ?></p>
                </div>
                <?php endif; ?>
                
                <!-- Payout Status for Owners -->
                <?php if ($_SESSION['user_id'] == $reservation['owner_id'] && $reservation['status'] == 'completed'): ?>
                <div style="margin-top: 20px; padding: 18px; background: rgba(255,255,255,0.04); border-radius: 16px; border: 1px solid rgba(255,255,255,0.1);">
                    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 10px;">
                        <span style="font-weight: 500; color: white;"><i class="fas fa-wallet"></i> Payout Status:</span>
                        <span class="status-badge" style="background: <?php 
                            echo $reservation['payout_status'] == 'paid' ? 'rgba(34,197,94,0.15); color:#4ade80' : 
                                ($reservation['payout_status'] == 'processing' ? 'rgba(59,130,246,0.15); color:#60a5fa' : 
                                'rgba(245,158,11,0.15); color:#fbbf24'); ?>">
                            <i class="fas <?php echo $reservation['payout_status'] == 'paid' ? 'fa-check-circle' : ($reservation['payout_status'] == 'processing' ? 'fa-spinner fa-pulse' : 'fa-clock'); ?>"></i>
                            <?php echo ucfirst(sanitize($reservation['payout_status'] ?? 'pending')); ?>
                        </span>
                    </div>
                    <?php if (!empty($reservation['payout_date'])): ?>
                        <div style="font-size: 12px; color: rgba(255,255,255,0.5); margin-top: 8px;">
                            <i class="far fa-calendar-check"></i> Paid on: <?php echo date('M d, Y', strtotime($reservation['payout_date'])); ?>
                        </div>
                    <?php endif; ?>
                </div>
                <?php endif; ?>
                
                <!-- Action Buttons -->
                <div class="action-buttons">
                    <?php if ($_SESSION['user_id'] == $reservation['owner_id']): ?>
                        <?php if ($reservation['status'] == 'pending'): ?>
                            <a href="update-reservation-status.php?id=<?php echo (int)$reservation_id; ?>&status=confirmed" class="btn btn-primary" onclick="return confirm('Confirm this booking?')"><i class="fas fa-check"></i> Confirm Booking</a>
                            <a href="process-refund.php?id=<?php echo (int)$reservation_id; ?>&action=cancel" class="btn btn-danger" onclick="return confirm('Are you sure you want to cancel this booking? This will refund the customer.')"><i class="fas fa-times"></i> Cancel Booking</a>
                        <?php endif; ?>
                        <?php if ($reservation['status'] == 'confirmed'): ?>
                            <a href="update-reservation-status.php?id=<?php echo (int)$reservation_id; ?>&status=active" class="btn btn-success"><i class="fas fa-car"></i> Mark as Active</a>
                        <?php endif; ?>
                        <?php if ($reservation['status'] == 'active'): ?>
                            <a href="update-reservation-status.php?id=<?php echo (int)$reservation_id; ?>&status=completed" class="btn btn-success" onclick="return confirm('Mark this booking as completed?')"><i class="fas fa-check-double"></i> Complete Booking</a>
                        <?php endif; ?>
                        <a href="mailto:<?php echo sanitize($reservation['email']); ?>" class="btn btn-secondary"><i class="fas fa-envelope"></i> Contact Customer</a>
                        
                    <?php else: ?>
                        <?php if ($can_cancel): ?>
                            <a href="process-refund.php?id=<?php echo (int)$reservation_id; ?>&action=cancel" class="btn btn-danger" onclick="return confirm('Are you sure you want to cancel this reservation? You will be refunded according to our cancellation policy.')"><i class="fas fa-times"></i> Cancel Reservation</a>
                        <?php endif; ?>
                        <a href="mailto:<?php echo sanitize($reservation['owner_email']); ?>" class="btn btn-secondary"><i class="fas fa-envelope"></i> Contact Owner</a>
                    <?php endif; ?>
                    <a href="dashboard.php" class="btn btn-secondary"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
                </div>
                
                <!-- Cancellation Policy Notice -->
                <div class="cancellation-policy">
                    <i class="fas fa-gavel"></i> <strong>Cancellation Policy:</strong> Free cancellation up to 1 hour before start time. Platform commission is non-refundable.
                </div>
            </div>
        </div>
    </div>
    
    <script>
        function formatDuration(startDate, endDate) {
            const start = new Date(startDate);
            const end = new Date(endDate);
            
            const diffMs = end - start;
            const totalMinutes = Math.round(diffMs / (1000 * 60));
            
            const minutesInHour = 60;
            const minutesInDay = 24 * minutesInHour;
            const minutesInMonth = 30 * minutesInDay;
            
            if (totalMinutes < minutesInHour) {
                return totalMinutes + ' ' + (totalMinutes === 1 ? 'minute' : 'minutes');
            } 
            else if (totalMinutes < minutesInDay) {
                const hours = Math.floor(totalMinutes / minutesInHour);
                const minutes = totalMinutes % minutesInHour;
                let text = hours + ' ' + (hours === 1 ? 'hour' : 'hours');
                if (minutes > 0) {
                    text += ' ' + minutes + ' ' + (minutes === 1 ? 'minute' : 'minutes');
                }
                return text;
            } 
            else if (totalMinutes < minutesInMonth) {
                const days = Math.floor(totalMinutes / minutesInDay);
                const remainingMinutes = totalMinutes % minutesInDay;
                let text = days + ' ' + (days === 1 ? 'day' : 'days');
                if (remainingMinutes > 0) {
                    const hours = Math.floor(remainingMinutes / minutesInHour);
                    if (hours > 0) {
                        text += ' ' + hours + ' ' + (hours === 1 ? 'hour' : 'hours');
                    }
                    const minutes = remainingMinutes % minutesInHour;
                    if (minutes > 0) {
                        text += ' ' + minutes + ' ' + (minutes === 1 ? 'minute' : 'minutes');
                    }
                }
                return text;
            } 
            else {
                const months = Math.floor(totalMinutes / minutesInMonth);
                const remainingMinutes = totalMinutes % minutesInMonth;
                const days = Math.floor(remainingMinutes / minutesInDay);
                let text = months + ' ' + (months === 1 ? 'month' : 'months');
                if (days > 0) {
                    text += ' ' + days + ' ' + (days === 1 ? 'day' : 'days');
                }
                return text;
            }
        }
    </script>
</body>
</html>