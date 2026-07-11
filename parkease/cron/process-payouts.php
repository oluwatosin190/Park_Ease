<?php
/**
 * CRON Job for Automatic Payouts
 * Run this daily at midnight
 * 
 * To set up cron job (cPanel):
 * 0 0 * * * /usr/bin/php /path/to/cron/process-payouts.php
 * 
 * For Windows Task Scheduler:
 * schtasks /create /tn "ParkEasePayouts" /tr "php C:\xampp\htdocs\Park_Ease\parkease\cron\process-payouts.php" /sc daily /st 00:00
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/paystack-api.php';
require_once __DIR__ . '/../includes/commission-functions.php';

$log_file = __DIR__ . '/payouts_log.txt';
$timestamp = date('Y-m-d H:i:s');

function writeLog($message) {
    global $log_file, $timestamp;
    file_put_contents($log_file, "[$timestamp] $message\n", FILE_APPEND);
    echo "[$timestamp] $message\n";
}

writeLog("=== Starting Automatic Payout Processing ===");

$database = new Database();
$db = $database->getConnection();

// Get payout schedule from settings
$settings_query = "SELECT setting_value FROM system_settings WHERE setting_key = 'payout_schedule'";
$settings_stmt = $db->prepare($settings_query);
$settings_stmt->execute();
$schedule = $settings_stmt->fetchColumn() ?: 'daily';

// Get minimum payout
$min_query = "SELECT setting_value FROM system_settings WHERE setting_key = 'min_payout'";
$min_stmt = $db->prepare($min_query);
$min_stmt->execute();
$min_payout = $min_stmt->fetchColumn() ?: 1000;

writeLog("Schedule: $schedule, Min Payout: ₦$min_payout");

// Determine if we should process based on schedule
$should_process = false;
$day_of_week = date('w'); // 0 (Sunday) to 6 (Saturday)
$day_of_month = date('j'); // 1 to 31

switch ($schedule) {
    case 'daily':
        $should_process = true;
        break;
    case 'weekly':
        $should_process = ($day_of_week == 1); // Monday
        break;
    case 'monthly':
        $should_process = ($day_of_month == date('t')); // Last day of month
        break;
}

if (!$should_process) {
    writeLog("Skipping payout - not scheduled for today");
    exit();
}

// Get all owners with pending balances
$owners_query = "SELECT ob.*, u.email, u.first_name, u.last_name, 
                 u.bank_name, u.account_number, u.account_name, u.bank_code, u.recipient_code
                 FROM owner_balances ob
                 JOIN users u ON ob.owner_id = u.id
                 WHERE ob.current_balance >= :min_payout";
$owners_stmt = $db->prepare($owners_query);
$owners_stmt->bindParam(':min_payout', $min_payout);
$owners_stmt->execute();
$owners = $owners_stmt->fetchAll(PDO::FETCH_ASSOC);

writeLog("Found " . count($owners) . " owners eligible for payout");

$paystack = new PaystackAPI();
$processed = 0;
$failed = 0;

foreach ($owners as $owner) {
    writeLog("Processing owner: {$owner['first_name']} {$owner['last_name']} - Balance: ₦{$owner['current_balance']}");
    
    // Check if owner has bank details
    if (empty($owner['bank_name']) || empty($owner['account_number']) || empty($owner['account_name'])) {
        writeLog("  ⚠️ Owner missing bank details - skipping");
        
        // Notify owner to add bank details
        $subject = "Action Required: Add Bank Details for Payout";
        $message = "Hello {$owner['first_name']},\n\n";
        $message .= "You have ₦" . number_format($owner['current_balance'], 2) . " available for payout, ";
        $message .= "but your bank details are missing. Please update your bank information in your dashboard.\n\n";
        $message .= "ParkEase Team";
        
        mail($owner['email'], $subject, $message);
        continue;
    }
    
    // Create or get recipient code
    $recipient_code = $owner['recipient_code'];
    
    if (empty($recipient_code)) {
        writeLog("  Creating recipient code for {$owner['account_number']}");
        
        $recipient = $paystack->createTransferRecipient(
            $owner['account_name'],
            $owner['account_number'],
            $owner['bank_code']
        );
        
        if ($recipient['status']) {
            $recipient_code = $recipient['recipient_code'];
            
            // Save recipient code
            $update = "UPDATE users SET recipient_code = :code WHERE id = :id";
            $update_stmt = $db->prepare($update);
            $update_stmt->bindParam(':code', $recipient_code);
            $update_stmt->bindParam(':id', $owner['owner_id']);
            $update_stmt->execute();
            
            writeLog("  ✅ Recipient code created: $recipient_code");
        } else {
            writeLog("  ❌ Failed to create recipient: " . ($recipient['message'] ?? 'Unknown error'));
            $failed++;
            continue;
        }
    }
    
    // Initiate transfer
    $amount = $owner['current_balance'];
    $reason = "ParkEase Payout - " . date('Y-m-d');
    
    $transfer = $paystack->initiateTransfer($amount, $recipient_code, $reason);
    
    if ($transfer['status']) {
        // Record transfer
        $insert = "INSERT INTO payout_transfers (owner_id, amount, transfer_code, recipient_code, status) 
                   VALUES (:owner_id, :amount, :code, :recipient, 'processing')";
        $insert_stmt = $db->prepare($insert);
        $insert_stmt->bindParam(':owner_id', $owner['owner_id']);
        $insert_stmt->bindParam(':amount', $amount);
        $insert_stmt->bindParam(':code', $transfer['transfer_code']);
        $insert_stmt->bindParam(':recipient', $recipient_code);
        $insert_stmt->execute();
        
        // Update owner balance
        $update = "UPDATE owner_balances SET current_balance = 0 WHERE owner_id = :owner_id";
        $update_stmt = $db->prepare($update);
        $update_stmt->bindParam(':owner_id', $owner['owner_id']);
        $update_stmt->execute();
        
        writeLog("  ✅ Transfer initiated: ₦$amount - Code: {$transfer['transfer_code']}");
        $processed++;
        
        // Send email notification
        $subject = "Payout Processed - ParkEase";
        $message = "Hello {$owner['first_name']},\n\n";
        $message .= "Your payout of ₦" . number_format($amount, 2) . " has been initiated.\n";
        $message .= "It should reflect in your bank account within 24 hours.\n\n";
        $message .= "Transfer Reference: {$transfer['transfer_code']}\n\n";
        $message .= "Thank you for using ParkEase!";
        
        mail($owner['email'], $subject, $message);
        
    } else {
        writeLog("  ❌ Transfer failed: " . ($transfer['message'] ?? 'Unknown error'));
        $failed++;
        
        // Log failed transfer
        $insert = "INSERT INTO payout_transfers (owner_id, amount, status, failure_reason) 
                   VALUES (:owner_id, :amount, 'failed', :reason)";
        $insert_stmt = $db->prepare($insert);
        $insert_stmt->bindParam(':owner_id', $owner['owner_id']);
        $insert_stmt->bindParam(':amount', $amount);
        $insert_stmt->bindParam(':reason', $transfer['message']);
        $insert_stmt->execute();
    }
    
    // Small delay to avoid rate limits
    usleep(500000); // 0.5 seconds
}

writeLog("=== Payout Processing Complete ===");
writeLog("Processed: $processed, Failed: $failed");
writeLog("----------------------------------------\n");

// Also log to database for admin viewing
$summary = "Processed $processed payouts, Failed: $failed";
$log_action = "INSERT INTO admin_logs (admin_id, action, details) VALUES (0, 'auto_payout', :details)";
$log_stmt = $db->prepare($log_action);
$log_stmt->bindParam(':details', $summary);
$log_stmt->execute();
?>
