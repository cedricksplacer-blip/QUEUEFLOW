@echo off
title QueueFlow TMC - Laravel and ngrok Launcher
color 0B
setlocal EnableExtensions EnableDelayedExpansion

echo ==========================================
echo   QUEUEFLOW TMC - SERVER STARTUP
echo ==========================================
echo.

REM ==========================================
REM CONFIGURATION
REM ==========================================
set "BACKEND=C:\QUEUEFLOW TMC\QueueFlow_TMC"
set "PORT=8000"
set "HEALTH_PATH=/api/v1/health"
set "LOCAL_HEALTH_URL=http://127.0.0.1:%PORT%%HEALTH_PATH%"

REM Keep the current reserved NGROK hostname here.
REM Set this to blank to use a temporary ngrok URL.
REM Hostname only; do not include https:// or a path.
set "NGROK_DOMAIN=spelling-ocelot-opposing.ngrok-free.dev"

REM ==========================================
REM CHECK LARAVEL PROJECT
REM ==========================================
if not exist "%BACKEND%\artisan" (
    echo ERROR: Laravel project not found:
    echo %BACKEND%
    goto :FAIL
)

REM ==========================================
REM CHECK PHP
REM ==========================================
where php >nul 2>&1
if errorlevel 1 (
    echo ERROR: PHP was not found in PATH.
    echo Add PHP to your Windows PATH.
    goto :FAIL
)

REM ==========================================
REM CHECK NGROK
REM ==========================================
where ngrok >nul 2>&1
if errorlevel 1 (
    echo ERROR: ngrok was not found in PATH.
    echo Install ngrok or add ngrok.exe to PATH.
    goto :FAIL
)

REM ==========================================
REM CHECK NGROK CONFIGURATION
REM ==========================================
echo Checking ngrok configuration...
ngrok config check
if errorlevel 1 (
    echo ERROR: ngrok configuration is invalid.
    goto :FAIL
)

REM ==========================================
REM CHECK / START LARAVEL
REM ==========================================
echo.
echo Checking Laravel health endpoint:
echo %LOCAL_HEALTH_URL%

call :CHECK_LOCAL_HEALTH
if "!HEALTH_OK!"=="1" goto :LARAVEL_READY

echo Laravel health endpoint is not responding.
echo Starting Laravel on port %PORT%...

REM Check if something is already listening on the port.
powershell -NoProfile -ExecutionPolicy Bypass -Command "$c=Get-NetTCPConnection -LocalPort %PORT% -State Listen -ErrorAction SilentlyContinue; if ($c) { exit 0 } else { exit 1 }"
if not errorlevel 1 (
    echo WARNING: Port %PORT% is already listening, but the health check failed.
    echo Check the existing process and Laravel logs before continuing.
    echo The launcher will not start a second server on the same port.
    goto :FAIL
)

start "QueueFlow Laravel API" cmd /k "cd /d ""%BACKEND%"" && php artisan serve --host=0.0.0.0 --port=%PORT%"

echo Waiting for Laravel health check...
set "READY=0"
for /L %%i in (1,1,20) do (
    timeout /t 2 /nobreak >nul
    call :CHECK_LOCAL_HEALTH
    if "!HEALTH_OK!"=="1" (
        set "READY=1"
        goto :LARAVEL_CHECKED
    )
)

:LARAVEL_CHECKED
if "!READY!"=="1" goto :LARAVEL_READY

echo.
echo ERROR: Laravel did not pass its health check.
echo Check the Laravel server window for errors.
echo Test manually:
echo %LOCAL_HEALTH_URL%
goto :FAIL

:LARAVEL_READY
echo.
echo SUCCESS: Laravel health endpoint returned HTTP 200.
echo.

REM ==========================================
REM CHECK FOR AN EXISTING NGROK TUNNEL
REM ==========================================
echo Checking the local ngrok inspector at http://127.0.0.1:4040 ...

set "EXISTING_PUBLIC_URL="
for /f "usebackq delims=" %%U in (`powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; try { $j=Invoke-RestMethod -Uri 'http://127.0.0.1:4040/api/tunnels' -TimeoutSec 3; $t=$j.tunnels | Where-Object { $_.proto -eq 'https' -and $_.public_url }; if ($t) { $t | ForEach-Object { $_.public_url } } } catch {}"`) do (
    if not defined EXISTING_PUBLIC_URL set "EXISTING_PUBLIC_URL=%%U"
)

if defined EXISTING_PUBLIC_URL goto :EXISTING_TUNNEL

REM If ngrok is running but the inspector has no active tunnel, do not launch
REM another process automatically; this helps avoid ERR_NGROK_334 conflicts.
tasklist /FI "IMAGENAME eq ngrok.exe" 2>nul | find /I "ngrok.exe" >nul
if not errorlevel 1 (
    echo.
    echo WARNING: ngrok.exe is already running, but no active HTTPS tunnel
    echo was found through http://127.0.0.1:4040.
    echo Inspect the existing ngrok window or stop that process before retrying.
    echo This launcher will not start a duplicate ngrok process.
    goto :DONE_WARNING
)

REM ==========================================
REM START NGROK
REM ==========================================
echo Starting ngrok HTTPS tunnel...

if defined NGROK_DOMAIN goto :START_RESERVED

start "QueueFlow ngrok Tunnel" cmd /k "ngrok http %PORT%"
goto :WAIT_FOR_TUNNEL

:START_RESERVED
start "QueueFlow ngrok Tunnel" cmd /k "ngrok http %PORT% --url=https://%NGROK_DOMAIN%"

:WAIT_FOR_TUNNEL
echo Waiting for ngrok to publish an HTTPS URL...
set "PUBLIC_URL="
for /L %%i in (1,1,15) do (
    timeout /t 2 /nobreak >nul
    for /f "usebackq delims=" %%U in (`powershell -NoProfile -ExecutionPolicy Bypass -Command "$ProgressPreference='SilentlyContinue'; try { $j=Invoke-RestMethod -Uri 'http://127.0.0.1:4040/api/tunnels' -TimeoutSec 2; $t=$j.tunnels | Where-Object { $_.proto -eq 'https' -and $_.public_url }; if ($t) { $t | ForEach-Object { $_.public_url } } } catch {}"`) do (
        if not defined PUBLIC_URL set "PUBLIC_URL=%%U"
    )
    if defined PUBLIC_URL goto :TUNNEL_FOUND
)
