<?php
/**
 * POST /chat/api/send_message.php
 * Creates or finds a conversation, inserts a message.
 * Used as REST fallback when WebSocket is unavailable.
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

require_once dirname(__DIR__, 2) . '/config/database.php';

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { 
    http_response_code(405); 
    echo json_encode(['error' => 'Method not allowed']);
    exit; 
}

$userId = (int)($_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? 0);
if (!$userId) { 
    http_response_code(401); 
    echo json_encode(['error' => 'Unauthenticated']); 
    exit; 
}

$input          = json_decode(file_get_contents('php://input'), true) ?? [];
$recipientId    = (int)($input['recipient_id']    ?? 0);
$message        = trim($input['message']          ?? '');
$parkingId      = (int)($input['parking_id']      ?? 0) ?: null;
$conversationId = (int)($input['conversation_id'] ?? 0) ?: null;

if (!$recipientId && !$conversationId) { 
    echo json_encode(['error' => 'Missing recipient_id or conversation_id']); 
    exit; 
}
if ($message === '') { 
    echo json_encode(['error' => 'Empty message']); 
    exit; 
}
if (strlen($message) > 5000) { 
    echo json_encode(['error' => 'Message too long']); 
    exit; 
}

$database = new Database();
$db       = $database->getConnection();

// ── Find or create conversation ───────────────────────────────────
if (!$conversationId) {
    // Enforce ordering: smaller id = participant_1
    // This guarantees the UNIQUE KEY works regardless of who starts the chat
    $p1 = min($userId, $recipientId);
    $p2 = max($userId, $recipientId);

    // Try to find existing conversation
    $findStmt = $db->prepare(
        "SELECT id FROM chat_conversations WHERE participant_1 = ? AND participant_2 = ?"
    );
    $findStmt->execute([$p1, $p2]);
    $conversationId = $findStmt->fetchColumn();

    if (!$conversationId) {
        // Create new conversation
        $createStmt = $db->prepare(
            "INSERT INTO chat_conversations (participant_1, participant_2, parking_id) VALUES (?, ?, ?)"
        );
        $createStmt->execute([$p1, $p2, $parkingId]);
        $conversationId = (int)$db->lastInsertId();
    }
} else {
    // Validate user is a participant in this conversation
    $check = $db->prepare("SELECT 1 FROM chat_conversations WHERE id = ? AND (participant_1 = ? OR participant_2 = ?)");
    $check->execute([$conversationId, $userId, $userId]);
    if (!$check->fetchColumn()) { 
        http_response_code(403); 
        echo json_encode(['error' => 'Forbidden']); 
        exit; 
    }
}

// ── Insert message ────────────────────────────────────────────────
$msgStmt = $db->prepare(
    "INSERT INTO chat_messages (conversation_id, sender_id, message, message_type) VALUES (?, ?, ?, 'text')"
);
$msgStmt->execute([$conversationId, $userId, $message]);
$messageId = (int)$db->lastInsertId();

// Update conversation timestamp
$db->prepare("UPDATE chat_conversations SET updated_at = NOW() WHERE id = ?")->execute([$conversationId]);

// Get sender name for response
$userStmt = $db->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
$userStmt->execute([$userId]);
$user = $userStmt->fetch(PDO::FETCH_ASSOC);

echo json_encode([
    'success'        => true,
    'messageId'      => $messageId,
    'conversationId' => $conversationId,
    'senderId'       => $userId,
    'senderName'     => $user['first_name'],
    'message'        => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
    'sentAt'         => date('Y-m-d H:i:s'),
]);