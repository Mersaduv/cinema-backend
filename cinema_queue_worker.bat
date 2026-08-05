@echo off
setlocal

rem ============================================================
rem Cinema queue worker (long-running). Do NOT put this in Task
rem Scheduler directly. Started by cinema_task_scheduler.bat.
rem Unique marker: new-cinema-api + cinema queues (not CRM).
rem ============================================================

set "APP_DIR=D:\sites\cinema\new-cinema-api"
set "PHP_BIN=D:\php82\php.exe"
set "ARTISAN=%APP_DIR%\artisan"
set "LOG_FILE=%APP_DIR%\storage\logs\cinema_queue_worker.log"

if not exist "%LOG_FILE%" (
  type nul > "%LOG_FILE%"
)

if not exist "%PHP_BIN%" (
  >> "%LOG_FILE%" echo [%date% %time%] ERROR: PHP not found at %PHP_BIN%
  exit /b 2
)

if not exist "%ARTISAN%" (
  >> "%LOG_FILE%" echo [%date% %time%] ERROR: artisan not found at %ARTISAN%
  exit /b 2
)

cd /d "%APP_DIR%"

:loop
>> "%LOG_FILE%" echo [%date% %time%] Starting cinema queue worker...
"%PHP_BIN%" "%ARTISAN%" queue:work database --queue=high,default,low,media-processing --sleep=3 --tries=3 --timeout=180 --max-time=3600 --memory=512 >> "%LOG_FILE%" 2>&1
set "EXIT_CODE=%ERRORLEVEL%"
>> "%LOG_FILE%" echo [%date% %time%] Worker exited with code %EXIT_CODE%. Restarting in 5 seconds...
ping -n 6 127.0.0.1 >nul
goto loop

endlocal
