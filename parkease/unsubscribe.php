<?php
session_start(); // Start session at the beginning
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Function to log unsubscribe actions
function logUnsubscribe($email, $method, $status, $db = null) {
    $log_file = __DIR__ . '/logs/unsubscribe.log';
    $log_dir = dirname($log_file);
    
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $log_entry = "[$timestamp] Email: $email | Method: $method | Status: $status | IP: $ip\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
    
    // Also log to database if available
    if ($db) {
        try {
            $log_query = "INSERT INTO newsletter_logs (email, action, ip_address, created_at) 
                          VALUES (:email, 'unsubscribe', :ip, NOW())";
            $log_stmt = $db->prepare($log_query);
            $log_stmt->bindParam(':email', $email, PDO::PARAM_STR);
            $log_stmt->bindParam(':ip', $ip, PDO::PARAM_STR);
            $log_stmt->execute();
        } catch (Exception $e) {
            // Silently fail - logging is not critical
        }
    }
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    error_log("Database connection failed in unsubscribe.php");
    die('System error. Please try again later.');
}

$message = '';
$email = isset($_GET['email']) ? trim($_GET['email']) : '';
$token = isset($_GET['token']) ? trim($_GET['token']) : '';

// Validate email format if provided
if (!empty($email) && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
    $message = '<div class="alert-error">Invalid email address provided.</div>';
    $email = '';
    $token = '';
}

