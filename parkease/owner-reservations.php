<?php
session_start();
require_once 'includes/user-access.php';
require_once 'config/database.php';

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

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$owner_id = (int)$_SESSION['user_id'];
$filter = isset($_GET['filter']) ? sanitize($_GET['filter']) : 'all';

// Whitelist allowed filters to prevent injection
$allowed_filters = ['all', 'pending', 'waiting', 'active', 'pending_checkout', 'completed', 'cancelled'];
if (!in_array($filter, $allowed_filters)) {
    $filter = 'all';
}

// Get all parking spaces owned by this user
$spaces_query = "SELECT id, name FROM parking_spaces WHERE owner_id = :owner_id AND is_active = 1";
try {
    $spaces_stmt = $db->prepare($spaces_query);
    $spaces_stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
    $spaces_stmt->execute();
    $spaces = $spaces_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Spaces query error: " . $e->getMessage());
    $spaces = [];
    $_SESSION['error'] = 'Unable to load parking spaces.';
}

// Build query based on filter
$query = "SELECT r.*, 
          ps.name as parking_name, 
          ps.address, 
          ps.city,
          u.first_name, 
          u.last_name, 
          u.email, 
          u.phone,
          u.id as user_id
          FROM reservations r
          JOIN parking_spaces ps ON r.parking_id = ps.id
          JOIN users u ON r.user_id = u.id
          WHERE ps.owner_id = :owner_id";

// Apply filters based on selection
switch ($filter) {
    case 'pending':
        $query .= " AND r.payment_status != 'paid' AND r.status = 'pending'";
        break;
    case 'waiting':
        $query .= " AND r.payment_status = 'paid' AND r.timer_status = 'pending'";
        break;
    case 'active':
        $query .= " AND r.timer_status = 'active'";
        break;
    case 'pending_checkout':
        $query .= " AND r.timer_status = 'pending_checkout'";
        break;
    case 'completed':
        $query .= " AND r.status = 'completed'";
        break;
    case 'cancelled':
        $query .= " AND r.status = 'cancelled'";
        break;
}

$query .= " ORDER BY 
            CASE 
                WHEN r.timer_status = 'pending_checkout' THEN 1
                WHEN r.timer_status = 'active' THEN 2
                ELSE 3
            END,
            r.created_at DESC";

try {
    $stmt = $db->prepare($query);
    $stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
    $stmt->execute();
    $bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Bookings query error: " . $e->getMessage());
    $bookings = [];
    $_SESSION['error'] = 'Unable to load bookings.';
}

// Get statistics for all statuses
$stats_query = "SELECT 
    COUNT(CASE WHEN r.payment_status != 'paid' AND r.status = 'pending' THEN 1 END) as pending_count,
    COUNT(CASE WHEN r.payment_status = 'paid' AND r.timer_status = 'pending' THEN 1 END) as waiting_count,
    COUNT(CASE WHEN r.timer_status = 'active' THEN 1 END) as active_count,
    COUNT(CASE WHEN r.timer_status = 'pending_checkout' THEN 1 END) as pending_checkout_count,
    COUNT(CASE WHEN r.status = 'completed' THEN 1 END) as completed_count,
    COUNT(CASE WHEN r.status = 'cancelled' THEN 1 END) as cancelled_count,
    COALESCE(SUM(CASE WHEN r.payment_status = 'paid' THEN r.total_amount ELSE 0 END), 0) as total_earned
    FROM reservations r
    JOIN parking_spaces ps ON r.parking_id = ps.id
    WHERE ps.owner_id = :owner_id";

