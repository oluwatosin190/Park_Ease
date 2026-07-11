<?php
require_once '../config/database.php';
require_once 'includes/auth.php';

requireAdminLogin();

$database = new Database();
$db = $database->getConnection();

$message = '';
$error = '';

// Get all settings
$settings_query = "SELECT * FROM system_settings ORDER BY setting_key";
$settings_stmt = $db->prepare($settings_query);
$settings_stmt->execute();
$settings = [];
while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
    $settings[$row['setting_key']] = $row['setting_value'];
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (isset($_POST['save_settings'])) {
        $db->beginTransaction();
        
        try {
            foreach ($_POST as $key => $value) {
                if ($key != 'save_settings' && strpos($key, 'setting_') === 0) {
                    $setting_key = substr($key, 8); // Remove 'setting_' prefix
                    
                    $update = "UPDATE system_settings SET setting_value = :value WHERE setting_key = :key";
                    $stmt = $db->prepare($update);
                    $stmt->bindParam(':value', $value);
                    $stmt->bindParam(':key', $setting_key);
                    $stmt->execute();
                }
            }
            
            $db->commit();
            logAdminAction($db, 'update_settings', 'Updated system settings');
            $message = 'Settings saved successfully';
            
            // Refresh settings
            $settings_stmt->execute();
            $settings = [];
            while ($row = $settings_stmt->fetch(PDO::FETCH_ASSOC)) {
                $settings[$row['setting_key']] = $row['setting_value'];
            }
            
        } catch (Exception $e) {
            $db->rollBack();
            $error = 'Failed to save settings: ' . $e->getMessage();
        }
    }
}

$page_title = 'System Settings';
include 'includes/header.php';
?>

