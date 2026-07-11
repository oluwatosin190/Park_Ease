<?php
session_start();
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Function to format currency
function formatCurrency($amount) {
    return '₦' . number_format($amount, 2);
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    error_log("Database connection failed in profile.php");
    $_SESSION['error'] = 'System error. Please try again later.';
    header('Location: dashboard.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$user_type = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : '';

// Get user details
$query = "SELECT id, first_name, last_name, email, phone, user_type, created_at, 
          is_active, bank_name, account_number, account_name, profile_image 
          FROM users WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

// Check if account is active
if ($user['is_active'] != 1) {
    session_destroy();
    $_SESSION['error'] = 'Your account has been deactivated. Please contact support.';
    header('Location: login.php');
    exit();
}

// Get statistics based on user type
try {
    if ($user_type == 'parker') {
        // Get parker statistics
        $stats_query = "SELECT 
                        COUNT(*) as total_bookings,
                        SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed_bookings,
                        SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active_bookings,
                        COALESCE(SUM(total_amount), 0) as total_spent
                        FROM reservations 
                        WHERE user_id = :user_id";
        $stats_stmt = $db->prepare($stats_query);
        $stats_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stats_stmt->execute();
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    } else {
        // Get owner statistics
        $stats_query = "SELECT 
                        COUNT(DISTINCT r.id) as total_bookings,
                        COUNT(DISTINCT ps.id) as total_spaces,
                        COALESCE(SUM(r.total_amount), 0) as total_earned,
                        COALESCE(SUM(r.commission_amount), 0) as total_commission
                        FROM parking_spaces ps
                        LEFT JOIN reservations r ON ps.id = r.parking_id AND r.payment_status = 'paid'
                        WHERE ps.owner_id = :user_id";
        $stats_stmt = $db->prepare($stats_query);
        $stats_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $stats_stmt->execute();
        $stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Stats query error in profile.php: " . $e->getMessage());
    $stats = [
        'total_bookings' => 0,
        'total_spent' => 0,
        'total_earned' => 0,
        'total_commission' => 0
    ];
}

// Get recent activity
try {
    if ($user_type == 'parker') {
        $activity_query = "SELECT r.*, ps.name as parking_name, ps.address, ps.city 
                           FROM reservations r
                           JOIN parking_spaces ps ON r.parking_id = ps.id
                           WHERE r.user_id = :user_id
                           ORDER BY r.created_at DESC
                           LIMIT 5";
        $activity_stmt = $db->prepare($activity_query);
        $activity_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $activity_stmt->execute();
        $activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
    } else {
        $activity_query = "SELECT r.*, u.first_name, u.last_name, u.email, ps.name as parking_name
                           FROM reservations r
                           JOIN parking_spaces ps ON r.parking_id = ps.id
                           JOIN users u ON r.user_id = u.id
                           WHERE ps.owner_id = :user_id
                           ORDER BY r.created_at DESC
                           LIMIT 5";
        $activity_stmt = $db->prepare($activity_query);
        $activity_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $activity_stmt->execute();
        $activities = $activity_stmt->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (PDOException $e) {
    error_log("Activity query error in profile.php: " . $e->getMessage());
    $activities = [];
}

// Calculate member since date
$member_since = date('F d, Y', strtotime($user['created_at']));
$initials = strtoupper(substr($user['first_name'] ?? '', 0, 1) . substr($user['last_name'] ?? '', 0, 1));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="robots" content="noindex, nofollow">
    <title>Profile - SpaceNode</title>
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
        
        /* Glassmorphism Alert Messages */
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
        
        /* Glassmorphism Profile Header */
        .profile-header {
            background: linear-gradient(135deg, rgba(79,110,247,0.2), rgba(124,58,237,0.2));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 32px;
            padding: 40px;
            margin-bottom: 30px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
        }
        
        .profile-header::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .profile-info {
            display: flex;
            align-items: center;
            gap: 30px;
            position: relative;
            z-index: 2;
            flex-wrap: wrap;
        }
        
        .profile-avatar {
            width: 120px;
            height: 120px;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            border-radius: 60px;
            display: flex;
            align-items: center;
            justify-content: center;
            font-family: 'Outfit', sans-serif;
            font-size: 48px;
            font-weight: 700;
            color: white;
            box-shadow: 0 10px 30px rgba(0,0,0,0.3);
            border: 3px solid rgba(255,255,255,0.3);
        }
        
        .profile-details h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 32px;
            font-weight: 700;
            color: white;
            margin-bottom: 8px;
        }
        
        .profile-details p {
            color: rgba(255,255,255,0.8);
            margin-bottom: 15px;
        }
        
        .profile-badge {
            display: inline-block;
            padding: 8px 18px;
            background: rgba(255,255,255,0.15);
            border-radius: 50px;
            font-size: 13px;
            font-weight: 600;
            backdrop-filter: blur(10px);
            color: #a5b4fc;
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        /* Glassmorphism Stats Grid */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        
        .stat-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 24px;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
        }
        
        .stat-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.3);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        .stat-card h3 {
            font-size: 14px;
            font-weight: 500;
            color: rgba(255,255,255,0.7);
            margin-bottom: 12px;
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
        
        .stat-number.small {
            font-size: 24px;
        }
        
        /* Glassmorphism Profile Content */
        .profile-content {
            display: grid;
            grid-template-columns: 1fr 2fr;
            gap: 30px;
        }
        
        /* Glassmorphism Info Card */
        .info-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 28px;
            transition: all 0.3s ease;
        }
        
        .info-card:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.25);
        }
        
        .info-card h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: white;
            margin-bottom: 24px;
            letter-spacing: -0.3px;
        }
        
        .info-item {
            margin-bottom: 20px;
        }
        
        .info-label {
            font-size: 12px;
            font-weight: 500;
            color: rgba(255,255,255,0.5);
            margin-bottom: 5px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        
        .info-value {
            font-size: 16px;
            font-weight: 500;
            color: white;
            word-break: break-word;
        }
        
        /* Glassmorphism Bank Details */
        .bank-details {
            margin-top: 24px;
            padding: 20px;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border-radius: 20px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .bank-details h4 {
            font-size: 16px;
            font-weight: 600;
            color: #a5b4fc;
            margin-bottom: 16px;
        }
        
        .bank-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
            flex-wrap: wrap;
            gap: 8px;
        }
        
        .bank-label {
            color: rgba(255,255,255,0.6);
        }
        
        .bank-value {
            font-weight: 500;
            color: white;
        }
        
        /* Glassmorphism Edit Button */
        .edit-btn {
            display: inline-block;
            margin-top: 24px;
            padding: 14px 24px;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            text-decoration: none;
            border-radius: 50px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            transition: all 0.3s ease;
            width: 100%;
            text-align: center;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(79,110,247,0.3);
        }
        
        .edit-btn::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .edit-btn:hover::before {
            left: 100%;
        }
        
        .edit-btn:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79,110,247,0.4);
        }
        
        /* Glassmorphism Activity List */
        .activity-list {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 28px;
            transition: all 0.3s ease;
        }
        
        .activity-list:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.25);
        }
        
        .activity-list h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: white;
            margin-bottom: 24px;
            letter-spacing: -0.3px;
        }
        
        .activity-item {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 16px 0;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            flex-wrap: wrap;
            gap: 10px;
            transition: all 0.3s ease;
        }
        
        .activity-item:hover {
            transform: translateX(5px);
        }
        
        .activity-item:last-child {
            border-bottom: none;
        }
        
        .activity-info h4 {
            font-size: 16px;
            font-weight: 600;
            color: white;
            margin-bottom: 5px;
        }
        
        .activity-info p {
            font-size: 13px;
            color: rgba(255,255,255,0.6);
        }
        
        .activity-status {
            padding: 5px 14px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            backdrop-filter: blur(5px);
        }
        
        .status-completed { background: rgba(34,197,94,0.15); color: #4ade80; border: 1px solid rgba(34,197,94,0.2); }
        .status-active { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.2); }
        .status-pending { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.2); }
        .status-cancelled { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.2); }
        
        /* Empty State */
        .empty-activity {
            text-align: center;
            padding: 50px 20px;
            color: rgba(255,255,255,0.5);
        }
        
        .empty-activity svg {
            width: 60px;
            height: 60px;
            margin-bottom: 15px;
            opacity: 0.4;
            stroke: rgba(255,255,255,0.5);
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 20px 15px;
            }
            
            .profile-info {
                flex-direction: column;
                text-align: center;
            }
            
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
            
            .profile-content {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .stat-number {
                font-size: 28px;
            }
            
            .profile-avatar {
                width: 100px;
                height: 100px;
                font-size: 40px;
            }
            
            .profile-details h2 {
                font-size: 26px;
            }
        }
        
        @media (max-width: 480px) {
            .stats-grid {
                grid-template-columns: 1fr;
            }
            
            .profile-header {
                padding: 25px;
            }
            
            .info-card, .activity-list {
                padding: 20px;
            }
            
            .stat-number {
                font-size: 24px;
            }
            
            .stat-number.small {
                font-size: 20px;
            }
            
            .activity-item {
                flex-direction: column;
                align-items: flex-start;
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
        
        .stat-card, .info-card, .activity-list {
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }
        
        .stat-card:nth-child(1) { animation-delay: 0.05s; }
        .stat-card:nth-child(2) { animation-delay: 0.1s; }
        .stat-card:nth-child(3) { animation-delay: 0.15s; }
        .stat-card:nth-child(4) { animation-delay: 0.2s; }
        
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
            <h1>My Profile</h1>
            <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo sanitize($_SESSION['success']); unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <?php if (isset($_SESSION['error'])): ?>
            <div class="alert alert-error"><?php echo sanitize($_SESSION['error']); unset($_SESSION['error']); ?></div>
        <?php endif; ?>
        
        <!-- Glassmorphism Profile Header -->
        <div class="profile-header">
            <div class="profile-info">
                <div class="profile-avatar">
                    <?php echo sanitize($initials ?: 'U'); ?>
                </div>
                <div class="profile-details">
                    <h2><?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?></h2>
                    <p><?php echo sanitize($user['email']); ?></p>
                    <span class="profile-badge">
                        <i class="fas fa-user-circle"></i> <?php echo ucfirst(sanitize($user['user_type'])); ?> Account
                    </span>
                </div>
            </div>
        </div>
        
        <!-- Glassmorphism Statistics -->
        <div class="stats-grid">
            <?php if ($user_type == 'parker'): ?>
            <div class="stat-card">
                <h3><i class="fas fa-calendar-check"></i> Total Bookings</h3>
                <div class="stat-number"><?php echo (int)($stats['total_bookings'] ?? 0); ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-check-circle"></i> Completed</h3>
                <div class="stat-number"><?php echo (int)($stats['completed_bookings'] ?? 0); ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-clock"></i> Active</h3>
                <div class="stat-number"><?php echo (int)($stats['active_bookings'] ?? 0); ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-money-bill-wave"></i> Total Spent</h3>
                <div class="stat-number small">₦<?php echo number_format($stats['total_spent'] ?? 0, 2); ?></div>
            </div>
            <?php else: ?>
            <div class="stat-card">
                <h3><i class="fas fa-parking"></i> Total Spaces</h3>
                <div class="stat-number"><?php echo (int)($stats['total_spaces'] ?? 0); ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-bookmark"></i> Total Bookings</h3>
                <div class="stat-number"><?php echo (int)($stats['total_bookings'] ?? 0); ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-chart-line"></i> Total Earned</h3>
                <div class="stat-number small">₦<?php echo number_format($stats['total_earned'] ?? 0, 2); ?></div>
            </div>
            <div class="stat-card">
                <h3><i class="fas fa-percent"></i> Commission</h3>
                <div class="stat-number small">₦<?php echo number_format($stats['total_commission'] ?? 0, 2); ?></div>
            </div>
            <?php endif; ?>
        </div>
        
        <!-- Glassmorphism Profile Content -->
        <div class="profile-content">
            <!-- Personal Information -->
            <div class="info-card">
                <h3><i class="fas fa-user"></i> Personal Information</h3>
                
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-user-tag"></i> Full Name</div>
                    <div class="info-value"><?php echo sanitize($user['first_name'] . ' ' . $user['last_name']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-envelope"></i> Email Address</div>
                    <div class="info-value"><?php echo sanitize($user['email']); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-phone"></i> Phone Number</div>
                    <div class="info-value"><?php echo sanitize($user['phone'] ?: 'Not provided'); ?></div>
                </div>
                
                <div class="info-item">
                    <div class="info-label"><i class="fas fa-calendar-alt"></i> Member Since</div>
                    <div class="info-value"><?php echo sanitize($member_since); ?></div>
                </div>
                
                <?php if ($user_type == 'owner' && !empty($user['bank_name'])): ?>
                <div class="bank-details">
                    <h4><i class="fas fa-university"></i> Bank Account Details</h4>
                    <div class="bank-row">
                        <span class="bank-label">Bank Name:</span>
                        <span class="bank-value"><?php echo sanitize($user['bank_name']); ?></span>
                    </div>
                    <div class="bank-row">
                        <span class="bank-label">Account Number:</span>
                        <span class="bank-value"><?php echo sanitize($user['account_number']); ?></span>
                    </div>
                    <div class="bank-row">
                        <span class="bank-label">Account Name:</span>
                        <span class="bank-value"><?php echo sanitize($user['account_name']); ?></span>
                    </div>
                </div>
                <?php endif; ?>
                
                <a href="settings.php" class="edit-btn"><i class="fas fa-edit"></i> Edit Profile</a>
            </div>
            
            <!-- Recent Activity -->
            <div class="activity-list">
                <h3><i class="fas fa-history"></i> Recent Activity</h3>
                
                <?php if (empty($activities)): ?>
                <div class="empty-activity">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <circle cx="12" cy="12" r="10"/>
                        <line x1="12" y1="8" x2="12" y2="12"/>
                        <line x1="12" y1="16" x2="12.01" y2="16"/>
                    </svg>
                    <p>No recent activity found.</p>
                </div>
                <?php else: ?>
                    <?php foreach ($activities as $activity): ?>
                    <div class="activity-item">
                        <div class="activity-info">
                            <h4>
                                <?php if ($user_type == 'parker'): ?>
                                    <?php echo sanitize($activity['parking_name'] ?? ''); ?>
                                <?php else: ?>
                                    Booking by <?php echo sanitize(($activity['first_name'] ?? '') . ' ' . ($activity['last_name'] ?? '')); ?>
                                <?php endif; ?>
                            </h4>
                            <p>
                                <i class="far fa-calendar-alt"></i> <?php echo date('M d, Y - h:i A', strtotime($activity['created_at'] ?? 'now')); ?>
                                <?php if ($user_type == 'parker' && !empty($activity['address'])): ?>
                                    - <i class="fas fa-map-marker-alt"></i> <?php echo sanitize($activity['address']); ?>
                                <?php endif; ?>
                            </p>
                        </div>
                        <span class="activity-status status-<?php echo $activity['status'] ?? 'pending'; ?>">
                            <?php echo ucfirst($activity['status'] ?? 'Pending'); ?>
                        </span>
                    </div>
                    <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </div>
    </div>
</body>
</html>