<?php
session_start();
require_once 'includes/user-access.php';
require_once 'config/database.php';
require_once 'includes/commission-functions.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();
$commission = new CommissionManager($db);

$owner_id = (int)$_SESSION['user_id'];

// Get owner balance with error handling
try {
    $balance = $commission->getOwnerBalance($owner_id);
} catch (Exception $e) {
    error_log("Error fetching owner balance: " . $e->getMessage());
    $balance = [
        'current_balance' => 0,
        'pending_balance' => 0,
        'total_earned' => 0,
        'total_commission' => 0
    ];
    $_SESSION['error'] = 'Unable to load balance information.';
}

// Get pending payouts
try {
    $pending_payouts = $commission->getPendingPayouts($owner_id);
} catch (Exception $e) {
    error_log("Error fetching pending payouts: " . $e->getMessage());
    $pending_payouts = [];
}

// Get transaction history
try {
    $transactions = $commission->getOwnerTransactions($owner_id, 50);
} catch (Exception $e) {
    error_log("Error fetching transactions: " . $e->getMessage());
    $transactions = [];
}

// Get withdrawal history
$withdrawals_query = "SELECT * FROM owner_payouts WHERE owner_id = :owner_id ORDER BY created_at DESC LIMIT 10";
try {
    $withdrawals_stmt = $db->prepare($withdrawals_query);
    $withdrawals_stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
    $withdrawals_stmt->execute();
    $withdrawals = $withdrawals_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Withdrawals query error: " . $e->getMessage());
    $withdrawals = [];
    $_SESSION['error'] = 'Unable to load withdrawal history.';
}

