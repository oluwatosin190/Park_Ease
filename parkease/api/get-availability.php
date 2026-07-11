<?php
require_once '../config/database.php';

header('Content-Type: application/json');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

if (!$id) {
    echo json_encode(['success' => false, 'message' => 'No ID provided']);
    exit();
}

$database = new Database();
$db = $database->getConnection();

$query = "SELECT available_spots, total_spots FROM parking_spaces WHERE id = :id AND is_active = 1";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->execute();
$space = $stmt->fetch(PDO::FETCH_ASSOC);

if ($space) {
    echo json_encode([
        'success' => true,
        'available_spots' => $space['available_spots'],
        'total_spots' => $space['total_spots'],
        'is_available' => $space['available_spots'] > 0
    ]);
} else {
    echo json_encode(['success' => false, 'message' => 'Space not found']);
}
?>