<?php
require_once '../config/database.php';
require_once 'includes/auth.php';

// Require admin login
requireAdminLogin();

$database = new Database();
$db = $database->getConnection();

// Get today's stats
$today = date('Y-m-d');

// Total bookings today
$bookings_query = "SELECT COUNT(*) as total, COALESCE(SUM(total_amount), 0) as revenue 
                   FROM reservations WHERE DATE(created_at) = :today";
$bookings_stmt = $db->prepare($bookings_query);
$bookings_stmt->bindParam(':today', $today);
$bookings_stmt->execute();
$today_stats = $bookings_stmt->fetch(PDO::FETCH_ASSOC);

// Total users
$users_query = "SELECT 
    COUNT(*) as total_users,
    SUM(CASE WHEN user_type = 'owner' THEN 1 ELSE 0 END) as total_owners,
    SUM(CASE WHEN user_type = 'parker' THEN 1 ELSE 0 END) as total_parkers
    FROM users WHERE is_active = 1";
$users_stmt = $db->prepare($users_query);
$users_stmt->execute();
$user_stats = $users_stmt->fetch(PDO::FETCH_ASSOC);

// Total earnings
$earnings_query = "SELECT 
    COALESCE(SUM(commission_amount), 0) as total_commission,
    COALESCE(SUM(owner_payout), 0) as total_payouts
    FROM reservations WHERE status = 'completed'";
$earnings_stmt = $db->prepare($earnings_query);
$earnings_stmt->execute();
$earnings_stats = $earnings_stmt->fetch(PDO::FETCH_ASSOC);

// Recent bookings
$recent_query = "SELECT r.*, u.first_name, u.last_name, ps.name as parking_name
                 FROM reservations r
                 JOIN users u ON r.user_id = u.id
                 JOIN parking_spaces ps ON r.parking_id = ps.id
                 ORDER BY r.created_at DESC LIMIT 10";
$recent_stmt = $db->prepare($recent_query);
$recent_stmt->execute();
$recent_bookings = $recent_stmt->fetchAll(PDO::FETCH_ASSOC);

// Monthly chart data
$monthly_query = "SELECT 
    DATE_FORMAT(created_at, '%Y-%m') as month,
    COUNT(*) as bookings,
    COALESCE(SUM(total_amount), 0) as revenue,
    COALESCE(SUM(commission_amount), 0) as commission
    FROM reservations
    WHERE created_at >= DATE_SUB(NOW(), INTERVAL 6 MONTH)
    GROUP BY DATE_FORMAT(created_at, '%Y-%m')
    ORDER BY month ASC";
$monthly_stmt = $db->prepare($monthly_query);
$monthly_stmt->execute();
$monthly_data = $monthly_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get commission rate for display
$settings_query = "SELECT setting_value FROM system_settings WHERE setting_key = 'commission_rate'";
$settings_stmt = $db->prepare($settings_query);
$settings_stmt->execute();
$commission_rate = $settings_stmt->fetchColumn();

// Log this view
logAdminAction($db, 'view_dashboard', 'Viewed main dashboard');

$page_title = 'Dashboard';
include 'includes/header.php';
?>

