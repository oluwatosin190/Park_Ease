<?php
session_start(); // Start session at the beginning
require_once 'config/database.php';
require_once 'includes/commission-functions.php';
require_once 'includes/email-functions.php';

// Function to sanitize input
function sanitize($input) {
    return htmlspecialchars(trim($input), ENT_QUOTES, 'UTF-8');
}

// Function to validate Nigerian bank account number (10 digits)
function validateBankAccount($account_number) {
    // Nigerian bank account numbers are typically 10 digits
    return preg_match('/^[0-9]{10}$/', $account_number);
}

// Function to validate Nigerian bank name (basic validation)
function validateBankName($bank_name) {
    $valid_banks = [
        'Access Bank', 'Citibank', 'Ecobank', 'Fidelity Bank', 'First Bank', 
        'First City Monument Bank', 'Globus Bank', 'Guaranty Trust Bank', 
        'Heritage Bank', 'Jaiz Bank', 'Keystone Bank', 'Polaris Bank', 
        'Providus Bank', 'Stanbic IBTC Bank', 'Standard Chartered Bank', 
        'Sterling Bank', 'Suntrust Bank', 'Titan Trust Bank', 'Union Bank', 
        'United Bank for Africa', 'Unity Bank', 'Wema Bank', 'Zenith Bank'
    ];
    
    // Allow any bank name (not strict to Nigerian banks)
    return !empty($bank_name) && strlen($bank_name) <= 100 && preg_match('/^[a-zA-Z\s\-\'\.]+$/', $bank_name);
}

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || empty($_SESSION['user_id']) || !isset($_SESSION['user_type']) || $_SESSION['user_type'] !== 'owner') {
    $_SESSION['error'] = 'Access denied. Only parking space owners can request withdrawals.';
    header('Location: login.php');
    exit();
}

// Check CSRF token
if (!isset($_POST['csrf_token']) || $_POST['csrf_token'] !== ($_SESSION['csrf_token'] ?? '')) {
    $_SESSION['error'] = 'Invalid security token. Please try again.';
    header('Location: owner-earnings.php');
    exit();
}

