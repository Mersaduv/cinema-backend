# Refresh TMDB hosts via Cloudflare DoH (run periodically)
function Get-DohA([string]$name) {
  $json = & curl.exe -s --max-time 12 "https://1.1.1.1/dns-query?name=$name&type=A" -H "accept: application/dns-json"
  $obj = $json | ConvertFrom-Json
  @($obj.Answer | Where-Object { $_.type -eq 1 } | ForEach-Object { $_.data }) | Select-Object -First 1
}
$apiIp = Get-DohA 'api.themoviedb.org'
$imgIp = Get-DohA 'image.tmdb.org'
if (-not $apiIp -or -not $imgIp) { throw 'DoH resolve failed' }
$path = 'C:\Windows\System32\drivers\etc\hosts'
$lines = Get-Content $path | Where-Object { $_ -notmatch 'themoviedb\.org|image\.tmdb\.org' }
$lines + '' + '# Cinema TMDB DNS workaround' + "$apiIp`tapi.themoviedb.org" + "$imgIp`timage.tmdb.org" | Set-Content $path -Encoding ASCII
ipconfig /flushdns | Out-Null
Write-Host "Updated api=$apiIp img=$imgIp"
