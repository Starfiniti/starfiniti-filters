[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'powershell-compat.ps1')
$repoRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) { $env:STARFINITI_SEARCH_RUNTIME_ROOT } else { Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters' }
$lock = Get-Content (Join-Path $repoRoot 'config\runtime-lock.json') -Raw | ConvertFrom-Json
$php = Join-Path $runtimeRoot "php-$($lock.php.version)\php.exe"
$wp = Join-Path $runtimeRoot "packages\wp-cli-$($lock.wpCli.version).phar"
$site = Join-Path $runtimeRoot 'sites\starfiniti-search\wordpress'
$archivePath = Join-Path $repoRoot 'dist\starfiniti-search.zip'
$sbomPath = Join-Path $repoRoot 'dist\starfiniti-search.cdx.json'
$manifestPath = Join-Path $repoRoot 'dist\release-manifest.json'
$mainHeader = Get-Content (Join-Path $repoRoot 'plugin\starfiniti-search\starfiniti-search.php') -Raw
$versionMatch = [regex]::Match($mainHeader, '(?mi)^\s*\*\s*Version:\s*([^\r\n]+)')
if (-not $versionMatch.Success) { throw 'Plugin version header is missing.' }
$skillArchivePath = Join-Path $repoRoot "dist\starfiniti-search-ai-skill-$($versionMatch.Groups[1].Value.Trim()).zip"
$installed = Join-Path $site 'wp-content\plugins\starfiniti-search'
$nodeFallback = 'C:\Users\dejan\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
$node = if (Get-Command node -ErrorAction SilentlyContinue) { (Get-Command node).Source } elseif (Test-Path $nodeFallback) { $nodeFallback } else { throw 'Node.js is required.' }
$env:HTTP_HOST = '127.0.0.1:8088'

function Build-Artifact {
    $packageOutput = powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'package-plugin.ps1')
    $packageOutput | ForEach-Object { Write-Host $_ }
    if ($LASTEXITCODE -ne 0) { throw 'Plugin packaging failed.' }
    return @{
        zip = (Get-StarfinitiFileHash -LiteralPath $archivePath -Algorithm SHA256).Hash
        sbom = (Get-StarfinitiFileHash -LiteralPath $sbomPath -Algorithm SHA256).Hash
        manifest = (Get-StarfinitiFileHash -LiteralPath $manifestPath -Algorithm SHA256).Hash
        skill = (Get-StarfinitiFileHash -LiteralPath $skillArchivePath -Algorithm SHA256).Hash
    }
}

