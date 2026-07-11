<?php
/**
 * GET /chat/api/get_typing.php?conversation_id=X
 * Returns who is currently typing (rows older than 5s are ignored)
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

$conversationId = (int)($_GET['conversation_id'] ?? 0);
if (!$conversationId) {
    echo json_encode(['typing' => []]);
    exit;
}

$database = new Database();
$db       = $database->getConnection();

$stmt = $db->prepare("
    SELECT t.user_id, u.first_name
    FROM chat_typing t
    JOIN users u ON u.id = t.user_id
    WHERE t.conversation_id = ?
      AND t.user_id != ?
      AND t.updated_at > DATE_SUB(NOW(), INTERVAL 5 SECOND)
");
$stmt->execute([$conversationId, $userId]);
$typers = $stmt->fetchAll(PDO::FETCH_ASSOC);

echo json_encode(['typing' => $typers]);