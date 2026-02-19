<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $email = trim($_POST['email']);
    $password = $_POST['password'];
    $remember = isset($_POST['remember']);
    
    if (empty($email) || empty($password)) {
        $error = 'Please enter both email and password.';
    } else {
        $query = "SELECT * FROM users WHERE email = :email AND is_active = 1";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        if ($user = $stmt->fetch(PDO::FETCH_ASSOC)) {
            if (password_verify($password, $user['password'])) {
                // Set session
                $_SESSION['user_id'] = $user['id'];
                $_SESSION['user_type'] = $user['user_type'];
                $_SESSION['user_name'] = $user['first_name'] . ' ' . $user['last_name'];
                $_SESSION['user_email'] = $user['email'];
                
                if ($remember) {
                    //Guys this  Set cookies for 30 days
                    setcookie('user_email', $email, time() + (86400 * 30), '/');
                    setcookie('user_password', $password, time() + (86400 * 30), '/'); 
                }
                
                // Redirect to dashboard or home
                header('Location: dashboard.php');
                exit();
            } else {
                $error = 'Invalid email or password.';
            }
        } else {
            $error = 'Invalid email or password.';
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Login - ParkEase</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #6366F1 0%, #8B5CF6 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .login-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 450px;
            overflow: hidden;
        }
        .login-header {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            padding: 40px;
            text-align: center;
        }
        .login-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .login-header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .login-body {
            padding: 40px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-group label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
            color: #374151;
        }
        .form-group input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #E5E7EB;
            border-radius: 10px;
            font-size: 14px;
            transition: border-color 0.3s;
        }
        .form-group input:focus {
            outline: none;
            border-color: #4F6EF7;
            box-shadow: 0 0 0 3px rgba(79,110,247,0.1);
        }
        .form-options {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .remember-me {
            display: flex;
            align-items: center;
            gap: 8px;
            color: #6B7280;
            cursor: pointer;
        }
        .remember-me input {
            width: 16px;
            height: 16px;
            accent-color: #4F6EF7;
        }
        .forgot-password {
            color: #4F6EF7;
            text-decoration: none;
            font-weight: 500;
        }
        .forgot-password:hover {
            text-decoration: underline;
        }
        .btn-login {
            width: 100%;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            border: none;
            padding: 14px;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s;
            margin-bottom: 20px;
        }
        .btn-login:hover {
            transform: translateY(-2px);
        }
        .alert {
            padding: 15px;
            border-radius: 10px;
            margin-bottom: 20px;
            font-size: 14px;
        }
        .alert-error {
            background: #FEE2E2;
            color: #DC2626;
            border: 1px solid #FECACA;
        }
        .register-link {
            text-align: center;
            font-size: 14px;
            color: #6B7280;
        }
        .register-link a {
            color: #4F6EF7;
            text-decoration: none;
            font-weight: 600;
        }
        .register-link a:hover {
            text-decoration: underline;
        }
        .back-home {
            display: block;
            text-align: center;
            margin-top: 20px;
            color: #6B7280;
            text-decoration: none;
            font-size: 13px;
        }
        .back-home:hover {
            color: #4F6EF7;
        }
    </style>
</head>
<body>
    <div class="login-container">
        <div class="login-header">
            <h1>Welcome Back!</h1>
            <p>Sign in to your ParkEase account</p>
        </div>
        <div class="login-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <form method="POST" action="">
                <div class="form-group">
                    <label>Email Address</label>
                    <input type="email" name="email" required placeholder="john@example.com" 
                           value="<?php echo isset($_COOKIE['user_email']) ? $_COOKIE['user_email'] : ''; ?>">
                </div>
                
                <div class="form-group">
                    <label>Password</label>
                    <input type="password" name="password" required placeholder="••••••"
                           value="<?php echo isset($_COOKIE['user_password']) ? $_COOKIE['user_password'] : ''; ?>">
                </div>
                
                <div class="form-options">
                    <label class="remember-me">
                        <input type="checkbox" name="remember" <?php echo isset($_COOKIE['user_email']) ? 'checked' : ''; ?>>
                        <span>Remember me</span>
                    </label>
                    <a href="forgot-password.php" class="forgot-password">Forgot Password?</a>
                </div>
                
                <button type="submit" class="btn-login">Sign In</button>
                
                <div class="register-link">
                    Don't have an account? <a href="register.php">Create Account</a>
                </div>
                
                <a href="index.php" class="back-home">← Back to Home</a>
            </form>
        </div>
    </div>
</body>
</html>