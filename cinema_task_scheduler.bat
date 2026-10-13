@echo off
setlocal EnableExtensions EnableDelayedExpansion

rem ============================================================
rem Cinema Task Scheduler entry (run every 1 minute)
rem
rem What it does:
rem   1) php artisan schedule:run  (publish, recover pipelines, TMDB, …)
rem   2) Ensures primary queue worker is alive (high,default)
rem   3) Ensures low queue worker is alive (person images / cache)
rem ============================================================

set "APP_DIR=D:\sites\cinema\new-cinema-api"
set "PHP_BIN=D:\php82\php.exe"
set "ARTISAN=%APP_DIR%\artisan"
set "WORKER_BAT=%APP_DIR%\cinema_queue_worker.bat"
set "WORKER_VBS=%APP_DIR%\cinema_queue_worker_hidden.vbs"
set "LOW_BAT=%APP_DIR%\cinema_queue_worker_low.bat"
set "LOW_VBS=%APP_DIR%\cinema_queue_worker_low_hidden.vbs"
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

rem --- 2) Ensure primary cinema queue worker is running ---
set "WORKER_RUNNING=0"
powershell -NoProfile -ExecutionPolicy Bypass -File "%DETECT_PS1%" >nul 2>&1
if "!ERRORLEVEL!"=="0" set "WORKER_RUNNING=1"

if "!WORKER_RUNNING!"=="0" (
  if exist "%WORKER_VBS%" (
    >> "%LOG_FILE%" echo [%date% %time%] Watchdog: primary worker NOT running. Starting...
    wscript.exe //B "%WORKER_VBS%"
  ) else if exist "%WORKER_BAT%" (
    start "" /MIN cmd.exe /c ""%WORKER_BAT%""
  )
)

rem --- 3) Ensure low-priority worker is running ---
set "LOW_RUNNING=0"
powershell -NoProfile -ExecutionPolicy Bypass -Command "$procs = Get-CimInstance Win32_Process; $php = $procs | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -match 'new-cinema-api' -and $_.CommandLine -match 'queue:work' -and $_.CommandLine -match '--queue=low' -and $_.CommandLine -notmatch 'high,default' }; $bat = $procs | Where-Object { $_.Name -eq 'cmd.exe' -and $_.CommandLine -match 'cinema_queue_worker_low\.bat' }; if ($php -or $bat) { exit 0 } else { exit 1 }" >nul 2>&1
if "!ERRORLEVEL!"=="0" set "LOW_RUNNING=1"

if "!LOW_RUNNING!"=="0" (
  if exist "%LOW_VBS%" (
    >> "%LOG_FILE%" echo [%date% %time%] Watchdog: low worker NOT running. Starting...
    wscript.exe //B "%LOW_VBS%"
  ) else if exist "%LOW_BAT%" (
    start "" /MIN cmd.exe /c ""%LOW_BAT%""
  )
)

ping -n 3 127.0.0.1 >nul

powershell -NoProfile -ExecutionPolicy Bypass -File "%DETECT_PS1%" >nul 2>&1
if "!ERRORLEVEL!"=="0" (
  >> "%LOG_FILE%" echo [%date% %time%] OK: schedule ran; primary worker up; low_running=!LOW_RUNNING!
  exit /b 0
)

>> "%LOG_FILE%" echo [%date% %time%] WARN: primary worker not detected yet
exit /b 0

endlocal
