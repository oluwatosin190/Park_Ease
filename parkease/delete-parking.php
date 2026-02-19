<?php
require_once 'config/database.php';

// Check if user is logged in and is an owner
if (!isset($_SESSION['user_id']) || $_SESSION['user_type'] !== 'owner') {
    header('Location: login.php');
    exit();
}

$database = new Database();
$db = $database->getConnection();

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;

// First, get the images to delete from server
$query = "SELECT images FROM parking_spaces WHERE id = :id AND owner_id = :owner_id";
$stmt = $db->prepare($query);
$stmt->bindParam(':id', $id);
$stmt->bindParam(':owner_id', $_SESSION['user_id']);
$stmt->execute();
$space = $stmt->fetch(PDO::FETCH_ASSOC);

if ($space) {
    // Delete images from server
    if ($space['images']) {
        $images = json_decode($space['images'], true);
        foreach ($images as $image) {
            $file_path = 'uploads/parking/' . $image;
            if (file_exists($file_path)) {
                unlink($file_path);
            }
        }
    }
    
    // Delete from database
    $query = "DELETE FROM parking_spaces WHERE id = :id AND owner_id = :owner_id";
    $stmt = $db->prepare($query);
    $stmt->bindParam(':id', $id);
    $stmt->bindParam(':owner_id', $_SESSION['user_id']);
    $stmt->execute();
}

// Redirect back to dashboard
header('Location: dashboard.php?msg=deleted');
exit();
?>