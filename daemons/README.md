# TutSys Daemon - Auto-Start Setup

This directory contains the Lesson Notification Daemon and scripts to keep it running automatically on **Windows (local development)** and **Linux (hosted servers)**.

## Overview

The daemon continuously monitors for upcoming lessons and sends email notifications to students and tutors 15 minutes before lessons start.

---

## 🪟 Windows Setup (Local Development)

*For XAMPP/WAMP local development environments*

## Quick Start - Automatic Setup (Recommended)

### Setup Auto-Start (Run Once)

1. **Open PowerShell as Administrator**
   - Right-click on PowerShell
   - Select "Run as Administrator"

2. **Navigate to the daemons directory**
   ```powershell
   cd C:\xampp\htdocs\tutsys3.0\daemons
   ```

3. **Run the setup script**
   ```powershell
   .\setup_auto_start.ps1
   ```

This will:
- Create a Windows Task Scheduler task that starts the daemon on system boot
- Create a monitor task that checks every 5 minutes and restarts the daemon if it stops
- Ensure the daemon is always running

### Remove Auto-Start

If you want to remove the automatic startup:

1. **Open PowerShell as Administrator**
2. **Navigate to the daemons directory**
3. **Run the removal script**
   ```powershell
   .\remove_auto_start.ps1
   ```

## Manual Management

### Start Daemon Manually
```batch
start_daemon.bat
```

### Stop Daemon Manually
```batch
stop_daemon.bat
```

### Check Daemon Status
```batch
check_status.bat
```

### Run Monitor Manually
```batch
php daemon_monitor.php
```

## Files

- **lesson_notification_daemon.php** - Main daemon script that runs continuously
- **daemon_monitor.php** - Monitor script that checks if daemon is running and restarts it
- **start_daemon.bat** - Manual start script
- **stop_daemon.bat** - Manual stop script
- **check_status.bat** - Status check script
- **setup_auto_start.ps1** - PowerShell script to set up automatic startup (run as Admin)
- **remove_auto_start.ps1** - PowerShell script to remove automatic startup (run as Admin)

## Logs

- **daemon.log** - Main daemon output log (auto-cleared every 10 minutes)
- **daemon_error.log** - Error log for the daemon
- **monitor.log** - Monitor script log

## How It Works

1. **On System Boot**: Windows Task Scheduler automatically starts the daemon
2. **Every 5 Minutes**: The monitor script checks if the daemon is running
3. **If Stopped**: The monitor automatically restarts the daemon
4. **Continuous Operation**: The daemon checks for upcoming lessons every 60 seconds

## Troubleshooting

### Daemon Not Starting

1. Check if PHP is in your system PATH:
   ```powershell
   php -v
   ```

2. Check Task Scheduler:
   - Open Task Scheduler (taskschd.msc)
   - Look for tasks: `TutSys_Daemon_Start` and `TutSys_Daemon_Monitor`
   - Check their last run time and status

3. Check logs:
   - Review `daemon.log` for errors
   - Review `daemon_error.log` for PHP errors
   - Review `monitor.log` for monitor activity

### Verify Tasks Are Running

```powershell
# Check startup task
schtasks /Query /TN TutSys_Daemon_Start

# Check monitor task
schtasks /Query /TN TutSys_Daemon_Monitor
```

### Manual Task Creation (Alternative)

If the PowerShell script doesn't work, you can create tasks manually:

1. Open Task Scheduler (taskschd.msc)
2. Create Basic Task:
   - **Name**: TutSys_Daemon_Start
   - **Trigger**: When the computer starts
   - **Action**: Start a program
   - **Program**: `php.exe`
   - **Arguments**: `"C:\xampp\htdocs\tutsys3.0\daemons\lesson_notification_daemon.php"`
   - **Start in**: `C:\xampp\htdocs\tutsys3.0\daemons`
   - **Security**: Run whether user is logged on or not, Run with highest privileges

3. Create another task for monitoring:
   - **Name**: TutSys_Daemon_Monitor
   - **Trigger**: Repeat task every 5 minutes
   - **Action**: Start a program
   - **Program**: `php.exe`
   - **Arguments**: `"C:\xampp\htdocs\tutsys3.0\daemons\daemon_monitor.php"`
   - **Start in**: `C:\xampp\htdocs\tutsys3.0\daemons`
   - **Security**: Run whether user is logged on or not, Run with highest privileges

---

## 🐧 Linux Hosting Setup (Production Server)

*For VPS, dedicated servers, or cloud hosting with Linux*

### Option 1: Systemd Service (Recommended for VPS/Dedicated Servers)

Systemd is the standard service manager on modern Linux distributions (Ubuntu 16.04+, Debian 8+, CentOS 7+, etc.).

#### Installation

