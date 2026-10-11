#!/bin/bash
# Installation script for Supervisor (alternative to systemd)
# Run this script as root or with sudo

set -e

echo "========================================"
echo "TutSys Daemon - Supervisor Installation"
echo "========================================"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "ERROR: This script must be run as root or with sudo"
    echo "Usage: sudo ./install_supervisor.sh"
    exit 1
fi

# Check if supervisor is installed
if ! command -v supervisorctl &> /dev/null; then
    echo "Supervisor is not installed. Installing..."
    
    if command -v apt-get &> /dev/null; then
        apt-get update
        apt-get install -y supervisor
    elif command -v yum &> /dev/null; then
        yum install -y supervisor
    else
        echo "ERROR: Cannot detect package manager. Please install supervisor manually."
        exit 1
    fi
fi

# Get the directory where this script is located
SCRIPT_DIR="$( cd "$( dirname "${BASH_SOURCE[0]}" )" && pwd )"
PROJECT_ROOT="$(dirname "$SCRIPT_DIR")"

# Detect PHP path
PHP_PATH=$(which php)
if [ -z "$PHP_PATH" ]; then
    echo "ERROR: PHP is not found in PATH!"
    exit 1
fi

echo "PHP found at: $PHP_PATH"
echo "Project root: $PROJECT_ROOT"
echo ""

# Detect web server user
if id "www-data" &>/dev/null; then
    WEB_USER="www-data"
elif id "apache" &>/dev/null; then
    WEB_USER="apache"
elif id "nginx" &>/dev/null; then
    WEB_USER="nginx"
else
    echo "Please enter the web server user (e.g., www-data, apache, nginx):"
    read -r WEB_USER
fi

echo "Using web server user: $WEB_USER"
echo ""

# Create supervisor config
SUPERVISOR_CONFIG="/etc/supervisor/conf.d/tutsys-daemon.conf"

echo "Creating supervisor configuration..."

# Replace placeholders
sed -e "s|/var/www/html/tutsys3.0|$PROJECT_ROOT|g" \
    -e "s|/usr/bin/php|$PHP_PATH|g" \
    -e "s|user=www-data|user=$WEB_USER|g" \
    "$SCRIPT_DIR/supervisor_tutsys.conf" > "$SUPERVISOR_CONFIG"

chmod 644 "$SUPERVISOR_CONFIG"

# Set ownership
chown -R "$WEB_USER:$WEB_USER" "$SCRIPT_DIR"

echo "✓ Supervisor config created: $SUPERVISOR_CONFIG"
echo ""

# Reload supervisor
echo "Reloading supervisor..."
supervisorctl reread
supervisorctl update
echo "✓ Supervisor reloaded"
echo ""

# Start the daemon
echo "Starting daemon..."
supervisorctl start tutsys-daemon
echo "✓ Daemon started"
echo ""

# Check status
echo "Checking status..."
supervisorctl status tutsys-daemon
echo ""

echo "========================================"
echo "Installation Complete!"
echo "========================================"
echo ""
echo "Useful commands:"
echo "  sudo supervisorctl status tutsys-daemon    # Check status"
echo "  sudo supervisorctl start tutsys-daemon     # Start daemon"
echo "  sudo supervisorctl stop tutsys-daemon      # Stop daemon"
echo "  sudo supervisorctl restart tutsys-daemon  # Restart daemon"
echo "  sudo supervisorctl tail tutsys-daemon      # View logs"
echo ""
