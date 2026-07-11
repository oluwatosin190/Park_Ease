<?php
session_start(); // Start session at the beginning
require_once 'includes/user-access.php';
require_once 'config/database.php';

// Function to sanitize output
function sanitize($input) {
    return htmlspecialchars($input, ENT_QUOTES, 'UTF-8');
}

// Function to get image URL safely
function getImageUrl($image) {
    $image_path = 'uploads/parking/' . $image;
    return file_exists($image_path) ? $image_path : null;
}

// Function to log actions
function logAction($db, $user_id, $action, $details = null) {
    try {
        $log_query = "INSERT INTO admin_logs (user_id, action, details, ip_address, created_at) 
                      VALUES (:user_id, :action, :details, :ip, NOW())";
        $log_stmt = $db->prepare($log_query);
        $log_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
        $log_stmt->bindParam(':action', $action, PDO::PARAM_STR);
        $log_stmt->bindParam(':details', $details, PDO::PARAM_STR);
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        $log_stmt->bindParam(':ip', $ip, PDO::PARAM_STR);
        $log_stmt->execute();
    } catch (Exception $e) {
        error_log("Failed to log action: " . $e->getMessage());
    }
}

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'owner') {
    $_SESSION['error'] = 'Access denied. Only parking space owners can edit spaces.';
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$parking_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if ($parking_id <= 0) {
    $_SESSION['error'] = 'Invalid parking space ID.';
    header('Location: dashboard.php');
    exit();
}

// Get parking space details and verify ownership
$query = "SELECT * FROM parking_spaces WHERE id = :id AND owner_id = :owner_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $parking_id, PDO::PARAM_INT);
$stmt->bindParam(':owner_id', $_SESSION['user_id'], PDO::PARAM_INT);
$stmt->execute();
$space = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$space) {
    $_SESSION['error'] = 'Parking space not found or you do not have permission to edit it.';
    header('Location: dashboard.php');
    exit();
}

// Decode amenities and images
$amenities = !empty($space['amenities']) ? json_decode($space['amenities'], true) : [];
$existing_images = !empty($space['images']) ? json_decode($space['images'], true) : [];

// Validate that existing images actually exist on server
$valid_images = [];
foreach ($existing_images as $image) {
    if (file_exists('uploads/parking/' . $image)) {
        $valid_images[] = $image;
    } else {
        error_log("Missing image file for space ID {$parking_id}: {$image}");
    }
}
$existing_images = $valid_images;

$error = '';
$success = '';

