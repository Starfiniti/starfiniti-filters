$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) { $env:STARFINITI_SEARCH_RUNTIME_ROOT } else { Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters' }
$lock = Get-Content (Join-Path $repoRoot 'config\runtime-lock.json') -Raw | ConvertFrom-Json
$php = Join-Path $runtimeRoot "php-$($lock.php.version)\php.exe"
if (-not (Test-Path -LiteralPath $php)) { throw 'Run pnpm runtime:setup first.' }

$files = Get-ChildItem (Join-Path $repoRoot 'plugin\starfiniti-search') -Recurse -Filter '*.php'
foreach ($file in $files) {
    & $php -l $file.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax check failed: $($file.FullName)" }
}

& $php (Join-Path $repoRoot 'tests\php\run.php')
if ($LASTEXITCODE -ne 0) { throw 'PHP unit tests failed.' }

