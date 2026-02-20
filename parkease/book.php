<?php
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Book Parking - <?php echo htmlspecialchars($space['name']); ?></title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #F9FAFB;
            padding: 40px 20px;
        }
        .container {
            max-width: 1200px;
            margin: 0 auto;
        }
        .booking-grid {
            display: grid;
            grid-template-columns: 1.5fr 1fr;
            gap: 30px;
        }
        
        /* Booking Form */
        .booking-form {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .form-header {
            margin-bottom: 30px;
        }
        .form-header h1 {
            font-size: 24px;
            color: #111827;
            margin-bottom: 5px;
        }
        .form-header p {
            color: #6B7280;
            font-size: 14px;
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: #4F6EF7;
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 15px;
        }
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 14px;
            color: #374151;
        }
        .required::after {
            content: ' *';
            color: #DC2626;
        }
        input, select, textarea {
            width: 100%;
            padding: 12px 16px;
            border: 1px solid #E5E7EB;
            border-radius: 10px;
            font-size: 14px;
            font-family: 'Inter', sans-serif;
            transition: all 0.3s;
        }
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: #4F6EF7;
            box-shadow: 0 0 0 3px rgba(79,110,247,0.1);
        }
        .datetime-input {
            position: relative;
        }
        .datetime-input svg {
            position: absolute;
            right: 12px;
            top: 50%;
            transform: translateY(-50%);
            width: 20px;
            height: 20px;
            color: #9CA3AF;
            pointer-events: none;
        }
        
        /* Price Summary */
        .price-summary {
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            position: sticky;
            top: 20px;
        }
        .space-info {
            display: flex;
            gap: 15px;
            margin-bottom: 20px;
            padding-bottom: 20px;
            border-bottom: 1px solid #E5E7EB;
        }
        .space-image {
            width: 80px;
            height: 80px;
            border-radius: 10px;
            overflow: hidden;
            background: #F3F4F6;
        }
        .space-image img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .space-details h3 {
            font-size: 18px;
            margin-bottom: 5px;
            color: #111827;
        }
        .space-details p {
            font-size: 13px;
            color: #6B7280;
        }
        .rate-display {
            background: #F3F4F6;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .rate-item {
            display: flex;
            justify-content: space-between;
            margin-bottom: 10px;
            font-size: 14px;
        }
        .rate-item:last-child {
            margin-bottom: 0;
        }
        .rate-label {
            color: #6B7280;
        }
        .rate-value {
            font-weight: 600;
            color: #4F6EF7;
        }
        .rate-value::before {
            content: '₦';
            margin-right: 2px;
        }
        .booking-summary {
            margin: 20px 0;
            padding: 20px 0;
            border-top: 1px solid #E5E7EB;
            border-bottom: 1px solid #E5E7EB;
        }
        .summary-row {
            display: flex;
            justify-content: space-between;
            margin-bottom: 15px;
            font-size: 14px;
        }
        .summary-row.total {
            font-size: 18px;
            font-weight: 700;
            color: #111827;
            margin-top: 15px;
            padding-top: 15px;
            border-top: 2px solid #E5E7EB;
        }
        .total-amount {
            color: #4F6EF7;
        }
        .btn-book {
            width: 100%;
            padding: 16px;
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            border: none;
            border-radius: 10px;
            font-size: 16px;
            font-weight: 600;
            cursor: pointer;
            transition: transform 0.3s;
            margin: 20px 0;
        }
        .btn-book:hover:not(:disabled) {
            transform: translateY(-2px);
        }
        .btn-book:disabled {
            opacity: 0.5;
            cursor: not-allowed;
        }
        .secure-badge {
            display: flex;
            align-items: center;
            justify-content: center;
            gap: 8px;
            color: #6B7280;
            font-size: 13px;
            margin-top: 15px;
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
        
        /* Vehicle Info */
        .vehicle-info {
            background: #F9FAFB;
            border-radius: 10px;
            padding: 15px;
            margin-bottom: 20px;
        }
        .vehicle-info h4 {
            font-size: 14px;
            margin-bottom: 10px;
            color: #374151;
        }
        
        /* Responsive */
        @media (max-width: 768px) {
            .booking-grid {
                grid-template-columns: 1fr;
            }
            .form-row {
                grid-template-columns: 1fr;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="parking-details.php?id=<?php echo $parking_id; ?>" class="back-link">← Back to parking details</a>
        
        <div class="booking-grid">
            <!-- Booking Form -->
            <div class="booking-form">
                <div class="form-header">
                    <h1>Complete Your Booking</h1>
                    <p>Please fill in your details to reserve this parking space</p>
                </div>
                
                <?php if ($error): ?>
                    <div class="alert alert-error"><?php echo $error; ?></div>
                <?php endif; ?>
                
                <?php if ($success): ?>
                    <div class="alert alert-success"><?php echo $success; ?></div>
                <?php endif; ?>
                
                <form id="bookingForm" method="POST" action="process-booking.php">
                    <input type="hidden" name="parking_id" value="<?php echo $parking_id; ?>">
                    
                    <div class="form-row">
                        <div class="form-group">
                            <label class="required">Start Date & Time</label>
                            <input type="text" id="start_datetime" name="start_datetime" class="datetime-picker" required placeholder="Select start date & time">
                        </div>
                        <div class="form-group">
                            <label class="required">End Date & Time</label>
                            <input type="text" id="end_datetime" name="end_datetime" class="datetime-picker" required placeholder="Select end date & time">
                        </div>
                    </div>
                    
                    <div class="vehicle-info">
                        <h4>Vehicle Information</h4>
                        <div class="form-row">
                            <div class="form-group">
                                <label>Vehicle Number</label>
                                <input type="text" name="vehicle_number" placeholder="e.g., ABC-1234" value="<?php echo isset($_POST['vehicle_number']) ? htmlspecialchars($_POST['vehicle_number']) : ''; ?>">
                            </div>
                            <div class="form-group">
                                <label>Vehicle Model</label>
                                <input type="text" name="vehicle_model" placeholder="e.g., Toyota Camry" value="<?php echo isset($_POST['vehicle_model']) ? htmlspecialchars($_POST['vehicle_model']) : ''; ?>">
                            </div>
                        </div>
                    </div>
                    
                    <div class="form-group">
                        <label>Special Requests (Optional)</label>
                        <textarea name="special_requests" rows="3" placeholder="Any special requirements?"><?php echo isset($_POST['special_requests']) ? htmlspecialchars($_POST['special_requests']) : ''; ?></textarea>
                    </div>
                    
                    <div class="form-group">
                        <label>Payment Method</label>
                        <select name="payment_method" required>
                            <option value="">Select payment method</option>
                            <option value="card">Credit/Debit Card</option>
                            <option value="transfer">Bank Transfer</option>
                            <option value="cash">Pay at Location</option>
                        </select>
                    </div>
                    
                    <button type="submit" class="btn-book" id="submitBtn" disabled>Select dates to see price</button>
                    
                    <div class="secure-badge">
                        <svg width="16" height="16" viewBox="0 0 24 24" fill="none" stroke="#10B981" stroke-width="2">
                            <rect x="3" y="11" width="18" height="11" rx="2" ry="2"/>
                            <path d="M7 11V7a5 5 0 0 1 10 0v4"/>
                        </svg>
                        Your information is secure and encrypted
                    </div>
                </form>
            </div>
            
            <!-- Price Summary -->
            <div class="price-summary">
                <div class="space-info">
                    <div class="space-image">
                        <?php 
                        $space_images = !empty($space['images']) ? json_decode($space['images'], true) : [];
                        $image_url = !empty($space_images) ? 'uploads/parking/' . $space_images[0] : 'img/parking-placeholder.jpg';
                        ?>
                        <img src="<?php echo $image_url; ?>" alt="">
                    </div>
                    <div class="space-details">
                        <h3><?php echo htmlspecialchars($space['name']); ?></h3>
                        <p><?php echo htmlspecialchars($space['city']); ?></p>
                        <p style="color: #4F6EF7; font-weight: 600; margin-top: 5px;">★ <?php echo number_format($space['avg_rating'] ?? 0, 1); ?></p>
                    </div>
                </div>
                
                <div class="rate-display">
                    <div class="rate-item">
                        <span class="rate-label">Hourly Rate</span>
                        <span class="rate-value"><?php echo number_format($space['hourly_rate'] ?? 0, 0); ?></span>
                    </div>
                    <?php if ($space['daily_rate']): ?>
                    <div class="rate-item">
                        <span class="rate-label">Daily Rate</span>
                        <span class="rate-value"><?php echo number_format($space['daily_rate'], 0); ?></span>
                    </div>
                    <?php endif; ?>
                    <?php if ($space['monthly_rate']): ?>
                    <div class="rate-item">
                        <span class="rate-label">Monthly Rate</span>
                        <span class="rate-value"><?php echo number_format($space['monthly_rate'], 0); ?></span>
                    </div>
                    <?php endif; ?>
                </div>
                
                <div class="booking-summary" id="bookingSummary" style="display: none;">
                    <h4 style="margin-bottom: 15px;">Booking Summary</h4>
                    <div class="summary-row">
                        <span>Duration</span>
                        <span id="durationDisplay">-</span>
                    </div>
                    <div class="summary-row">
                        <span>Rate Applied</span>
                        <span id="rateApplied">-</span>
                    </div>
                    <div class="summary-row total">
                        <span>Total Amount</span>
                        <span class="total-amount" id="totalAmount">₦0</span>
                    </div>
                </div>
                
                <div style="text-align: center; color: #6B7280; font-size: 13px; margin-top: 15px;">
                    <p>Free cancellation up to 1 hour before start time</p>
                </div>
            </div>
        </div>
    </div>
    
    <script src="https://cdn.jsdelivr.net/npm/flatpickr"></script>
    <script>
        // Initialize datetime pickers
        const startPicker = flatpickr("#start_datetime", {
            enableTime: true,
            dateFormat: "Y-m-d H:i",
            minDate: "today",
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
            minDate: "today",
            time_24hr: true,
            minuteIncrement: 15,
            onChange: function() {
                calculatePrice();
            }
        });
        
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
                    // Calculate hours
                    const hours = (end - start) / (1000 * 60 * 60);
                    const days = hours / 24;
                    
                    // Determine rate type
                    let rateType = 'hourly';
                    let rateAmount = <?php echo $space['hourly_rate'] ?? 0; ?>;
                    let total = 0;
                    
                    <?php if ($space['daily_rate']): ?>
                    if (days >= 1) {
                        rateType = 'daily';
                        rateAmount = <?php echo $space['daily_rate']; ?>;
                        total = Math.ceil(days) * rateAmount;
                    } else {
                        total = hours * rateAmount;
                    }
                    <?php else: ?>
                    total = hours * rateAmount;
                    <?php endif; ?>
                    
                    // Update summary
                    document.getElementById('durationDisplay').textContent = 
                        days >= 1 ? days.toFixed(1) + ' days' : hours.toFixed(1) + ' hours';
                    document.getElementById('rateApplied').textContent = 
                        rateType.charAt(0).toUpperCase() + rateType.slice(1) + ' (₦' + rateAmount.toFixed(0) + ')';
                    document.getElementById('totalAmount').textContent = '₦' + total.toFixed(2);
                    
                    summaryDiv.style.display = 'block';
                    submitBtn.disabled = false;
                    submitBtn.textContent = 'Confirm Booking - ₦' + total.toFixed(2);
                } else {
                    summaryDiv.style.display = 'none';
                    submitBtn.disabled = true;
                    submitBtn.textContent = 'End time must be after start time';
                }
            } else {
                summaryDiv.style.display = 'none';
                submitBtn.disabled = true;
                submitBtn.textContent = 'Select dates to see price';
            }
        }
    </script>
</body>
</html>