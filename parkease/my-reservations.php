<?php
session_start();
require_once 'includes/user-access.php';
redirectOwnersFromPublicPages();
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Function to get image URL safely
function getImageUrl($images_json, $default = 'img/parking-placeholder.jpg') {
    if (!empty($images_json)) {
        $images = json_decode($images_json, true);
        if (!empty($images) && isset($images[0])) {
            $image_path = 'uploads/parking/' . $images[0];
            return file_exists($image_path) ? $image_path : $default;
        }
    }
    return $default;
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$user_id = (int)$_SESSION['user_id'];

// Get all reservations with proper joins
$query = "SELECT r.*, 
          ps.name as parking_name, 
          ps.address, 
          ps.city, 
          ps.images,
          u.first_name as user_first_name, 
          u.last_name as user_last_name,
          owner.first_name as owner_first_name, 
          owner.last_name as owner_last_name,
          owner.email as owner_email,
          owner.phone as owner_phone
          FROM reservations r
          INNER JOIN parking_spaces ps ON r.parking_id = ps.id
          INNER JOIN users u ON r.user_id = u.id
          INNER JOIN users owner ON r.owner_id = owner.id
          WHERE r.user_id = :user_id
          ORDER BY r.created_at DESC";

try {
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
    $stmt->execute();
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("My reservations query error: " . $e->getMessage());
    $reservations = [];
    $_SESSION['error'] = 'Unable to load reservations. Please try again later.';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="robots" content="noindex, nofollow">
    <title>My Reservations - SpaceNode</title>
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
            margin-bottom: 40px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(28px, 5vw, 36px);
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }
        
        .back-link {
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
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,0.15);
            transform: translateY(-2px);
            border-color: rgba(255,255,255,0.3);
        }
        
        /* Glassmorphism Reservations Grid */
        .reservations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(360px, 1fr));
            gap: 24px;
        }
        
        .reservation-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            overflow: hidden;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }
        
        .reservation-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.3);
            box-shadow: 0 20px 48px 0 rgba(0, 0, 0, 0.3);
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
        
        .reservation-card:nth-child(1) { animation-delay: 0.05s; }
        .reservation-card:nth-child(2) { animation-delay: 0.1s; }
        .reservation-card:nth-child(3) { animation-delay: 0.15s; }
        .reservation-card:nth-child(4) { animation-delay: 0.2s; }
        .reservation-card:nth-child(5) { animation-delay: 0.25s; }
        .reservation-card:nth-child(6) { animation-delay: 0.3s; }
        
        .reservation-image {
            height: 180px;
            overflow: hidden;
            position: relative;
            background: linear-gradient(135deg, #1a1a2e, #16213e);
        }
        
        .reservation-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
            transition: transform 0.5s ease;
        }
        
        .reservation-card:hover .reservation-image img {
            transform: scale(1.05);
        }
        
        .status-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            backdrop-filter: blur(10px);
            z-index: 2;
        }
        
        .status-pending { background: rgba(245,158,11,0.2); color: #fbbf24; border: 1px solid rgba(245,158,11,0.3); }
        .status-confirmed { background: rgba(34,197,94,0.2); color: #4ade80; border: 1px solid rgba(34,197,94,0.3); }
        .status-active { background: rgba(59,130,246,0.2); color: #60a5fa; border: 1px solid rgba(59,130,246,0.3); }
        .status-completed { background: rgba(107,114,128,0.2); color: #9ca3af; border: 1px solid rgba(107,114,128,0.3); }
        .status-cancelled { background: rgba(239,68,68,0.2); color: #f87171; border: 1px solid rgba(239,68,68,0.3); }
        
        .reservation-content {
            padding: 20px;
        }
        
        .parking-name {
            font-family: 'Outfit', sans-serif;
            font-size: 18px;
            font-weight: 700;
            color: white;
            margin-bottom: 6px;
            letter-spacing: -0.3px;
        }
        
        .parking-location {
            display: flex;
            align-items: center;
            gap: 6px;
            color: rgba(255,255,255,0.6);
            font-size: 13px;
            margin-bottom: 16px;
        }
        
        .booking-ref {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(5px);
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-family: monospace;
            color: rgba(255,255,255,0.7);
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .payment-badge {
            display: inline-block;
            padding: 5px 12px;
            border-radius: 30px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .payment-pending { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.2); }
        .payment-paid { background: rgba(34,197,94,0.15); color: #4ade80; border: 1px solid rgba(34,197,94,0.2); }
        .payment-refunded { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.2); }
        
        .dates {
            display: flex;
            justify-content: space-between;
            margin: 16px 0;
            padding: 14px 0;
            border-top: 1px solid rgba(255,255,255,0.1);
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .date-item {
            text-align: center;
            flex: 1;
        }
        
        .date-label {
            font-size: 10px;
            color: rgba(255,255,255,0.5);
            margin-bottom: 6px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            font-weight: 600;
        }
        
        .date-value {
            font-size: 13px;
            font-weight: 600;
            color: white;
        }
        
        .amount {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            font-weight: 800;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 12px 0;
        }
        
        .amount::before {
            content: '₦';
            font-size: 16px;
            margin-right: 2px;
        }
        
        /* Glassmorphism Timer Display */
        .timer-display {
            background: rgba(79,110,247,0.1);
            backdrop-filter: blur(10px);
            padding: 12px;
            border-radius: 16px;
            margin: 12px 0;
            text-align: center;
            border: 1px solid rgba(79,110,247,0.2);
        }
        
        .timer-label {
            font-size: 12px;
            color: #a5b4fc;
            font-weight: 600;
            margin-bottom: 5px;
        }
        
        .timer-value {
            font-family: 'Outfit', sans-serif;
            font-size: 28px;
            font-weight: 800;
            color: #a5b4fc;
        }
        
        /* Pending Checkout Box */
        .pending-checkout-box {
            background: rgba(245,158,11,0.1);
            backdrop-filter: blur(10px);
            padding: 12px;
            border-radius: 16px;
            margin: 12px 0;
            text-align: center;
            border: 1px solid rgba(245,158,11,0.2);
        }
        
        .pending-checkout-title {
            color: #fbbf24;
            font-weight: 600;
            font-size: 14px;
        }
        
        .overstay-charge {
            color: #f87171;
            font-size: 13px;
            margin-top: 8px;
            font-weight: 500;
        }
        
        /* Glassmorphism View Button */
        .view-btn {
            display: block;
            text-align: center;
            padding: 12px;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-size: 14px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
            margin-top: 10px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(79,110,247,0.3);
        }
        
        .view-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .view-btn:hover::before {
            left: 100%;
        }
        
        .view-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79,110,247,0.4);
        }
        
        /* Empty State - Glassmorphism */
        .empty-state {
            text-align: center;
            padding: 80px 40px;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 28px;
            grid-column: 1 / -1;
            animation: fadeInUp 0.5s ease forwards;
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
            margin-bottom: 25px;
        }
        
        .find-parking-btn {
            display: inline-block;
            padding: 12px 30px;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
            box-shadow: 0 4px 15px rgba(79,110,247,0.3);
        }
        
        .find-parking-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79,110,247,0.4);
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 20px 15px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .reservations-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .reservation-card {
                max-width: 100%;
            }
            
            .dates {
                flex-direction: column;
                gap: 12px;
            }
            
            .date-item {
                text-align: left;
            }
        }
        
        @media (max-width: 480px) {
            .reservation-content {
                padding: 16px;
            }
            
            .parking-name {
                font-size: 16px;
            }
            
            .amount {
                font-size: 20px;
            }
            
            .timer-value {
                font-size: 22px;
            }
            
            .empty-state {
                padding: 50px 20px;
            }
            
            .empty-state h3 {
                font-size: 18px;
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
            <h1>My Reservations</h1>
            <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        </div>
        
        <?php if (empty($reservations)): ?>
            <div class="empty-state">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <h3>No reservations yet</h3>
                <p>Start by finding a parking space that suits your needs.</p>
                <a href="all-spaces.php" class="find-parking-btn">Find Parking →</a>
            </div>
        <?php else: ?>
            <div class="reservations-grid">
                <?php foreach ($reservations as $res): 
                    $image = getImageUrl($res['images'] ?? '');
                    $start_date = new DateTime($res['start_date']);
                    $end_date = new DateTime($res['end_date']);
                    $is_active = ($res['timer_status'] == 'active');
                    $is_pending_checkout = ($res['timer_status'] == 'pending_checkout');
                ?>
                <div class="reservation-card">
                    <div class="reservation-image">
                        <img src="<?php echo sanitize($image); ?>" 
                             alt="<?php echo sanitize($res['parking_name']); ?>"
                             onerror="this.src='img/parking-placeholder.jpg'">
                        <span class="status-badge status-<?php echo sanitize($res['status']); ?>">
                            <?php 
                            if ($is_pending_checkout) {
                                echo 'PENDING CHECKOUT';
                            } else {
                                echo ucfirst(sanitize($res['status'])); 
                            }
                            ?>
                        </span>
                    </div>
                    
                    <div class="reservation-content">
                        <h3 class="parking-name"><?php echo sanitize($res['parking_name']); ?></h3>
                        
                        <div class="parking-location">
                            <i class="fas fa-map-marker-alt" style="font-size: 12px;"></i>
                            <?php echo sanitize($res['city'] ?: 'Location not specified'); ?>
                        </div>
                        
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 12px; flex-wrap: wrap; gap: 8px;">
                            <span class="booking-ref"><?php echo sanitize($res['booking_reference'] ?? 'N/A'); ?></span>
                            <span class="payment-badge payment-<?php echo sanitize($res['payment_status']); ?>">
                                <?php echo ucfirst(sanitize($res['payment_status'])); ?>
                            </span>
                        </div>
                        
                        <div class="dates">
                            <div class="date-item">
                                <div class="date-label">FROM</div>
                                <div class="date-value"><?php echo $start_date->format('M d, h:i A'); ?></div>
                            </div>
                            <div class="date-item">
                                <div class="date-label">TO</div>
                                <div class="date-value"><?php echo $end_date->format('M d, h:i A'); ?></div>
                            </div>
                        </div>
                        
                        <?php if ($is_active && !empty($res['actual_end_time'])): ?>
                            <?php 
                            $actual_end = new DateTime($res['actual_end_time']);
                            $remaining_seconds = $actual_end->getTimestamp() - time();
                            ?>
                            <div class="timer-display">
                                <div class="timer-label">
                                    <i class="fas fa-hourglass-half"></i> Time Remaining
                                </div>
                                <div class="timer-value" id="parker-timer-<?php echo (int)$res['id']; ?>" 
                                     data-end-time="<?php echo $actual_end->getTimestamp(); ?>">
                                    <?php 
                                    if ($remaining_seconds > 0) {
                                        $minutes = floor($remaining_seconds / 60);
                                        $seconds = $remaining_seconds % 60;
                                        echo $minutes . 'm ' . $seconds . 's';
                                    } else {
                                        echo '0m 0s';
                                    }
                                    ?>
                                </div>
                            </div>
                        <?php elseif ($is_pending_checkout): ?>
                            <div class="pending-checkout-box">
                                <div class="pending-checkout-title">
                                    <i class="fas fa-clock"></i> Your parking time has ended
                                </div>
                                <div style="font-size: 13px; color: rgba(255,255,255,0.6); margin-top: 5px;">
                                    Please proceed to exit. Owner will confirm checkout.
                                </div>
                                <?php if (!empty($res['overstay_charge']) && $res['overstay_charge'] > 0): ?>
                                    <div class="overstay-charge">
                                        <i class="fas fa-exclamation-triangle"></i> Overstay charge: ₦<?php echo number_format($res['overstay_charge'], 2); ?>
                                    </div>
                                <?php endif; ?>
                            </div>
                        <?php endif; ?>
                        
                        <div class="amount"><?php echo number_format($res['total_amount'], 2); ?></div>
                        
                        <a href="reservation-details.php?id=<?php echo (int)$res['id']; ?>" class="view-btn">
                            View Details <i class="fas fa-arrow-right"></i>
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>

    <script>
    // Update timers for parker view
    function updateParkerTimers() {
        const now = Math.floor(Date.now() / 1000);
        let anyExpired = false;
        
        document.querySelectorAll('[data-end-time]').forEach(timer => {
            const endTime = parseInt(timer.dataset.endTime);
            const remaining = endTime - now;
            
            if (remaining <= 0) {
                timer.innerHTML = '0m 0s';
                timer.style.color = '#f87171';
                anyExpired = true;
            } else {
                const minutes = Math.floor(remaining / 60);
                const seconds = remaining % 60;
                timer.innerHTML = minutes + 'm ' + seconds + 's';
                
                if (remaining < 300) {
                    timer.style.color = '#f87171';
                } else if (remaining < 600) {
                    timer.style.color = '#fbbf24';
                } else {
                    timer.style.color = '#a5b4fc';
                }
            }
        });
        
        // If any timer expired, reload the page after 3 seconds
        if (anyExpired) {
            setTimeout(() => {
                location.reload();
            }, 3000);
        }
    }

    // Update parker timers every second if any active timers exist
    const activeTimers = document.querySelectorAll('[data-end-time]');
    if (activeTimers.length > 0) {
        updateParkerTimers();
        setInterval(updateParkerTimers, 1000);
    }
    
    // Auto-refresh every 30 seconds to update status
    let refreshInterval = setInterval(function() {
        location.reload();
    }, 30000);
    
    // Clean up interval when page unloads
    window.addEventListener('beforeunload', function() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
        }
    });
    </script>
    <?php include __DIR__ . '/chat/widget.php'; ?>
</body>
</html>