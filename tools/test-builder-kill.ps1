[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) { $env:STARFINITI_SEARCH_RUNTIME_ROOT } else { Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters' }
$lock = Get-Content (Join-Path $repoRoot 'config\runtime-lock.json') -Raw | ConvertFrom-Json
$php = Join-Path $runtimeRoot "php-$($lock.php.version)\php.exe"
$wp = Join-Path $runtimeRoot "packages\wp-cli-$($lock.wpCli.version).phar"
$site = Join-Path $runtimeRoot 'sites\starfiniti-search\wordpress'
$setupMarker = Join-Path $runtimeRoot 'state\builder-kill.setup.json'
$workerMarker = Join-Path $runtimeRoot 'state\builder-kill.worker.json'
$stdout = Join-Path $runtimeRoot 'logs\builder-kill.stdout.log'
$stderr = Join-Path $runtimeRoot 'logs\builder-kill.stderr.log'
$env:HTTP_HOST = '127.0.0.1:8088'
$env:STARFINITI_BUILDER_KILL_SETUP = $setupMarker
$env:STARFINITI_BUILDER_KILL_WORKER = $workerMarker

powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'wordpress-runtime.ps1') start | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'WordPress runtime did not start.' }
foreach ($path in @($setupMarker, $workerMarker, $stdout, $stderr)) {
    if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Force }
}

$worker = $null
try {
    & $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\builder-kill-setup.php') | Out-Null
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $setupMarker)) { throw 'Builder-kill setup failed.' }
    $worker = Start-Process -FilePath $php -ArgumentList $wp, "--path=$site", 'eval-file', '.\tests\php\integration\builder-kill-worker.php' -WorkingDirectory $repoRoot -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
    for ($attempt = 0; $attempt -lt 120 -and -not (Test-Path -LiteralPath $workerMarker); ++$attempt) {
        if ($worker.HasExited) { throw "Builder-kill worker exited before its durable progress marker. See $stderr." }
        Start-Sleep -Milliseconds 250
    }
    if (-not (Test-Path -LiteralPath $workerMarker)) { throw 'Builder-kill worker did not publish its durable progress marker.' }
    $resolved = Get-Process -Id $worker.Id -ErrorAction Stop
    if ($resolved.Path -ne $php) { throw 'Refusing to terminate an unexpected shadow-builder process.' }
    Stop-Process -Id $worker.Id -Force
    $worker.WaitForExit(5000) | Out-Null
    Start-Sleep -Seconds 3
    & $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\builder-kill-recovery.php')
    if ($LASTEXITCODE -ne 0) { throw 'Builder-kill recovery assertion failed.' }
} finally {
    if ($worker -ne $null -and -not $worker.HasExited) {
        $resolved = Get-Process -Id $worker.Id -ErrorAction SilentlyContinue
        if ($resolved -ne $null -and $resolved.Path -eq $php) { Stop-Process -Id $worker.Id -Force }
    }
    & $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\builder-kill-cleanup.php') | Out-Null
    foreach ($path in @($setupMarker, $workerMarker)) {
        if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Force }
    }
}
