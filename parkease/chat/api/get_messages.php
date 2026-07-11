<?php
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
$page           = max(1, (int)($_GET['page'] ?? 1));
$perPage        = 20;
$offset         = ($page - 1) * $perPage;

if (!$conversationId) {
    echo json_encode(['error' => 'Missing conversation_id']);
    exit;
}

$database = new Database();
$db       = $database->getConnection();

// Security: verify current user is in this conversation
$check = $db->prepare("
    SELECT 1 FROM chat_conversations
    WHERE id = ? AND (participant_1 = ? OR participant_2 = ?)
");
$check->execute([$conversationId, $userId, $userId]);
if (!$check->fetchColumn()) {
    http_response_code(403);
    echo json_encode(['error' => 'Forbidden']);
    exit;
}

// Get total message count
$countStmt = $db->prepare("
    SELECT COUNT(*)
    FROM chat_messages
    WHERE conversation_id = ? AND is_deleted = 0
");
$countStmt->execute([$conversationId]);
$total = (int)$countStmt->fetchColumn();

// Calculate offset from the start so newest messages show on page 1
// Total=25, perPage=20:
//   page 1 → offsetFromEnd=0,  limit=20 → messages 6-25 (newest)
//   page 2 → offsetFromEnd=0,  limit=5  → messages 1-5  (oldest)
$offsetFromEnd = $total - $offset - $perPage;
$limitActual   = $perPage;

if ($offsetFromEnd < 0) {
    $limitActual   = $perPage + $offsetFromEnd;
    $offsetFromEnd = 0;
}

if ($limitActual <= 0) {
    echo json_encode([
        'success'  => true,
        'messages' => [],
        'page'     => $page,
        'total'    => $total,
        'hasMore'  => false,
    ]);
    exit;
}

// Cast to int explicitly — PDO binds as string by default
// MariaDB rejects LIMIT/OFFSET as quoted strings
$limitActual   = (int)$limitActual;
$offsetFromEnd = (int)$offsetFromEnd;

// Use bindValue with explicit PDO::PARAM_INT for LIMIT and OFFSET
$stmt = $db->prepare("
    SELECT
        m.id,
        m.sender_id,
        m.message,
        m.message_type,
        m.attachment_url,
        m.is_deleted,
        m.created_at,
        u.first_name AS sender_first,
        u.last_name  AS sender_last,
        CASE WHEN rr.id IS NOT NULL THEN 1 ELSE 0 END AS is_read
    FROM chat_messages m
    JOIN users u ON u.id = m.sender_id
    LEFT JOIN chat_read_receipts rr
        ON rr.message_id = m.id AND rr.user_id != m.sender_id
    WHERE m.conversation_id = ? AND m.is_deleted = 0
    ORDER BY m.created_at ASC
    LIMIT ? OFFSET ?
");

// Bind each parameter explicitly as integer
$stmt->bindValue(1, $conversationId, PDO::PARAM_INT);
$stmt->bindValue(2, $limitActual,    PDO::PARAM_INT);
$stmt->bindValue(3, $offsetFromEnd,  PDO::PARAM_INT);
$stmt->execute();
$messages = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Auto-mark messages as read
$markStmt = $db->prepare("
    INSERT IGNORE INTO chat_read_receipts (message_id, user_id)
    SELECT m.id, :uid
    FROM chat_messages m
    WHERE m.conversation_id = :cid
      AND m.sender_id != :uid
      AND m.is_deleted = 0
      AND m.id NOT IN (
          SELECT message_id FROM chat_read_receipts WHERE user_id = :uid
      )
");
$markStmt->execute([':uid' => $userId, ':cid' => $conversationId]);

// Format each message
foreach ($messages as &$msg) {
    $msg['id']        = (int)$msg['id'];
    $msg['sender_id'] = (int)$msg['sender_id'];
    $msg['is_read']   = (bool)$msg['is_read'];
    $msg['is_mine']   = $msg['sender_id'] === $userId;
    if ($msg['is_deleted']) {
        $msg['message'] = 'This message was removed';
    }
}
unset($msg);

// hasMore = true if there are older messages before this page
$hasMore = $offsetFromEnd > 0;

echo json_encode([
    'success'  => true,
    'messages' => $messages,
    'page'     => $page,
    'total'    => $total,
    'hasMore'  => $hasMore,
]);