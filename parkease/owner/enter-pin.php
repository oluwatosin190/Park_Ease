<?php
session_start();
require_once '../includes/user-access.php';
require_once '../config/database.php';
require_once '../includes/pin-functions.php';

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();
$pinManager = new PinManager($db);

$owner_id = $_SESSION['user_id'];
$message = '';
$error = '';

// Handle PIN submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $reservation_id = $_POST['reservation_id'] ?? 0;
    $entered_pin = $_POST['pin'] ?? '';
    
    if (!$reservation_id || !$entered_pin) {
        $error = 'Please select a booking and enter a PIN';
    } else {
        // Verify that this booking belongs to one of the owner's spaces
        $check = $db->prepare("SELECT r.id FROM reservations r
                                JOIN parking_spaces ps ON r.parking_id = ps.id
                                WHERE r.id = :rid AND ps.owner_id = :oid");
        $check->execute([':rid' => $reservation_id, ':oid' => $owner_id]);
        
        if (!$check->fetch()) {
            $error = 'You do not have permission to access this booking';
        } else {
            $result = $pinManager->validateAndStartTimer($reservation_id, $entered_pin);
            
            if ($result['success']) {
                $message = '✅ Timer started successfully! Session active.';
                // Auto-refresh after 2 seconds
                echo "<script>setTimeout(() => window.location.href = 'active-sessions.php', 2000);</script>";
            } else {
                $error = $result['message'];
            }
        }
    }
}

// Get pending bookings for this owner (where timer hasn't started)
$query = "SELECT r.*, u.first_name, u.last_name, u.email, u.phone,
          ps.name as parking_name, ps.address
          FROM reservations r
          JOIN parking_spaces ps ON r.parking_id = ps.id
          JOIN users u ON r.user_id = u.id
          WHERE ps.owner_id = :owner_id
          AND r.payment_status = 'paid'
          AND r.timer_status = 'pending'
          ORDER BY r.start_date ASC";