// Handle filter parameter
$filter = isset($_GET['filter']) ? sanitize($_GET['filter']) : 'all';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="robots" content="noindex, nofollow">
    <title>Earnings Dashboard - SpaceNode</title>
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
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #a5b4fc, #f0abfc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
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
        
        /* Glassmorphism Info Box */
        .info-box {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(165,180,252,0.3);
            border-radius: 24px;
            padding: 24px;
            margin-bottom: 28px;
            transition: all 0.3s ease;
        }
        
        .info-box:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(165,180,252,0.5);
        }
        
        .info-title {
            font-family: 'Outfit', sans-serif;
            font-size: 18px;
            font-weight: 600;
            color: #a5b4fc;
            margin-bottom: 16px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-list {
            list-style: none;
            color: rgba(255,255,255,0.8);
            font-size: 14px;
            display: grid;
            grid-template-columns: repeat(auto-fit, minmax(250px, 1fr));
            gap: 12px;
        }
        
        .info-list li {
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .info-list li i {
            color: #a5b4fc;
            width: 20px;
        }
        
        /* Glassmorphism Balance Cards */
        .balance-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 32px;
        }
        
        .balance-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 24px;
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
        
        .balance-card:nth-child(1) { animation-delay: 0.05s; }
        .balance-card:nth-child(2) { animation-delay: 0.1s; }
        .balance-card:nth-child(3) { animation-delay: 0.15s; }
        .balance-card:nth-child(4) { animation-delay: 0.2s; }
        
        .balance-card::before {
            content: '';
            position: absolute;
            top: 0;
            left: 0;
            width: 100%;
            height: 3px;
            background: linear-gradient(90deg, #4F6EF7, #a5b4fc, #c4b5fd);
        }
        
        .balance-card:hover {
            transform: translateY(-5px);
            background: rgba(255,255,255,0.1);
            border-color: rgba(255,255,255,0.3);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        .balance-card h3 {
            font-size: 14px;
            font-weight: 500;
            color: rgba(255,255,255,0.7);
            margin-bottom: 12px;
        }
        
        .balance-amount {
            font-family: 'Outfit', sans-serif;
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .balance-note {
            font-size: 12px;
            color: #4ade80;
            margin-top: 8px;
        }
        
        /* Glassmorphism Withdraw Section */
        .withdraw-section {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 28px;
            margin-bottom: 28px;
            transition: all 0.3s ease;
        }
        
        .withdraw-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .withdraw-header h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: white;
        }
        
        .available-badge {
            background: rgba(34,197,94,0.15);
            color: #4ade80;
            padding: 8px 18px;
            border-radius: 50px;
            font-weight: 600;
            border: 1px solid rgba(34,197,94,0.3);
            backdrop-filter: blur(10px);
        }
        
        .withdraw-form {
            display: flex;
            gap: 16px;
            align-items: flex-end;
            flex-wrap: wrap;
        }
        
        .form-group {
            flex: 1;
            min-width: 150px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-size: 13px;
            color: rgba(255,255,255,0.7);
        }
        
        .form-group input, .form-group select {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            color: white;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus, .form-group select:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.1);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.2);
        }
        
        .form-group input::placeholder {
            color: rgba(255,255,255,0.4);
        }
        
        .form-group select option {
            background: #1a1a2e;
            color: white;
        }
        
        .btn-withdraw {
            background: linear-gradient(135deg, #10B981, #059669);
            color: white;
            padding: 12px 30px;
            border: none;
            border-radius: 60px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Outfit', sans-serif;
            box-shadow: 0 4px 15px rgba(16,185,129,0.3);
        }
        
        .btn-withdraw:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(16,185,129,0.4);
        }
        
        .btn-withdraw:disabled {
            background: rgba(156,163,175,0.5);
            cursor: not-allowed;
        }
        
        .warning-text {
            color: #f87171;
            font-size: 13px;
            margin-top: 12px;
        }
        
        /* Glassmorphism Transactions Section */
        .transactions-section {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 28px;
            margin-bottom: 28px;
        }
        
        .section-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 24px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .section-header h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: white;
        }
        
        .filter-select {
            padding: 10px 18px;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-size: 13px;
            font-family: 'DM Sans', sans-serif;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .filter-select:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
        }
        
        .filter-select option {
            background: #1a1a2e;
            color: white;
        }
        
        .table-container {
            overflow-x: auto;
        }
        
        table {
            width: 100%;
            border-collapse: collapse;
            min-width: 800px;
        }
        
        th {
            text-align: left;
            padding: 14px;
            background: rgba(255,255,255,0.04);
            color: rgba(255,255,255,0.7);
            font-size: 13px;
            font-weight: 600;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        td {
            padding: 14px;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            font-size: 14px;
            color: rgba(255,255,255,0.9);
        }
        
        tr:hover td {
            background: rgba(255,255,255,0.04);
        }
        
        .booking-ref {
            font-family: monospace;
            color: #a5b4fc;
            font-weight: 600;
        }
        
        .amount-positive {
            color: #4ade80;
            font-weight: 600;
        }
        
        .amount-negative {
            color: #f87171;
        }
        
        .commission-amount {
            color: #fbbf24;
        }
        
        .status-badge {
            display: inline-flex;
            align-items: center;
            gap: 6px;
            padding: 4px 12px;
            border-radius: 50px;
            font-size: 12px;
            font-weight: 600;
            backdrop-filter: blur(10px);
        }
        
        .bg-green-100 { background: rgba(34,197,94,0.15); color: #4ade80; border: 1px solid rgba(34,197,94,0.2); }
        .bg-yellow-100 { background: rgba(245,158,11,0.15); color: #fbbf24; border: 1px solid rgba(245,158,11,0.2); }
        .bg-blue-100 { background: rgba(59,130,246,0.15); color: #60a5fa; border: 1px solid rgba(59,130,246,0.2); }
        .bg-red-100 { background: rgba(239,68,68,0.15); color: #f87171; border: 1px solid rgba(239,68,68,0.2); }
        .bg-gray-100 { background: rgba(107,114,128,0.15); color: #9ca3af; border: 1px solid rgba(107,114,128,0.2); }
        
        /* Glassmorphism Withdrawals Section */
        .withdrawals-section {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 28px;
        }
        
        .withdrawals-section h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: white;
            margin-bottom: 24px;
        }
        
        /* Glassmorphism Empty State */
        .empty-state {
            text-align: center;
            padding: 50px 20px;
            color: rgba(255,255,255,0.6);
        }
        
        .empty-state i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.4;
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
        @media (max-width: 1024px) {
            .balance-grid {
                grid-template-columns: repeat(2, 1fr);
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 20px 15px;
            }
            
            .balance-grid {
                grid-template-columns: repeat(2, 1fr);
                gap: 16px;
            }
            
            .withdraw-form {
                flex-direction: column;
                align-items: stretch;
            }
            
            .form-group {
                width: 100%;
            }
            
            .btn-withdraw {
                width: 100%;
            }
            
            .balance-amount {
                font-size: 28px;
            }
            
            .info-list {
                grid-template-columns: 1fr;
            }
        }
        
        @media (max-width: 480px) {
            .balance-grid {
                grid-template-columns: 1fr;
            }
            
            .withdraw-header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .section-header {
                flex-direction: column;
                align-items: flex-start;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1><i class="fas fa-chart-line"></i> Earnings Dashboard</h1>
            <a href="dashboard.php" class="btn-glass"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
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
        
        <!-- Glassmorphism Commission Info Box -->
        <div class="info-box">
            <div class="info-title">
                <i class="fas fa-percent"></i> Commission Information
            </div>
            <ul class="info-list">
                <li><i class="fas fa-tag"></i> Commission Rate: <strong>15%</strong> per booking</li>
                <li><i class="fas fa-arrow-down"></i> Minimum Commission: <strong>₦100</strong></li>
                <li><i class="fas fa-arrow-up"></i> Maximum Commission: <strong>₦50,000</strong> (capped)</li>
                <li><i class="fas fa-calendar-week"></i> Payout Frequency: <strong>Daily, Weekly, or Monthly</strong> (you choose)</li>
                <li><i class="fas fa-hand-holding-usd"></i> Minimum Payout: <strong>₦100</strong></li>
                <li><i class="fas fa-ban"></i> Cancellation Policy: Platform keeps commission if cancelled within 1 hour of start</li>
            </ul>
        </div>
        
        <!-- Glassmorphism Balance Cards -->
        <div class="balance-grid">
            <div class="balance-card">
                <h3><i class="fas fa-wallet"></i> Current Balance</h3>
                <div class="balance-amount">₦<?php echo number_format($balance['current_balance'] ?? 0, 2); ?></div>
                <div class="balance-note"><i class="fas fa-check-circle"></i> Available for withdrawal</div>
            </div>
            
            <div class="balance-card">
                <h3><i class="fas fa-clock"></i> Pending Balance</h3>
                <div class="balance-amount">₦<?php echo number_format($balance['pending_balance'] ?? 0, 2); ?></div>
                <div class="balance-note"><i class="fas fa-hourglass-half"></i> From active bookings</div>
            </div>
            
            <div class="balance-card">
                <h3><i class="fas fa-chart-line"></i> Total Earned</h3>
                <div class="balance-amount">₦<?php echo number_format($balance['total_earned'] ?? 0, 2); ?></div>
                <div class="balance-note"><i class="fas fa-trophy"></i> Lifetime earnings</div>
            </div>
            
            <div class="balance-card">
                <h3><i class="fas fa-percent"></i> Platform Commission</h3>
                <div class="balance-amount">₦<?php echo number_format($balance['total_commission'] ?? 0, 2); ?></div>
                <div class="balance-note"><i class="fas fa-building"></i> 15% on all bookings</div>
            </div>
        </div>
        
        <!-- Glassmorphism Withdrawal Section -->
        <div class="withdraw-section">
            <div class="withdraw-header">
                <h2><i class="fas fa-hand-holding-usd"></i> Withdraw Earnings</h2>
                <span class="available-badge"><i class="fas fa-money-bill-wave"></i> Available: ₦<?php echo number_format($balance['current_balance'] ?? 0, 2); ?></span>
            </div>
            
            <form action="process-withdrawal.php" method="POST" class="withdraw-form">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token'] ?? ''; ?>">
                
                <div class="form-group">
                    <label><i class="fas fa-coins"></i> Amount to Withdraw (₦)</label>
                    <input type="number" name="amount" min="100" max="<?php echo (int)($balance['current_balance'] ?? 0); ?>" step="100" required 
                           placeholder="Enter amount">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-calendar-alt"></i> Payout Frequency</label>
                    <select name="payout_frequency">
                        <option value="daily">Daily</option>
                        <option value="weekly">Weekly</option>
                        <option value="monthly">Monthly</option>
                    </select>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-university"></i> Bank Name</label>
                    <input type="text" name="bank_name" required maxlength="100" placeholder="e.g., GTBank">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-credit-card"></i> Account Number</label>
                    <input type="text" name="account_number" required maxlength="20" pattern="[0-9]+" title="Only numbers allowed" placeholder="10 digits">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Account Name</label>
                    <input type="text" name="account_name" required maxlength="100" placeholder="Name on account">
                </div>
                
                <button type="submit" class="btn-withdraw" <?php echo (($balance['current_balance'] ?? 0) < 100) ? 'disabled' : ''; ?>>
                    <i class="fas fa-paper-plane"></i> Request Withdrawal
                </button>
            </form>
            
            <?php if (($balance['current_balance'] ?? 0) < 100): ?>
                <div class="warning-text">
                    <i class="fas fa-exclamation-triangle"></i> Minimum withdrawal amount is ₦100
                </div>
            <?php endif; ?>
        </div>
        
        <!-- Glassmorphism Recent Transactions -->
        <div class="transactions-section">
            <div class="section-header">
                <h2><i class="fas fa-history"></i> Recent Transactions</h2>
                <select class="filter-select" onchange="filterTransactions(this.value)">
                    <option value="all" <?php echo $filter == 'all' ? 'selected' : ''; ?>>All</option>
                    <option value="completed" <?php echo $filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                    <option value="pending" <?php echo $filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                    <option value="paid" <?php echo $filter == 'paid' ? 'selected' : ''; ?>>Paid</option>
                </select>
            </div>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Booking Ref</th>
                            <th>Parking Space</th>
                            <th>Date</th>
                            <th>Gross Amount</th>
                            <th>Commission (15%)</th>
                            <th>Your Earnings</th>
                            <th>Status</th>
                            <th>Payout Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($transactions)): ?>
                            <tr>
                                <td colspan="8" class="empty-state">
                                    <i class="fas fa-receipt"></i><br>
                                    No transactions yet
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php 
                            $filtered_transactions = $transactions;
                            if ($filter !== 'all') {
                                $filtered_transactions = array_filter($transactions, function($trans) use ($filter) {
                                    return ($filter === 'completed' && $trans['status'] === 'completed') ||
                                           ($filter === 'pending' && $trans['status'] === 'pending') ||
                                           ($filter === 'paid' && $trans['payout_status'] === 'paid');
                                });
                            }
                            foreach ($filtered_transactions as $trans): 
                            ?>
                            <tr>
                                <td class="booking-ref"><i class="fas fa-hashtag"></i> <?php echo sanitize($trans['booking_reference'] ?? 'N/A'); ?></td>
                                <td><i class="fas fa-parking"></i> <?php echo sanitize($trans['parking_name'] ?? 'N/A'); ?></td>
                                <td><i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($trans['start_date'] ?? 'now')); ?></td>
                                <td class="amount-positive"><i class="fas fa-money-bill-wave"></i> ₦<?php echo number_format($trans['gross_amount'] ?? 0, 2); ?></td>
                                <td class="commission-amount"><i class="fas fa-percent"></i> -₦<?php echo number_format($trans['commission_amount'] ?? 0, 2); ?></td>
                                <td class="amount-positive"><i class="fas fa-chart-line"></i> ₦<?php echo number_format($trans['owner_payout'] ?? 0, 2); ?></td>
                                <td>
                                    <span class="status-badge bg-<?php 
                                        echo $trans['status'] == 'completed' ? 'green' : 
                                            ($trans['status'] == 'pending' ? 'yellow' : 
                                            ($trans['status'] == 'cancelled' ? 'red' : 'gray')); ?>-100">
                                        <i class="fas <?php echo $trans['status'] == 'completed' ? 'fa-check-circle' : ($trans['status'] == 'pending' ? 'fa-clock' : 'fa-times-circle'); ?>"></i>
                                        <?php echo ucfirst(sanitize($trans['status'] ?? 'N/A')); ?>
                                    </span>
                                </td>
                                <td>
                                    <span class="status-badge bg-<?php 
                                        echo ($trans['payout_status'] ?? '') == 'paid' ? 'green' : 
                                            (($trans['payout_status'] ?? '') == 'processing' ? 'blue' : 
                                            (($trans['payout_status'] ?? '') == 'pending' ? 'yellow' : 'gray')); ?>-100">
                                        <i class="fas <?php echo ($trans['payout_status'] ?? '') == 'paid' ? 'fa-check-circle' : (($trans['payout_status'] ?? '') == 'processing' ? 'fa-spinner fa-pulse' : 'fa-clock'); ?>"></i>
                                        <?php echo ucfirst(sanitize($trans['payout_status'] ?? 'N/A')); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
        
        <!-- Glassmorphism Withdrawal History -->
        <div class="withdrawals-section">
            <h2><i class="fas fa-history"></i> Withdrawal History</h2>
            
            <div class="table-container">
                <table>
                    <thead>
                        <tr>
                            <th>Date</th>
                            <th>Amount</th>
                            <th>Bank</th>
                            <th>Account</th>
                            <th>Reference</th>
                            <th>Status</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php if (empty($withdrawals)): ?>
                            <tr>
                                <td colspan="6" class="empty-state">
                                    <i class="fas fa-download"></i><br>
                                    No withdrawals yet
                                </td>
                            </tr>
                        <?php else: ?>
                            <?php foreach ($withdrawals as $withdrawal): ?>
                            <tr>
                                <td><i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($withdrawal['created_at'] ?? 'now')); ?></td>
                                <td class="amount-negative"><i class="fas fa-arrow-down"></i> -₦<?php echo number_format($withdrawal['amount'] ?? 0, 2); ?></td>
                                <td><i class="fas fa-university"></i> <?php echo sanitize($withdrawal['bank_name'] ?? 'N/A'); ?></td>
                                <td><i class="fas fa-credit-card"></i> <?php echo sanitize($withdrawal['account_number'] ?? 'N/A'); ?></td>
                                <td><i class="fas fa-hashtag"></i> <?php echo sanitize($withdrawal['reference'] ?? 'N/A'); ?></td>
                                <td>
                                    <span class="status-badge bg-<?php 
                                        echo ($withdrawal['status'] ?? '') == 'completed' ? 'green' : 
                                            (($withdrawal['status'] ?? '') == 'processing' ? 'blue' : 
                                            (($withdrawal['status'] ?? '') == 'pending' ? 'yellow' : 'red')); ?>-100">
                                        <i class="fas <?php echo ($withdrawal['status'] ?? '') == 'completed' ? 'fa-check-circle' : (($withdrawal['status'] ?? '') == 'processing' ? 'fa-spinner fa-pulse' : 'fa-clock'); ?>"></i>
                                        <?php echo ucfirst(sanitize($withdrawal['status'] ?? 'N/A')); ?>
                                    </span>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        <?php endif; ?>
                    </tbody>
                </table>
            </div>
        </div>
    </div>
    
    <script>
        function filterTransactions(status) {
            window.location.href = '?filter=' + encodeURIComponent(status);
        }
        
        const accountInput = document.querySelector('input[name="account_number"]');
        if (accountInput) {
            accountInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '');
            });
        }
        
        const amountInput = document.querySelector('input[name="amount"]');
        if (amountInput) {
            amountInput.addEventListener('input', function(e) {
                let value = parseFloat(this.value);
                let max = parseFloat(this.getAttribute('max'));
                if (value > max) {
                    this.value = max;
                }
            });
        }
    </script>
</body>
</html>