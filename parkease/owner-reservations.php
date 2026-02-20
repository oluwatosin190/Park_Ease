<?php
require_once 'config/database.php';

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$owner_id = $_SESSION['user_id'];
$filter = isset($_GET['filter']) ? $_GET['filter'] : 'all';

// Get all parking spaces owned by this user
$spaces_query = "SELECT id, name FROM parking_spaces WHERE owner_id = :owner_id AND is_active = 1";
$spaces_stmt = $db->prepare($spaces_query);
$spaces_stmt->bindParam(':owner_id', $owner_id);
$spaces_stmt->execute();
$spaces = $spaces_stmt->fetchAll(PDO::FETCH_ASSOC);

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

if ($filter === 'pending') {
    $query .= " AND r.status = 'pending'";
} elseif ($filter === 'confirmed') {
    $query .= " AND r.status = 'confirmed'";
} elseif ($filter === 'active') {
    $query .= " AND r.status = 'active'";
} elseif ($filter === 'completed') {
    $query .= " AND r.status = 'completed'";
} elseif ($filter === 'cancelled') {
    $query .= " AND r.status = 'cancelled'";
}

$query .= " ORDER BY r.created_at DESC";

$stmt = $db->prepare($query);
$stmt->bindParam(':owner_id', $owner_id);
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_query = "SELECT 
    COUNT(CASE WHEN r.status = 'pending' THEN 1 END) as pending_count,
    COUNT(CASE WHEN r.status = 'confirmed' THEN 1 END) as confirmed_count,
    COUNT(CASE WHEN r.status = 'active' THEN 1 END) as active_count,
    COUNT(CASE WHEN r.status = 'completed' THEN 1 END) as completed_count,
    COUNT(CASE WHEN r.status = 'cancelled' THEN 1 END) as cancelled_count,
    COALESCE(SUM(CASE WHEN r.payment_status = 'paid' THEN r.total_amount ELSE 0 END), 0) as total_earned
    FROM reservations r
    JOIN parking_spaces ps ON r.parking_id = ps.id
    WHERE ps.owner_id = :owner_id";

