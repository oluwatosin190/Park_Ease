<?php
header('Content-Type: application/json');

require_once '../config/database.php';
require_once '../includes/pin-functions.php';

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$database = new Database();
$db = $database->getConnection();
$pinManager = new PinManager($db);

$owner_id = $_SESSION['user_id'];
$input = json_decode(file_get_contents('php://input'), true);

$reservation_id = $input['reservation_id'] ?? 0;
$pin = $input['pin'] ?? '';

if (!$reservation_id || !$pin) {
    echo json_encode(['success' => false, 'message' => 'Missing required fields']);
    exit();
}

// Verify ownership
$check = $db->prepare("SELECT r.id FROM reservations r
                        JOIN parking_spaces ps ON r.parking_id = ps.id
                        WHERE r.id = :rid AND ps.owner_id = :oid");
$check->execute([':rid' => $reservation_id, ':oid' => $owner_id]);

if (!$check->fetch()) {
    echo json_encode(['success' => false, 'message' => 'Unauthorized']);
    exit();
}

$result = $pinManager->validateAndStartTimer($reservation_id, $pin);
echo json_encode($result);
?>