[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) { $env:STARFINITI_SEARCH_RUNTIME_ROOT } else { Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters' }
$lock = Get-Content (Join-Path $repoRoot 'config\runtime-lock.json') -Raw | ConvertFrom-Json
$php = Join-Path $runtimeRoot "php-$($lock.php.version)\php.exe"
$wp = Join-Path $runtimeRoot "packages\wp-cli-$($lock.wpCli.version).phar"
$site = Join-Path $runtimeRoot 'sites\starfiniti-search\wordpress'
$marker = Join-Path $runtimeRoot 'state\process-kill-worker.ready.json'
$stdout = Join-Path $runtimeRoot 'logs\process-kill-worker.stdout.log'
$stderr = Join-Path $runtimeRoot 'logs\process-kill-worker.stderr.log'
$env:HTTP_HOST = '127.0.0.1:8088'
$env:STARFINITI_PROCESS_KILL_MARKER = $marker

powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'wordpress-runtime.ps1') start | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'WordPress runtime did not start.' }
foreach ($path in @($marker, $stdout, $stderr)) { if (Test-Path -LiteralPath $path) { Remove-Item -LiteralPath $path -Force } }

$worker = $null
try {
    $worker = Start-Process -FilePath $php -ArgumentList $wp, "--path=$site", 'eval-file', '.\tests\php\integration\process-kill-worker.php' -WorkingDirectory $repoRoot -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
    for ($attempt = 0; $attempt -lt 80 -and -not (Test-Path -LiteralPath $marker); ++$attempt) {
        if ($worker.HasExited) { throw "Process-kill worker exited before the lease marker. See $stderr." }
        Start-Sleep -Milliseconds 250
    }
    if (-not (Test-Path -LiteralPath $marker)) { throw 'Process-kill worker did not publish its lease marker.' }
    $resolved = Get-Process -Id $worker.Id -ErrorAction Stop
    if ($resolved.Path -ne $php) { throw 'Refusing to terminate an unexpected process.' }
    Stop-Process -Id $worker.Id -Force
    $worker.WaitForExit(5000) | Out-Null
    Start-Sleep -Seconds 3
    & $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\process-kill-recovery.php')
    if ($LASTEXITCODE -ne 0) { throw 'Process-kill recovery assertion failed.' }
} finally {
    if ($worker -ne $null -and -not $worker.HasExited) {
        $resolved = Get-Process -Id $worker.Id -ErrorAction SilentlyContinue
        if ($resolved -ne $null -and $resolved.Path -eq $php) { Stop-Process -Id $worker.Id -Force }
    }
    & $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\process-kill-cleanup.php') | Out-Null
    if (Test-Path -LiteralPath $marker) { Remove-Item -LiteralPath $marker -Force }
}
