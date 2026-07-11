<?php
session_start();
require_once 'config/database.php';
require_once 'includes/paystack-api.php';
require_once 'includes/pin-functions.php';
require_once 'includes/email-functions.php';

$reference = $_GET['reference'] ?? '';
$trxref = $_GET['trxref'] ?? '';

if (empty($reference) && empty($trxref)) {
    header('Location: index.php');
    exit();
}

$ref = $reference ?: $trxref;

$database = new Database();
$db = $database->getConnection();

// Verify payment
$paystack = new PaystackAPI();
$verification = $paystack->verifyTransaction($ref);

if ($verification['status'] && $verification['payment_status'] == 'success') {
    // Find reservation
    $query = "SELECT r.* FROM reservations r WHERE r.paystack_reference = :ref";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':ref', $ref);
    $stmt->execute();
    $reservation = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if ($reservation) {
        // Update payment status if not already updated by webhook
        if ($reservation['payment_status'] != 'paid') {
            $update = "UPDATE reservations SET 
                       payment_status = 'paid',
                       payment_date = NOW(),
                       payment_verified = 1,
                       status = 'confirmed'
                       WHERE id = :id";
            $update_stmt = $db->prepare($update);
            $update_stmt->bindParam(':id', $reservation['id']);
            $update_stmt->execute();
        }
        
        // =====  Generate and save PIN =====
        $pinManager = new PinManager($db);
        $pin = $pinManager->createAndSavePin($reservation['id']);
        
        // Get full reservation details for notifications
        $details_query = "SELECT r.*, u.email, u.first_name, u.last_name, u.phone,
                          ps.name as parking_name, ps.address, ps.city,
                          o.email as owner_email, o.first_name as owner_first_name
                          FROM reservations r
                          JOIN users u ON r.user_id = u.id
                          JOIN parking_spaces ps ON r.parking_id = ps.id
                          JOIN users o ON ps.owner_id = o.id
                          WHERE r.id = :id";
        $details_stmt = $db->prepare($details_query);
        $details_stmt->execute([':id' => $reservation['id']]);
        $details = $details_stmt->fetch(PDO::FETCH_ASSOC);
        
        // Send email with PIN
        $emailer = new EmailNotifications($db);
        $emailer->sendPinEmail($details['id']);
        
        $success = true;
        $booking_ref = $reservation['booking_reference'];
        $amount = $reservation['total_amount'];
        $access_pin = $pin;
    }
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Payment Successful - SpaceNode</title>
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
        }
        
        /* Glassmorphism Success Card */
        .success-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 40px;
            padding: 50px;
            max-width: 550px;
            width: 100%;
            text-align: center;
            box-shadow: 0 20px 60px rgba(0,0,0,0.4);
            animation: slideUp 0.6s cubic-bezier(0.4,0,0.2,1);
            position: relative;
            z-index: 1;
        }
        
        @keyframes slideUp {
            from {
                opacity: 0;
                transform: translateY(30px);
            }
            to {
                opacity: 1;
                transform: translateY(0);
            }
        }
        
        /* Glassmorphism Success Icon */
        .success-icon {
            width: 110px;
            height: 110px;
            background: linear-gradient(135deg, rgba(16,185,129,0.8), rgba(5,150,105,0.8));
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            animation: scaleIn 0.6s cubic-bezier(0.4,0,0.2,1) 0.2s both;
            box-shadow: 0 10px 30px rgba(16,185,129,0.4);
            border: 2px solid rgba(255,255,255,0.3);
        }
        
        @keyframes scaleIn {
            from {
                transform: scale(0);
            }
            to {
                transform: scale(1);
            }
        }
        
        .success-icon svg {
            width: 55px;
            height: 55px;
            fill: white;
        }
        
        h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 36px;
            font-weight: 800;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 15px;
            letter-spacing: -0.5px;
        }
        
        .subtitle {
            color: rgba(255,255,255,0.7);
            margin-bottom: 25px;
            font-size: 15px;
        }
        
        /* Glassmorphism Amount */
        .amount {
            font-family: 'Outfit', sans-serif;
            font-size: 52px;
            font-weight: 800;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin: 20px 0;
        }
        
        .amount::before {
            content: '₦';
            font-size: 32px;
            margin-right: 5px;
        }
        
        /* Glassmorphism Reference */
        .ref {
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            padding: 14px 20px;
            border-radius: 60px;
            font-family: monospace;
            font-size: 15px;
            margin: 20px 0;
            border: 1px solid rgba(255,255,255,0.15);
            color: rgba(255,255,255,0.9);
        }
        
        /* Glassmorphism PIN Box */
        .pin-box {
            background: linear-gradient(135deg, rgba(79,110,247,0.15), rgba(124,58,237,0.15));
            backdrop-filter: blur(20px);
            border: 1px solid rgba(165,180,252,0.4);
            color: white;
            padding: 35px;
            border-radius: 28px;
            margin: 30px 0;
            position: relative;
            overflow: hidden;
        }
        
        .pin-box::before {
            content: '';
            position: absolute;
            top: -50%;
            right: -50%;
            width: 200%;
            height: 200%;
            background: radial-gradient(circle, rgba(255,255,255,0.08) 0%, transparent 70%);
            animation: rotate 20s linear infinite;
        }
        
        @keyframes rotate {
            from { transform: rotate(0deg); }
            to { transform: rotate(360deg); }
        }
        
        .pin-label {
            font-size: 14px;
            color: rgba(255,255,255,0.7);
            margin-bottom: 12px;
            letter-spacing: 1px;
        }
        
        .pin-number {
            font-family: 'Outfit', sans-serif;
            font-size: 54px;
            font-weight: 800;
            letter-spacing: 12px;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            position: relative;
            z-index: 1;
        }
        
        .pin-note {
            font-size: 12px;
            color: rgba(255,255,255,0.6);
            margin-top: 12px;
            position: relative;
            z-index: 1;
        }
        
        /* Info Text */
        .info-text {
            color: rgba(255,255,255,0.6);
            font-size: 13px;
            margin: 20px 0;
            line-height: 1.6;
        }
        
        /* Glassmorphism Buttons */
        .buttons {
            display: flex;
            gap: 16px;
            margin-top: 30px;
        }
        
        .btn {
            flex: 1;
            padding: 14px 20px;
            border-radius: 60px;
            text-decoration: none;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            font-size: 14px;
            transition: all 0.3s ease;
            position: relative;
            overflow: hidden;
            text-align: center;
        }
        
        .btn-primary {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
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
        
        .btn-secondary {
            background: rgba(255,255,255,0.1);
            backdrop-filter: blur(10px);
            color: rgba(255,255,255,0.9);
            border: 1px solid rgba(255,255,255,0.2);
        }
        
        .btn-secondary:hover {
            background: rgba(255,255,255,0.2);
            transform: translateY(-2px);
        }
        
        .btn-primary:hover {
            transform: translateY(-2px);
            box-shadow: 0 8px 25px rgba(79,110,247,0.4);
        }
        
        /* Loading/Pending State */
        .pending-card {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 40px;
            padding: 50px;
            max-width: 500px;
            width: 100%;
            text-align: center;
        }
        
        .pending-icon {
            width: 100px;
            height: 100px;
            background: rgba(245,158,11,0.15);
            border-radius: 50%;
            display: flex;
            align-items: center;
            justify-content: center;
            margin: 0 auto 30px;
            border: 2px solid rgba(245,158,11,0.3);
        }
        
        .pending-icon svg {
            width: 50px;
            height: 50px;
            stroke: #fbbf24;
            stroke-width: 2;
            fill: none;
        }
        
        .pending-title {
            font-family: 'Outfit', sans-serif;
            font-size: 28px;
            font-weight: 700;
            color: white;
            margin-bottom: 15px;
        }
        
        .pending-text {
            color: rgba(255,255,255,0.6);
            margin: 20px 0;
        }
        
        /* Responsive Design */
        @media (max-width: 600px) {
            .success-card, .pending-card {
                padding: 35px 25px;
            }
            
            h1 {
                font-size: 28px;
            }
            
            .amount {
                font-size: 40px;
            }
            
            .amount::before {
                font-size: 26px;
            }
            
            .pin-number {
                font-size: 38px;
                letter-spacing: 8px;
            }
            
            .buttons {
                flex-direction: column;
                gap: 12px;
            }
            
            .btn {
                padding: 12px 16px;
            }
            
            .pin-box {
                padding: 25px;
            }
        }
        
        @media (max-width: 480px) {
            .success-card, .pending-card {
                padding: 28px 20px;
            }
            
            h1 {
                font-size: 24px;
            }
            
            .amount {
                font-size: 34px;
            }
            
            .pin-number {
                font-size: 32px;
                letter-spacing: 6px;
            }
            
            .success-icon {
                width: 80px;
                height: 80px;
            }
            
            .success-icon svg {
                width: 40px;
                height: 40px;
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
    <?php if (isset($success) && $success): ?>
        <!-- Glassmorphism Success Card -->
        <div class="success-card">
            <div class="success-icon">
                <svg viewBox="0 0 24 24">
                    <path d="M9 16.17L4.83 12l-1.42 1.41L9 19 21 7l-1.41-1.41L9 16.17z"/>
                </svg>
            </div>
            
            <h1>Payment Successful!</h1>
            <p class="subtitle">Your booking has been confirmed</p>
            
            <div class="amount"><?php echo number_format($amount, 2); ?></div>
            
            <div class="ref">
                <i class="fas fa-receipt"></i> Reference: <?php echo htmlspecialchars($booking_ref); ?>
            </div>
            
            <!-- Glassmorphism PIN Display -->
            <div class="pin-box">
                <div class="pin-label">
                    <i class="fas fa-key"></i> Your Access PIN
                </div>
                <div class="pin-number"><?php echo $access_pin; ?></div>
                <div class="pin-note">
                    <i class="fas fa-info-circle"></i> Show this 4-digit PIN to the parking owner to start your session
                </div>
            </div>
            
            <div class="info-text">
                <i class="fas fa-envelope"></i> A confirmation email with your PIN has been sent to your email address.
                <?php if (!empty($details['phone'])): ?>
                    <br><i class="fas fa-phone"></i> You'll also receive it via SMS.
                <?php endif; ?>
            </div>
            
            <div class="buttons">
                <a href="reservation-details.php?id=<?php echo $reservation['id']; ?>" class="btn btn-primary">
                    <i class="fas fa-calendar-check"></i> View Booking
                </a>
                <a href="dashboard.php" class="btn btn-secondary">
                    <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                </a>
            </div>
        </div>
    <?php else: ?>
        <!-- Glassmorphism Pending Card -->
        <div class="pending-card">
            <div class="pending-icon">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                    <circle cx="12" cy="12" r="10"/>
                    <polyline points="12 6 12 12 16 14"/>
                </svg>
            </div>
            
            <h1 class="pending-title">Payment Verification</h1>
            <p class="pending-text">
                Your payment is being processed. You'll receive a confirmation email shortly.
            </p>
            
            <div class="buttons">
                <a href="dashboard.php" class="btn btn-primary">
                    <i class="fas fa-tachometer-alt"></i> Go to Dashboard
                </a>
            </div>
        </div>
    <?php endif; ?>
</body>
</html>