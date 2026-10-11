# PowerShell script to set up Windows Task Scheduler for automatic daemon startup
# Run this script as Administrator to set up automatic daemon management

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "TutSys Daemon Auto-Start Setup" -ForegroundColor Cyan
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""

# Check if running as Administrator
$isAdmin = ([Security.Principal.WindowsPrincipal] [Security.Principal.WindowsIdentity]::GetCurrent()).IsInRole([Security.Principal.WindowsBuiltInRole]::Administrator)
if (-not $isAdmin) {
    Write-Host "ERROR: This script must be run as Administrator!" -ForegroundColor Red
    Write-Host "Right-click PowerShell and select 'Run as Administrator'" -ForegroundColor Yellow
    pause
    exit 1
}

# Get script directory
$scriptDir = Split-Path -Parent $MyInvocation.MyCommand.Path
$projectRoot = Split-Path -Parent $scriptDir
$phpPath = (Get-Command php -ErrorAction SilentlyContinue).Source

if (-not $phpPath) {
    Write-Host "ERROR: PHP is not found in PATH!" -ForegroundColor Red
    Write-Host "Please ensure PHP is installed and added to your system PATH." -ForegroundColor Yellow
    pause
    exit 1
}

Write-Host "PHP found at: $phpPath" -ForegroundColor Green
Write-Host "Daemon directory: $scriptDir" -ForegroundColor Green
Write-Host ""

# Task names
$startTaskName = "TutSys_Daemon_Start"
$monitorTaskName = "TutSys_Daemon_Monitor"

# Remove existing tasks if they exist
Write-Host "Removing existing tasks (if any)..." -ForegroundColor Yellow
schtasks /Delete /TN $startTaskName /F 2>$null
schtasks /Delete /TN $monitorTaskName /F 2>$null

# Create startup task (runs on system boot)
Write-Host "Creating startup task..." -ForegroundColor Yellow
$startAction = "`"$phpPath`" `"$scriptDir\lesson_notification_daemon.php`""
$startTask = schtasks /Create /TN $startTaskName /TR $startAction /SC ONSTART /RU SYSTEM /RL HIGHEST /F

if ($LASTEXITCODE -eq 0) {
    Write-Host "✓ Startup task created successfully" -ForegroundColor Green
} else {
    Write-Host "✗ Failed to create startup task" -ForegroundColor Red
    pause
    exit 1
}

# Create monitor task (runs every 5 minutes)
Write-Host "Creating monitor task..." -ForegroundColor Yellow
$monitorAction = "`"$phpPath`" `"$scriptDir\daemon_monitor.php`""
$monitorTask = schtasks /Create /TN $monitorTaskName /TR $monitorAction /SC MINUTE /MO 5 /RU SYSTEM /RL HIGHEST /F

if ($LASTEXITCODE -eq 0) {
    Write-Host "✓ Monitor task created successfully" -ForegroundColor Green
} else {
    Write-Host "✗ Failed to create monitor task" -ForegroundColor Red
    pause
    exit 1
}

Write-Host ""
Write-Host "========================================" -ForegroundColor Cyan
Write-Host "Setup Complete!" -ForegroundColor Green
Write-Host "========================================" -ForegroundColor Cyan
Write-Host ""
Write-Host "The daemon will now:" -ForegroundColor Yellow
Write-Host "  1. Start automatically when Windows boots" -ForegroundColor White
Write-Host "  2. Be monitored every 5 minutes and restarted if it stops" -ForegroundColor White
Write-Host ""
Write-Host "To verify tasks, run:" -ForegroundColor Yellow
Write-Host "  schtasks /Query /TN $startTaskName" -ForegroundColor White
Write-Host "  schtasks /Query /TN $monitorTaskName" -ForegroundColor White
Write-Host ""
Write-Host "To remove tasks later, run:" -ForegroundColor Yellow
Write-Host "  schtasks /Delete /TN $startTaskName /F" -ForegroundColor White
Write-Host "  schtasks /Delete /TN $monitorTaskName /F" -ForegroundColor White
Write-Host ""

pause
