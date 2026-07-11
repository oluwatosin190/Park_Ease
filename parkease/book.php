<?php
session_start();
require_once 'includes/user-access.php';
redirectOwnersFromPublicPages();
require_once 'config/database.php';

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    header('Location: login.php?redirect=book.php?id=' . $_GET['id']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

$parking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// Get parking space details
$query = "SELECT ps.*, u.id as owner_id, u.first_name as owner_name 
          FROM parking_spaces ps
          JOIN users u ON ps.owner_id = u.id
          WHERE ps.id = :id AND ps.is_active = 1";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $parking_id);
$stmt->execute();
$space = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$space) {
    header('Location: index.php');
    exit();
}

if ($space['available_spots'] <= 0) {
    $_SESSION['error'] = 'Sorry, this parking space is no longer available.';
    header('Location: parking-details.php?id=' . $parking_id);
    exit();
}

// Check if user is trying to book their own space
if ($space['owner_id'] == $_SESSION['user_id']) {
    $_SESSION['error'] = "You cannot book your own parking space.";
    header('Location: parking-details.php?id=' . $parking_id);
    exit();
}

// Get user details for pre-filling
$user_query = "SELECT * FROM users WHERE id = :id";
$user_stmt = $db->prepare($user_query);
$user_stmt->bindParam(':id', $_SESSION['user_id']);
$user_stmt->execute();
$user = $user_stmt->fetch(PDO::FETCH_ASSOC);

$error = '';
$success = '';

// Get existing bookings for this space (for validation)
$bookings_query = "SELECT start_date, end_date FROM reservations 
                   WHERE parking_id = :parking_id 
                   AND status IN ('confirmed', 'active')
                   AND end_date > NOW()";
