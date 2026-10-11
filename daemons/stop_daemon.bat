@echo off
REM Stop Lesson Notification Daemon
REM This script stops all running PHP daemon processes

echo Stopping Lesson Notification Daemon...

REM Kill all PHP processes (be careful - this will kill ALL PHP processes)
taskkill /F /IM php.exe /T 2>nul

if %ERRORLEVEL% EQU 0 (
    echo Daemon stopped successfully!
) else (
    echo No daemon processes found or already stopped.
)

pause
