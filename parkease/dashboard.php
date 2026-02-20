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
$user_type = $_SESSION['user_type'];

// Get user details
$query = "SELECT * FROM users WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $user_id);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

// Get user's reservations (for parkers) or parking spaces (for owners)
if ($user_type == 'parker') {
    $query = "SELECT r.*, p.name as parking_name, p.address 
              FROM reservations r 
              JOIN parking_spaces p ON r.parking_id = p.id 
              WHERE r.user_id = :user_id 
              ORDER BY r.created_at DESC 
              LIMIT 5";
} else {
    $query = "SELECT * FROM parking_spaces WHERE owner_id = :user_id ORDER BY created_at DESC";
}

$stmt = $db->prepare($query);
$stmt->bindParam(':user_id', $user_id);
$stmt->execute();
$items = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Dashboard - ParkEase</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #F9FAFB;
        }
        .dashboard-container {
            display: flex;
            min-height: 100vh;
        }
        
        /* Sidebar */
        .sidebar {
            width: 280px;
            background: white;
            border-right: 1px solid #E5E7EB;
            padding: 30px 20px;
        }
        .sidebar-logo {
            display: flex;
            align-items: center;
            gap: 10px;
            margin-bottom: 40px;
            text-decoration: none;
        }
        .sidebar-logo-icon {
            width: 36px;
            height: 36px;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .sidebar-logo-icon svg {
            width: 20px;
            height: 20px;
            fill: white;
        }
        .sidebar-logo-text h2 {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
        }
        .sidebar-logo-text p {
            font-size: 10px;
            color: #6B7280;
        }
        .sidebar-menu {
            list-style: none;
        }
        .sidebar-menu li {
            margin-bottom: 5px;
        }
        .sidebar-menu a {
            display: flex;
            align-items: center;
            gap: 12px;
            padding: 12px 16px;
            color: #6B7280;
            text-decoration: none;
            border-radius: 10px;
            transition: all 0.3s;
            font-size: 14px;
            font-weight: 500;
        }
        .sidebar-menu a:hover,
        .sidebar-menu a.active {
            background: #F3F4F6;
            color: #4F6EF7;
        }
        .sidebar-menu a svg {
            width: 20px;
            height: 20px;
        }
        .sidebar-menu .logout {
            margin-top: 40px;
            color: #DC2626;
        }
        .sidebar-menu .logout:hover {
            background: #FEE2E2;
        }
        
        /* Main Content */
        .main-content {
            flex: 1;
            padding: 30px 40px;
        }
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 30px;
        }
        .header h1 {
            font-size: 24px;
            font-weight: 700;
            color: #111827;
        }
        .header h1 span {
            font-size: 14px;
            font-weight: 400;
            color: #6B7280;
            margin-left: 10px;
        }
        .user-info {
            display: flex;
            align-items: center;
            gap: 20px;
        }
        .user-avatar {
            width: 40px;
            height: 40px;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            border-radius: 10px;
            display: flex;
            align-items: center;
            justify-content: center;
            color: white;
            font-weight: 600;
            font-size: 16px;
        }
        .user-details p {
            font-size: 14px;
            font-weight: 500;
            color: #111827;
        }
        .user-details small {
            font-size: 12px;
            color: #6B7280;
        }
        
        /* Stats Cards */
        .stats-grid {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 20px;
            margin-bottom: 30px;
        }
        .stat-card {
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 20px;
        }
        .stat-card h3 {
            font-size: 14px;
            font-weight: 500;
            color: #6B7280;
            margin-bottom: 10px;
        }
        .stat-card .number {
            font-size: 28px;
            font-weight: 700;
            color: #111827;
            margin-bottom: 5px;
        }
        .stat-card .trend {
            font-size: 12px;
            color: #22C55E;
        }
        
        /* Recent Items */
        .recent-section {
            background: white;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            padding: 20px;
        }
        .recent-header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
        }
        .recent-header h2 {
            font-size: 18px;
            font-weight: 600;
            color: #111827;
        }
        .recent-header a {
            color: #f3f4f6;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        .items-list {
            list-style: none;
        }
        .items-list li {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 15px 0;
            border-bottom: 1px solid #F3F4F6;
        }
        .items-list li:last-child {
            border-bottom: none;
        }
        .item-info h4 {
            font-size: 16px;
            font-weight: 600;
            color: #111827;
            margin-bottom: 5px;
        }
        .item-info p {
            font-size: 13px;
            color: #6B7280;
        }
        .item-status {
            padding: 5px 12px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 500;
        }
        .status-confirmed { background: #DCFCE7; color: #16A34A; }
        .status-pending { background: #FEF3C7; color: #D97706; }
        .status-cancelled { background: #FEE2E2; color: #DC2626; }
        
        .empty-state {
            text-align: center;
            padding: 40px;
            color: #6B7280;
        }
        .empty-state svg {
            width: 60px;
            height: 60px;
            margin-bottom: 20px;
            opacity: 0.5;
        }

        .btn-add {
            background: #4F6EF7;
            color: white;
            padding: 8px 16px;
            border-radius: 8px;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: background 0.3s;
        }
        .btn-add:hover {
            background: #3a56d4;
        }
        .parking-spaces-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(300px, 1fr));
            gap: 20px;
            margin-top: 20px;
        }
        .parking-space-card {
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            overflow: hidden;
            transition: transform 0.3s, box-shadow 0.3s;
            background: white;
        }
        .parking-space-card:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 25px rgba(0,0,0,0.1);
        }
        .space-image {
            position: relative;
            height: 160px;
            overflow: hidden;
            background: #F3F4F6;
        }

        .space-image img {
            width: 100%;
            height: 100%;
            object-fit: cover; 
            transition: transform 0.3s ease;
        }

        .parking-space-card:hover .space-image img {
            transform: scale(1.05);
        }
        .space-status {
            position: absolute;
            top: 10px;
            right: 10px;
            padding: 4px 8px;
            border-radius: 6px;
            font-size: 12px;
            font-weight: 600;
            background: white;
            z-index: 2;
        }
        .space-status.active {
            background: #DCFCE7;
            color: #16A34A;
        }
        .space-status.full {
            background: #FEE2E2;
            color: #DC2626;
        }
        .space-details {
            padding: 15px;
        }
        .space-details h3 {
            font-size: 16px;
            margin-bottom: 5px;
            color: #111827;
        }
        .space-location {
            font-size: 13px;
            color: #6B7280;
            margin-bottom: 10px;
        }
        .space-prices {
            display: flex;
            gap: 10px;
            margin-bottom: 15px;
            font-size: 13px;
            font-weight: 600;
            color: #4F6EF7;
        }
        .space-actions {
            display: flex;
            gap: 8px;
        }
        .space-actions a, .space-actions button {
            flex: 1;
            padding: 8px;
            border: none;
            border-radius: 6px;
            font-size: 13px;
            font-weight: 500;
            cursor: pointer;
            text-align: center;
            text-decoration: none;
            transition: all 0.3s;
        }
        .btn-edit {
            background: #F3F4F6;
            color: #374151;
        }
        .btn-edit:hover {
            background: #E5E7EB;
        }
        .btn-view {
            background: #4F6EF7;
            color: white;
        }
        .btn-view:hover {
            background: #3a56d4;
        }
        .btn-delete {
            background: #FEE2E2;
            color: #DC2626;
        }
        .btn-delete:hover {
            background: #FECACA;
        }
    </style>
