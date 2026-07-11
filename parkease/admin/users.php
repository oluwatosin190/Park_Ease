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

// Handle user actions
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['suspend_user'])) {
        $user_id = (int)$_POST['user_id'];
        $reason = trim($_POST['reason'] ?? '');
        
        $update = "UPDATE users SET is_active = 0 WHERE id = :id";
        $stmt = $db->prepare($update);
        $stmt->bindParam(':id', $user_id);
        
        if ($stmt->execute()) {
            logAdminAction($db, 'suspend_user', "Suspended user ID: $user_id, Reason: $reason");
            $message = "User suspended successfully";
        } else {
            $error = "Failed to suspend user";
        }
    }
    
    if (isset($_POST['activate_user'])) {
        $user_id = (int)$_POST['user_id'];
        
        $update = "UPDATE users SET is_active = 1 WHERE id = :id";
        $stmt = $db->prepare($update);
        $stmt->bindParam(':id', $user_id);
        
        if ($stmt->execute()) {
            logAdminAction($db, 'activate_user', "Activated user ID: $user_id");
            $message = "User activated successfully";
        } else {
            $error = "Failed to activate user";
        }
    }
    
    if (isset($_POST['delete_user'])) {
        if (hasRole('super_admin')) {
            $user_id = (int)$_POST['user_id'];
            
            $delete = "DELETE FROM users WHERE id = :id";
            $stmt = $db->prepare($delete);
            $stmt->bindParam(':id', $user_id);
            
            if ($stmt->execute()) {
                logAdminAction($db, 'delete_user', "Deleted user ID: $user_id");
                $message = "User deleted permanently";
            } else {
                $error = "Failed to delete user";
            }
        } else {
            $error = "Only super admin can delete users";
        }
    }
}

// Get filter
$filter = $_GET['filter'] ?? 'all';
$search = $_GET['search'] ?? '';

// Build query
$query = "SELECT * FROM users WHERE 1=1";
$params = [];

if ($filter == 'owners') {
    $query .= " AND user_type = 'owner'";
} elseif ($filter == 'parkers') {
    $query .= " AND user_type = 'parker'";
} elseif ($filter == 'active') {
    $query .= " AND is_active = 1";
} elseif ($filter == 'suspended') {
    $query .= " AND is_active = 0";
}

if (!empty($search)) {
    $query .= " AND (first_name LIKE :search OR last_name LIKE :search OR email LIKE :search)";
    $params[':search'] = "%$search%";
}

$query .= " ORDER BY created_at DESC";

$stmt = $db->prepare($query);
foreach ($params as $key => $value) {
    $stmt->bindValue($key, $value);
}
$stmt->execute();
$users = $stmt->fetchAll(PDO::FETCH_ASSOC);

logAdminAction($db, 'view_users', 'Viewed users list');

$page_title = 'User Management';
include 'includes/header.php';
?>