1. **SSH into your server** and navigate to the daemons directory:
   ```bash
   cd /var/www/html/tutsys3.0/daemons
   # Or wherever your project is located
   ```

2. **Make the installation script executable**:
   ```bash
   chmod +x install_systemd.sh
   ```

3. **Run the installation script as root**:
   ```bash
   sudo ./install_systemd.sh
   ```

The script will:
- Detect your PHP path and web server user
- Create a systemd service file
- Enable the service to start on boot
- Start the daemon immediately

#### Management Commands

```bash
# Check status
sudo systemctl status tutsys-daemon

# Start daemon
sudo systemctl start tutsys-daemon

# Stop daemon
sudo systemctl stop tutsys-daemon

# Restart daemon
sudo systemctl restart tutsys-daemon

# View logs
sudo journalctl -u tutsys-daemon -f

# Enable on boot
sudo systemctl enable tutsys-daemon

# Disable on boot
sudo systemctl disable tutsys-daemon
```

#### Uninstallation

```bash
sudo ./uninstall_systemd.sh
```

---

### Option 2: Supervisor (Alternative to Systemd)

Supervisor is useful if you prefer it over systemd or need more advanced process management.

#### Installation

1. **SSH into your server** and navigate to the daemons directory:
   ```bash
   cd /var/www/html/tutsys3.0/daemons
   ```

2. **Make the installation script executable**:
   ```bash
   chmod +x install_supervisor.sh
   ```

3. **Run the installation script as root**:
   ```bash
   sudo ./install_supervisor.sh
   ```

The script will install Supervisor if needed and configure the daemon.

#### Management Commands

```bash
# Check status
sudo supervisorctl status tutsys-daemon

# Start daemon
sudo supervisorctl start tutsys-daemon

# Stop daemon
sudo supervisorctl stop tutsys-daemon

# Restart daemon
sudo supervisorctl restart tutsys-daemon

# View logs
sudo supervisorctl tail tutsys-daemon
```

---

### Option 3: Cron-Based Monitor (For Shared Hosting)

If you're on shared hosting without root access, use this cron-based solution.

#### Easy Setup (Recommended)

**Use the automated setup script:**

```bash
cd /path/to/tutsys3.0/daemons
chmod +x setup_cron.sh
./setup_cron.sh
```

This script will:
- Find your correct path automatically
- Make the monitor script executable
- Add the cron job for you
- Verify everything is set up correctly

#### Manual Setup

**Step 1: Find Your Path**

You need to replace `/home/username/public_html/tutsys3.0/daemons/cron_monitor.sh` with your **actual absolute path**.

**Option A: Use the path finder script (easiest)**
```bash
cd /path/to/tutsys3.0/daemons
php find_path.php
```

This will show you the exact path to use.

**Option B: Find it manually**
```bash
# Navigate to your daemons directory
cd /path/to/tutsys3.0/daemons

# Get the absolute path
pwd

# This will show something like:
# /home/yourusername/public_html/tutsys3.0/daemons
```

**Common hosting paths:**
- **cPanel**: `/home/username/public_html/tutsys3.0/daemons/cron_monitor.sh`
- **Plesk**: `/var/www/vhosts/domain.com/httpdocs/tutsys3.0/daemons/cron_monitor.sh`
- **DirectAdmin**: `/home/username/domains/domain.com/public_html/tutsys3.0/daemons/cron_monitor.sh`
- **Custom VPS**: `/var/www/html/tutsys3.0/daemons/cron_monitor.sh`

**Step 2: Make Script Executable**
```bash
chmod +x cron_monitor.sh
```

**Step 3: Add to Crontab**

1. Open crontab editor:
   ```bash
   crontab -e
   ```

2. Add this line (replace with YOUR actual path from Step 1):
   ```cron
   */5 * * * * /home/YOUR_USERNAME/public_html/tutsys3.0/daemons/cron_monitor.sh
   ```
   
   **Example** (if your path is `/home/john/public_html/tutsys3.0/daemons`):
   ```cron
   */5 * * * * /home/john/public_html/tutsys3.0/daemons/cron_monitor.sh
   ```

3. Save and exit:
   - **nano editor**: Press `Ctrl+X`, then `Y`, then `Enter`
   - **vi editor**: Press `Esc`, type `:wq`, press `Enter`

**Step 4: Verify**

```bash
# Check your crontab entries
crontab -l

# Verify the script exists at that path
ls -la /home/YOUR_USERNAME/public_html/tutsys3.0/daemons/cron_monitor.sh
```

The monitor will check every 5 minutes and restart the daemon if it stops.

#### Manual Start (First Time)

Before the cron monitor can work, start the daemon once manually:

```bash
cd /path/to/tutsys3.0/daemons
nohup php lesson_notification_daemon.php > daemon.log 2>&1 &
echo $! > daemon.pid
```

After this, the cron monitor will keep it running automatically.

---

## 📋 Hosting Comparison

| Method | Best For | Root Access Required | Auto-Restart | Boot Start |
|--------|----------|---------------------|--------------|------------|
| **Systemd** | VPS, Dedicated Servers | ✅ Yes | ✅ Yes | ✅ Yes |
| **Supervisor** | VPS, Dedicated Servers | ✅ Yes | ✅ Yes | ✅ Yes |
| **Cron Monitor** | Shared Hosting | ❌ No | ✅ Yes | ⚠️ Manual |
| **Windows Task Scheduler** | Windows Local Dev | ✅ Yes (Admin) | ✅ Yes | ✅ Yes |

---

## 🔧 Configuration for Hosting

### Update Paths in Service Files

If your project is not in `/var/www/html/tutsys3.0`, you'll need to update paths:

1. **For systemd**: Edit `/etc/systemd/system/tutsys-daemon.service`:
   ```ini
   WorkingDirectory=/your/actual/path/tutsys3.0/daemons
   ExecStart=/usr/bin/php /your/actual/path/tutsys3.0/daemons/lesson_notification_daemon.php
   ```

2. **For supervisor**: Edit `/etc/supervisor/conf.d/tutsys-daemon.conf`:
   ```ini
   command=/usr/bin/php /your/actual/path/tutsys3.0/daemons/lesson_notification_daemon.php
   directory=/your/actual/path/tutsys3.0/daemons
   ```

3. **Reload the service**:
   ```bash
   # Systemd
   sudo systemctl daemon-reload
   sudo systemctl restart tutsys-daemon
   
   # Supervisor
   sudo supervisorctl reread
   sudo supervisorctl update
   ```

### Set Correct Web Server User

The installation scripts try to auto-detect the web server user. Common users:
- **Ubuntu/Debian**: `www-data`
- **CentOS/RHEL**: `apache` or `nginx`
- **Custom**: Check with `ps aux | grep -E 'apache|nginx|httpd'`

---

## 📁 Files Reference

### Windows Files
- `start_daemon.bat` - Manual start script
- `stop_daemon.bat` - Manual stop script
- `check_status.bat` - Status check script
- `setup_auto_start.ps1` - PowerShell setup script
- `remove_auto_start.ps1` - PowerShell removal script

### Linux Files
- `tutsys-daemon.service` - Systemd service file template
- `install_systemd.sh` - Systemd installation script
- `uninstall_systemd.sh` - Systemd removal script
- `supervisor_tutsys.conf` - Supervisor configuration template
- `install_supervisor.sh` - Supervisor installation script
- `cron_monitor.sh` - Cron-based monitor script
- `setup_cron.sh` - Interactive cron setup helper (shared hosting)
- `find_path.php` - Path finder script to help locate your project path

### Common Files
- `lesson_notification_daemon.php` - Main daemon script
- `daemon_monitor.php` - Monitor script (Windows/PHP-based)
- `daemon.log` - Main daemon output log
- `daemon_error.log` - Error log
- `monitor.log` - Monitor script log

---

## 🐛 Troubleshooting (Hosting)

### Daemon Not Starting

1. **Check PHP path**:
   ```bash
   which php
   php -v
   ```

2. **Check file permissions**:
   ```bash
   ls -la /path/to/tutsys3.0/daemons/
   # Ensure web server user can read/execute
   ```

3. **Check service status**:
   ```bash
   # Systemd
   sudo systemctl status tutsys-daemon
   sudo journalctl -u tutsys-daemon -n 50
   
   # Supervisor
   sudo supervisorctl status tutsys-daemon
   sudo supervisorctl tail tutsys-daemon
   ```

4. **Check logs**:
   ```bash
   tail -f /path/to/tutsys3.0/daemons/daemon.log
   tail -f /path/to/tutsys3.0/daemons/daemon_error.log
   ```

### Permission Issues

If you see permission errors:

```bash
# Set ownership to web server user
sudo chown -R www-data:www-data /path/to/tutsys3.0/daemons/

# Set executable permissions
chmod +x /path/to/tutsys3.0/daemons/*.sh
chmod +x /path/to/tutsys3.0/daemons/*.php
```

### Database Connection Issues

Ensure your database configuration in `config/db_connect.php` or `.env` is correct for your hosting environment.

---

## 📝 Notes

- The daemon runs continuously and checks for lessons every 60 seconds
- Logs are automatically cleared every 10 minutes to prevent disk space issues
- The monitor ensures the daemon restarts automatically if it crashes or stops
- On Linux, services run as the web server user for security
- For shared hosting, you may need to contact your host to enable long-running PHP processes
