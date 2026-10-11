#!/bin/bash
# Cron-based monitor script for shared hosting
# Add this to crontab to run every 5 minutes:
# */5 * * * * /path/to/daemons/cron_monitor.sh

SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
DAEMON_SCRIPT="$SCRIPT_DIR/lesson_notification_daemon.php"
LOG_FILE="$SCRIPT_DIR/monitor.log"
PID_FILE="$SCRIPT_DIR/daemon.pid"

# Function to log messages
log_message() {
    echo "[$(date '+%Y-%m-%d %H:%M:%S')] $1" >> "$LOG_FILE"
}

# Check if daemon is running
is_daemon_running() {
    if [ -f "$PID_FILE" ]; then
        PID=$(cat "$PID_FILE")
        if ps -p "$PID" > /dev/null 2>&1; then
            # Verify it's actually our daemon
            if ps -p "$PID" -o args | grep -q "lesson_notification_daemon.php"; then
                return 0
            fi
        fi
        # PID file exists but process is dead, remove stale PID file
        rm -f "$PID_FILE"
    fi
    return 1
}

# Start the daemon
start_daemon() {
    cd "$SCRIPT_DIR"
    nohup php "$DAEMON_SCRIPT" >> "$LOG_FILE" 2>&1 &
    DAEMON_PID=$!
    echo $DAEMON_PID > "$PID_FILE"
    sleep 2
    
    if is_daemon_running; then
        log_message "Daemon started successfully (PID: $DAEMON_PID)"
        return 0
    else
        log_message "ERROR: Failed to start daemon"
        rm -f "$PID_FILE"
        return 1
    fi
}

# Main monitoring logic
log_message "Monitor check started"

if is_daemon_running; then
    log_message "Daemon is running - OK"
else
    log_message "Daemon is NOT running - attempting to restart..."
    start_daemon
fi
