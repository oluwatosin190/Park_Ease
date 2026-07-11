<?php
require_once '../config/database.php';
require_once 'includes/auth.php';

requireAdminLogin();

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// Get current settings
$settings_query = "SELECT * FROM system_settings WHERE setting_key IN 
                   ('commission_rate', 'min_commission', 'max_commission', 'min_payout')";
$settings_stmt = $db->prepare($settings_query);
$settings_stmt->execute();
$settings = [];
while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['update_commission'])) {
        $commission_rate = floatval($_POST['commission_rate']);
        $min_commission = floatval($_POST['min_commission']);
        $max_commission = floatval($_POST['max_commission']);
        $min_payout = floatval($_POST['min_payout']);
        
        // Validate
        if ($commission_rate < 0 || $commission_rate > 100) {
            $error = 'Commission rate must be between 0 and 100';
        } elseif ($min_commission < 0) {
            $error = 'Minimum commission must be positive';
        } elseif ($max_commission < $min_commission) {
            $error = 'Maximum commission must be greater than minimum';
        } else {
            // Update settings
            $updates = [
                'commission_rate' => $commission_rate,
                'min_commission' => $min_commission,
                'max_commission' => $max_commission,
                'min_payout' => $min_payout
            ];
            
            $db->beginTransaction();
            
            try {
                foreach ($updates as $key => $value) {
                    $update = "UPDATE system_settings SET setting_value = :value WHERE setting_key = :key";
                    $stmt = $db->prepare($update);
                    $stmt->bindParam(':value', $value);
                    $stmt->bindParam(':key', $key);
                    $stmt->execute();
                }
                
                $db->commit();
                logAdminAction($db, 'update_commission', "Updated commission settings");
                $message = 'Commission settings updated successfully';
                
                // Refresh settings
                $settings_stmt->execute();
                $settings = [];
                while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
                    $settings[$row['setting_key']] = $row['setting_value'];
                }
                
            } catch (Exception $e) {
                $db->rollBack();
                $error = 'Failed to update settings: ' . $e->getMessage();
            }
        }
    }
}

$page_title = 'Commission Settings';
include 'includes/header.php';
?>

