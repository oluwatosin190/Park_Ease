<?php
require_once 'config/database.php';

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$error = '';
$success = '';

// Create upload directory if it doesn't exist
$upload_dir = 'uploads/parking/';
if (!file_exists($upload_dir)) {
    mkdir($upload_dir, 0777, true);
}

// Handle form submission
if ($_SERVER['REQUEST_METHOD'] == 'POST') {
    $name = trim($_POST['name']);
    $address = trim($_POST['address']);
    $city = trim($_POST['city']);
    $parking_type = $_POST['parking_type'];
    $total_spots = (int)$_POST['total_spots'];
    $hourly_rate = !empty($_POST['hourly_rate']) ? (float)$_POST['hourly_rate'] : null;
    $daily_rate = !empty($_POST['daily_rate']) ? (float)$_POST['daily_rate'] : null;
    $monthly_rate = !empty($_POST['monthly_rate']) ? (float)$_POST['monthly_rate'] : null;
    $description = trim($_POST['description']);
    $amenities = isset($_POST['amenities']) ? $_POST['amenities'] : [];
    
    // Validation
    if (empty($name) || empty($address) || empty($city) || empty($parking_type) || $total_spots < 1) {
        $error = 'Please fill in all required fields.';
    } else {
        // Handle image uploads
        $uploaded_images = [];
        
        if (isset($_FILES['images']) && !empty($_FILES['images']['name'][0])) {
            $allowed_types = ['image/jpeg', 'image/png', 'image/jpg', 'image/gif'];
            $max_size = 5 * 1024 * 1024; // 5MB
            
            foreach ($_FILES['images']['tmp_name'] as $key => $tmp_name) {
                if ($_FILES['images']['error'][$key] === 0) {
                    $file_name = $_FILES['images']['name'][$key];
                    $file_size = $_FILES['images']['size'][$key];
                    $file_type = $_FILES['images']['type'][$key];
                    
                    // Validate file type
                    if (!in_array($file_type, $allowed_types)) {
                        $error = 'Only JPG, PNG, and GIF images are allowed.';
                        break;
                    }
                    
                    // Validate file size
                    if ($file_size > $max_size) {
                        $error = 'Image size must be less than 5MB.';
                        break;
                    }
                    
                    // Generate unique filename
                    $file_ext = pathinfo($file_name, PATHINFO_EXTENSION);
                    $new_filename = uniqid() . '_' . time() . '.' . $file_ext;
                    $upload_path = $upload_dir . $new_filename;
                    
                    if (move_uploaded_file($tmp_name, $upload_path)) {
                        $uploaded_images[] = $new_filename;
                    }
                }
            }
        }
        
        if (empty($error)) {
            // Convert amenities array to JSON
            $amenities_json = json_encode($amenities);
            $images_json = json_encode($uploaded_images);
            
            // Insert into database
            $query = "INSERT INTO parking_spaces 
                      (owner_id, name, address, city, parking_type, total_spots, available_spots, 
                       hourly_rate, daily_rate, monthly_rate, description, amenities, images) 
                      VALUES 
                      (:owner_id, :name, :address, :city, :parking_type, :total_spots, :available_spots,
                       :hourly_rate, :daily_rate, :monthly_rate, :description, :amenities, :images)";
            
            $stmt = $db->prepare($query);
            $stmt->bindParam(':owner_id', $_SESSION['user_id']);
            $stmt->bindParam(':name', $name);
            $stmt->bindParam(':address', $address);
            $stmt->bindParam(':city', $city);
            $stmt->bindParam(':parking_type', $parking_type);
            $stmt->bindParam(':total_spots', $total_spots);
            $stmt->bindParam(':available_spots', $total_spots); // Initially all spots available
            $stmt->bindParam(':hourly_rate', $hourly_rate);
            $stmt->bindParam(':daily_rate', $daily_rate);
            $stmt->bindParam(':monthly_rate', $monthly_rate);
            $stmt->bindParam(':description', $description);
            $stmt->bindParam(':amenities', $amenities_json);
            $stmt->bindParam(':images', $images_json);
            
            if ($stmt->execute()) {
                $success = 'Parking space added successfully!';
                // Clear form data on success
                $_POST = [];
            } else {
                $error = 'Failed to add parking space. Please try again.';
            }
        }
    }
}

