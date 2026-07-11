<?php
/**
 * ParkChat WebSocket Server — built on Ratchet
 * 
 * Install Ratchet first:
 *   composer require cboden/ratchet
 * 
 * Run this server (from project root):
 *   php chat/server/ChatServer.php
 * 
 * Keep alive in production with supervisor or screen:
 *   screen -S parkchat php chat/server/ChatServer.php
 */

require dirname(__DIR__, 2) . '/vendor/autoload.php';
require dirname(__DIR__, 2) . '/config/database.php';

use Ratchet\MessageComponentInterface;
use Ratchet\ConnectionInterface;
use Ratchet\Server\IoServer;
use Ratchet\Http\HttpServer;
use Ratchet\WebSocket\WsServer;

class ChatServer implements MessageComponentInterface
{
    /** @var \SplObjectStorage  connection → metadata */
    protected \SplObjectStorage $clients;

    /** @var array  userId → ConnectionInterface  (for direct messaging) */
    protected array $userConnections = [];

    protected \PDO $db;

    public function __construct()
    {
        $this->clients = new \SplObjectStorage();
        $database      = new Database();
        $this->db      = $database->getConnection();
        echo "[ParkChat] WebSocket server started\n";
    }

    // ── Connection opened ──────────────────────────────────────────
    public function onOpen(ConnectionInterface $conn): void
    {
        $this->clients->attach($conn, [
            'userId'         => null,
            'conversationId' => null,
        ]);
        echo "[ParkChat] New connection: {$conn->resourceId}\n";
    }

    // ── Message received ───────────────────────────────────────────
    public function onMessage(ConnectionInterface $from, $rawMsg): void
    {
        $data = json_decode($rawMsg, true);
        if (!$data || !isset($data['type'])) return;

        switch ($data['type']) {

            // Client sends auth token (session user_id) on connect
            case 'auth':
                $this->handleAuth($from, $data);
                break;

            // User sent a chat message
            case 'message':
                $this->handleMessage($from, $data);
                break;

            // User opened a conversation (mark messages read)
            case 'join_conversation':
                $this->handleJoin($from, $data);
                break;

            // User is typing
            case 'typing':
                $this->handleTyping($from, $data);
                break;

            // Heartbeat to stay online
            case 'heartbeat':
                $this->handleHeartbeat($from);
                break;
        }
    }

    // ── Connection closed ──────────────────────────────────────────
    public function onClose(ConnectionInterface $conn): void
    {
        $meta = $this->clients[$conn];

        // Mark user offline
        if ($meta['userId']) {
            $this->setOnlineStatus($meta['userId'], false);

            // Notify conversation partner they went offline
            $this->broadcastPresence($meta['userId'], false);

            // Remove from userConnections map
            unset($this->userConnections[$meta['userId']]);
        }

        $this->clients->detach($conn);
        echo "[ParkChat] Connection closed: {$conn->resourceId}\n";
    }

    public function onError(ConnectionInterface $conn, \Exception $e): void
    {
        echo "[ParkChat] Error: {$e->getMessage()}\n";
        $conn->close();
    }

    // ── Handlers ───────────────────────────────────────────────────

    private function handleAuth(ConnectionInterface $conn, array $data): void
    {
        $userId = (int)($data['userId'] ?? 0);
        if (!$userId) return;

        // Validate user exists
        $stmt = $this->db->prepare("SELECT id, first_name, user_type FROM users WHERE id = ? AND is_active = 1");
        $stmt->execute([$userId]);
        $user = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$user) return;

        // Update metadata on this connection
        $this->clients[$conn] = array_merge($this->clients[$conn], [
            'userId'    => $userId,
            'firstName' => $user['first_name'],
            'userType'  => $user['user_type'],
        ]);

        // Store connection by userId for direct delivery
        $this->userConnections[$userId] = $conn;

        // Mark online in DB
        $this->setOnlineStatus($userId, true);

        // Tell client auth succeeded
        $conn->send(json_encode([
            'type'   => 'auth_ok',
            'userId' => $userId,
        ]));