try {
    $stats_stmt = $db->prepare($stats_query);
    $stats_stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
    $stats_stmt->execute();
    $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Stats query error: " . $e->getMessage());
    $stats = [
        'pending_count' => 0,
        'waiting_count' => 0,
        'active_count' => 0,
        'pending_checkout_count' => 0,
        'completed_count' => 0,
        'cancelled_count' => 0,
        'total_earned' => 0
    ];
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="robots" content="noindex, nofollow">
    <title>Manage Bookings - SpaceNode Owner Dashboard</title>
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
            max-width: 1400px;
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
            flex-wrap: wrap;
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
        
        .btn-glass-primary {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            box-shadow: 0 4px 15px rgba(79,110,247,0.3);
        }
        
        .btn-glass-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79,110,247,0.4);
        }
        
        .btn-glass-success {
            background: linear-gradient(135deg, #10B981, #059669);
            color: white;
            box-shadow: 0 4px 15px rgba(16,185,129,0.3);
        }
        
        /* Glassmorphism Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(7, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 20px;
            padding: 20px;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
            position: relative;
            overflow: hidden;
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
        
        .stat-card:nth-child(1) { animation-delay: 0.02s; }
        .stat-card:nth-child(2) { animation-delay: 0.04s; }
        .stat-card:nth-child(3) { animation-delay: 0.06s; }
        .stat-card:nth-child(4) { animation-delay: 0.08s; }
        .stat-card:nth-child(5) { animation-delay: 0.1s; }
        .stat-card:nth-child(6) { animation-delay: 0.12s; }
        .stat-card:nth-child(7) { animation-delay: 0.14s; }
        
        .stat-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: transparent;
        }
        
        .stat-card.pending::before { background: #fbbf24; }
        .stat-card.waiting::before { background: #a78bfa; }
        .stat-card.active::before { background: #60a5fa; }
        .stat-card.pending-checkout::before { background: #fb923c; }
        .stat-card.completed::before { background: #9ca3af; }
        .stat-card.cancelled::before { background: #f87171; }
        .stat-card.earnings::before { background: #a5b4fc; }
        
        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.3);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        .stat-card h3 {
            font-size: 13px;
            font-weight: 500;
            color: rgba(255,255,255,0.7);
            margin-bottom: 10px;
        }
        
        .stat-card.earnings h3 {
            color: white;
        }
        
        .stat-number {
            font-family: 'Outfit', sans-serif;
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Glassmorphism Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 12px;
            margin-bottom: 32px;
            flex-wrap: wrap;
        }
        
        .filter-tab {
            padding: 10px 24px;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 50px;
            color: rgba(255,255,255,0.7);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .filter-tab:hover {
            background: rgba(255,255,255,0.12);
            transform: translateY(-2px);
        }
        
        .filter-tab.active {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            border-color: transparent;
            box-shadow: 0 4px 15px rgba(79,110,247,0.3);
        }
        
        /* Glassmorphism Table Container */
        .bookings-table-container {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 24px;
            overflow-x: auto;
            transition: all 0.3s ease;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 1100px;
        }
        
        th {
            text-align: left;
            padding: 16px;
            background: rgba(255,255,255,0.04);
            color: rgba(255,255,255,0.7);
            font-weight: 600;
            font-size: 13px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        td {
            padding: 16px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            font-size: 14px;
            color: rgba(255,255,255,0.9);
        }
        
        tr:hover td {
            background: rgba(255,255,255,0.04);
        }
        
        .booking-ref {
            font-family: monospace;
            font-weight: 600;
            color: #a5b4fc;
        }
        
        .customer-info {
            display: flex;
            flex-direction: column;
        }
        
        .customer-name {
            font-weight: 600;
            color: white;
        }
        
        .customer-email {
            font-size: 12px;
            color: rgba(255,255,255,0.6);
        }
        
        /* Glassmorphism Status Badges */
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }
        
        .status-pending { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.2); }
        .status-waiting { background: rgba(139,92,246,0.15); color: #a78bfa; border: 1px solid rgba(139,92,246,0.2); }
        .status-active { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.2); }
        .status-pending-checkout { background: rgba(249,115,22,0.15); color: #fb923c; border: 1px solid rgba(249,115,22,0.2); }
        .status-completed { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.2); }
        .status-cancelled { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.2); }
        
        /* Payment Badges */
        .payment-badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 50px;
            font-size: 11px;
            font-weight: 600;
        }
        
        .payment-paid { background: rgba(34,197,94,0.15); color: #4ade80; border: 1px solid rgba(34,197,94,0.2); }
        .payment-pending { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.2); }
        .payment-refunded { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.2); }
        
        /* Timer Info */
        .timer-info {
            font-size: 11px;
            margin-top: 5px;
            color: #60a5fa;
        }
        
        .overstay-info {
            font-size: 11px;
            margin-top: 5px;
            color: #f87171;
            font-weight: 600;
        }
        
        /* Glassmorphism Action Buttons */
        .action-buttons {
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        
        .btn {
            padding: 7px 14px;
            border: none;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            cursor: pointer;
            text-decoration: none;
            display: inline-flex;
            align-items: center;
            gap: 6px;
            transition: all 0.3s ease;
            font-family: 'Outfit', sans-serif;
        }
        
        .btn-pin {
            background: rgba(139,92,246,0.15);
            color: #a78bfa;
            border: 1px solid rgba(139,92,246,0.3);
        }
        
        .btn-pin:hover {
            background: rgba(139,92,246,0.25);
            transform: translateY(-2px);
        }
        
        .btn-view {
            background: rgba(79,110,247,0.15);
            color: #a5b4fc;
            border: 1px solid rgba(79,110,247,0.3);
        }
        
        .btn-view:hover {
            background: rgba(79,110,247,0.25);
            transform: translateY(-2px);
        }
        
        .btn-active {
            background: rgba(59,130,246,0.15);
            color: #60a5fa;
            border: 1px solid rgba(59,130,246,0.3);
        }
        
        .btn-active:hover {
            background: rgba(59,130,246,0.25);
            transform: translateY(-2px);
        }
        
        .btn-checkout {
            background: rgba(249,115,22,0.15);
            color: #fb923c;
            border: 1px solid rgba(249,115,22,0.3);
        }
        
        .btn-checkout:hover {
            background: rgba(249,115,22,0.25);
            transform: translateY(-2px);
        }
        
        /* Glassmorphism Empty State */
        .empty-state {
            text-align: center;
            padding: 60px 40px;
            color: rgba(255,255,255,0.6);
        }
        
        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.4;
            stroke: rgba(255,255,255,0.5);
        }
        
        /* Glassmorphism Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 20px;
            margin-bottom: 24px;
            backdrop-filter: blur(20px);
            animation: slideDown 0.4s ease;
            display: flex;
            align-items: center;
            gap: 12px;
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
        
        .alert-error {
            background: rgba(239,68,68,0.15);
            color: #f87171;
            border: 1px solid rgba(239,68,68,0.3);
        }
        
        .alert-success {
            background: rgba(34,197,94,0.15);
            color: #4ade80;
            border: 1px solid rgba(34,197,94,0.3);
        }
        
        /* Amount Highlight */
        .amount-highlight {
            font-family: 'Outfit', sans-serif;
            font-weight: 700;
            color: #a5b4fc;
        }
        
        .parking-name {
            font-weight: 600;
            color: white;
        }
        
        .parking-city {
            font-size: 12px;
            color: rgba(255,255,255,0.5);
            margin-top: 2px;
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
            height: 6px;
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
        
        /* Responsive Design */
        @media (max-width: 1200px) {
            .stats-grid {
                grid-template-columns: repeat(4, 1fr);
            }
        }
        
        @media (max-width: 900px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 20px 15px;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .stat-number {
                font-size: 28px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .filter-tabs {
                gap: 8px;
            }
            
            .filter-tab {
                padding: 8px 16px;
                font-size: 12px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-calendar-alt"></i> Manage Bookings</h1>
            <div class="header-buttons">
                <a href="owner/enter-pin.php" class="btn-glass btn-glass-primary">
                    <i class="fas fa-key"></i> Enter PIN
                </a>
                <a href="owner/active-sessions.php" class="btn-glass btn-glass-success">
                    <i class="fas fa-clock"></i> Active Sessions
                </a>
                <a href="dashboard.php" class="btn-glass">
                    <i class="fas fa-arrow-left"></i> Dashboard
                </a>
            </div>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo sanitize($_SESSION['success']); unset($_SESSION['success']); ?>
            </div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo sanitize($_SESSION['error']); unset($_SESSION['error']); ?>
            </div>
        <?php endif; ?>
        
        <!-- Glassmorphism Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card pending">
                <h3><i class="fas fa-hourglass-half"></i> Pending Payment</h3>
                <div class="stat-number"><?php echo (int)($stats['pending_count'] ?? 0); ?></div>
            </div>
            <div class="stat-card waiting">
                <h3><i class="fas fa-clock"></i> Waiting for PIN</h3>
                <div class="stat-number"><?php echo (int)($stats['waiting_count'] ?? 0); ?></div>
            </div>
            <div class="stat-card active">
                <h3><i class="fas fa-play-circle"></i> Active</h3>
                <div class="stat-number"><?php echo (int)($stats['active_count'] ?? 0); ?></div>
            </div>
            <div class="stat-card pending-checkout">
                <h3><i class="fas fa-hourglass-end"></i> Pending Checkout</h3>
                <div class="stat-number"><?php echo (int)($stats['pending_checkout_count'] ?? 0); ?></div>
            </div>
            <div class="stat-card completed">
                <h3><i class="fas fa-check-circle"></i> Completed</h3>
                <div class="stat-number"><?php echo (int)($stats['completed_count'] ?? 0); ?></div>
            </div>
            <div class="stat-card cancelled">
                <h3><i class="fas fa-times-circle"></i> Cancelled</h3>
                <div class="stat-number"><?php echo (int)($stats['cancelled_count'] ?? 0); ?></div>
            </div>
            <div class="stat-card earnings">
                <h3><i class="fas fa-chart-line"></i> Total Earned</h3>
                <div class="stat-number">₦<?php echo number_format($stats['total_earned'] ?? 0, 2); ?></div>
            </div>
        </div>
        
        <!-- Glassmorphism Filter Tabs -->
        <div class="filter-tabs">
            <a href="?filter=all" class="filter-tab <?php echo $filter == 'all' ? 'active' : ''; ?>"><i class="fas fa-list"></i> All</a>
            <a href="?filter=pending" class="filter-tab <?php echo $filter == 'pending' ? 'active' : ''; ?>"><i class="fas fa-hourglass-half"></i> Pending Payment</a>
            <a href="?filter=waiting" class="filter-tab <?php echo $filter == 'waiting' ? 'active' : ''; ?>"><i class="fas fa-clock"></i> Waiting for PIN</a>
            <a href="?filter=active" class="filter-tab <?php echo $filter == 'active' ? 'active' : ''; ?>"><i class="fas fa-play-circle"></i> Active</a>
            <a href="?filter=pending_checkout" class="filter-tab <?php echo $filter == 'pending_checkout' ? 'active' : ''; ?>"><i class="fas fa-hourglass-end"></i> Pending Checkout</a>
            <a href="?filter=completed" class="filter-tab <?php echo $filter == 'completed' ? 'active' : ''; ?>"><i class="fas fa-check-circle"></i> Completed</a>
            <a href="?filter=cancelled" class="filter-tab <?php echo $filter == 'cancelled' ? 'active' : ''; ?>"><i class="fas fa-times-circle"></i> Cancelled</a>
        </div>
        
        <!-- Glassmorphism Bookings Table -->
        <div class="bookings-table-container">
            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <i class="fas fa-calendar-times fa-4x" style="color: rgba(255,255,255,0.4); margin-bottom: 20px;"></i>
                    <p>No bookings found</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th><i class="fas fa-ticket-alt"></i> Booking Ref</th>
                            <th><i class="fas fa-user"></i> Customer</th>
                            <th><i class="fas fa-parking"></i> Parking Space</th>
                            <th><i class="fas fa-calendar"></i> Schedule</th>
                            <th><i class="fas fa-money-bill-wave"></i> Amount</th>
                            <th><i class="fas fa-chart-simple"></i> Status</th>
                            <th><i class="fas fa-credit-card"></i> Payment</th>
                            <th><i class="fas fa-cog"></i> Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): 
                            $display_status = $booking['status'];
                            $status_class = $booking['status'];
                            
                            if ($booking['payment_status'] == 'paid' && $booking['timer_status'] == 'pending' && $booking['status'] == 'pending') {
                                $display_status = 'waiting';
                                $status_class = 'waiting';
                            } elseif ($booking['timer_status'] == 'active') {
                                $display_status = 'active';
                                $status_class = 'active';
                            } elseif ($booking['timer_status'] == 'pending_checkout') {
                                $display_status = 'pending_checkout';
                                $status_class = 'pending-checkout';
                            }
                        ?>
                        <tr>
                            <td>
                                <span class="booking-ref"><i class="fas fa-hashtag"></i> <?php echo sanitize($booking['booking_reference'] ?? 'N/A'); ?></span>
                            </td>
                            <td>
                                <div class="customer-info">
                                    <span class="customer-name"><i class="fas fa-user-circle"></i> <?php echo sanitize(($booking['first_name'] ?? '') . ' ' . ($booking['last_name'] ?? '')); ?></span>
                                    <span class="customer-email"><i class="fas fa-envelope"></i> <?php echo sanitize($booking['email'] ?? ''); ?></span>
                                    <?php if (!empty($booking['phone'])): ?>
                                        <span class="customer-email"><i class="fas fa-phone"></i> <?php echo sanitize($booking['phone']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div class="parking-name"><i class="fas fa-building"></i> <?php echo sanitize($booking['parking_name'] ?? 'N/A'); ?></div>
                                <div class="parking-city"><i class="fas fa-map-marker-alt"></i> <?php echo sanitize($booking['city'] ?? ''); ?></div>
                            </td>
                            <td>
                                <div><i class="fas fa-play-circle"></i> <strong>From:</strong> <?php echo date('M d, h:i A', strtotime($booking['start_date'])); ?></div>
                                <div><i class="fas fa-stop-circle"></i> <strong>To:</strong> <?php echo date('M d, h:i A', strtotime($booking['end_date'])); ?></div>
                                <div style="font-size: 12px; color: rgba(255,255,255,0.5);">
                                    <i class="fas fa-hourglass-half"></i> Duration: <?php echo formatDuration($booking['start_date'], $booking['end_date']); ?>
                                </div>
                                
                                <?php if ($booking['timer_status'] == 'active' && !empty($booking['actual_start_time'])): ?>
                                    <div class="timer-info">
                                        <i class="fas fa-play"></i> Started: <?php echo date('h:i A', strtotime($booking['actual_start_time'])); ?><br>
                                        <i class="fas fa-hourglass-end"></i> Ends: <?php echo date('h:i A', strtotime($booking['actual_end_time'])); ?>
                                    </div>
                                <?php elseif ($booking['timer_status'] == 'pending_checkout' && !empty($booking['actual_end_time'])): ?>
                                    <div class="timer-info" style="color: #fb923c;">
                                        <i class="fas fa-stop"></i> Ended: <?php echo date('h:i A', strtotime($booking['actual_end_time'])); ?>
                                    </div>
                                    <?php if (!empty($booking['overstay_charge']) && $booking['overstay_charge'] > 0): ?>
                                        <div class="overstay-info">
                                            <i class="fas fa-exclamation-triangle"></i> Overstay: ₦<?php echo number_format($booking['overstay_charge'], 2); ?>
                                        </div>
                                    <?php endif; ?>
                                <?php endif; ?>
                            </td>
                            <td>
                                <strong class="amount-highlight">₦<?php echo number_format($booking['total_amount'] ?? 0, 2); ?></strong>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $status_class; ?>">
                                    <?php 
                                    if ($display_status == 'waiting') {
                                        echo '<i class="fas fa-clock"></i> Waiting for PIN';
                                    } elseif ($display_status == 'pending_checkout') {
                                        echo '<i class="fas fa-hourglass-end"></i> Pending Checkout';
                                    } else {
                                        echo '<i class="fas fa-circle"></i> ' . ucfirst($display_status); 
                                    }
                                    ?>
                                </span>
                            </td>
                            <td>
                                <span class="payment-badge payment-<?php echo $booking['payment_status']; ?>">
                                    <i class="fas <?php echo $booking['payment_status'] == 'paid' ? 'fa-check-circle' : ($booking['payment_status'] == 'pending' ? 'fa-clock' : 'fa-undo'); ?>"></i>
                                    <?php echo ucfirst($booking['payment_status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <?php if ($display_status == 'waiting'): ?>
                                        <a href="owner/enter-pin.php?reservation=<?php echo (int)$booking['id']; ?>" 
                                           class="btn btn-pin"><i class="fas fa-key"></i> Enter PIN</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($booking['timer_status'] == 'active'): ?>
                                        <a href="owner/active-sessions.php" class="btn btn-active"><i class="fas fa-clock"></i> View Timer</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($booking['timer_status'] == 'pending_checkout'): ?>
                                        <a href="owner/confirm-checkout.php?id=<?php echo (int)$booking['id']; ?>" 
                                           class="btn btn-checkout"
                                           onclick="return confirm('Confirm that customer has left the parking space?')">
                                            <i class="fas fa-check-circle"></i> Confirm Checkout
                                        </a>
                                    <?php endif; ?>
                                    
                                    <a href="reservation-details.php?id=<?php echo (int)$booking['id']; ?>" class="btn btn-view">
                                        <i class="fas fa-eye"></i> View
                                    </a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
    let refreshInterval = setInterval(function() {
        location.reload();
    }, 30000);
    
    window.addEventListener('beforeunload', function() {
        if (refreshInterval) {
            clearInterval(refreshInterval);
        }
    });
    
    <?php foreach ($bookings as $booking): ?>
        <?php if ($booking['timer_status'] == 'active'): ?>
            (function() {
                const bookingId = <?php echo (int)$booking['id']; ?>;
                let checkInterval = setInterval(function() {
                    fetch('api/check-status.php?id=' + bookingId)
                        .then(response => response.json())
                        .then(data => {
                            if (data.completed) {
                                location.reload();
                            }
                        })
                        .catch(error => console.error('Status check failed:', error));
                }, 5000);
            })();
        <?php endif; ?>
    <?php endforeach; ?>
    </script>
    <?php include __DIR__ . '/chat/widget.php'; ?>
</body>
</html>