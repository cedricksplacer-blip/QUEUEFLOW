@echo off
title QueueFlow TMC - Stop Laravel and ngrok
color 0C
setlocal EnableExtensions

echo ==========================================
echo   QUEUEFLOW TMC - STOP SERVER
echo ==========================================
echo.
echo This will stop the QueueFlow Laravel server
echo and ngrok processes started on this computer.
echo It will NOT delete project files or database data.
echo.

choice /C YN /N /M "Stop Laravel and ngrok now? [Y/N]: "
if errorlevel 2 goto :CANCEL

echo.
echo Stopping Laravel server on port 8000...

REM Stop only the process listening on port 8000, if identifiable.
powershell -NoProfile -ExecutionPolicy Bypass -Command ^
 "$ErrorActionPreference='SilentlyContinue';" ^
 "$connections=Get-NetTCPConnection -LocalPort 8000 -State Listen;" ^
 "if ($connections) {" ^
 "  $pids=$connections | Select-Object -ExpandProperty OwningProcess -Unique;" ^
 "  foreach ($procId in $pids) {" ^
 "    if ($procId -and $procId -ne 0 -and $procId -ne 4) {" ^
 "      $proc=Get-CimInstance Win32_Process -Filter ('ProcessId = ' + $procId);" ^
 "      if ($proc -and $proc.CommandLine -match 'artisan serve') {" ^
 "        Stop-Process -Id $procId -Force;" ^
 "        Write-Host ('Stopped Laravel artisan serve (PID ' + $procId + ').');" ^
 "      } else {" ^
 "        Write-Host ('Port 8000 is used by PID ' + $procId + ', not clearly identified as Laravel; left untouched.');" ^
 "      }" ^
 "    }" ^
 "  }" ^
 "} else { Write-Host 'No process is listening on port 8000.' }"

echo.
echo Stopping ngrok processes...

REM Stop ngrok.exe processes; this may also stop tunnels used by other projects.
tasklist /FI "IMAGENAME eq ngrok.exe" 2>nul | find /I "ngrok.exe" >nul
if errorlevel 1 (
    echo No ngrok.exe process found.
) else (
    echo WARNING: This stops all ngrok.exe processes on this computer.
    echo Any other project using ngrok will lose its tunnel.
    choice /C YN /N /M "Stop all ngrok.exe processes? [Y/N]: "
    if errorlevel 2 goto :SKIP_NGROK
    taskkill /IM ngrok.exe /T /F
    if errorlevel 1 (
        echo Could not stop ngrok automatically. Close its terminal window manually.
    ) else (
        echo ngrok processes stopped.
    )
)

:SKIP_NGROK
echo.
echo ==========================================
echo Stop operation finished.
echo ==========================================
echo If a Laravel or ngrok terminal remains open,
echo check it before closing it manually.
echo Your project files and database were not changed.
echo.
goto :END

:CANCEL
echo.
echo Cancelled. No processes were stopped.
echo.

:END
pause
endlocal
