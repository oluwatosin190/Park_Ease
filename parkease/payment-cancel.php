<?php
session_start();

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Check if user is logged in (optional - they might have attempted payment without login)
$is_logged_in = isset($_SESSION['user_id']) && !empty($_SESSION['user_id']);
$user_name = $is_logged_in ? ($_SESSION['user_name'] ?? 'User') : 'Guest';

// Get booking ID from URL if available (for retry functionality)
$booking_id = isset($_GET['booking_id']) ? (int)$_GET['booking_id'] : 0;
$reference = isset($_GET['reference']) ? trim($_GET['reference']) : '';
$reason = isset($_GET['reason']) ? sanitize($_GET['reason']) : '';

// Log the cancellation for analytics (optional)
if ($is_logged_in && !empty($reference)) {
    error_log("Payment cancelled - User: {$_SESSION['user_id']}, Reference: $reference, Reason: $reason, IP: {$_SERVER['REMOTE_ADDR']}");
} elseif (!empty($reference)) {
    error_log("Payment cancelled (guest) - Reference: $reference, Reason: $reason, IP: {$_SERVER['REMOTE_ADDR']}");
}

// Define valid cancellation reasons for user-friendly messages
$reason_messages = [
    'user_cancelled' => 'You cancelled the payment process.',
    'timeout' => 'The payment session timed out.',
    'insufficient_funds' => 'Insufficient funds or payment declined.',
    'technical_error' => 'A technical error occurred during processing.',
    'invalid_payment' => 'The payment method could not be processed.',
    'expired' => 'The payment session has expired.'
];

$user_message = isset($reason_messages[$reason]) ? $reason_messages[$reason] : 'Your payment was not completed. You can try again or choose a different payment method.';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Payment Cancelled - SpaceNode</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: linear-gradient(135deg, #f43f5e 0%, #e11d48 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .cancel-card {
            background: white;
            border-radius: 20px;
            padding: 50px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: fadeIn 0.5s ease-out;
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
        .cancel-icon {
            width: 100px;
            height: 100px;
            background: #FEE2E2;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            animation: shake 0.5s ease-out;
        }
        @keyframes shake {
            0%, 100% { transform: translateX(0); }
            10%, 30%, 50%, 70%, 90% { transform: translateX(-5px); }
            20%, 40%, 60%, 80% { transform: translateX(5px); }
        }
        .cancel-icon svg {
            width: 50px;
            height: 50px;
            stroke: #DC2626;
            stroke-width: 2;
            fill: none;
        }
        h1 {
            font-size: 32px;
            color: #111827;
            margin-bottom: 15px;
        }
        p {
            color: #6B7280;
            margin-bottom: 20px;
            line-height: 1.6;
        }
        .reference {
            background: #F3F4F6;
            padding: 10px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 12px;
            margin: 20px 0;
            word-break: break-all;
            color: #4B5563;
        }
        .buttons {
            display: flex;
            gap: 15px;
            margin-top: 30px;
        }
        .btn {
            flex: 1;
            padding: 14px;
            border-radius: 10px;
            text-decoration: none;
            font-weight: 600;
            transition: all 0.3s;
            text-align: center;
            cursor: pointer;
            border: none;
            font-family: 'Inter', sans-serif;
            font-size: 14px;
            display: inline-block;
        }
        .btn-primary {
            background: #4F6EF7;
            color: white;
        }
        .btn-primary:hover {
            background: #3a56d4;
            transform: translateY(-2px);
            box-shadow: 0 5px 15px rgba(79,110,247,0.3);
        }
        .btn-secondary {
            background: #F3F4F6;
            color: #374151;
        }
        .btn-secondary:hover {
            background: #E5E7EB;
            transform: translateY(-2px);
        }
        .btn-outline {
            background: transparent;
            color: #4F6EF7;
            border: 2px solid #4F6EF7;
        }
        .btn-outline:hover {
            background: #4F6EF7;
            color: white;
        }
        .help-text {
            font-size: 12px;
            color: #9CA3AF;
            margin-top: 20px;
        }
        .help-text a {
            color: #4F6EF7;
            text-decoration: none;
        }
        .help-text a:hover {
            text-decoration: underline;
        }
        
        @media (max-width: 480px) {
            .cancel-card {
                padding: 30px 20px;
            }
            h1 {
                font-size: 24px;
            }
            .buttons {
                flex-direction: column;
                gap: 10px;
            }
            .cancel-icon {
                width: 80px;
                height: 80px;
            }
            .cancel-icon svg {
                width: 40px;
                height: 40px;
            }
        }
    </style>
</head>
<body>
    <div class="cancel-card">
        <div class="cancel-icon">
            <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                <circle cx="12" cy="12" r="10"/>
                <line x1="15" y1="9" x2="9" y2="15"/>
                <line x1="9" y1="9" x2="15" y2="15"/>
            </svg>
        </div>
        
        <h1>Payment Cancelled</h1>
        <p><?php echo sanitize($user_message); ?></p>
        
        <?php if (!empty($reference)): ?>
            <div class="reference">
                Reference: <?php echo sanitize($reference); ?>
            </div>
        <?php endif; ?>
        
        <?php if ($booking_id > 0): ?>
            <div class="buttons">
                <a href="process-payment.php?id=<?php echo $booking_id; ?>" class="btn btn-outline">🔄 Retry Payment</a>
                <a href="reservation-details.php?id=<?php echo $booking_id; ?>" class="btn btn-secondary">View Booking</a>
            </div>
        <?php else: ?>
            <div class="buttons">
                <a href="javascript:history.back()" class="btn btn-primary">Try Again</a>
                <a href="dashboard.php" class="btn btn-secondary">Go to Dashboard</a>
            </div>
        <?php endif; ?>
        
        <div class="help-text">
            <p>Need help? <a href="contact.php">Contact our support team</a> and we'll assist you.</p>
            <?php if (!empty($reference)): ?>
                <p style="margin-top: 5px;">Please keep this reference number for support: <?php echo sanitize($reference); ?></p>
            <?php endif; ?>
        </div>
    </div>
    
    <script>
        // Optional: Add analytics tracking for cancelled payments
        <?php if (!empty($reference)): ?>
        if (typeof gtag !== 'undefined') {
            gtag('event', 'payment_cancelled', {
                'reference': '<?php echo sanitize($reference); ?>',
                'reason': '<?php echo sanitize($reason); ?>'
            });
        }
        <?php endif; ?>
        
        // Prevent back button from showing cached page
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                window.location.reload();
            }
        });
        
        // Optional: Clear any pending payment intervals
        window.addEventListener('beforeunload', function() {
            // Clear any intervals if needed
            if (window.paymentInterval) {
                clearInterval(window.paymentInterval);
            }
        });
    </script>
</body>
</html>