function Resolve-ArchiveEntry {
    param(
        [Parameter(Mandatory = $true)][string]$EntryName,
        [Parameter(Mandatory = $true)][string]$ExpectedRoot,
        [Parameter(Mandatory = $true)][string]$TargetRoot,
        [Parameter(Mandatory = $true)][AllowEmptyCollection()][Collections.Generic.HashSet[string]]$Seen
    )

    if ($EntryName.Contains('\')) { throw "Archive entry uses a backslash: $EntryName" }
    $segments = @($EntryName.Split('/'))
    if ($segments.Count -lt 2 -or $segments[0] -cne $ExpectedRoot) {
        throw "Archive entry is outside the expected root ${ExpectedRoot}: $EntryName"
    }
    foreach ($segment in $segments) {
        if ([string]::IsNullOrEmpty($segment) -or $segment -eq '.' -or $segment -eq '..' -or $segment.Contains(':')) {
            throw "Archive entry has an unsafe path segment: $EntryName"
        }
    }

    $relative = [string]::Join([IO.Path]::DirectorySeparatorChar, $segments[1..($segments.Count - 1)])
    if (-not $Seen.Add($relative)) { throw "Archive contains a duplicate or case-colliding entry: $EntryName" }
    $targetPrefix = [IO.Path]::GetFullPath($TargetRoot).TrimEnd([IO.Path]::DirectorySeparatorChar, [IO.Path]::AltDirectorySeparatorChar) + [IO.Path]::DirectorySeparatorChar
    $path = [IO.Path]::GetFullPath((Join-Path $TargetRoot $relative))
    if (-not $path.StartsWith($targetPrefix, [StringComparison]::OrdinalIgnoreCase)) {
        throw "Archive entry resolves outside the installed root: $EntryName"
    }
    return @{ Relative = $relative; Path = $path }
}

function Assert-ArchiveEntryValidator {
    $invalidEntries = @(
        'wrong-root/file.php',
        'starfiniti-search/../file.php',
        'starfiniti-search/.//file.php',
        'starfiniti-search\file.php',
        'starfiniti-search/C:/file.php'
    )
    foreach ($entryName in $invalidEntries) {
        $rejected = $false
        try {
            $seen = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
            Resolve-ArchiveEntry -EntryName $entryName -ExpectedRoot 'starfiniti-search' -TargetRoot $installed -Seen $seen | Out-Null
        } catch { $rejected = $true }
        if (-not $rejected) { throw "Unsafe archive-entry self-test was accepted: $entryName" }
    }
    $seen = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
    Resolve-ArchiveEntry -EntryName 'starfiniti-search/File.php' -ExpectedRoot 'starfiniti-search' -TargetRoot $installed -Seen $seen | Out-Null
    $collisionRejected = $false
    try {
        Resolve-ArchiveEntry -EntryName 'starfiniti-search/file.php' -ExpectedRoot 'starfiniti-search' -TargetRoot $installed -Seen $seen | Out-Null
    } catch { $collisionRejected = $true }
    if (-not $collisionRejected) { throw 'Case-colliding archive-entry self-test was accepted.' }
}

function Assert-SkillArtifact {
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $skillInstalled = Join-Path $installed 'ai\starfiniti-search-assistant'
    $archive = [System.IO.Compression.ZipFile]::OpenRead($skillArchivePath)
    try {
        $entries = @($archive.Entries | Where-Object { -not [string]::IsNullOrEmpty($_.Name) })
        $installedFiles = @(Get-ChildItem -LiteralPath $skillInstalled -Recurse -File)
        if ($entries.Count -ne $installedFiles.Count) {
            throw "Customer skill file count mismatch: archive=$($entries.Count), installed=$($installedFiles.Count)."
        }
        $seen = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
        foreach ($entry in $entries) {
            $resolved = Resolve-ArchiveEntry -EntryName $entry.FullName -ExpectedRoot 'starfiniti-search-assistant' -TargetRoot $skillInstalled -Seen $seen
            $relative = $resolved.Relative
            $path = $resolved.Path
            if (-not (Test-Path -LiteralPath $path)) { throw "Installed customer skill file is missing: $relative" }
            $stream = $entry.Open()
            try {
                $hasher = [Security.Cryptography.SHA256]::Create()
                try { $archiveHash = ([BitConverter]::ToString($hasher.ComputeHash($stream))).Replace('-', '') } finally { $hasher.Dispose() }
            } finally { $stream.Dispose() }
            if ($archiveHash -ne (Get-StarfinitiFileHash -LiteralPath $path -Algorithm SHA256).Hash) {
                throw "Installed customer skill differs from the companion archive: $relative"
            }
        }
        return $entries.Count
    } finally { $archive.Dispose() }
}

function Assert-InstalledArtifact {
    Add-Type -AssemblyName System.IO.Compression
    Add-Type -AssemblyName System.IO.Compression.FileSystem
    $archive = [System.IO.Compression.ZipFile]::OpenRead($archivePath)
    try {
        $entries = @($archive.Entries | Where-Object { -not [string]::IsNullOrEmpty($_.Name) })
        $installedFiles = @(Get-ChildItem -LiteralPath $installed -Recurse -File)
        if ($entries.Count -ne $installedFiles.Count) {
            throw "Installed file count mismatch: archive=$($entries.Count), installed=$($installedFiles.Count)."
        }
        $seen = [Collections.Generic.HashSet[string]]::new([StringComparer]::OrdinalIgnoreCase)
        foreach ($entry in $entries) {
            $resolved = Resolve-ArchiveEntry -EntryName $entry.FullName -ExpectedRoot 'starfiniti-search' -TargetRoot $installed -Seen $seen
            $relative = $resolved.Relative
            $path = $resolved.Path
            if (-not (Test-Path -LiteralPath $path)) { throw "Installed file is missing: $relative" }
            $stream = $entry.Open()
            try {
                $hasher = [Security.Cryptography.SHA256]::Create()
                try { $archiveHash = ([BitConverter]::ToString($hasher.ComputeHash($stream))).Replace('-', '') } finally { $hasher.Dispose() }
            } finally { $stream.Dispose() }
            if ($archiveHash -ne (Get-StarfinitiFileHash -LiteralPath $path -Algorithm SHA256).Hash) {
                throw "Installed file differs from the archive: $relative"
            }
        }
        return $entries.Count
    } finally { $archive.Dispose() }
}

Assert-ArchiveEntryValidator
powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'wordpress-runtime.ps1') start | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'WordPress runtime did not start.' }

