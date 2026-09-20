 @echo off
setlocal enabledelayedexpansion
title SeaPass Cloud Server Launcher

echo ======================================================================
echo        SeaPass Backend + ngrok Tunnel Launcher
echo ======================================================================
echo.

:: ── 1. Free Port 8000 ────────────────────────────────────────────────────
echo [1/3] Freeing port 8000 if occupied...
for /f "tokens=5" %%a in ('netstat -aon ^| findstr ":8000" ^| findstr "LISTENING"') do (
    taskkill /F /PID %%a >nul 2>&1
)

:: ── 2. USB ADB Reverse (for any USB-connected phones) ─────────────────────
where adb >nul 2>&1
if %errorLevel% equ 0 (
    adb devices | findstr /R /C:"[0-9a-zA-Z].*device$" >nul 2>&1
    if %errorLevel% equ 0 (
        adb reverse tcp:8000 tcp:8000 >nul 2>&1
        echo [OK] ADB reverse set for USB-connected phone
    ) else (
        echo [--] No USB phone detected. Skipping ADB reverse.
    )
) else (
    echo [--] ADB not found in PATH. Skipping ADB reverse.
)

:: ── 3. Start ngrok Tunnel in background ──────────────────────────────────
echo [2/3] Starting ngrok Tunnel (permanent domain)...
start "ngrok Tunnel" /MIN ngrok http 8000 --domain=overplant-theology-chomp.ngrok-free.dev
timeout /t 3 /nobreak >nul

:: ── 4. Start Laravel Backend ─────────────────────────────────────────────
echo [3/3] Starting Laravel Backend on port 8000...
echo.
echo ======================================================================
echo   SERVER IS LIVE WORLDWIDE (via ngrok):
echo   Public URL:   https://overplant-theology-chomp.ngrok-free.dev
echo   Admin Panel:  https://overplant-theology-chomp.ngrok-free.dev/admin/dashboard
echo   API Health:   https://overplant-theology-chomp.ngrok-free.dev/api/fares
echo   Local (USB):  http://127.0.0.1:8000
echo ======================================================================
echo.
echo   TIP: Keep this window AND the 'ngrok Tunnel' window open during demo.
echo   Press Ctrl+C to stop the Laravel server.
echo.

cd /d "%~dp0.."
php artisan serve --host=127.0.0.1 --port=8000
