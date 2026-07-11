<?php
session_start();
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Function to validate Nigerian phone number (optional)
function validatePhone($phone) {
    if (empty($phone)) return true;
    return preg_match('/^(?:\+234|0)[7-9][0-1][0-9]{8}$/', $phone);
}

// Function to validate Nigerian bank account number
function validateBankAccount($account_number) {
    return preg_match('/^[0-9]{10}$/', $account_number);
}

// Check if user is logged in
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id'])) {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    error_log("Database connection failed in settings.php");
    $_SESSION['error'] = 'System error. Please try again later.';
    header('Location: dashboard.php');
    exit();
}

$user_id = (int)$_SESSION['user_id'];
$user_type = isset($_SESSION['user_type']) ? $_SESSION['user_type'] : '';

// Get user details
$query = "SELECT id, first_name, last_name, email, phone, user_type, created_at, 
          bank_name, account_number, account_name, password
          FROM users WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
$stmt->execute();
$user = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$user) {
    session_destroy();
    header('Location: login.php');
    exit();
}

$success = '';
$error = '';

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== $_SESSION['csrf_token']) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // Profile Update
        if (isset($_POST['update_profile'])) {
            $first_name = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
            $last_name = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
            $email = isset($_POST['email']) ? trim($_POST['email']) : '';
            $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
            
            // Validation
            $errors = [];
            
            if (empty($first_name)) {
                $errors[] = 'First name is required.';
            } elseif (strlen($first_name) > 100) {
                $errors[] = 'First name cannot exceed 100 characters.';
            } elseif (!preg_match('/^[a-zA-Z\s\-\']+$/', $first_name)) {
                $errors[] = 'First name can only contain letters, spaces, hyphens, and apostrophes.';
            }
            
            if (empty($last_name)) {
                $errors[] = 'Last name is required.';
            } elseif (strlen($last_name) > 100) {
                $errors[] = 'Last name cannot exceed 100 characters.';
            } elseif (!preg_match('/^[a-zA-Z\s\-\']+$/', $last_name)) {
                $errors[] = 'Last name can only contain letters, spaces, hyphens, and apostrophes.';
            }
            
            if (empty($email)) {
                $errors[] = 'Email address is required.';
            } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
                $errors[] = 'Please enter a valid email address.';
            } elseif (strlen($email) > 100) {
                $errors[] = 'Email cannot exceed 100 characters.';
            }
            
            if (!empty($phone) && !validatePhone($phone)) {
                $errors[] = 'Please enter a valid phone number (e.g., 08012345678 or +2348012345678).';
            }
            
            if (empty($errors)) {
                try {
                    // Check if email already exists for another user
                    $check_query = "SELECT id FROM users WHERE email = :email AND id != :id";
                    $check_stmt = $db->prepare($check_query);
                    $check_stmt->bindParam(':email', $email, PDO::PARAM_STR);
                    $check_stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
                    $check_stmt->execute();
                    
                    if ($check_stmt->fetch()) {
                        $error = 'Email already in use by another account.';
                    } else {
                        $update_query = "UPDATE users SET 
                                         first_name = :first_name,
                                         last_name = :last_name,
                                         email = :email,
                                         phone = :phone
                                         WHERE id = :id";
                        $update_stmt = $db->prepare($update_query);
                        $update_stmt->bindParam(':first_name', $first_name, PDO::PARAM_STR);
                        $update_stmt->bindParam(':last_name', $last_name, PDO::PARAM_STR);
                        $update_stmt->bindParam(':email', $email, PDO::PARAM_STR);
                        $update_stmt->bindParam(':phone', $phone, PDO::PARAM_STR);
                        $update_stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
                        
                        if ($update_stmt->execute()) {
                            $success = 'Profile updated successfully!';
                            // Update session name
                            $_SESSION['user_name'] = $first_name . ' ' . $last_name;
                            $_SESSION['user_email'] = $email;
                            // Refresh user data
                            $user['first_name'] = $first_name;
                            $user['last_name'] = $last_name;
                            $user['email'] = $email;
                            $user['phone'] = $phone;
                            error_log("Profile updated for user: $user_id");
                        } else {
                            $error = 'Failed to update profile.';
                        }
                    }
                } catch (PDOException $e) {
                    error_log("Profile update error: " . $e->getMessage());
                    $error = 'A database error occurred. Please try again.';
                }
            } else {
                $error = implode('<br>', $errors);
            }
        }
        
        // Change Password
        if (isset($_POST['change_password'])) {
            $current_password = isset($_POST['current_password']) ? $_POST['current_password'] : '';
            $new_password = isset($_POST['new_password']) ? $_POST['new_password'] : '';
            $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
            
            $errors = [];
            
            if (empty($current_password)) {
                $errors[] = 'Current password is required.';
            }
            if (empty($new_password)) {
                $errors[] = 'New password is required.';
            } elseif (strlen($new_password) < 6) {
                $errors[] = 'New password must be at least 6 characters long.';
            } elseif (strlen($new_password) > 255) {
                $errors[] = 'New password cannot exceed 255 characters.';
            }
            if ($new_password !== $confirm_password) {
                $errors[] = 'New passwords do not match.';
            }
            
            if (empty($errors)) {
                // Verify current password
                if (password_verify($current_password, $user['password'])) {
                    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
                    $update_query = "UPDATE users SET password = :password WHERE id = :id";
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->bindParam(':password', $hashed_password, PDO::PARAM_STR);
                    $update_stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
                    
                    if ($update_stmt->execute()) {
                        $success = 'Password changed successfully!';
                        error_log("Password changed for user: $user_id");
                    } else {
                        $error = 'Failed to change password.';
                    }
                } else {
                    $error = 'Current password is incorrect.';
                }
            } else {
                $error = implode('<br>', $errors);
            }
        }
        
        // Bank Details Update (Owners only)
        if (isset($_POST['update_bank']) && $user_type == 'owner') {
            $bank_name = isset($_POST['bank_name']) ? trim($_POST['bank_name']) : '';
            $account_number = isset($_POST['account_number']) ? trim($_POST['account_number']) : '';
            $account_name = isset($_POST['account_name']) ? trim($_POST['account_name']) : '';
            
            $errors = [];
            
            if (empty($bank_name)) {
                $errors[] = 'Bank name is required.';
            } elseif (strlen($bank_name) > 100) {
                $errors[] = 'Bank name cannot exceed 100 characters.';
            }
            
            if (empty($account_number)) {
                $errors[] = 'Account number is required.';
            } elseif (!validateBankAccount($account_number)) {
                $errors[] = 'Please enter a valid 10-digit account number.';
            }
            
            if (empty($account_name)) {
                $errors[] = 'Account name is required.';
            } elseif (strlen($account_name) > 100) {
                $errors[] = 'Account name cannot exceed 100 characters.';
            } elseif (!preg_match('/^[a-zA-Z\s\-\'\.]+$/', $account_name)) {
                $errors[] = 'Account name contains invalid characters.';
            }
            
            if (empty($errors)) {
                $update_query = "UPDATE users SET 
                                 bank_name = :bank_name,
                                 account_number = :account_number,
                                 account_name = :account_name
                                 WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':bank_name', $bank_name, PDO::PARAM_STR);
                $update_stmt->bindParam(':account_number', $account_number, PDO::PARAM_STR);
                $update_stmt->bindParam(':account_name', $account_name, PDO::PARAM_STR);
                $update_stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
                
                if ($update_stmt->execute()) {
                    $success = 'Bank details updated successfully!';
                    // Refresh user data
                    $user['bank_name'] = $bank_name;
                    $user['account_number'] = $account_number;
                    $user['account_name'] = $account_name;
                    error_log("Bank details updated for user: $user_id");
                } else {
                    $error = 'Failed to update bank details.';
                }
            } else {
                $error = implode('<br>', $errors);
            }
        }
    }
}