<!-- Custom Styles for Commission Page -->
<style>
    .commission-form {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 24px;
        padding: 28px;
        transition: all 0.3s ease;
    }
    
    .commission-form:hover {
        background: rgba(255,255,255,0.1);
        border-color: rgba(255,255,255,0.25);
    }
    
    .commission-form h2 {
        font-family: 'Outfit', sans-serif;
        font-size: 18px;
        font-weight: 600;
        background: linear-gradient(135deg, #fff, #a5b4fc);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .info-panel {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 24px;
        padding: 28px;
        transition: all 0.3s ease;
    }
    
    .info-panel:hover {
        background: rgba(255,255,255,0.1);
        border-color: rgba(255,255,255,0.25);
    }
    
    .info-panel h2 {
        font-family: 'Outfit', sans-serif;
        font-size: 18px;
        font-weight: 600;
        background: linear-gradient(135deg, #fff, #a5b4fc);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
        background-clip: text;
        margin-bottom: 24px;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    
    .calculation-box {
        background: rgba(255,255,255,0.05);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.1);
        border-radius: 20px;
        padding: 20px;
        margin-bottom: 24px;
    }
    
    .calculation-box h3 {
        font-family: 'Outfit', sans-serif;
        font-size: 16px;
        font-weight: 600;
        color: white;
        margin-bottom: 12px;
        display: flex;
        align-items: center;
        gap: 8px;
    }
    
    .calculation-box p {
        font-size: 14px;
        color: rgba(255,255,255,0.7);
        margin-bottom: 12px;
    }
    
    .calculation-list {
        list-style: none;
        margin-top: 12px;
    }
    
    .calculation-list li {
        display: flex;
        justify-content: space-between;
        padding: 8px 0;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        font-size: 14px;
    }
    
    .calculation-list li:last-child {
        border-bottom: none;
    }
    
    .calculation-list .label {
        color: rgba(255,255,255,0.6);
    }
    
    .calculation-list .value {
        color: white;
        font-weight: 600;
    }
    
    .calculation-list .value.positive {
        color: #4ade80;
    }
    
    .calculation-list .value.negative {
        color: #f87171;
    }
    
    .rules-list {
        list-style: none;
        margin-top: 10px;
    }
    
    .rules-list li {
        display: flex;
        align-items: center;
        gap: 12px;
        padding: 10px 0;
        border-bottom: 1px solid rgba(255,255,255,0.08);
        font-size: 14px;
        color: rgba(255,255,255,0.8);
    }
    
    .rules-list li:last-child {
        border-bottom: none;
    }
    
    .rules-list li i {
        width: 24px;
        font-size: 16px;
    }
    
    .rules-list li i.fa-check-circle {
        color: #4ade80;
    }
    
    .rules-list li i.fa-info-circle {
        color: #a5b4fc;
    }
    
    .form-group small {
        display: block;
        margin-top: 5px;
        font-size: 11px;
        color: rgba(255,255,255,0.5);
    }
    
    .two-columns {
        display: grid;
        grid-template-columns: 2fr 1fr;
        gap: 30px;
        margin-top: 20px;
    }
    
    @media (max-width: 768px) {
        .two-columns {
            grid-template-columns: 1fr;
            gap: 20px;
        }
    }
</style>

<!-- Top Bar -->
<div class="top-bar">
    <div class="page-title">
        <h1><i class="fas fa-percent"></i> Commission Settings</h1>
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

<!-- Two Column Layout -->
<div class="two-columns">
    
    <!-- Settings Form -->
    <div class="commission-form">
        <h2><i class="fas fa-sliders-h"></i> Commission Configuration</h2>
        
        <form method="POST">
            <div class="form-group">
                <label><i class="fas fa-percent"></i> Commission Rate (%)</label>
                <input type="number" name="commission_rate" class="form-control" 
                       value="<?php echo $settings['commission_rate'] ?? 15; ?>" 
                       step="0.01" min="0" max="100" required>
                <small>Percentage taken from each booking. Recommended: 10-20%</small>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-arrow-down"></i> Minimum Commission (₦)</label>
                <input type="number" name="min_commission" class="form-control" 
                       value="<?php echo $settings['min_commission'] ?? 100; ?>" 
                       step="1" min="0" required>
                <small>Minimum commission amount for small bookings (applies when calculated commission is below this)</small>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-arrow-up"></i> Maximum Commission (₦)</label>
                <input type="number" name="max_commission" class="form-control" 
                       value="<?php echo $settings['max_commission'] ?? 50000; ?>" 
                       step="1" min="0" required>
                <small>Maximum commission cap for large bookings (applies when calculated commission exceeds this)</small>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-money-bill-wave"></i> Minimum Payout (₦)</label>
                <input type="number" name="min_payout" class="form-control" 
                       value="<?php echo $settings['min_payout'] ?? 1000; ?>" 
                       step="100" min="100" required>
                <small>Minimum amount owners must reach before requesting a withdrawal</small>
            </div>
            
            <button type="submit" name="update_commission" class="btn btn-primary">
                <i class="fas fa-save"></i> Save Changes
            </button>
        </form>
    </div>
    
    <!-- Information Panel -->
    <div class="info-panel">
        <h2><i class="fas fa-info-circle"></i> How It Works</h2>
        
        <!-- Commission Calculation Example -->
        <div class="calculation-box">
            <h3><i class="fas fa-calculator"></i> Commission Calculation</h3>
            <p>Example with ₦10,000 booking:</p>
            
            <?php 
                $booking_amount = 10000;
                $rate = $settings['commission_rate'] ?? 15;
                $raw_commission = $booking_amount * ($rate / 100);
                $min_comm = $settings['min_commission'] ?? 100;
                $max_comm = $settings['max_commission'] ?? 50000;
                
                // Apply min/max caps
                $final_commission = max($min_comm, min($max_comm, $raw_commission));
                $owner_payout = $booking_amount - $final_commission;
            ?>
            
            <ul class="calculation-list">
                <li>
                    <span class="label"><i class="fas fa-ticket-alt"></i> Gross Amount:</span>
                    <span class="value">₦<?php echo number_format($booking_amount, 2); ?></span>
                </li>
                <li>
                    <span class="label"><i class="fas fa-percent"></i> Raw Commission (<?php echo $rate; ?>%):</span>
                    <span class="value negative">-₦<?php echo number_format($raw_commission, 2); ?></span>
                </li>
                <?php if ($raw_commission < $min_comm): ?>
                <li>
                    <span class="label"><i class="fas fa-arrow-down"></i> Min Commission Applied:</span>
                    <span class="value negative">-₦<?php echo number_format($min_comm, 2); ?></span>
                </li>
                <?php elseif ($raw_commission > $max_comm): ?>
                <li>
                    <span class="label"><i class="fas fa-arrow-up"></i> Max Commission Applied:</span>
                    <span class="value negative">-₦<?php echo number_format($max_comm, 2); ?></span>
                </li>
                <?php endif; ?>
                <li style="border-top: 1px solid rgba(255,255,255,0.15); margin-top: 8px; padding-top: 12px;">
                    <span class="label"><strong>Owner Payout:</strong></span>
                    <span class="value positive">₦<?php echo number_format($owner_payout, 2); ?></span>
                </li>
            </ul>
        </div>
        
        <!-- Rules -->
        <div class="calculation-box">
            <h3><i class="fas fa-gavel"></i> Rules & Policies</h3>
            <ul class="rules-list">
                <li>
                    <i class="fas fa-check-circle"></i>
                    <span>Minimum commission: <strong>₦<?php echo number_format($settings['min_commission'] ?? 100, 0); ?></strong></span>
                </li>
                <li>
                    <i class="fas fa-check-circle"></i>
                    <span>Maximum commission: <strong>₦<?php echo number_format($settings['max_commission'] ?? 50000, 0); ?></strong></span>
                </li>
                <li>
                    <i class="fas fa-check-circle"></i>
                    <span>Minimum payout: <strong>₦<?php echo number_format($settings['min_payout'] ?? 1000, 0); ?></strong></span>
                </li>
                <li>
                    <i class="fas fa-info-circle"></i>
                    <span>Owners pay transfer fees (if applicable)</span>
                </li>
                <li>
                    <i class="fas fa-info-circle"></i>
                    <span>Platform keeps commission on cancellations</span>
                </li>
                <li>
                    <i class="fas fa-info-circle"></i>
                    <span>Commission is calculated on total booking amount</span>
                </li>
            </ul>
        </div>
        
        <!-- Quick Stats -->
        <div class="calculation-box">
            <h3><i class="fas fa-chart-line"></i> Current Settings Summary</h3>
            <ul class="rules-list">
                <li>
                    <i class="fas fa-percent"></i>
                    <span>Commission Rate: <strong style="color: #a5b4fc;"><?php echo $settings['commission_rate'] ?? 15; ?>%</strong></span>
                </li>
                <li>
                    <i class="fas fa-arrow-down"></i>
                    <span>Min Commission: <strong style="color: #4ade80;">₦<?php echo number_format($settings['min_commission'] ?? 100, 0); ?></strong></span>
                </li>
                <li>
                    <i class="fas fa-arrow-up"></i>
                    <span>Max Commission: <strong style="color: #fbbf24;">₦<?php echo number_format($settings['max_commission'] ?? 50000, 0); ?></strong></span>
                </li>
                <li>
                    <i class="fas fa-hand-holding-usd"></i>
                    <span>Min Payout: <strong style="color: #60a5fa;">₦<?php echo number_format($settings['min_payout'] ?? 1000, 0); ?></strong></span>
                </li>
            </ul>
        </div>
    </div>
</div>

<?php include 'includes/footer.php'; ?>