        // Broadcast online status to relevant users
        $this->broadcastPresence($userId, true);
    }

    private function handleMessage(ConnectionInterface $from, array $data): void
    {
        $meta           = $this->clients[$from];
        $senderId       = $meta['userId'] ?? null;
        $conversationId = (int)($data['conversationId'] ?? 0);
        $message        = trim($data['message'] ?? '');

        if (!$senderId || !$conversationId || $message === '') return;

        // Security: ensure sender is actually in this conversation
        if (!$this->isParticipant($senderId, $conversationId)) return;

        // Persist to DB
        $stmt = $this->db->prepare(
            "INSERT INTO chat_messages (conversation_id, sender_id, message, message_type)
             VALUES (?, ?, ?, 'text')"
        );
        $stmt->execute([$conversationId, $senderId, $message]);
        $messageId = $this->db->lastInsertId();

        // Update conversation updated_at
        $this->db->prepare("UPDATE chat_conversations SET updated_at = NOW() WHERE id = ?")
                 ->execute([$conversationId]);

        // Clear typing indicator for this sender
        $this->db->prepare("DELETE FROM chat_typing WHERE user_id = ? AND conversation_id = ?")
                 ->execute([$senderId, $conversationId]);

        // Build the payload to broadcast
        $payload = [
            'type'           => 'new_message',
            'messageId'      => (int)$messageId,
            'conversationId' => $conversationId,
            'senderId'       => $senderId,
            'senderName'     => $meta['firstName'],
            'message'        => htmlspecialchars($message, ENT_QUOTES, 'UTF-8'),
            'sentAt'         => date('Y-m-d H:i:s'),
            'isRead'         => false,
        ];

        // Deliver to both participants
        $this->deliverToConversation($conversationId, $senderId, $payload);
    }

    private function handleJoin(ConnectionInterface $conn, array $data): void
    {
        $meta           = $this->clients[$conn];
        $userId         = $meta['userId'] ?? null;
        $conversationId = (int)($data['conversationId'] ?? 0);

        if (!$userId || !$conversationId) return;
        if (!$this->isParticipant($userId, $conversationId)) return;

        // Store which conversation this connection is actively viewing
        $this->clients[$conn] = array_merge($this->clients[$conn], [
            'conversationId' => $conversationId,
        ]);

        // Mark all unread messages in this conversation as read
        $this->markConversationRead($userId, $conversationId);

        // Notify the other participant their messages were seen
        $otherId = $this->getOtherParticipant($conversationId, $userId);
        if ($otherId && isset($this->userConnections[$otherId])) {
            $this->userConnections[$otherId]->send(json_encode([
                'type'           => 'messages_read',
                'conversationId' => $conversationId,
                'readBy'         => $userId,
                'readAt'         => date('Y-m-d H:i:s'),
            ]));
        }
    }

    private function handleTyping(ConnectionInterface $from, array $data): void
    {
        $meta           = $this->clients[$from];
        $userId         = $meta['userId'] ?? null;
        $conversationId = (int)($data['conversationId'] ?? 0);
        $isTyping       = (bool)($data['isTyping'] ?? false);

        if (!$userId || !$conversationId) return;
        if (!$this->isParticipant($userId, $conversationId)) return;

        // Upsert typing row
        if ($isTyping) {
            $this->db->prepare(
                "INSERT INTO chat_typing (user_id, conversation_id)
                 VALUES (?, ?)
                 ON DUPLICATE KEY UPDATE updated_at = NOW()"
            )->execute([$userId, $conversationId]);
        } else {
            $this->db->prepare(
                "DELETE FROM chat_typing WHERE user_id = ? AND conversation_id = ?"
            )->execute([$userId, $conversationId]);
        }

        // Forward typing event to the OTHER participant only
        $otherId = $this->getOtherParticipant($conversationId, $userId);
        if ($otherId && isset($this->userConnections[$otherId])) {
            $this->userConnections[$otherId]->send(json_encode([
                'type'           => 'typing',
                'conversationId' => $conversationId,
                'userId'         => $userId,
                'name'           => $meta['firstName'],
                'isTyping'       => $isTyping,
            ]));
        }
    }

    private function handleHeartbeat(ConnectionInterface $conn): void
    {
        $meta   = $this->clients[$conn];
        $userId = $meta['userId'] ?? null;
        if (!$userId) return;

        $this->setOnlineStatus($userId, true);
        $conn->send(json_encode(['type' => 'heartbeat_ack']));
    }

    // ── Helpers ────────────────────────────────────────────────────

    /** Deliver a payload to both participants of a conversation */
    private function deliverToConversation(int $convId, int $senderId, array $payload): void
    {
        $stmt = $this->db->prepare(
            "SELECT participant_1, participant_2 FROM chat_conversations WHERE id = ?"
        );
        $stmt->execute([$convId]);
        $conv = $stmt->fetch(\PDO::FETCH_ASSOC);
        if (!$conv) return;

        $json = json_encode($payload);
        foreach ([$conv['participant_1'], $conv['participant_2']] as $uid) {
            if (isset($this->userConnections[$uid])) {
                $this->userConnections[$uid]->send($json);
            }
        }
    }

    /** Broadcast online/offline status to all connected users who share a conversation */
    private function broadcastPresence(int $userId, bool $isOnline): void
    {
        // Find all conversation partners
        $stmt = $this->db->prepare(
            "SELECT CASE WHEN participant_1 = :u THEN participant_2 ELSE participant_1 END AS partner_id
             FROM chat_conversations
             WHERE participant_1 = :u OR participant_2 = :u"
        );
        $stmt->execute([':u' => $userId]);
        $partners = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        $payload = json_encode([
            'type'     => 'presence',
            'userId'   => $userId,
            'isOnline' => $isOnline,
            'lastSeen' => date('Y-m-d H:i:s'),
        ]);

        foreach ($partners as $partnerId) {
            if (isset($this->userConnections[$partnerId])) {
                $this->userConnections[$partnerId]->send($payload);
            }
        }
    }

    private function isParticipant(int $userId, int $convId): bool
    {
        $stmt = $this->db->prepare(
            "SELECT 1 FROM chat_conversations
             WHERE id = ? AND (participant_1 = ? OR participant_2 = ?) LIMIT 1"
        );
        $stmt->execute([$convId, $userId, $userId]);
        return (bool)$stmt->fetchColumn();
    }

    private function getOtherParticipant(int $convId, int $userId): ?int
    {
        $stmt = $this->db->prepare(
            "SELECT CASE WHEN participant_1 = ? THEN participant_2 ELSE participant_1 END
             FROM chat_conversations WHERE id = ?"
        );
        $stmt->execute([$userId, $convId]);
        $result = $stmt->fetchColumn();
        return $result ? (int)$result : null;
    }

    private function markConversationRead(int $userId, int $convId): void
    {
        // Get all unread messages in this conversation not sent by this user
        $stmt = $this->db->prepare(
            "SELECT id FROM chat_messages
             WHERE conversation_id = ?
               AND sender_id != ?
               AND is_deleted = 0
               AND id NOT IN (
                   SELECT message_id FROM chat_read_receipts WHERE user_id = ?
               )"
        );
        $stmt->execute([$convId, $userId, $userId]);
        $unread = $stmt->fetchAll(\PDO::FETCH_COLUMN);

        if (empty($unread)) return;

        // Bulk insert read receipts
        $placeholders = implode(',', array_fill(0, count($unread), '(?,?)'));
        $params = [];
        foreach ($unread as $msgId) {
            $params[] = $msgId;
            $params[] = $userId;
        }
        $this->db->prepare(
            "INSERT IGNORE INTO chat_read_receipts (message_id, user_id) VALUES $placeholders"
        )->execute($params);
    }

    private function setOnlineStatus(int $userId, bool $isOnline): void
    {
        $this->db->prepare(
            "INSERT INTO chat_presence (user_id, is_online)
             VALUES (?, ?)
             ON DUPLICATE KEY UPDATE is_online = ?, last_seen = NOW()"
        )->execute([$userId, (int)$isOnline, (int)$isOnline]);
    }
}

// ── Boot the server ────────────────────────────────────────────────
$server = IoServer::factory(
    new HttpServer(
        new WsServer(
            new ChatServer()
        )
    ),
    8080,          // ← WebSocket port (open this in firewall/security group)
    '0.0.0.0'      // listen on all interfaces
);

echo "[ParkChat] Listening on ws://0.0.0.0:8080\n";
$server->run();