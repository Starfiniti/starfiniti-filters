[CmdletBinding()]
param(
    [ValidateRange(10, 200)]
    [int] $Samples = 20,
    [switch] $EnforceEndToEnd
)

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$endpoint = 'http://127.0.0.1:8088/wp-json/starfiniti-search/v1/search?q=EXACT-001&size=8'

function Percentile([double[]] $Values, [double] $Percentile) {
    $ordered = @($Values | Sort-Object)
    $index = [Math]::Max(0, [Math]::Ceiling($ordered.Count * $Percentile) - 1)
    return [double] $ordered[$index]
}

powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'wordpress-runtime.ps1') start | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'WordPress runtime did not start.' }

1..3 | ForEach-Object {
    $warm = Invoke-RestMethod -Uri $endpoint -Method Get -TimeoutSec 30
    if ($warm.hits[0].projection.identity.title -ne 'Blue Alpine Shirt') {
        throw 'Warm-up returned an unexpected search result.'
    }
}

$http = @()
$provider = @()
1..$Samples | ForEach-Object {
    $watch = [Diagnostics.Stopwatch]::StartNew()
    $response = Invoke-RestMethod -Uri $endpoint -Method Get -TimeoutSec 30
    $watch.Stop()
    if ($response.hits[0].projection.identity.title -ne 'Blue Alpine Shirt') {
        throw 'Benchmark returned an unexpected search result.'
    }
    $http += $watch.Elapsed.TotalMilliseconds
    $provider += [double] $response.timing.provider_ms
}

$providerP50 = Percentile $provider 0.50
$providerP95 = Percentile $provider 0.95
$providerP99 = Percentile $provider 0.99
$httpP50 = Percentile $http 0.50
$httpP95 = Percentile $http 0.95
$httpP99 = Percentile $http 0.99

Write-Output ('Provider samples={0}; p50={1:N1} ms; p95={2:N1} ms; p99={3:N1} ms; budgets=60/150/300 ms' -f $Samples, $providerP50, $providerP95, $providerP99)
Write-Output ('HTTP samples={0}; p50={1:N1} ms; p95={2:N1} ms; p99={3:N1} ms; budgets=60/150/300 ms' -f $Samples, $httpP50, $httpP95, $httpP99)

if ($providerP50 -gt 60 -or $providerP95 -gt 150 -or $providerP99 -gt 300) {
    throw 'Provider latency budget failed.'
}
if ($EnforceEndToEnd -and ($httpP50 -gt 60 -or $httpP95 -gt 150 -or $httpP99 -gt 300)) {
    throw 'End-to-end HTTP latency budget failed.'
}
if (-not $EnforceEndToEnd) {
    Write-Output 'End-to-end HTTP values are observational; use -EnforceEndToEnd on the production-like reference runtime.'
}
