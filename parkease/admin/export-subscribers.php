<?php
session_start();
require_once '../config/database.php';
require_once '../includes/MailchimpService.php';

// Check if user is admin
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'admin') {
    header('Location: ../login.php');
    exit();
}

$mailchimp = new MailchimpService();
$all_subscribers = [];
$offset = 0;
$per_page = 100;

// Fetch all subscribers (Mailchimp paginates)
while (true) {
    $result = $mailchimp->getSubscribers($per_page, $offset);
    $members = $result['members'] ?? [];
    
    if (empty($members)) break;
    
    $all_subscribers = array_merge($all_subscribers, $members);
    $offset += $per_page;
}

// Set headers for CSV download
header('Content-Type: text/csv');
header('Content-Disposition: attachment; filename="newsletter-subscribers-' . date('Y-m-d') . '.csv"');

// Create output stream
$output = fopen('php://output', 'w');

// Add CSV headers
fputcsv($output, ['Email', 'First Name', 'Last Name', 'Status', 'Subscribed Date', 'Tags']);

// Add subscriber data
foreach ($all_subscribers as $subscriber) {
    if ($subscriber['status'] == 'subscribed') { // Only export active subscribers
        fputcsv($output, [
            $subscriber['email_address'],
            $subscriber['merge_fields']['FNAME'] ?? '',
            $subscriber['merge_fields']['LNAME'] ?? '',
            $subscriber['status'],
            date('Y-m-d H:i:s', strtotime($subscriber['timestamp_opt'])),
            implode(', ', array_column($subscriber['tags'] ?? [], 'name'))
        ]);
    }
}

fclose($output);
exit();
?>