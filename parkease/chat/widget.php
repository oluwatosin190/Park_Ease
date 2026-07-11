<?php
/**
 * ParkChat Widget — drop this include into ANY page to embed the chat.
 *
 * Usage in any PHP file:
 *   <?php include ROOT . '/chat/widget.php'; ?>
 *
 * Works with both $_SESSION['user_id'] (frontend)
 * and $_SESSION['admin_id'] (admin panel).
 *
 * You can also start a specific conversation from a parking space page:
 *   <button onclick="ParkChat.openWith(<?= $owner_id ?>, <?= $parking_id ?>)">
 *       Chat with owner
 *   </button>
 */

// Auto-detect user from either session context
$chatUserId   = $_SESSION['user_id']   ?? $_SESSION['admin_id']  ?? null;
$chatUserName = $_SESSION['user_name'] ?? $_SESSION['admin_name'] ?? 'User';
$chatUserRole = $_SESSION['user_type'] ?? $_SESSION['admin_role'] ?? 'parker';

// Don't render widget if no one is logged in
if (!$chatUserId) return;

/*
 * ⚠️  PRODUCTION: Change these to match your domain.
 *
 * WS_HOST  → your server's domain or IP
 * WS_PORT  → port 8080 (or 443 if using wss:// behind nginx proxy)
 *
 * For wss:// (secure WebSocket) behind nginx, set:
 *   WS_SCHEME = 'wss'
 *   WS_HOST   = 'yourdomain.com'
 *   WS_PORT   = 443
 */
$wsScheme = 'ws';
$wsHost   = $_SERVER['HTTP_HOST'] ?? 'localhost';
$wsPort   = 8080;
$wsUrl    = "{$wsScheme}://{$wsHost}:{$wsPort}";

// Determine API base path (works in /admin/ and / equally)
$scriptDir = dirname($_SERVER['SCRIPT_NAME']);
$apiBase   = '/Park_Ease/parkease/chat/api'; // adjust if your project is in a subdirectory
?>

<!-- ParkChat widget config -->
<script>
window.PARKCHAT_CONFIG = {
    userId:   <?= (int)$chatUserId ?>,
    userName: <?= json_encode($chatUserName) ?>,
    userRole: <?= json_encode($chatUserRole) ?>,
    wsUrl:    <?= json_encode($wsUrl) ?>,
    apiBase:  <?= json_encode($apiBase) ?>,
};
</script>

<!-- ParkChat styles & script -->
<link rel="stylesheet" href="/Park_Ease/parkease/chat/assets/chat.css">
<script src="/Park_Ease/parkease/chat/assets/chat.js" defer></script>