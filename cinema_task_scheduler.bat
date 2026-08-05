@echo off
setlocal EnableExtensions

rem ============================================================
rem Cinema Task Scheduler entry (run every 1 minute)
rem
rem What it does:
rem   1) php artisan schedule:run  (publish, TMDB daily, cleanups)
rem   2) Ensures cinema queue worker is alive (starts if missing)
rem
rem Safe vs other projects on this server:
rem   - CRM uses --queue=crm          (D:\xamp\htdocs\CRM)
rem   - ApprovalForm uses approvalform-queue
rem   - This worker uses new-cinema-api path + queues:
rem       high,default,low,media-processing
rem   - Detection matches ONLY cinema artisan path / cinema_queue_worker.bat
rem
rem Task Scheduler settings (recommended):
rem   Program/script:  D:\sites\cinema\new-cinema-api\cinema_task_scheduler.bat
rem   Start in:        D:\sites\cinema\new-cinema-api
rem   Trigger:         Every 1 minute (or At startup + repeat every 1 min)
rem   Run whether user is logged on or not
rem   Do not start a new instance if already running
rem ============================================================

set "APP_DIR=D:\sites\cinema\new-cinema-api"
set "PHP_BIN=D:\php82\php.exe"
set "ARTISAN=%APP_DIR%\artisan"
set "WORKER_BAT=%APP_DIR%\cinema_queue_worker.bat"
set "LOG_FILE=%APP_DIR%\storage\logs\cinema_scheduler.log"
set "WORKER_LOG=%APP_DIR%\storage\logs\cinema_queue_worker.log"

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
set "SCHED_EXIT=%ERRORLEVEL%"
if not "%SCHED_EXIT%"=="0" (
  >> "%LOG_FILE%" echo [%date% %time%] WARN: schedule:run exit=%SCHED_EXIT%
)

rem --- 2) Ensure cinema queue worker is running (no CRM/Approval touch) ---
set "WORKER_RUNNING=0"

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$hit = Get-CimInstance Win32_Process | Where-Object { " ^
  "  ($_.Name -eq 'cmd.exe' -and $_.CommandLine -match 'cinema_queue_worker\.bat') -or " ^
  "  ($_.Name -eq 'php.exe' -and $_.CommandLine -match 'new-cinema-api\\artisan' -and $_.CommandLine -match 'queue:work' -and $_.CommandLine -match 'high,default,low,media-processing') " ^
  "}; if ($hit) { exit 0 } else { exit 1 }"

if "%ERRORLEVEL%"=="0" (
  set "WORKER_RUNNING=1"
)

if "%WORKER_RUNNING%"=="1" (
  >> "%LOG_FILE%" echo [%date% %time%] OK: schedule ran; cinema queue worker already running
  exit /b 0
)

if not exist "%WORKER_BAT%" (
  >> "%LOG_FILE%" echo [%date% %time%] ERROR: worker bat missing: %WORKER_BAT%
  exit /b 3
)

>> "%LOG_FILE%" echo [%date% %time%] Watchdog: cinema queue worker NOT running. Starting...
start "" /MIN cmd.exe /c ""%WORKER_BAT%""

rem brief settle, then verify (ping avoids Task Scheduler stdin issues with timeout)
ping -n 3 127.0.0.1 >nul

powershell -NoProfile -ExecutionPolicy Bypass -Command ^
  "$hit = Get-CimInstance Win32_Process | Where-Object { " ^
  "  ($_.Name -eq 'cmd.exe' -and $_.CommandLine -match 'cinema_queue_worker\.bat') -or " ^
  "  ($_.Name -eq 'php.exe' -and $_.CommandLine -match 'new-cinema-api\\artisan' -and $_.CommandLine -match 'queue:work' -and $_.CommandLine -match 'high,default,low,media-processing') " ^
  "}; if ($hit) { exit 0 } else { exit 1 }"

if "%ERRORLEVEL%"=="0" (
  >> "%LOG_FILE%" echo [%date% %time%] OK: cinema queue worker started
  exit /b 0
)

>> "%LOG_FILE%" echo [%date% %time%] ERROR: failed to start cinema queue worker
exit /b 4

endlocal
