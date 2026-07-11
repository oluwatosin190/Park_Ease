<?php
/**
 * POST /chat/api/heartbeat.php
 * Called every 30s by the frontend to maintain online presence
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config/database.php';

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0);
if (!$userId) { 
    http_response_code(401); 
    echo json_encode(['error' => 'Unauthenticated']);
    exit; 
}

$database = new Database();
$db       = $database->getConnection();

$db->prepare(
    "INSERT INTO chat_presence (user_id, is_online)
     VALUES (?, 1)
     ON DUPLICATE KEY UPDATE is_online = 1, last_seen = NOW()"
)->execute([$userId]);

echo json_encode(['success' => true, 'time' => time()]);