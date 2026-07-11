<?php
session_start();
require_once 'includes/user-access.php';
require_once 'config/database.php';

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = '';

function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

$upload_dir = 'uploads/parking/';
if (!file_exists($upload_dir)) {
    if (!mkdir($upload_dir, 0755, true)) {
        error_log("Failed to create upload directory: " . $upload_dir);
        $error = 'System error: Unable to create upload directory. Please contact support.';
    }
}

$allowed_mime_types = [
    'image/jpeg' => 'jpg',
    'image/jpg' => 'jpg',
    'image/png' => 'png',
    'image/gif' => 'gif',
    'image/webp' => 'webp'
];

$max_file_size = 5 * 1024 * 1024; // 5MB
$max_files = 10;

if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    try {
        $name = isset($_POST['name']) ? sanitize($_POST['name']) : '';
        $address = isset($_POST['address']) ? sanitize($_POST['address']) : '';
        $city = isset($_POST['city']) ? sanitize($_POST['city']) : '';
        $parking_type = isset($_POST['parking_type']) ? sanitize($_POST['parking_type']) : '';
        $total_spots = isset($_POST['total_spots']) ? (int)$_POST['total_spots'] : 0;
        $hourly_rate = isset($_POST['hourly_rate']) && !empty($_POST['hourly_rate']) ? (float)$_POST['hourly_rate'] : null;
        $daily_rate = isset($_POST['daily_rate']) && !empty($_POST['daily_rate']) ? (float)$_POST['daily_rate'] : null;
        $monthly_rate = isset($_POST['monthly_rate']) && !empty($_POST['monthly_rate']) ? (float)$_POST['monthly_rate'] : null;
        $description = isset($_POST['description']) ? sanitize($_POST['description']) : '';
        $amenities = isset($_POST['amenities']) && is_array($_POST['amenities']) ? $_POST['amenities'] : [];
        
        $errors = [];
        
        if (empty($name)) $errors[] = 'Parking space name is required.';
        elseif (strlen($name) > 255) $errors[] = 'Parking space name cannot exceed 255 characters.';
        
        if (empty($address)) $errors[] = 'Address is required.';
        elseif (strlen($address) > 500) $errors[] = 'Address cannot exceed 500 characters.';
        
        if (empty($city)) $errors[] = 'City is required.';
        elseif (strlen($city) > 100) $errors[] = 'City cannot exceed 100 characters.';
        
        if (empty($parking_type)) $errors[] = 'Parking type is required.';
        
        if ($total_spots < 1) $errors[] = 'Total spots must be at least 1.';
        elseif ($total_spots > 1000) $errors[] = 'Total spots cannot exceed 1000.';
        
        if (empty($hourly_rate) && empty($daily_rate) && empty($monthly_rate)) {
            $errors[] = 'At least one pricing option (hourly, daily, or monthly) is required.';
        }
        
        $sanitized_amenities = [];
        foreach ($amenities as $amenity) {
            $sanitized_amenities[] = sanitize($amenity);
        }
        
        $uploaded_images = [];
        $upload_errors = [];
        
        if (empty($errors) && isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $file_count = count($_FILES['images']['name']);
            if ($file_count > $max_files) $upload_errors[] = "Maximum $max_files images allowed.";
            
            for ($i = 0; $i < min($file_count, $max_files); $i++) {
                if ($_FILES['images']['error'][$i] === UPLOAD_ERR_OK) {
                    $file_name = $_FILES['images']['name'][$i];
                    $file_size = $_FILES['images']['size'][$i];
                    $file_tmp = $_FILES['images']['tmp_name'][$i];
                    $file_type = mime_content_type($file_tmp);
                    
                    if (!isset($allowed_mime_types[$file_type])) {
                        $upload_errors[] = "File '$file_name' is not an allowed image type. Allowed: JPG, PNG, GIF, WebP";
                        continue;
                    }
                    if ($file_size > $max_file_size) {
                        $upload_errors[] = "File '$file_name' exceeds the 5MB size limit.";
                        continue;
                    }
                    $image_info = getimagesize($file_tmp);
                    if ($image_info === false) {
                        $upload_errors[] = "File '$file_name' is not a valid image.";
                        continue;
                    }
                    $file_ext = $allowed_mime_types[$file_type];
                    $new_filename = uniqid() . '_' . time() . '_' . bin2hex(random_bytes(8)) . '.' . $file_ext;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($file_tmp, $upload_path)) {
                        chmod($upload_path, 0644);
                        $uploaded_images[] = $new_filename;
                    } else {
                        $upload_errors[] = "Failed to upload file '$file_name'. Please try again.";
                        error_log("Failed to move uploaded file: $upload_path");
                    }
                } elseif ($_FILES['images']['error'][$i] !== UPLOAD_ERR_NO_FILE) {
                    $upload_errors[] = "Error uploading file '" . $_FILES['images']['name'][$i] . "'. Error code: " . $_FILES['images']['error'][$i];
                }
            }
        }
        
        if (!empty($upload_errors)) {
            foreach ($upload_errors as $upload_error) $errors[] = $upload_error;
        }
        
        if (empty($errors)) {
            $amenities_json = !empty($sanitized_amenities) ? json_encode($sanitized_amenities) : null;
            $images_json = !empty($uploaded_images) ? json_encode($uploaded_images) : null;
            
            $db->beginTransaction();
            
            $query = "INSERT INTO parking_spaces 
                      (owner_id, name, address, city, parking_type, total_spots, available_spots, 
                       hourly_rate, daily_rate, monthly_rate, description, amenities, images, is_active, created_at) 
                      VALUES 
                      (:owner_id, :name, :address, :city, :parking_type, :total_spots, :available_spots,
                       :hourly_rate, :daily_rate, :monthly_rate, :description, :amenities, :images, 1, NOW())";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':owner_id', $_SESSION['user_id'], PDO::PARAM_INT);
            $stmt->bindParam(':name', $name, PDO::PARAM_STR);
            $stmt->bindParam(':address', $address, PDO::PARAM_STR);
            $stmt->bindParam(':city', $city, PDO::PARAM_STR);
            $stmt->bindParam(':parking_type', $parking_type, PDO::PARAM_STR);
            $stmt->bindParam(':total_spots', $total_spots, PDO::PARAM_INT);
            $stmt->bindParam(':available_spots', $total_spots, PDO::PARAM_INT);
            $stmt->bindParam(':hourly_rate', $hourly_rate, $hourly_rate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindParam(':daily_rate', $daily_rate, $daily_rate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindParam(':monthly_rate', $monthly_rate, $monthly_rate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
            $stmt->bindParam(':description', $description, PDO::PARAM_STR);
            $stmt->bindParam(':amenities', $amenities_json, PDO::PARAM_STR);
            $stmt->bindParam(':images', $images_json, PDO::PARAM_STR);
            
            if ($stmt->execute()) {
                $new_id = $db->lastInsertId();
                $db->commit();
                error_log("New parking space added: ID $new_id by owner ID {$_SESSION['user_id']}");
                $success = 'Parking space added successfully! Redirecting to dashboard...';
                $_POST = [];
                $uploaded_images = [];
            } else {
                $db->rollBack();
                $error = 'Failed to add parking space. Please try again.';
                error_log("Database insert failed for parking space: " . print_r($stmt->errorInfo(), true));
            }
        } else {
            $error = implode('<br>', $errors);
        }
        
    } catch (PDOException $e) {
        if ($db->inTransaction()) $db->rollBack();
        error_log("Database error in add-parking.php: " . $e->getMessage());
        $error = 'A database error occurred. Please try again later.';
    } catch (Exception $e) {
        error_log("Unexpected error in add-parking.php: " . $e->getMessage());
        $error = 'An unexpected error occurred. Please try again later.';
    }
}

$amenities_list = [
    '24/7 Access', 'Security Cameras', 'EV Charging', 'Shuttle Service', 'Security Patrol',
    'Reserved Spots', 'Valet Service', 'Student Discount', 'Permit Parking', 'Covered Parking',
    'Handicap Accessible', 'Car Wash', 'Bike Storage', 'Electric Gate', 'Lighting', 'Emergency Call Box'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=5.0, user-scalable=yes">
    <meta name="robots" content="noindex, nofollow">
    <title>Add Parking Space - SpaceNode</title>
    <link href="https://fonts.googleapis.com/css2?family=Outfit:wght@400;500;600;700;800;900&family=DM+Sans:wght@400;500;600&display=swap" rel="stylesheet">
    <link rel="stylesheet" href="https://cdnjs.cloudflare.com/ajax/libs/font-awesome/6.0.0/css/all.min.css">
    <style>
        /* Global Glassmorphism Reset */
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
            animation: meshPulse 12s ease-in-out infinite alternate;
        }
        
        @keyframes meshPulse {
            0% { opacity: 0.7; transform: scale(1); }
            100% { opacity: 1; transform: scale(1.02); }
        }
        
        /* Glass Container */
        .glass-container {
            max-width: 900px;
            margin: 0 auto;
            position: relative;
            z-index: 1;
            background: rgba(255,255,255,0.06);
            backdrop-filter: blur(20px);
            -webkit-backdrop-filter: blur(20px);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 32px;
            box-shadow: 0 8px 32px rgba(0,0,0,0.2);
            overflow: hidden;
            transition: all 0.3s ease;
        }
        
        .glass-container:hover {
            background: rgba(255,255,255,0.08);
            border-color: rgba(255,255,255,0.25);
        }
        
        /* Header */
        .glass-header {
            background: linear-gradient(135deg, rgba(79,110,247,0.15), rgba(124,58,237,0.15));
            backdrop-filter: blur(10px);
            border-bottom: 1px solid rgba(255,255,255,0.1);
            padding: 30px 35px;
        }
        
        .glass-header h1 {
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
        
        .glass-header p {
            color: rgba(255,255,255,0.7);
            font-size: 14px;
        }
        
        .back-link {
            display: inline-flex;
            align-items: center;
            gap: 8px;
            margin-bottom: 15px;
            color: rgba(255,255,255,0.8);
            text-decoration: none;
            font-size: 14px;
            font-weight: 500;
            transition: all 0.3s;
        }
        
        .back-link:hover {
            color: #a5b4fc;
            gap: 12px;
        }
        
        /* Form Container */
        .form-container {
            padding: 35px;
        }
        
        /* Glass Form Elements */
        .form-group {
            margin-bottom: 24px;
        }
        
        .form-row {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 18px;
        }
        
        .form-row-2 {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 18px;
        }
        
        label {
            display: block;
            margin-bottom: 8px;
            font-weight: 500;
            font-size: 13px;
            color: rgba(255,255,255,0.8);
        }
        
        .required::after {
            content: ' *';
            color: #f87171;
        }
        
        input, select, textarea {
            width: 100%;
            padding: 12px 16px;
            background: rgba(255,255,255,0.08);
            backdrop-filter: blur(10px);
            border: 1px solid rgba(255,255,255,0.2);
            border-radius: 60px;
            font-size: 14px;
            font-family: 'DM Sans', sans-serif;
            color: white;
            transition: all 0.3s ease;
        }
        
        input:focus, select:focus, textarea:focus {
            outline: none;
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.12);
            box-shadow: 0 0 0 3px rgba(79,110,247,0.2);
        }
        
        input::placeholder, textarea::placeholder {
            color: rgba(255,255,255,0.4);
        }
        
        select option {
            background: #1a1a2e;
            color: white;
        }
        
        textarea {
            border-radius: 24px;
            resize: vertical;
        }
        
        .help-text {
            font-size: 11px;
            color: rgba(255,255,255,0.5);
            margin-top: 6px;
        }
        
        /* Glass Amenities Grid */
        .amenities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(160px, 1fr));
            gap: 12px;
            padding: 16px;
            background: rgba(255,255,255,0.05);
            border: 1px solid rgba(255,255,255,0.15);
            border-radius: 20px;
            max-height: 280px;
            overflow-y: auto;
        }
        
        .amenity-item {
            display: flex;
            align-items: center;
            gap: 10px;
            font-size: 13px;
            cursor: pointer;
            color: rgba(255,255,255,0.8);
            padding: 6px 10px;
            border-radius: 50px;
            transition: all 0.2s;
        }
        
        .amenity-item:hover {
            background: rgba(79,110,247,0.1);
        }
        
        .amenity-item input[type="checkbox"] {
            width: 16px;
            height: 16px;
            accent-color: #4F6EF7;
            cursor: pointer;
            margin: 0;
        }
        
        /* Glass Upload Area */
        .upload-area {
            background: rgba(255,255,255,0.05);
            backdrop-filter: blur(10px);
            border: 2px dashed rgba(255,255,255,0.2);
            border-radius: 20px;
            padding: 28px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        
        .upload-area:hover {
            border-color: rgba(165,180,252,0.6);
            background: rgba(255,255,255,0.08);
        }
        
        .upload-area.dragover {
            border-color: #4F6EF7;
            background: rgba(79,110,247,0.08);
        }
        
        .upload-icon {
            width: 50px;
            height: 50px;
            margin-bottom: 12px;
            color: rgba(255,255,255,0.5);
        }
        
        .upload-text {
            font-size: 14px;
            color: rgba(255,255,255,0.8);
            margin-bottom: 5px;
        }
        
        .upload-hint {
            font-size: 12px;
            color: rgba(255,255,255,0.5);
        }
        
        /* Image Preview Grid */
        .image-preview {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 12px;
            margin-top: 20px;
        }
        
        .preview-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 16px;
            overflow: hidden;
            border: 2px solid transparent;
            cursor: pointer;
            transition: all 0.2s;
        }
        
        .preview-item.selected {
            border-color: #a5b4fc;
            box-shadow: 0 0 0 2px rgba(165,180,252,0.4);
        }
        
        .preview-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        
        .remove-image {
            position: absolute;
            top: 6px;
            right: 6px;
            width: 26px;
            height: 26px;
            background: rgba(0,0,0,0.6);
            backdrop-filter: blur(5px);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
            transition: all 0.2s;
        }
        
        .remove-image:hover {
            background: #DC2626;
            transform: scale(1.1);
        }
        
        /* Glass Submit Button */
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
            margin-top: 10px;
            position: relative;
            overflow: hidden;
            box-shadow: 0 4px 20px rgba(79,110,247,0.3);
        }
        
        .btn-submit::before {
            content: '';
            position: absolute;
            top: -50%;
            left: -75%;
            width: 50%;
            height: 200%;
            background: linear-gradient(90deg, transparent, rgba(255,255,255,0.15), transparent);
            transform: skewX(-20deg);
            transition: left 0.5s ease;
        }
        
        .btn-submit:hover::before {
            left: 130%;
        }
        
        .btn-submit:hover:not(:disabled) {
            transform: translateY(-2px);
            box-shadow: 0 10px 30px rgba(79,110,247,0.4);
        }
        
        .btn-submit:disabled {
            opacity: 0.6;
            cursor: not-allowed;
        }
        
        /* Glass Alerts */
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
            from { opacity: 0; transform: translateY(-15px); }
            to { opacity: 1; transform: translateY(0); }
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
        
        /* Loader */
        .loader {
            display: inline-block;
            width: 18px;
            height: 18px;
            border: 2px solid #fff;
            border-radius: 50%;
            border-top-color: transparent;
            animation: spin 0.6s linear infinite;
            margin-left: 10px;
            vertical-align: middle;
        }
        
        @keyframes spin {
            to { transform: rotate(360deg); }
        }
        
        /* Custom Scrollbar */
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
        
        /* Responsive */
        @media (max-width: 768px) {
            body { padding: 20px 15px; }
            .form-row, .form-row-2 { grid-template-columns: 1fr; }
            .image-preview { grid-template-columns: repeat(2, 1fr); }
            .glass-header { padding: 25px; }
            .form-container { padding: 25px; }
            .glass-header h1 { font-size: 24px; }
        }
        
        @media (max-width: 480px) {
            .form-container { padding: 20px; }
            .glass-header { padding: 20px; }
            .amenities-grid { grid-template-columns: 1fr; }
            .image-preview { grid-template-columns: 1fr; }
        }
    </style>