$first = Build-Artifact
$second = Build-Artifact
foreach ($name in @('zip', 'skill', 'sbom', 'manifest')) {
    if ($first[$name] -ne $second[$name]) { throw "Reproducibility failed for $name." }
}

& $php $wp --path=$site plugin install $archivePath --force | Write-Output
if ($LASTEXITCODE -ne 0) { throw 'Exact ZIP installation failed.' }
& $php $wp --path=$site plugin activate starfiniti-search | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Installed plugin activation failed.' }
$fileCount = Assert-InstalledArtifact
$skillFileCount = Assert-SkillArtifact

$pluginCheckCli = Join-Path $site 'wp-content\plugins\plugin-check\cli.php'
if (-not (Test-Path -LiteralPath $pluginCheckCli)) { throw 'Pinned official Plugin Check is missing; run wordpress:setup.' }
$pluginCheckOutput = @(& $php $wp --path=$site plugin check starfiniti-search --require=$pluginCheckCli --format=json 2>&1)
$pluginCheckExit = $LASTEXITCODE
$pluginCheckOutput | Write-Output
if ($pluginCheckExit -ne 0 -or (($pluginCheckOutput -join "`n") -match '"type":"(ERROR|WARNING)"')) {
    throw 'Official Plugin Check reported an error or warning for the exact installed artifact.'
}

foreach ($file in @('storefront-contract.php', 'setup-readiness.php', 'schema-upgrade-v9-v10.php', 'configuration-lifecycle.php', 'custom-fields.php', 'analytics-failure-isolation.php', 'relevance-policy.php', 'control-rest.php', 'admin-relevance.php', 'continuation-scheduling.php')) {
    & $php $wp --path=$site eval-file (Join-Path $repoRoot "tests\php\integration\$file") | Write-Output
    if ($LASTEXITCODE -ne 0) { throw "Installed-artifact contract failed: $file" }
}

foreach ($script in @('test-lifecycle.ps1', 'test-process-kill.ps1', 'test-builder-kill.ps1', 'test-disaster-recovery.ps1')) {
    powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot $script) | Write-Output
    if ($LASTEXITCODE -ne 0) { throw "Installed-artifact qualification script failed: $script" }
}

& $node (Join-Path $PSScriptRoot 'validate-contracts.mjs') | Write-Output
if ($LASTEXITCODE -ne 0) { throw 'Installed live JSON Schema validation failed.' }
$exact = Invoke-RestMethod -Uri 'http://127.0.0.1:8088/wp-json/starfiniti-search/v1/search?q=EXACT-001&size=8' -TimeoutSec 30
$restricted = Invoke-RestMethod -Uri ('http://127.0.0.1:8088/wp-json/starfiniti-search/v1/search?q=' + [uri]::EscapeDataString('Hidden Wholesale Belt') + '&size=8') -TimeoutSec 30
$page = Invoke-WebRequest -Uri 'http://127.0.0.1:8088/starfiniti-search-qualification/' -UseBasicParsing -TimeoutSec 30
if ($exact.hits[0].projection.identity.title -ne 'Blue Alpine Shirt' -or $restricted.total -ne 0 -or $page.Content -notlike '*data-starfiniti-discovery*') {
    throw 'Installed public exact-SKU, visibility, or discovery smoke failed.'
}

Write-Output "Artifact qualification passed: $fileCount plugin files and $skillFileCount customer skill files; ZIP $($second.zip); skill $($second.skill); SBOM $($second.sbom); manifest $($second.manifest)."
