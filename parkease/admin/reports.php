<?php
require_once '../config/database.php';
require_once 'includes/auth.php';

requireAdminLogin();

$database = new Database();
$db = $database->getConnection();

$report_type = $_GET['type'] ?? 'bookings';
$format = $_GET['format'] ?? 'html';
$date_from = $_GET['from'] ?? date('Y-m-d', strtotime('-30 days'));
$date_to = $_GET['to'] ?? date('Y-m-d');

// Get data based on report type
switch ($report_type) {
    case 'bookings':
        $query = "SELECT 
                  r.booking_reference,
                  CONCAT(u.first_name, ' ', u.last_name) as customer_name,
                  u.email as customer_email,
                  CONCAT(o.first_name, ' ', o.last_name) as owner_name,
                  ps.name as parking_name,
                  ps.address,
                  r.start_date,
                  r.end_date,
                  r.total_hours,
                  r.total_amount,
                  r.commission_amount,
                  r.owner_payout,
                  r.status,
                  r.payment_status,
                  r.created_at
                  FROM reservations r
                  JOIN users u ON r.user_id = u.id
                  JOIN users o ON r.owner_id = o.id
                  JOIN parking_spaces ps ON r.parking_id = ps.id
                  WHERE DATE(r.created_at) BETWEEN :from AND :to
                  ORDER BY r.created_at DESC";
        break;
        
    case 'earnings':
        $query = "SELECT 
                  DATE(r.created_at) as date,
                  COUNT(*) as total_bookings,
                  SUM(r.total_amount) as gross_revenue,
                  SUM(r.commission_amount) as platform_commission,
                  SUM(r.owner_payout) as owner_payouts,
                  AVG(r.total_amount) as average_booking
                  FROM reservations r
                  WHERE DATE(r.created_at) BETWEEN :from AND :to
                  GROUP BY DATE(r.created_at)
                  ORDER BY date DESC";
        break;
        
    case 'users':
        $query = "SELECT 
                  id,
                  user_type,
                  first_name,
                  last_name,
                  email,
                  phone,
                  company_name,
                  is_active,
                  created_at
                  FROM users
                  WHERE DATE(created_at) BETWEEN :from AND :to
                  ORDER BY created_at DESC";
        break;
        
    case 'payouts':
        $query = "SELECT 
                  p.*,
                  u.first_name,
                  u.last_name,
                  u.email
                  FROM owner_payouts p
                  JOIN users u ON p.owner_id = u.id
                  WHERE DATE(p.created_at) BETWEEN :from AND :to
                  ORDER BY p.created_at DESC";
        break;
}

$stmt = $db->prepare($query);
$stmt->bindParam(':from', $date_from);
$stmt->bindParam(':to', $date_to);
$stmt->execute();
$data = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Handle Excel export
if ($format == 'excel') {
    header('Content-Type: application/vnd.ms-excel');
    header('Content-Disposition: attachment; filename="spacenode_' . $report_type . '_' . $date_from . '_to_' . $date_to . '.xls"');
    
    echo "<table border='1'>";
    
    echo "<tr>";
    if (!empty($data)) {
        foreach (array_keys($data[0]) as $header) {
            echo "<th>" . str_replace('_', ' ', ucwords($header)) . "</th>";
        }
    }
    echo "</tr>";
    
    foreach ($data as $row) {
        echo "<tr>";
        foreach ($row as $value) {
            echo "<td>" . htmlspecialchars($value) . "</td>";
        }
        echo "</tr>";
    }
    
    echo "</table>";
    exit();
}

// Handle PDF export
if ($format == 'pdf') {
    if (!file_exists('../vendor/autoload.php')) {
        die('mPDF not installed. Please run: composer require mpdf/mpdf');
    }
    
    require_once '../vendor/autoload.php';
    
    $mpdf = new \Mpdf\Mpdf(['format' => 'A4-L']);
    
    $html = "<h1>SpaceNode Report - " . ucfirst($report_type) . "</h1>";
    $html .= "<p>From: $date_from To: $date_to</p>";
    $html .= "<table border='1' cellpadding='5' style='border-collapse: collapse; width: 100%;'>";
    
    $html .= "<tr style='background: #4F6EF7; color: white;'>";
    if (!empty($data)) {
        foreach (array_keys($data[0]) as $header) {
            $html .= "<th>" . str_replace('_', ' ', ucwords($header)) . "</th>";
        }
    }
    $html .= "<tr>";
    
    foreach ($data as $row) {
        $html .= "<tr>";
        foreach ($row as $value) {
            $html .= "<td>" . htmlspecialchars($value) . "</td>";
        }
        $html .= "</tr>";
    }
    
    $html .= "</table>";
    
    $mpdf->WriteHTML($html);
    $mpdf->Output('spacenode_report.pdf', 'D');
    exit();
}

