#!/bin/bash
# Check daemon status script for Linux
# Usage: ./check_status.sh

echo "========================================"
echo "TutSys Daemon Status Check"
echo "========================================"
echo ""

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
DAEMON_SCRIPT="$SCRIPT_DIR/lesson_notification_daemon.php"
PID_FILE="$SCRIPT_DIR/daemon.pid"

# Check if PID file exists
if [ -f "$PID_FILE" ]; then
    PID=$(cat "$PID_FILE")
    echo "PID file found: $PID_FILE"
    echo "Stored PID: $PID"
    echo ""
    
    # Check if process is running
    if ps -p "$PID" > /dev/null 2>&1; then
        echo "✓ Daemon is RUNNING (PID: $PID)"
        echo ""
        echo "Process details:"
        ps -p "$PID" -o pid,ppid,user,start,time,cmd
        echo ""
        
        # Verify it's actually our daemon
        if ps -p "$PID" -o args | grep -q "lesson_notification_daemon.php"; then
            echo "✓ Verified: This is the correct daemon process"
        else
            echo "⚠ WARNING: PID exists but doesn't match daemon script"
        fi
    else
        echo "✗ Daemon is NOT running (stale PID file)"
        echo "  The PID file exists but the process is not running."
        echo "  You may want to remove the stale PID file: rm $PID_FILE"
    fi
else
    echo "PID file not found: $PID_FILE"
    echo ""
    
    # Check for any running daemon process
    echo "Checking for any running daemon processes..."
    DAEMON_PROCESSES=$(ps aux | grep "lesson_notification_daemon.php" | grep -v grep)
    
    if [ -z "$DAEMON_PROCESSES" ]; then
        echo "✗ Daemon is NOT running"
        echo ""
        echo "No daemon processes found."
    else
        echo "⚠ Found daemon process(es) but no PID file:"
        echo "$DAEMON_PROCESSES"
        echo ""
        echo "You may want to create a PID file or restart the daemon properly."
    fi
fi

echo ""
echo "========================================"
echo "Log Files"
echo "========================================"
echo ""

# Check log files
if [ -f "$SCRIPT_DIR/daemon.log" ]; then
    LOG_SIZE=$(stat -f%z "$SCRIPT_DIR/daemon.log" 2>/dev/null || stat -c%s "$SCRIPT_DIR/daemon.log" 2>/dev/null || echo "unknown")
    echo "daemon.log: exists ($LOG_SIZE bytes)"
    echo "Last 5 lines:"
    tail -n 5 "$SCRIPT_DIR/daemon.log" 2>/dev/null || echo "  (empty or unreadable)"
else
    echo "daemon.log: not found"
fi

echo ""

if [ -f "$SCRIPT_DIR/daemon_error.log" ]; then
    ERROR_SIZE=$(stat -f%z "$SCRIPT_DIR/daemon_error.log" 2>/dev/null || stat -c%s "$SCRIPT_DIR/daemon_error.log" 2>/dev/null || echo "unknown")
    echo "daemon_error.log: exists ($ERROR_SIZE bytes)"
    if [ "$ERROR_SIZE" != "0" ] && [ "$ERROR_SIZE" != "unknown" ]; then
        echo "Last 5 lines:"
        tail -n 5 "$SCRIPT_DIR/daemon_error.log" 2>/dev/null || echo "  (empty or unreadable)"
    fi
else
    echo "daemon_error.log: not found"
fi

echo ""

if [ -f "$SCRIPT_DIR/monitor.log" ]; then
    MONITOR_SIZE=$(stat -f%z "$SCRIPT_DIR/monitor.log" 2>/dev/null || stat -c%s "$SCRIPT_DIR/monitor.log" 2>/dev/null || echo "unknown")
    echo "monitor.log: exists ($MONITOR_SIZE bytes)"
    if [ "$MONITOR_SIZE" != "0" ] && [ "$MONITOR_SIZE" != "unknown" ]; then
        echo "Last 5 lines:"
        tail -n 5 "$SCRIPT_DIR/monitor.log" 2>/dev/null || echo "  (empty or unreadable)"
    fi
else
    echo "monitor.log: not found"
fi

echo ""
echo "========================================"
echo "Quick Commands"
echo "========================================"
echo ""
echo "To start daemon:"
echo "  cd $SCRIPT_DIR"
echo "  nohup php lesson_notification_daemon.php > daemon.log 2>&1 &"
echo "  echo \$! > daemon.pid"
echo ""
echo "To stop daemon:"
echo "  kill \$(cat $PID_FILE)"
echo ""
echo "To view live logs:"
echo "  tail -f $SCRIPT_DIR/daemon.log"
echo ""
