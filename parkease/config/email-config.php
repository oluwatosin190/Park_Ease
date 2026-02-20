<?php
// Email Configuration
use PHPMailer\PHPMailer\PHPMailer;
use PHPMailer\PHPMailer\SMTP;
use PHPMailer\PHPMailer\Exception;

// Load PHPMailer
require_once __DIR__ . '/../vendor/PHPMailer/PHPMailer.php';
require_once __DIR__ . '/../vendor/PHPMailer/SMTP.php';
require_once __DIR__ . '/../vendor/PHPMailer/Exception.php';

class EmailConfig {
    public static function getMailer() {
        $mail = new PHPMailer(true);
        
        // Server settings
        $mail->SMTPDebug = 0;                      
        $mail->isSMTP();                            // Send using SMTP
        $mail->Host       = 'smtp.gmail.com';       // Set the SMTP server to send through
        $mail->SMTPAuth   = true;                   // Enable SMTP authentication
        $mail->Username   = 'teeboss017@gmail.com'; // SMTP username (This is Our GMAIL That we crated for sending emails)
        $mail->Password   = 'xnas qwbd mtlz xwju';    // SMTP password (GMAIL APP PASSWORD)
        $mail->SMTPSecure = PHPMailer::ENCRYPTION_STARTTLS; // Enable TLS encryption
        $mail->Port       = 587;                    // TCP port to connect to
        
        // Default sender
        $mail->setFrom('noreply@parkease.com', 'ParkEase Parking');
        
        return $mail;
    }
}

?>