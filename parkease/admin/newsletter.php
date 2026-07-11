<?php
session_start();
require_once '../config/database.php';
require_once 'includes/auth.php';

// Require admin login
requireAdminLogin();

$database = new Database();
$db = $database->getConnection();

// Handle unsubscribe request
if (isset($_GET['unsubscribe'])) {
    $id = (int)$_GET['unsubscribe'];
    $update = $db->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed' WHERE id = ?");
    $update->execute([$id]);
    $_SESSION['success'] = 'Subscriber unsubscribed successfully';
    header('Location: newsletter.php');
    exit();
}

// Handle delete request
if (isset($_GET['delete'])) {
    $id = (int)$_GET['delete'];
    $delete = $db->prepare("DELETE FROM newsletter_subscribers WHERE id = ?");
    $delete->execute([$id]);
    $_SESSION['success'] = 'Subscriber deleted successfully';
    header('Location: newsletter.php');
    exit();
}

// Get filter
$status = isset($_GET['status']) ? $_GET['status'] : 'active';
$search = isset($_GET['search']) ? trim($_GET['search']) : '';

// Build query
$query = "SELECT * FROM newsletter_subscribers WHERE 1=1";
$params = [];

if ($status != 'all') {
    $query .= " AND status = :status";
    $params[':status'] = $status;
}

if (!empty($search)) {
    $query .= " AND (email LIKE :search OR first_name LIKE :search OR last_name LIKE :search)";
    $params[':search'] = "%$search%";
}

$query .= " ORDER BY subscribed_at DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Get counts
$counts_query = "SELECT 
    COUNT(*) as total,
    SUM(CASE WHEN status = 'active' THEN 1 ELSE 0 END) as active,
    SUM(CASE WHEN status = 'unsubscribed' THEN 1 ELSE 0 END) as unsubscribed
    FROM newsletter_subscribers";
$counts_stmt = $db->prepare($counts_query);
$counts_stmt->execute();
$counts = $counts_stmt->fetch(PDO::FETCH_ASSOC);

// Log this view
logAdminAction($db, 'view_newsletter', 'Viewed newsletter subscribers');

$page_title = 'Newsletter Subscribers';
include 'includes/header.php';
?>