$stmt = $db->prepare($query);
$stmt->execute([':owner_id' => $owner_id]);
$pending_bookings = $stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Enter PIN - ParkEase Owner</title>
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
            max-width: 600px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }
        
        /* Glassmorphism Header */
        .header {
            display: flex;
            justify-content: space-between;
            align-items: center;
            margin-bottom: 32px;
            flex-wrap: wrap;
            gap: 15px;
        }
        
        .header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: clamp(24px, 5vw, 32px);
            font-weight: 700;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            letter-spacing: -0.5px;
        }
        
        .btn-glass {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            padding: 10px 20px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 50px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s ease;
        }
        
        .btn-glass:hover {
            background: rgba(255,255,255,0.15);
            transform: translateY(-2px);
            border-color: rgba(255,255,255,0.3);
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
        
        /* Glassmorphism PIN Form */
        .pin-form {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 28px;
            padding: 32px;
            margin-bottom: 28px;
            transition: all 0.4s ease;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
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
        
        .pin-form:hover {
            background: rgba(255,255,255,0.08);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        .pin-form h2 {
            font-family: 'Outfit', sans-serif;
            font-size: 24px;
            font-weight: 600;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
        }
        
        .pin-form p {
            color: rgba(255,255,255,0.6);
            margin-bottom: 24px;
            font-size: 14px;
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
        
        .form-group select {
            width: 100%;
            padding: 14px 18px;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            color: white;
            cursor: pointer;
            transition: all 0.3s ease;
        }
        
        .form-group select:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.1);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.2);
        }
        
        .form-group select option {
            background: #1a1a2e;
            color: white;
        }
        
        .form-group select:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Glassmorphism PIN Input */
        .pin-input {
            width: 100%;
            padding: 16px 20px;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-size: 36px;
            font-family: 'Outfit', sans-serif;
            letter-spacing: 12px;
            text-align: center;
            font-weight: 700;
            color: white;
            transition: all 0.3s ease;
        }
        
        .pin-input:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.1);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.2);
        }
        
        .pin-input::placeholder {
            letter-spacing: normal;
            font-size: 16px;
            color: rgba(255,255,255,0.3);
        }
        
        .pin-input:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        
        /* Glassmorphism Submit Button */
        .btn-submit {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            border: none;
            border-radius: 60px;
            font-size: 16px;
            font-weight: 600;
            font-family: 'Outfit', sans-serif;
            cursor: pointer;
            transition: all 0.3s ease;
            margin-top: 8px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(79,110,247,0.3);
        }
        
        .btn-submit::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-submit:hover::before {
            left: 100%;
        }
        
        .btn-submit:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 8px 28px rgba(79,110,247,0.4);
        }
        
        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
            transform: none;
        }
        
        .warning-text {
            color: #fbbf24;
            margin-top: 12px;
            font-size: 13px;
            display: flex;
            align-items: center;
            gap: 8px;
        }
        
        /* Glassmorphism Info Box */
        .info-box {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 24px;
            padding: 24px;
            transition: all 0.3s ease;
        }
        
        .info-box h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 18px;
            font-weight: 600;
            color: white;
            margin-bottom: 20px;
            display: flex;
            align-items: center;
            gap: 10px;
        }
        
        .booking-item {
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.1);
            border-radius: 16px;
            padding: 16px;
            margin-bottom: 12px;
            transition: all 0.3s ease;
        }
        
        .booking-item:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(165,180,252,0.3);
            transform: translateX(5px);
        }
        
        .booking-item strong {
            font-size: 16px;
            font-weight: 600;
            color: white;
        }
        
        .booking-item small {
            display: flex;
            align-items: center;
            gap: 8px;
            color: rgba(255,255,255,0.6);
            font-size: 13px;
            margin-top: 6px;
        }
        
        .no-bookings {
            text-align: center;
            padding: 40px 20px;
            color: rgba(255,255,255,0.6);
        }
        
        .no-bookings i {
            font-size: 48px;
            margin-bottom: 16px;
            opacity: 0.4;
        }
        
        /* Responsive Design */
        @media (max-width: 600px) {
            body {
                padding: 20px 15px;
            }
            
            .pin-form {
                padding: 24px;
            }
            
            .pin-form h2 {
                font-size: 22px;
            }
            
            .pin-input {
                font-size: 28px;
                letter-spacing: 8px;
                padding: 14px 16px;
            }
            
            .info-box {
                padding: 20px;
            }
            
            .booking-item strong {
                font-size: 14px;
            }
        }
        
        @media (max-width: 480px) {
            .pin-input {
                font-size: 24px;
                letter-spacing: 6px;
            }
            
            .header {
                flex-direction: column;
                align-items: flex-start;
            }
            
            .btn-glass {
                width: 100%;
                justify-content: center;
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
            <h1><i class="fas fa-key"></i> Enter Customer PIN</h1>
            <a href="../dashboard.php" class="btn-glass"><i class="fas fa-arrow-left"></i> Dashboard</a>
        </div>
        
        <?php if ($message): ?>
            <div class="alert alert-success">
                <i class="fas fa-check-circle"></i> <?php echo $message; ?>
            </div>
        <?php endif; ?>
        
        <?php if ($error): ?>
            <div class="alert alert-error">
                <i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?>
            </div>
        <?php endif; ?>
        
        <!-- Glassmorphism PIN Form -->
        <div class="pin-form">
            <h2><i class="fas fa-hourglass-start"></i> Start Parking Session</h2>
            <p><i class="fas fa-info-circle"></i> Ask the customer for their 4-digit PIN and enter it below.</p>
            
            <form method="POST" action="" id="pinForm">
                <div class="form-group">
                    <label><i class="fas fa-user-clock"></i> Select Customer Booking</label>
                    <?php if (empty($pending_bookings)): ?>
                        <select disabled>
                            <option>No pending bookings available</option>
                        </select>
                        <div class="warning-text">
                            <i class="fas fa-clock"></i> No customers are waiting to start their session.
                        </div>
                    <?php else: ?>
                        <select name="reservation_id" required>
                            <option value="">Choose a customer...</option>
                            <?php foreach ($pending_bookings as $booking): ?>
                                <option value="<?php echo $booking['id']; ?>">
                                    <?php echo $booking['first_name'] . ' ' . $booking['last_name']; ?> - 
                                    <?php echo $booking['parking_name']; ?> - 
                                    <?php echo date('M d, h:i A', strtotime($booking['start_date'])); ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    <?php endif; ?>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-qrcode"></i> Enter 4-Digit PIN</label>
                    <input type="text" name="pin" class="pin-input" maxlength="4" pattern="[0-9]{4}" required placeholder="••••" <?php echo empty($pending_bookings) ? 'disabled' : ''; ?>>
                </div>
                
                <button type="submit" class="btn-submit" <?php echo empty($pending_bookings) ? 'disabled' : ''; ?>>
                    <i class="fas fa-play-circle"></i> Start Timer
                </button>
            </form>
        </div>
        
        <?php if (!empty($pending_bookings)): ?>
        <!-- Glassmorphism Info Box -->
        <div class="info-box">
            <h3><i class="fas fa-users"></i> Customers Waiting to Start</h3>
            <?php foreach ($pending_bookings as $booking): ?>
                <div class="booking-item">
                    <strong><i class="fas fa-user"></i> <?php echo $booking['first_name'] . ' ' . $booking['last_name']; ?></strong><br>
                    <small><i class="fas fa-parking"></i> <?php echo $booking['parking_name']; ?></small><br>
                    <small><i class="fas fa-calendar-alt"></i> <?php echo date('M d, h:i A', strtotime($booking['start_date'])); ?></small><br>
                    <small><i class="fas fa-hourglass-half"></i> Grace period ends: <?php echo date('h:i A', strtotime($booking['start_date'] . ' +30 minutes')); ?></small>
                </div>
            <?php endforeach; ?>
        </div>
        <?php endif; ?>
    </div>
    
    <script>
        // Auto-format PIN input
        const pinInput = document.querySelector('input[name="pin"]');
        if (pinInput) {
            pinInput.addEventListener('input', function(e) {
                this.value = this.value.replace(/[^0-9]/g, '').substring(0, 4);
            });
            
            // Add visual feedback for PIN entry
            pinInput.addEventListener('keyup', function(e) {
                if (this.value.length === 4) {
                    this.style.borderColor = 'rgba(34,197,94,0.5)';
                    this.style.boxShadow = '0 0 0 3px rgba(34,197,94,0.1)';
                } else {
                    this.style.borderColor = 'rgba(255,255,255,0.2)';
                    this.style.boxShadow = 'none';
                }
            });
        }
        
        // Prevent form double-submission
        const pinForm = document.getElementById('pinForm');
        if (pinForm) {
            pinForm.addEventListener('submit', function() {
                const btn = this.querySelector('button[type="submit"]');
                if (btn && !btn.disabled) {
                    btn.disabled = true;
                    btn.innerHTML = '<i class="fas fa-spinner fa-pulse"></i> Processing...';
                }
            });
        }
        
        // Auto-focus PIN input when a booking is selected
        const selectInput = document.querySelector('select[name="reservation_id"]');
        if (selectInput) {
            selectInput.addEventListener('change', function() {
                const pinField = document.querySelector('input[name="pin"]');
                if (pinField && this.value) {
                    pinField.focus();
                }
            });
        }
    </script>
</body>
</html>