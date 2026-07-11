<?php
$message = '';
$error = '';

// Create team uploads directory if it doesn't exist
$upload_dir = 'uploads/team/';
if (!is_dir($upload_dir)) {
    mkdir($upload_dir, 0755, true);
}

// Handle image upload
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_FILES['team_image'])) {
    $file = $_FILES['team_image'];
    $file_name = basename($file['name']);
    $file_tmp = $file['tmp_name'];
    $file_size = $file['size'];
    $file_error = $file['error'];
    
    // Check for errors
    if ($file_error !== UPLOAD_ERR_OK) {
        $error = 'Error: File upload failed. Code: ' . $file_error;
    } elseif ($file_size > 5000000) {
        $error = 'Error: File is too large (max 5MB).';
    } else {
        // Get file info
        $file_type = mime_content_type($file_tmp);
        $allowed_types = ['image/jpeg', 'image/png', 'image/gif', 'image/webp'];
        
        if (!in_array($file_type, $allowed_types)) {
            $error = 'Error: Only JPG, PNG, GIF, and WEBP images are allowed. You uploaded: ' . $file_type;
        } else {
            // Generate unique filename
            $file_ext = strtolower(pathinfo($file_name, PATHINFO_EXTENSION));
            $new_filename = 'tristack-team-' . time() . '.' . $file_ext;
            $upload_path = $upload_dir . $new_filename;
            
            // Move uploaded file
            if (move_uploaded_file($file_tmp, $upload_path)) {
                // Update config file
                $config_file = 'config/team-image.php';
                $config_content = "<?php\n// Team image path - Auto-updated on upload\n\$team_image_path = 'uploads/team/" . $new_filename . "';\n?>";
                
                if (file_put_contents($config_file, $config_content)) {
                    $message = '✅ Success! Image uploaded successfully. Refresh the About page to see the changes.';
                } else {
                    $error = 'Error: File uploaded but failed to update configuration.';
                }
            } else {
                $error = 'Error: Failed to move the uploaded file.';
            }
        }
    }
}

// Get current image
$current_image = '';
if (file_exists('config/team-image.php')) {
    include 'config/team-image.php';
    $current_image = $team_image_path ?? '';
}
?>

