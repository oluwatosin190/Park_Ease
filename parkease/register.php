<?php
require_once 'config/database.php';

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = '';

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $user_type = $_POST['user_type'];
    $first_name = trim($_POST['first_name']);
    $last_name = trim($_POST['last_name']);
    $email = trim($_POST['email']);
    $phone = trim($_POST['phone']);
    $password = $_POST['password'];
    $confirm_password = $_POST['confirm_password'];
    $company_name = isset($_POST['company_name']) ? trim($_POST['company_name']) : '';
    
    // Validation
    if (empty($first_name) || empty($last_name) || empty($email) || empty($password)) {
        $error = 'Please fill in all required fields.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $error = 'Please enter a valid email address.';
    } elseif (strlen($password) < 6) {
        $error = 'Password must be at least 6 characters long.';
    } elseif ($password !== $confirm_password) {
        $error = 'Passwords do not match.';
    } else {
        // Check if email already exists
        $query = "SELECT id FROM users WHERE email = :email";
        $stmt = $db->prepare($query);
        $stmt->bindParam(':email', $email);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            $error = 'Email already registered. Please use a different email or login.';
        } else {
            // Hash password
            $hashed_password = password_hash($password, PASSWORD_DEFAULT);
            
            // Insert user
            $query = "INSERT INTO users (user_type, first_name, last_name, email, phone, password, company_name) 
                      VALUES (:user_type, :first_name, :last_name, :email, :phone, :password, :company_name)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':user_type', $user_type);
            $stmt->bindParam(':first_name', $first_name);
            $stmt->bindParam(':last_name', $last_name);
            $stmt->bindParam(':email', $email);
            $stmt->bindParam(':phone', $phone);
            $stmt->bindParam(':password', $hashed_password);
            $stmt->bindParam(':company_name', $company_name);
            
            if ($stmt->execute()) {
                $success = 'Registration successful! You can now login.';
                // Redirect to login after 2 seconds
                header("refresh:2;url=login.php");
            } else {
                $error = 'Registration failed. Please try again.';
            }
        }
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Register - ParkEase</title>
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
        .register-container {
            background: white;
            border-radius: 20px;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            width: 100%;
            max-width: 600px;
            overflow: hidden;
        }
        .register-header {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            padding: 40px;
            text-align: center;
        }
        .register-header h1 {
            font-size: 28px;
            margin-bottom: 10px;
        }
        .register-header p {
            opacity: 0.9;
            font-size: 14px;
        }
        .register-body {
            padding: 40px;
        }
        .user-type-selector {
            display: flex;
            gap: 20px;
            margin-bottom: 30px;
            background: #F3F4F6;
            padding: 10px;
            border-radius: 12px;
        }
        .user-type-option {
            flex: 1;
            text-align: center;
            padding: 15px;
            border-radius: 10px;
            cursor: pointer;
            transition: all 0.3s;
        }
        .user-type-option.active {
            background: white;
            box-shadow: 0 4px 12px rgba(0,0,0,0.1);
        }
        .user-type-option svg {
            width: 32px;
            height: 32px;
            margin-bottom: 8px;
        }
        .user-type-option h3 {
            font-size: 16px;
            margin-bottom: 4px;
        }
        .user-type-option p {
            font-size: 12px;
            color: #6B7280;
        }
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
        .company-field {
            display: none;
        }
        .company-field.show {
            display: block;
        }
        .btn-register {
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
            margin-top: 20px;
        }
        .btn-register:hover {
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
        .alert-success {
            background: #DCFCE7;
            color: #16A34A;
            border: 1px solid #BBF7D0;
        }
        .login-link {
            text-align: center;
            margin-top: 20px;
            font-size: 14px;
            color: #6B7280;
        }
        .login-link a {
            color: #4F6EF7;
            text-decoration: none;
            font-weight: 600;
        }
        .login-link a:hover {
            text-decoration: underline;
        }
    </style>
</head>
<body>
    <div class="register-container">
        <div class="register-header">
            <h1>Join ParkEase Today</h1>
            <p>Create your account and start parking smarter</p>
        </div>
        <div class="register-body">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <div class="user-type-selector">
                <div class="user-type-option active" data-type="parker" onclick="selectUserType('parker')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#4F6EF7" stroke-width="2">
                        <circle cx="12" cy="8" r="4"/>
                        <path d="M5 20v-2a7 7 0 0 1 14 0v2"/>
                    </svg>
                    <h3>Parker</h3>
                    <p>I want to find parking</p>
                </div>
                <div class="user-type-option" data-type="owner" onclick="selectUserType('owner')">
                    <svg viewBox="0 0 24 24" fill="none" stroke="#4F6EF7" stroke-width="2">
                        <path d="M3 9l9-7 9 7v11a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2z"/>
                        <polyline points="9 22 9 12 15 12 15 22"/>
                    </svg>
                    <h3>Parking Owner</h3>
                    <p>I own parking spaces</p>
                </div>
            </div>
            
            <form method="POST" action="" onsubmit="return validateForm()">
                <input type="hidden" name="user_type" id="user_type" value="parker">
                
                <div class="form-row">
                    <div class="form-group">
                        <label>First Name *</label>
                        <input type="text" name="first_name" required placeholder="John">
                    </div>
                    <div class="form-group">
                        <label>Last Name *</label>
                        <input type="text" name="last_name" required placeholder="Doe">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Email Address *</label>
                    <input type="email" name="email" required placeholder="john@example.com">
                </div>
                
                <div class="form-group">
                    <label>Phone Number</label>
                    <input type="tel" name="phone" placeholder="+1 234 567 8900">
                </div>
                
                <div class="company-field" id="company_field">
                    <div class="form-group">
                        <label>Company/Business Name</label>
                        <input type="text" name="company_name" placeholder="Your parking business name">
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Password *</label>
                        <input type="password" name="password" required minlength="6" placeholder="••••••">
                    </div>
                    <div class="form-group">
                        <label>Confirm Password *</label>
                        <input type="password" name="confirm_password" required minlength="6" placeholder="••••••">
                    </div>
                </div>
                
                <button type="submit" class="btn-register">Create Account</button>
            </form>
            
            <div class="login-link">
                Already have an account? <a href="login.php">Sign In</a>
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
        
        function validateForm() {
            const password = document.querySelector('input[name="password"]').value;
            const confirm = document.querySelector('input[name="confirm_password"]').value;
            
            if (password !== confirm) {
                alert('Passwords do not match!');
                return false;
            }
            return true;
        }
        
        selectUserType('parker');
    </script>
</body>
</html>