</head>
<body>
    <div class="glass-container">
        <div class="glass-header">
            <a href="dashboard.php" class="back-link"><i class="fas fa-arrow-left"></i> Back to Dashboard</a>
            <h1><i class="fas fa-plus-circle"></i> Add New Parking Space</h1>
            <p>List your parking space and start earning</p>
        </div>
        
        <div class="form-container">
            <?php if ($error): ?>
                <div class="alert alert-error"><i class="fas fa-exclamation-triangle"></i> <?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><i class="fas fa-check-circle"></i> <?php echo $success; ?></div>
                <script>
                    setTimeout(function() {
                        window.location.href = 'dashboard.php';
                    }, 2000);
                </script>
            <?php endif; ?>
            
            <form method="POST" action="" enctype="multipart/form-data" id="parkingForm" novalidate>
                <div class="form-group">
                    <label class="required"><i class="fas fa-parking"></i> Parking Space Name</label>
                    <input type="text" name="name" required maxlength="255" 
                           value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name'], ENT_QUOTES, 'UTF-8') : ''; ?>" 
                           placeholder="e.g., Downtown Plaza Parking">
                </div>
                
                <div class="form-group">
                    <label class="required"><i class="fas fa-location-dot"></i> Full Address</label>
                    <input type="text" name="address" required maxlength="500" 
                           value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address'], ENT_QUOTES, 'UTF-8') : ''; ?>" 
                           placeholder="Street address, building name">
                </div>
                
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="required"><i class="fas fa-city"></i> City/Area</label>
                        <input type="text" name="city" required maxlength="100" 
                               value="<?php echo isset($_POST['city']) ? htmlspecialchars($_POST['city'], ENT_QUOTES, 'UTF-8') : ''; ?>" 
                               placeholder="e.g., Downtown">
                    </div>
                    
                    <div class="form-group">
                        <label class="required"><i class="fas fa-tag"></i> Parking Type</label>
                        <select name="parking_type" required>
                            <option value="">Select type</option>
                            <option value="covered_garage" <?php echo (isset($_POST['parking_type']) && $_POST['parking_type'] == 'covered_garage') ? 'selected' : ''; ?>>Covered Garage</option>
                            <option value="open_lot" <?php echo (isset($_POST['parking_type']) && $_POST['parking_type'] == 'open_lot') ? 'selected' : ''; ?>>Open Lot</option>
                            <option value="underground" <?php echo (isset($_POST['parking_type']) && $_POST['parking_type'] == 'underground') ? 'selected' : ''; ?>>Underground</option>
                            <option value="street_parking" <?php echo (isset($_POST['parking_type']) && $_POST['parking_type'] == 'street_parking') ? 'selected' : ''; ?>>Street Parking</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-group">
                    <label class="required"><i class="fas fa-warehouse"></i> Total Parking Spots</label>
                    <input type="number" name="total_spots" required min="1" max="1000" 
                           value="<?php echo isset($_POST['total_spots']) ? (int)$_POST['total_spots'] : ''; ?>" 
                           placeholder="Number of parking spots">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label><i class="fas fa-clock"></i> Hourly Rate (₦)</label>
                        <input type="number" name="hourly_rate" min="0" step="0.01" 
                               value="<?php echo isset($_POST['hourly_rate']) ? htmlspecialchars($_POST['hourly_rate'], ENT_QUOTES, 'UTF-8') : ''; ?>" 
                               placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-calendar-day"></i> Daily Rate (₦)</label>
                        <input type="number" name="daily_rate" min="0" step="0.01" 
                               value="<?php echo isset($_POST['daily_rate']) ? htmlspecialchars($_POST['daily_rate'], ENT_QUOTES, 'UTF-8') : ''; ?>" 
                               placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label><i class="fas fa-calendar-alt"></i> Monthly Rate (₦)</label>
                        <input type="number" name="monthly_rate" min="0" step="0.01" 
                               value="<?php echo isset($_POST['monthly_rate']) ? htmlspecialchars($_POST['monthly_rate'], ENT_QUOTES, 'UTF-8') : ''; ?>" 
                               placeholder="0.00">
                    </div>
                </div>
                <div class="help-text"><i class="fas fa-info-circle"></i> At least one pricing option is required.</div>
                
                <div class="form-group">
                    <label><i class="fas fa-file-alt"></i> Description</label>
                    <textarea name="description" rows="4" maxlength="2000" 
                              placeholder="Describe your parking space (security features, location highlights, special instructions, etc.)"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description'], ENT_QUOTES, 'UTF-8') : ''; ?></textarea>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-star"></i> Amenities & Features</label>
                    <div class="amenities-grid">
                        <?php foreach ($amenities_list as $amenity): ?>
                        <label class="amenity-item">
                            <input type="checkbox" name="amenities[]" value="<?php echo htmlspecialchars($amenity, ENT_QUOTES, 'UTF-8'); ?>" 
                                <?php echo (isset($_POST['amenities']) && in_array($amenity, $_POST['amenities'])) ? 'checked' : ''; ?>>
                            <span><?php echo htmlspecialchars($amenity, ENT_QUOTES, 'UTF-8'); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="help-text">Select all amenities that apply to your parking space</div>
                </div>
                
                <div class="form-group">
                    <label><i class="fas fa-images"></i> Upload Images (Max 5MB each, up to 10 images)</label>
                    <input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" style="display: none;" id="imageInput">
                    
                    <div class="upload-area" id="uploadArea" onclick="document.getElementById('imageInput').click()">
                        <i class="fas fa-cloud-upload-alt upload-icon"></i>
                        <div class="upload-text">Click or drag images to upload</div>
                        <div class="upload-hint">JPG, PNG, GIF or WebP (Max 5MB each)</div>
                    </div>
                    
                    <div class="image-preview" id="imagePreview"></div>
                    <div class="help-text"><i class="fas fa-image"></i> First image will be the main photo. Click on an image to set as main (selected images have blue border).</div>
                </div>
                
                <button type="submit" class="btn-submit" id="submitBtn">
                    <i class="fas fa-plus-circle"></i> Add Parking Space
                </button>
            </form>
        </div>
    </div>
    
    <script>
        // Drag and drop functionality
        const uploadArea = document.getElementById('uploadArea');
        const imageInput = document.getElementById('imageInput');
        const imagePreview = document.getElementById('imagePreview');
        let selectedFiles = [];
        let selectedMainIndex = 0;
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        ['dragenter', 'dragover'].forEach(eventName => {
            uploadArea.addEventListener(eventName, highlight, false);
        });
        
        ['dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, unhighlight, false);
        });
        
        function highlight(e) {
            uploadArea.classList.add('dragover');
        }
        
        function unhighlight(e) {
            uploadArea.classList.remove('dragover');
        }
        
        uploadArea.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }
        
        imageInput.addEventListener('change', function() {
            handleFiles(this.files);
        });
        
        function handleFiles(files) {
            const maxFiles = 10;
            const maxSize = 5 * 1024 * 1024;
            const allowedTypes = ['image/jpeg', 'image/jpg', 'image/png', 'image/gif', 'image/webp'];
            let validFiles = [];
            let errors = [];
            
            for (let i = 0; i < Math.min(files.length, maxFiles); i++) {
                const file = files[i];
                if (!allowedTypes.includes(file.type)) {
                    errors.push(`"${file.name}" is not an allowed image type.`);
                    continue;
                }
                if (file.size > maxSize) {
                    errors.push(`"${file.name}" exceeds 5MB limit.`);
                    continue;
                }
                validFiles.push(file);
            }
            
            if (errors.length > 0) {
                alert(errors.join('\n'));
            }
            
            if (validFiles.length > 0) {
                selectedFiles = [...selectedFiles, ...validFiles].slice(0, maxFiles);
                previewImages();
            }
        }
        
        function previewImages() {
            imagePreview.innerHTML = '';
            for (let i = 0; i < selectedFiles.length; i++) {
                const file = selectedFiles[i];
                const reader = new FileReader();
                reader.onload = function(e) {
                    const previewItem = document.createElement('div');
                    previewItem.className = 'preview-item' + (i === selectedMainIndex ? ' selected' : '');
                    previewItem.setAttribute('data-index', i);
                    
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    img.className = 'preview-img';
                    
                    const removeBtn = document.createElement('button');
                    removeBtn.className = 'remove-image';
                    removeBtn.innerHTML = '×';
                    removeBtn.onclick = function(e) {
                        e.stopPropagation();
                        removeImage(i);
                    };
                    
                    previewItem.appendChild(img);
                    previewItem.appendChild(removeBtn);
                    previewItem.onclick = function(e) {
                        if (e.target !== removeBtn) {
                            selectMainImage(i);
                        }
                    };
                    imagePreview.appendChild(previewItem);
                }
                reader.readAsDataURL(file);
            }
            const dt = new DataTransfer();
            selectedFiles.forEach(file => dt.items.add(file));
            imageInput.files = dt.files;
        }
        
        function removeImage(index) {
            selectedFiles.splice(index, 1);
            if (selectedMainIndex >= selectedFiles.length) {
                selectedMainIndex = Math.max(0, selectedFiles.length - 1);
            }
            previewImages();
        }
        
        function selectMainImage(index) {
            selectedMainIndex = index;
            previewImages();
        }
        
        // Form validation
        const form = document.getElementById('parkingForm');
        const submitBtn = document.getElementById('submitBtn');
        
        form.addEventListener('submit', function(e) {
            const totalSpots = document.querySelector('input[name="total_spots"]').value;
            const hourlyRate = document.querySelector('input[name="hourly_rate"]').value;
            const dailyRate = document.querySelector('input[name="daily_rate"]').value;
            const monthlyRate = document.querySelector('input[name="monthly_rate"]').value;
            
            if (!hourlyRate && !dailyRate && !monthlyRate) {
                e.preventDefault();
                alert('Please set at least one pricing option (hourly, daily, or monthly rate).');
                return false;
            }
            if (totalSpots < 1) {
                e.preventDefault();
                alert('Total spots must be at least 1.');
                return false;
            }
            if (totalSpots > 1000) {
                e.preventDefault();
                alert('Total spots cannot exceed 1000.');
                return false;
            }
            submitBtn.disabled = true;
            submitBtn.innerHTML = 'Adding Parking Space... <span class="loader"></span>';
        });
    </script>
</body>
</html>