<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Upload Team Image - SpaceNode</title>
    <style>
        * {
            margin: 0;
            padding: 0;
            box-sizing: border-box;
        }

        body {
            font-family: 'Segoe UI', Tahoma, Geneva, Verdana, sans-serif;
            background: linear-gradient(135deg, #667eea 0%, #764ba2 100%);
            min-height: 100vh;
            display: flex;
            align-items: center;
            justify-content: center;
            padding: 20px;
        }

        .container {
            background: white;
            border-radius: 16px;
            box-shadow: 0 20px 60px rgba(0, 0, 0, 0.3);
            max-width: 600px;
            width: 100%;
            padding: 40px;
        }

        h1 {
            font-size: 28px;
            color: #111827;
            margin-bottom: 8px;
            text-align: center;
        }

        .subtitle {
            color: #6B7280;
            text-align: center;
            margin-bottom: 32px;
            font-size: 14px;
        }

        .message {
            padding: 16px;
            border-radius: 8px;
            margin-bottom: 24px;
            font-size: 14px;
            text-align: center;
        }

        .message.success {
            background: #DCFCE7;
            color: #15803d;
            border: 1px solid #86EFAC;
        }

        .message.error {
            background: #FEE2E2;
            color: #b91c1c;
            border: 1px solid #FCA5A5;
        }

        .upload-box {
            border: 3px dashed #4F6EF7;
            border-radius: 12px;
            padding: 50px 20px;
            text-align: center;
            cursor: pointer;
            transition: all 0.3s;
            background: #F3F4F6;
            margin-bottom: 24px;
        }

        .upload-box:hover {
            background: rgba(79, 110, 247, 0.1);
            border-color: #3a56d4;
        }

        .upload-box.dragover {
            background: rgba(79, 110, 247, 0.2);
            border-color: #3a56d4;
        }

        .upload-box input[type="file"] {
            display: none;
        }

        .upload-icon {
            font-size: 48px;
            margin-bottom: 12px;
        }

        .upload-text {
            color: #111827;
            font-size: 16px;
            font-weight: 600;
            margin-bottom: 4px;
        }

        .upload-hint {
            color: #6B7280;
            font-size: 13px;
        }

        .preview {
            margin-bottom: 24px;
            display: none;
        }

        .preview.show {
            display: block;
        }

        .preview-label {
            font-size: 12px;
            font-weight: 600;
            color: #6B7280;
            text-transform: uppercase;
            margin-bottom: 8px;
        }

        .preview-image {
            width: 100%;
            max-height: 250px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #E5E7EB;
        }

        .button-group {
            display: flex;
            gap: 12px;
            margin-top: 24px;
        }

        .btn {
            flex: 1;
            padding: 14px 24px;
            border: none;
            border-radius: 8px;
            font-size: 15px;
            font-weight: 600;
            cursor: pointer;
            transition: all 0.2s;
        }

        .btn-primary {
            background: #4F6EF7;
            color: white;
        }

        .btn-primary:hover {
            background: #3a56d4;
        }

        .btn-primary:disabled {
            background: #BFDBFE;
            cursor: not-allowed;
        }

        .btn-secondary {
            background: #F3F4F6;
            color: #111827;
            border: 1px solid #E5E7EB;
        }

        .btn-secondary:hover {
            background: #E5E7EB;
        }

        .current-section {
            margin-top: 32px;
            padding-top: 32px;
            border-top: 1px solid #E5E7EB;
        }

        .current-section h2 {
            font-size: 16px;
            color: #111827;
            margin-bottom: 16px;
        }

        .current-image {
            width: 100%;
            max-height: 200px;
            object-fit: cover;
            border-radius: 8px;
            border: 1px solid #E5E7EB;
        }

        .no-image {
            background: #F3F4F6;
            padding: 24px;
            border-radius: 8px;
            text-align: center;
            color: #6B7280;
            font-size: 14px;
        }

        .info {
            background: #EFF6FF;
            border: 1px solid #BFDBFE;
            color: #1e40af;
            padding: 16px;
            border-radius: 8px;
            margin-top: 24px;
            font-size: 13px;
            line-height: 1.6;
        }

        .info strong {
            display: block;
            margin-bottom: 8px;
        }
    </style>
</head>
<body>
    <div class="container">
        <h1>🎨 Upload Team Image</h1>
        <p class="subtitle">Upload a photo for the Leadership Team section on the About page</p>

        <?php if ($message): ?>
            <div class="message success"><?php echo htmlspecialchars($message); ?></div>
        <?php endif; ?>

        <?php if ($error): ?>
            <div class="message error"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>

        <form method="POST" enctype="multipart/form-data" id="uploadForm">
            <div class="upload-box" id="uploadBox">
                <div class="upload-icon">📸</div>
                <div class="upload-text">Click to select image or drag & drop</div>
                <div class="upload-hint">JPG, PNG, GIF, or WEBP (Max 5MB)</div>
                <input type="file" id="fileInput" name="team_image" accept="image/*" required>
            </div>

            <div class="preview" id="preview">
                <div class="preview-label">✓ Image Selected</div>
                <img id="previewImage" class="preview-image" alt="Preview">
            </div>

            <div class="button-group">
                <button type="submit" class="btn btn-primary">📤 Upload Image</button>
                <button type="reset" class="btn btn-secondary">Clear</button>
            </div>

            <div class="info">
                <strong>💡 Tips:</strong>
                • Recommended size: 500x300px or larger<br>
                • The image will display at 200px height on the about page<br>
                • Each upload replaces the previous image
            </div>
        </form>

        <?php if ($current_image && file_exists($current_image)): ?>
            <div class="current-section">
                <h2>📷 Current Team Image</h2>
                <img src="<?php echo htmlspecialchars($current_image); ?>" class="current-image" alt="Current Team Image">
                <p style="color: #6B7280; font-size: 12px; margin-top: 8px;">
                    File: <code><?php echo htmlspecialchars($current_image); ?></code>
                </p>
            </div>
        <?php else: ?>
            <div class="current-section">
                <h2>📷 Current Team Image</h2>
                <div class="no-image">No image uploaded yet. Upload one to get started!</div>
            </div>
        <?php endif; ?>
    </div>

    <script>
        const uploadBox = document.getElementById('uploadBox');
        const fileInput = document.getElementById('fileInput');
        const preview = document.getElementById('preview');
        const previewImage = document.getElementById('previewImage');
        const uploadForm = document.getElementById('uploadForm');

        // Click to upload
        uploadBox.addEventListener('click', () => fileInput.click());

        // File selected
        fileInput.addEventListener('change', (e) => {
            if (e.target.files && e.target.files[0]) {
                const reader = new FileReader();
                reader.onload = (event) => {
                    previewImage.src = event.target.result;
                    preview.classList.add('show');
                };
                reader.readAsDataURL(e.target.files[0]);
            }
        });

        // Drag and drop
        ['dragenter', 'dragover', 'dragleave', 'drop'].forEach(eventName => {
            uploadBox.addEventListener(eventName, preventDefaults, false);
        });

        function preventDefaults(e) {
            e.preventDefault();
            e.stopPropagation();
        }

        ['dragenter', 'dragover'].forEach(eventName => {
            uploadBox.addEventListener(eventName, () => {
                uploadBox.classList.add('dragover');
            });
        });

        ['dragleave', 'drop'].forEach(eventName => {
            uploadBox.addEventListener(eventName, () => {
                uploadBox.classList.remove('dragover');
            });
        });

        uploadBox.addEventListener('drop', (e) => {
            const dt = e.dataTransfer;
            const files = dt.files;
            fileInput.files = files;
            
            // Trigger change event
            const event = new Event('change', { bubbles: true });
            fileInput.dispatchEvent(event);
        });

        // Reset preview on form reset
        uploadForm.addEventListener('reset', () => {
            preview.classList.remove('show');
        });
    </script>
</body>
</html>
