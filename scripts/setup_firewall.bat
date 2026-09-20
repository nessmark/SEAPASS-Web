@echo off
:: Batch script to open TCP Port 8000 in Windows Defender Firewall with auto-elevation

echo ======================================================================
echo    SeaPass - Windows Defender Firewall Port 8000 Configurator
echo ======================================================================
echo.

:: Check for Administrator permissions
net session >nul 2>&1
if %errorLevel% neq 0 (
    echo Requesting Administrator privileges to modify firewall...
    powershell -Command "Start-Process cmd -ArgumentList '/c \"\"%~f0\"\"' -Verb RunAs"
    exit /b
)

echo Adding inbound rule for TCP Port 8000...
netsh advfirewall firewall add rule name="Laravel SeaPass (Port 8000)" dir=in action=allow protocol=TCP localport=8000 profile=any

if %errorLevel% equ 0 (
    echo.
    echo [SUCCESS] Port 8000 is now open in Windows Defender Firewall!
    echo External devices (phones/emulators) on your Wi-Fi can now connect.
) else (
    echo.
    echo [ERROR] Failed to add firewall rule. Please run this script as Administrator.
)

echo.
pause
