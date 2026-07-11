<?php
/**
 * POST /chat/api/set_typing.php
 * Body: { conversation_id: X, is_typing: true/false }
 * REST fallback for typing indicators (WS preferred)
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
$isTyping       = (bool)($input['is_typing'] ?? false);

if (!$conversationId) {
    echo json_encode(['error' => 'Missing conversation_id']);
    exit;
}

$database = new Database();
$db       = $database->getConnection();

// Security: verify user is a participant in this conversation
$check = $db->prepare("SELECT 1 FROM chat_conversations WHERE id = ? AND (participant_1 = ? OR participant_2 = ?)");
$check->execute([$conversationId, $userId, $userId]);
if (!$check->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

if ($isTyping) {
    $db->prepare(
        "INSERT INTO chat_typing (user_id, conversation_id)
         VALUES (?, ?) ON DUPLICATE KEY UPDATE updated_at = NOW()"
    )->execute([$userId, $conversationId]);
} else {
    $db->prepare("DELETE FROM chat_typing WHERE user_id = ? AND conversation_id = ?")
       ->execute([$userId, $conversationId]);
}

echo json_encode(['success' => true]);