if ($_SERVER['REQUEST_METHOD'] != 'POST') {
    header('Location: owner-earnings.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

if (!$db) {
    error_log("Database connection failed in process-withdrawal.php");
    $_SESSION['error'] = 'System error. Please try again later.';
    header('Location: owner-earnings.php');
    exit();
}

$commission = new CommissionManager($db);

$owner_id = (int)$_SESSION['user_id'];
$amount = isset($_POST['amount']) ? (float)$_POST['amount'] : 0;
$payout_frequency = isset($_POST['payout_frequency']) ? sanitize($_POST['payout_frequency']) : '';
$bank_name = isset($_POST['bank_name']) ? trim($_POST['bank_name']) : '';
$account_number = isset($_POST['account_number']) ? trim($_POST['account_number']) : '';
$account_name = isset($_POST['account_name']) ? trim($_POST['account_name']) : '';

// Validate payout frequency
$valid_frequencies = ['daily', 'weekly', 'monthly'];
if (!in_array($payout_frequency, $valid_frequencies)) {
    $_SESSION['error'] = 'Invalid payout frequency selected.';
    header('Location: owner-earnings.php');
    exit();
}

// Validate amount
$errors = [];

if ($amount <= 0) {
    $errors[] = 'Withdrawal amount must be greater than zero.';
} elseif ($amount < 100) {
    $errors[] = 'Minimum withdrawal amount is ₦100.';
} elseif ($amount > 500000) {
    $errors[] = 'Maximum withdrawal amount is ₦500,000 per request.';
}

// Get owner balance
try {
    $balance = $commission->getOwnerBalance($owner_id);
    $current_balance = $balance['current_balance'] ?? 0;
    
    if ($amount > $current_balance) {
        $errors[] = 'Insufficient balance. Your current balance is ₦' . number_format($current_balance, 2);
    }
} catch (Exception $e) {
    error_log("Failed to get owner balance: " . $e->getMessage());
    $errors[] = 'Unable to verify balance. Please try again later.';
}

// Validate bank details
if (empty($bank_name)) {
    $errors[] = 'Bank name is required.';
} elseif (!validateBankName($bank_name)) {
    $errors[] = 'Invalid bank name. Please enter a valid bank name.';
}

if (empty($account_number)) {
    $errors[] = 'Account number is required.';
} elseif (!validateBankAccount($account_number)) {
    $errors[] = 'Account number must be 10 digits.';
}

if (empty($account_name)) {
    $errors[] = 'Account name is required.';
} elseif (strlen($account_name) > 100) {
    $errors[] = 'Account name cannot exceed 100 characters.';
} elseif (!preg_match('/^[a-zA-Z\s\-\'\.]+$/', $account_name)) {
    $errors[] = 'Account name contains invalid characters.';
}

// Check for existing pending withdrawal request
if (empty($errors)) {
    try {
        $check_query = "SELECT id FROM owner_payouts 
                        WHERE owner_id = :owner_id AND status = 'pending' 
                        AND created_at > DATE_SUB(NOW(), INTERVAL 24 HOUR)";
        $check_stmt = $db->prepare($check_query);
        $check_stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
        $check_stmt->execute();
        
        if ($check_stmt->rowCount() > 0) {
            $errors[] = 'You already have a pending withdrawal request. Please wait for it to be processed before submitting another.';
        }
    } catch (PDOException $e) {
        error_log("Failed to check pending withdrawals: " . $e->getMessage());
    }
}

// If there are errors, redirect back
if (!empty($errors)) {
    $_SESSION['error'] = implode('<br>', $errors);
    header('Location: owner-earnings.php');
    exit();
}

// Generate unique reference
$reference = 'WD_' . uniqid() . '_' . $owner_id . '_' . time() . '_' . bin2hex(random_bytes(4));
$reference = substr($reference, 0, 100);

// Begin transaction
$db->beginTransaction();

try {
    // Insert payout request
    $query = "INSERT INTO owner_payouts 
              (owner_id, amount, payout_frequency, bank_name, account_number, account_name, reference, status, created_at) 
              VALUES 
              (:owner_id, :amount, :payout_frequency, :bank_name, :account_number, :account_name, :reference, 'pending', NOW())";
    
    $stmt = $db->prepare($query);
    $stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
    $stmt->bindParam(':amount', $amount, PDO::PARAM_STR);
    $stmt->bindParam(':payout_frequency', $payout_frequency, PDO::PARAM_STR);
    $stmt->bindParam(':bank_name', $bank_name, PDO::PARAM_STR);
    $stmt->bindParam(':account_number', $account_number, PDO::PARAM_STR);
    $stmt->bindParam(':account_name', $account_name, PDO::PARAM_STR);
    $stmt->bindParam(':reference', $reference, PDO::PARAM_STR);
    
    if (!$stmt->execute()) {
        throw new Exception("Failed to insert payout request");
    }
    
    $payout_id = $db->lastInsertId();
    
    // Update owner balance (reduce current balance, increase pending withdrawal)
    $update = "UPDATE owner_balances SET 
               current_balance = current_balance - :amount,
               pending_withdrawal = COALESCE(pending_withdrawal, 0) + :amount,
               total_withdrawn = COALESCE(total_withdrawn, 0) + :amount,
               last_withdrawal_request = NOW()
               WHERE owner_id = :owner_id";
    $update_stmt = $db->prepare($update);
    $update_stmt->bindParam(':amount', $amount, PDO::PARAM_STR);
    $update_stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
    
    if (!$update_stmt->execute()) {
        throw new Exception("Failed to update owner balance");
    }
    
    // Check if update affected any rows
    if ($update_stmt->rowCount() === 0) {
        // Insert initial balance record if not exists
        $insert_balance = "INSERT INTO owner_balances (owner_id, current_balance, pending_withdrawal, total_withdrawn, last_withdrawal_request) 
                           VALUES (:owner_id, :amount, :amount, :amount, NOW())
                           ON DUPLICATE KEY UPDATE 
                           current_balance = current_balance - :amount2,
                           pending_withdrawal = pending_withdrawal + :amount2,
                           total_withdrawn = total_withdrawn + :amount2";
        $insert_balance_stmt = $db->prepare($insert_balance);
        $insert_balance_stmt->bindParam(':owner_id', $owner_id, PDO::PARAM_INT);
        $insert_balance_stmt->bindParam(':amount', $amount, PDO::PARAM_STR);
        $insert_balance_stmt->bindParam(':amount2', $amount, PDO::PARAM_STR);
        $insert_balance_stmt->execute();
    }
    
    $db->commit();
    
    // Log the withdrawal request
    error_log("Withdrawal request submitted - Owner ID: $owner_id, Amount: ₦$amount, Reference: $reference");
    
    // Send confirmation email to owner
    try {
        $owner_query = "SELECT email, first_name FROM users WHERE id = :id";
        $owner_stmt = $db->prepare($owner_query);
        $owner_stmt->bindParam(':id', $owner_id, PDO::PARAM_INT);
        $owner_stmt->execute();
        $owner = $owner_stmt->fetch(PDO::FETCH_ASSOC);
        
        if ($owner) {
            $subject = "Withdrawal Request Received - SpaceNode";
            $message = "Hello {$owner['first_name']},\n\n";
            $message .= "Your withdrawal request has been received and is being processed.\n\n";
            $message .= "Amount: ₦" . number_format($amount, 2) . "\n";
            $message .= "Reference: $reference\n";
            $message .= "Bank: $bank_name\n";
            $message .= "Account: $account_number\n\n";
            $message .= "Your funds will be transferred to your bank account within 2-3 business days.\n\n";
            $message .= "Thank you for using SpaceNode!";
            
            $headers = "From: SpaceNode <noreply@spacenode.com>\r\n";
            mail($owner['email'], $subject, $message, $headers);
            error_log("Withdrawal confirmation email sent to owner: {$owner['email']}");
        }
    } catch (Exception $e) {
        error_log("Failed to send withdrawal confirmation email: " . $e->getMessage());
        // Don't fail the withdrawal if email fails
    }
    
    // Notify admin
    try {
        $admin_email = defined('ADMIN_EMAIL') ? ADMIN_EMAIL : 'admin@spacenode.com';
        $subject = "New Withdrawal Request - SpaceNode";
        $message = "A new withdrawal request has been submitted.\n\n";
        $message .= "Owner ID: $owner_id\n";
        $message .= "Amount: ₦" . number_format($amount, 2) . "\n";
        $message .= "Reference: $reference\n";
        $message .= "Bank: $bank_name\n";
        $message .= "Account: $account_number\n";
        $message .= "Account Name: $account_name\n\n";
        $message .= "Please process this request in the admin dashboard.";
        
        $headers = "From: SpaceNode <noreply@spacenode.com>\r\n";
        mail($admin_email, $subject, $message, $headers);
    } catch (Exception $e) {
        error_log("Failed to send admin notification: " . $e->getMessage());
    }
    
    $_SESSION['success'] = 'Withdrawal request submitted successfully! Reference: ' . $reference;
    
} catch (PDOException $e) {
    $db->rollBack();
    error_log("Database error in process-withdrawal.php: " . $e->getMessage());
    $_SESSION['error'] = 'A database error occurred. Please try again later.';
    
} catch (Exception $e) {
    $db->rollBack();
    error_log("General error in process-withdrawal.php: " . $e->getMessage());
    $_SESSION['error'] = 'Failed to process withdrawal. Please try again.';
}

header('Location: owner-earnings.php');
exit();
?>