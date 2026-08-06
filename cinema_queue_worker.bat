@echo off
setlocal EnableExtensions EnableDelayedExpansion

rem ============================================================
rem Cinema queue worker — ALWAYS-ON loop (never exits permanently)
rem
rem Started by:
rem   - Cinema Queue Worker (Windows Task, at startup + on failure)
rem   - cinema_task_scheduler.bat (watchdog every 1 minute)
rem
rem Unique marker: new-cinema-api + cinema queues (not CRM).
rem CRM uses --queue=crm under a different path.
rem ============================================================

set "APP_DIR=D:\sites\cinema\new-cinema-api"
set "PHP_BIN=D:\php82\php.exe"
set "ARTISAN=%APP_DIR%\artisan"
set "LOG_DIR=%APP_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\cinema_queue_worker.log"
set "PID_FILE=%LOG_DIR%\cinema_queue_worker.pid"
set "HEARTBEAT_FILE=%LOG_DIR%\cinema_queue_worker.heartbeat"
set "DETECT_PS1=%APP_DIR%\cinema_detect_php_worker.ps1"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"
if not exist "%LOG_FILE%" type nul > "%LOG_FILE%"

:loop
  rem --- prerequisites: never exit; retry forever ---
  if not exist "%PHP_BIN%" (
    >> "%LOG_FILE%" echo [%date% %time%] ERROR: PHP missing at %PHP_BIN%. Retry in 30s...
    ping -n 31 127.0.0.1 >nul
    goto loop
  )
  if not exist "%ARTISAN%" (
    >> "%LOG_FILE%" echo [%date% %time%] ERROR: artisan missing at %ARTISAN%. Retry in 30s...
    ping -n 31 127.0.0.1 >nul
    goto loop
  )

  rem If another php cinema worker is already running, wait (avoid duplicate consumers)
  if exist "%DETECT_PS1%" (
    powershell -NoProfile -ExecutionPolicy Bypass -File "%DETECT_PS1%" >nul 2>&1
    if "!ERRORLEVEL!"=="0" (
      >> "%LOG_FILE%" echo [%date% %time%] Another cinema php worker is active. Waiting 30s...
      echo %DATE% %TIME% standby > "%HEARTBEAT_FILE%"
      ping -n 31 127.0.0.1 >nul
      goto loop
    )
  )

  cd /d "%APP_DIR%"
  echo %DATE% %TIME% > "%HEARTBEAT_FILE%"
  echo %DATE% %TIME% wrapper > "%PID_FILE%"

  >> "%LOG_FILE%" echo [%date% %time%] Starting cinema queue worker...
  "%PHP_BIN%" "%ARTISAN%" queue:work database --queue=high,default,low,media-processing --sleep=3 --tries=3 --timeout=180 --max-time=3600 --memory=512 >> "%LOG_FILE%" 2>&1
  set "EXIT_CODE=!ERRORLEVEL!"

  >> "%LOG_FILE%" echo [%date% %time%] Worker exited with code !EXIT_CODE!. Restarting in 5 seconds...
  ping -n 6 127.0.0.1 >nul
  goto loop

endlocal