// Define upload directory and allowed types
$target_dir = "uploads/parking/";
$allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif', 'image/webp'];
$max_size = 5 * 1024 * 1024; // 5MB
$max_images = 10; // Maximum images per space

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    // Validate CSRF token (optional but recommended)
    if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
        $error = 'Invalid security token. Please try again.';
    } else {
        // Sanitize and validate inputs
        $name = trim($_POST['name']);
        $address = trim($_POST['address']);
        $city = trim($_POST['city']);
        $parking_type = isset($_POST['parking_type']) ? sanitize($_POST['parking_type']) : '';
        $total_spots = isset($_POST['total_spots']) ? (int)$_POST['total_spots'] : 0;
        $available_spots = isset($_POST['available_spots']) ? (int)$_POST['available_spots'] : 0;
        $hourly_rate = !empty($_POST['hourly_rate']) ? (float)$_POST['hourly_rate'] : null;
        $daily_rate = !empty($_POST['daily_rate']) ? (float)$_POST['daily_rate'] : null;
        $monthly_rate = !empty($_POST['monthly_rate']) ? (float)$_POST['monthly_rate'] : null;
        $description = trim($_POST['description']);
        $selected_amenities = isset($_POST['amenities']) && is_array($_POST['amenities']) ? $_POST['amenities'] : [];
        
        // Validation
        $errors = [];
        
        if (empty($name)) {
            $errors[] = 'Parking space name is required.';
        } elseif (strlen($name) > 255) {
            $errors[] = 'Parking space name cannot exceed 255 characters.';
        }
        
        if (empty($address)) {
            $errors[] = 'Address is required.';
        } elseif (strlen($address) > 500) {
            $errors[] = 'Address cannot exceed 500 characters.';
        }
        
        if (empty($city)) {
            $errors[] = 'City is required.';
        } elseif (strlen($city) > 100) {
            $errors[] = 'City cannot exceed 100 characters.';
        }
        
        if (empty($parking_type)) {
            $errors[] = 'Parking type is required.';
        }
        
        if ($total_spots < 1) {
            $errors[] = 'Total spots must be at least 1.';
        } elseif ($total_spots > 1000) {
            $errors[] = 'Total spots cannot exceed 1000.';
        }
        
        if ($available_spots < 0) {
            $errors[] = 'Available spots cannot be negative.';
        } elseif ($available_spots > $total_spots) {
            $errors[] = 'Available spots cannot exceed total spots.';
        }
        
        // Sanitize amenities
        $sanitized_amenities = array_map('sanitize', $selected_amenities);
        
        if (empty($errors)) {
            // Begin transaction
            $db->beginTransaction();
            
            try {
                // Handle new image uploads
                $uploaded_images = $existing_images;
                
                if (isset($_FILES['new_images']) && !empty($_FILES['new_images']['name'][0])) {
                    $new_images_count = count($uploaded_images);
                    
                    foreach ($_FILES['new_images']['tmp_name'] as $key => $tmp_name) {
                        // Check image limit
                        if ($new_images_count >= $max_images) {
                            $errors[] = "Maximum $max_images images allowed per space.";
                            break;
                        }
                        
                        if ($_FILES['new_images']['error'][$key] === UPLOAD_ERR_OK) {
                            $file_name = $_FILES['new_images']['name'][$key];
                            $file_size = $_FILES['new_images']['size'][$key];
                            $file_tmp = $_FILES['new_images']['tmp_name'][$key];
                            $file_type = mime_content_type($file_tmp);
                            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
                            
                            // Validate file type
                            if (!in_array($file_type, $allowed_types)) {
                                $errors[] = "File '$file_name' is not an allowed image type. Allowed: JPG, PNG, GIF, WebP";
                                continue;
                            }
                            
                            // Validate file size
                            if ($file_size > $max_size) {
                                $errors[] = "File '$file_name' exceeds the 5MB size limit.";
                                continue;
                            }
                            
                            // Validate image
                            $image_info = getimagesize($file_tmp);
                            if ($image_info === false) {
                                $errors[] = "File '$file_name' is not a valid image.";
                                continue;
                            }
                            
                            // Create directory if it doesn't exist
                            if (!file_exists($target_dir)) {
                                mkdir($target_dir, 0755, true);
                            }
                            
                            // Generate unique filename
                            $new_filename = uniqid() . '_' . time() . '_' . bin2hex(random_bytes(4)) . '.' . $file_ext;
                            $upload_path = $target_dir . $new_filename;
                            
                            if (move_uploaded_file($file_tmp, $upload_path)) {
                                chmod($upload_path, 0644);
                                $uploaded_images[] = $new_filename;
                                $new_images_count++;
                            } else {
                                $errors[] = "Failed to upload file '$file_name'.";
                            }
                        }
                    }
                }
                
                // Handle deleted images
                if (isset($_POST['delete_images']) && is_array($_POST['delete_images'])) {
                    foreach ($_POST['delete_images'] as $delete_image) {
                        $delete_image = basename($delete_image); // Prevent path traversal
                        $key = array_search($delete_image, $uploaded_images);
                        if ($key !== false) {
                            // Delete file from server
                            $file_path = $target_dir . $delete_image;
                            if (file_exists($file_path)) {
                                if (unlink($file_path)) {
                                    error_log("Deleted image: $file_path");
                                } else {
                                    error_log("Failed to delete image: $file_path");
                                }
                            }
                            // Remove from array
                            unset($uploaded_images[$key]);
                        }
                    }
                    // Reindex array
                    $uploaded_images = array_values($uploaded_images);
                }
                
                if (empty($errors)) {
                    // Convert arrays to JSON
                    $amenities_json = !empty($sanitized_amenities) ? json_encode($sanitized_amenities) : null;
                    $images_json = !empty($uploaded_images) ? json_encode($uploaded_images) : null;
                    
                    // Update database
                    $update_query = "UPDATE parking_spaces SET 
                                      name = :name, 
                                      address = :address, 
                                      city = :city, 
                                      parking_type = :parking_type, 
                                      total_spots = :total_spots, 
                                      available_spots = :available_spots, 
                                      hourly_rate = :hourly_rate, 
                                      daily_rate = :daily_rate, 
                                      monthly_rate = :monthly_rate, 
                                      description = :description, 
                                      amenities = :amenities, 
                                      images = :images 
                                      WHERE id = :id AND owner_id = :owner_id";
                    
                    $update_stmt = $db->prepare($update_query);
                    $update_stmt->bindParam(':name', $name, PDO::PARAM_STR);
                    $update_stmt->bindParam(':address', $address, PDO::PARAM_STR);
                    $update_stmt->bindParam(':city', $city, PDO::PARAM_STR);
                    $update_stmt->bindParam(':parking_type', $parking_type, PDO::PARAM_STR);
                    $update_stmt->bindParam(':total_spots', $total_spots, PDO::PARAM_INT);
                    $update_stmt->bindParam(':available_spots', $available_spots, PDO::PARAM_INT);
                    $update_stmt->bindParam(':hourly_rate', $hourly_rate, $hourly_rate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $update_stmt->bindParam(':daily_rate', $daily_rate, $daily_rate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $update_stmt->bindParam(':monthly_rate', $monthly_rate, $monthly_rate === null ? PDO::PARAM_NULL : PDO::PARAM_STR);
                    $update_stmt->bindParam(':description', $description, PDO::PARAM_STR);
                    $update_stmt->bindParam(':amenities', $amenities_json, PDO::PARAM_STR);
                    $update_stmt->bindParam(':images', $images_json, PDO::PARAM_STR);
                    $update_stmt->bindParam(':id', $parking_id, PDO::PARAM_INT);
                    $update_stmt->bindParam(':owner_id', $_SESSION['user_id'], PDO::PARAM_INT);
                    
                    if ($update_stmt->execute()) {
                        $db->commit();
                        
                        // Log the update
                        $log_details = "Parking space updated: ID {$parking_id}, Name: {$name}";
                        logAction($db, $_SESSION['user_id'], 'edit_parking_space', $log_details);
                        
                        $success = 'Parking space updated successfully!';
                        
                        // Refresh space data
                        $refresh_stmt = $db->prepare("SELECT * FROM parking_spaces WHERE id = :id AND owner_id = :owner_id");
                        $refresh_stmt->bindParam(':id', $parking_id, PDO::PARAM_INT);
                        $refresh_stmt->bindParam(':owner_id', $_SESSION['user_id'], PDO::PARAM_INT);
                        $refresh_stmt->execute();
                        $space = $refresh_stmt->fetch(PDO::FETCH_ASSOC);
                        $amenities = !empty($space['amenities']) ? json_decode($space['amenities'], true) : [];
                        $existing_images = !empty($space['images']) ? json_decode($space['images'], true) : [];
                        
                    } else {
                        $db->rollBack();
                        $errors[] = 'Failed to update parking space. Please try again.';
                        error_log("Failed to update parking space ID: $parking_id");
                    }
                }
                
                if (!empty($errors)) {
                    $error = implode('<br>', $errors);
                }
                
            } catch (PDOException $e) {
                $db->rollBack();
                error_log("Database error in edit-parking.php: " . $e->getMessage());
                $error = 'A database error occurred. Please try again later.';
            } catch (Exception $e) {
                $db->rollBack();
                error_log("Unexpected error in edit-parking.php: " . $e->getMessage());
                $error = 'An unexpected error occurred. Please try again later.';
            }
        } else {
            $error = implode('<br>', $errors);
        }
    }
}

// Generate CSRF token
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// List of common amenities
$amenities_list = [
    '24/7 Access',
    'Security Cameras',
    'EV Charging',
    'Shuttle Service',
    'Security Patrol',
    'Reserved Spots',
    'Valet Service',
    'Student Discount',
    'Permit Parking',
    'Covered Parking',
    'Handicap Accessible',
    'Car Wash',
    'Bike Storage',
    'Electric Gate',
    'Lighting',
    'Emergency Call Box'
];
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <meta name="robots" content="noindex, nofollow">
    <title>Edit Parking Space - SpaceNode</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        /* [CSS remains the same as your original - no changes needed] */
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #F9FAFB;
            padding: 40px 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
        }
        .header {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            padding: 30px;
            border-radius: 20px 20px 0 0;
        }
        .header h1 {
            font-size: 24px;
            margin-bottom: 5px;
        }
        .header p {
            opacity: 0.9;
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
        .back-link:hover {
            text-decoration: underline;
        }
        .form-container {
            background: white;
            padding: 30px;
            border-radius: 0 0 20px 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr;
            gap: 20px;
        }
        .form-row-3 {
            display: grid;
            grid-template-columns: repeat(3, 1fr);
            gap: 20px;
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
        .amenities-grid {
            display: grid;
            grid-template-columns: repeat(auto-fill, minmax(150px, 1fr));
            gap: 10px;
            padding: 15px;
            border: 1px solid #E5E7EB;
            border-radius: 10px;
            max-height: 300px;
            overflow-y: auto;
        }
        .amenity-item {
            display: flex;
            align-items: center;
            gap: 8px;
            font-size: 14px;
            cursor: pointer;
        }
        .amenity-item input[type="checkbox"] {
            width: auto;
            accent-color: #4F6EF7;
            cursor: pointer;
        }
        
        /* Image Gallery */
        .image-gallery {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 15px;
            margin: 20px 0;
        }
        .image-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid #E5E7EB;
        }
        .image-item img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .image-checkbox {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 28px;
            height: 28px;
            background: rgba(255,255,255,0.9);
            border-radius: 4px;
            display: flex;
            align-items: center;
            justify-content: center;
            cursor: pointer;
        }
        .image-checkbox input {
            width: 18px;
            height: 18px;
            cursor: pointer;
        }
        .delete-label {
            position: absolute;
            bottom: 5px;
            left: 5px;
            background: rgba(220,38,38,0.9);
            color: white;
            padding: 2px 6px;
            border-radius: 4px;
            font-size: 10px;
            font-weight: 500;
        }
        
        /* Upload Area */
        .upload-area {
            border: 2px dashed #E5E7EB;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            margin: 20px 0;
        }
        .upload-area:hover {
            border-color: #4F6EF7;
            background: #F9FAFB;
        }
        .upload-icon {
            width: 48px;
            height: 48px;
            margin-bottom: 10px;
            color: #9CA3AF;
        }
        .upload-text {
            font-size: 14px;
            color: #374151;
            margin-bottom: 5px;
        }
        .upload-hint {
            font-size: 12px;
            color: #6B7280;
        }
        
        /* Buttons */
        .btn-submit {
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
            margin-top: 20px;
        }
        .btn-submit:hover {
            transform: translateY(-2px);
        }
        .btn-secondary {
            background: #F3F4F6;
            color: #374151;
            padding: 10px 20px;
            border: none;
            border-radius: 8px;
            font-size: 14px;
            font-weight: 500;
            cursor: pointer;
            text-decoration: none;
            display: inline-block;
            transition: background 0.2s;
        }
        .btn-secondary:hover {
            background: #E5E7EB;
        }
        
        /* Alerts */
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
        
        .help-text {
            font-size: 12px;
            color: #6B7280;
            margin-top: 5px;
        }
        
        @media (max-width: 768px) {
            .form-row, .form-row-3 {
                grid-template-columns: 1fr;
            }
            .image-gallery {
                grid-template-columns: repeat(2, 1fr);
            }
            .container {
                margin: 0 10px;
            }
            .header, .form-container {
                padding: 20px;
            }
        }
    </style>
</head>
<body>
    <div class="container">
        <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
        
        <div class="header">
            <h1>Edit Parking Space</h1>
            <p>Update your parking space information</p>
        </div>
        
        <div class="form-container">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
            <?php endif; ?>
            
            <form method="POST" action="" enctype="multipart/form-data" id="parkingForm">
                <input type="hidden" name="csrf_token" value="<?php echo $_SESSION['csrf_token']; ?>">
                
                <div class="form-group">
                    <label class="required">Parking Space Name</label>
                    <input type="text" name="name" required maxlength="255" value="<?php echo sanitize($space['name']); ?>" placeholder="e.g., Downtown Plaza Parking">
                </div>
                
                <div class="form-group">
                    <label class="required">Full Address</label>
                    <input type="text" name="address" required maxlength="500" value="<?php echo sanitize($space['address']); ?>" placeholder="Street address, building name">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">City/Area</label>
                        <input type="text" name="city" required maxlength="100" value="<?php echo sanitize($space['city']); ?>" placeholder="e.g., Downtown">
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Parking Type</label>
                        <select name="parking_type" required>
                            <option value="">Select type</option>
                            <option value="covered_garage" <?php echo $space['parking_type'] == 'covered_garage' ? 'selected' : ''; ?>>Covered Garage</option>
                            <option value="open_lot" <?php echo $space['parking_type'] == 'open_lot' ? 'selected' : ''; ?>>Open Lot</option>
                            <option value="underground" <?php echo $space['parking_type'] == 'underground' ? 'selected' : ''; ?>>Underground</option>
                            <option value="street_parking" <?php echo $space['parking_type'] == 'street_parking' ? 'selected' : ''; ?>>Street Parking</option>
                        </select>
                    </div>
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label class="required">Total Spots</label>
                        <input type="number" name="total_spots" required min="1" max="1000" value="<?php echo (int)$space['total_spots']; ?>" placeholder="Total number of spots">
                    </div>
                    <div class="form-group">
                        <label class="required">Available Spots</label>
                        <input type="number" name="available_spots" required min="0" max="<?php echo (int)$space['total_spots']; ?>" value="<?php echo (int)$space['available_spots']; ?>" placeholder="Currently available spots" id="available_spots">
                    </div>
                </div>

                <!-- Quick availability toggles -->
                <div class="form-group">
                    <label>Quick Set Availability</label>
                    <div style="display: flex; gap: 10px; flex-wrap: wrap;">
                        <button type="button" class="btn-secondary" onclick="setAvailableSpots(<?php echo (int)$space['total_spots']; ?>)">
                            Set All Available
                        </button>
                        <button type="button" class="btn-secondary" onclick="setAvailableSpots(0)">
                            Set Full
                        </button>
                        <?php 
                        $total = (int)$space['total_spots'];
                        if ($total >= 5):
                        ?>
                        <button type="button" class="btn-secondary" onclick="setAvailableSpots(Math.floor(<?php echo $total; ?> / 2))">
                            Half Full (<?php echo floor($total / 2); ?> spots)
                        </button>
                        <?php endif; ?>
                    </div>
                </div>
                
                <div class="form-row-3">
                    <div class="form-group">
                        <label>Hourly Rate (₦)</label>
                        <input type="number" name="hourly_rate" min="0" step="0.01" value="<?php echo $space['hourly_rate'] ?: ''; ?>" placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label>Daily Rate (₦)</label>
                        <input type="number" name="daily_rate" min="0" step="0.01" value="<?php echo $space['daily_rate'] ?: ''; ?>" placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label>Monthly Rate (₦)</label>
                        <input type="number" name="monthly_rate" min="0" step="0.01" value="<?php echo $space['monthly_rate'] ?: ''; ?>" placeholder="0.00">
                    </div>
                </div>
                <div class="help-text">Note: At least one pricing option is recommended.</div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="4" maxlength="2000" placeholder="Describe your parking space"><?php echo sanitize($space['description']); ?></textarea>
                </div>
                
                <!-- Current Images -->
                <?php if (!empty($existing_images)): ?>
                <div class="form-group">
                    <label>Current Images</label>
                    <p class="help-text">Check any images you want to delete</p>
                    <div class="image-gallery">
                        <?php foreach ($existing_images as $image): ?>
                        <div class="image-item">
                            <img src="uploads/parking/<?php echo sanitize($image); ?>" alt="Parking space">
                            <label class="image-checkbox">
                                <input type="checkbox" name="delete_images[]" value="<?php echo sanitize($image); ?>">
                            </label>
                            <span class="delete-label">Delete</span>
                        </div>
                        <?php endforeach; ?>
                    </div>
                </div>
                <?php endif; ?>
                
                <!-- Upload New Images -->
                <div class="form-group">
                    <label>Add New Images (Max <?php echo 10 - count($existing_images); ?> images)</label>
                    <input type="file" name="new_images[]" multiple accept="image/jpeg,image/png,image/jpg,image/gif,image/webp" style="display: none;" id="imageInput">
                    
                    <div class="upload-area" id="uploadArea" onclick="document.getElementById('imageInput').click()">
                        <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        <div class="upload-text">Click to upload new images</div>
                        <div class="upload-hint">JPG, PNG, GIF or WebP (Max 5MB each)</div>
                    </div>
                    
                    <div class="image-gallery" id="imagePreview"></div>
                </div>
                
                <!-- Amenities -->
                <div class="form-group">
                    <label>Amenities & Features</label>
                    <div class="amenities-grid">
                        <?php foreach ($amenities_list as $amenity): ?>
                        <label class="amenity-item">
                            <input type="checkbox" name="amenities[]" value="<?php echo sanitize($amenity); ?>" 
                                <?php echo in_array($amenity, $amenities) ? 'checked' : ''; ?>>
                            <span><?php echo sanitize($amenity); ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="help-text">Select all amenities that apply to your parking space</div>
                </div>
                
                <button type="submit" class="btn-submit">Update Parking Space</button>
            </form>
            
            <div style="text-align: center; margin-top: 20px;">
                <a href="parking-details.php?id=<?php echo $parking_id; ?>" class="btn-secondary">View Public Page</a>
            </div>
        </div>
    </div>
    
    <script>
        // Image preview for new uploads
        const imageInput = document.getElementById('imageInput');
        const imagePreview = document.getElementById('imagePreview');
        const maxImages = <?php echo 10 - count($existing_images); ?>;
        
        imageInput.addEventListener('change', function(e) {
            imagePreview.innerHTML = '';
            const files = Array.from(this.files);
            
            if (files.length > maxImages) {
                alert(`You can only upload ${maxImages} more images.`);
                this.value = '';
                return;
            }
            
            files.forEach((file, index) => {
                if (!file.type.match('image.*')) {
                    alert(`File "${file.name}" is not an image.`);
                    return;
                }
                
                if (file.size > 5 * 1024 * 1024) {
                    alert(`File "${file.name}" exceeds 5MB limit.`);
                    return;
                }
                
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const div = document.createElement('div');
                    div.className = 'image-item';
                    
                    const img = document.createElement('img');
                    img.src = e.target.result;
                    
                    const span = document.createElement('span');
                    span.className = 'delete-label';
                    span.textContent = 'New';
                    span.style.background = '#10B981';
                    
                    div.appendChild(img);
                    div.appendChild(span);
                    imagePreview.appendChild(div);
                }
                
                reader.readAsDataURL(file);
            });
        });
        
        // Set available spots value
        function setAvailableSpots(value) {
            const availableSpotsInput = document.getElementById('available_spots');
            const totalSpots = parseInt(document.querySelector('input[name="total_spots"]').value);
            
            if (value > totalSpots) {
                alert('Cannot set available spots greater than total spots.');
                return;
            }
            
            availableSpotsInput.value = value;
        }
        
        // Validate form before submission
        document.getElementById('parkingForm').addEventListener('submit', function(e) {
            const totalSpots = parseInt(document.querySelector('input[name="total_spots"]').value);
            const availableSpots = parseInt(document.querySelector('input[name="available_spots"]').value);
            
            if (availableSpots > totalSpots) {
                e.preventDefault();
                alert('Available spots cannot exceed total spots');
                return false;
            }
            
            // Check if at least one rate is set (optional warning)
            const hourlyRate = document.querySelector('input[name="hourly_rate"]').value;
            const dailyRate = document.querySelector('input[name="daily_rate"]').value;
            const monthlyRate = document.querySelector('input[name="monthly_rate"]').value;
            
            if (!hourlyRate && !dailyRate && !monthlyRate) {
                if (!confirm('Warning: No pricing options set. Users will not be able to book this space. Continue anyway?')) {
                    e.preventDefault();
                    return false;
                }
            }
        });
        
        // Drag and drop for upload area
        const uploadArea = document.getElementById('uploadArea');
        
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, preventDefaults, false);
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
            imageInput.files = files;
            const changeEvent = new Event('change');
            imageInput.dispatchEvent(changeEvent);
        }
    </script>
</body>
</html>