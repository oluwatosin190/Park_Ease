<?php
session_start(); // Start session for logging
require_once 'config/database.php';

// ===== SECURITY CHECK =====
// Only allow access from localhost or specific IPs for security
$allowed_ips = ['127.0.0.1', '::1', 'localhost'];
$user_ip = $_SERVER['REMOTE_ADDR'];

// Uncomment for production - restrict access
// if (!in_array($user_ip, $allowed_ips)) {
//     die('Access denied. This script can only be run from localhost.');
// }

// Check if user is already logged in as admin (optional security)
$is_admin_logged_in = isset($_SESSION['admin_id']) && !empty($_SESSION['admin_id']);

// If not on localhost and not logged in, require authentication
if (!in_array($user_ip, $allowed_ips) && !$is_admin_logged_in) {
    die('Access denied. Please log in as admin first.');
}

// Function to log actions
function logAdminReset($message, $type = 'info') {
    $log_file = __DIR__ . '/logs/admin_reset.log';
    $log_dir = dirname($log_file);
    
    if (!file_exists($log_dir)) {
        mkdir($log_dir, 0755, true);
    }
    
    $timestamp = date('Y-m-d H:i:s');
    $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
    $log_entry = "[$timestamp] [$type] $message (IP: $ip)\n";
    file_put_contents($log_file, $log_entry, FILE_APPEND);
}

echo "<!DOCTYPE html>
<html lang='en'>
<head>
    <meta charset='UTF-8'>
    <meta name='viewport' content='width=device-width, initial-scale=1.0'>
    <title>Admin Password Reset - SpaceNode</title>
    <link href='https://fonts.googleapis.com/css2?family=Inter:wght@400;500;600;700;800&display=swap' rel='stylesheet'>
    <style>
        * { margin: 0; padding: 0; box-sizing: border-box; }
        body {
            font-family: 'Inter', sans-serif;
            background: #F9FAFB;
            padding: 40px 20px;
            line-height: 1.6;
        }
        .container {
            max-width: 800px;
            margin: 0 auto;
            background: white;
            border-radius: 20px;
            padding: 30px;
            box-shadow: 0 10px 40px rgba(0,0,0,0.1);
        }
        h1 {
            font-size: 24px;
            color: #111827;
            margin-bottom: 20px;
            border-bottom: 2px solid #4F6EF7;
            padding-bottom: 10px;
        }
        h2 {
            font-size: 18px;
            margin: 20px 0 15px;
            color: #111827;
        }
        .success {
            background: #DCFCE7;
            color: #16A34A;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 4px solid #16A34A;
        }
        .error {
            background: #FEE2E2;
            color: #DC2626;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 4px solid #DC2626;
        }
        .warning {
            background: #FEF3C7;
            color: #D97706;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 4px solid #F59E0B;
        }
        .info {
            background: #DBEAFE;
            color: #1E40AF;
            padding: 15px;
            border-radius: 8px;
            margin: 15px 0;
            border-left: 4px solid #3B82F6;
        }
        table {
            width: 100%;
            border-collapse: collapse;
            margin: 15px 0;
        }
        th, td {
            padding: 12px;
            text-align: left;
            border-bottom: 1px solid #E5E7EB;
        }
        th {
            background: #F9FAFB;
            color: #6B7280;
            font-weight: 600;
            font-size: 13px;
        }
        td {
            color: #111827;
        }
        .btn {
            display: inline-block;
            background: #4F6EF7;
            color: white;
            padding: 10px 20px;
            text-decoration: none;
            border-radius: 8px;
            margin-top: 20px;
            transition: background 0.2s;
        }
        .btn:hover {
            background: #3a56d4;
        }
        .warning-box {
            background: #FEF3C7;
            border: 1px solid #FCD34D;
            padding: 15px;
            border-radius: 8px;
            margin: 20px 0;
            font-size: 13px;
            color: #92400E;
        }
        .warning-box strong {
            display: block;
            margin-bottom: 10px;
        }
        code {
            background: #F3F4F6;
            padding: 2px 6px;
            border-radius: 4px;
            font-family: monospace;
            font-size: 13px;
        }
        hr {
            margin: 20px 0;
            border: none;
            border-top: 1px solid #E5E7EB;
        }
    </style>
