<?php
session_start(); // Start session to destroy it

/**
 * Secure logout script for SpaceNode
 * Properly clears all session data and cookies
 */

// Function to clear all session data
function clearSession() {
    // Unset all session variables
    $_SESSION = array();
    
    // Destroy the session cookie
    if (ini_get("session.use_cookies")) {
        $params = session_get_cookie_params();
        setcookie(
            session_name(),
            '',
            time() - 42000,
            $params["path"],
            $params["domain"],
            $params["secure"],
            $params["httponly"]
        );
    }
    
    // Destroy the session
    session_destroy();
}

// Function to clear remember me cookies
function clearRememberMeCookies() {
    // List of cookies to clear
    $cookies = [
        'user_email',
        'user_token',      // New secure token cookie
        'user_password',   // Legacy - remove if exists
        'user_remember'
    ];
    
    foreach ($cookies as $cookie) {
        if (isset($_COOKIE[$cookie])) {
            setcookie($cookie, '', time() - 3600, '/', '', true, true);
        }
    }
}

// Log the logout action (for security audit)
function logLogout($db = null) {
    if (isset($_SESSION['user_id']) && !empty($_SESSION['user_id'])) {
        $user_id = $_SESSION['user_id'];
        $user_email = $_SESSION['user_email'] ?? 'unknown';
        $ip = $_SERVER['REMOTE_ADDR'] ?? '0.0.0.0';
        
        // Log to file
        error_log("User logout - ID: $user_id, Email: $user_email, IP: $ip, Time: " . date('Y-m-d H:i:s'));
        
        // If database connection is available, log to database
        if ($db && isset($db)) {
            try {
                // Check if user_logs table exists
                $check_table = $db->query("SHOW TABLES LIKE 'user_logs'");
                if ($check_table->rowCount() > 0) {
                    $log_query = "INSERT INTO user_logs (user_id, action, ip_address, created_at) 
                                  VALUES (:user_id, 'logout', :ip, NOW())";
                    $log_stmt = $db->prepare($log_query);
                    $log_stmt->bindParam(':user_id', $user_id, PDO::PARAM_INT);
                    $log_stmt->bindParam(':ip', $ip, PDO::PARAM_STR);
                    $log_stmt->execute();
                }
            } catch (Exception $e) {
                error_log("Failed to log logout to database: " . $e->getMessage());
            }
        }
        
        // Update last logout time in users table if available
        if ($db && isset($db)) {
            try {
                $update_query = "UPDATE users SET last_logout = NOW() WHERE id = :id";
                $update_stmt = $db->prepare($update_query);
                $update_stmt->bindParam(':id', $user_id, PDO::PARAM_INT);
                $update_stmt->execute();
            } catch (Exception $e) {
                // Silently fail - not critical
            }
        }
    }
}

// Get database connection for logging (optional)
$db = null;
try {
    require_once 'config/database.php';
    $database = new Database();
    $db = $database->getConnection();
} catch (Exception $e) {
    // Database connection failed, continue without logging
    error_log("Logout.php: Could not connect to database for logging: " . $e->getMessage());
}

// Log the logout
logLogout($db);

// Clear all session data
clearSession();

// Clear remember me cookies
clearRememberMeCookies();

// Regenerate session ID to prevent session fixation (even though we're destroying it)
// This is done before redirect for extra security
if (session_status() == PHP_SESSION_ACTIVE) {
    session_regenerate_id(true);
}

// Redirect to login page with success message (optional)
$redirect_url = 'login.php?logout=success';
header('Location: ' . $redirect_url);
exit();
?>