<?php
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$reservation_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get reservation details
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
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $reservation_id);
$stmt->bindParam(':user_id', $_SESSION['user_id']);
$stmt->execute();
$reservation = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$reservation) {
    header('Location: dashboard.php');
    exit();
}

$images = !empty($reservation['images']) ? json_decode($reservation['images'], true) : [];
$main_image = !empty($images) ? 'uploads/parking/' . $images[0] : 'img/parking-placeholder.jpg';

// Check if user can cancel (within 1 hour of start time)
$start = new DateTime($reservation['start_date']);
$now = new DateTime();
$can_cancel = ($reservation['status'] == 'pending' || $reservation['status'] == 'confirmed') && 
               ($now < $start->sub(new DateInterval('PT1H')));
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Reservation Details - ParkEase</title>
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
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #4F6EF7;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        .reservation-card {
            background: white;
            border-radius: 20px;
            overflow: hidden;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .reservation-header {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            padding: 30px;
        }
        .reservation-header h1 {
            font-size: 24px;
            margin-bottom: 10px;
        }
        .booking-reference {
            display: inline-block;
            background: rgba(255,255,255,0.2);
            padding: 8px 16px;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 600;
            letter-spacing: 1px;
        }
        .reservation-body {
            padding: 30px;
        }
        .status-badge {
            display: inline-block;
            padding: 6px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 600;
            text-transform: uppercase;
        }
        .status-pending { background: #FEF3C7; color: #D97706; }
        .status-confirmed { background: #DCFCE7; color: #16A34A; }
        .status-active { background: #DBEAFE; color: #2563EB; }
        .status-completed { background: #E5E7EB; color: #4B5563; }
        .status-cancelled { background: #FEE2E2; color: #DC2626; }
        
        .info-grid {
            display: grid;
            grid-template-columns: repeat(2, 1fr);
            gap: 30px;
            margin: 30px 0;
        }
        .info-section {
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 20px;
        }
        .info-section h3 {
            font-size: 16px;
            margin-bottom: 15px;
            color: #111827;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        .info-row {
            display: flex;
            margin-bottom: 12px;
            font-size: 14px;
        }
        .info-label {
            width: 120px;
            color: #6B7280;
        }
        .info-value {
            flex: 1;
            color: #111827;
            font-weight: 500;
        }
        .parking-image {
            width: 100%;
            height: 200px;
            border-radius: 12px;
            overflow: hidden;
            margin-top: 15px;
        }
        .parking-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .amount-large {
            font-size: 32px;
            font-weight: 700;
            color: #4F6EF7;
            margin: 10px 0;
        }
        .amount-large::before {
            content: '₦';
            font-size: 20px;
            margin-right: 5px;
        }
        .action-buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        .btn {
            padding: 12px 24px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
        }
        .btn-primary {
            background: #4F6EF7;
            color: white;
        }
        .btn-primary:hover {
            background: #3a56d4;
        }
        .btn-danger {
            background: #FEE2E2;
            color: #DC2626;
        }
        .btn-danger:hover {
            background: #FECACA;
        }
        .btn-secondary {
            background: #F3F4F6;
            color: #374151;
        }
        .btn-secondary:hover {
            background: #E5E7EB;
        }
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-success {
            background: #DCFCE7;
            color: #16A34A;
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        
        <?php if (isset($_SESSION['success'])): ?>
            <div class="alert alert-success"><?php echo $_SESSION['success']; unset($_SESSION['success']); ?></div>
        <?php endif; ?>
        
        <div class="reservation-card">
            <div class="reservation-header">
                <h1>Reservation Details</h1>
                <span class="booking-reference"><?php echo htmlspecialchars($reservation['booking_reference']); ?></span>
            </div>
            
            <div class="reservation-body">
                <div style="display: flex; justify-content: space-between; align-items: center; margin-bottom: 20px;">
                    <span class="status-badge status-<?php echo $reservation['status']; ?>">
                        <?php echo ucfirst($reservation['status']); ?>
                    </span>
                    <span class="status-badge" style="background: #F3F4F6; color: #4F6EF7;">
                        Payment: <?php echo ucfirst($reservation['payment_status']); ?>
                    </span>
                </div>
                
                <div class="info-grid">
                    <div class="info-section">
                        <h3>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4F6EF7" stroke-width="2">
                                <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                                <line x1="16" y1="2" x2="16" y2="6"/>
                                <line x1="8" y1="2" x2="8" y2="6"/>
                                <line x1="3" y1="10" x2="21" y2="10"/>
                            </svg>
                            Booking Details
                        </h3>
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
                            <span class="info-value"><?php echo number_format($reservation['total_hours'], 1); ?> hours</span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Rate Type</span>
                            <span class="info-value"><?php echo ucfirst($reservation['rate_type']); ?> (₦<?php echo number_format($reservation['rate_amount'], 0); ?>)</span>
                        </div>
                    </div>
                    
                    <div class="info-section">
                        <h3>
                            <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4F6EF7" stroke-width="2">
                                <path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/>
                                <circle cx="12" cy="7" r="4"/>
                            </svg>
                            Customer Details
                        </h3>
                        <div class="info-row">
                            <span class="info-label">Name</span>
                            <span class="info-value"><?php echo htmlspecialchars($reservation['first_name'] . ' ' . $reservation['last_name']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Email</span>
                            <span class="info-value"><?php echo htmlspecialchars($reservation['email']); ?></span>
                        </div>
                        <div class="info-row">
                            <span class="info-label">Phone</span>
                            <span class="info-value"><?php echo htmlspecialchars($reservation['phone'] ?: 'Not provided'); ?></span>
                        </div>
                        <?php if ($reservation['vehicle_number']): ?>
                        <div class="info-row">
                            <span class="info-label">Vehicle</span>
                            <span class="info-value"><?php echo htmlspecialchars($reservation['vehicle_number'] . ' - ' . $reservation['vehicle_model']); ?></span>
                        </div>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="info-section" style="margin-top: 20px;">
                    <h3>
                        <svg width="20" height="20" viewBox="0 0 24 24" fill="none" stroke="#4F6EF7" stroke-width="2">
                            <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                            <circle cx="12" cy="10" r="3"/>
                        </svg>
                        Parking Space
                    </h3>
                    <div style="display: grid; grid-template-columns: 1fr 1fr; gap: 20px;">
                        <div>
                            <div class="info-row">
                                <span class="info-label">Name</span>
                                <span class="info-value"><?php echo htmlspecialchars($reservation['parking_name']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Location</span>
                                <span class="info-value"><?php echo htmlspecialchars($reservation['address'] . ', ' . $reservation['city']); ?></span>
                            </div>
                            <div class="info-row">
                                <span class="info-label">Owner</span>
                                <span class="info-value"><?php echo htmlspecialchars($reservation['owner_first_name'] . ' ' . $reservation['owner_last_name']); ?></span>
                            </div>
                        </div>
                        <div class="parking-image">
                            <img src="<?php echo $main_image; ?>" alt="">
                        </div>
                    </div>
                </div>
                
                <?php if ($reservation['special_requests']): ?>
                <div class="info-section" style="margin-top: 20px;">
                    <h3>Special Requests</h3>
                    <p style="color: #374151;"><?php echo nl2br(htmlspecialchars($reservation['special_requests'])); ?></p>
                </div>
                <?php endif; ?>
                
                <div style="background: #F9FAFB; border-radius: 12px; padding: 20px; margin: 20px 0;">
                    <div style="display: flex; justify-content: space-between; align-items: center;">
                        <div>
                            <div style="color: #6B7280; font-size: 14px;">Total Amount</div>
                            <div class="amount-large"><?php echo number_format($reservation['total_amount'], 2); ?></div>
                        </div>
                        <div style="text-align: right;">
                            <div style="color: #6B7280; font-size: 14px;">Payment Method</div>
                            <div style="font-weight: 600;"><?php echo ucfirst($reservation['payment_method_detail'] ?: $reservation['payment_method']); ?></div>
                        </div>
                    </div>
                </div>
                
                <div class="action-buttons">
                    <?php if ($_SESSION['user_id'] == $reservation['owner_id']): ?>
                        <!-- Owner actions -->
                        <?php if ($reservation['status'] == 'pending'): ?>
                            <a href="update-reservation-status.php?id=<?php echo $reservation_id; ?>&status=confirmed" class="btn btn-primary">Confirm Booking</a>
                            <a href="update-reservation-status.php?id=<?php echo $reservation_id; ?>&status=cancelled" class="btn btn-danger" onclick="return confirm('Are you sure you want to cancel this booking?')">Cancel Booking</a>
                        <?php endif; ?>
                        <a href="mailto:<?php echo $reservation['email']; ?>" class="btn btn-secondary">Contact Customer</a>
                    <?php else: ?>
                        <!-- Customer actions -->
                        <?php if ($can_cancel): ?>
                            <a href="cancel-reservation.php?id=<?php echo $reservation_id; ?>" class="btn btn-danger" onclick="return confirm('Are you sure you want to cancel this reservation?')">Cancel Reservation</a>
                        <?php endif; ?>
                        <a href="mailto:<?php echo $reservation['owner_email']; ?>" class="btn btn-secondary">Contact Owner</a>
                    <?php endif; ?>
                    <a href="dashboard.php" class="btn btn-secondary">Back to Dashboard</a>
                </div>
            </div>
        </div>
    </div>
</body>
</html>