<?php
require_once '../config/database.php';
require_once 'includes/auth.php';

requireAdminLogin();

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? 'list';
$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$message = '';
$error = '';

// Handle booking actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_status'])) {
        $booking_id = (int)$_POST['booking_id'];
        $new_status = $_POST['status'];
        
        $update = "UPDATE reservations SET status = :status WHERE id = :id";
        $stmt = $db->prepare($update);
        $stmt->bindParam(':status', $new_status);
        $stmt->bindParam(':id', $booking_id);
        
        if ($stmt->execute()) {
            logAdminAction($db, 'update_booking_status', "Updated booking ID: $booking_id to $new_status");
            $message = "Booking status updated successfully";
        } else {
            $error = "Failed to update booking";
        }
    }
    
    if (isset($_POST['process_refund'])) {
        $booking_id = (int)$_POST['booking_id'];
        $amount = floatval($_POST['amount']);
        $reason = trim($_POST['reason']);
        
        header("Location: process-refund.php?id=$booking_id&amount=$amount&reason=" . urlencode($reason));
        exit();
    }
}

// Get filter
$status_filter = $_GET['status'] ?? 'all';
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['to'] ?? date('Y-m-d');

// Build query
$query = "SELECT r.*, 
          u.first_name as user_first, u.last_name as user_last, u.email as user_email,
          o.first_name as owner_first, o.last_name as owner_last,
          ps.name as parking_name, ps.address
          FROM reservations r
          JOIN users u ON r.user_id = u.id
          JOIN users o ON r.owner_id = o.id
          JOIN parking_spaces ps ON r.parking_id = ps.id
          WHERE DATE(r.created_at) BETWEEN :from AND :to";
$params = [':from' => $date_from, ':to' => $date_to];

if ($status_filter != 'all') {
    $query .= " AND r.status = :status";
    $params[':status'] = $status_filter;
}

$query .= " ORDER BY r.created_at DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get statistics
$stats_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending,
    SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) as confirmed,
    SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed,
    SUM(CASE WHEN status = 'cancelled' THEN 1 ELSE 0 END) as cancelled,
    COALESCE(SUM(total_amount), 0) as total_revenue,
    COALESCE(SUM(commission_amount), 0) as total_commission
    FROM reservations WHERE DATE(created_at) BETWEEN :from AND :to";
$stats_stmt = $db->prepare($stats_query);
$stats_stmt->bindParam(':from', $date_from);
$stats_stmt->bindParam(':to', $date_to);
$stats_stmt->execute();
$stats = $stats_stmt->fetch(PDO::FETCH_ASSOC);

logAdminAction($db, 'view_bookings', "Viewed bookings from $date_from to $date_to");

$page_title = 'Manage Bookings';
include 'includes/header.php';
?>

