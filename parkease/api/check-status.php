<?php
require_once '../config/database.php';

// Start session if not already started
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Enable error reporting for debugging (remove in production)
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set header for JSON response
header('Content-Type: application/json');

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Unauthorized - Please login as owner']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

// Get POST data
$input = file_get_contents('php://input');
$data = json_decode($input, true);

if (!$data) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid JSON data']);
    exit();
}

$space_id = isset($data['space_id']) ? (int)$data['space_id'] : 0;
$available_spots = isset($data['available_spots']) ? (int)$data['available_spots'] : -1;

if (!$space_id || $available_spots < 0) {
    http_response_code(400);
    echo json_encode(['success' => false, 'message' => 'Invalid data - missing space_id or available_spots']);
    exit();
}

// Verify ownership
$query = "SELECT owner_id, total_spots FROM parking_spaces WHERE id = :id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $space_id);
$stmt->execute();
$space = $stmt->fetch(PDO::FETCH_ASSOC);

if (!$space) {
    http_response_code(404);
    echo json_encode(['success' => false, 'message' => 'Parking space not found']);
    exit();
}

if ($space['owner_id'] != $_SESSION['user_id']) {
    http_response_code(403);
    echo json_encode(['success' => false, 'message' => 'Not authorized to modify this space']);
    exit();
}

// Validate available spots doesn't exceed total
if ($available_spots > $space['total_spots']) {
    $available_spots = $space['total_spots'];
}

// Update available spots
$update = "UPDATE parking_spaces SET available_spots = :available WHERE id = :id";
$stmt = $db->prepare($update);
$stmt->bindParam(':available', $available_spots);
$stmt->bindParam(':id', $space_id);

if ($stmt->execute()) {
    // Determine new status
    $status = $available_spots > 0 ? 'available' : 'full';
    $status_text = $available_spots > 0 ? 'Available' : 'Full';
    
    echo json_encode([
        'success' => true,
        'available_spots' => $available_spots,
        'status' => $status,
        'status_text' => $status_text,
        'message' => 'Availability updated successfully'
    ]);
} else {
    http_response_code(500);
    echo json_encode(['success' => false, 'message' => 'Database update failed']);
}
?>