<?php
require_once '../config/database.php';
require_once 'includes/auth.php';

requireAdminLogin();

$database = new Database();
$db = $database->getConnection();

$action = $_GET['action'] ?? 'list';
$message = '';
$error = '';

// Handle manual payout
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['manual_payout'])) {
    $owner_id = (int)$_POST['owner_id'];
    $amount = floatval($_POST['amount']);
    $notes = trim($_POST['notes'] ?? '');
    
    // Get owner details
    $query = "SELECT u.*, ob.current_balance FROM users u
              LEFT JOIN owner_balances ob ON u.id = ob.owner_id
              WHERE u.id = :id AND u.user_type = 'owner'";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $owner_id);
    $stmt->execute();
    $owner = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$owner) {
        $error = 'Owner not found';
    } elseif ($amount > $owner['current_balance']) {
        $error = 'Amount exceeds available balance';
    } elseif ($amount < 100) {
        $error = 'Minimum payout is ₦100';
    } else {
        // Create payout record
        $ref = 'MANUAL_' . uniqid() . '_' . time();
        
        $insert = "INSERT INTO owner_payouts (owner_id, amount, status, reference, notes) 
                   VALUES (:owner_id, :amount, 'pending', :ref, :notes)";
        $insert_stmt = $db->prepare($insert);
        $insert_stmt->bindParam(':owner_id', $owner_id);
        $insert_stmt->bindParam(':amount', $amount);
        $insert_stmt->bindParam(':ref', $ref);
        $insert_stmt->bindParam(':notes', $notes);
        
        if ($insert_stmt->execute()) {
            logAdminAction($db, 'manual_payout', "Manual payout of ₦$amount to owner ID: $owner_id");
            $message = "Payout request created successfully";
        } else {
            $error = "Failed to create payout";
        }
    }
}

// Mark payout as completed
if (isset($_GET['complete']) && hasRole('super_admin')) {
    $payout_id = (int)$_GET['complete'];
    
    $update = "UPDATE owner_payouts SET status = 'completed', processed_date = NOW() WHERE id = :id";
    $stmt = $db->prepare($update);
    $stmt->bindParam(':id', $payout_id);
    
    if ($stmt->execute()) {
        logAdminAction($db, 'complete_payout', "Completed payout ID: $payout_id");
        $message = "Payout marked as completed";
    } else {
        $error = "Failed to update payout";
    }
}

// Get all owners with balances
$owners_query = "SELECT u.id, u.first_name, u.last_name, u.email, u.bank_name, 
                 u.account_number, u.account_name,
                 COALESCE(ob.current_balance, 0) as current_balance,
                 COALESCE(ob.pending_balance, 0) as pending_balance
                 FROM users u
                 LEFT JOIN owner_balances ob ON u.id = ob.owner_id
                 WHERE u.user_type = 'owner' AND u.is_active = 1
                 ORDER BY current_balance DESC";
$owners_stmt = $db->prepare($owners_query);
$owners_stmt->execute();
$owners = $owners_stmt->fetchAll(PDO::FETCH_ASSOC);

// Get payout history
$payouts_query = "SELECT p.*, u.first_name, u.last_name, u.email 
                  FROM owner_payouts p
                  JOIN users u ON p.owner_id = u.id
                  ORDER BY p.created_at DESC LIMIT 50";
$payouts_stmt = $db->prepare($payouts_query);
$payouts_stmt->execute();
$payouts = $payouts_stmt->fetchAll(PDO::FETCH_ASSOC);

$page_title = 'Manage Payouts';
include 'includes/header.php';
?>

