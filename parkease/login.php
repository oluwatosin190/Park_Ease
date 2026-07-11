<?php
session_start();
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = '';

// Check if user is already logged in
if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
    // Redirect based on user type
    if (isset($_SESSION['user_type']) && $_SESSION['user_type'] === 'owner') {
        header('Location: dashboard.php');
    } else {
        header('Location: index.php');
    }
    exit();
}

// Check for remember me cookies
if (isset($_COOKIE['user_email']) && isset($_COOKIE['user_token'])) {
    // Validate remember me token (more secure than storing password)
    $token = $_COOKIE['user_token'];
    $email = $_COOKIE['user_email'];
    
    $query = "SELECT * FROM users WHERE email = :email AND is_active = 1";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':email', $email, PDO::PARAM_STR);
    $stmt->execute();
    $user = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($user && isset($user['remember_token']) && hash_equals($user['remember_token'], $token)) {
        // Auto-login
        $_SESSION['user_id'] = $user['id'];
        $_SESSION['user_type'] = $user['user_type'];
        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
        $_SESSION['user_email'] = $user['email'];
        
        error_log("Auto-login successful for user: {$user['email']} via remember token");
        
        // Redirect based on user type
        if ($user['user_type'] === 'owner') {
            header('Location: dashboard.php');
        } else {
            header('Location: index.php');
        }
        exit();
    } else {
        // Invalid token, clear cookies
        setcookie('user_email', '', time() - 3600, '/');
        setcookie('user_token', '', time() - 3600, '/');
    }
}

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        $email = isset($_POST['email']) ? trim($_POST['email']) : '';
        $password = isset($_POST['password']) ? $_POST['password'] : '';
        $remember = isset($_POST['remember']);
        
        if (empty($email) || empty($password)) {
            $error = 'Please enter both email and password.';
        } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            $error = 'Please enter a valid email address.';
        } else {
            $ip = $_SERVER['REMOTE_ADDR'];
            $rate_limit_query = "SELECT COUNT(*) as attempts FROM login_attempts 
                                 WHERE ip_address = :ip AND attempt_time > DATE_SUB(NOW(), INTERVAL 15 MINUTE)";
            $rate_stmt = $db->prepare($rate_limit_query);
            $rate_stmt->bindParam(':ip', $ip, PDO::PARAM_STR);
            $rate_stmt->execute();
            $attempts = $rate_stmt->fetch(PDO::FETCH_ASSOC)['attempts'];
            
            if ($attempts >= 5) {
                $error = 'Too many failed login attempts. Please try again in 15 minutes.';
                error_log("Rate limit exceeded for IP: $ip");
            } else {
                $query = "SELECT id, first_name, last_name, email, password, user_type, is_active, 
                          last_login, failed_login_attempts, remember_token
                          FROM users WHERE email = :email";
                $stmt = $db->prepare($query);
                $stmt->bindParam(':email', $email, PDO::PARAM_STR);
                $stmt->execute();
                $user = $stmt->fetch(PDO::FETCH_ASSOC);
                
                if ($user) {
                    if ($user['is_active'] != 1) {
                        $error = 'Your account has been deactivated. Please contact support.';
                        error_log("Login attempt on deactivated account: $email");
                    } elseif (password_verify($password, $user['password'])) {
                        $reset_query = "UPDATE users SET failed_login_attempts = 0, last_login = NOW() WHERE id = :id";
                        $reset_stmt = $db->prepare($reset_query);
                        $reset_stmt->bindParam(':id', $user['id'], PDO::PARAM_INT);
                        $reset_stmt->execute();
                        
                        $_SESSION['user_id'] = $user['id'];
                        $_SESSION['user_type'] = $user['user_type'];
                        $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                        $_SESSION['user_email'] = $user['email'];
                        $_SESSION['login_time'] = time();
                        
                        if ($remember) {
                            $remember_token = bin2hex(random_bytes(32));
                            
                            $token_update = "UPDATE users SET remember_token = :token WHERE id = :id";
                            $token_stmt = $db->prepare($token_update);
                            $token_stmt->bindParam(':token', $remember_token, PDO::PARAM_STR);
                            $token_stmt->bindParam(':id', $user['id'], PDO::PARAM_INT);
                            $token_stmt->execute();
                            
                            setcookie('user_email', $email, time() + (86400 * 30), '/', '', true, true);
                            setcookie('user_token', $remember_token, time() + (86400 * 30), '/', '', true, true);
                        }
                        
                        $clear_attempts = "DELETE FROM login_attempts WHERE ip_address = :ip";
                        $clear_stmt = $db->prepare($clear_attempts);
                        $clear_stmt->bindParam(':ip', $ip, PDO::PARAM_STR);
                        $clear_stmt->execute();
                        
                        error_log("Successful login for user: $email from IP: $ip");
                        
                        // --- FIXED: Redirect based on user type ---
                        // Check if there was a custom redirect stored (e.g., from trying to book while not logged in)
                        $custom_redirect = isset($_SESSION['redirect_after_login']) ? $_SESSION['redirect_after_login'] : '';
                        unset($_SESSION['redirect_after_login']);
                        
                        if (!empty($custom_redirect)) {
                            header('Location: ' . $custom_redirect);
                        } elseif ($user['user_type'] === 'owner') {
                            header('Location: dashboard.php');
                        } else {
                            // Parker - redirect to landing page
                            header('Location: index.php');
                        }
                        exit();
                        
                    } else {
                        $error = 'Invalid email or password.';
                        
                        $failed_attempts = ($user['failed_login_attempts'] ?? 0) + 1;
                        $update_attempts = "UPDATE users SET failed_login_attempts = :attempts WHERE id = :id";
                        $attempt_stmt = $db->prepare($update_attempts);
                        $attempt_stmt->bindParam(':attempts', $failed_attempts, PDO::PARAM_INT);
                        $attempt_stmt->bindParam(':id', $user['id'], PDO::PARAM_INT);
                        $attempt_stmt->execute();
                        
                        error_log("Failed login attempt for email: $email from IP: $ip (Attempt #$failed_attempts)");
                        
                        $log_attempt = "INSERT INTO login_attempts (ip_address, email, success) VALUES (:ip, :email, 0)";
                        $log_stmt = $db->prepare($log_attempt);
                        $log_stmt->bindParam(':ip', $ip, PDO::PARAM_STR);
                        $log_stmt->bindParam(':email', $email, PDO::PARAM_STR);
                        $log_stmt->execute();
                    }
                } else {
                    $error = 'Invalid email or password.';
                    
                    error_log("Failed login attempt for non-existent email: $email from IP: $ip");
                    
                    $log_attempt = "INSERT INTO login_attempts (ip_address, email, success) VALUES (:ip, :email, 0)";
                    $log_stmt = $db->prepare($log_attempt);
                    $log_stmt->bindParam(':ip', $ip, PDO::PARAM_STR);
                    $log_stmt->bindParam(':email', $email, PDO::PARAM_STR);
                    $log_stmt->execute();
                }
            }
        }
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
    <title>Login - SpaceNode</title>
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
        
        /* Glassmorphism Login Container */
        .login-container {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 40px;
            width: 100%;
            max-width: 480px;
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
        
        /* Glassmorphism Login Header */
        .login-header {
            background: linear-gradient(135deg, rgba(79,110,247,0.15), rgba(124,58,237,0.15));
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 45px 40px;
            text-align: center;
        }
        
        .login-header h1 {
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
        
        .login-header p {
            color: rgba(255,255,255,0.7);
            font-size: 15px;
        }
        
        /* Glassmorphism Login Body */
        .login-body {
            padding: 40px;
        }
        
        /* Glassmorphism Form Groups */
        .form-group {
            margin-bottom: 24px;
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
        
        .password-wrapper {
            position: relative;
            width: 100%;
        }
        
        .password-wrapper input {
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
            padding-right: 48px;
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
        
        /* Glassmorphism Form Options */
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 28px;
            font-size: 14px;
        }
        
        .remember-me {
            display: flex;
            align-items: center;
            gap: 10px;
            color: rgba(255,255,255,0.7);
            cursor: pointer;
        }
        
        .remember-me input {
            width: 18px;
            height: 18px;
            accent-color: #4F6EF7;
            cursor: pointer;
        }
        
        .forgot-password {
            color: #a5b4fc;
            text-decoration: none;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .forgot-password:hover {
            color: #c4b5fd;
            text-decoration: underline;
        }
        
        /* Glassmorphism Login Button */
        .btn-login {
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
            margin-bottom: 24px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(79,110,247,0.3);
        }
        
        .btn-login::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-login:hover::before {
            left: 100%;
        }
        
        .btn-login:hover {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(79,110,247,0.4);
        }
        
        /* Glassmorphism Register Link */
        .register-link {
            text-align: center;
            font-size: 14px;
            color: rgba(255,255,255,0.6);
            margin-bottom: 16px;
        }
        
        .register-link a {
            color: #a5b4fc;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s ease;
        }
        
        .register-link a:hover {
            color: #c4b5fd;
            text-decoration: underline;
        }
        
        /* Glassmorphism Back Home Link */
        .back-home {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            text-align: center;
            color: rgba(255,255,255,0.5);
            text-decoration: none;
            font-size: 13px;
            transition: all 0.3s ease;
        }
        
        .back-home:hover {
            color: rgba(255,255,255,0.8);
            gap: 12px;
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
        
        .alert-success {
            background: rgba(34,197,94,0.15);
            color: #4ade80;
            border: 1px solid rgba(34,197,94,0.3);
        }
        
        /* Responsive Design */
        @media (max-width: 600px) {
            .login-header {
                padding: 35px 30px;
            }
            
            .login-header h1 {
                font-size: 28px;
            }
            
            .login-body {
                padding: 30px 25px;
            }
            
            .form-group input {
                padding: 12px 16px;
                font-size: 14px;
            }
            
            .password-wrapper input {
                padding-right: 44px;
            }
            
            .toggle-password {
                right: 14px;
                font-size: 16px;
            }
            
            .btn-login {
                padding: 14px;
                font-size: 15px;
            }
        }
        
        @media (max-width: 480px) {
            .login-header {
                padding: 30px 25px;
            }
            
            .login-header h1 {
                font-size: 26px;
            }
            
            .login-body {
                padding: 25px 20px;
            }
            
            .form-options {
                flex-direction: column;
                gap: 12px;
                align-items: flex-start;
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
    <div class="login-container">
        <div class="login-header">
            <h1><i class="fas fa-parking"></i> Welcome Back!</h1>
            <p>Sign in to your SpaceNode account</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo sanitize($error); ?>
                </div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success">
                    <i class="fas fa-check-circle"></i> <?php echo sanitize($success); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label><i class="fas fa-envelope"></i> Email Address</label>
                    <input type="email" name="email" required placeholder="john@example.com" 
                           value="<?php echo isset($_COOKIE['user_email']) ? sanitize($_COOKIE['user_email']) : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" required placeholder="••••••" id="password">
                        <button type="button" class="toggle-password" data-target="password">
                            <i class="fas fa-eye"></i>
                        </button>
                    </div>
                </div>
                
                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember" <?php echo isset($_COOKIE['user_email']) ? 'checked' : ''; ?>>
                        <span><i class="fas fa-check-circle"></i> Remember me</span>
                    </label>
                    <a href="forgot-password.php" class="forgot-password"><i class="fas fa-question-circle"></i> Forgot Password?</a>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Sign In
                </button>
                
                <div class="register-link">
                    <i class="fas fa-user-plus"></i> Don't have an account? <a href="register.php">Create Account</a>
                </div>
                
                <a href="index.php" class="back-home">
                    <i class="fas fa-arrow-left"></i> Back to Home
                </a>
            </form>
        </div>
    </div>
    
    <script>
        (function() {
            const passwordInput = document.getElementById('password');
            const toggleButton = document.querySelector('.toggle-password');
            
            if (!passwordInput || !toggleButton) return;
            
            toggleButton.addEventListener('click', function(e) {
                e.preventDefault();
                const type = passwordInput.getAttribute('type') === 'password' ? 'text' : 'password';
                passwordInput.setAttribute('type', type);
                
                const icon = this.querySelector('i');
                if (icon) {
                    icon.classList.toggle('fa-eye');
                    icon.classList.toggle('fa-eye-slash');
                }
                passwordInput.focus();
            });
            
        })();
    </script>
</body>
</html>