<?php
session_start();
require_once '../config/database.php';
require_once '../includes/MailchimpService.php';
require_once 'includes/auth.php';

// Require admin login
requireAdminLogin();

if ($_SERVER['REQUEST_METHOD'] == 'POST' && isset($_POST['email'])) {
    $email = filter_var($_POST['email'], FILTER_SANITIZE_EMAIL);
    $return_url = $_POST['return_url'] ?? 'newsletter.php';
    
    if (filter_var($email, FILTER_VALIDATE_EMAIL)) {
        $mailchimp = new MailchimpService();
        $result = $mailchimp->unsubscribe($email);
        
        if ($result['success']) {
            $_SESSION['success_message'] = 'Successfully unsubscribed ' . $email;
        } else {
            $_SESSION['error_message'] = 'Failed to unsubscribe: ' . ($result['error'] ?? 'Unknown error');
        }
    }
}

header('Location: ' . $return_url);
exit();
?>