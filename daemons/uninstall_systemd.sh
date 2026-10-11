#!/bin/bash
# Uninstallation script for systemd service
# Run this script as root or with sudo

set -e

echo "========================================"
echo "TutSys Daemon - Systemd Uninstallation"
echo "========================================"
echo ""

# Check if running as root
if [ "$EUID" -ne 0 ]; then 
    echo "ERROR: This script must be run as root or with sudo"
    echo "Usage: sudo ./uninstall_systemd.sh"
    exit 1
fi

# Stop and disable service
echo "Stopping daemon service..."
systemctl stop tutsys-daemon.service 2>/dev/null || true

echo "Disabling service..."
systemctl disable tutsys-daemon.service 2>/dev/null || true

# Remove service file
SERVICE_FILE="/etc/systemd/system/tutsys-daemon.service"
if [ -f "$SERVICE_FILE" ]; then
    echo "Removing service file..."
    rm "$SERVICE_FILE"
    echo "✓ Service file removed"
else
    echo "Service file not found (may already be removed)"
fi

# Reload systemd
echo "Reloading systemd daemon..."
systemctl daemon-reload
echo "✓ Systemd reloaded"
echo ""

echo "========================================"
echo "Uninstallation Complete!"
echo "========================================"
echo "The daemon service has been removed."
echo ""
