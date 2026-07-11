<?php
session_start();
require_once 'config/database.php';

// Function to sanitize input
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Function to validate Nigerian phone number (optional)
function validatePhone($phone) {
    if (empty($phone)) return true;
    return preg_match('/^(?:\+234|0)[7-9][0-1][0-9]{8}$/', $phone);
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    error_log("Database connection failed in register.php");
    die('System error. Please try again later.');
}

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_type = isset($_POST['user_type']) ? sanitize($_POST['user_type']) : '';
    $first_name = isset($_POST['first_name']) ? trim($_POST['first_name']) : '';
    $last_name = isset($_POST['last_name']) ? trim($_POST['last_name']) : '';
    $email = isset($_POST['email']) ? trim($_POST['email']) : '';
    $phone = isset($_POST['phone']) ? trim($_POST['phone']) : '';
    $password = isset($_POST['password']) ? $_POST['password'] : '';
    $confirm_password = isset($_POST['confirm_password']) ? $_POST['confirm_password'] : '';
    $company_name = isset($_POST['company_name']) ? trim($_POST['company_name']) : '';
    
    $valid_user_types = ['parker', 'owner'];
    if (!in_array($user_type, $valid_user_types)) {
        $error = 'Invalid user type selected.';
    }
    
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
        $errors[] = 'Email address cannot exceed 100 characters.';
    }
    
    if (!empty($phone) && !validatePhone($phone)) {
        $errors[] = 'Please enter a valid phone number (e.g., 08012345678 or +2348012345678).';
    }
    
    if (empty($password)) {
        $errors[] = 'Password is required.';
    } elseif (strlen($password) < 6) {
        $errors[] = 'Password must be at least 6 characters long.';
    } elseif (strlen($password) > 255) {
        $errors[] = 'Password cannot exceed 255 characters.';
    }
    
    if ($password !== $confirm_password) {
        $errors[] = 'Passwords do not match.';
    }
    
    if ($user_type == 'owner' && !empty($company_name) && strlen($company_name) > 100) {
        $errors[] = 'Company name cannot exceed 100 characters.';
    }
    
    if (empty($errors)) {
        try {
            $check_query = "SELECT id FROM users WHERE email = :email";
            $check_stmt = $db->prepare($check_query);
            $check_stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $check_stmt->execute();
            
            if ($check_stmt->rowCount() > 0) {
                $error = 'Email already registered. Please use a different email or <a href="login.php">login</a>.';
            } else {
                $hashed_password = password_hash($password, PASSWORD_DEFAULT);
                
                $db->beginTransaction();
                
                $insert_query = "INSERT INTO users 
                                (user_type, first_name, last_name, email, phone, password, company_name, is_active, created_at) 
                                VALUES 
                                (:user_type, :first_name, :last_name, :email, :phone, :password, :company_name, 1, NOW())";
                
                $insert_stmt = $db->prepare($insert_query);
                $insert_stmt->bindParam(':user_type', $user_type, PDO::PARAM_STR);
                $insert_stmt->bindParam(':first_name', $first_name, PDO::PARAM_STR);
                $insert_stmt->bindParam(':last_name', $last_name, PDO::PARAM_STR);
                $insert_stmt->bindParam(':email', $email, PDO::PARAM_STR);
                $insert_stmt->bindParam(':phone', $phone, PDO::PARAM_STR);
                $insert_stmt->bindParam(':password', $hashed_password, PDO::PARAM_STR);
                $insert_stmt->bindParam(':company_name', $company_name, PDO::PARAM_STR);
                
                if ($insert_stmt->execute()) {
                    $user_id = $db->lastInsertId();
                    $db->commit();
                    
                    error_log("New user registered - ID: $user_id, Type: $user_type, Email: $email, IP: {$_SERVER['REMOTE_ADDR']}");
                    
                    $success = 'Registration successful! Redirecting to login...';
                    $password = '';
                    $confirm_password = '';
                    
                    try {
                        require_once 'includes/email-functions.php';
                        $emailer = new EmailNotifications($db);
                        $emailer->sendWelcomeEmail($user_id);
                    } catch (Exception $e) {
                        error_log("Failed to send welcome email: " . $e->getMessage());
                    }
                    
                    header("refresh:2;url=login.php");
                    
                } else {
                    $db->rollBack();
                    error_log("Failed to insert user: " . print_r($insert_stmt->errorInfo(), true));
                    $error = 'Registration failed. Please try again.';
                }
            }
        } catch (PDOException $e) {
            if ($db->inTransaction()) {
                $db->rollBack();
            }
            error_log("Database error in register.php: " . $e->getMessage());
            $error = 'A database error occurred. Please try again later.';
        }
    } else {
        $error = implode('<br>', $errors);
    }
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="robots" content="noindex, nofollow">
    <title>Register - SpaceNode</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        
        body {
            font-family: 'DM Sans', sans-serif;
            background: radial-gradient(ellipse at 0% 0%, #1a1a2e 0%, #16213e 40%, #0f0f23 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
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
            background: radial-gradient(circle at 20% 50%, rgba(79,110,247,0.2) 0%, transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(124,58,237,0.2) 0%, transparent 50%),
                        radial-gradient(circle at 40% 20%, rgba(236,72,153,0.12) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
            animation: pulseBg 8s ease-in-out infinite;
        }
        
        @keyframes pulseBg {
            0%, 100% { opacity: 0.5; }
            50% { opacity: 1; }
        }
        
        /* Glassmorphism Register Container */
        .register-container {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 40px;
            width: 100%;
            max-width: 650px;
            overflow: hidden;
            animation: fadeInUp 0.6s cubic-bezier(0.4,0,0.2,1);
            box-shadow: 0 25px 60px rgba(0,0,0,0.3);
            position: relative;
            z-index: 1;
        }
        
        @keyframes fadeInUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Glassmorphism Register Header */
        .register-header {
            background: linear-gradient(135deg, rgba(79,110,247,0.15), rgba(124,58,237,0.15));
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 45px 40px;
            text-align: center;
        }
        
        .register-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 32px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #a5b4fc, #c4b5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 12px;
            letter-spacing: -0.5px;
        }
        
        .register-header p {
            color: rgba(255,255,255,0.7);
            font-size: 15px;
        }
        
        /* Glassmorphism Register Body */
        .register-body {
            padding: 40px;
        }
        
        /* Glassmorphism User Type Selector */
        .user-type-selector {
            display: flex;
            gap: 20px;
            margin-bottom: 32px;
            background: rgba(255,255,255,0.05);
            padding: 12px;
            border-radius: 60px;
            backdrop-filter: blur(10px);
        }
        
        .user-type-option {
            flex: 1;
            text-align: center;
            padding: 18px;
            border-radius: 50px;
            cursor: pointer;
            transition: all 0.3s ease;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .user-type-option.active {
            background: linear-gradient(135deg, rgba(79,110,247,0.2), rgba(124,58,237,0.2));
            border-color: rgba(165,180,252,0.5);
            box-shadow: 0 4px 15px rgba(0,0,0,0.1);
        }
        
        .user-type-option svg {
            width: 36px;
            height: 36px;
            margin-bottom: 8px;
            stroke: #a5b4fc;
        }
        
        .user-type-option h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 16px;
            font-weight: 600;
            color: white;
            margin-bottom: 4px;
        }
        
        .user-type-option p {
            font-size: 12px;
            color: rgba(255,255,255,0.6);
        }
        
        /* Password field with eye toggle - only visible when typing */
        .password-wrapper {
            position: relative;
            width: 100%;
        }
        
        .password-wrapper input {
            width: 100%;
            padding: 14px 48px 14px 18px;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-size: 15px;
            font-family: 'DM Sans', sans-serif;
            color: white;
            transition: all 0.3s ease;
        }
        
        .password-wrapper input:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.1);
            box-shadow: 0 0 0 4px rgba(79,110,247,0.2);
        }
        
        .password-wrapper input::placeholder {
            color: rgba(255,255,255,0.4);
        }
        
        .toggle-password {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: none;
            border: none;
            cursor: pointer;
            color: rgba(255,255,255,0.6);
            font-size: 18px;
            transition: all 0.3s ease;
            padding: 0;
            display: flex;
            align-items: center;
            justify-content: center;
            z-index: 2;
            opacity: 0;
            visibility: hidden;
            pointer-events: none;
        }
        
        /* Show eye button only when user has started typing (non-empty value) */
        .password-wrapper input:not(:placeholder-shown) ~ .toggle-password {
            opacity: 1;
            visibility: visible;
            pointer-events: auto;
        }
        
        .toggle-password:hover {
            color: #a5b4fc;
        }
        
        .toggle-password i {
            pointer-events: none;
        }
        
        /* Glassmorphism Form Elements */
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
            margin-bottom: 20px;
        }
        
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-group label {
            display: block;
            margin-bottom: 10px;
            font-weight: 500;
            font-size: 14px;
            color: rgba(255,255,255,0.8);
        }
        
        .form-group label i {
            margin-right: 8px;
            color: #a5b4fc;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 18px;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-size: 15px;
            font-family: 'DM Sans', sans-serif;
            color: white;
            transition: all 0.3s ease;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.1);
            box-shadow: 0 0 0 4px rgba(79,110,247,0.2);
        }
        
        .form-group input::placeholder {
            color: rgba(255,255,255,0.4);
        }
        
        .form-group input:disabled {
            background: rgba(255,255,255,0.02);
            cursor: not-allowed;
        }
        
        /* Company Field */
        .company-field {
            display: none;
        }
        
        .company-field.show {
            display: block;
        }
        
        /* Glassmorphism Password Strength */
        .password-strength {
            margin-top: 8px;
            font-size: 12px;
            font-weight: 500;
        }
        
        .strength-weak { color: #f87171; }
        .strength-medium { color: #fbbf24; }
        .strength-strong { color: #4ade80; }
        
        /* Glassmorphism Register Button */
        .btn-register {
            width: 100%;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            border: none;
            padding: 16px;
            border-radius: 60px;
            font-size: 16px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 20px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(79,110,247,0.3);
        }
        
        .btn-register::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-register:hover::before {
            left: 100%;
        }
        
        .btn-register:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(79,110,247,0.4);
        }
        
        .btn-register:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        /* Glassmorphism Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 20px;
            margin-bottom: 24px;
            backdrop-filter: blur(20px);
            animation: slideDown 0.4s ease;
            display: flex;
            align-items: center;
            gap: 12px;
            font-size: 14px;
            font-weight: 500;
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
        
        .alert-error {
            background: rgba(239,68,68,0.15);
            color: #f87171;
            border: 1px solid rgba(239,68,68,0.3);
        }
        
        .alert-error a {
            color: #f87171;
            text-decoration: underline;
        }
        
        .alert-success {
            background: rgba(34,197,94,0.15);
            color: #4ade80;
            border: 1px solid rgba(34,197,94,0.3);
        }
        
        /* Login Link */
        .login-link {
            text-align: center;
            margin-top: 24px;
            font-size: 14px;
            color: rgba(255,255,255,0.6);
        }
        
        .login-link a {
            color: #a5b4fc;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .login-link a:hover {
            color: #c4b5fd;
            text-decoration: underline;
        }
        
        /* Loader Animation */
        .loader {
            display: inline-block;
            width: 16px;
            height: 16px;
            border: 2px solid #fff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.6s linear infinite;
            margin-left: 8px;
            vertical-align: middle;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Responsive Design */
        @media (max-width: 600px) {
            .register-header {
                padding: 35px 30px;
            }
            
            .register-header h1 {
                font-size: 28px;
            }
            
            .register-body {
                padding: 30px 25px;
            }
            
            .form-group input {
                padding: 12px 16px;
                font-size: 14px;
            }
            
            .btn-register {
                padding: 14px;
                font-size: 15px;
            }
            
            .password-wrapper input {
                padding: 12px 48px 12px 16px;
            }
            
            .toggle-password {
                right: 14px;
                font-size: 16px;
            }
        }
        
        @media (max-width: 550px) {
            .user-type-selector {
                padding: 12px;
                gap: 12px;
            }
            
            .user-type-option {
                padding: 12px;
            }
            
            .user-type-option svg {
                width: 28px;
                height: 28px;
            }
            
            .user-type-option h3 {
                font-size: 14px;
            }
            
            .user-type-option p {
                font-size: 10px;
            }
        }
        
        @media (max-width: 480px) {
            .register-header {
                padding: 30px 25px;
            }
            
            .register-header h1 {
                font-size: 26px;
            }
            
            .register-body {
                padding: 25px 20px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            
            .user-type-selector {
                flex-direction: column;
                border-radius: 30px;
            }
            
            .user-type-option {
                border-radius: 30px;
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
    <div class="register-container">
        <div class="register-header">
            <h1><i class="fas fa-user-plus"></i> Join SpaceNode Today</h1>
            <p>Create your account and start parking smarter</p>
        </div>
        <div class="register-body">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
                </div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo $success; ?>
                </div>
            <?php endif; ?>
            
            <div class="user-type-selector">
                <div class="user-type-option active" data-type="parker" onclick="selectUserType('parker')">
                    <h3>Parker</h3>
                    <p>I want to find parking</p>
                </div>
                <div class="user-type-option" data-type="owner" onclick="selectUserType('owner')">
                    <h3>Parking Owner</h3>
                    <p>I own parking spaces</p>
                </div>
            </div>
            
            <form method="POST" action="" id="registerForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                <input type="hidden" name="user_type" id="user_type" value="parker">
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> First Name *</label>
                        <input type="text" name="first_name" required maxlength="100" 
                               pattern="[a-zA-Z\s\-']+" title="Only letters, spaces, hyphens, and apostrophes allowed"
                               value="<?php echo isset($_POST['first_name']) ? sanitize($_POST['first_name']) : ''; ?>"
                               placeholder="John">
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-user"></i> Last Name *</label>
                        <input type="text" name="last_name" required maxlength="100"
                               pattern="[a-zA-Z\s\-']+" title="Only letters, spaces, hyphens, and apostrophes allowed"
                               value="<?php echo isset($_POST['last_name']) ? sanitize($_POST['last_name']) : ''; ?>"
                               placeholder="Doe">
                    </div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email Address *</label>
                    <input type="email" name="email" required maxlength="100"
                           value="<?php echo isset($_POST['email']) ? sanitize($_POST['email']) : ''; ?>"
                           placeholder="john@example.com">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-phone"></i> Phone Number</label>
                    <input type="tel" name="phone" maxlength="20"
                           pattern="(?:\+234|0)[7-9][0-1][0-9]{8}" 
                           title="Enter a valid Nigerian phone number (e.g., 08012345678 or +2348012345678)"
                           value="<?php echo isset($_POST['phone']) ? sanitize($_POST['phone']) : ''; ?>"
                           placeholder="08012345678">
                </div>
                
                <div class="company-field" id="company_field">
                    <div class="form-group">
                        <label><i class="fas fa-building"></i> Company/Business Name</label>
                        <input type="text" name="company_name" maxlength="100"
                               value="<?php echo isset($_POST['company_name']) ? sanitize($_POST['company_name']) : ''; ?>"
                               placeholder="Your parking business name">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-lock"></i> Password *</label>
                        <div class="password-wrapper">
                            <input type="password" name="password" required minlength="6" maxlength="255" id="password" placeholder="••••••">
                            <button type="button" class="toggle-password" data-target="password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                        <div class="password-strength" id="passwordStrength"></div>
                    </div>
                    <div class="form-group">
                        <label><i class="fas fa-check-circle"></i> Confirm Password *</label>
                        <div class="password-wrapper">
                            <input type="password" name="confirm_password" required minlength="6" id="confirm_password" placeholder="••••••">
                            <button type="button" class="toggle-password" data-target="confirm_password">
                                <i class="fas fa-eye"></i>
                            </button>
                        </div>
                    </div>
                </div>
                
                <button type="submit" class="btn-register" id="submitBtn">
                    <i class="fas fa-user-plus"></i> Create Account
                </button>
            </form>
            
            <div class="login-link">
                <i class="fas fa-sign-in-alt"></i> Already have an account? <a href="login.php">Sign In</a>
            </div>
        </div>
    </div>
    
    <script>
        function selectUserType(type) {
            document.getElementById('user_type').value = type;
            
            document.querySelectorAll('.user-type-option').forEach(option => {
                option.classList.remove('active');
            });
            document.querySelector(`[data-type="${type}"]`).classList.add('active');
            
            const companyField = document.getElementById('company_field');
            if (type === 'owner') {
                companyField.classList.add('show');
            } else {
                companyField.classList.remove('show');
            }
        }
        
        function checkPasswordStrength(password) {
            let strength = 0;
            if (password.length >= 6) strength++;
            if (password.length >= 8) strength++;
            if (password.match(/[a-z]/) && password.match(/[A-Z]/)) strength++;
            if (password.match(/[0-9]/)) strength++;
            if (password.match(/[^a-zA-Z0-9]/)) strength++;
            
            if (strength <= 2) return { level: 'weak', text: 'Weak password' };
            if (strength <= 4) return { level: 'medium', text: 'Medium password' };
            return { level: 'strong', text: 'Strong password' };
        }
        
        // Initialize password toggle functionality
        function initPasswordToggle() {
            const toggleButtons = document.querySelectorAll('.toggle-password');
            
            toggleButtons.forEach(button => {
                button.addEventListener('click', function() {
                    const targetId = this.getAttribute('data-target');
                    const input = document.getElementById(targetId);
                    
                    if (input) {
                        // Toggle password type
                        const type = input.getAttribute('type') === 'password' ? 'text' : 'password';
                        input.setAttribute('type', type);
                        
                        // Toggle eye icon
                        const icon = this.querySelector('i');
                        if (icon) {
                            icon.classList.toggle('fa-eye');
                            icon.classList.toggle('fa-eye-slash');
                        }
                    }
                });
            });
        }
        
        const passwordInput = document.getElementById('password');
        if (passwordInput) {
            passwordInput.addEventListener('input', function() {
                const strength = checkPasswordStrength(this.value);
                const strengthDiv = document.getElementById('passwordStrength');
                strengthDiv.textContent = strength.text;
                strengthDiv.className = `password-strength strength-${strength.level}`;
            });
        }
        
        const registerForm = document.getElementById('registerForm');
        if (registerForm) {
            registerForm.addEventListener('submit', function(e) {
                const password = document.getElementById('password').value;
                const confirm = document.getElementById('confirm_password').value;
                const submitBtn = document.getElementById('submitBtn');
                
                if (password !== confirm) {
                    e.preventDefault();
                    alert('Passwords do not match!');
                    return false;
                }
                
                submitBtn.disabled = true;
                submitBtn.innerHTML = 'Creating Account... <span class="loader"></span>';
                return true;
            });
        }
        
        // Initialize password toggle
        initPasswordToggle();
        
        selectUserType('parker');
    </script>
</body>
</html>