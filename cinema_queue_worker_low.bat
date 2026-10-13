@echo off
setlocal EnableExtensions

rem Secondary cinema worker for low-priority jobs (person images, cache, revalidate).
rem Runs alongside cinema_queue_worker.bat so high/default pipeline jobs are not starved.

set "APP_DIR=D:\sites\cinema\new-cinema-api"
set "PHP_BIN=D:\php82\php.exe"
set "ARTISAN=%APP_DIR%\artisan"
set "LOG_DIR=%APP_DIR%\storage\logs"
set "LOG_FILE=%LOG_DIR%\cinema_queue_worker_low.log"
set "HEARTBEAT_FILE=%LOG_DIR%\cinema_queue_worker_low.heartbeat"

if not exist "%LOG_DIR%" mkdir "%LOG_DIR%"

:loop
  if not exist "%PHP_BIN%" (
    ping -n 31 127.0.0.1 >nul
    goto loop
  )
  cd /d "%APP_DIR%"
  echo %DATE% %TIME% > "%HEARTBEAT_FILE%"
  >> "%LOG_FILE%" echo [%date% %time%] Starting cinema LOW queue worker...
  "%PHP_BIN%" "%ARTISAN%" queue:work database --queue=low --sleep=2 --tries=3 --timeout=180 --max-time=3600 --memory=512 >> "%LOG_FILE%" 2>&1
  >> "%LOG_FILE%" echo [%date% %time%] Low worker exited. Restarting in 5s...
  ping -n 6 127.0.0.1 >nul
  goto loop

endlocal
