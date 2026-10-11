#!/bin/bash
# Interactive cron setup script for shared hosting
# This script helps you set up the cron job with the correct path

echo "========================================"
echo "TutSys Daemon - Cron Setup Helper"
echo "========================================"
echo ""

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
MONITOR_SCRIPT="$SCRIPT_DIR/cron_monitor.sh"

echo "Detected paths:"
echo "  Daemons directory: $SCRIPT_DIR"
echo "  Monitor script:   $MONITOR_SCRIPT"
echo ""

# Check if monitor script exists
if [ ! -f "$MONITOR_SCRIPT" ]; then
    echo "ERROR: Monitor script not found at $MONITOR_SCRIPT"
    exit 1
fi

# Make it executable
chmod +x "$MONITOR_SCRIPT"
echo "✓ Made monitor script executable"
echo ""

# Check if PHP is available
PHP_PATH=$(which php 2>/dev/null)
if [ -z "$PHP_PATH" ]; then
    echo "WARNING: PHP not found in PATH. You may need to specify full PHP path."
    echo "Please enter PHP path (e.g., /usr/bin/php or /opt/cpanel/ea-php81/root/usr/bin/php):"
    read -r PHP_PATH
    if [ ! -f "$PHP_PATH" ]; then
        echo "ERROR: PHP not found at $PHP_PATH"
        exit 1
    fi
else
    echo "✓ PHP found at: $PHP_PATH"
fi
echo ""

# Show current crontab
echo "Current crontab entries:"
echo "------------------------"
crontab -l 2>/dev/null || echo "(No crontab entries found)"
echo ""

# Check if cron job already exists
if crontab -l 2>/dev/null | grep -q "cron_monitor.sh"; then
    echo "⚠ WARNING: A cron job for cron_monitor.sh already exists!"
    echo ""
    echo "Existing entry:"
    crontab -l 2>/dev/null | grep "cron_monitor.sh"
    echo ""
    read -p "Do you want to replace it? (y/n): " -n 1 -r
    echo ""
    if [[ ! $REPLY =~ ^[Yy]$ ]]; then
        echo "Cancelled. No changes made."
        exit 0
    fi
    
    # Remove old entry
    crontab -l 2>/dev/null | grep -v "cron_monitor.sh" | crontab -
    echo "✓ Removed old cron entry"
fi

# Create new crontab entry
CRON_ENTRY="*/5 * * * * $MONITOR_SCRIPT"

echo "Adding cron job:"
echo "  $CRON_ENTRY"
echo ""

# Add to crontab
(crontab -l 2>/dev/null; echo "$CRON_ENTRY") | crontab -

if [ $? -eq 0 ]; then
    echo "✓ Cron job added successfully!"
    echo ""
    echo "The daemon monitor will run every 5 minutes."
    echo ""
    echo "To verify:"
    echo "  crontab -l"
    echo ""
    echo "To remove later:"
    echo "  crontab -e  (then delete the line)"
    echo ""
else
    echo "✗ ERROR: Failed to add cron job"
    exit 1
fi
