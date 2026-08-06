@echo off
setlocal EnableExtensions EnableDelayedExpansion

rem ============================================================
rem Cinema Task Scheduler entry (run every 1 minute)
rem
rem What it does:
rem   1) php artisan schedule:run  (publish, recover pipelines, TMDB, …)
rem   2) Ensures cinema queue worker is alive (starts if missing)
rem
rem Safe vs other projects on this server:
rem   - CRM uses --queue=crm          (other path)
rem   - This worker uses new-cinema-api + queues:
rem       high,default,low,media-processing
rem ============================================================

set "APP_DIR=D:\sites\cinema\new-cinema-api"
set "PHP_BIN=D:\php82\php.exe"
set "ARTISAN=%APP_DIR%\artisan"
set "WORKER_BAT=%APP_DIR%\cinema_queue_worker.bat"
set "WORKER_VBS=%APP_DIR%\cinema_queue_worker_hidden.vbs"
set "LOG_FILE=%APP_DIR%\storage\logs\cinema_scheduler.log"
set "WORKER_LOG=%APP_DIR%\storage\logs\cinema_queue_worker.log"
set "DETECT_PS1=%APP_DIR%\cinema_detect_worker.ps1"

if not exist "%APP_DIR%\storage\logs" mkdir "%APP_DIR%\storage\logs"
if not exist "%LOG_FILE%" type nul > "%LOG_FILE%"
if not exist "%WORKER_LOG%" type nul > "%WORKER_LOG%"

if not exist "%PHP_BIN%" (
  >> "%LOG_FILE%" echo [%date% %time%] ERROR: PHP not found at %PHP_BIN%
  exit /b 2
)

if not exist "%ARTISAN%" (
  >> "%LOG_FILE%" echo [%date% %time%] ERROR: artisan not found at %ARTISAN%
  exit /b 2
)

cd /d "%APP_DIR%"

rem --- 1) Laravel scheduler (always, short-lived) ---
"%PHP_BIN%" "%ARTISAN%" schedule:run >> "%LOG_FILE%" 2>&1
set "SCHED_EXIT=!ERRORLEVEL!"
if not "!SCHED_EXIT!"=="0" (
  >> "%LOG_FILE%" echo [%date% %time%] WARN: schedule:run exit=!SCHED_EXIT!
)

rem --- 2) Ensure cinema queue worker is running ---
set "WORKER_RUNNING=0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%DETECT_PS1%" >nul 2>&1
if "!ERRORLEVEL!"=="0" set "WORKER_RUNNING=1"

if "!WORKER_RUNNING!"=="1" (
  >> "%LOG_FILE%" echo [%date% %time%] OK: schedule ran; cinema queue worker already running
  exit /b 0
)

if not exist "%WORKER_BAT%" (
  >> "%LOG_FILE%" echo [%date% %time%] ERROR: worker bat missing: %WORKER_BAT%
  exit /b 3
)

>> "%LOG_FILE%" echo [%date% %time%] Watchdog: cinema queue worker NOT running. Starting (hidden)...
if exist "%WORKER_VBS%" (
  wscript.exe //B "%WORKER_VBS%"
) else (
  start "" /MIN cmd.exe /c ""%WORKER_BAT%""
)

ping -n 4 127.0.0.1 >nul

powershell -NoProfile -ExecutionPolicy Bypass -File "%DETECT_PS1%" >nul 2>&1
if "!ERRORLEVEL!"=="0" (
  >> "%LOG_FILE%" echo [%date% %time%] OK: cinema queue worker started
  exit /b 0
)

>> "%LOG_FILE%" echo [%date% %time%] ERROR: failed to start cinema queue worker
exit /b 4

endlocal
