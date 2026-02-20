<?php
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$user_id = $_SESSION['user_id'];

// Get all reservations for the user with proper joins
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

$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$reservations = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>My Reservations - ParkEase</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #F9FAFB;
            padding: 40px 20px;
        }
        .container {
            max-width: 1200px;
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
            box-shadow: 0 2px 4px rgba(0,0,0,0.05);
        }
        .back-link:hover {
            background: #F3F4F6;
        }
        .reservations-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(350px, 1fr));
            gap: 20px;
        }
        .reservation-card {
            background: white;
            border-radius: 16px;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(0,0,0,0.05);
            transition: transform 0.3s, box-shadow 0.3s;
        }
        .reservation-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 30px rgba(0,0,0,0.1);
        }
        .reservation-image {
            height: 160px;
            overflow: hidden;
            position: relative;
        }
        .reservation-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .status-badge {
            position: absolute;
            top: 12px;
            right: 12px;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .status-pending { background: #FEF3C7; color: #D97706; }
        .status-confirmed { background: #DCFCE7; color: #16A34A; }
        .status-active { background: #DBEAFE; color: #2563EB; }
        .status-completed { background: #E5E7EB; color: #4B5563; }
        .status-cancelled { background: #FEE2E2; color: #DC2626; }
        
        .reservation-content {
            padding: 20px;
        }
        .parking-name {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 5px;
        }
        .parking-location {
            display: flex;
            align-items: center;
            gap: 4px;
            color: #6B7280;
            font-size: 13px;
            margin-bottom: 15px;
        }
        .booking-ref {
            background: #F3F4F6;
            padding: 4px 10px;
            border-radius: 6px;
            font-size: 12px;
            font-family: monospace;
            margin-bottom: 15px;
            display: inline-block;
        }
        .dates {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            padding: 12px 0;
            border-top: 1px solid #F3F4F6;
            border-bottom: 1px solid #F3F4F6;
        }
        .date-item {
            text-align: center;
            flex: 1;
        }
        .date-label {
            font-size: 11px;
            color: #6B7280;
            margin-bottom: 4px;
            text-transform: uppercase;
            letter-spacing: 0.5px;
        }
        .date-value {
            font-size: 13px;
            font-weight: 600;
            color: #111827;
        }
        .amount {
            font-size: 20px;
            font-weight: 700;
            color: #4F6EF7;
            margin: 10px 0;
        }
        .amount::before {
            content: '₦';
            font-size: 14px;
            margin-right: 2px;
        }
        .view-btn {
            display: block;
            text-align: center;
            padding: 12px;
            background: #4F6EF7;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s;
        }
        .view-btn:hover {
            background: #3a56d4;
        }
        .empty-state {
            text-align: center;
            padding: 60px;
            background: white;
            border-radius: 20px;
            grid-column: 1 / -1;
        }
        .empty-state svg {
            width: 80px;
            height: 80px;
            margin-bottom: 20px;
            opacity: 0.5;
        }
        .empty-state h3 {
            color: #374151;
            margin-bottom: 10px;
        }
        .empty-state p {
            color: #6B7280;
            margin-bottom: 20px;
        }
        .find-parking-btn {
            display: inline-block;
            padding: 12px 24px;
            background: #4F6EF7;
            color: white;
            text-decoration: none;
            border-radius: 8px;
            font-weight: 500;
            transition: background 0.3s;
        }
        .find-parking-btn:hover {
            background: #3a56d4;
        }
        
        /* Payment status badge */
        .payment-badge {
            display: inline-block;
            padding: 4px 12px;
            border-radius: 20px;
            font-size: 11px;
            font-weight: 600;
            text-transform: uppercase;
            letter-spacing: 0.5px;
            margin-left: 8px;
        }
        .payment-pending { background: #FEF3C7; color: #D97706; }
        .payment-paid { background: #DCFCE7; color: #16A34A; }
        .payment-refunded { background: #E5E7EB; color: #4B5563; }
        
        @media (max-width: 768px) {
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            .reservations-grid {
                grid-template-columns: 1fr;
            }
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
                <svg viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="1.5">
                    <rect x="3" y="4" width="18" height="18" rx="2" ry="2"/>
                    <line x1="16" y1="2" x2="16" y2="6"/>
                    <line x1="8" y1="2" x2="8" y2="6"/>
                    <line x1="3" y1="10" x2="21" y2="10"/>
                </svg>
                <h3>No reservations yet</h3>
                <p>Start by finding a parking space that suits your needs.</p>
                <a href="index.php" class="find-parking-btn">Find Parking →</a>
            </div>
        <?php else: ?>
            <div class="reservations-grid">
                <?php foreach ($reservations as $res): 
                    $images = !empty($res['images']) ? json_decode($res['images'], true) : [];
                    $image = !empty($images) ? 'uploads/parking/' . $images[0] : 'img/parking-placeholder.jpg';
                    
                    // Format dates
                    $start_date = new DateTime($res['start_date']);
                    $end_date = new DateTime($res['end_date']);
                ?>
                <div class="reservation-card">
                    <div class="reservation-image">
                        <img src="<?php echo htmlspecialchars($image); ?>" 
                             alt="<?php echo htmlspecialchars($res['parking_name']); ?>"
                             onerror="this.src='img/parking-placeholder.jpg'">
                        <span class="status-badge status-<?php echo $res['status']; ?>">
                            <?php echo ucfirst($res['status']); ?>
                        </span>
                    </div>
                    
                    <div class="reservation-content">
                        <h3 class="parking-name"><?php echo htmlspecialchars($res['parking_name']); ?></h3>
                        
                        <div class="parking-location">
                            <svg width="12" height="12" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                                <path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/>
                                <circle cx="12" cy="10" r="3"/>
                            </svg>
                            <?php echo htmlspecialchars($res['city'] ?: 'Location not specified'); ?>
                        </div>
                        
                        <div style="display: flex; align-items: center; justify-content: space-between; margin-bottom: 10px;">
                            <span class="booking-ref"><?php echo htmlspecialchars($res['booking_reference'] ?? 'N/A'); ?></span>
                            <span class="payment-badge payment-<?php echo $res['payment_status']; ?>">
                                <?php echo ucfirst($res['payment_status']); ?>
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
                        
                        <div class="amount"><?php echo number_format($res['total_amount'], 2); ?></div>
                        
                        <a href="reservation-details.php?id=<?php echo $res['id']; ?>" class="view-btn">
                            View Details
                        </a>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    </div>
</body>
</html>