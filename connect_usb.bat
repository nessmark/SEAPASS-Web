@echo off
echo ======================================================================
echo          SeaPass - ADB Reverse Port Forwarding Helper
echo ======================================================================
echo.
adb devices
echo.
echo Forwarding phone's localhost:8000 to PC:8000...
adb reverse tcp:8000 tcp:8000
if %errorLevel% equ 0 (
    echo.
    echo [SUCCESS] Port 8000 reverse port forwarding is active!
    echo Your phone can now reach the Laravel server at:
    echo   http://127.0.0.1:8000/api
) else (
    echo.
    echo [ERROR] Failed to run adb reverse.
    echo Ensure your phone is connected with USB Debugging enabled in Developer Options.
)
echo.
pause