<!-- Custom Styles for Bookings Page -->
<style>
    .booking-ref {
        font-family: 'Outfit', sans-serif;
        color: #a5b4fc;
        font-weight: 600;
        letter-spacing: 0.3px;
    }
    
    .amount-positive {
        color: #4ade80;
        font-weight: 600;
    }
    
    .commission-amount {
        color: #fbbf24;
        font-weight: 600;
    }
    
    .btn-secondary {
        background: rgba(255,255,255,0.1);
        color: rgba(255,255,255,0.8);
        border: 1px solid rgba(255,255,255,0.2);
    }
    
    .btn-secondary:hover {
        background: rgba(255,255,255,0.2);
        transform: translateY(-2px);
    }
    
    .customer-info {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    
    .customer-info strong {
        color: white;
        font-weight: 600;
    }
    
    .customer-info small {
        color: rgba(255,255,255,0.6);
        font-size: 11px;
    }
    
    .parking-info {
        display: flex;
        flex-direction: column;
        gap: 4px;
    }
    
    .parking-info strong {
        color: white;
        font-weight: 600;
    }
    
    .parking-info small {
        color: rgba(255,255,255,0.6);
        font-size: 11px;
    }
    
    .date-info {
        font-size: 12px;
    }
    
    .date-info small {
        color: rgba(255,255,255,0.5);
        font-size: 10px;
    }
    
    .action-buttons {
        display: flex;
        gap: 8px;
        flex-wrap: wrap;
    }
    
    .stats-breakdown {
        display: flex;
        gap: 10px;
        font-size: 12px;
        flex-wrap: wrap;
        margin-top: 8px;
    }
    
    /* Modal overlay fix */
    .modal-overlay {
        display: none;
        position: fixed;
        top: 0;
        left: 0;
        width: 100%;
        height: 100%;
        background: rgba(0,0,0,0.6);
        backdrop-filter: blur(8px);
        align-items: center;
        justify-content: center;
        z-index: 1000;
    }
    
    .modal-overlay.active {
        display: flex;
    }
    
    .modal-content {
        background: rgba(26,26,46,0.95);
        backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 28px;
        padding: 32px;
        max-width: 450px;
        width: 90%;
        box-shadow: 0 20px 60px rgba(0,0,0,0.4);
        animation: modalIn 0.3s cubic-bezier(0.34, 1.56, 0.64, 1);
    }
    
    @keyframes modalIn {
        from {
            opacity: 0;
            transform: scale(0.9) translateY(20px);
        }
        to {
            opacity: 1;
            transform: scale(1) translateY(0);
        }
    }
    
    .modal-content h3 {
        font-family: 'Outfit', sans-serif;
        font-size: 20px;
        font-weight: 700;
        background: linear-gradient(135deg, #fff, #a5b4fc);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .modal-actions {
        display: flex;
        gap: 12px;
        margin-top: 24px;
    }
    
    .modal-actions .btn {
        flex: 1;
        justify-content: center;
    }
    
    /* DataTables custom styling */
    .dataTables_wrapper .dataTables_filter,
    .dataTables_wrapper .dataTables_length {
        margin-bottom: 15px;
    }
    
    .dataTables_wrapper .dataTables_filter input,
    .dataTables_wrapper .dataTables_length select {
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 50px;
        color: white;
        padding: 6px 12px;
    }
    
    .dataTables_wrapper .dataTables_filter input:focus,
    .dataTables_wrapper .dataTables_length select:focus {
        border-color: #a5b4fc;
        outline: none;
        box-shadow: 0 0 0 3px rgba(165,180,252,0.2);
    }
    
    .dataTables_wrapper .dataTables_info {
        color: rgba(255,255,255,0.6);
    }
    
    .dataTables_wrapper .dataTables_paginate .paginate_button {
        background: rgba(255,255,255,0.08);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 50px;
        color: rgba(255,255,255,0.8) !important;
        padding: 5px 12px;
        margin: 0 3px;
    }
    
    .dataTables_wrapper .dataTables_paginate .paginate_button.current {
        background: linear-gradient(135deg, #4F6EF7, #7C3AED);
        border-color: transparent;
        color: white !important;
    }
    
    .dataTables_wrapper .dataTables_paginate .paginate_button:hover {
        background: rgba(79,110,247,0.3);
        border-color: rgba(79,110,247,0.5);
        color: white !important;
    }
</style>

<!-- Top Bar -->
<div class="top-bar">
    <div class="page-title">
        <h1><i class="fas fa-calendar-check"></i> Manage Bookings</h1>
    </div>
    <div class="admin-info">
        <span class="admin-badge"><i class="fas fa-shield-alt"></i> <?php echo $_SESSION['admin_role']; ?></span>
        <span class="admin-name"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<?php if ($message): ?>
    <div class="alert alert-success">
        <i class="fas fa-check-circle"></i> <?php echo $message; ?>
    </div>
<?php endif; ?>

<?php if ($error): ?>
    <div class="alert alert-error">
        <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
    </div>
<?php endif; ?>

<!-- Glassmorphism Stats Cards -->
<div class="stats-grid">
    <div class="stat-card">
        <h3><i class="fas fa-chart-line"></i> Total Bookings</h3>
        <div class="stat-number"><?php echo $stats['total']; ?></div>
    </div>
    <div class="stat-card">
        <h3><i class="fas fa-money-bill-wave"></i> Revenue</h3>
        <div class="stat-number">₦<?php echo number_format($stats['total_revenue'], 2); ?></div>
    </div>
    <div class="stat-card">
        <h3><i class="fas fa-percent"></i> Commission</h3>
        <div class="stat-number">₦<?php echo number_format($stats['total_commission'], 2); ?></div>
    </div>
    <div class="stat-card">
        <h3><i class="fas fa-chart-pie"></i> Status Breakdown</h3>
        <div class="stats-breakdown">
            <span class="badge badge-warning"><i class="fas fa-clock"></i> Pending: <?php echo $stats['pending']; ?></span>
            <span class="badge badge-info"><i class="fas fa-check-circle"></i> Confirmed: <?php echo $stats['confirmed']; ?></span>
            <span class="badge badge-success"><i class="fas fa-check-double"></i> Completed: <?php echo $stats['completed']; ?></span>
            <span class="badge badge-danger"><i class="fas fa-times-circle"></i> Cancelled: <?php echo $stats['cancelled']; ?></span>
        </div>
    </div>
</div>

<!-- Glassmorphism Filters -->
<div class="table-container" style="margin-bottom: 20px;">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <h3 style="font-family: 'Outfit', sans-serif; font-size: 16px; font-weight: 600; color: white;">
            <i class="fas fa-filter"></i> Filter Bookings
        </h3>
    </div>
    <form method="GET" style="display: flex; gap: 15px; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin: 0;">
            <label><i class="fas fa-calendar-alt"></i> From Date</label>
            <input type="date" name="from" value="<?php echo $date_from; ?>" class="form-control">
        </div>
        <div class="form-group" style="margin: 0;">
            <label><i class="fas fa-calendar-alt"></i> To Date</label>
            <input type="date" name="to" value="<?php echo $date_to; ?>" class="form-control">
        </div>
        <div class="form-group" style="margin: 0;">
            <label><i class="fas fa-filter"></i> Status</label>
            <select name="status" class="form-control">
                <option value="all" <?php echo $status_filter == 'all' ? 'selected' : ''; ?>>All</option>
                <option value="pending" <?php echo $status_filter == 'pending' ? 'selected' : ''; ?>>Pending</option>
                <option value="confirmed" <?php echo $status_filter == 'confirmed' ? 'selected' : ''; ?>>Confirmed</option>
                <option value="active" <?php echo $status_filter == 'active' ? 'selected' : ''; ?>>Active</option>
                <option value="completed" <?php echo $status_filter == 'completed' ? 'selected' : ''; ?>>Completed</option>
                <option value="cancelled" <?php echo $status_filter == 'cancelled' ? 'selected' : ''; ?>>Cancelled</option>
            </select>
        </div>
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Apply Filters</button>
        <a href="reports.php?export=bookings&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>" class="btn btn-success"><i class="fas fa-file-excel"></i> Export to Excel</a>
    </form>
</div>

<!-- Glassmorphism Bookings Table -->
<div class="table-container">
    <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px; flex-wrap: wrap; gap: 15px;">
        <h3 style="font-family: 'Outfit', sans-serif; font-size: 16px; font-weight: 600; color: white;">
            <i class="fas fa-table-list"></i> All Bookings
        </h3>
    </div>
    <div style="overflow-x: auto;">
        <table id="bookingsTable">
            <thead>
                <tr>
                    <th><i class="fas fa-ticket-alt"></i> Booking Ref</th>
                    <th><i class="fas fa-user"></i> Customer</th>
                    <th><i class="fas fa-user-tie"></i> Owner</th>
                    <th><i class="fas fa-parking"></i> Parking Space</th>
                    <th><i class="fas fa-calendar"></i> Dates</th>
                    <th><i class="fas fa-money-bill-wave"></i> Amount</th>
                    <th><i class="fas fa-percent"></i> Commission</th>
                    <th><i class="fas fa-chart-simple"></i> Status</th>
                    <th><i class="fas fa-credit-card"></i> Payment</th>
                    <th><i class="fas fa-cog"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($bookings as $booking): ?>
                <tr>
                    <td>
                        <span class="booking-ref"><i class="fas fa-hashtag"></i> <?php echo htmlspecialchars($booking['booking_reference']); ?></span>
                    </td>
                    <td>
                        <div class="customer-info">
                            <strong><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($booking['user_first'] . ' ' . $booking['user_last']); ?></strong>
                            <small><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($booking['user_email']); ?></small>
                        </div>
                    </td>
                    <td>
                        <i class="fas fa-building"></i> <?php echo htmlspecialchars($booking['owner_first'] . ' ' . $booking['owner_last']); ?>
                    </td>
                    <td>
                        <div class="parking-info">
                            <strong><i class="fas fa-parking"></i> <?php echo htmlspecialchars($booking['parking_name']); ?></strong>
                            <small><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($booking['address']); ?></small>
                        </div>
                    </td>
                    <td class="date-info">
                        <div><small><i class="fas fa-play-circle"></i> From:</small> <?php echo date('M d, h:i A', strtotime($booking['start_date'])); ?></div>
                        <div><small><i class="fas fa-stop-circle"></i> To:</small> <?php echo date('M d, h:i A', strtotime($booking['end_date'])); ?></div>
                    </td>
                    <td class="amount-positive">₦<?php echo number_format($booking['total_amount'], 2); ?></td>
                    <td class="commission-amount">₦<?php echo number_format($booking['commission_amount'], 2); ?></td>
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
                    <td>
                        <span class="badge badge-<?php echo $booking['payment_status'] == 'paid' ? 'success' : 'warning'; ?>">
                            <i class="fas <?php echo $booking['payment_status'] == 'paid' ? 'fa-check-circle' : 'fa-clock'; ?>"></i>
                            <?php echo ucfirst($booking['payment_status']); ?>
                        </span>
                    </td>
                    <td>
                        <div class="action-buttons">
                            <button onclick="showStatusModal(<?php echo $booking['id']; ?>, '<?php echo $booking['status']; ?>')" class="btn btn-sm btn-primary">
                                <i class="fas fa-edit"></i> Status
                            </button>
                            
                            <?php if ($booking['payment_status'] == 'paid' && $booking['status'] != 'cancelled'): ?>
                                <button onclick="showRefundModal(<?php echo $booking['id']; ?>, <?php echo $booking['total_amount']; ?>)" class="btn btn-sm btn-danger">
                                    <i class="fas fa-undo-alt"></i> Refund
                                </button>
                            <?php endif; ?>
                            
                            <a href="../reservation-details.php?id=<?php echo $booking['id']; ?>" target="_blank" class="btn btn-sm btn-info">
                                <i class="fas fa-eye"></i> View
                            </a>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Glassmorphism Status Update Modal -->
<div id="statusModal" class="modal-overlay">
    <div class="modal-content">
        <h3><i class="fas fa-pen-alt"></i> Update Booking Status</h3>
        
        <form method="POST">
            <input type="hidden" name="booking_id" id="statusBookingId">
            
            <div class="form-group">
                <label><i class="fas fa-chart-line"></i> New Status</label>
                <select name="status" id="statusSelect" class="form-control" required>
                    <option value="pending">Pending</option>
                    <option value="confirmed">Confirmed</option>
                    <option value="active">Active</option>
                    <option value="completed">Completed</option>
                    <option value="cancelled">Cancelled</option>
                </select>
            </div>
            
            <div class="modal-actions">
                <button type="submit" name="update_status" class="btn btn-primary"><i class="fas fa-save"></i> Update</button>
                <button type="button" onclick="closeStatusModal()" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</button>
            </div>
        </form>
    </div>
</div>

<!-- Glassmorphism Refund Modal -->
<div id="refundModal" class="modal-overlay">
    <div class="modal-content">
        <h3><i class="fas fa-undo-alt"></i> Process Refund</h3>
        
        <form method="POST" action="process-refund.php">
            <input type="hidden" name="booking_id" id="refundBookingId">
            
            <div class="form-group">
                <label><i class="fas fa-money-bill-wave"></i> Refund Amount (₦)</label>
                <input type="number" name="amount" id="refundAmount" class="form-control" step="0.01" min="0" required>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-comment"></i> Reason for Refund</label>
                <textarea name="reason" class="form-control" rows="3" required placeholder="Enter reason..."></textarea>
            </div>
            
            <div class="modal-actions">
                <button type="submit" name="process_refund" class="btn btn-danger"><i class="fas fa-check"></i> Process Refund</button>
                <button type="button" onclick="closeRefundModal()" class="btn btn-secondary"><i class="fas fa-times"></i> Cancel</button>
            </div>
        </form>
    </div>
</div>

<script>
    function showStatusModal(bookingId, currentStatus) {
        document.getElementById('statusBookingId').value = bookingId;
        document.getElementById('statusSelect').value = currentStatus;
        document.getElementById('statusModal').classList.add('active');
    }
    
    function closeStatusModal() {
        document.getElementById('statusModal').classList.remove('active');
    }
    
    function showRefundModal(bookingId, amount) {
        document.getElementById('refundBookingId').value = bookingId;
        document.getElementById('refundAmount').value = amount;
        document.getElementById('refundModal').classList.add('active');
    }
    
    function closeRefundModal() {
        document.getElementById('refundModal').classList.remove('active');
    }
    
    // Close modals when clicking outside
    window.addEventListener('click', function(event) {
        const statusModal = document.getElementById('statusModal');
        const refundModal = document.getElementById('refundModal');
        
        if (event.target === statusModal && statusModal.classList.contains('active')) {
            statusModal.classList.remove('active');
        }
        if (event.target === refundModal && refundModal.classList.contains('active')) {
            refundModal.classList.remove('active');
        }
    });
</script>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script>
    $(document).ready(function() {
        $('#bookingsTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                search: "<i class='fas fa-search'></i> Search:",
                lengthMenu: "_MENU_ entries per page",
                info: "Showing _START_ to _END_ of _TOTAL_ bookings",
                paginate: {
                    first: "<i class='fas fa-angle-double-left'></i>",
                    last: "<i class='fas fa-angle-double-right'></i>",
                    next: "<i class='fas fa-angle-right'></i>",
                    previous: "<i class='fas fa-angle-left'></i>"
                }
            }
        });
    });
</script>

<?php include 'includes/footer.php'; ?>