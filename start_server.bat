 @echo off
setlocal enabledelayedexpansion

echo ======================================================================
echo           SeaPass Backend & Device Connector Launcher
echo ======================================================================
echo.

:: 1. Detect PC Wi-Fi IP Address
for /f "delims=" %%i in ('powershell -NoProfile -Command "(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.InterfaceAlias -like '*Wi-Fi*' -or $_.InterfaceAlias -like '*Wireless*' } | Select-Object -ExpandProperty IPAddress -First 1)"') do (
    set PC_IP=%%i
)

if "%PC_IP%"=="" (
    for /f "delims=" %%i in ('powershell -NoProfile -Command "(Get-NetIPAddress -AddressFamily IPv4 | Where-Object { $_.IPAddress -notlike '127.*' -and $_.IPAddress -notlike '169.254.*' } | Select-Object -ExpandProperty IPAddress -First 1)"') do (
        set PC_IP=%%i
    )
)

echo [1/3] Detected Host PC IP: %PC_IP%

:: 2. Check for USB Android Device and apply ADB reverse
echo [2/3] Checking for connected Android devices via USB...
where adb >nul 2>&1
if %errorLevel% equ 0 (
    adb devices | findstr /R /C:"[0-9a-zA-Z].*device$" >nul 2>&1
    if %errorLevel% equ 0 (
        echo       Android device detected via USB!
        adb reverse tcp:8000 tcp:8000 >nul 2>&1
        echo       [OK] ADB reverse set: phone 127.0.0.1:8000 -^> PC:8000
    ) else (
        echo       (No USB Android device detected. If using USB, plug in phone with USB Debugging enabled)
    )
) else (
    echo       (ADB command not found in PATH. Skipping ADB reverse)
)

:: 3. Terminate any previous lingering PHP server processes on port 8000
echo [3/3] Freeing port 8000 if occupied...
for /f "tokens=5" %%a in ('netstat -aon ^| findstr ":8000" ^| findstr "LISTENING"') do (
    taskkill /F /PID %%a >nul 2>&1
)

echo.
echo ======================================================================
echo   READY FOR CONNECTIONS:
echo   - USB Cable (Fastest): http://127.0.0.1:8000/api
echo   - Wi-Fi Local Network: http://%PC_IP%:8000/api
echo ======================================================================
echo.
echo Starting Laravel backend on 0.0.0.0:8000 (Ctrl+C to stop)...
echo.

cd /d "%~dp0SeaPass"
php artisan serve --host=0.0.0.0 --port=8000
