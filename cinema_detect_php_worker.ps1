# Exit 0 if a cinema PHP queue:work process is already running.
# Used by the worker bat to avoid starting a second consumer.

$ErrorActionPreference = 'SilentlyContinue'

$hit = Get-CimInstance Win32_Process |
  Where-Object {
    $_.Name -eq 'php.exe' -and
    $_.CommandLine -and
    $_.CommandLine -match 'new-cinema-api[\\/]+artisan' -and
    $_.CommandLine -match 'queue:work' -and
    $_.CommandLine -match 'high,default,low,media-processing' -and
    $_.CommandLine -notmatch '--queue=crm'
  }

if ($hit) { exit 0 }
exit 1
