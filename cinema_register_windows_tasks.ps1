#Requires -RunAsAdministrator
<#
.SYNOPSIS
  Registers always-on Windows Scheduled Tasks for Cinema API:
    1) Cinema Queue Worker     — starts hidden worker at boot; restarts on failure
    2) Cinema Scheduler Watchdog — every 1 minute: schedule:run + ensure worker alive

.NOTES
  Safe to re-run (idempotent). Does not touch CRM or other projects.
#>

$ErrorActionPreference = 'Stop'

$AppDir = 'D:\sites\cinema\new-cinema-api'
$WorkerVbs = Join-Path $AppDir 'cinema_queue_worker_hidden.vbs'
$WorkerBat = Join-Path $AppDir 'cinema_queue_worker.bat'
$SchedulerBat = Join-Path $AppDir 'cinema_task_scheduler.bat'

foreach ($path in @($WorkerVbs, $WorkerBat, $SchedulerBat)) {
  if (-not (Test-Path -LiteralPath $path)) {
    throw "Missing required file: $path"
  }
}

function Register-CinemaTask {
  param(
    [string]$TaskName,
    [string]$Execute,
    [string]$Arguments,
    [string]$WorkingDirectory,
    [CimInstance[]]$Triggers,
    [Microsoft.PowerShell.Cmdletization.GeneratedTypes.ScheduledTask.MultipleInstancesEnum]$MultipleInstances =
      [Microsoft.PowerShell.Cmdletization.GeneratedTypes.ScheduledTask.MultipleInstancesEnum]::IgnoreNew
  )

  $action = New-ScheduledTaskAction -Execute $Execute -Argument $Arguments -WorkingDirectory $WorkingDirectory
  $settings = New-ScheduledTaskSettingsSet `
    -AllowStartIfOnBatteries `
    -DontStopIfGoingOnBatteries `
    -StartWhenAvailable `
    -ExecutionTimeLimit ([TimeSpan]::Zero) `
    -RestartCount 999 `
    -RestartInterval (New-TimeSpan -Minutes 1) `
    -MultipleInstances $MultipleInstances

  # Run as SYSTEM so worker survives logoff
  $principal = New-ScheduledTaskPrincipal -UserId 'SYSTEM' -LogonType ServiceAccount -RunLevel Highest

  Register-ScheduledTask `
    -TaskName $TaskName `
    -Action $action `
    -Trigger $Triggers `
    -Settings $settings `
    -Principal $principal `
    -Force | Out-Null

  Write-Host "Registered: $TaskName"
}

# --- 1) Always-on queue worker (boot + failure restart) ---
$workerTrigger = New-ScheduledTaskTrigger -AtStartup
Register-CinemaTask `
  -TaskName 'Cinema Queue Worker' `
  -Execute 'wscript.exe' `
  -Arguments "//B `"$WorkerVbs`"" `
  -WorkingDirectory $AppDir `
  -Triggers @($workerTrigger) `
  -MultipleInstances ([Microsoft.PowerShell.Cmdletization.GeneratedTypes.ScheduledTask.MultipleInstancesEnum]::IgnoreNew)

# --- 2) Watchdog every 1 minute (also at startup) ---
$watchdogTrigger = New-ScheduledTaskTrigger -Once -At (Get-Date).Date.AddMinutes(1) `
  -RepetitionInterval (New-TimeSpan -Minutes 1) `
  -RepetitionDuration (New-TimeSpan -Days 3650)
$watchdogStartup = New-ScheduledTaskTrigger -AtStartup

Register-CinemaTask `
  -TaskName 'Cinema Scheduler Watchdog' `
  -Execute 'cmd.exe' `
  -Arguments "/c `"$SchedulerBat`"" `
  -WorkingDirectory $AppDir `
  -Triggers @($watchdogTrigger, $watchdogStartup) `
  -MultipleInstances ([Microsoft.PowerShell.Cmdletization.GeneratedTypes.ScheduledTask.MultipleInstancesEnum]::IgnoreNew)

# Start both now
Start-ScheduledTask -TaskName 'Cinema Queue Worker'
Start-Sleep -Seconds 3
Start-ScheduledTask -TaskName 'Cinema Scheduler Watchdog'

Start-Sleep -Seconds 2
& (Join-Path $AppDir 'cinema_detect_worker.ps1')
$running = ($LASTEXITCODE -eq 0)

Write-Host ''
Write-Host '=== Status ==='
Get-ScheduledTask -TaskName 'Cinema Queue Worker', 'Cinema Scheduler Watchdog' |
  ForEach-Object {
    $info = $_ | Get-ScheduledTaskInfo
    [PSCustomObject]@{
      Task     = $_.TaskName
      State    = $_.State
      LastRun  = $info.LastRunTime
      NextRun  = $info.NextRunTime
      LastCode = $info.LastTaskResult
    }
  } | Format-Table -AutoSize

if ($running) {
  Write-Host 'Cinema queue worker: RUNNING'
  exit 0
}

Write-Host 'Cinema queue worker: NOT DETECTED YET (check logs if still down after ~10s)'
exit 1