$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindParam(':owner_id', $owner_id);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Manage Bookings - ParkEase Owner Dashboard</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #F9FAFB;
            padding: 40px 20px;
        }
        .container {
            max-width: 1400px;
            margin: 0 auto;
        }
        
        /* Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
            flex-wrap: wrap;
            gap: 15px;
        }
        .header h1 {
            font-size: 28px;
            color: #111827;
        }
        .back-link {
            color: #4F6EF7;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 8px 16px;
            background: white;
            border-radius: 8px;
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(5, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border-radius: 12px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
        }
        .stat-card h3 {
            font-size: 14px;
            color: #6B7280;
            margin-bottom: 10px;
        }
        .stat-number {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
        }
        .stat-card.pending { border-top: 4px solid #F59E0B; }
        .stat-card.confirmed { border-top: 4px solid #10B981; }
        .stat-card.active { border-top: 4px solid #3B82F6; }
        .stat-card.completed { border-top: 4px solid #6B7280; }
        .stat-card.cancelled { border-top: 4px solid #EF4444; }
        .stat-card.earnings { border-top: 4px solid #4F6EF7; }
        
        /* Filter Tabs */
        .filter-tabs {
            display: flex;
            gap: 10px;
            margin-bottom: 30px;
            flex-wrap: wrap;
        }
        .filter-tab {
            padding: 10px 20px;
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            color: #6B7280;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }
        .filter-tab:hover {
            background: #F3F4F6;
        }
        .filter-tab.active {
            background: #4F6EF7;
            color: white;
            border-color: #4F6EF7;
        }
        
        /* Bookings Table */
        .bookings-table-container {
            background: white;
            border-radius: 16px;
            padding: 20px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            overflow-x: auto;
        }
        table {
            width: 100%;
            border-collapse: collapse;
        }
        th {
            text-align: left;
            padding: 15px;
            background: #F9FAFB;
            color: #6B7280;
            font-weight: 500;
            font-size: 13px;
            border-bottom: 2px solid #E5E7EB;
        }
        td {
            padding: 15px;
            border-bottom: 1px solid #F3F4F6;
            font-size: 14px;
        }
        tr:hover td {
            background: #F9FAFB;
        }
        .booking-ref {
            font-family: monospace;
            font-weight: 600;
            color: #4F6EF7;
        }
        .customer-info {
            display: flex;
            flex-direction: column;
        }
        .customer-name {
            font-weight: 600;
            color: #111827;
        }
        .customer-email {
            font-size: 12px;
            color: #6B7280;
        }
        .status-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
            text-transform: capitalize;
        }
        .status-pending { background: #FEF3C7; color: #D97706; }
        .status-confirmed { background: #DCFCE7; color: #16A34A; }
        .status-active { background: #DBEAFE; color: #2563EB; }
        .status-completed { background: #E5E7EB; color: #4B5563; }
        .status-cancelled { background: #FEE2E2; color: #DC2626; }
        
        .payment-badge {
            display: inline-block;
            padding: 4px 8px;
            border-radius: 4px;
            font-size: 11px;
            font-weight: 500;
        }
        .payment-paid { background: #DCFCE7; color: #16A34A; }
        .payment-pending { background: #FEF3C7; color: #D97706; }
        .payment-refunded { background: #E5E7EB; color: #4B5563; }
        
        .action-buttons {
            display: flex;
            gap: 5px;
            flex-wrap: wrap;
        }
        .btn {
            padding: 6px 12px;
            border: none;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-confirm {
            background: #10B981;
            color: white;
        }
        .btn-confirm:hover {
            background: #059669;
        }
        .btn-view {
            background: #4F6EF7;
            color: white;
        }
        .btn-view:hover {
            background: #3a56d4;
        }
        .btn-cancel {
            background: #FEE2E2;
            color: #DC2626;
        }
        .btn-cancel:hover {
            background: #FECACA;
        }
        .btn-complete {
            background: #6B7280;
            color: white;
        }
        .btn-complete:hover {
            background: #4B5563;
        }
        
        .empty-state {
            text-align: center;
            padding: 60px;
            color: #6B7280;
        }
        .parking-filter {
            margin-bottom: 20px;
        }
        .parking-select {
            padding: 10px;
            border: 1px solid #E5E7EB;
            border-radius: 8px;
            font-size: 14px;
            min-width: 200px;
        }
        
        @media (max-width: 1024px) {
            .stats-grid {
                grid-template-columns: repeat(3, 1fr);
            }
        }
        @media (max-width: 768px) {
            .stats-grid {
                grid-template-columns: repeat(2, 1fr);
            }
            table {
                min-width: 800px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Manage Bookings</h1>
            <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        </div>
        
        <!-- Statistics Cards -->
        <div class="stats-grid">
            <div class="stat-card pending">
                <h3>Pending</h3>
                <div class="stat-number"><?php echo $stats['pending_count'] ?? 0; ?></div>
            </div>
            <div class="stat-card confirmed">
                <h3>Confirmed</h3>
                <div class="stat-number"><?php echo $stats['confirmed_count'] ?? 0; ?></div>
            </div>
            <div class="stat-card active">
                <h3>Active</h3>
                <div class="stat-number"><?php echo $stats['active_count'] ?? 0; ?></div>
            </div>
            <div class="stat-card completed">
                <h3>Completed</h3>
                <div class="stat-number"><?php echo $stats['completed_count'] ?? 0; ?></div>
            </div>
            <div class="stat-card earnings">
                <h3>Total Earned</h3>
                <div class="stat-number">₦<?php echo number_format($stats['total_earned'] ?? 0, 2); ?></div>
            </div>
        </div>
        
        <!-- Filter Tabs -->
        <div class="filter-tabs">
            <a href="?filter=all" class="filter-tab <?php echo $filter == 'all' ? 'active' : ''; ?>">All Bookings</a>
            <a href="?filter=pending" class="filter-tab <?php echo $filter == 'pending' ? 'active' : ''; ?>">Pending</a>
            <a href="?filter=confirmed" class="filter-tab <?php echo $filter == 'confirmed' ? 'active' : ''; ?>">Confirmed</a>
            <a href="?filter=active" class="filter-tab <?php echo $filter == 'active' ? 'active' : ''; ?>">Active</a>
            <a href="?filter=completed" class="filter-tab <?php echo $filter == 'completed' ? 'active' : ''; ?>">Completed</a>
            <a href="?filter=cancelled" class="filter-tab <?php echo $filter == 'cancelled' ? 'active' : ''; ?>">Cancelled</a>
        </div>
        
        <!-- Bookings Table -->
        <div class="bookings-table-container">
            <?php if (empty($bookings)): ?>
                <div class="empty-state">
                    <svg width="60" height="60" viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="1.5">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <p style="margin-top: 10px;">No bookings found</p>
                </div>
            <?php else: ?>
                <table>
                    <thead>
                        <tr>
                            <th>Booking Ref</th>
                            <th>Parking Space</th>
                            <th>Customer</th>
                            <th>Dates</th>
                            <th>Amount</th>
                            <th>Status</th>
                            <th>Payment</th>
                            <th>Actions</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($bookings as $booking): ?>
                        <tr>
                            <td>
                                <span class="booking-ref"><?php echo htmlspecialchars($booking['booking_reference'] ?? 'N/A'); ?></span>
                            </td>
                            <td>
                                <strong><?php echo htmlspecialchars($booking['parking_name']); ?></strong>
                                <div style="font-size: 12px; color: #6B7280;"><?php echo htmlspecialchars($booking['city']); ?></div>
                            </td>
                            <td>
                                <div class="customer-info">
                                    <span class="customer-name"><?php echo htmlspecialchars($booking['first_name'] . ' ' . $booking['last_name']); ?></span>
                                    <span class="customer-email"><?php echo htmlspecialchars($booking['email']); ?></span>
                                    <?php if ($booking['phone']): ?>
                                        <span class="customer-email">📞 <?php echo htmlspecialchars($booking['phone']); ?></span>
                                    <?php endif; ?>
                                </div>
                            </td>
                            <td>
                                <div><strong>From:</strong> <?php echo date('M d, h:i A', strtotime($booking['start_date'])); ?></div>
                                <div><strong>To:</strong> <?php echo date('M d, h:i A', strtotime($booking['end_date'])); ?></div>
                                <div style="font-size: 12px; color: #6B7280;"><?php echo number_format($booking['total_hours'], 1); ?> hours</div>
                            </td>
                            <td>
                                <strong style="color: #4F6EF7;">₦<?php echo number_format($booking['total_amount'], 2); ?></strong>
                            </td>
                            <td>
                                <span class="status-badge status-<?php echo $booking['status']; ?>">
                                    <?php echo ucfirst($booking['status']); ?>
                                </span>
                            </td>
                            <td>
                                <span class="payment-badge payment-<?php echo $booking['payment_status']; ?>">
                                    <?php echo ucfirst($booking['payment_status']); ?>
                                </span>
                            </td>
                            <td>
                                <div class="action-buttons">
                                    <?php if ($booking['status'] == 'pending'): ?>
                                        <a href="update-booking-status.php?id=<?php echo $booking['id']; ?>&status=confirmed" 
                                           class="btn btn-confirm" 
                                           onclick="return confirm('Confirm this booking?')">Confirm</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($booking['status'] == 'confirmed'): ?>
                                        <a href="update-booking-status.php?id=<?php echo $booking['id']; ?>&status=active" 
                                           class="btn btn-complete"
                                           onclick="return confirm('Mark as active?')">Start</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($booking['status'] == 'active'): ?>
                                        <a href="update-booking-status.php?id=<?php echo $booking['id']; ?>&status=completed" 
                                           class="btn btn-complete"
                                           onclick="return confirm('Mark as completed?')">Complete</a>
                                    <?php endif; ?>
                                    
                                    <?php if ($booking['status'] != 'completed' && $booking['status'] != 'cancelled'): ?>
                                        <a href="update-booking-status.php?id=<?php echo $booking['id']; ?>&status=cancelled" 
                                           class="btn btn-cancel"
                                           onclick="return confirm('Cancel this booking?')">Cancel</a>
                                    <?php endif; ?>
                                    
                                    <a href="reservation-details.php?id=<?php echo $booking['id']; ?>" class="btn btn-view">View</a>
                                </div>
                            </td>
                        </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>