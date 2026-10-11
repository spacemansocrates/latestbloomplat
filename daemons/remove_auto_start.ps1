# PowerShell script to remove Windows Task Scheduler tasks
# Run this script as Administrator to remove automatic daemon management

Write-Host "========================================" -ForegroundColor Cyan
Write-Host "TutSys Daemon Auto-Start Removal" -ForegroundColor Cyan
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

# Task names
$startTaskName = "TutSys_Daemon_Start"
$monitorTaskName = "TutSys_Daemon_Monitor"

Write-Host "Removing tasks..." -ForegroundColor Yellow

# Remove startup task
$result = schtasks /Delete /TN $startTaskName /F 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Host "✓ Removed startup task" -ForegroundColor Green
} else {
    Write-Host "✗ Startup task not found or already removed" -ForegroundColor Yellow
}

# Remove monitor task
$result = schtasks /Delete /TN $monitorTaskName /F 2>&1
if ($LASTEXITCODE -eq 0) {
    Write-Host "✓ Removed monitor task" -ForegroundColor Green
} else {
    Write-Host "✗ Monitor task not found or already removed" -ForegroundColor Yellow
}

Write-Host ""
Write-Host "Tasks removed successfully!" -ForegroundColor Green
Write-Host "The daemon will no longer start automatically." -ForegroundColor Yellow
Write-Host ""

pause