<!-- Custom Styles for Newsletter Page -->
<style>
    .newsletter-stats {
        display: grid;
        grid-template-columns: repeat(4, 1fr);
        gap: 20px;
        margin-bottom: 30px;
    }
    
    .newsletter-stat-card {
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
    
    .newsletter-stat-card:nth-child(1) { animation-delay: 0.05s; }
    .newsletter-stat-card:nth-child(2) { animation-delay: 0.1s; }
    .newsletter-stat-card:nth-child(3) { animation-delay: 0.15s; }
    .newsletter-stat-card:nth-child(4) { animation-delay: 0.2s; }
    
    .newsletter-stat-card:hover {
        transform: translateY(-5px);
        background: rgba(255,255,255,0.12);
        border-color: rgba(255,255,255,0.3);
        box-shadow: 0 20px 48px rgba(0,0,0,0.3);
    }
    
    .newsletter-stat-card h3 {
        font-size: 14px;
        font-weight: 500;
        color: rgba(255,255,255,0.7);
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .newsletter-stat-card h3 i {
        color: #a5b4fc;
    }
    
    .stat-number {
        font-family: 'Outfit', sans-serif;
        font-size: 36px;
        font-weight: 800;
        background: linear-gradient(135deg, #fff, #a5b4fc);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
    }
    
    .stat-number.active {
        background: linear-gradient(135deg, #4ade80, #10B981);
        -webkit-background-clip: text;
        background-clip: text;
    }
    
    .stat-number.unsubscribed {
        background: linear-gradient(135deg, #f87171, #DC2626);
        -webkit-background-clip: text;
        background-clip: text;
    }
    
    .action-buttons-card {
        display: flex;
        gap: 12px;
        flex-wrap: wrap;
    }
    
    /* Glass Filter Section */
    .filter-section {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 24px;
        padding: 20px;
        margin-bottom: 24px;
        transition: all 0.3s ease;
    }
    
    .filter-section:hover {
        background: rgba(255,255,255,0.12);
        border-color: rgba(255,255,255,0.25);
    }
    
    .filter-form {
        display: flex;
        gap: 12px;
        align-items: center;
        flex-wrap: wrap;
    }
    
    .filter-select {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 50px;
        padding: 10px 16px;
        font-family: 'DM Sans', sans-serif;
        font-size: 13px;
        color: white;
        cursor: pointer;
        transition: all 0.3s ease;
    }
    
    .filter-select option {
        background: #1a1a2e;
        color: white;
    }
    
    .filter-select:focus {
        outline: none;
        border-color: rgba(165,180,252,0.6);
    }
    
    .filter-input {
        flex: 1;
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 50px;
        padding: 10px 18px;
        font-family: 'DM Sans', sans-serif;
        font-size: 13px;
        color: white;
        transition: all 0.3s ease;
    }
    
    .filter-input::placeholder {
        color: rgba(255,255,255,0.4);
    }
    
    .filter-input:focus {
        outline: none;
        border-color: rgba(165,180,252,0.6);
        background: rgba(255,255,255,0.12);
    }
    
    .btn-clear {
        background: rgba(255,255,255,0.1);
        color: rgba(255,255,255,0.8);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 50px;
    }
    
    .btn-clear:hover {
        background: rgba(255,255,255,0.2);
        transform: translateY(-2px);
    }
    
    /* Table header info */
    .table-header-info {
        display: flex;
        justify-content: space-between;
        align-items: center;
        margin-bottom: 20px;
        flex-wrap: wrap;
        gap: 15px;
    }
    
    .table-header-info h2 {
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
    
    .subscriber-count {
        color: rgba(255,255,255,0.6);
        font-size: 13px;
    }
    
    .subscriber-count strong {
        color: #a5b4fc;
    }
    
    /* Table styles */
    .newsletter-table {
        width: 100%;
        border-collapse: collapse;
    }
    
    .newsletter-table th {
        text-align: left;
        padding: 14px;
        background: rgba(255,255,255,0.05);
        color: rgba(255,255,255,0.8);
        font-weight: 600;
        font-size: 12px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    
    .newsletter-table td {
        padding: 14px;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        font-size: 13px;
        color: rgba(255,255,255,0.9);
    }
    
    .newsletter-table tr:hover td {
        background: rgba(255,255,255,0.04);
    }
    
    .subscriber-email {
        font-family: 'Outfit', sans-serif;
        color: #a5b4fc;
        font-weight: 500;
    }
    
    .table-actions {
        display: flex;
        gap: 6px;
        flex-wrap: wrap;
    }
    
    @media (max-width: 1100px) {
        .newsletter-stats {
            grid-template-columns: repeat(2, 1fr);
        }
    }
    
    @media (max-width: 768px) {
        .newsletter-stats {
            grid-template-columns: 1fr;
        }
        
        .stat-number {
            font-size: 28px;
        }
        
        .filter-form {
            flex-direction: column;
        }
        
        .filter-select, .filter-input, .filter-form .btn {
            width: 100%;
        }
        
        .table-header-info {
            flex-direction: column;
            align-items: flex-start;
        }
        
        .newsletter-table th,
        .newsletter-table td {
            white-space: nowrap;
        }
    }
    
    @media (max-width: 480px) {
        .newsletter-stat-card {
            padding: 18px;
        }
        
        .stat-number {
            font-size: 24px;
        }
    }
</style>

<!-- Top Bar -->
<div class="top-bar">
    <div class="page-title">
        <h1><i class="fas fa-envelope"></i> Newsletter Subscribers</h1>
    </div>
    <div class="admin-info">
        <span class="admin-badge"><i class="fas fa-shield-alt"></i> <?php echo $_SESSION['admin_role']; ?></span>
        <span class="admin-name"><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($_SESSION['admin_name']); ?></span>
        <a href="logout.php" class="logout-btn"><i class="fas fa-sign-out-alt"></i> Logout</a>
    </div>
</div>

<!-- Stats Cards -->
<div class="newsletter-stats">
    <div class="newsletter-stat-card">
        <h3><i class="fas fa-users"></i> Total Subscribers</h3>
        <div class="stat-number"><?php echo $counts['total'] ?? 0; ?></div>
    </div>
    <div class="newsletter-stat-card">
        <h3><i class="fas fa-check-circle"></i> Active</h3>
        <div class="stat-number active"><?php echo $counts['active'] ?? 0; ?></div>
    </div>
    <div class="newsletter-stat-card">
        <h3><i class="fas fa-times-circle"></i> Unsubscribed</h3>
        <div class="stat-number unsubscribed"><?php echo $counts['unsubscribed'] ?? 0; ?></div>
    </div>
    <div class="newsletter-stat-card">
        <h3><i class="fas fa-cog"></i> Quick Actions</h3>
        <div class="action-buttons-card">
            <a href="export-newsletter.php" class="btn btn-success"><i class="fas fa-file-export"></i> Export CSV</a>
            <a href="?status=all" class="btn btn-primary"><i class="fas fa-eye"></i> View All</a>
        </div>
    </div>
</div>

<!-- Glass Filter Section -->
<div class="filter-section">
    <form method="GET" class="filter-form">
        <select name="status" class="filter-select">
            <option value="active" <?php echo $status == 'active' ? 'selected' : ''; ?>>Active</option>
            <option value="unsubscribed" <?php echo $status == 'unsubscribed' ? 'selected' : ''; ?>>Unsubscribed</option>
            <option value="all" <?php echo $status == 'all' ? 'selected' : ''; ?>>All</option>
        </select>
        <input type="text" name="search" placeholder="Search by email or name..." value="<?php echo htmlspecialchars($search); ?>" class="filter-input">
        <button type="submit" class="btn btn-primary"><i class="fas fa-search"></i> Filter</button>
        <?php if (!empty($search) || $status != 'active'): ?>
            <a href="newsletter.php" class="btn btn-clear"><i class="fas fa-times"></i> Clear</a>
        <?php endif; ?>
    </form>
</div>

<!-- Subscribers Table -->
<div class="table-container">
    <div class="table-header-info">
        <h2><i class="fas fa-list"></i> Subscriber List</h2>
        <div class="subscriber-count">
            <i class="fas fa-chart-line"></i> Showing <strong><?php echo count($subscribers); ?></strong> of <strong><?php echo $counts['total'] ?? 0; ?></strong> total subscribers
        </div>
    </div>
    
    <div style="overflow-x: auto;">
        <table class="newsletter-table">
            <thead>
                <tr>
                    <th><i class="fas fa-envelope"></i> Email</th>
                    <th><i class="fas fa-user"></i> Name</th>
                    <th><i class="fas fa-calendar-plus"></i> Subscribed</th>
                    <th><i class="fas fa-circle"></i> Status</th>
                    <th><i class="fas fa-globe"></i> Source</th>
                    <th><i class="fas fa-cog"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php if (empty($subscribers)): ?>
                    <tr>
                        <td colspan="6" style="text-align: center; padding: 60px; color: rgba(255,255,255,0.6);">
                            <i class="fas fa-inbox" style="font-size: 48px; margin-bottom: 15px; display: block; opacity: 0.5;"></i>
                            No subscribers found
                        </td>
                    </tr>
                <?php else: ?>
                    <?php foreach ($subscribers as $sub): ?>
                    <tr>
                        <td class="subscriber-email"><i class="fas fa-at"></i> <?php echo htmlspecialchars($sub['email']); ?></td>
                        <td>
                            <i class="fas fa-user-circle"></i> 
                            <?php 
                            $name = trim(($sub['first_name'] ?? '') . ' ' . ($sub['last_name'] ?? ''));
                            echo $name ? htmlspecialchars($name) : '<span style="color: rgba(255,255,255,0.4);">Not provided</span>';
                            ?>
                        </td>
                        <td><i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($sub['subscribed_at'])); ?></td>
                        <td>
                            <span class="badge badge-<?php 
                                echo $sub['status'] == 'active' ? 'success' : 
                                    ($sub['status'] == 'unsubscribed' ? 'danger' : 'warning'); ?>">
                                <i class="fas <?php echo $sub['status'] == 'active' ? 'fa-check-circle' : 'fa-times-circle'; ?>"></i>
                                <?php echo ucfirst($sub['status']); ?>
                            </span>
                        </td>
                        <td><i class="fas fa-code-branch"></i> <?php echo ucfirst($sub['source'] ?? 'Website'); ?></td>
                        <td>
                            <div class="table-actions">
                                <a href="mailto:<?php echo $sub['email']; ?>" class="btn btn-sm btn-primary">
                                    <i class="fas fa-envelope"></i> Email
                                </a>
                                <?php if ($sub['status'] == 'active'): ?>
                                    <a href="?unsubscribe=<?php echo $sub['id']; ?>" 
                                       class="btn btn-sm btn-danger"
                                       onclick="return confirm('Unsubscribe <?php echo htmlspecialchars($sub['email']); ?>?')">
                                        <i class="fas fa-ban"></i> Unsubscribe
                                    </a>
                                <?php endif; ?>
                                <a href="?delete=<?php echo $sub['id']; ?>" 
                                   class="btn btn-sm btn-danger"
                                   onclick="return confirm('Delete <?php echo htmlspecialchars($sub['email']); ?> permanently? This cannot be undone.')">
                                    <i class="fas fa-trash"></i> Delete
                                </a>
                            </div>
                        </td>
                    </tr>
                    <?php endforeach; ?>
                <?php endif; ?>
            </tbody>
        </table>
    </div>
</div>

<?php include 'includes/footer.php'; ?>