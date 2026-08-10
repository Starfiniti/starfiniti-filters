[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) { $env:STARFINITI_SEARCH_RUNTIME_ROOT } else { Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters' }
$php = Join-Path $runtimeRoot 'php-8.3.28\php.exe'
$wp = Join-Path $runtimeRoot 'packages\wp-cli-2.12.0.phar'
$site = Join-Path $runtimeRoot 'sites\starfiniti-search\wordpress'

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\generation-lifecycle.php')
if ($LASTEXITCODE -ne 0) { throw 'Generation lifecycle test failed.' }
