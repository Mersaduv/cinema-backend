# Detects ONLY the primary cinema queue worker (high/default), not CRM / low-only.
# Exit 0 = running, Exit 1 = not running.

$ErrorActionPreference = 'SilentlyContinue'

$hit = Get-CimInstance Win32_Process |
  Where-Object {
    $cmd = $_.CommandLine
    if (-not $cmd) { return $false }

    $isWorkerBat =
      $_.Name -eq 'cmd.exe' -and
      $cmd -match 'cinema_queue_worker\.bat' -and
      $cmd -notmatch 'cinema_queue_worker_low\.bat'

    $isCinemaPhp =
      $_.Name -eq 'php.exe' -and
      $cmd -match 'new-cinema-api[\\/]+artisan' -and
      $cmd -match 'queue:work' -and
      $cmd -match '--queue=high,default' -and
      $cmd -notmatch '--queue=low' -and
      $cmd -notmatch '--queue=crm'

    # Legacy single-worker command (before split high/low).
    $isLegacyCinemaPhp =
      $_.Name -eq 'php.exe' -and
      $cmd -match 'new-cinema-api[\\/]+artisan' -and
      $cmd -match 'queue:work' -and
      $cmd -match 'high,default,low,media-processing' -and
      $cmd -notmatch '--queue=crm'

    return ($isWorkerBat -or $isCinemaPhp -or $isLegacyCinemaPhp)
  }

if ($hit) {
  exit 0
}

exit 1
