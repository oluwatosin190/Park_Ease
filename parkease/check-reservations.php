<?php
session_start(); // Start session at the beginning
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    // Return JSON error for API-like response
    header('Content-Type: application/json');
    echo json_encode(['error' => 'Please login first']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get the most recent reservations for the logged-in user
$query = "SELECT r.id, r.booking_reference, r.total_amount, r.status, r.payment_status, 
          r.start_date, r.end_date, r.created_at,
          ps.name as parking_name, ps.address, ps.city
          FROM reservations r
          JOIN parking_spaces ps ON r.parking_id = ps.id
          WHERE r.user_id = :user_id
          ORDER BY r.created_at DESC
          LIMIT 5";

try {
    $stmt = $db->prepare($query);
    $stmt->bindParam(':user_id', $_SESSION['user_id'], PDO::PARAM_INT);
    $stmt->execute();
    $reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (PDOException $e) {
    error_log("Check reservations query error: " . $e->getMessage());
    die('Database error occurred. Please try again later.');
}

// Status badge colors for better UI
function getStatusBadge($status) {
    $badges = [
        'pending' => '<span class="badge badge-warning">Pending</span>',
        'confirmed' => '<span class="badge badge-info">Confirmed</span>',
        'active' => '<span class="badge badge-success">Active</span>',
        'completed' => '<span class="badge badge-secondary">Completed</span>',
        'cancelled' => '<span class="badge badge-danger">Cancelled</span>'
    ];
    return $badges[$status] ?? '<span class="badge badge-light">' . sanitize($status) . '</span>';
}

function getPaymentBadge($status) {
    $badges = [
        'pending' => '<span class="badge badge-warning">Pending</span>',
        'paid' => '<span class="badge badge-success">Paid</span>',
        'refunded' => '<span class="badge badge-info">Refunded</span>',
        'failed' => '<span class="badge badge-danger">Failed</span>'
    ];
    return $badges[$status] ?? '<span class="badge badge-light">' . sanitize($status) . '</span>';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Your Reservations - SpaceNode</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #F9FAFB;
            padding: 40px 20px;
        }
        .container {
            max-width: 1000px;
            margin: 0 auto;
        }
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
            transition: all 0.2s;
        }
        .back-link:hover {
            background: #F3F4F6;
        }
        .reservations-card {
            background: white;
            border-radius: 16px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            overflow: hidden;
        }
        .reservations-table {
            width: 100%;
            border-collapse: collapse;
        }
        .reservations-table th {
            background: #F9FAFB;
            padding: 15px;
            text-align: left;
            font-size: 13px;
            font-weight: 600;
            color: #6B7280;
            border-bottom: 1px solid #E5E7EB;
        }
        .reservations-table td {
            padding: 15px;
            font-size: 14px;
            color: #111827;
            border-bottom: 1px solid #F3F4F6;
        }
        .reservations-table tr:hover td {
            background: #F9FAFB;
        }
        .badge {
            display: inline-block;
            padding: 4px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .badge-warning {
            background: #FEF3C7;
            color: #D97706;
        }
        .badge-info {
            background: #DBEAFE;
            color: #2563EB;
        }
        .badge-success {
            background: #DCFCE7;
            color: #16A34A;
        }
        .badge-secondary {
            background: #E5E7EB;
            color: #4B5563;
        }
        .badge-danger {
            background: #FEE2E2;
            color: #DC2626;
        }
        .badge-light {
            background: #F3F4F6;
            color: #374151;
        }
        .empty-state {
            text-align: center;
            padding: 60px 20px;
        }
        .empty-state svg {
            width: 80px;
            height: 80px;
            color: #9CA3AF;
            margin-bottom: 20px;
        }
        .empty-state h3 {
            font-size: 20px;
            color: #374151;
            margin-bottom: 10px;
        }
        .empty-state p {
            color: #6B7280;
            margin-bottom: 20px;
        }
        .btn-primary {
            display: inline-block;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            padding: 10px 24px;
            border-radius: 8px;
            text-decoration: none;
            font-weight: 500;
            transition: transform 0.2s;
        }
        .btn-primary:hover {
            transform: translateY(-2px);
        }
        .view-link {
            color: #4F6EF7;
            text-decoration: none;
            font-weight: 500;
        }
        .view-link:hover {
            text-decoration: underline;
        }
        .amount {
            font-weight: 600;
            color: #4F6EF7;
        }
        @media (max-width: 768px) {
            body {
                padding: 20px 15px;
            }
            .header h1 {
                font-size: 22px;
            }
            .reservations-table {
                display: block;
                overflow-x: auto;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Your Recent Reservations</h1>
            <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        </div>
        
        <div class="reservations-card">
            <?php if (empty($reservations)): ?>
                <div class="empty-state">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.5">
                        <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                        <line x1="16" y1="2" x2="16" y2="6"/>
                        <line x1="8" y1="2" x2="8" y2="6"/>
                        <line x1="3" y1="10" x2="21" y2="10"/>
                    </svg>
                    <h3>No reservations found</h3>
                    <p>You haven't made any parking reservations yet.</p>
                    <a href="index.php" class="btn-primary">Find Parking</a>
                </div>
            <?php else: ?>
                <div style="overflow-x: auto;">
                    <table class="reservations-table">
                        <thead>
                            <tr>
                                <th>Reference</th>
                                <th>Parking Space</th>
                                <th>Amount</th>
                                <th>Status</th>
                                <th>Payment</th>
                                <th>Action</th>
                            </tr>
                        </thead>
                        <tbody>
                            <?php foreach ($reservations as $r): ?>
                            <tr>
                                <td>
                                    <strong><?php echo sanitize($r['booking_reference']); ?></strong>
                                    <br>
                                    <small style="color: #6B7280;"><?php echo date('M d, Y', strtotime($r['created_at'])); ?></small>
                                </td>
                                <td>
                                    <?php echo sanitize($r['parking_name']); ?>
                                    <br>
                                    <small style="color: #6B7280;"><?php echo sanitize($r['city'] ?? ''); ?></small>
                                </td>
                                <td class="amount">₦<?php echo number_format($r['total_amount'], 2); ?></td>
                                <td><?php echo getStatusBadge($r['status']); ?></td>
                                <td><?php echo getPaymentBadge($r['payment_status']); ?></td>
                                <td>
                                    <a href="reservation-details.php?id=<?php echo (int)$r['id']; ?>" class="view-link">View Details →</a>
                                </td>
                            </tr>
                            <?php endforeach; ?>
                        </tbody>
                    </table>
                </div>
                
                <div style="padding: 15px; text-align: center; border-top: 1px solid #E5E7EB;">
                    <a href="my-reservations.php" class="view-link">View All Reservations →</a>
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>