<!-- Custom Styles for Dashboard -->
<style>
    .dashboard-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .dashboard-stat-card {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 24px;
        padding: 24px;
        transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
        box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        animation: fadeInUp 0.5s ease forwards;
        opacity: 0;
    }
    
    .dashboard-stat-card:nth-child(1) { animation-delay: 0.05s; }
    .dashboard-stat-card:nth-child(2) { animation-delay: 0.1s; }
    .dashboard-stat-card:nth-child(3) { animation-delay: 0.15s; }
    .dashboard-stat-card:nth-child(4) { animation-delay: 0.2s; }
    
    .dashboard-stat-card:hover {
        transform: translateY(-5px);
        background: rgba(255,255,255,0.12);
        border-color: rgba(255,255,255,0.3);
        box-shadow: 0 20px 48px rgba(0,0,0,0.3);
    }
    
    .dashboard-stat-card h3 {
        font-size: 14px;
        font-weight: 500;
        color: rgba(255,255,255,0.7);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .dashboard-stat-card h3 i {
        color: #a5b4fc;
    }
    
    .stat-main-number {
        font-family: 'Outfit', sans-serif;
        font-size: 36px;
        font-weight: 800;
        background: linear-gradient(135deg, #fff, #a5b4fc);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 8px;
    }
    
    .stat-sub-number {
        font-size: 18px;
        font-weight: 700;
        color: #4ade80;
    }
    
    .stat-sub-number.commission {
        color: #fbbf24;
    }
    
    .stat-detail {
        display: flex;
        gap: 15px;
        margin-top: 12px;
        font-size: 13px;
        color: rgba(255,255,255,0.6);
    }
    
    .stat-detail span i {
        margin-right: 5px;
    }
    
    /* Chart Container */
    .chart-container {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 24px;
        padding: 28px;
        margin-bottom: 30px;
        transition: all 0.3s ease;
    }
    
    .chart-container:hover {
        background: rgba(255,255,255,0.1);
        border-color: rgba(255,255,255,0.25);
    }
    
    .chart-container h2 {
        font-family: 'Outfit', sans-serif;
        font-size: 18px;
        font-weight: 600;
        background: linear-gradient(135deg, #fff, #a5b4fc);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .chart-wrapper {
        position: relative;
        height: 320px;
    }
    
    /* Recent Bookings Table */
    .recent-section {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 24px;
        padding: 28px;
        transition: all 0.3s ease;
    }
    
    .recent-section:hover {
        background: rgba(255,255,255,0.1);
        border-color: rgba(255,255,255,0.25);
    }
    
    .recent-header {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 24px;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .recent-header h2 {
        font-family: 'Outfit', sans-serif;
        font-size: 18px;
        font-weight: 600;
        background: linear-gradient(135deg, #fff, #a5b4fc);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .recent-table {
        overflow-x: auto;
    }
    
    .recent-table table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .recent-table th {
        text-align: left;
        padding: 14px;
        background: rgba(255,255,255,0.05);
        color: rgba(255,255,255,0.8);
        font-weight: 600;
        font-size: 12px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    
    .recent-table td {
        padding: 14px;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        font-size: 13px;
        color: rgba(255,255,255,0.9);
    }
    
    .recent-table tr:hover td {
        background: rgba(255,255,255,0.04);
    }
    
    .booking-ref-link {
        font-family: 'Outfit', sans-serif;
        color: #a5b4fc;
        font-weight: 600;
        text-decoration: none;
    }
    
    .booking-ref-link:hover {
        text-decoration: underline;
    }
    
    @media (max-width: 1100px) {
        .dashboard-stats {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 768px) {
        .dashboard-stats {
            grid-template-columns: 1fr;
            gap: 16px;
        }
        
        .stat-main-number {
            font-size: 28px;
        }
        
        .chart-wrapper {
            height: 250px;
        }
        
        .recent-header {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .recent-table th,
        .recent-table td {
            white-space: nowrap;
        }
    }
    
    @media (max-width: 480px) {
        .stat-main-number {
            font-size: 24px;
        }
        
        .stat-sub-number {
            font-size: 16px;
        }
    }
</style>

<!-- Top Bar -->
<div class="top-bar">
    <div class="page-title">
        <h1><i class="fas fa-tachometer-alt"></i> Dashboard</h1>
    </div>
    <div class="admin-info">
        <span class="admin-badge"><i class="fas fa-shield-alt"></i> <?php echo $_SESSION['admin_role']; ?></span>
        <span class="admin-name"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<!-- Stats Cards -->
<div class="dashboard-stats">
    <div class="dashboard-stat-card">
        <h3><i class="fas fa-calendar-day"></i> Today's Bookings</h3>
        <div class="stat-main-number"><?php echo $today_stats['total'] ?? 0; ?></div>
        <div class="stat-sub-number">₦<?php echo number_format($today_stats['revenue'] ?? 0, 2); ?></div>
    </div>
    
    <div class="dashboard-stat-card">
        <h3><i class="fas fa-users"></i> Total Users</h3>
        <div class="stat-main-number"><?php echo $user_stats['total_users'] ?? 0; ?></div>
        <div class="stat-detail">
            <span><i class="fas fa-user-tie"></i> Owners: <?php echo $user_stats['total_owners'] ?? 0; ?></span>
            <span><i class="fas fa-user"></i> Parkers: <?php echo $user_stats['total_parkers'] ?? 0; ?></span>
        </div>
    </div>
    
    <div class="dashboard-stat-card">
        <h3><i class="fas fa-chart-line"></i> Platform Earnings</h3>
        <div class="stat-main-number">₦<?php echo number_format($earnings_stats['total_commission'] ?? 0, 2); ?></div>
        <div class="stat-detail">
            <span><i class="fas fa-hand-holding-usd"></i> Paid: ₦<?php echo number_format($earnings_stats['total_payouts'] ?? 0, 2); ?></span>
        </div>
    </div>
    
    <div class="dashboard-stat-card">
        <h3><i class="fas fa-percent"></i> Commission Rate</h3>
        <div class="stat-main-number"><?php echo $commission_rate ?? 15; ?>%</div>
        <div class="stat-detail">
            <span><i class="fas fa-arrow-down"></i> Min: ₦100</span>
            <span><i class="fas fa-arrow-up"></i> Max: ₦50,000</span>
        </div>
    </div>
</div>

<!-- Modern Chart Container -->
<div class="chart-container">
    <h2><i class="fas fa-chart-line"></i> Last 6 Months Performance</h2>
    <div class="chart-wrapper">
        <canvas id="monthlyChart"></canvas>
    </div>
</div>

<!-- Recent Bookings -->
<div class="recent-section">
    <div class="recent-header">
        <h2><i class="fas fa-clock"></i> Recent Bookings</h2>
        <a href="bookings.php" class="btn btn-primary"><i class="fas fa-eye"></i> View All</a>
    </div>
    <div class="recent-table">
        <table>
            <thead>
                <tr>
                    <th><i class="fas fa-ticket-alt"></i> Booking Ref</th>
                    <th><i class="fas fa-user"></i> Customer</th>
                    <th><i class="fas fa-parking"></i> Parking Space</th>
                    <th><i class="fas fa-money-bill-wave"></i> Amount</th>
                    <th><i class="fas fa-chart-simple"></i> Status</th>
                    <th><i class="fas fa-calendar"></i> Date</th>
                    <th><i class="fas fa-cog"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($recent_bookings as $booking): ?>
                <tr>
                    <td><a href="bookings.php?view=<?php echo $booking['id']; ?>" class="booking-ref-link">
                        <i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($booking['booking_reference']); ?>
                    </a></td>
                    <td><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']); ?></td>
                    <td><i class="fas fa-parking"></i> <?php echo htmlspecialchars($booking['parking_name']); ?></td>
                    <td class="amount-positive">₦<?php echo number_format($booking['total_amount'], 2); ?></td>
                    <td>
                        <span class="badge badge-<?php 
                            echo $booking['status'] == 'completed' ? 'success' : 
                                ($booking['status'] == 'pending' ? 'warning' : 
                                ($booking['status'] == 'cancelled' ? 'danger' : 'info')); ?>">
                            <i class="fas <?php 
                                echo $booking['status'] == 'completed' ? 'fa-check-circle' : 
                                    ($booking['status'] == 'pending' ? 'fa-clock' : 
                                    ($booking['status'] == 'cancelled' ? 'fa-times-circle' : 'fa-info-circle')); ?>"></i>
                            <?php echo ucfirst($booking['status']); ?>
                        </span>
                    </td>
                    <td><i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($booking['created_at'])); ?></td>
                    <td>
                        <a href="bookings.php?view=<?php echo $booking['id']; ?>" class="btn btn-sm btn-primary">
                            <i class="fas fa-eye"></i> View
                        </a>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Modern Chart.js Script with Sharp Gradient Bars -->
<script src="https://cdn.jsdelivr.net/npm/chart.js"></script>
<script>
    // Prepare chart data
    const months = <?php 
        $months = array_column($monthly_data, 'month');
        $months = array_map(function($m) { 
            return date('M Y', strtotime($m . '-01')); 
        }, $months);
        echo json_encode($months);
    ?>;
    
    const revenue = <?php 
        $revenues = array_column($monthly_data, 'revenue');
        echo json_encode($revenues);
    ?>;
    
    const commissions = <?php 
        $commissions = array_column($monthly_data, 'commission');
        echo json_encode($commissions);
    ?>;
    
    // Create sharp, modern gradient chart
    const ctx = document.getElementById('monthlyChart').getContext('2d');
    
    // Create gradients
    const revenueGradient = ctx.createLinearGradient(0, 0, 0, 300);
    revenueGradient.addColorStop(0, 'rgba(79, 110, 247, 0.6)');
    revenueGradient.addColorStop(1, 'rgba(79, 110, 247, 0.02)');
    
    const commissionGradient = ctx.createLinearGradient(0, 0, 0, 300);
    commissionGradient.addColorStop(0, 'rgba(16, 185, 129, 0.6)');
    commissionGradient.addColorStop(1, 'rgba(16, 185, 129, 0.02)');
    
    new Chart(ctx, {
        type: 'line',
        data: {
            labels: months,
            datasets: [
                {
                    label: 'Revenue',
                    data: revenue,
                    borderColor: '#4F6EF7',
                    borderWidth: 3,
                    backgroundColor: revenueGradient,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#4F6EF7',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#7C3AED',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 2
                },
                {
                    label: 'Commission',
                    data: commissions,
                    borderColor: '#10B981',
                    borderWidth: 3,
                    backgroundColor: commissionGradient,
                    fill: true,
                    tension: 0.4,
                    pointBackgroundColor: '#10B981',
                    pointBorderColor: '#ffffff',
                    pointBorderWidth: 2,
                    pointRadius: 5,
                    pointHoverRadius: 7,
                    pointHoverBackgroundColor: '#059669',
                    pointHoverBorderColor: '#ffffff',
                    pointHoverBorderWidth: 2
                }
            ]
        },
        options: {
            responsive: true,
            maintainAspectRatio: false,
            interaction: {
                mode: 'index',
                intersect: false
            },
            plugins: {
                tooltip: {
                    backgroundColor: 'rgba(26, 26, 46, 0.95)',
                    titleColor: '#ffffff',
                    bodyColor: 'rgba(255,255,255,0.8)',
                    borderColor: 'rgba(165,180,252,0.5)',
                    borderWidth: 1,
                    cornerRadius: 12,
                    padding: 12,
                    callbacks: {
                        label: function(context) {
                            let label = context.dataset.label || '';
                            let value = context.raw;
                            return label + ': ₦' + value.toLocaleString();
                        }
                    }
                },
                legend: {
                    position: 'bottom',
                    labels: {
                        color: 'rgba(255,255,255,0.8)',
                        font: {
                            family: "'DM Sans', sans-serif",
                            size: 12,
                            weight: '500'
                        },
                        usePointStyle: true,
                        pointStyle: 'circle'
                    }
                },
                decimation: {
                    enabled: true,
                    algorithm: 'min-max'
                }
            },
            scales: {
                x: {
                    grid: {
                        color: 'rgba(255,255,255,0.08)',
                        drawBorder: false
                    },
                    ticks: {
                        color: 'rgba(255,255,255,0.6)',
                        font: {
                            family: "'DM Sans', sans-serif",
                            size: 11
                        }
                    }
                },
                y: {
                    beginAtZero: true,
                    grid: {
                        color: 'rgba(255,255,255,0.08)',
                        drawBorder: false
                    },
                    ticks: {
                        color: 'rgba(255,255,255,0.6)',
                        font: {
                            family: "'DM Sans', sans-serif",
                            size: 11
                        },
                        callback: function(value) {
                            return '₦' + value.toLocaleString();
                        }
                    }
                }
            },
            elements: {
                line: {
                    borderJoin: 'round',
                    borderCap: 'round'
                }
            },
            layout: {
                padding: {
                    left: 10,
                    right: 10,
                    top: 20,
                    bottom: 10
                }
            }
        }
    });
</script>

<?php include 'includes/footer.php'; ?>