</head>
<body>
    <div class="dashboard-container">
        <!-- Sidebar -->
        <aside class="sidebar">
            <a href="index.php" class="sidebar-logo">
                <div class="sidebar-logo-icon">
                    <svg viewBox="0 0 24 24"><path d="M12 2C8.13 2 5 5.13 5 9c0 5.25 7 13 7 13s7-7.75 7-13c0-3.87-3.13-7-7-7z"/></svg>
                </div>
                <div class="sidebar-logo-text">
                    <h2>ParkEase</h2>
                    <p>Smart Parking Solutions</p>
                </div>
            </a>
            
            <ul class="sidebar-menu">
                <li><a href="#" class="active">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/></svg>
                    Dashboard
                </a></li>
                
                <?php if ($user_type == 'parker'): ?>
                <li><a href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M21 10c0 7-9 13-9 13s-9-6-9-13a9 9 0 0 1 18 0z"/><circle cx="12" cy="10" r="3"/></svg>
                    Find Parking
                </a></li>
                <li><a href="my-reservations.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    My Reservations
</a></li>
                <?php else: ?>
                <li><a href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/><polyline points="9 22 9 12 15 12 15 22"/></svg>
                    My Spaces
                </a></li>
                <li><a href="owner-reservations.php">
    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    Manage Bookings
