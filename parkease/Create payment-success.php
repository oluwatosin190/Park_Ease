<?php
session_start(); // Start session at the beginning
require_once 'config/database.php';
require_once 'includes/paystack-api.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Get payment reference from URL
$reference = isset($_GET['reference']) ? trim($_GET['reference']) : '';
$trxref = isset($_GET['trxref']) ? trim($_GET['trxref']) : '';

if (empty($reference) && empty($trxref)) {
    $_SESSION['error'] = 'Invalid payment reference.';
    header('Location: index.php');
    exit();
}

$ref = $reference ?: $trxref;

// Validate reference format (Paystack references are alphanumeric)
if (!preg_match('/^[A-Za-z0-9_-]+$/', $ref)) {
    error_log("Invalid payment reference format: $ref");
    $_SESSION['error'] = 'Invalid payment reference format.';
    header('Location: index.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$success = false;
$booking_ref = '';
$amount = 0;
$reservation_id = 0;
$error_message = '';
$verification_data = null;

// Check if this reference was already processed (prevent double processing)
$check_query = "SELECT id, status, payment_status, payment_verified, booking_reference, total_amount FROM reservations WHERE paystack_reference = :ref";
$check_stmt = $db->prepare($check_query);
$check_stmt->bindParam(':ref', $ref, PDO::PARAM_STR);
$check_stmt->execute();
$existing_reservation = $check_stmt->fetch(PDO::FETCH_ASSOC);

if ($existing_reservation && $existing_reservation['payment_verified'] == 1) {
    // Already processed, just show success
    $success = true;
    $booking_ref = $existing_reservation['booking_reference'] ?? '';
    $amount = $existing_reservation['total_amount'] ?? 0;
    $reservation_id = $existing_reservation['id'] ?? 0;
    error_log("Payment already processed for reference: $ref");
} else {
    // Verify payment with Paystack
    try {
        $paystack = new PaystackAPI();
        $verification = $paystack->verifyTransaction($ref);
        
        // Log verification attempt
        error_log("Paystack verification for $ref: " . ($verification['status'] ? 'Success' : 'Failed - ' . ($verification['message'] ?? 'Unknown error')));
        
        if ($verification['status'] && $verification['payment_status'] == 'success') {
            // Find reservation by paystack reference
            $query = "SELECT r.*, u.email, u.first_name, u.last_name 
                      FROM reservations r 
                      JOIN users u ON r.user_id = u.id
                      WHERE r.paystack_reference = :ref";
            $stmt = $db->prepare($query);
            $stmt->bindParam(':ref', $ref, PDO::PARAM_STR);
            $stmt->execute();
            $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
            
            if ($reservation) {
                // Check if payment amount matches
                $expected_amount = $reservation['total_amount'] * 100; // Paystack uses kobo
                $actual_amount = $verification['amount'] ?? 0;
                
                if ($actual_amount < $expected_amount) {
                    error_log("Payment amount mismatch for reservation {$reservation['id']}: Expected ₦" . ($expected_amount/100) . ", Received ₦" . ($actual_amount/100));
                    $error_message = 'Payment amount does not match booking amount. Please contact support.';
                } else {
                    // Update payment status
                    $update = "UPDATE reservations SET 
                               payment_status = 'paid',
                               payment_date = NOW(),
                               payment_verified = 1,
                               paystack_response = :response,
                               status = 'confirmed'
                               WHERE id = :id";
                    $update_stmt = $db->prepare($update);
                    $response_json = json_encode($verification);
                    $update_stmt->bindParam(':response', $response_json, PDO::PARAM_STR);
                    $update_stmt->bindParam(':id', $reservation['id'], PDO::PARAM_INT);
                    
                    if ($update_stmt->execute()) {
                        $success = true;
                        $booking_ref = $reservation['booking_reference'];
                        $amount = $reservation['total_amount'];
                        $reservation_id = $reservation['id'];
                        
                        // Log successful payment
                        error_log("Payment successful for reservation {$reservation['id']}, user {$reservation['user_id']}, amount ₦$amount");
                        
                        // Update available spots
                        $update_spots = $db->prepare("UPDATE parking_spaces SET available_spots = available_spots - 1 WHERE id = :id");
                        $update_spots->bindParam(':id', $reservation['parking_id'], PDO::PARAM_INT);
                        $update_spots->execute();
                        
                        // Send confirmation email
                        try {
                            require_once 'includes/email-functions.php';
                            $emailer = new EmailNotifications($db);
                            $emailer->sendPaymentConfirmation($reservation['id']);
                            error_log("Confirmation email sent for reservation {$reservation['id']}");
                        } catch (Exception $e) {
                            error_log("Failed to send confirmation email: " . $e->getMessage());
                            // Don't fail the payment if email fails
                        }
                        
                        // Generate and send PIN
                        try {
                            require_once 'includes/pin-functions.php';
                            $pinManager = new PinManager($db);
                            $pin = $pinManager->createAndSavePin($reservation['id']);
                            $emailer->sendPinEmail($reservation['id']);
                            error_log("PIN generated and sent for reservation {$reservation['id']}");
                        } catch (Exception $e) {
                            error_log("Failed to generate/send PIN: " . $e->getMessage());
                        }
                        
                        // Set session success message
                        $_SESSION['success'] = 'Payment successful! Your booking has been confirmed.';
                        
                    } else {
                        error_log("Failed to update reservation status for {$reservation['id']}");
                        $error_message = 'Failed to update booking status. Please contact support.';
                    }
                }
            } else {
                error_log("No reservation found for reference: $ref");
                $error_message = 'Reservation not found. Please contact support.';
            }
        } else {
            $error_message = $verification['message'] ?? 'Payment verification failed. Please try again or contact support.';
            error_log("Payment verification failed for $ref: $error_message");
        }
    } catch (Exception $e) {
        error_log("Paystack verification exception: " . $e->getMessage());
        $error_message = 'Payment verification encountered an error. Please contact support.';
    }
}

// Determine styles based on success status
$bg_gradient = $success ? 'linear-gradient(135deg, #667eea 0%, #764ba2 100%)' : 'linear-gradient(135deg, #f43f5e 0%, #e11d48 100%)';
$icon_bg = $success ? '#10B981' : '#FEE2E2';
$icon_svg_class = $success ? 'success-svg' : 'error-svg';
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Payment <?php echo $success ? 'Successful' : 'Status'; ?> - SpaceNode</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: <?php echo $bg_gradient; ?>;
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }
        .success-card {
            background: white;
            border-radius: 20px;
            padding: 50px;
            max-width: 500px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.3);
            animation: slideUp 0.5s ease;
        }
        .success-icon {
            width: 100px;
            height: 100px;
            background: <?php echo $icon_bg; ?>;
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            animation: scaleIn 0.5s ease 0.2s both;
        }
        .success-icon svg {
            width: 50px;
            height: 50px;
        }
        .success-svg {
            fill: white;
        }
        .error-svg {
            stroke: #DC2626;
            fill: none;
            stroke-width: 2;
        }
        h1 {
            font-size: 32px;
            color: #111827;
            margin-bottom: 15px;
        }
        .amount {
            font-size: 48px;
            font-weight: 700;
            color: #10B981;
            margin: 20px 0;
        }
        .amount::before {
            content: '₦';
            font-size: 30px;
            margin-right: 5px;
        }
        .ref {
            background: #F3F4F6;
            padding: 12px;
            border-radius: 8px;
            font-family: monospace;
            font-size: 14px;
            margin: 20px 0;
            word-break: break-all;
        }
        .error-message {
            background: #FEF3C7;
            color: #D97706;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            font-size: 14px;
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
        .btn-danger {
            background: #DC2626;
            color: white;
        }
        .btn-danger:hover {
            background: #B91C1C;
            transform: translateY(-2px);
        }
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(20px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }
        @media (max-width: 480px) {
            .success-card {
                padding: 30px 20px;
            }
            h1 {
                font-size: 24px;
            }
            .amount {
                font-size: 32px;
            }
            .buttons {
                flex-direction: column;
                gap: 10px;
            }
        }
    </style>
</head>
<body>
    <div class="success-card">
        <div class="success-icon">
            <?php if ($success): ?>
                <svg class="success-svg" viewBox="0 0 24 24">
                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                </svg>
            <?php else: ?>
                <svg class="error-svg" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <line x1="15" y1="9" x2="9" y2="15"/>
                    <line x1="9" y1="9" x2="15" y2="15"/>
                </svg>
            <?php endif; ?>
        </div>
        
        <?php if ($success): ?>
            <h1>Payment Successful! 🎉</h1>
            <p style="color: #6B7280; margin-bottom: 20px;">Your booking has been confirmed</p>
            
            <div class="amount">₦<?php echo number_format($amount, 2); ?></div>
            
            <div class="ref">
                <strong>Booking Reference:</strong> <?php echo sanitize($booking_ref); ?><br>
                <strong style="font-size: 12px; color: #6B7280;">Payment Reference:</strong> <?php echo sanitize($ref); ?>
            </div>
            
            <p style="color: #6B7280; font-size: 14px; margin: 20px 0;">
                📧 A confirmation email has been sent to your email address.<br>
                🔑 Your access PIN will be sent separately.
            </p>
            
            <div class="buttons">
                <a href="reservation-details.php?id=<?php echo $reservation_id; ?>" class="btn btn-primary">View Booking Details</a>
                <a href="dashboard.php" class="btn btn-secondary">Go to Dashboard</a>
            </div>
            
        <?php elseif (!empty($error_message)): ?>
            <h1>Payment Verification Failed</h1>
            
            <div class="error-message">
                <?php echo sanitize($error_message); ?>
            </div>
            
            <div class="ref">
                <strong>Payment Reference:</strong> <?php echo sanitize($ref); ?>
            </div>
            
            <p style="color: #6B7280; margin: 20px 0;">
                Your payment may have been processed but we couldn't verify it. 
                Please check your email for confirmation or contact support.
            </p>
            
            <div class="buttons">
                <a href="contact.php" class="btn btn-danger">Contact Support</a>
                <a href="dashboard.php" class="btn btn-secondary">Go to Dashboard</a>
            </div>
            
        <?php else: ?>
            <h1>Payment Processing</h1>
            <p style="color: #6B7280; margin: 20px 0;">
                Your payment is being processed. You'll receive a confirmation email shortly.
            </p>
            
            <div class="ref">
                Reference: <?php echo sanitize($ref); ?>
            </div>
            
            <div class="buttons">
                <a href="dashboard.php" class="btn btn-primary">Go to Dashboard</a>
                <a href="contact.php" class="btn btn-secondary">Contact Support</a>
            </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Prevent back button from showing cached page
        window.addEventListener('pageshow', function(event) {
            if (event.persisted) {
                window.location.reload();
            }
        });
        
        <?php if ($success && isset($_SESSION['user_id'])): ?>
        // Optional: Send analytics event
        if (typeof gtag !== 'undefined') {
            gtag('event', 'purchase', {
                'transaction_id': '<?php echo sanitize($booking_ref); ?>',
                'value': <?php echo $amount; ?>,
                'currency': 'NGN'
            });
        }
        <?php endif; ?>
    </script>
</body>
</html>