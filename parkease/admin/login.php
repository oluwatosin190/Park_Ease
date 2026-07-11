<?php
require_once '../config/database.php';

// Check if session is already started before starting a new one
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Redirect if already logged in
if (isset($_SESSION['admin_id'])) {
    header('Location: index.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$error = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $username = trim($_POST['username']);
    $password = $_POST['password'];
    
    if (empty($username) || empty($password)) {
        $error = 'Please enter username and password';
    } else {
        // Check if username is email or username
        $query = "SELECT * FROM admins WHERE (username = :username OR email = :username) AND is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':username', $username);
        $stmt->execute();
        
        $admin = $stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($admin && password_verify($password, $admin['password'])) {
            // Start session if not already started
            if (session_status() == PHP_SESSION_NONE) {
                session_start();
            }
            
            $_SESSION['admin_id'] = $admin['id'];
            $_SESSION['admin_username'] = $admin['username'];
            $_SESSION['admin_name'] = $admin['full_name'];
            $_SESSION['admin_role'] = $admin['role'];
            
            // Update last login
            $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
            $update = "UPDATE admins SET last_login = NOW(), last_ip = :ip WHERE id = :id";
            $update_stmt = $db->prepare($update);
            $update_stmt->bindParam(':ip', $ip);
            $update_stmt->bindParam(':id', $admin['id']);
            $update_stmt->execute();
            
            // Log login
            $log = "INSERT INTO admin_logs (admin_id, action, ip_address) VALUES (:id, 'login', :ip)";
            $log_stmt = $db->prepare($log);
            $log_stmt->bindParam(':id', $admin['id']);
            $log_stmt->bindParam(':ip', $ip);
            $log_stmt->execute();
            
            header('Location: index.php');
            exit();
        } else {
            $error = 'Invalid username or password';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Admin Login - SpaceNode</title>
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
            background: radial-gradient(circle at 20% 50%, rgba(79,110,247,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 80% 80%, rgba(124,58,237,0.15) 0%, transparent 50%),
                        radial-gradient(circle at 40% 20%, rgba(236,72,153,0.1) 0%, transparent 50%);
            pointer-events: none;
            z-index: 0;
            animation: meshPulse 12s ease-in-out infinite alternate;
        }
        
        @keyframes meshPulse {
            0% { opacity: 0.7; transform: scale(1); }
            100% { opacity: 1; transform: scale(1.02); }
        }
        
        /* Glassmorphism Login Container */
        .login-container {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 40px;
            width: 100%;
            max-width: 450px;
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
            font-size: 28px;
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
            font-size: 14px;
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
        
        .password-wrapper {
            position: relative;
            width: 100%;
        }
        
        .form-group input {
            width: 100%;
            padding: 14px 18px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-size: 15px;
            font-family: 'DM Sans', sans-serif;
            color: white;
            transition: all 0.3s ease;
        }
        
        .password-wrapper input {
            padding-right: 48px;
        }
        
        .form-group input:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.12);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.2);
        }
        
        .form-group input::placeholder {
            color: rgba(255,255,255,0.4);
        }
        
        /* Eye Toggle Button */
        .eye-toggle {
            position: absolute;
            right: 16px;
            top: 50%;
            transform: translateY(-50%);
            background: transparent;
            border: none;
            cursor: pointer;
            color: rgba(255,255,255,0.6);
            font-size: 18px;
            padding: 0;
            display: none;  /* Hidden by default */
            align-items: center;
            justify-content: center;
            transition: color 0.2s ease;
            z-index: 2;
        }
        
        .eye-toggle.visible {
            display: flex;   /* becomes visible once typing starts */
        }
        
        .eye-toggle:hover {
            color: rgba(165,180,252,0.9);
        }
        
        .eye-toggle:focus {
            outline: none;
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
        
        /* Glassmorphism Alert */
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
        
        /* Responsive Design */
        @media (max-width: 600px) {
            .login-header {
                padding: 35px 30px;
            }
            
            .login-header h1 {
                font-size: 26px;
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
            
            .eye-toggle {
                font-size: 16px;
                right: 14px;
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
                font-size: 24px;
            }
            
            .login-body {
                padding: 25px 20px;
            }
            
            .alert {
                padding: 12px 16px;
                font-size: 13px;
            }
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1><i class="fas fa-parking"></i> SpaceNode Admin</h1>
            <p>Secure Admin Access Portal</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-error">
                    <i class="fas fa-exclamation-triangle"></i> <?php echo htmlspecialchars($error); ?>
                </div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label><i class="fas fa-user"></i> Username or Email</label>
                    <input type="text" name="username" required placeholder="Enter username or email">
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-lock"></i> Password</label>
                    <div class="password-wrapper">
                        <input type="password" name="password" id="passwordField" required placeholder="Enter password" autocomplete="off">
                        <button type="button" id="eyeToggleBtn" class="eye-toggle" aria-label="Show password">
                            <i class="fas fa-eye-slash"></i>
                        </button>
                    </div>
                </div>
                
                <button type="submit" class="btn-login">
                    <i class="fas fa-sign-in-alt"></i> Login to Dashboard
                </button>
            </form>
            
            
        </div>
    </div>

    <script>
        (function() {
            const passwordInput = document.getElementById('passwordField');
            const eyeButton = document.getElementById('eyeToggleBtn');
            
            if (!passwordInput || !eyeButton) return;
            
            function updateEyeIcon() {
                if (!passwordInput || !eyeButton) return;
                if (passwordInput.type === 'password') {
                    eyeButton.innerHTML = '<i class="fas fa-eye-slash"></i>';
                } else {
                    eyeButton.innerHTML = '<i class="fas fa-eye"></i>';
                }
            }
            
            function togglePasswordVisibility(e) {
                if (!passwordInput) return;
                e.preventDefault();
                e.stopPropagation();
                if (passwordInput.type === 'password') {
                    passwordInput.type = 'text';
                } else {
                    passwordInput.type = 'password';
                }
                updateEyeIcon();
                passwordInput.focus();
            }
            
            function handleTyping() {
                if (!passwordInput || !eyeButton) return;
                if (passwordInput.value.length > 0) {
                    eyeButton.classList.add('visible');
                } else {
                    eyeButton.classList.remove('visible');
                }
            }
            
          
            passwordInput.addEventListener('input', handleTyping);
            passwordInput.addEventListener('change', handleTyping);
            
          
            eyeButton.addEventListener('click', togglePasswordVisibility);
            
          
            if (passwordInput.value.length > 0) {
                eyeButton.classList.add('visible');
            } else {
                eyeButton.classList.remove('visible');
            }
        })();
    </script>
</body>
</html>