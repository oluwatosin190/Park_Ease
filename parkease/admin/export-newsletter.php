<?php
session_start();
require_once '../config/database.php';
require_once 'includes/auth.php';

// Require admin login
requireAdminLogin();

$database = new Database();
$db = $database->getConnection();

// Get all active subscribers
$query = "SELECT email, first_name, last_name, subscribed_at FROM newsletter_subscribers 
          WHERE status = 'active' ORDER BY subscribed_at DESC";
$stmt = $db->prepare($query);
$stmt->execute();
$subscribers = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Set headers for CSV download
header('Content-Type: text/csv; charset=utf-8');
header('Content-Disposition: attachment; filename="spacenode-newsletter-' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add CSV headers (UTF-8 BOM for Excel compatibility)
fprintf($output, chr(0xEF).chr(0xBB).chr(0xBF));
fputcsv($output, ['Email', 'First Name', 'Last Name', 'Subscribed Date']);

// Add subscriber data
foreach ($subscribers as $sub) {
    fputcsv($output, [
        $sub['email'],
        $sub['first_name'] ?? '',
        $sub['last_name'] ?? '',
        date('Y-m-d', strtotime($sub['subscribed_at']))
    ]);
}

fclose($output);
exit();
?>