<!-- Custom Styles for Payouts Page -->
<style>
    .glass-section-title {
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
    }
    
    .balance-amount {
        color: #4ade80;
        font-weight: 700;
    }
    
    .pending-balance {
        color: #fbbf24;
        font-weight: 600;
    }
    
    .bank-details {
        font-size: 12px;
        line-height: 1.4;
    }
    
    .bank-details strong {
        color: white;
    }
    
    .bank-details small {
        color: rgba(255,255,255,0.6);
        display: block;
    }
    
    .action-badge {
        display: inline-block;
        padding: 4px 10px;
        border-radius: 50px;
        font-size: 11px;
        font-weight: 600;
    }
    
    .action-badge.warning {
        background: rgba(245,158,11,0.15);
        color: #fbbf24;
        border: 1px solid rgba(245,158,11,0.2);
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
    
    .readonly-input {
        background: rgba(255,255,255,0.05) !important;
        color: rgba(255,255,255,0.7) !important;
        cursor: not-allowed;
    }
    
    .balance-hint {
        display: block;
        margin-top: 5px;
        font-size: 11px;
        color: #4ade80;
    }
    
    /* DataTables override */
    .dataTables_wrapper .dataTables_info,
    .dataTables_wrapper .dataTables_paginate {
        color: rgba(255,255,255,0.7);
    }
    
    @media (max-width: 768px) {
        .glass-modal {
            padding: 24px;
            width: 95%;
        }
        
        .glass-modal h3 {
            font-size: 18px;
        }
    }
</style>

<!-- Top Bar -->
<div class="top-bar">
    <div class="page-title">
        <h1><i class="fas fa-money-bill-wave"></i> Manage Payouts</h1>
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

<!-- Owner Balances Table -->
<div class="table-container" style="margin-bottom: 30px;">
    <div class="glass-section-title">
        <i class="fas fa-users"></i> Owner Balances
    </div>
    
    <div style="overflow-x: auto;">
        <table id="ownersTable">
            <thead>
                <tr>
                    <th><i class="fas fa-user"></i> Owner</th>
                    <th><i class="fas fa-envelope"></i> Email</th>
                    <th><i class="fas fa-university"></i> Bank Details</th>
                    <th><i class="fas fa-wallet"></i> Current Balance</th>
                    <th><i class="fas fa-clock"></i> Pending Balance</th>
                    <th><i class="fas fa-cog"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($owners as $owner): ?>
                <tr>
                    <td>
                        <strong><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($owner['first_name'] . ' ' . $owner['last_name']); ?></strong>
                    </td>
                    <td><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($owner['email']); ?></td>
                    <td class="bank-details">
                        <?php if ($owner['bank_name']): ?>
                            <strong><i class="fas fa-university"></i> <?php echo htmlspecialchars($owner['bank_name']); ?></strong>
                            <small><i class="fas fa-credit-card"></i> <?php echo htmlspecialchars($owner['account_number']); ?></small>
                            <small><i class="fas fa-user"></i> <?php echo htmlspecialchars($owner['account_name']); ?></small>
                        <?php else: ?>
                            <span class="badge badge-danger"><i class="fas fa-exclamation-triangle"></i> No Bank Details</span>
                        <?php endif; ?>
                    </td>
                    <td class="balance-amount">₦<?php echo number_format($owner['current_balance'], 2); ?></td>
                    <td class="pending-balance">₦<?php echo number_format($owner['pending_balance'], 2); ?></td>
                    <td>
                        <?php if ($owner['current_balance'] >= 100 && $owner['bank_name']): ?>
                            <button onclick="showPayoutModal(<?php echo $owner['id']; ?>, '<?php echo htmlspecialchars($owner['first_name'] . ' ' . $owner['last_name']); ?>', <?php echo $owner['current_balance']; ?>)" class="btn btn-sm btn-success">
                                <i class="fas fa-hand-holding-usd"></i> Process Payout
                            </button>
                        <?php else: ?>
                            <span class="action-badge warning">
                                <i class="fas fa-ban"></i> Not Eligible
                            </span>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Payout History Table -->
<div class="table-container">
    <div class="glass-section-title">
        <i class="fas fa-history"></i> Recent Payouts
    </div>
    
    <div style="overflow-x: auto;">
        <table id="payoutsTable">
            <thead>
                <tr>
                    <th><i class="fas fa-calendar"></i> Date</th>
                    <th><i class="fas fa-user"></i> Owner</th>
                    <th><i class="fas fa-money-bill-wave"></i> Amount</th>
                    <th><i class="fas fa-hashtag"></i> Reference</th>
                    <th><i class="fas fa-chart-simple"></i> Status</th>
                    <th><i class="fas fa-sticky-note"></i> Notes</th>
                    <th><i class="fas fa-cog"></i> Actions</th>
                </tr>
            </thead>
            <tbody>
                <?php foreach ($payouts as $payout): ?>
                <tr>
                    <td><i class="fas fa-calendar-alt"></i> <?php echo date('M d, Y H:i', strtotime($payout['created_at'])); ?></td>
                    <td>
                        <strong><i class="fas fa-user-circle"></i> <?php echo htmlspecialchars($payout['first_name'] . ' ' . $payout['last_name']); ?></strong>
                        <br><small><i class="fas fa-envelope"></i> <?php echo htmlspecialchars($payout['email']); ?></small>
                    </td>
                    <td class="balance-amount">₦<?php echo number_format($payout['amount'], 2); ?></td>
                    <td><small class="subscriber-email"><?php echo htmlspecialchars($payout['reference']); ?></small></td>
                    <td>
                        <span class="badge badge-<?php 
                            echo $payout['status'] == 'completed' ? 'success' : 
                                ($payout['status'] == 'processing' ? 'info' : 
                                ($payout['status'] == 'pending' ? 'warning' : 'danger')); ?>">
                            <i class="fas <?php 
                                echo $payout['status'] == 'completed' ? 'fa-check-circle' : 
                                    ($payout['status'] == 'processing' ? 'fa-spinner fa-pulse' : 
                                    ($payout['status'] == 'pending' ? 'fa-clock' : 'fa-times-circle')); ?>"></i>
                            <?php echo ucfirst($payout['status']); ?>
                        </span>
                    </td>
                    <td><small><?php echo htmlspecialchars($payout['notes'] ?: '-'); ?></small></td>
                    <td>
                        <?php if ($payout['status'] == 'pending' && hasRole('super_admin')): ?>
                            <a href="?complete=<?php echo $payout['id']; ?>" class="btn btn-sm btn-success" onclick="return confirm('Mark this payout as completed?')">
                                <i class="fas fa-check"></i> Mark Complete
                            </a>
                        <?php endif; ?>
                    </td>
                </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    </div>
</div>

<!-- Glassmorphism Payout Modal -->
<div id="payoutModal" class="modal-overlay">
    <div class="glass-modal">
        <h3><i class="fas fa-hand-holding-usd"></i> Process Manual Payout</h3>
        
        <form method="POST">
            <input type="hidden" name="owner_id" id="payoutOwnerId">
            
            <div class="form-group">
                <label><i class="fas fa-user"></i> Owner</label>
                <input type="text" id="payoutOwnerName" class="form-control readonly-input" readonly disabled>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-money-bill-wave"></i> Amount (₦)</label>
                <input type="number" name="amount" id="payoutAmount" class="form-control" step="0.01" min="100" required>
                <small id="balanceHint" class="balance-hint"></small>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-sticky-note"></i> Notes (Optional)</label>
                <textarea name="notes" class="form-control" rows="2" placeholder="Add any notes..."></textarea>
            </div>
            
            <div class="modal-actions" style="display: flex; gap: 12px; margin-top: 24px;">
                <button type="submit" name="manual_payout" class="btn btn-success" style="flex: 1;">
                    <i class="fas fa-check"></i> Process Payout
                </button>
                <button type="button" onclick="closePayoutModal()" class="btn btn-secondary" style="flex: 1;">
                    <i class="fas fa-times"></i> Cancel
                </button>
            </div>
        </form>
    </div>
</div>

<script>
    function showPayoutModal(ownerId, ownerName, maxAmount) {
        document.getElementById('payoutOwnerId').value = ownerId;
        document.getElementById('payoutOwnerName').value = ownerName;
        document.getElementById('payoutAmount').max = maxAmount;
        document.getElementById('payoutAmount').value = maxAmount;
        document.getElementById('balanceHint').innerHTML = '<i class="fas fa-info-circle"></i> Available balance: ₦' + maxAmount.toLocaleString();
        document.getElementById('payoutModal').classList.add('active');
    }
    
    function closePayoutModal() {
        document.getElementById('payoutModal').classList.remove('active');
    }
    
    // Close modal when clicking outside
    window.addEventListener('click', function(event) {
        const modal = document.getElementById('payoutModal');
        if (event.target === modal && modal.classList.contains('active')) {
            modal.classList.remove('active');
        }
    });
    
    // Validate amount before submission
    const amountInput = document.getElementById('payoutAmount');
    if (amountInput) {
        amountInput.addEventListener('change', function() {
            const max = parseFloat(this.max);
            const value = parseFloat(this.value);
            const hint = document.getElementById('balanceHint');
            if (value > max) {
                this.value = max;
                hint.innerHTML = '<i class="fas fa-exclamation-triangle"></i> Amount cannot exceed available balance';
                hint.style.color = '#f87171';
            } else {
                hint.innerHTML = '<i class="fas fa-info-circle"></i> Available balance: ₦' + max.toLocaleString();
                hint.style.color = '#4ade80';
            }
        });
    }
</script>

<!-- DataTables -->
<link rel="stylesheet" href="https://cdn.datatables.net/1.11.5/css/jquery.dataTables.min.css">
<script src="https://code.jquery.com/jquery-3.6.0.min.js"></script>
<script src="https://cdn.datatables.net/1.11.5/js/jquery.dataTables.min.js"></script>
<script>
    $(document).ready(function() {
        $('#ownersTable').DataTable({
            pageLength: 25,
            order: [[3, 'desc']],
            language: {
                search: "<i class='fas fa-search'></i> Search:",
                lengthMenu: "_MENU_ entries per page"
            }
        });
        
        $('#payoutsTable').DataTable({
            pageLength: 25,
            order: [[0, 'desc']],
            language: {
                search: "<i class='fas fa-search'></i> Search:",
                lengthMenu: "_MENU_ entries per page"
            }
        });
    });
</script>

<?php include 'includes/footer.php'; ?>