</a></li>
                <li><a href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><line x1="12" y1="20" x2="12" y2="10"/><line x1="18" y1="20" x2="18" y2="4"/><line x1="6" y1="20" x2="6" y2="16"/></svg>
                    Earnings
                </a></li>
                <?php endif; ?>
                
                <li><a href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M20 21v-2a4 4 0 0 0-4-4H8a4 4 0 0 0-4 4v2"/><circle cx="12" cy="7" r="4"/></svg>
                    Profile
                </a></li>
                <li><a href="#">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="3"/><path d="M19.4 15a1.65 1.65 0 0 0 .33 1.82l.06.06a2 2 0 0 1 0 2.83 2 2 0 0 1-2.83 0l-.06-.06a1.65 1.65 0 0 0-1.82-.33 1.65 1.65 0 0 0-1 1.51V21a2 2 0 0 1-2 2 2 2 0 0 1-2-2v-.09A1.65 1.65 0 0 0 9 19.4a1.65 1.65 0 0 0-1.82.33l-.06.06a2 2 0 0 1-2.83 0 2 2 0 0 1 0-2.83l.06-.06a1.65 1.65 0 0 0 .33-1.82 1.65 1.65 0 0 0-1.51-1H3a2 2 0 0 1-2-2 2 2 0 0 1 2-2h.09A1.65 1.65 0 0 0 4.6 9a1.65 1.65 0 0 0-.33-1.82l-.06-.06a2 2 0 0 1 0-2.83 2 2 0 0 1 2.83 0l.06.06a1.65 1.65 0 0 0 1.82.33H9a1.65 1.65 0 0 0 1-1.51V3a2 2 0 0 1 2-2 2 2 0 0 1 2 2v.09a1.65 1.65 0 0 0 1 1.51 1.65 1.65 0 0 0 1.82-.33l.06-.06a2 2 0 0 1 2.83 0 2 2 0 0 1 0 2.83l-.06.06a1.65 1.65 0 0 0-.33 1.82V9a1.65 1.65 0 0 0 1.51 1H21a2 2 0 0 1 2 2 2 2 0 0 1-2 2h-.09a1.65 1.65 0 0 0-1.51 1z"/></svg>
                    Settings
                </a></li>
                <li><a href="logout.php" class="logout">
                    <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><polyline points="16 17 21 12 16 7"/><line x1="21" y1="12" x2="9" y2="12"/></svg>
                    Logout
                </a></li>
            </ul>
        </aside>
        
        <!-- Main Content -->
        <main class="main-content">
            <div class="header">
                <h1>
                    Welcome back, <?php echo htmlspecialchars($user['first_name']); ?>!
                    <span><?php echo ucfirst($user_type); ?></span>
                </h1>
                <div class="user-info">
                    <div class="user-details">
                        <p><?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></p>
                        <small><?php echo htmlspecialchars($user['email']); ?></small>
                    </div>
                    <div class="user-avatar">
                        <?php echo strtoupper(substr($user['first_name'], 0, 1) . substr($user['last_name'], 0, 1)); ?>
                    </div>
                </div>
            </div>
            
            <!-- Stats -->
            <div class="stats-grid">
                <div class="stat-card">
                    <h3>Total <?php echo $user_type == 'parker' ? 'Reservations' : 'Spaces'; ?></h3>
                    <div class="number"><?php echo count($items); ?></div>
                    <div class="trend">↑ 12% from last month</div>
                </div>
                <div class="stat-card">
                    <h3><?php echo $user_type == 'parker' ? 'Active Reservations' : 'Available Spots'; ?></h3>
                    <div class="number"><?php 
                        if ($user_type == 'parker') {
                            $active = array_filter($items, function($item) {
                                return $item['status'] == 'confirmed';
                            });
                            echo count($active);
                        } else {
                            $total = array_sum(array_column($items, 'available_spots'));
                            echo $total;
                        }
                    ?></div>
                    <div class="trend">↑ 5% from yesterday</div>
                </div>
                <div class="stat-card">
                    <h3>Total Spent<?php echo $user_type == 'parker' ? '' : '/Earned'; ?></h3>
                    <div class="number">₦<?php 
                        if ($user_type == 'parker') {
                            $total = array_sum(array_column($items, 'total_amount'));
                            echo number_format($total, 2);
                        } else {
                            echo '0.00';
                        }
                    ?></div>
                </div>
                <div class="stat-card">
                    <h3>Member Since</h3>
                    <div class="number"><?php echo date('M Y', strtotime($user['created_at'])); ?></div>
                </div>
            </div>
            
           

