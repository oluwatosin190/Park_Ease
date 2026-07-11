<?php

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/commission-functions.php';

$database = new Database();
$db = $database->getConnection();
$commission = new CommissionManager($db);

// Log file
$log_file = __DIR__ . '/payouts_log.txt';
$timestamp = date('Y-m-d H:i:s');

// Start processing
$message = "[$timestamp] Starting daily payout processing...\n";
file_put_contents($log_file, $message, FILE_APPEND);

// Process payouts
$results = $commission->processDailyPayouts();

// Log results
$message = "[$timestamp] Processed " . count($results) . " payouts\n";
file_put_contents($log_file, $message, FILE_APPEND);

foreach ($results as $result) {
    $message = "[$timestamp] - Owner: {$result['owner_name']}, Amount: ₦{$result['amount']}, Reference: {$result['reference']}\n";
    file_put_contents($log_file, $message, FILE_APPEND);
}

$message = "[$timestamp] Payout processing completed\n\n";
file_put_contents($log_file, $message, FILE_APPEND);

// If running from browser
if (php_sapi_name() !== 'cli') {
    echo "<h2>Daily Payout Processing</h2>";
    echo "<p>Processed " . count($results) . " payouts</p>";
    echo "<pre>";
    print_r($results);
    echo "</pre>";
}
?>