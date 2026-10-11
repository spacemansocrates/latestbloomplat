@echo off
REM Check Daemon Status
REM This script checks if the daemon is currently running

echo Checking Lesson Notification Daemon status...
echo.

tasklist /FI "IMAGENAME eq php.exe" 2>nul | find /I /N "php.exe">nul

if %ERRORLEVEL% EQU 0 (
    echo [RUNNING] Daemon is currently running
    echo.
    echo Active PHP processes:
    tasklist /FI "IMAGENAME eq php.exe"
) else (
    echo [STOPPED] Daemon is not running
)

echo.
pause
