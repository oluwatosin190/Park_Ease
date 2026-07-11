#!/bin/bash
# ================================================================
#  ParkChat — WebSocket Server Startup Script
# ================================================================

# Install Ratchet (run once from project root)
# composer require cboden/ratchet

PHP_BIN=$(which php)
SCRIPT_DIR="$(cd "$(dirname "${BASH_SOURCE[0]}")" && pwd)"
SERVER="$SCRIPT_DIR/chat/server/ChatServer.php"
LOG="$SCRIPT_DIR/chat/server/parkchat.log"

echo "[ParkChat] Starting WebSocket server..."
echo "[ParkChat] PHP binary: $PHP_BIN"
echo "[ParkChat] Server script: $SERVER"
echo "[ParkChat] Log: $LOG"

# Start server with nohup so it stays alive after terminal close
nohup "$PHP_BIN" "$SERVER" >> "$LOG" 2>&1 &

echo "[ParkChat] Server started with PID $!"
echo "[ParkChat] Check logs: tail -f $LOG"

# ── For production, use Supervisor instead ──
# Install: sudo apt-get install supervisor
# Create /etc/supervisor/conf.d/parkchat.conf:
#
# [program:parkchat]
# command=php /var/www/html/chat/server/ChatServer.php
# autostart=true
# autorestart=true
# stderr_logfile=/var/log/parkchat.err.log
# stdout_logfile=/var/log/parkchat.out.log