// List of Nigerian banks for dropdown
$banks = [
    'Access Bank',
    'Ecobank',
    'Fidelity Bank',
    'First Bank',
    'First City Monument Bank (FCMB)',
    'Guaranty Trust Bank (GTB)',
    'Heritage Bank',
    'Keystone Bank',
    'Kuda Bank',
    'Moniepoint',
    'Opay',
    'Polaris Bank',
    'Providus Bank',
    'Palmpay',
    'Stanbic IBTC Bank',
    'Sterling Bank',
    'Union Bank',
    'United Bank for Africa (UBA)',
    'Unity Bank',
    'Wema Bank',
    'Zenith Bank'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="robots" content="noindex, nofollow">
    <title>Settings - SpaceNode</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'DM Sans', sans-serif;
            background: radial-gradient(ellipse at 0% 0%, #1a1a2e 0%, #16213e 40%, #0f0f23 100%);
            min-height: 100vh;
            padding: 40px 20px;
            position: relative;
            overflow-x: hidden;
        }
        
        /* Animated mesh gradient overlay */
        body::before {
            content: '';
            position: fixed;
            top: 0;
            left: 0;
            right: 0;
            bottom: 0;
            background: radial-gradient(circle at 20% 50%, rgba(79,110,247,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(124,58,237,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 40% 20%, rgba(236,72,153,0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
        }
        
        .container {
            max-width: 800px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }
        
        /* Glassmorphism Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 40px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(28px, 5vw, 36px);
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }
        
        .back-link {
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 10px 20px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.15);
            transition: all 0.3s ease;
            display: inline-flex;
            align-items: center;
            gap: 8px;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,0.15);
            transform: translateY(-2px);
            border-color: rgba(255,255,255,0.3);
        }
        
        /* Glassmorphism Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 20px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            backdrop-filter: blur(20px);
            animation: slideDown 0.4s ease;
        }
        
        @keyframes slideDown {
            from {
                opacity: 0;
                transform: translateY(-15px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .alert-success {
            background: rgba(34,197,94,0.15);
            color: #4ade80;
            border: 1px solid rgba(34,197,94,0.3);
        }
        
        .alert-error {
            background: rgba(239,68,68,0.15);
            color: #f87171;
            border: 1px solid rgba(239,68,68,0.3);
        }
        
        /* Glassmorphism Settings Grid */
        .settings-grid {
            display: grid;
            gap: 28px;
        }
        
        .settings-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 28px;
            padding: 32px;
            transition: all 0.4s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }
        
        .settings-card:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.25);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        .settings-card:nth-child(1) { animation-delay: 0.05s; }
        .settings-card:nth-child(2) { animation-delay: 0.1s; }
        .settings-card:nth-child(3) { animation-delay: 0.15s; }
        .settings-card:nth-child(4) { animation-delay: 0.2s; }
        
        .card-title {
            font-family: 'Outfit', sans-serif;
            font-size: 20px;
            font-weight: 600;
            color: white;
            margin-bottom: 24px;
            display: flex;
            align-items: center;
            gap: 10px;
            letter-spacing: -0.3px;
        }
        
        .card-title svg {
            color: #a5b4fc;
            width: 22px;
            height: 22px;
        }
        
        /* Glassmorphism Form Elements */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 13px;
            color: rgba(255,255,255,0.7);
        }
        
        input, select {
            width: 100%;
            padding: 14px 18px;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            transition: all 0.3s ease;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            color: white;
        }
        
        input:focus, select:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.1);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.2);
        }
        
        input::placeholder {
            color: rgba(255,255,255,0.4);
        }
        
        input[readonly] {
            background: rgba(255,255,255,0.02);
            cursor: not-allowed;
            color: rgba(255,255,255,0.5);
        }
        
        select option {
            background: #1a1a2e;
            color: white;
        }
        
        .info-text {
            font-size: 11px;
            color: rgba(255,255,255,0.4);
            margin-top: 6px;
        }
        
        /* Glassmorphism Button */
        .btn {
            padding: 14px 28px;
            border: none;
            border-radius: 60px;
            font-size: 14px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s ease;
            font-family: 'Outfit', sans-serif;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 15px rgba(79,110,247,0.3);
        }
        
        .btn-primary::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-primary:hover::before {
            left: 100%;
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79,110,247,0.4);
        }
        
        /* Glassmorphism Account Info */
        .info-row {
            display: flex;
            justify-content: space-between;
            align-items: center;
            padding: 14px 0;
            border-bottom: 1px solid rgba(255,255,255,0.08);
            flex-wrap: wrap;
            gap: 10px;
        }
        
        .info-row:last-child {
            border-bottom: none;
        }
        
        .info-label {
            color: rgba(255,255,255,0.6);
            font-size: 14px;
        }
        
        .info-value {
            font-weight: 500;
            color: white;
            text-transform: capitalize;
        }
        
        .info-value.active {
            color: #4ade80;
        }
        
        .divider {
            height: 1px;
            background: rgba(255,255,255,0.1);
            margin: 28px 0;
        }
        
        /* Responsive Design */
        @media (max-width: 768px) {
            body {
                padding: 20px 15px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            
            .settings-card {
                padding: 24px;
            }
            
            .card-title {
                font-size: 18px;
            }
            
            .info-row {
                flex-direction: column;
                align-items: flex-start;
            }
        }
        
        @media (max-width: 480px) {
            .settings-card {
                padding: 20px;
            }
            
            .btn {
                width: 100%;
            }
        }
        
        /* Custom scrollbar */
        ::-webkit-scrollbar {
            width: 6px;
        }
        
        ::-webkit-scrollbar-track {
            background: rgba(255,255,255,0.05);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb {
            background: rgba(165,180,252,0.4);
            border-radius: 10px;
        }
        
        ::-webkit-scrollbar-thumb:hover {
            background: rgba(165,180,252,0.6);
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <h1>Account Settings</h1>
            <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
        </div>
        
        <?php if ($success): ?>
            <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo sanitize($success); ?></div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
        <?php endif; ?>
        
        <div class="settings-grid">
            <!-- Glassmorphism Profile Settings -->
            <div class="settings-card">
                <div class="card-title">
                    <i class="fas fa-user-circle"></i> Profile Information
                </div>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> First Name</label>
                            <input type="text" name="first_name" value="<?php echo sanitize($user['first_name']); ?>" required maxlength="100" pattern="[a-zA-Z\s\-']+">
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-user"></i> Last Name</label>
                            <input type="text" name="last_name" value="<?php echo sanitize($user['last_name']); ?>" required maxlength="100" pattern="[a-zA-Z\s\-']+">
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-envelope"></i> Email Address</label>
                        <input type="email" name="email" value="<?php echo sanitize($user['email']); ?>" required maxlength="100">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-phone"></i> Phone Number</label>
                        <input type="tel" name="phone" value="<?php echo sanitize($user['phone']); ?>" placeholder="08012345678" pattern="(?:\+234|0)[7-9][0-1][0-9]{8}" title="Enter a valid Nigerian phone number (e.g., 08012345678 or +2348012345678)">
                        <div class="info-text"><i class="fas fa-info-circle"></i> Optional. Format: 08012345678 or +2348012345678</div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-tag"></i> Account Type</label>
                        <input type="text" value="<?php echo ucfirst(sanitize($user['user_type'])); ?>" readonly>
                    </div>
                    
                    <button type="submit" name="update_profile" class="btn btn-primary"><i class="fas fa-save"></i> Update Profile</button>
                </form>
            </div>
            
            <!-- Glassmorphism Change Password -->
            <div class="settings-card">
                <div class="card-title">
                    <i class="fas fa-lock"></i> Change Password
                </div>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label><i class="fas fa-key"></i> Current Password</label>
                        <input type="password" name="current_password" required>
                    </div>
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label><i class="fas fa-lock"></i> New Password</label>
                            <input type="password" name="new_password" required minlength="6" maxlength="255">
                            <div class="info-text"><i class="fas fa-info-circle"></i> Minimum 6 characters</div>
                        </div>
                        <div class="form-group">
                            <label><i class="fas fa-check-circle"></i> Confirm New Password</label>
                            <input type="password" name="confirm_password" required>
                        </div>
                    </div>
                    
                    <button type="submit" name="change_password" class="btn btn-primary"><i class="fas fa-sync-alt"></i> Change Password</button>
                </form>
            </div>
            
            <!-- Glassmorphism Bank Details (Owners Only) -->
            <?php if ($user_type == 'owner'): ?>
            <div class="settings-card">
                <div class="card-title">
                    <i class="fas fa-university"></i> Bank Account Details
                </div>
                <div class="info-text" style="margin-bottom: 20px;"><i class="fas fa-info-circle"></i> Your earnings will be paid to this bank account.</div>
                
                <form method="POST">
                    <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                    
                    <div class="form-group">
                        <label><i class="fas fa-building"></i> Bank Name</label>
                        <select name="bank_name" required>
                            <option value="">Select your bank</option>
                            <?php foreach ($banks as $bank): ?>
                                <option value="<?php echo sanitize($bank); ?>" <?php echo ($user['bank_name'] ?? '') == $bank ? 'selected' : ''; ?>>
                                    <?php echo sanitize($bank); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-credit-card"></i> Account Number</label>
                        <input type="text" name="account_number" value="<?php echo sanitize($user['account_number'] ?? ''); ?>" placeholder="10-digit account number" maxlength="10" pattern="[0-9]{10}" title="Please enter a valid 10-digit account number" required>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-user-tie"></i> Account Name</label>
                        <input type="text" name="account_name" value="<?php echo sanitize($user['account_name'] ?? ''); ?>" placeholder="Name on account" maxlength="100" pattern="[a-zA-Z\s\-\'\.]+" required>
                    </div>
                    
                    <button type="submit" name="update_bank" class="btn btn-primary"><i class="fas fa-save"></i> Update Bank Details</button>
                </form>
            </div>
            <?php endif; ?>
            
            <!-- Glassmorphism Account Information -->
            <div class="settings-card">
                <div class="card-title">
                    <i class="fas fa-info-circle"></i> Account Information
                </div>
                
                <div class="info-row">
                    <span class="info-label"><i class="far fa-calendar-alt"></i> Member Since</span>
                    <span class="info-value"><?php echo date('F d, Y', strtotime($user['created_at'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-user-tag"></i> Account Type</span>
                    <span class="info-value"><?php echo ucfirst(sanitize($user['user_type'])); ?></span>
                </div>
                <div class="info-row">
                    <span class="info-label"><i class="fas fa-circle"></i> Account Status</span>
                    <span class="info-value active"><i class="fas fa-check-circle"></i> Active</span>
                </div>
            </div>
        </div>
    </div>
</body>
</html>