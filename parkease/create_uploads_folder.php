<?php
/**
 * Create Uploads Folder Script
 * This script creates the necessary upload directories for the application
 * 
 * IMPORTANT: Run this script only once, then delete it or move it to a secure location
 */

// Prevent direct access if this is a production environment
// Uncomment in production to prevent accidental execution
// if ($_SERVER['REMOTE_ADDR'] !== '127.0.0.1') {
//     die('This script can only be run locally.');
// }

// Enable error reporting for debugging (disable in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Function to log messages
function logMessage($message, $type = 'info') {
    $log_file = __DIR__ . '/logs/folder_creation.log';
    $log_dir = dirname($log_file);
    
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $log_entry = "[$timestamp] [$type] $message\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
    
    echo $message . "<br>\n";
}

// Define upload directories
$directories = [
    'uploads/',
    'uploads/parking/',
    'uploads/profile/',
    'uploads/temp/',
    'logs/'
];

$permissions = [
    'uploads/' => 0755,
    'uploads/parking/' => 0755,
    'uploads/profile/' => 0755,
    'uploads/temp/' => 0755,
    'logs/' => 0755
];

// Function to check if directory exists and is writable
function checkDirectory($path) {
    if (file_exists($path)) {
        if (is_dir($path)) {
            if (is_writable($path)) {
                return ['status' => 'ok', 'message' => "Directory exists and is writable: $path"];
            } else {
                return ['status' => 'warning', 'message' => "Directory exists but is NOT writable: $path"];
            }
        } else {
            return ['status' => 'error', 'message' => "Path exists but is NOT a directory: $path"];
        }
    }
    return ['status' => 'missing', 'message' => "Directory does not exist: $path"];
}

// Function to create directory with proper permissions
function createDirectory($path, $permission = 0755) {
    if (file_exists($path)) {
        return checkDirectory($path);
    }
    
    // Create directory recursively
    $created = mkdir($path, $permission, true);
    
    if ($created) {
        // Set proper ownership (optional, may require root)
        // chown($path, 'www-data');
        // chgrp($path, 'www-data');
        
        // Create index.html to prevent directory listing
        $index_file = $path . 'index.html';
        if (!file_exists($index_file)) {
            $index_content = '<!DOCTYPE html><html><head><title>Access Denied</title></head><body><h1>Access Denied</h1><p>You do not have permission to access this directory.</p></body></html>';
            file_put_contents($index_file, $index_content);
            chmod($index_file, 0644);
        }
        
        // Create .htaccess file to prevent PHP execution in uploads folder (Apache only)
        if (strpos($path, 'uploads') !== false) {
            $htaccess_file = $path . '.htaccess';
            if (!file_exists($htaccess_file)) {
                $htaccess_content = "# Prevent PHP execution in uploads folder\n";
                $htaccess_content .= "<FilesMatch \"\.(php|php5|phtml|py|pl|sh|html|htm|shtml)$\">\n";
                $htaccess_content .= "    Order Deny,Allow\n";
                $htaccess_content .= "    Deny from all\n";
                $htaccess_content .= "</FilesMatch>\n\n";
                $htaccess_content .= "# Disable directory browsing\n";
                $htaccess_content .= "Options -Indexes\n\n";
                $htaccess_content .= "# Protect sensitive files\n";
                $htaccess_content .= "<FilesMatch \"^\.(htaccess|htpasswd|ini|log|sh|sql)$\">\n";
                $htaccess_content .= "    Order Allow,Deny\n";
                $htaccess_content .= "    Deny from all\n";
                $htaccess_content .= "</FilesMatch>";
                file_put_contents($htaccess_file, $htaccess_content);
                chmod($htaccess_file, 0644);
            }
        }
        
        logMessage("Created directory: $path (permissions: " . decoct($permission) . ")", 'success');
        return ['status' => 'created', 'message' => "Successfully created directory: $path"];
    } else {
        logMessage("Failed to create directory: $path", 'error');
        return ['status' => 'error', 'message' => "Failed to create directory: $path"];
    }
}

// Function to set correct permissions for existing directories
function setPermissions($path, $permission) {
    if (file_exists($path) && is_dir($path)) {
        // Get current permissions
        $current_perms = fileperms($path) & 0777;
        
        if ($current_perms != $permission) {
            if (chmod($path, $permission)) {
                logMessage("Updated permissions for: $path from " . decoct($current_perms) . " to " . decoct($permission), 'info');
                return true;
            } else {
                logMessage("Failed to update permissions for: $path", 'warning');
                return false;
            }
        }
    }
    return true;
}