// Process unsubscribe based on method
if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['email'])) {
    // Handle unsubscribe form submission
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    
    if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $message = '<div class="alert-error">Please enter a valid email address.</div>';
    } else {
        try {
            // Check if email exists in newsletter_subscribers table
            $check = $db->prepare("SELECT id, status FROM newsletter_subscribers WHERE email = ?");
            $check->execute([$email]);
            $subscriber = $check->fetch(PDO::FETCH_ASSOC);
            
            if ($subscriber) {
                if ($subscriber['status'] == 'unsubscribed') {
                    $message = '<div class="alert-info">You are already unsubscribed from our newsletter.</div>';
                    logUnsubscribe($email, 'form', 'already_unsubscribed', $db);
                } else {
                    $update = $db->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE email = ?");
                    $update->execute([$email]);
                    
                    if ($update->rowCount() > 0) {
                        $message = '<div class="alert-success">You have been unsubscribed successfully.</div>';
                        logUnsubscribe($email, 'form', 'success', $db);
                    } else {
                        $message = '<div class="alert-error">Failed to unsubscribe. Please try again.</div>';
                        logUnsubscribe($email, 'form', 'failed', $db);
                    }
                }
            } else {
                $message = '<div class="alert-error">Email not found in our newsletter list.</div>';
                logUnsubscribe($email, 'form', 'not_found', $db);
            }
        } catch (PDOException $e) {
            error_log("Unsubscribe query error: " . $e->getMessage());
            $message = '<div class="alert-error">A database error occurred. Please try again later.</div>';
        }
    }
} elseif (!empty($email) && !empty($token)) {
    // Verify token and unsubscribe (from email link)
    try {
        $check = $db->prepare("SELECT id, status FROM newsletter_subscribers WHERE email = ? AND unsubscribe_token = ?");
        $check->execute([$email, $token]);
        $subscriber = $check->fetch(PDO::FETCH_ASSOC);
        
        if ($subscriber) {
            if ($subscriber['status'] == 'unsubscribed') {
                $message = '<div class="alert-info">You are already unsubscribed from our newsletter.</div>';
                logUnsubscribe($email, 'token', 'already_unsubscribed', $db);
            } else {
                $update = $db->prepare("UPDATE newsletter_subscribers SET status = 'unsubscribed', unsubscribed_at = NOW() WHERE email = ?");
                $update->execute([$email]);
                
                if ($update->rowCount() > 0) {
                    $message = '<div class="alert-success">You have been unsubscribed successfully.</div>';
                    logUnsubscribe($email, 'token', 'success', $db);
                } else {
                    $message = '<div class="alert-error">Failed to unsubscribe. Please try again.</div>';
                    logUnsubscribe($email, 'token', 'failed', $db);
                }
            }
        } else {
            $message = '<div class="alert-error">Invalid or expired unsubscribe link. Please use the form below.</div>';
            logUnsubscribe($email, 'token', 'invalid_token', $db);
            // Clear the token to show form
            $email = '';
            $token = '';
        }
    } catch (PDOException $e) {
        error_log("Unsubscribe token verification error: " . $e->getMessage());
        $message = '<div class="alert-error">A database error occurred. Please try again later.</div>';
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Unsubscribe - SpaceNode Newsletter</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #F9FAFB 0%, #F3F4F6 100%);
            display: flex;
            align-items: center;
            justify-content: center;
            min-height: 100vh;
            padding: 20px;
        }
        .container {
            max-width: 450px;
            width: 100%;
            animation: fadeIn 0.5s ease;
        }
        @keyframes fadeIn {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        .card {
            background: white;
            border-radius: 24px;
            padding: 40px;
            box-shadow: 0 20px 40px rgba(0,0,0,0.1);
            text-align: center;
        }
        .icon {
            width: 60px;
            height: 60px;
            background: #FEE2E2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 20px;
        }
        .icon svg {
            width: 30px;
            height: 30px;
            stroke: #DC2626;
        }
        h1 {
            font-size: 24px;
            color: #111827;
            margin-bottom: 10px;
        }
        p {
            color: #6B7280;
            margin-bottom: 30px;
            line-height: 1.6;
        }
        .form-group {
            margin-bottom: 20px;
        }
        input {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #E5E7EB;
            border-radius: 12px;
            font-size: 14px;
            transition: all 0.3s;
        }
        input:focus {
            outline: none;
            border-color: #4F6EF7;
            box-shadow: 0 0 0 3px rgba(79,110,247,0.1);
        }
        .btn {
            width: 100%;
            padding: 12px;
            background: #DC2626;
            color: white;
            border: none;
            border-radius: 12px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.3s;
        }
        .btn:hover {
            background: #B91C1C;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(220,38,38,0.3);
        }
        .alert-success {
            background: #DCFCE7;
            color: #16A34A;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #10B981;
        }
        .alert-error {
            background: #FEE2E2;
            color: #DC2626;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #EF4444;
        }
        .alert-info {
            background: #DBEAFE;
            color: #1E40AF;
            padding: 15px;
            border-radius: 12px;
            margin-bottom: 20px;
            border-left: 4px solid #3B82F6;
        }
        .back-link {
            display: inline-block;
            margin-top: 20px;
            color: #4F6EF7;
            text-decoration: none;
            font-size: 14px;
            transition: color 0.2s;
        }
        .back-link:hover {
            color: #7C3AED;
            text-decoration: underline;
        }
        .footer-note {
            font-size: 12px;
            color: #9CA3AF;
            margin-top: 20px;
        }
        
        @media (max-width: 480px) {
            .card {
                padding: 30px 20px;
            }
            h1 {
                font-size: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="card">
            <div class="icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
            </div>
            <h1>Unsubscribe from Newsletter</h1>
            
            <?php if ($message): ?>
                <?php echo $message; ?>
                <a href="index.php" class="back-link">← Back to SpaceNode</a>
            <?php else: ?>
                <p>We're sorry to see you go. Please enter your email to unsubscribe from our newsletter.</p>
                
                <form method="POST">
                    <div class="form-group">
                        <input type="email" name="email" placeholder="Your email address" required>
                    </div>
                    <button type="submit" class="btn">Unsubscribe</button>
                </form>
                
                <a href="index.php" class="back-link">← No thanks, take me back</a>
                <div class="footer-note">
                    You can also manage your preferences in your account settings.
                </div>
            <?php endif; ?>
        </div>
    </div>
</body>
</html>