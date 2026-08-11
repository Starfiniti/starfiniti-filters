[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'powershell-compat.ps1')
$repoRoot = Split-Path -Parent $PSScriptRoot
$runtimeNode = 'C:\Users\dejan\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
$node = if (Get-Command node -ErrorAction SilentlyContinue) { (Get-Command node).Source } elseif (Test-Path $runtimeNode) { $runtimeNode } else { throw 'Node.js is required.' }
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) { $env:STARFINITI_SEARCH_RUNTIME_ROOT } else { Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters' }
$php = Join-Path $runtimeRoot 'php-8.3.28\php.exe'
$source = Join-Path $repoRoot 'plugin\starfiniti-search'
$dist = Join-Path $repoRoot 'dist'
$archive = Join-Path $dist 'starfiniti-search.zip'

& $node (Join-Path $PSScriptRoot 'verify-release.mjs')
if ($LASTEXITCODE -ne 0) { throw 'Release policy scan failed.' }
if (-not (Test-Path $php)) { throw 'Pinned PHP runtime is missing; run runtime:setup.' }
Get-ChildItem $source -Recurse -Filter *.php | ForEach-Object {
    & $php -l $_.FullName | Out-Null
    if ($LASTEXITCODE -ne 0) { throw "PHP syntax validation failed for $($_.FullName)." }
}
Get-ChildItem $source -Recurse -Filter *.js | ForEach-Object {
    & $node --check $_.FullName
    if ($LASTEXITCODE -ne 0) { throw "JavaScript syntax validation failed for $($_.FullName)." }
}

New-Item -ItemType Directory -Force -Path $dist | Out-Null
if (Test-Path $archive) { Remove-Item -LiteralPath $archive -Force }
Add-Type -AssemblyName System.IO.Compression
Add-Type -AssemblyName System.IO.Compression.FileSystem
$stream = [System.IO.File]::Open($archive, [System.IO.FileMode]::CreateNew)
$zip = New-Object System.IO.Compression.ZipArchive($stream, [System.IO.Compression.ZipArchiveMode]::Create, $false)
$timestamp = New-Object System.DateTimeOffset(2026, 8, 9, 0, 0, 0, [System.TimeSpan]::Zero)
try {
    Get-ChildItem $source -Recurse -File | Sort-Object FullName | ForEach-Object {
        $relative = $_.FullName.Substring($source.Length).TrimStart('\').Replace('\', '/')
        $entry = $zip.CreateEntry("starfiniti-search/$relative", [System.IO.Compression.CompressionLevel]::Optimal)
        $entry.LastWriteTime = $timestamp
        $input = [System.IO.File]::OpenRead($_.FullName)
        $output = $entry.Open()
        try { $input.CopyTo($output) } finally { $output.Dispose(); $input.Dispose() }
    }
} finally {
    $zip.Dispose()
    $stream.Dispose()
}
$hash = (Get-StarfinitiFileHash -LiteralPath $archive -Algorithm SHA256).Hash
Write-Output "Packaged $archive"
Write-Output "SHA256 $hash"
& $node (Join-Path $PSScriptRoot 'generate-sbom.mjs')
if ($LASTEXITCODE -ne 0) { throw 'SBOM generation failed.' }