// Get existing amenities for suggestions
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
    <title>Add Parking Space - ParkEase</title>
    <link href="https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap" rel="stylesheet">
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #F9FAFB;
            padding: 40px 20px;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
            overflow: hidden;
        }
        .header {
            background: linear-gradient(135deg, #4F6EF7, #7C3AED);
            color: white;
            padding: 30px;
            position: relative;
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
            margin-bottom: 15px;
            color: white;
            text-decoration: none;
            opacity: 0.9;
            font-size: 14px;
        }
        .back-link:hover {
            opacity: 1;
        }
        .form-container {
            padding: 30px;
        }
        .form-group {
            margin-bottom: 20px;
        }
        .form-row {
            display: grid;
            grid-template-columns: 1fr 1fr 1fr;
            gap: 15px;
        }
        .form-row-2 {
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
        }
        .amenity-item input[type="checkbox"] {
            width: auto;
            accent-color: #4F6EF7;
        }
        .upload-area {
            border: 2px dashed #E5E7EB;
            border-radius: 10px;
            padding: 30px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
        }
        .upload-area:hover {
            border-color: #4F6EF7;
            background: #F9FAFB;
        }
        .upload-area.dragover {
            border-color: #4F6EF7;
            background: #EEF2FF;
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
        .image-preview {
            display: grid;
            grid-template-columns: repeat(4, 1fr);
            gap: 10px;
            margin-top: 15px;
        }
        .preview-item {
            position: relative;
            aspect-ratio: 1;
            border-radius: 8px;
            overflow: hidden;
            border: 2px solid transparent;
        }
        .preview-item.selected {
            border-color: #4F6EF7;
        }
        .preview-img {
            width: 100%;
            height: 100%;
            object-fit: cover;
        }
        .remove-image {
            position: absolute;
            top: 5px;
            right: 5px;
            width: 24px;
            height: 24px;
            background: rgba(0,0,0,0.5);
            color: white;
            border: none;
            border-radius: 50%;
            cursor: pointer;
            display: flex;
            align-items: center;
            justify-content: center;
            font-size: 16px;
        }
        .remove-image:hover {
            background: rgba(220,38,38,0.8);
        }
        .btn-submit {
            width: 100%;
            padding: 14px;
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
        .btn-submit:disabled {
            opacity: 0.5;
            cursor: not-allowed;
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
        .help-text {
            font-size: 12px;
            color: #6B7280;
            margin-top: 5px;
        }
    </style>
</head>
<body>
    <div class="container">
        <div class="header">
            <a href="dashboard.php" class="back-link">← Back to Dashboard</a>
            <h1>Add New Parking Space</h1>
            <p>List your parking space and start earning</p>
        </div>
        
        <div class="form-container">
            <?php if ($error): ?>
                <div class="alert alert-error"><?php echo $error; ?></div>
            <?php endif; ?>
            
            <?php if ($success): ?>
                <div class="alert alert-success"><?php echo $success; ?></div>
                <script>
                    setTimeout(function() {
                        window.location.href = 'dashboard.php';
                    }, 2000);
                </script>
            <?php endif; ?>
            
            <form method="POST" action="" enctype="multipart/form-data" id="parkingForm">
                <div class="form-group">
                    <label class="required">Parking Space Name</label>
                    <input type="text" name="name" required value="<?php echo isset($_POST['name']) ? htmlspecialchars($_POST['name']) : ''; ?>" placeholder="e.g., Downtown Plaza Parking">
                </div>
                
                <div class="form-group">
                    <label class="required">Full Address</label>
                    <input type="text" name="address" required value="<?php echo isset($_POST['address']) ? htmlspecialchars($_POST['address']) : ''; ?>" placeholder="Street address, building name">
                </div>
                
                <div class="form-row-2">
                    <div class="form-group">
                        <label class="required">City/Area</label>
                        <input type="text" name="city" required value="<?php echo isset($_POST['city']) ? htmlspecialchars($_POST['city']) : ''; ?>" placeholder="e.g., Downtown">
                    </div>
                    
                    <div class="form-group">
                        <label class="required">Parking Type</label>
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
                    <label class="required">Total Parking Spots</label>
                    <input type="number" name="total_spots" required min="1" value="<?php echo isset($_POST['total_spots']) ? (int)$_POST['total_spots'] : ''; ?>" placeholder="Number of parking spots">
                </div>
                
                <div class="form-row">
                    <div class="form-group">
                        <label>Hourly Rate (₦)</label>
                        <input type="number" name="hourly_rate" min="0" step="0.01" value="<?php echo isset($_POST['hourly_rate']) ? htmlspecialchars($_POST['hourly_rate']) : ''; ?>" placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label>Daily Rate (₦)</label>
                        <input type="number" name="daily_rate" min="0" step="0.01" value="<?php echo isset($_POST['daily_rate']) ? htmlspecialchars($_POST['daily_rate']) : ''; ?>" placeholder="0.00">
                    </div>
                    
                    <div class="form-group">
                        <label>Monthly Rate (₦)</label>
                        <input type="number" name="monthly_rate" min="0" step="0.01" value="<?php echo isset($_POST['monthly_rate']) ? htmlspecialchars($_POST['monthly_rate']) : ''; ?>" placeholder="0.00">
                    </div>
                </div>
                
                <div class="form-group">
                    <label>Description</label>
                    <textarea name="description" rows="4" placeholder="Describe your parking space (security features, location highlights, special instructions, etc.)"><?php echo isset($_POST['description']) ? htmlspecialchars($_POST['description']) : ''; ?></textarea>
                </div>
                
                <div class="form-group">
                    <label>Amenities & Features</label>
                    <div class="amenities-grid">
                        <?php foreach ($amenities_list as $amenity): ?>
                        <label class="amenity-item">
                            <input type="checkbox" name="amenities[]" value="<?php echo $amenity; ?>" 
                                <?php echo (isset($_POST['amenities']) && in_array($amenity, $_POST['amenities'])) ? 'checked' : ''; ?>>
                            <span><?php echo $amenity; ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                    <div class="help-text">Select all amenities that apply to your parking space</div>
                </div>
                
                <div class="form-group">
                    <label>Upload Images</label>
                    <input type="file" name="images[]" multiple accept="image/jpeg,image/png,image/jpg,image/gif" style="display: none;" id="imageInput">
                    
                    <div class="upload-area" id="uploadArea" onclick="document.getElementById('imageInput').click()">
                        <svg class="upload-icon" viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2">
                            <path d="M21 15v4a2 2 0 0 1-2 2H5a2 2 0 0 1-2-2v-4"/>
                            <polyline points="17 8 12 3 7 8"/>
                            <line x1="12" y1="3" x2="12" y2="15"/>
                        </svg>
                        <div class="upload-text">Click or drag images to upload</div>
                        <div class="upload-hint">JPG, PNG or GIF (Max 5MB each)</div>
                    </div>
                    
                    <div class="image-preview" id="imagePreview"></div>
                    <div class="help-text">First image will be the main photo. Click on an image to set as main.</div>
                </div>
                
                <button type="submit" class="btn-submit" id="submitBtn">Add Parking Space</button>
            </form>
        </div>
    </div>
    
    <script>
        // Drag and drop functionality
        const uploadArea = document.getElementById('uploadArea');
        const imageInput = document.getElementById('imageInput');
        const imagePreview = document.getElementById('imagePreview');
        let selectedFiles = [];
        
        // Prevent default drag behaviors
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadArea.addEventListener(eventName, preventDefaults, false);
            document.body.addEventListener(eventName, preventDefaults, false);
        });
        
        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }
        
        // Highlight drop area
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
        
        // Handle dropped files
        uploadArea.addEventListener('drop', handleDrop, false);
        
        function handleDrop(e) {
            const dt = e.dataTransfer;
            const files = dt.files;
            handleFiles(files);
        }
        
        // Handle selected files
        imageInput.addEventListener('change', function() {
            handleFiles(this.files);
        });
        
        function handleFiles(files) {
            selectedFiles = [...files];
            previewImages();
        }
        
        function previewImages() {
            imagePreview.innerHTML = '';
            
            for (let i = 0; i < selectedFiles.length; i++) {
                const file = selectedFiles[i];
                const reader = new FileReader();
                
                reader.onload = function(e) {
                    const previewItem = document.createElement('div');
                    previewItem.className = 'preview-item' + (i === 0 ? ' selected' : '');
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
        }
        
        function removeImage(index) {
            selectedFiles.splice(index, 1);
            
            // Create new FileList
            const dt = new DataTransfer();
            selectedFiles.forEach(file => dt.items.add(file));
            imageInput.files = dt.files;
            
            previewImages();
        }
        
        function selectMainImage(index) {
            document.querySelectorAll('.preview-item').forEach(item => {
                item.classList.remove('selected');
            });
            document.querySelector(`[data-index="${indexz}"]`).classList.add('selected');
        }
        
        // Form validation
        document.getElementById('parkingForm').addEventListener('submit', function(e) {
            const totalSpots = document.querySelector('input[name="total_spots"]').value;
            if (totalSpots < 1) {
                e.preventDefault();
                alert('Total spots must be at least 1');
            }
        });
    </script>
</body>
</html>