<!-- Custom Styles for Users Page -->
<style>
    .users-filters {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 24px;
        padding: 20px;
        margin-bottom: 24px;
        transition: all 0.3s ease;
    }
    
    .users-filters:hover {
        background: rgba(255,255,255,0.12);
        border-color: rgba(255,255,255,0.25);
    }
    
    .filter-buttons {
        display: flex;
        gap: 10px;
        flex-wrap: wrap;
    }
    
    .filter-btn {
        padding: 8px 18px;
        border-radius: 50px;
        background: rgba(255,255,255,0.05);
        border: 1px solid rgba(255,255,255,0.15);
        color: rgba(255,255,255,0.8);
        text-decoration: none;
        font-size: 13px;
        font-weight: 500;
        transition: all 0.3s ease;
    }
    
    .filter-btn:hover {
        background: rgba(255,255,255,0.15);
        transform: translateY(-2px);
    }
    
    .filter-btn.active {
        background: linear-gradient(135deg, #4F6EF7, #7C3AED);
        border-color: transparent;
        color: white;
        box-shadow: 0 4px 15px rgba(79,110,247,0.3);
    }
    
    .search-form {
        display: flex;
        gap: 10px;
        align-items: center;
    }
    
    .search-input {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 50px;
        padding: 10px 18px;
        font-family: 'DM Sans', sans-serif;
        font-size: 13px;
        color: white;
        width: 250px;
        transition: all 0.3s ease;
    }
    
    .search-input::placeholder {
        color: rgba(255,255,255,0.4);
    }
    
    .search-input:focus {
        outline: none;
        border-color: rgba(165,180,252,0.6);
        background: rgba(255,255,255,0.12);
    }
    
    .user-type-badge {
        display: inline-flex;
        align-items: center;
        gap: 6px;
        padding: 5px 12px;
        border-radius: 50px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .user-type-owner {
        background: rgba(245,158,11,0.15);
        color: #fbbf24;
        border: 1px solid rgba(245,158,11,0.2);
    }
    
    .user-type-parker {
        background: rgba(59,130,246,0.15);
        color: #60a5fa;
        border: 1px solid rgba(59,130,246,0.2);
    }
    
    .user-status-active {
        background: rgba(34,197,94,0.15);
        color: #4ade80;
        border: 1px solid rgba(34,197,94,0.2);
    }
    
    .user-status-suspended {
        background: rgba(239,68,68,0.15);
        color: #f87171;
        border: 1px solid rgba(239,68,68,0.2);
    }
    
    /* Action Buttons */
    .action-btn {
        display: inline-flex;
        align-items: center;
        gap: 5px;
        padding: 6px 12px;
        border-radius: 50px;
        font-size: 11px;
        font-weight: 600;
        cursor: pointer;
        text-decoration: none;
        transition: all 0.3s ease;
        border: none;
    }
    
    .action-btn-primary {
        background: linear-gradient(135deg, #4F6EF7, #7C3AED);
        color: white;
    }
    
    .action-btn-primary:hover {
        transform: translateY(-2px);
        box-shadow: 0 4px 12px rgba(79,110,247,0.3);
    }
    
    .action-btn-danger {
        background: rgba(239,68,68,0.15);
        color: #f87171;
        border: 1px solid rgba(239,68,68,0.2);
    }
    
    .action-btn-danger:hover {
        background: rgba(239,68,68,0.25);
        transform: translateY(-2px);
    }
    
    .action-btn-success {
        background: rgba(34,197,94,0.15);
        color: #4ade80;
        border: 1px solid rgba(34,197,94,0.2);
    }
    
    .action-btn-success:hover {
        background: rgba(34,197,94,0.25);
        transform: translateY(-2px);
    }
    
    .user-company {
        font-size: 11px;
        color: rgba(255,255,255,0.5);
        display: block;
        margin-top: 2px;
    }
    
    /* Modal Styles */
    .glass-modal {
        background: rgba(26,26,46,0.95);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
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
    
    .glass-modal h3 {
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
    
    .suspend-user-name {
        color: rgba(255,255,255,0.8);
        margin-bottom: 20px;
        padding: 12px;
        background: rgba(255,255,255,0.05);
        border-radius: 16px;
        text-align: center;
    }
    
    .suspend-user-name strong {
        color: #f87171;
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
    
    @media (max-width: 768px) {
        .filter-buttons {
            justify-content: center;
        }
        
        .search-form {
            width: 100%;
            margin-top: 10px;
        }
        
        .search-input {
            flex: 1;
            width: auto;
        }
        
        .users-filters .d-flex {
            flex-direction: column;
        }
        
        .glass-modal {
            padding: 24px;
            width: 95%;
        }
    }
    
    @media (max-width: 480px) {
        .filter-btn {
            padding: 6px 14px;
            font-size: 12px;
        }
        
        .action-btn {
            padding: 4px 10px;
            font-size: 10px;
        }
    }
</style>

<!-- Top Bar -->
<div class="top-bar">
    <div class="page-title">
        <h1><i class="fas fa-users"></i> User Management</h1>
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

<!-- Glass Filters Section -->
<div class="users-filters">
    <div style="display: flex; justify-content: space-between; align-items: center; flex-wrap: wrap; gap: 15px;">
        <div class="filter-buttons">
            <a href="?filter=all" class="filter-btn <?php echo $filter == 'all' ? 'active' : ''; ?>">
                <i class="fas fa-users"></i> All
            </a>
            <a href="?filter=owners" class="filter-btn <?php echo $filter == 'owners' ? 'active' : ''; ?>">
                <i class="fas fa-building"></i> Owners
            </a>
            <a href="?filter=parkers" class="filter-btn <?php echo $filter == 'parkers' ? 'active' : ''; ?>">
                <i class="fas fa-user"></i> Parkers
            </a>
            <a href="?filter=active" class="filter-btn <?php echo $filter == 'active' ? 'active' : ''; ?>">
                <i class="fas fa-check-circle"></i> Active
            </a>
            <a href="?filter=suspended" class="filter-btn <?php echo $filter == 'suspended' ? 'active' : ''; ?>">
                <i class="fas fa-ban"></i> Suspended
            </a>
        </div>
        
        <form method="GET" class="search-form">
            <input type="text" name="search" placeholder="Search by name or email..." value="<?php echo htmlspecialchars($search); ?>" class="search-input">
            <button type="submit" class="btn btn-primary">
                <i class="fas fa-search"></i> Search
            </button>
        </form>
    </div>
</div>

<!-- Glass Users Table -->
<div class="table-container">
    <div style="overflow-x: auto;">
        <table id="usersTable">
            <thead>
                <tr>
                    <th><i class="fas fa-hashtag"></i> ID</th>
                    <th><i class="fas fa-user"></i> Name</th>
                    <th><i class="fas fa-envelope"></i> Email</th>
                    <th><i class="fas fa-tag"></i> Type</th>
                    <th><i class="fas fa-phone"></i> Phone</th>
                    <th><i class="fas fa-calendar-plus"></i> Joined</th>
                    <th><i class="fas fa-circle"></i> Status</th>
                    <th><i class="fas fa-cog"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($users as $user): ?>
                <tr>
                    <td style="font-family: monospace; color: #a5b4fc;">#<?php echo $user['id']; ?></td>
                    <td>
                        <strong><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?></strong>
                        <?php if ($user['company_name']): ?>
                            <span class="user-company"><i class="fas fa-building"></i> <?php echo htmlspecialchars($user['company_name']); ?></span>
                        <?php endif; ?>
                    </td>
                    <td><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($user['email']); ?></td>
                    <td>
                        <span class="user-type-badge user-type-<?php echo $user['user_type']; ?>">
                            <i class="fas <?php echo $user['user_type'] == 'owner' ? 'fa-building' : 'fa-user'; ?>"></i>
                            <?php echo ucfirst($user['user_type']); ?>
                        </span>
                    </td>
                    <td>
                        <i class="fas fa-phone"></i> <?php echo htmlspecialchars($user['phone'] ?: 'N/A'); ?>
                    </td>
                    <td>
                        <i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y', strtotime($user['created_at'])); ?>
                    </td>
                    <td>
                        <span class="user-type-badge user-status-<?php echo $user['is_active'] ? 'active' : 'suspended'; ?>">
                            <i class="fas <?php echo $user['is_active'] ? 'fa-check-circle' : 'fa-ban'; ?>"></i>
                            <?php echo $user['is_active'] ? 'Active' : 'Suspended'; ?>
                        </span>
                    </td>
                    <td>
                        <div style="display: flex; gap: 8px; flex-wrap: wrap;">
                            <a href="impersonate.php?id=<?php echo $user['id']; ?>" class="action-btn action-btn-primary" onclick="return confirm('Login as this user?')">
                                <i class="fas fa-eye"></i> Login
                            </a>
                            
                            <?php if ($user['is_active']): ?>
                                <button onclick="showSuspendModal(<?php echo $user['id']; ?>, '<?php echo htmlspecialchars($user['first_name'] . ' ' . $user['last_name']); ?>')" class="action-btn action-btn-danger">
                                    <i class="fas fa-ban"></i> Suspend
                                </button>
                            <?php else: ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" name="activate_user" class="action-btn action-btn-success" onclick="return confirm('Activate this user?')">
                                        <i class="fas fa-check-circle"></i> Activate
                                    </button>
                                </form>
                            <?php endif; ?>
                            
                            <?php if (hasRole('super_admin')): ?>
                                <form method="POST" style="display: inline;">
                                    <input type="hidden" name="user_id" value="<?php echo $user['id']; ?>">
                                    <button type="submit" name="delete_user" class="action-btn action-btn-danger" onclick="return confirm('Permanently delete this user? This cannot be undone!')">
                                        <i class="fas fa-trash"></i> Delete
                                    </button>
                                </form>
                            <?php endif; ?>
                        </div>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Glass Suspend Modal -->
<div id="suspendModal" class="modal-overlay">
    <div class="glass-modal">
        <h3><i class="fas fa-ban"></i> Suspend User</h3>
        
        <div class="suspend-user-name" id="suspendUserName"></div>
        
        <form method="POST">
            <input type="hidden" name="user_id" id="suspendUserId">
            
            <div class="form-group">
                <label><i class="fas fa-comment"></i> Reason for suspension</label>
                <textarea name="reason" class="form-control" rows="3" required placeholder="Enter reason..."></textarea>
            </div>
            
            <div class="modal-actions">
                <button type="submit" name="suspend_user" class="btn btn-danger">
                    <i class="fas fa-ban"></i> Suspend User
                </button>
                <button type="button" onclick="closeSuspendModal()" class="btn btn-secondary">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function showSuspendModal(userId, userName) {
        document.getElementById('suspendUserId').value = userId;
        document.getElementById('suspendUserName').innerHTML = '<i class="fas fa-exclamation-triangle"></i> Suspending: <strong>' + userName + '</strong>';
        document.getElementById('suspendModal').classList.add('active');
    }
    
    function closeSuspendModal() {
        document.getElementById('suspendModal').classList.remove('active');
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('suspendModal');
        if (event.target === modal && modal.classList.contains('active')) {
            modal.classList.remove('active');
        }
    });
    
    // Reset modal form when closed
    function resetModal() {
        document.querySelector('#suspendModal textarea').value = '';
    }
</script>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script>
    $(document).ready(function() {
        $('#usersTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                search: "<i class='fas fa-search'></i> Search:",
                lengthMenu: "_MENU_ entries per page",
                info: "Showing _START_ to _END_ of _TOTAL_ users",
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