$bookings_stmt = $db->prepare($bookings_query);
$bookings_stmt->bindParam(':parking_id', $parking_id);
$bookings_stmt->execute();
$existing_bookings = $bookings_stmt->fetchAll(PDO::FETCH_ASSOC);
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <title>Book Parking - <?php echo htmlspecialchars($space['name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
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
            max-width: 1200px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
        }
        
        /* Glassmorphism Back Link */
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 24px;
            color: rgba(255,255,255,0.9);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            padding: 10px 20px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border-radius: 50px;
            border: 1px solid rgba(255,255,255,0.15);
            transition: all 0.3s ease;
        }
        
        .back-link:hover {
            background: rgba(255,255,255,0.15);
            transform: translateY(-2px);
            border-color: rgba(255,255,255,0.3);
        }
        
        .booking-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 30px;
        }
        
        /* Glassmorphism Booking Form */
        .booking-form {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 28px;
            padding: 32px;
            transition: all 0.4s ease;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
        }
        
        .booking-form:hover {
            background: rgba(255,255,255,0.08);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        .form-header {
            margin-bottom: 28px;
        }
        
        .form-header h1 {
            font-family: 'Outfit', sans-serif;
            font-size: 28px;
            font-weight: 700;
            background: linear-gradient(135deg, #fff, #a5b4fc);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
            margin-bottom: 8px;
            letter-spacing: -0.5px;
        }
        
        .form-header p {
            color: rgba(255,255,255,0.6);
            font-size: 14px;
        }
        
        /* Glassmorphism Form Elements */
        .form-group {
            margin-bottom: 20px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 13px;
            color: rgba(255,255,255,0.7);
        }
        
        .required::after {
            content: ' *';
            color: #f87171;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 14px 18px;
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            transition: all 0.3s ease;
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            color: white;
        }
        
        textarea {
            border-radius: 20px;
            resize: vertical;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.1);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.2);
        }
        
        input::placeholder, textarea::placeholder {
            color: rgba(255,255,255,0.4);
        }
        
        select option {
            background: #1a1a2e;
            color: white;
        }
        
        /* Glassmorphism Vehicle Info */
        .vehicle-info {
            background: rgba(255,255,255,0.05);
            border-radius: 20px;
            padding: 20px;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .vehicle-info h4 {
            font-size: 15px;
            font-weight: 600;
            color: white;
            margin-bottom: 15px;
        }
        
        /* Glassmorphism Alerts */
        .alert {
            padding: 16px 20px;
            border-radius: 20px;
            margin-bottom: 24px;
            font-size: 14px;
            font-weight: 500;
            backdrop-filter: blur(20px);
            animation: slideDown 0.4s ease;
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
        
        /* Glassmorphism Price Summary */
        .price-summary {
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 28px;
            padding: 28px;
            position: sticky;
            top: 20px;
            transition: all 0.4s ease;
            box-shadow: 0 8px 32px 0 rgba(0, 0, 0, 0.2);
        }
        
        .price-summary:hover {
            background: rgba(255,255,255,0.08);
            box-shadow: 0 16px 48px 0 rgba(0, 0, 0, 0.3);
        }
        
        .space-info {
            display: flex;
            gap: 16px;
            margin-bottom: 24px;
            padding-bottom: 20px;
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .space-image {
            width: 80px;
            height: 80px;
            border-radius: 16px;
            overflow: hidden;
            background: linear-gradient(135deg, #1a1a2e, #16213e);
            box-shadow: 0 4px 12px rgba(0,0,0,0.2);
        }
        
        .space-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .space-details h3 {
            font-family: 'Outfit', sans-serif;
            font-size: 18px;
            font-weight: 600;
            color: white;
            margin-bottom: 5px;
            letter-spacing: -0.3px;
        }
        
        .space-details p {
            font-size: 13px;
            color: rgba(255,255,255,0.6);
        }
        
        .rate-display {
            background: rgba(255,255,255,0.05);
            border-radius: 20px;
            padding: 18px;
            margin-bottom: 20px;
            border: 1px solid rgba(255,255,255,0.1);
        }
        
        .rate-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
        }
        
        .rate-item:last-child {
            margin-bottom: 0;
        }
        
        .rate-label {
            color: rgba(255,255,255,0.6);
        }
        
        .rate-value {
            font-weight: 600;
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        .booking-summary {
            margin: 20px 0;
            padding: 20px 0;
            border-top: 1px solid rgba(255,255,255,0.1);
            border-bottom: 1px solid rgba(255,255,255,0.1);
        }
        
        .booking-summary h4 {
            font-size: 16px;
            font-weight: 600;
            color: white;
            margin-bottom: 15px;
        }
        
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 12px;
            font-size: 14px;
            color: rgba(255,255,255,0.7);
        }
        
        .summary-row.total {
            font-size: 18px;
            font-weight: 700;
            color: white;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 1px solid rgba(255,255,255,0.1);
        }
        
        .total-amount {
            background: linear-gradient(135deg, #a5b4fc, #c4b5fd);
            -webkit-background-clip: text;
            -webkit-text-fill-color: transparent;
            background-clip: text;
        }
        
        /* Glassmorphism Book Button */
        .btn-book {
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
            margin: 20px 0;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(79,110,247,0.3);
        }
        
        .btn-book::before {
            content: '';
            position: absolute;
            top: 0;
            left: -100%;
            width: 100%;
            height: 100%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.2), transparent);
            transition: left 0.5s ease;
        }
        
        .btn-book:hover::before {
            left: 100%;
        }
        
        .btn-book:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 12px 30px rgba(79,110,247,0.4);
        }
        
        .btn-book:disabled {
            background: rgba(156,163,175,0.5);
            cursor: not-allowed;
            opacity: 0.7;
            box-shadow: none;
        }
        
        .secure-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 10px;
            color: rgba(255,255,255,0.5);
            font-size: 13px;
            margin-top: 15px;
        }
        
        .secure-badge i {
            color: #4ade80;
        }
        
        /* Flatpickr Custom Styling */
        .flatpickr-calendar {
            background: rgba(255,255,255,0.15) !important;
            backdrop-filter: blur(32px) saturate(180%) !important;
            -webkit-backdrop-filter: blur(32px) saturate(180%) !important;
            border: 1px solid rgba(255,255,255,0.28) !important;
            border-radius: 24px !important;
            box-shadow: 0 20px 60px rgba(0,0,0,0.18), inset 0 1px 0 rgba(255,255,255,0.4) !important;
            color: #0F172A !important;
        }

        .flatpickr-calendar .flatpickr-month,
        .flatpickr-calendar .flatpickr-current-month {
            color: #0F172A !important;
        }

        .flatpickr-calendar .flatpickr-prev-month,
        .flatpickr-calendar .flatpickr-next-month {
            color: #4F6EF7 !important;
        }

        .flatpickr-calendar .flatpickr-prev-month:hover,
        .flatpickr-calendar .flatpickr-next-month:hover {
            color: #7C3AED !important;
        }

        .flatpickr-weekdays {
            background: transparent !important;
        }

        .flatpickr-weekday {
            color: #0F172A !important;
            font-weight: 600 !important;
        }

        .flatpickr-day {
            color: #0F172A !important;
            background: transparent !important;
            border: 1px solid transparent !important;
        }

        .flatpickr-day:hover {
            background: rgba(79,110,247,0.15) !important;
            border: 1px solid rgba(79,110,247,0.3) !important;
        }

        .flatpickr-day.selected, 
        .flatpickr-day.startRange, 
        .flatpickr-day.endRange {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED) !important;
            border-color: #4F6EF7 !important;
            color: white !important;
            font-weight: 600 !important;
            box-shadow: 0 4px 12px rgba(79,110,247,0.3) !important;
        }

        .flatpickr-day.inRange {
            background: rgba(79,110,247,0.1) !important;
            color: #0F172A !important;
        }

        .flatpickr-day.today {
            border: 1px solid rgba(79,110,247,0.5) !important;
            color: #4F6EF7 !important;
            font-weight: 600 !important;
        }

        .flatpickr-day.disabled {
            color: rgba(15,23,42,0.35) !important;
        }

        .flatpickr-time {
            background: rgba(255,255,255,0.08) !important;
            border-top: 1px solid rgba(255,255,255,0.2) !important;
        }

        .flatpickr-time input {
            color: #0F172A !important;
            background: rgba(255,255,255,0.4) !important;
            border: 1px solid rgba(255,255,255,0.2) !important;
            border-radius: 8px !important;
        }

        .flatpickr-time input:hover, 
        .flatpickr-time input:focus {
            background: rgba(255,255,255,0.6) !important;
            border-color: #4F6EF7 !important;
        }

        .flatpickr-time .flatpickr-am-pm {
            color: #0F172A !important;
            background: rgba(79,110,247,0.08) !important;
            border-radius: 8px !important;
        }

        .flatpickr-time .flatpickr-am-pm:hover {
            background: rgba(79,110,247,0.15) !important;
        }
        
        /* Responsive Design */
        @media (max-width: 1024px) {
            .booking-grid {
                gap: 24px;
            }
        }
        
        @media (max-width: 768px) {
            body {
                padding: 20px 15px;
            }
            
            .booking-grid {
                grid-template-columns: 1fr;
                gap: 20px;
            }
            
            .form-row {
                grid-template-columns: 1fr;
                gap: 0;
            }
            
            .booking-form, .price-summary {
                padding: 24px;
            }
            
            .form-header h1 {
                font-size: 24px;
            }
        }
        
        @media (max-width: 480px) {
            .booking-form, .price-summary {
                padding: 20px;
            }
            
            .space-info {
                flex-direction: column;
                text-align: center;
            }
            
            .space-image {
                margin: 0 auto;
            }
            
            .rate-item {
                flex-direction: column;
                align-items: center;
                gap: 5px;
                text-align: center;
            }
            
            .summary-row {
                flex-direction: column;
                align-items: center;
                gap: 5px;
                text-align: center;
            }
        }
        
        /* Animation for content */
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
        
        .booking-form, .price-summary {
            animation: fadeInUp 0.5s ease forwards;
            opacity: 0;
        }
        
        .booking-form { animation-delay: 0.05s; }
        .price-summary { animation-delay: 0.1s; }
        
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
        <a href="parking-details.php?id=<?php echo $parking_id; ?>" class="back-link">
            <i class="fas fa-arrow-left"></i> Back to parking details
        </a>
        
        <div class="booking-grid">
            <!-- Glassmorphism Booking Form -->
            <div class="booking-form">
                <div class="form-header">
                    <h1>Complete Your Booking</h1>
                    <p>Please fill in your details to reserve this parking space</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
                <?php endif; ?>
                
                <form id="bookingForm" method="POST" action="process-booking.php">
                    <input type="hidden" name="parking_id" value="<?php echo $parking_id; ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required"><i class="far fa-calendar-alt"></i> Start Date & Time</label>
                            <input type="text" id="start_datetime" name="start_datetime" class="datetime-picker" required placeholder="Select start date & time">
                        </div>
                        <div class="form-group">
                            <label class="required"><i class="far fa-calendar-check"></i> End Date & Time</label>
                            <input type="text" id="end_datetime" name="end_datetime" class="datetime-picker" required placeholder="Select end date & time">
                        </div>
                    </div>
                    
                    <div class="vehicle-info">
                        <h4><i class="fas fa-car"></i> Vehicle Information</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label><i class="fas fa-tag"></i> Vehicle Number</label>
                                <input type="text" name="vehicle_number" placeholder="e.g., ABC-1234" value="<?php echo isset($_POST['vehicle_number']) ? htmlspecialchars($_POST['vehicle_number']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label><i class="fas fa-car-side"></i> Vehicle Model</label>
                                <input type="text" name="vehicle_model" placeholder="e.g., Toyota Camry" value="<?php echo isset($_POST['vehicle_model']) ? htmlspecialchars($_POST['vehicle_model']) : ''; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-comment"></i> Special Requests (Optional)</label>
                        <textarea name="special_requests" rows="3" placeholder="Any special requirements?"><?php echo isset($_POST['special_requests']) ? htmlspecialchars($_POST['special_requests']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label class="required"><i class="fas fa-credit-card"></i> Payment Method</label>
                        <select name="payment_method" required>
                            <option value="">Select payment method</option>
                            <option value="card">Credit/Debit Card</option>
                            <option value="transfer">Bank Transfer</option>
                            <option value="wallet">Wallet</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-book" id="submitBtn" disabled>
                        <i class="fas fa-calendar-check"></i> Select dates to see price
                    </button>
                    
                    <div class="secure-badge">
                        <i class="fas fa-lock"></i>
                        Your information is secure and encrypted
                    </div>
                </form>
            </div>
            
            <!-- Glassmorphism Price Summary -->
            <div class="price-summary">
                <div class="space-info">
                    <div class="space-image">
                        <?php 
                        $space_images = !empty($space['images']) ? json_decode($space['images'], true) : [];
                        $image_url = !empty($space_images) ? 'uploads/parking/' . $space_images[0] : 'img/parking-placeholder.jpg';
                        ?>
                        <img src="<?php echo $image_url; ?>" alt="<?php echo htmlspecialchars($space['name']); ?>" onerror="this.src='img/parking-placeholder.jpg'">
                    </div>
                    <div class="space-details">
                        <h3><?php echo htmlspecialchars($space['name']); ?></h3>
                        <p><i class="fas fa-map-marker-alt"></i> <?php echo htmlspecialchars($space['city']); ?></p>
                        <p style="margin-top: 5px;"><i class="fas fa-star" style="color: #FBBF24;"></i> ★ <?php echo number_format($space['avg_rating'] ?? 0, 1); ?></p>
                    </div>
                </div>
                
                <div class="rate-display">
                    <div class="rate-item">
                        <span class="rate-label"><i class="far fa-clock"></i> Hourly Rate</span>
                        <span class="rate-value">₦<?php echo number_format($space['hourly_rate'] ?? 0, 0); ?></span>
                    </div>
                    <?php if ($space['daily_rate']): ?>
                    <div class="rate-item">
                        <span class="rate-label"><i class="far fa-calendar-day"></i> Daily Rate</span>
                        <span class="rate-value">₦<?php echo number_format($space['daily_rate'], 0); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($space['monthly_rate']): ?>
                    <div class="rate-item">
                        <span class="rate-label"><i class="far fa-calendar-alt"></i> Monthly Rate</span>
                        <span class="rate-value">₦<?php echo number_format($space['monthly_rate'], 0); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="booking-summary" id="bookingSummary" style="display: none;">
                    <h4><i class="fas fa-receipt"></i> Booking Summary</h4>
                    <div class="summary-row">
                        <span><i class="far fa-hourglass"></i> Duration</span>
                        <span id="durationDisplay">-</span>
                    </div>
                    <div class="summary-row">
                        <span><i class="fas fa-tag"></i> Rate Applied</span>
                        <span id="rateApplied">-</span>
                    </div>
                    <div class="summary-row total">
                        <span><i class="fas fa-money-bill-wave"></i> Total Amount</span>
                        <span class="total-amount" id="totalAmount">₦0</span>
                    </div>
                </div>
                
                <div style="text-align: center; color: rgba(255,255,255,0.5); font-size: 13px; margin-top: 15px;">
                    <i class="fas fa-ban"></i> Free cancellation up to 1 hour before start time
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Get rates from PHP
        const hourlyRate = <?php echo $space['hourly_rate'] ?? 0; ?>;
        const dailyRate = <?php echo $space['daily_rate'] ?? 0; ?>;
        const monthlyRate = <?php echo $space['monthly_rate'] ?? 0; ?>;
        
        // Initialize datetime pickers with today's date as minimum
        const today = new Date();
        today.setHours(0, 0, 0, 0);
        
        const startPicker = flatpickr("#start_datetime", {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            minDate: today,
            defaultDate: today,
            time_24hr: true,
            minuteIncrement: 15,
            onChange: function(selectedDates, dateStr, instance) {
                endPicker.set('minDate', dateStr);
                calculatePrice();
            }
        });
        
        const endPicker = flatpickr("#end_datetime", {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            minDate: today,
            time_24hr: true,
            minuteIncrement: 15,
            onChange: function() {
                calculatePrice();
            }
        });
        
        // Format duration in a user-friendly way
        function formatDuration(totalMinutes) {
            if (totalMinutes < 60) {
                return totalMinutes + ' ' + (totalMinutes === 1 ? 'minute' : 'minutes');
            }
            
            const hours = Math.floor(totalMinutes / 60);
            const minutes = totalMinutes % 60;
            
            let durationText = '';
            
            if (hours > 0) {
                durationText += hours + ' ' + (hours === 1 ? 'hour' : 'hours');
            }
            
            if (minutes > 0) {
                durationText += (hours > 0 ? ' ' : '') + minutes + ' ' + (minutes === 1 ? 'minute' : 'minutes');
            }
            
            return durationText;
        }
        
        // Calculate price function 
        function calculatePrice() {
            const startDate = document.getElementById('start_datetime').value;
            const endDate = document.getElementById('end_datetime').value;
            const submitBtn = document.getElementById('submitBtn');
            const summaryDiv = document.getElementById('bookingSummary');
            
            if (startDate && endDate) {
                const start = new Date(startDate);
                const end = new Date(endDate);
                
                if (end > start) {
                    // Calculate total minutes
                    const diffMs = end - start;
                    const totalMinutes = Math.round(diffMs / (1000 * 60));
                    
                    // Calculate hours (with decimal for rate calculation)
                    const hours = totalMinutes / 60;
                    const days = hours / 24;
                    
                    // Calculate different rate options
                    const hourlyTotal = hours * hourlyRate;
                    
                    // Initialize with very high numbers
                    let dailyTotal = Number.MAX_SAFE_INTEGER;
                    let monthlyTotal = Number.MAX_SAFE_INTEGER;
                    let finalTotal = hourlyTotal;
                    let rateType = 'hourly';
                    let appliedRate = hourlyRate;
                    
                    // Check daily rate
                    if (dailyRate > 0 && hours >= 24) {
                        const totalDays = Math.ceil(hours / 24);
                        dailyTotal = totalDays * dailyRate;
                    }
                    
                    // Check monthly rate (30 days)
                    if (monthlyRate > 0 && hours >= 720) { // 30 days
                        const totalMonths = Math.ceil(hours / 720);
                        monthlyTotal = totalMonths * monthlyRate;
                    }
                    
                    // Choose the cheapest option
                    if (monthlyTotal <= dailyTotal && monthlyTotal <= hourlyTotal) {
                        finalTotal = monthlyTotal;
                        rateType = 'monthly';
                        appliedRate = monthlyRate;
                    } else if (dailyTotal <= hourlyTotal) {
                        finalTotal = dailyTotal;
                        rateType = 'daily';
                        appliedRate = dailyRate;
                    } else {
                        finalTotal = hourlyTotal;
                        rateType = 'hourly';
                        appliedRate = hourlyRate;
                    }
                    
                    // Format duration display
                    let durationText;
                    if (days >= 1) {
                        if (days >= 30) {
                            const months = Math.floor(days / 30);
                            const remainingDays = Math.round(days % 30);
                            if (remainingDays > 0) {
                                durationText = months + ' month' + (months > 1 ? 's' : '') + ' ' + remainingDays + ' day' + (remainingDays > 1 ? 's' : '');
                            } else {
                                durationText = months + ' month' + (months > 1 ? 's' : '');
                            }
                        } else {
                            durationText = Math.round(days) + ' day' + (Math.round(days) > 1 ? 's' : '');
                            const remainingMinutes = totalMinutes % (24 * 60);
                            if (remainingMinutes > 0) {
                                durationText += ' ' + formatDuration(remainingMinutes);
                            }
                        }
                    } else {
                        durationText = formatDuration(totalMinutes);
                    }
                    
                    // Update summary display
                    document.getElementById('durationDisplay').textContent = durationText;
                    
                    let rateText = rateType.charAt(0).toUpperCase() + rateType.slice(1);
                    if (rateType === 'hourly') rateText = 'Hourly';
                    else if (rateType === 'daily') rateText = 'Daily';
                    else rateText = 'Monthly';
                    
                    document.getElementById('rateApplied').textContent = 
                        rateText + ' (₦' + appliedRate.toFixed(0) + ')';
                    
                    document.getElementById('totalAmount').textContent = '₦' + finalTotal.toFixed(2);
                    
                    summaryDiv.style.display = 'block';
                    submitBtn.disabled = false;
                    submitBtn.innerHTML = '<i class="fas fa-check-circle"></i> Confirm Booking - ₦' + finalTotal.toFixed(2);
                } else {
                    summaryDiv.style.display = 'none';
                    submitBtn.disabled = true;
                    submitBtn.innerHTML = '<i class="fas fa-exclamation-triangle"></i> End time must be after start time';
                }
            } else {
                summaryDiv.style.display = 'none';
                submitBtn.disabled = true;
                submitBtn.innerHTML = '<i class="fas fa-calendar-alt"></i> Select dates to see price';
            }
        }

        // Check if space is still available every 30 seconds
        function checkAvailability() {
            fetch(`api/get-availability.php?id=<?php echo $parking_id; ?>`)
                .then(response => response.json())
                .then(data => {
                    if (!data.is_available) {
                        alert('Sorry, this parking space just became fully booked. You will be redirected.');
                        window.location.href = 'parking-details.php?id=<?php echo $parking_id; ?>';
                    }
                });
        }

        // Check every 30 seconds
        setInterval(checkAvailability, 30000);
    </script>
</body>
</html>