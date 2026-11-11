[CmdletBinding()]
param([Parameter(Mandatory = $true)][string] $Python)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) { $env:STARFINITI_SEARCH_RUNTIME_ROOT } else { Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters' }
$lock = Get-Content (Join-Path $repoRoot 'config/runtime-lock.json') -Raw | ConvertFrom-Json
$php = Join-Path $runtimeRoot "php-$($lock.php.version)/php.exe"
$wp = Join-Path $runtimeRoot "packages/wp-cli-$($lock.wpCli.version).phar"
$site = Join-Path $runtimeRoot 'sites/starfiniti-search/wordpress'
$fixture = Join-Path $repoRoot 'tests/php/integration/configured-discovery-fixture.php'
$previousEnvironment = @{}
foreach ($name in @('HTTP_HOST', 'PYTHONPATH', 'PLAYWRIGHT_NODEJS_PATH', 'PLAYWRIGHT_BROWSERS_PATH')) {
    $previousEnvironment[$name] = [Environment]::GetEnvironmentVariable($name, 'Process')
}
try {
    $env:HTTP_HOST = '127.0.0.1:8088'
    $env:PYTHONPATH = Join-Path $repoRoot '.cache/browser-test-dependencies'
    $env:PLAYWRIGHT_NODEJS_PATH = (Get-Command node -ErrorAction Stop).Source
    $env:PLAYWRIGHT_BROWSERS_PATH = Join-Path $runtimeRoot 'browser-tests'
    & $php $wp --path=$site eval-file $fixture setup
    if ($LASTEXITCODE -ne 0) { throw 'Configured discovery fixture setup failed.' }
    & $Python -u (Join-Path $repoRoot 'tests/browser/configured-discovery.py')
    if ($LASTEXITCODE -ne 0) { throw 'Configured discovery browser test failed.' }
} finally {
    try {
        & $php $wp --path=$site eval-file $fixture restore
        if ($LASTEXITCODE -ne 0) { throw 'Configured discovery fixture restoration failed.' }
    } finally {
        foreach ($name in $previousEnvironment.Keys) {
            [Environment]::SetEnvironmentVariable($name, $previousEnvironment[$name], 'Process')
        }
    }
}