<!-- Custom Styles for Settings Page -->
<style>
    .settings-grid {
        display: grid;
        grid-template-columns: repeat(2, 1fr);
        gap: 28px;
        margin-bottom: 30px;
    }
    
    .settings-card {
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(20px);
        -webkit-backdrop-filter: blur(20px);
        border: 1px solid rgba(255,255,255,0.15);
        border-radius: 24px;
        padding: 28px;
        transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
        box-shadow: 0 8px 32px rgba(0,0,0,0.2);
        animation: fadeInUp 0.5s ease forwards;
        opacity: 0;
    }
    
    .settings-card:nth-child(1) { animation-delay: 0.05s; }
    .settings-card:nth-child(2) { animation-delay: 0.1s; }
    .settings-card:nth-child(3) { animation-delay: 0.15s; }
    .settings-card:nth-child(4) { animation-delay: 0.2s; }
    
    .settings-card:hover {
        background: rgba(255,255,255,0.12);
        border-color: rgba(255,255,255,0.3);
        box-shadow: 0 20px 48px rgba(0,0,0,0.3);
        transform: translateY(-4px);
    }
    
    .settings-card h2 {
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
        padding-bottom: 12px;
        border-bottom: 1px solid rgba(255,255,255,0.1);
    }
    
    .settings-card h2 i {
        font-size: 18px;
        color: #a5b4fc;
    }
    
    .form-group {
        margin-bottom: 20px;
    }
    
    .form-group label {
        display: flex;
        align-items: center;
        gap: 8px;
        margin-bottom: 10px;
        font-size: 13px;
        font-weight: 500;
        color: rgba(255,255,255,0.8);
    }
    
    .form-group label i {
        color: #a5b4fc;
        width: 18px;
    }
    
    .form-group input,
    .form-group select {
        width: 100%;
        padding: 12px 16px;
        background: rgba(255,255,255,0.08);
        backdrop-filter: blur(10px);
        border: 1px solid rgba(255,255,255,0.2);
        border-radius: 60px;
        font-size: 14px;
        font-family: 'DM Sans', sans-serif;
        color: white;
        transition: all 0.3s ease;
    }
    
    .form-group input:focus,
    .form-group select:focus {
        outline: none;
        border-color: rgba(165,180,252,0.6);
        background: rgba(255,255,255,0.15);
        box-shadow: 0 0 0 3px rgba(79,110,247,0.2);
    }
    
    .form-group input[type="password"] {
        font-family: monospace;
        letter-spacing: 2px;
    }
    
    .form-group select option {
        background: #1a1a2e;
        color: white;
    }
    
    .form-group small {
        display: block;
        margin-top: 8px;
        font-size: 11px;
        color: rgba(255,255,255,0.5);
    }
    
    .settings-actions {
        display: flex;
        justify-content: flex-end;
        margin-top: 30px;
    }
    
    .btn-save {
        background: linear-gradient(135deg, #4F6EF7, #7C3AED);
        color: white;
        padding: 14px 36px;
        border-radius: 60px;
        font-size: 15px;
        font-weight: 600;
        font-family: 'Outfit', sans-serif;
        border: none;
        cursor: pointer;
        transition: all 0.3s ease;
        display: inline-flex;
        align-items: center;
        gap: 10px;
        box-shadow: 0 4px 20px rgba(79,110,247,0.3);
        position: relative;
        overflow: hidden;
    }
    
    .btn-save::before {
        content: '';
        position: absolute;
        top: -50%;
        left: -75%;
        width: 50%;
        height: 200%;
        background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
        transform: skewX(-20deg);
        transition: left 0.5s ease;
    }
    
    .btn-save:hover::before {
        left: 130%;
    }
    
    .btn-save:hover {
        transform: translateY(-2px);
        box-shadow: 0 10px 30px rgba(79,110,247,0.4);
    }
    
    @media (max-width: 1024px) {
        .settings-grid {
            grid-template-columns: 1fr;
            gap: 24px;
        }
    }
    
    @media (max-width: 768px) {
        .settings-card {
            padding: 20px;
        }
        
        .settings-card h2 {
            font-size: 16px;
            margin-bottom: 20px;
        }
        
        .form-group input,
        .form-group select {
            padding: 10px 14px;
            font-size: 13px;
        }
        
        .settings-actions {
            justify-content: stretch;
        }
        
        .btn-save {
            width: 100%;
            justify-content: center;
            padding: 12px 24px;
        }
    }
    
    @media (max-width: 480px) {
        .settings-card {
            padding: 16px;
        }
        
        .settings-card h2 {
            font-size: 15px;
        }
        
        .form-group {
            margin-bottom: 16px;
        }
        
        .form-group label {
            font-size: 12px;
        }
        
        .form-group input,
        .form-group select {
            padding: 10px 14px;
            font-size: 13px;
        }
    }
</style>

<!-- Top Bar -->
<div class="top-bar">
    <div class="page-title">
        <h1><i class="fas fa-cog"></i> System Settings</h1>
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

<form method="POST">
    <div class="settings-grid">
        <!-- Commission Settings Card -->
        <div class="settings-card">
            <h2><i class="fas fa-percent"></i> Commission Settings</h2>
            
            <div class="form-group">
                <label><i class="fas fa-chart-line"></i> Commission Rate (%)</label>
                <input type="number" name="setting_commission_rate" class="form-control" 
                       value="<?php echo $settings['commission_rate'] ?? 15; ?>" step="0.01" min="0" max="100">
                <small>Percentage taken from each booking (10-20% recommended)</small>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-arrow-down"></i> Minimum Commission (₦)</label>
                <input type="number" name="setting_min_commission" class="form-control" 
                       value="<?php echo $settings['min_commission'] ?? 100; ?>" step="1" min="0">
                <small>Minimum commission amount for small bookings</small>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-arrow-up"></i> Maximum Commission (₦)</label>
                <input type="number" name="setting_max_commission" class="form-control" 
                       value="<?php echo $settings['max_commission'] ?? 50000; ?>" step="1" min="0">
                <small>Maximum commission cap for large bookings</small>
            </div>
        </div>
        
        <!-- Payout Settings Card -->
        <div class="settings-card">
            <h2><i class="fas fa-hand-holding-usd"></i> Payout Settings</h2>
            
            <div class="form-group">
                <label><i class="fas fa-money-bill-wave"></i> Minimum Payout (₦)</label>
                <input type="number" name="setting_min_payout" class="form-control" 
                       value="<?php echo $settings['min_payout'] ?? 1000; ?>" step="100" min="100">
                <small>Minimum amount owners can withdraw</small>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-calendar-week"></i> Payout Schedule</label>
                <select name="setting_payout_schedule" class="form-control">
                    <option value="daily" <?php echo ($settings['payout_schedule'] ?? 'daily') == 'daily' ? 'selected' : ''; ?>>Daily</option>
                    <option value="weekly" <?php echo ($settings['payout_schedule'] ?? 'daily') == 'weekly' ? 'selected' : ''; ?>>Weekly (Monday)</option>
                    <option value="monthly" <?php echo ($settings['payout_schedule'] ?? 'daily') == 'monthly' ? 'selected' : ''; ?>>Monthly (Last day)</option>
                </select>
                <small>When automatic payouts are processed</small>
            </div>
        </div>
        
        <!-- Site Settings Card -->
        <div class="settings-card">
            <h2><i class="fas fa-globe"></i> Site Settings</h2>
            
            <div class="form-group">
                <label><i class="fas fa-building"></i> Site Name</label>
                <input type="text" name="setting_site_name" class="form-control" 
                       value="<?php echo $settings['site_name'] ?? 'SpaceNode'; ?>">
                <small>Your brand name displayed throughout the system</small>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-envelope"></i> Site Email</label>
                <input type="email" name="setting_site_email" class="form-control" 
                       value="<?php echo $settings['site_email'] ?? 'support@spacenode.com'; ?>">
                <small>Email used for system notifications</small>
            </div>
        </div>
        
        <!-- Paystack Settings Card -->
        <div class="settings-card">
            <h2><i class="fas fa-credit-card"></i> Paystack Settings</h2>
            
            <div class="form-group">
                <label><i class="fas fa-key"></i> Public Key</label>
                <input type="text" name="setting_paystack_public_key" class="form-control" 
                       value="<?php echo $settings['paystack_public_key'] ?? ''; ?>">
                <small>Paystack API public key (starts with pk_live_ or pk_test_)</small>
            </div>
            
            <div class="form-group">
                <label><i class="fas fa-lock"></i> Secret Key</label>
                <input type="password" name="setting_paystack_secret_key" class="form-control" 
                       value="<?php echo $settings['paystack_secret_key'] ?? ''; ?>">
                <small>Keep this secret! (starts with sk_live_ or sk_test_)</small>
            </div>
        </div>
    </div>
    
    <div class="settings-actions">
        <button type="submit" name="save_settings" class="btn-save">
            <i class="fas fa-save"></i> Save All Settings
        </button>
    </div>
</form>

<?php include 'includes/footer.php'; ?>