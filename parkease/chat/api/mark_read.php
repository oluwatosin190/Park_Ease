<?php
/**
 * POST /chat/api/mark_read.php
 * Body: { conversation_id: X }
 * Marks all messages in the conversation as read for current user
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

$input          = json_decode(file_get_contents('php://input'), true) ?? [];
$conversationId = (int)($input['conversation_id'] ?? 0);
if (!$conversationId) { 
    echo json_encode(['error' => 'Missing conversation_id']); 
    exit; 
}

$database = new Database();
$db       = $database->getConnection();

// Security check — ensure user is a participant
$check = $db->prepare("SELECT 1 FROM chat_conversations WHERE id = ? AND (participant_1 = ? OR participant_2 = ?)");
$check->execute([$conversationId, $userId, $userId]);
if (!$check->fetchColumn()) { 
    http_response_code(403); 
    echo json_encode(['error' => 'Forbidden']);
    exit; 
}

$db->prepare("
    INSERT IGNORE INTO chat_read_receipts (message_id, user_id)
    SELECT m.id, :uid
    FROM chat_messages m
    WHERE m.conversation_id = :cid
      AND m.sender_id != :uid
      AND m.is_deleted = 0
      AND m.id NOT IN (SELECT message_id FROM chat_read_receipts WHERE user_id = :uid)
")->execute([':uid' => $userId, ':cid' => $conversationId]);

echo json_encode(['success' => true]);