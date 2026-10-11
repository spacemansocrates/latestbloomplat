#!/bin/bash
# Fix permissions and start daemon
# Run this script to fix common issues and start the daemon

echo "========================================"
echo "TutSys Daemon - Fix and Start"
echo "========================================"
echo ""

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
cd "$SCRIPT_DIR"

# Fix line endings for all shell scripts
echo "1. Fixing line endings..."
for file in *.sh; do
    if [ -f "$file" ]; then
        sed -i 's/\r$//' "$file"
        echo "   ✓ Fixed: $file"
    fi
done
echo ""

# Make all shell scripts executable
echo "2. Making scripts executable..."
chmod +x *.sh
echo "   ✓ All .sh files are now executable"
echo ""

# Remove stale PID file if it exists
echo "3. Cleaning up stale PID file..."
if [ -f "daemon.pid" ]; then
    OLD_PID=$(cat daemon.pid)
    if ! ps -p "$OLD_PID" > /dev/null 2>&1; then
        rm -f daemon.pid
        echo "   ✓ Removed stale PID file"
    else
        echo "   ⚠ PID file exists and process is running (PID: $OLD_PID)"
        echo "   If you want to restart, stop it first: kill $OLD_PID"
    fi
else
    echo "   ✓ No stale PID file found"
fi
echo ""

# Check if daemon is already running
echo "4. Checking if daemon is already running..."
if ps aux | grep "lesson_notification_daemon.php" | grep -v grep > /dev/null; then
    echo "   ⚠ Daemon appears to be running already"
    ps aux | grep "lesson_notification_daemon.php" | grep -v grep
    echo ""
    read -p "Do you want to start a new instance anyway? (y/n): " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Cancelled."
        exit 0
    fi
else
    echo "   ✓ No daemon process found"
fi
echo ""

# Test database connection first
echo "5. Testing database connection..."
if php -r "require 'check_db_config.php';" 2>/dev/null; then
    echo "   ✓ Database connection test passed"
else
    echo "   ⚠ Database connection test failed"
    echo "   The daemon will still start but may not work until database is configured."
    echo "   Run: php check_db_config.php"
fi
echo ""

# Start the daemon
echo "6. Starting daemon..."
nohup php lesson_notification_daemon.php > daemon.log 2>&1 &
DAEMON_PID=$!
echo $DAEMON_PID > daemon.pid

sleep 2

# Verify it started
if ps -p "$DAEMON_PID" > /dev/null 2>&1; then
    echo "   ✓ Daemon started successfully (PID: $DAEMON_PID)"
    echo ""
    echo "========================================"
    echo "Daemon Status"
    echo "========================================"
    ps -p "$DAEMON_PID" -o pid,ppid,user,start,time,cmd
    echo ""
    echo "View logs: tail -f daemon.log"
    echo "Check status: ./check_status.sh"
else
    echo "   ✗ Daemon failed to start"
    echo ""
    echo "Check the error log:"
    tail -20 daemon_error.log
    exit 1
fi
