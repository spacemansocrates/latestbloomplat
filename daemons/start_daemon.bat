@echo off
REM Start Lesson Notification Daemon
REM This script starts the daemon in the background

echo Starting Lesson Notification Daemon...

REM Get the directory where this script is located
set SCRIPT_DIR=%~dp0

REM Start PHP daemon in background, redirect output to log file
start /B php "%SCRIPT_DIR%lesson_notification_daemon.php" > "%SCRIPT_DIR%daemon.log" 2>&1

echo Daemon started successfully!
echo Check daemon.log for output.
echo.
echo To stop the daemon, run: stop_daemon.bat
pause
