<?php
// Email Configuration
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Auto-detect the correct path for PHPMailer
$paths = [
    __DIR__ . '/../vendor/PHPMailer/src/PHPMailer.php',
    __DIR__ . '/../vendor/phpmailer/phpmailer/src/PHPMailer.php',
    __DIR__ . '/../PHPMailer/src/PHPMailer.php',
    __DIR__ . '/../vendor/PHPMailer/PHPMailer.php',
];

$loaded = false;
foreach ($paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        $loaded = true;
        break;
    }
}

if (!$loaded) {
    error_log("PHPMailer not found. Searched paths: " . implode(', ', $paths));
    die('Email system configuration error. Please contact support.');
}

// Load SMTP and Exception classes
$smtp_paths = [
    __DIR__ . '/../vendor/PHPMailer/src/SMTP.php',
    __DIR__ . '/../vendor/phpmailer/phpmailer/src/SMTP.php',
    __DIR__ . '/../PHPMailer/src/SMTP.php',
    __DIR__ . '/../vendor/PHPMailer/SMTP.php',
];

foreach ($smtp_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

$exception_paths = [
    __DIR__ . '/../vendor/PHPMailer/src/Exception.php',
    __DIR__ . '/../vendor/phpmailer/phpmailer/src/Exception.php',
    __DIR__ . '/../PHPMailer/src/Exception.php',
    __DIR__ . '/../vendor/PHPMailer/Exception.php',
];

foreach ($exception_paths as $path) {
    if (file_exists($path)) {
        require_once $path;
        break;
    }
}

class EmailConfig {
    public static function getMailer() {
        try {
            $mail = new PHPMailer(true);
            
            // Enable verbose debug output (set to 0 in production, 2 for testing)
            $mail->SMTPDebug = 0;
            $mail->Debugoutput = 'html';
            
            // Server settings
            $mail->isSMTP();
            $mail->Host       = 'smtp.gmail.com';
            $mail->SMTPAuth   = true;
            $mail->Username   = 'teeboss017@gmail.com';
            $mail->Password   = 'xnas qwbd mtlz xwju';
            
            // Try SSL on port 465 (more reliable than TLS on 587)
            $mail->SMTPSecure = PHPMailer::ENCRYPTION_SMTPS; // SSL
            $mail->Port       = 465;
            
            // Alternative: Try TLS with different settings (uncomment if SSL doesn't work)
            // $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS;
            // $mail->Port       = 587;
            
            // Additional settings for better compatibility
            $mail->CharSet = 'UTF-8';
            $mail->Encoding = 'base64';
            $mail->Timeout = 30;
            
            // Disable SSL verification for localhost (remove in production)
            $mail->SMTPOptions = array(
                'ssl' => array(
                    'verify_peer' => false,
                    'verify_peer_name' => false,
                    'allow_self_signed' => true
                )
            );
            
            // Default sender
            $mail->setFrom('noreply@SpaceNode.com', 'SpaceNode Parking');
            
            return $mail;
            
        } catch (Exception $e) {
            error_log("PHPMailer initialization error: " . $e->getMessage());
            throw new Exception("Failed to initialize email system: " . $e->getMessage());
        }
    }
}

// Test function to verify email configuration
function testEmailConfig() {
    try {
        $mail = EmailConfig::getMailer();
        $mail->addAddress('test@example.com');
        $mail->Subject = 'Test Email from SpaceNode';
        $mail->Body = 'This is a test email to verify SMTP configuration.';
        $mail->send();
        return ['success' => true, 'message' => 'Email configuration is working'];
    } catch (Exception $e) {
        return ['success' => false, 'message' => $e->getMessage()];
    }
}
?>