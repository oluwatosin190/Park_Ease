<?php
// Turn on error reporting
error_reporting(E_ALL);
ini_set('display_errors', 1);

// Set header to JSON
header('Content-Type: application/json');

// Start session
if (session_status() == PHP_SESSION_NONE) {
    session_start();
}

// Check if user is logged in
if (!isset($_SESSION['user_id'])) {
    echo json_encode(['success' => false, 'message' => 'Not logged in']);
    exit();
}

require_once '../config/database.php';

try {
    $database = new Database();
    $db = $database->getConnection();
    
    if (!$db) {
        echo json_encode(['success' => false, 'message' => 'Database connection failed']);
        exit();
    }
    
    // Get POST data
    $input = file_get_contents('php://input');
    
    if (!$input) {
        echo json_encode(['success' => false, 'message' => 'No input received']);
        exit();
    }
    
    $data = json_decode($input, true);
    
    if (!$data) {
        echo json_encode(['success' => false, 'message' => 'Invalid JSON: ' . $input]);
        exit();
    }
    
    $space_id = isset($data['space_id']) ? (int)$data['space_id'] : 0;
    $available_spots = isset($data['available_spots']) ? (int)$data['available_spots'] : -1;
    
    if (!$space_id || $available_spots < 0) {
        echo json_encode(['success' => false, 'message' => 'Missing space_id or available_spots']);
        exit();
    }
    
    // Get space details
    $query = "SELECT owner_id, total_spots FROM parking_spaces WHERE id = :id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $space_id);
    $stmt->execute();
    $space = $stmt->fetch(PDO::FETCH_ASSOC);
    
    if (!$space) {
        echo json_encode(['success' => false, 'message' => 'Space not found']);
        exit();
    }
    
    if ($space['owner_id'] != $_SESSION['user_id']) {
        echo json_encode(['success' => false, 'message' => 'Not authorized']);
        exit();
    }
    
    // Update
    $update = "UPDATE parking_spaces SET available_spots = :available WHERE id = :id";
    $stmt = $db->prepare($update);
    $stmt->bindParam(':available', $available_spots);
    $stmt->bindParam(':id', $space_id);
    
    if ($stmt->execute()) {
        echo json_encode([
            'success' => true,
            'available_spots' => $available_spots,
            'status' => $available_spots > 0 ? 'available' : 'full',
            'message' => 'Updated successfully'
        ]);
    } else {
        echo json_encode(['success' => false, 'message' => 'Update failed']);
    }
    
} catch (Exception $e) {
    echo json_encode(['success' => false, 'message' => 'Error: ' . $e->getMessage()]);
}
?>