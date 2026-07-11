<?php
// Turn on error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

require_once '../config/database.php';
require_once '../includes/notification-manager.php';

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: ../login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$owner_id = $_SESSION['user_id'];
$session_id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$session_id) {
    $_SESSION['error'] = 'No session ID provided';
    header('Location: active-sessions.php');
    exit();
}

// Verify ownership and get session details
$check = $db->prepare("SELECT r.*, u.email, u.first_name, u.last_name,
                       ps.name as parking_name
                       FROM reservations r
                       JOIN parking_spaces ps ON r.parking_id = ps.id
                       JOIN users u ON r.user_id = u.id
                       WHERE r.id = :id AND ps.owner_id = :oid");
$check->execute([':id' => $session_id, ':oid' => $owner_id]);
$session = $check->fetch(PDO::FETCH_ASSOC);

if (!$session) {
    $_SESSION['error'] = 'You do not have permission to confirm this checkout';
    header('Location: active-sessions.php');
    exit();
}

// Begin transaction
$db->beginTransaction();

try {
    // Update the booking to completed
    $update = $db->prepare("UPDATE reservations SET 
                             timer_status = 'completed',
                             status = 'completed',
                             checkout_status = 'confirmed',
                             actual_end_time = NOW()
                             WHERE id = :id");
    $update->execute([':id' => $session_id]);
    
    // Send notification to customer that session is completed
    $notifier = new NotificationManager($db);
    $notifier->sendExpired($session_id, $session['overstay_charge']);
    
    // Send notification to owner that space is now available
    $notifier->sendOwnerDeparture($session_id, $session['overstay_charge']);
    
    $db->commit();
    
    $_SESSION['success'] = 'Checkout confirmed successfully. Session completed.';
    
} catch (Exception $e) {
    $db->rollBack();
    $_SESSION['error'] = 'Error confirming checkout: ' . $e->getMessage();
}

header('Location: active-sessions.php');
exit();
?>