<?php
/**
 * GET /chat/api/get_conversations.php
 * Returns the current user's conversation list (inbox)
 */

// Start session if not already started
if (session_status() === PHP_SESSION_NONE) {
    session_start();
}

header('Content-Type: application/json');

// Works for both admin panel ($_SESSION['admin_id']) and frontend ($_SESSION['user_id'])
$userId = $_SESSION['user_id'] ?? $_SESSION['admin_id'] ?? null;
if (!$userId) {
    http_response_code(401);
    echo json_encode(['error' => 'Unauthenticated']);
    exit;
}
$userId = (int)$userId;

// Correct path: chat/api → chat → parkease (2 levels up)
require_once dirname(__DIR__, 2) . '/config/database.php';

$database = new Database();
$db       = $database->getConnection();

// Load all conversations where current user is a participant
$stmt = $db->prepare("
    SELECT
        c.id                AS conversation_id,
        c.participant_1,
        c.participant_2,
        c.parking_id,
        c.updated_at,
        m.id                AS last_message_id,
        m.sender_id         AS last_sender_id,
        m.message           AS last_message,
        m.created_at        AS last_message_at,
        CASE WHEN c.participant_1 = :uid THEN u2.id        ELSE u1.id        END AS other_id,
        CASE WHEN c.participant_1 = :uid THEN u2.first_name ELSE u1.first_name END AS other_first,
        CASE WHEN c.participant_1 = :uid THEN u2.last_name  ELSE u1.last_name  END AS other_last,
        CASE WHEN c.participant_1 = :uid THEN u2.user_type  ELSE u1.user_type  END AS other_type,
        NULL AS other_photo,
        COALESCE(pr.is_online, 0) AS is_online,
        pr.last_seen,
        (
            SELECT COUNT(*) FROM chat_messages cm
            LEFT JOIN chat_read_receipts rr ON rr.message_id = cm.id AND rr.user_id = :uid
            WHERE cm.conversation_id = c.id
              AND cm.sender_id != :uid
              AND cm.is_deleted = 0
              AND rr.id IS NULL
        ) AS unread_count
    FROM chat_conversations c
    JOIN users u1 ON u1.id = c.participant_1
    JOIN users u2 ON u2.id = c.participant_2
    LEFT JOIN chat_messages m ON m.id = (
        SELECT id FROM chat_messages
        WHERE conversation_id = c.id AND is_deleted = 0
        ORDER BY created_at DESC LIMIT 1
    )
    LEFT JOIN chat_presence pr ON pr.user_id = (
        CASE WHEN c.participant_1 = :uid THEN c.participant_2 ELSE c.participant_1 END
    )
    WHERE (c.participant_1 = :uid OR c.participant_2 = :uid)
    ORDER BY c.updated_at DESC
");
$stmt->execute([':uid' => $userId]);
$conversations = $stmt->fetchAll(PDO::FETCH_ASSOC);

// Format timestamps
foreach ($conversations as &$conv) {
    $conv['conversation_id'] = (int)$conv['conversation_id'];
    $conv['unread_count']    = (int)$conv['unread_count'];
    $conv['is_online']       = (bool)$conv['is_online'];

    // Consider online if last heartbeat was within 60 seconds
    if ($conv['last_seen']) {
        $diffSeconds         = time() - strtotime($conv['last_seen']);
        $conv['is_online']   = $diffSeconds < 90;
    }
}

echo json_encode(['success' => true, 'conversations' => $conversations]);