<?php
/**
 * Setup Paystack Transfer Recipients
 * Run this once to set up bank codes and test recipients
 */

require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/paystack-api.php';

$database = new Database();
$db = $database->getConnection();

$paystack = new PaystackAPI();

echo "=== Paystack Transfer Recipient Setup ===\n\n";

// Get list of banks
echo "Fetching bank list from Paystack...\n";
$banks = $paystack->getBanks();

if (empty($banks)) {
    echo "❌ Failed to fetch banks. Check your Paystack API keys.\n";
    exit(1);
}

echo "✅ Retrieved " . count($banks) . " banks\n\n";

// Save banks to a cache file for reference
$bank_cache = [];
foreach ($banks as $bank) {
    $bank_cache[$bank->code] = $bank->name;
}

file_put_contents(__DIR__ . '/bank_codes.json', json_encode($bank_cache, JSON_PRETTY_PRINT));
echo "✅ Bank codes saved to bank_codes.json\n\n";

// Get all owners without recipient codes
$query = "SELECT u.id, u.first_name, u.last_name, u.bank_name, u.account_number, u.account_name, u.bank_code
          FROM users u
          WHERE u.user_type = 'owner' 
          AND u.bank_name IS NOT NULL 
          AND u.account_number IS NOT NULL
          AND (u.recipient_code IS NULL OR u.recipient_code = '')";
$stmt = $db->prepare($query);
$stmt->execute();
$owners = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo "Found " . count($owners) . " owners needing recipient codes\n\n";

foreach ($owners as $owner) {
    echo "Processing: {$owner['first_name']} {$owner['last_name']}\n";
    echo "  Bank: {$owner['bank_name']}\n";
    echo "  Account: {$owner['account_number']} - {$owner['account_name']}\n";
    
    // Find bank code
    $bank_code = null;
    foreach ($banks as $bank) {
        if (stripos($bank->name, $owner['bank_name']) !== false) {
            $bank_code = $bank->code;
            break;
        }
    }
    
    if (!$bank_code) {
        echo "  ❌ Could not find bank code for {$owner['bank_name']}\n\n";
        continue;
    }
    
    echo "  ✅ Found bank code: $bank_code\n";
    
    // Create recipient
    $recipient = $paystack->createTransferRecipient(
        $owner['account_name'],
        $owner['account_number'],
        $bank_code
    );
    
    if ($recipient['status']) {
        // Save recipient code
        $update = "UPDATE users SET recipient_code = :code, bank_code = :bank_code WHERE id = :id";
        $update_stmt = $db->prepare($update);
        $update_stmt->bindParam(':code', $recipient['recipient_code']);
        $update_stmt->bindParam(':bank_code', $bank_code);
        $update_stmt->bindParam(':id', $owner['id']);
        $update_stmt->execute();
        
        echo "  ✅ Recipient created: {$recipient['recipient_code']}\n";
    } else {
        echo "  ❌ Failed: " . ($recipient['message'] ?? 'Unknown error') . "\n";
    }
    
    echo "\n";
    usleep(500000); // 0.5 second delay
}

echo "=== Setup Complete ===\n";

// Create a test bank codes reference file
$bank_list = "";
foreach ($banks as $bank) {
    $bank_list .= "{$bank->code} - {$bank->name}\n";
}
file_put_contents(__DIR__ . '/bank_list.txt', $bank_list);
echo "Bank list saved to bank_list.txt\n";
?>