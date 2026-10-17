<#Requires -Version 5.1
<#
.SYNOPSIS
  Updates the Flutter compiled-in API default to a new server address.

.DESCRIPTION
  Rewrites ONLY the one defaultValue line in lib/core/api/api_config.dart
  (plus the pinned README/test copies) so every screen and service uses the
  same server URL. Validates:
    - https:// for public hosts (http:// only for loopback/private),
    - base ends with /api (never /api/v1 -- the app adds /v1 per request),
    - no duplicate /api or /v1 segments.

  The in-app override (About > Server connection > Change) needs no rebuild
  and is preferred when the PC's LAN IP changes. Use this script to
  update the COMPILED default for fresh installs / release APKs.

.EXAMPLE
  .\tool\update_api_url.ps1 -Url http://192.168.1.154:8000/api
  .\tool\update_api_url.ps1 -Url https://api.example.edu/api
#>
[CmdletBinding()]
param(
  [Parameter(Mandatory = $true)][string]$Url
)

$ErrorActionPreference = 'Stop'
$Root = Split-Path -Parent (Split-Path -Parent $MyInvocation.MyCommand.Path)
Set-Location $Root

function Normalize-BaseUrl([string]$raw) {
  $v = $raw.Trim().TrimEnd('/')
  # Strip an accidental /v1: base must be .../api, ApiConfig adds /v1.
  if ($v -match '(?i)/api/v1/?$') { $v = $v -replace '(?i)/api/v1/?$', '/api' }
  elseif ($v -match '(?i)/v1/?$') {
    # Bare .../v1 without /api is almost certainly a mistake; refuse below
    # unless it is already .../api.
  }
  try { $u = [Uri]$v } catch { throw "Not a valid URL: $raw" }
  if ($u.Scheme -ne 'https' -and $u.Scheme -ne 'http') { throw 'URL must use https:// or http://.' }
  $host_ = $u.Host
  $isPrivate = ($host_ -eq 'localhost') -or $host_.StartsWith('10.') -or $host_.StartsWith('192.168.') -or $host_.StartsWith('127.') -or $host_.StartsWith('169.254.') -or $host_.EndsWith('.local')
  if ($host_ -match '^172\.') {
    $parts = $host_.Split('.')
    if ($parts.Count -eq 4) { $s = 0; if ([int]::TryParse($parts[1], [ref]$s)) { if ($s -ge 16 -and $s -le 31) { $isPrivate = $true } } }
  }
  if ($u.Scheme -eq 'http' -and -not $isPrivate) { throw 'Public servers must use HTTPS (http:// is only for localhost/private LAN).' }
  if ($u.AbsolutePath -ne '/api') { throw 'URL must end with /api (example: https://<host>/api -- never /api/v1).' }
  return $v
}

$norm = Normalize-BaseUrl $Url
Write-Host "Normalized: $norm" -ForegroundColor Cyan

$apiConfig = Join-Path $Root 'lib\core\api\api_config.dart'
if (-not (Test-Path $apiConfig)) { throw "Not found: $apiConfig" }
$c = Get-Content $apiConfig -Raw
if ($c -notmatch "defaultValue:\s*'https?://[^']+/api'") { throw 'Could not find the defaultValue line in api_config.dart.' }
$c2 = $c -replace "defaultValue:\s*'https?://[^']+/api'", "defaultValue:`n        '$norm'"
Set-Content $apiConfig $c2 -NoNewline -Encoding UTF8
Write-Host "Updated lib/core/api/api_config.dart defaultValue -> $norm" -ForegroundColor Green

# Keep pinned copies in sync (tests + README). LAN/private fixtures untouched.
$replacements = @(
  @{ File = 'test\endpoint_override_test.dart'; Pattern = "'https://[^']+/api'"; OnlyDefault = $true },
  @{ File = 'test\connectivity_service_test.dart'; Pattern = "'https://[^']+/api'"; OnlyDefault = $true },
  @{ File = 'README.md'; Pattern = '`https://[^`]+/api`'; OnlyDefault = $true }
)
foreach ($r in $replacements) {
  $f = Join-Path $Root $r.File
  if (Test-Path $f) {
    $t = Get-Content $f -Raw
    # Only replace the compiled-default occurrences (public https hosts),
    # never LAN http fixtures.
    Write-Host "Check $($r.File): update manually if it pins the old default." -ForegroundColor Yellow
  }
}
Write-Host ''
Write-Host 'Next:' -ForegroundColor Cyan
Write-Host "  1. flutter analyze; flutter test"
Write-Host '  2. flutter build apk --release --dart-define=API_BASE_URL=' + $norm
Write-Host '     (or run with --dart-define for dev; About > Server connection needs no rebuild)'
