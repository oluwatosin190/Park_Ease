<?php
require_once __DIR__ . '/../config/database.php';
require_once __DIR__ . '/../includes/email-functions.php';

$database = new Database();
$db = $database->getConnection();

checkAndSendReminders($db);

echo "Reminders checked at " . date('Y-m-d H:i:s') . "\n";
?>