<?php if ($user_type == 'owner'): ?>
<div class="recent-section">
    <div class="recent-header">
        <h2><?php echo $user_type == 'parker' ? 'Recent Reservations' : 'Your Parking Spaces'; ?></h2>
        <?php if ($user_type == 'owner'): ?>
            <a href="add-parking.php" class="btn-add">+ Add New Space</a>
        <?php else: ?>
            <a href="index.php">View All →</a>
        <?php endif; ?>
    </div>
    
    <?php if (empty($items)): ?>
        <div class="empty-state">
            <svg viewBox="0 0 24 24" fill="none" stroke="#9CA3AF" stroke-width="1.5">
                <circle cx="12" cy="12" r="10"/>
                <line x1="12" y1="8" x2="12" y2="12"/>
                <line x1="12" y1="16" x2="12.01" y2="16"/>
            </svg>
            <p>No <?php echo $user_type == 'parker' ? 'reservations' : 'parking spaces'; ?> yet.</p>
            <?php if ($user_type == 'parker'): ?>
                <a href="index.php" style="color: #4F6EF7; text-decoration: none; margin-top: 10px; display: block;">Find Parking →</a>
            <?php else: ?>
                <a href="add-parking.php" style="color: #4F6EF7; text-decoration: none; margin-top: 10px; display: block;">+ Add Your First Space</a>
            <?php endif; ?>
        </div>
    <?php else: ?>
        <?php if ($user_type == 'parker'): ?>
            <ul class="items-list">
                <?php foreach ($items as $item): ?>
                    <li>
                        <div class="item-info">
                            <h4><?php echo htmlspecialchars($item['parking_name']); ?></h4>
                            <p><?php echo htmlspecialchars($item['address']); ?></p>
                            <p>₦<?php echo number_format($item['total_amount'], 2); ?></p>
                        </div>
                        <span class="item-status status-<?php echo $item['status']; ?>">
                            <?php echo ucfirst($item['status']); ?>
                        </span>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php else: ?>
            <div class="parking-spaces-grid">
                <?php foreach ($items as $space): 
                    $space_images = !empty($space['images']) ? json_decode($space['images'], true) : [];
                    $main_image = !empty($space_images) ? 'uploads/parking/' . $space_images[0] : 'img/parking-placeholder.jpg';
                ?>
                <div class="parking-space-card">
                    <div class="space-image">
                        <img src="<?php echo $main_image; ?>" alt="<?php echo htmlspecialchars($space['name']); ?>" onerror="this.src='img/parking-placeholder.jpg'">
                        <span class="space-status <?php echo $space['available_spots'] > 0 ? 'active' : 'full'; ?>">
                            <?php echo $space['available_spots']; ?>/<?php echo $space['total_spots']; ?> spots
                        </span>
                    </div>
                    <div class="space-details">
                        <h3><?php echo htmlspecialchars($space['name']); ?></h3>
                        <p class="space-location">📍 <?php echo htmlspecialchars($space['city']); ?></p>
                        <div class="space-prices">
                            <?php if ($space['hourly_rate']): ?>
                                <span>₦<?php echo number_format($space['hourly_rate'], 0); ?>/hr</span>
                            <?php endif; ?>
                            <?php if ($space['daily_rate']): ?>
                                <span>₦<?php echo number_format($space['daily_rate'], 0); ?>/day</span>
                            <?php endif; ?>
                        </div>
                        <div class="space-actions">
                            <a href="edit-parking.php?id=<?php echo $space['id']; ?>" class="btn-edit">Edit</a>
                            <a href="parking-details.php?id=<?php echo $space['id']; ?>" class="btn-view">View</a>
                            <button onclick="deleteSpace(<?php echo $space['id']; ?>)" class="btn-delete">Delete</button>
                        </div>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>
    <?php endif; ?>
</div>



<script>
function deleteSpace(id) {
    if (confirm('Are you sure you want to delete this parking space? This action cannot be undone.')) {
        window.location.href = 'delete-parking.php?id=' + id;
    }
}
</script>
<?php endif; ?>
        </main>
    </div>
</body>
</html>