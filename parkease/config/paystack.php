<?php
/**
 * Paystack Configuration
 * Live mode keys are set here
 */

define('PAYSTACK_PUBLIC_KEY', 'pk_test_9b05b4b5cf7c68f1191819d1db372cd39ef31b8f');
define('PAYSTACK_SECRET_KEY', 'sk_test_bea28af7ea5dbd9474ba294a05da9ab4d38515c6');
define('PAYSTACK_API_URL', 'https://api.paystack.co');

// Webhook URL - Update this to your actual domain
$protocol = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on' ? 'https://' : 'http://';
$host = $_SERVER['HTTP_HOST'];
$base_url = $protocol . $host . '/Park_Ease/parkease/';

define('PAYSTACK_WEBHOOK_URL', $base_url . 'paystack-webhook.php');
define('PAYSTACK_CALLBACK_URL', $base_url . 'payment-success.php');
?>