</head>
<body>
    <div class='container'>
        <h1>🔐 Admin Password Reset Tool</h1>
        
        <div class='warning-box'>
            <strong>⚠️ SECURITY NOTICE</strong>
            <p>This script is for emergency password recovery only. It should be deleted after use or moved to a secure location.</p>
            <p>Current IP: <code><?php echo htmlspecialchars($user_ip); ?></code></p>
            <p>Time: <?php echo date('Y-m-d H:i:s'); ?></p>
        </div>
";

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    echo "<div class='error'>❌ Database connection failed. Please check your database configuration.</div>";
    echo "</div></body></html>";
    exit();
}

try {
    logAdminReset("Admin reset script accessed");

    // Check if admins table exists
    $table_check = $db->query("SHOW TABLES LIKE 'admins'");
    if ($table_check->rowCount() == 0) {
        echo "<div class='warning'>⚠️ Admins table doesn't exist! Creating now...</div>";
        
        // Create admins table with proper structure
        $create_table = "CREATE TABLE IF NOT EXISTS admins (
            id INT PRIMARY KEY AUTO_INCREMENT,
            username VARCHAR(50) UNIQUE NOT NULL,
            email VARCHAR(100) UNIQUE NOT NULL,
            password VARCHAR(255) NOT NULL,
            full_name VARCHAR(100),
            role ENUM('super_admin', 'admin', 'support') DEFAULT 'admin',
            last_login TIMESTAMP NULL,
            last_ip VARCHAR(45),
            is_active BOOLEAN DEFAULT TRUE,
            created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
            updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
        )";
        
        $db->exec($create_table);
        echo "<div class='success'>✅ Admins table created successfully!</div>";
        logAdminReset("Admins table created");
    }

    // Check if any admin exists
    $check = $db->query("SELECT COUNT(*) as count FROM admins WHERE is_active = 1");
    $result = $check->fetch(PDO::FETCH_ASSOC);
    
    $new_password = 'Admin@123';
    $hashed_password = password_hash($new_password, PASSWORD_DEFAULT);
    
    if ($result['count'] == 0) {
        // No active admin exists, create one
        $username = 'superadmin';
        $email = 'admin@spacenode.com';
        $full_name = 'Super Admin';
        $role = 'super_admin';
        
        $insert = "INSERT INTO admins (username, email, password, full_name, role, is_active, created_at) 
                   VALUES (:username, :email, :pass, :full_name, :role, 1, NOW())";
        $stmt = $db->prepare($insert);
        $stmt->bindParam(':username', $username, PDO::PARAM_STR);
        $stmt->bindParam(':email', $email, PDO::PARAM_STR);
        $stmt->bindParam(':pass', $hashed_password, PDO::PARAM_STR);
        $stmt->bindParam(':full_name', $full_name, PDO::PARAM_STR);
        $stmt->bindParam(':role', $role, PDO::PARAM_STR);
        $stmt->execute();
        
        echo "<div class='success'>✅ Super admin created successfully!</div>";
        echo "<div class='info'>📝 Default credentials:<br><strong>Username:</strong> $username<br><strong>Password:</strong> $new_password</div>";
        logAdminReset("New super admin created: $username");
    } else {
        // Update existing admin password
        $update = "UPDATE admins SET password = :pass, updated_at = NOW() WHERE role = 'super_admin' AND is_active = 1";
        $stmt = $db->prepare($update);
        $stmt->bindParam(':pass', $hashed_password, PDO::PARAM_STR);
        $stmt->execute();
        
        if ($stmt->rowCount() > 0) {
            echo "<div class='success'>✅ Super admin password reset successfully!</div>";
            echo "<div class='info'>🔑 New password: <code>$new_password</code></div>";
            logAdminReset("Super admin password reset");
        } else {
            // Try updating any admin
            $update_any = "UPDATE admins SET password = :pass WHERE is_active = 1 LIMIT 1";
            $stmt_any = $db->prepare($update_any);
            $stmt_any->bindParam(':pass', $hashed_password, PDO::PARAM_STR);
            $stmt_any->execute();
            
            if ($stmt_any->rowCount() > 0) {
                echo "<div class='success'>✅ Admin password reset successfully!</div>";
                echo "<div class='info'>🔑 New password: <code>$new_password</code></div>";
                logAdminReset("Admin password reset (non-super admin)");
            } else {
                echo "<div class='warning'>⚠️ No active admin found. Creating a new super admin...</div>";
                
                $insert = "INSERT INTO admins (username, email, password, full_name, role, is_active, created_at) 
                           VALUES ('superadmin', 'admin@spacenode.com', :pass, 'Super Admin', 'super_admin', 1, NOW())";
                $stmt = $db->prepare($insert);
                $stmt->bindParam(':pass', $hashed_password, PDO::PARAM_STR);
                $stmt->execute();
                
                echo "<div class='success'>✅ New super admin created!</div>";
                echo "<div class='info'>📝 Default credentials:<br><strong>Username:</strong> superadmin<br><strong>Password:</strong> $new_password</div>";
                logAdminReset("New super admin created (no existing admins found)");
            }
        }
    }

    // Show all admins (excluding password)
    echo "<h2>👥 Current Admins</h2>";
    $admins = $db->query("SELECT id, username, email, full_name, role, is_active, last_login FROM admins ORDER BY role DESC, id ASC");
    
    echo "<table>";
    echo "<thead><tr><th>ID</th><th>Username</th><th>Email</th><th>Name</th><th>Role</th><th>Status</th><th>Last Login</th></tr></thead>";
    echo "<tbody>";
    
    while ($admin = $admins->fetch(PDO::FETCH_ASSOC)) {
        echo "<tr>";
        echo "<td>" . (int)$admin['id'] . "</td>";
        echo "<td><code>" . htmlspecialchars($admin['username']) . "</code></td>";
        echo "<td>" . htmlspecialchars($admin['email']) . "</td>";
        echo "<td>" . htmlspecialchars($admin['full_name'] ?? '-') . "</td>";
        echo "<td><span class='badge' style='background:#EEF2FF; padding:2px 8px; border-radius:12px; font-size:12px;'>" . htmlspecialchars($admin['role']) . "</span></td>";
        echo "<td>" . ($admin['is_active'] ? '🟢 Active' : '🔴 Inactive') . "</td>";
        echo "<td>" . ($admin['last_login'] ? date('M d, Y', strtotime($admin['last_login'])) : 'Never') . "</td>";
        echo "</tr>";
    }
    echo "</tbody></table>";
    
    echo "<hr>";
    
    echo "<h2>📋 Instructions</h2>";
    echo "<ol style='margin-left: 20px; color: #4B5563;'>";
    echo "<li>Use the credentials above to log in to the admin panel</li>";
    echo "<li>After logging in, change your password immediately</li>";
    echo "<li>For security, delete this file after use: <code>reset-admin.php</code></li>";
    echo "<li>If you need to reset another admin, run this script again</li>";
    echo "</ol>";
    
    echo "<a href='admin/login.php' class='btn'>🔑 Go to Admin Login</a>";
    
} catch (PDOException $e) {
    $error_msg = $e->getMessage();
    echo "<div class='error'>❌ Database error: " . htmlspecialchars($error_msg) . "</div>";
    logAdminReset("Database error: " . $error_msg, 'error');
} catch (Exception $e) {
    $error_msg = $e->getMessage();
    echo "<div class='error'>❌ Error: " . htmlspecialchars($error_msg) . "</div>";
    logAdminReset("General error: " . $error_msg, 'error');
}

echo "
        <div class='warning-box' style='margin-top: 20px;'>
            <strong>⚠️ IMPORTANT SECURITY NOTICE</strong>
            <p>This file contains sensitive information. Please delete it immediately after use:</p>
            <code>rm reset-admin.php</code> (Linux/Mac) or delete the file manually (Windows)
        </div>
    </div>
</body>
</html>";
?>