// Start output
echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Create Upload Folders - SpaceNode</title>
    <style>
        body {
            font-family: 'Inter', sans-serif;
            background: #F9FAFB;
            padding: 40px 20px;
            margin: 0;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 16px;
            padding: 30px;
            box-shadow: 0 4px 20px rgba(0,0,0,0.1);
        }
        h1 {
            font-size: 24px;
            color: #111827;
            margin-bottom: 20px;
            border-bottom: 2px solid #4F6EF7;
            padding-bottom: 10px;
        }
        .status {
            padding: 12px;
            margin: 8px 0;
            border-radius: 8px;
            font-size: 14px;
        }
        .status-success {
            background: #DCFCE7;
            color: #166534;
            border-left: 4px solid #22C55E;
        }
        .status-error {
            background: #FEE2E2;
            color: #991B1B;
            border-left: 4px solid #EF4444;
        }
        .status-warning {
            background: #FEF3C7;
            color: #92400E;
            border-left: 4px solid #F59E0B;
        }
        .status-info {
            background: #DBEAFE;
            color: #1E40AF;
            border-left: 4px solid #3B82F6;
        }
        .summary {
            margin-top: 20px;
            padding: 15px;
            background: #F3F4F6;
            border-radius: 8px;
        }
        .btn {
            display: inline-block;
            background: #4F6EF7;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 20px;
            font-weight: 500;
        }
        .btn:hover {
            background: #3a56d4;
        }
        .warning {
            background: #FEF3C7;
            padding: 15px;
            border-radius: 8px;
            margin-bottom: 20px;
            color: #92400E;
            font-size: 14px;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔧 SpaceNode - Upload Directory Setup</h1>
        
        <div class='warning'>
            <strong>⚠️ Security Notice:</strong> This script should be removed after execution to prevent unauthorized access.
            <br>Please delete this file after confirming all directories are created.
        </div>
";

// Create directories
logMessage("Starting directory setup...", 'info');

$results = [];
foreach ($directories as $dir) {
    $result = createDirectory($dir, $permissions[$dir] ?? 0755);
    $results[] = $result;
    
    $status_class = 'status-info';
    if ($result['status'] == 'success' || $result['status'] == 'created') {
        $status_class = 'status-success';
    } elseif ($result['status'] == 'warning') {
        $status_class = 'status-warning';
    } elseif ($result['status'] == 'error') {
        $status_class = 'status-error';
    }
    
    echo "<div class='status $status_class'>";
    echo htmlspecialchars($result['message']);
    echo "</div>";
}

// Check and fix permissions
echo "<h2 style='margin-top: 20px;'>🔐 Permission Check</h2>";

foreach ($directories as $dir) {
    setPermissions($dir, $permissions[$dir] ?? 0755);
    
    if (file_exists($dir) && is_dir($dir)) {
        $perms = fileperms($dir) & 0777;
        $is_writable = is_writable($dir);
        $writable_status = $is_writable ? '✅ Writable' : '❌ Not Writable';
        $status_class = $is_writable ? 'status-success' : 'status-error';
        
        echo "<div class='status $status_class'>";
        echo htmlspecialchars("$dir - Permissions: " . decoct($perms) . " - $writable_status");
        echo "</div>";
    }
}

// Summary
$success_count = count(array_filter($results, function($r) { 
    return in_array($r['status'], ['success', 'created', 'ok']); 
}));
$error_count = count(array_filter($results, function($r) { 
    return $r['status'] == 'error'; 
}));
$warning_count = count(array_filter($results, function($r) { 
    return $r['status'] == 'warning'; 
}));

echo "
        <div class='summary'>
            <strong>📊 Summary:</strong><br>
            ✅ Success: $success_count<br>
            ⚠️ Warnings: $warning_count<br>
            ❌ Errors: $error_count
        </div>
        
        <div style='margin-top: 20px;'>
            <a href='index.php' class='btn'>Go to Homepage</a>
            <a href='dashboard.php' class='btn' style='background: #6B7280; margin-left: 10px;'>Go to Dashboard</a>
        </div>
        
        <div class='warning' style='margin-top: 20px; background: #FEE2E2; color: #DC2626;'>
            <strong>⚠️ IMPORTANT:</strong> For security reasons, please delete this file (create_uploads_folder.php) after confirming all directories are created successfully.
        </div>
    </div>
</body>
</html>";

logMessage("Directory setup completed. Success: $success_count, Warnings: $warning_count, Errors: $error_count", 'info');
?>