$page_title = 'Reports';
include 'includes/header.php';
?>

<!-- Custom Styles for Reports Page -->
<style>
    .report-filters {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 24px;
        padding: 24px;
        margin-bottom: 30px;
        transition: all 0.3s ease;
    }
    
    .report-filters:hover {
        background: rgba(255,255,255,0.12);
        border-color: rgba(255,255,255,0.25);
    }
    
    .filter-label-icon {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 8px;
        font-size: 12px;
        font-weight: 500;
        color: rgba(255,255,255,0.7);
    }
    
    .filter-label-icon i {
        color: #a5b4fc;
    }
    
    .report-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .report-stat-card {
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
    
    .report-stat-card:nth-child(1) { animation-delay: 0.05s; }
    .report-stat-card:nth-child(2) { animation-delay: 0.1s; }
    .report-stat-card:nth-child(3) { animation-delay: 0.15s; }
    .report-stat-card:nth-child(4) { animation-delay: 0.2s; }
    
    .report-stat-card:hover {
        transform: translateY(-5px);
        background: rgba(255,255,255,0.12);
        border-color: rgba(255,255,255,0.3);
        box-shadow: 0 20px 48px rgba(0,0,0,0.3);
    }
    
    .report-stat-card h3 {
        font-size: 13px;
        font-weight: 500;
        color: rgba(255,255,255,0.7);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .report-stat-card h3 i {
        color: #a5b4fc;
    }
    
    .report-stat-number {
        font-family: 'Outfit', sans-serif;
        font-size: 32px;
        font-weight: 800;
        background: linear-gradient(135deg, #fff, #a5b4fc);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    
    .stat-revenue {
        background: linear-gradient(135deg, #fff, #60a5fa);
        -webkit-background-clip: text;
        background-clip: text;
    }
    
    .stat-commission {
        background: linear-gradient(135deg, #fff, #4ade80);
        -webkit-background-clip: text;
        background-clip: text;
    }
    
    .stat-payout {
        background: linear-gradient(135deg, #fff, #fbbf24);
        -webkit-background-clip: text;
        background-clip: text;
    }
    
    .table-section {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 24px;
        padding: 24px;
        transition: all 0.3s ease;
        overflow: hidden;
    }
    
    .table-section:hover {
        background: rgba(255,255,255,0.12);
        border-color: rgba(255,255,255,0.25);
    }
    
    .table-section h2 {
        font-family: 'Outfit', sans-serif;
        font-size: 18px;
        font-weight: 600;
        background: linear-gradient(135deg, #fff, #a5b4fc);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 20px;
        display: flex;
        align-items: center;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .record-count {
        background: rgba(255,255,255,0.1);
        border-radius: 50px;
        padding: 4px 12px;
        font-size: 12px;
        color: rgba(255,255,255,0.7);
        margin-left: 10px;
    }
    
    .amount-positive {
        color: #4ade80;
        font-weight: 600;
    }
    
    /* FIXED: Responsive table container */
    .table-responsive-wrapper {
        position: relative;
        width: 100%;
        max-width: 100%;
        overflow-x: auto;
        -webkit-overflow-scrolling: touch;
        margin: 0;
        padding: 0;
    }
    
    .table-responsive-wrapper::-webkit-scrollbar {
        height: 6px;
    }
    
    .table-responsive-wrapper::-webkit-scrollbar-track {
        background: rgba(255,255,255,0.05);
        border-radius: 10px;
    }
    
    .table-responsive-wrapper::-webkit-scrollbar-thumb {
        background: rgba(165,180,252,0.4);
        border-radius: 10px;
    }
    
    #reportTable {
        min-width: 100%;
        width: 100%;
        border-collapse: collapse;
        table-layout: auto;
    }
    
    #reportTable th,
    #reportTable td {
        padding: 12px 16px;
        white-space: nowrap;
        min-width: 100px;
    }
    
    .report-filters .form-group,
    .report-filters .btn,
    .report-filters .btn a {
        min-width: 0;
        flex: 1 1 180px;
    }
    
    .report-filters form {
        align-items: flex-end;
    }
    
    /* Allow some columns to wrap on larger screens */
    @media (min-width: 1200px) {
        #reportTable td:nth-child(3),
        #reportTable td:nth-child(4),
        #reportTable td:nth-child(5) {
            white-space: normal;
            min-width: 150px;
        }
    }
    
    /* DataTables custom styling */
    .dataTables_wrapper {
        overflow-x: auto;
    }
    
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
    
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
        margin-top: 15px;
    }
    
    @media (max-width: 1100px) {
        .report-stats {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 768px) {
        .report-stats {
            grid-template-columns: 1fr;
        }
        
        .report-stat-number {
            font-size: 28px;
        }
        
        .report-filters form {
            flex-direction: column;
        }
        
        .report-filters .form-group,
        .report-filters .btn {
            width: 100%;
        }
        
        .report-filters .form-group {
            min-width: 0;
        }
        
        .filter-buttons {
            flex-wrap: wrap;
        }
        
        .table-section {
            padding: 16px;
        }
        
        .table-responsive-wrapper,
        #reportTable {
            min-width: auto;
        }
        
        #reportTable th,
        #reportTable td {
            white-space: normal;
            word-break: break-word;
        }
    }
    
    @media (max-width: 480px) {
        .report-stat-number {
            font-size: 24px;
        }
        
        .report-stat-card {
            padding: 18px;
        }
        
        .table-section {
            padding: 12px;
        }
        
        #reportTable th,
        #reportTable td {
            padding: 8px 12px;
            font-size: 12px;
        }
    }
</style>

<!-- Top Bar -->
<div class="top-bar">
    <div class="page-title">
        <h1><i class="fas fa-chart-line"></i> Reports & Analytics</h1>
    </div>
    <div class="admin-info">
        <span class="admin-badge"><i class="fas fa-shield-alt"></i> <?php echo $_SESSION['admin_role']; ?></span>
        <span class="admin-name"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<!-- Glass Report Filters -->
<div class="report-filters">
    <form method="GET" style="display: flex; gap: 20px; align-items: flex-end; flex-wrap: wrap;">
        <div class="form-group" style="margin: 0; flex: 1; min-width: 150px;">
            <div class="filter-label-icon">
                <i class="fas fa-chart-pie"></i>
                <label>Report Type</label>
            </div>
            <select name="type" class="form-control">
                <option value="bookings" <?php echo $report_type == 'bookings' ? 'selected' : ''; ?>>📋 Bookings Report</option>
                <option value="earnings" <?php echo $report_type == 'earnings' ? 'selected' : ''; ?>>💰 Earnings Report</option>
                <option value="users" <?php echo $report_type == 'users' ? 'selected' : ''; ?>>👥 Users Report</option>
                <option value="payouts" <?php echo $report_type == 'payouts' ? 'selected' : ''; ?>>💸 Payouts Report</option>
            </select>
        </div>
        
        <div class="form-group" style="margin: 0; min-width: 160px;">
            <div class="filter-label-icon">
                <i class="fas fa-calendar-alt"></i>
                <label>From Date</label>
            </div>
            <input type="date" name="from" value="<?php echo $date_from; ?>" class="form-control">
        </div>
        
        <div class="form-group" style="margin: 0; min-width: 160px;">
            <div class="filter-label-icon">
                <i class="fas fa-calendar-alt"></i>
                <label>To Date</label>
            </div>
            <input type="date" name="to" value="<?php echo $date_to; ?>" class="form-control">
        </div>
        
        <div style="display: flex; gap: 12px; flex-wrap: wrap;">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-sync-alt"></i> Generate Report
            </button>
            <a href="?type=<?php echo $report_type; ?>&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>&format=excel" class="btn btn-success">
                <i class="fas fa-file-excel"></i> Export Excel
            </a>
            <a href="?type=<?php echo $report_type; ?>&from=<?php echo $date_from; ?>&to=<?php echo $date_to; ?>&format=pdf" class="btn btn-danger">
                <i class="fas fa-file-pdf"></i> Export PDF
            </a>
        </div>
    </form>
</div>

<!-- Summary Cards -->
<div class="report-stats">
    <div class="report-stat-card">
        <h3><i class="fas fa-database"></i> Total Records</h3>
        <div class="report-stat-number"><?php echo count($data); ?></div>
    </div>
    
    <?php if ($report_type == 'earnings'): ?>
    <?php 
        $total_gross = array_sum(array_column($data, 'gross_revenue'));
        $total_commission = array_sum(array_column($data, 'platform_commission'));
        $total_payouts = array_sum(array_column($data, 'owner_payouts'));
    ?>
    <div class="report-stat-card">
        <h3><i class="fas fa-chart-line"></i> Gross Revenue</h3>
        <div class="report-stat-number stat-revenue">₦<?php echo number_format($total_gross, 2); ?></div>
    </div>
    <div class="report-stat-card">
        <h3><i class="fas fa-percent"></i> Platform Commission</h3>
        <div class="report-stat-number stat-commission">₦<?php echo number_format($total_commission, 2); ?></div>
    </div>
    <div class="report-stat-card">
        <h3><i class="fas fa-hand-holding-usd"></i> Owner Payouts</h3>
        <div class="report-stat-number stat-payout">₦<?php echo number_format($total_payouts, 2); ?></div>
    </div>
    <?php endif; ?>
</div>

<!-- Glass Report Table with Fixed Responsive Wrapper -->
<div class="table-section">
    <h2>
        <i class="fas fa-table"></i> 
        <?php echo ucfirst($report_type); ?> Report Data
        <span class="record-count"><?php echo count($data); ?> records</span>
    </h2>
    
    <div class="table-responsive-wrapper">
        <table id="reportTable">
            <thead>
                <tr>
                    <?php if (!empty($data)): ?>
                        <?php foreach (array_keys($data[0]) as $header): ?>
                            <th>
                                <i class="fas <?php
                                    echo str_contains($header, 'date') ? 'fa-calendar' :
                                        (str_contains($header, 'amount') ? 'fa-money-bill-wave' :
                                        (str_contains($header, 'status') ? 'fa-chart-simple' :
                                        (str_contains($header, 'email') ? 'fa-envelope' :
                                        (str_contains($header, 'name') ? 'fa-user' : 'fa-hashtag')))); ?>"></i>
                                <?php echo str_replace('_', ' ', ucwords($header)); ?>
                            </th>
                        <?php endforeach; ?>
                    <?php endif; ?>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($data as $row): ?>
                <tr>
                    <?php foreach ($row as $key => $value): ?>
                        <td>
                            <?php 
                            // Format currency values
                            if (is_numeric($value) && (strpos($key, 'amount') !== false || strpos($key, 'revenue') !== false || strpos($key, 'commission') !== false || strpos($key, 'payout') !== false)) {
                                echo '<span class="amount-positive">₦' . number_format($value, 2) . '</span>';
                            } 
                            // Format total bookings
                            elseif (is_numeric($value) && strpos($key, 'total') !== false && !strpos($key, 'amount')) {
                                echo number_format($value);
                            }
                            // Format dates
                            elseif (strtotime($value) && (strpos($key, 'date') !== false || strpos($key, 'created') !== false)) {
                                echo '<i class="fas fa-calendar-alt"></i> ' . date('M d, Y H:i', strtotime($value));
                            }
                            // Format status badges
                            elseif (strpos($key, 'status') !== false || strpos($key, 'payment') !== false) {
                                $status_value = strtolower($value);
                                $badge_class = 'badge-info';
                                $icon = 'fa-info-circle';
                                
                                if ($status_value == 'completed' || $status_value == 'paid' || $status_value == 'active') {
                                    $badge_class = 'badge-success';
                                    $icon = 'fa-check-circle';
                                } elseif ($status_value == 'pending' || $status_value == 'processing') {
                                    $badge_class = 'badge-warning';
                                    $icon = 'fa-clock';
                                } elseif ($status_value == 'cancelled' || $status_value == 'rejected') {
                                    $badge_class = 'badge-danger';
                                    $icon = 'fa-times-circle';
                                } elseif ($status_value == 'confirmed') {
                                    $badge_class = 'badge-info';
                                    $icon = 'fa-info-circle';
                                }
                                echo '<span class="badge ' . $badge_class . '"><i class="fas ' . $icon . '"></i> ' . ucfirst($value) . '</span>';
                            }
                            // Format active/inactive
                            elseif (strpos($key, 'is_active') !== false) {
                                echo $value == 1 ? '<span class="badge badge-success"><i class="fas fa-check-circle"></i> Active</span>' : '<span class="badge badge-danger"><i class="fas fa-times-circle"></i> Inactive</span>';
                            }
                            // Format user type
                            elseif (strpos($key, 'user_type') !== false) {
                                echo $value == 'owner' ? '<span class="badge badge-info"><i class="fas fa-building"></i> Owner</span>' : '<span class="badge badge-success"><i class="fas fa-user"></i> Parker</span>';
                            }
                            // Regular text
                            else {
                                echo htmlspecialchars($value);
                            }
                            ?>
                        </td>
                    <?php endforeach; ?>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script>
    $(document).ready(function() {
        $('#reportTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            scrollX: true,
            autoWidth: false,
            responsive: true,
            language: {
                search: "<i class='fas fa-search'></i> Search:",
                lengthMenu: "_MENU_ entries per page",
                info: "Showing _START_ to _END_ of _TOTAL_ records",
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