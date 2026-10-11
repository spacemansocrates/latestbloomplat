#!/bin/bash
# Installation script for systemd service (Linux hosting)
# Run this script as root or with sudo

set -e

echo "========================================"
echo "TutSys Daemon - Systemd Installation"
echo "========================================"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "ERROR: This script must be run as root or with sudo"
    echo "Usage: sudo ./install_systemd.sh"
    exit 1
fi

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Detect PHP path
PHP_PATH=$(which php)
if [ -z "$PHP_PATH" ]; then
    echo "ERROR: PHP is not found in PATH!"
    echo "Please install PHP first."
    exit 1
fi

echo "PHP found at: $PHP_PATH"
echo "Project root: $PROJECT_ROOT"
echo "Daemon directory: $SCRIPT_DIR"
echo ""

# Detect web server user (common: www-data, apache, nginx)
if id "www-data" &>/dev/null; then
    WEB_USER="www-data"
elif id "apache" &>/dev/null; then
    WEB_USER="apache"
elif id "nginx" &>/dev/null; then
    WEB_USER="nginx"
else
    echo "WARNING: Could not detect web server user."
    echo "Please enter the web server user (e.g., www-data, apache, nginx):"
    read -r WEB_USER
fi

echo "Using web server user: $WEB_USER"
echo ""

# Create systemd service file
SERVICE_FILE="/etc/systemd/system/tutsys-daemon.service"

echo "Creating systemd service file..."

# Replace placeholders in service file
sed -e "s|/var/www/html/tutsys3.0|$PROJECT_ROOT|g" \
    -e "s|/usr/bin/php|$PHP_PATH|g" \
    -e "s|User=www-data|User=$WEB_USER|g" \
    -e "s|Group=www-data|Group=$WEB_USER|g" \
    "$SCRIPT_DIR/tutsys-daemon.service" > "$SERVICE_FILE"

# Set proper permissions
chmod 644 "$SERVICE_FILE"

# Set ownership of daemon directory
chown -R "$WEB_USER:$WEB_USER" "$SCRIPT_DIR"

echo "✓ Service file created: $SERVICE_FILE"
echo ""

# Reload systemd
echo "Reloading systemd daemon..."
systemctl daemon-reload
echo "✓ Systemd reloaded"
echo ""

# Enable service to start on boot
echo "Enabling service to start on boot..."
systemctl enable tutsys-daemon.service
echo "✓ Service enabled"
echo ""

# Start the service
echo "Starting daemon service..."
systemctl start tutsys-daemon.service
echo "✓ Service started"
echo ""

# Check status
echo "Checking service status..."
systemctl status tutsys-daemon.service --no-pager -l
echo ""

echo "========================================"
echo "Installation Complete!"
echo "========================================"
echo ""
echo "The daemon is now running and will start automatically on boot."
echo ""
echo "Useful commands:"
echo "  sudo systemctl status tutsys-daemon    # Check status"
echo "  sudo systemctl start tutsys-daemon     # Start daemon"
echo "  sudo systemctl stop tutsys-daemon      # Stop daemon"
echo "  sudo systemctl restart tutsys-daemon   # Restart daemon"
echo "  sudo systemctl enable tutsys-daemon    # Enable on boot"
echo "  sudo systemctl disable tutsys-daemon   # Disable on boot"
echo "  sudo journalctl -u tutsys-daemon -f    # View logs"
echo ""
