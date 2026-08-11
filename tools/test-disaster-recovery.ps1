[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'powershell-compat.ps1')
$repoRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) { $env:STARFINITI_SEARCH_RUNTIME_ROOT } else { Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters' }
$lock = Get-Content (Join-Path $repoRoot 'config\runtime-lock.json') -Raw | ConvertFrom-Json
$bin = Join-Path $runtimeRoot "mariadb-$($lock.mariadb.version)\mariadb-$($lock.mariadb.version)-winx64\bin"
$maria = Join-Path $bin 'mariadb.exe'
$dump = Join-Path $bin 'mariadb-dump.exe'
$php = Join-Path $runtimeRoot "php-$($lock.php.version)\php.exe"
$wp = Join-Path $runtimeRoot "packages\wp-cli-$($lock.wpCli.version).phar"
$site = Join-Path $runtimeRoot 'sites\starfiniti-search\wordpress'
$stateRoot = Join-Path $runtimeRoot 'state'
$temporary = Join-Path $stateRoot "dr-$PID"
$dumpPath = Join-Path $temporary 'starfiniti-search.sql'
$restoreOut = Join-Path $temporary 'restore.stdout.log'
$restoreErr = Join-Path $temporary 'restore.stderr.log'
$sourceDatabase = 'starfiniti_search_test'
$restoreDatabase = "starfiniti_search_dr_$PID"
if ($restoreDatabase -notmatch '^starfiniti_search_dr_[0-9]+$') { throw 'Refusing an unsafe disaster-recovery database name.' }

$secrets = @{}
Get-Content (Join-Path $stateRoot 'runtime.env') | ForEach-Object { if ($_ -match '^([^=]+)=(.*)$') { $secrets[$matches[1]] = $matches[2] } }
$rootPassword = [string]$secrets.MARIADB_ROOT_PASSWORD
if ($rootPassword -eq '') { throw 'Pinned runtime credentials are unavailable.' }
$adminArgs = @('--protocol=tcp', '--host=127.0.0.1', "--port=$($lock.mariadb.port)", '--user=root', "--password=$rootPassword", '--batch', '--skip-column-names')

function Invoke-AdminSql([string] $Sql) {
    $output = @(& $maria @adminArgs --execute=$Sql)
    if ($LASTEXITCODE -ne 0) { throw 'Disaster-recovery MariaDB command failed.' }
    return $output
}

powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'wordpress-runtime.ps1') start | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'WordPress runtime did not start.' }
New-Item -ItemType Directory -Path $temporary | Out-Null
$databaseCreated = $false
try {
    $existing = (Invoke-AdminSql "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$restoreDatabase';") | Select-Object -First 1
    if ([int]$existing -ne 0) { throw 'Disposable disaster-recovery database already exists.' }

    & $dump --protocol=tcp --host=127.0.0.1 "--port=$($lock.mariadb.port)" --user=root "--password=$rootPassword" --single-transaction --routines --events --triggers --hex-blob --default-character-set=utf8mb4 --skip-comments "--result-file=$dumpPath" $sourceDatabase
    if ($LASTEXITCODE -ne 0 -or -not (Test-Path -LiteralPath $dumpPath) -or (Get-Item -LiteralPath $dumpPath).Length -lt 1024) {
        throw 'Atomic logical backup generation failed.'
    }
    $backupHash = (Get-StarfinitiFileHash -LiteralPath $dumpPath -Algorithm SHA256).Hash

    Invoke-AdminSql "CREATE DATABASE ``$restoreDatabase`` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci;" | Out-Null
    $databaseCreated = $true
    $restoreArgs = @('--protocol=tcp', '--host=127.0.0.1', "--port=$($lock.mariadb.port)", '--user=root', "--password=$rootPassword", $restoreDatabase)
    $restore = Start-Process -FilePath $maria -ArgumentList $restoreArgs -WindowStyle Hidden -RedirectStandardInput $dumpPath -RedirectStandardOutput $restoreOut -RedirectStandardError $restoreErr -Wait -PassThru
    if ($restore.ExitCode -ne 0) { throw "Logical restore failed. See $restoreErr." }

    $tables = @(Invoke-AdminSql "SELECT table_name FROM information_schema.tables WHERE table_schema='$sourceDatabase' AND table_name LIKE 'wp_sfs_%' ORDER BY table_name;")
    if ($tables.Count -lt 10) { throw 'Source search-table inventory is incomplete.' }
    foreach ($table in $tables) {
        if ($table -notmatch '^wp_sfs_[a-z0-9_]+$') { throw 'Unsafe restored table identifier.' }
        $counts = @(Invoke-AdminSql "SELECT (SELECT COUNT(*) FROM ``$sourceDatabase``.``$table``),(SELECT COUNT(*) FROM ``$restoreDatabase``.``$table``);")
        $parts = [string]$counts[0] -split "`t"
        if ($parts.Count -ne 2 -or [int64]$parts[0] -ne [int64]$parts[1]) { throw "Restored row-count mismatch for $table." }
    }
    $optionCounts = @(Invoke-AdminSql "SELECT (SELECT COUNT(*) FROM ``$sourceDatabase``.wp_options WHERE option_name LIKE 'starfiniti_search_%'),(SELECT COUNT(*) FROM ``$restoreDatabase``.wp_options WHERE option_name LIKE 'starfiniti_search_%');")
    $optionParts = [string]$optionCounts[0] -split "`t"
    if ($optionParts.Count -ne 2 -or [int64]$optionParts[0] -ne [int64]$optionParts[1]) { throw 'Restored Starfiniti option count mismatch.' }

    $env:STARFINITI_DR_DATABASE = $restoreDatabase
    $env:STARFINITI_DR_USER = 'root'
    $env:STARFINITI_DR_PASSWORD = $rootPassword
    $env:STARFINITI_DR_HOST = "127.0.0.1:$($lock.mariadb.port)"
    & $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\disaster-recovery-verify.php')
    if ($LASTEXITCODE -ne 0) { throw 'Restored application verification failed.' }
    Write-Output "Disaster-recovery rehearsal passed: $($tables.Count) search tables and Starfiniti options matched; backup SHA256 $backupHash; restored application search passed."
} finally {
    Remove-Item Env:STARFINITI_DR_DATABASE,Env:STARFINITI_DR_USER,Env:STARFINITI_DR_PASSWORD,Env:STARFINITI_DR_HOST -ErrorAction SilentlyContinue
    if ($databaseCreated) {
        Invoke-AdminSql "DROP DATABASE IF EXISTS ``$restoreDatabase``;" | Out-Null
        $remaining = (Invoke-AdminSql "SELECT COUNT(*) FROM information_schema.schemata WHERE schema_name='$restoreDatabase';") | Select-Object -First 1
        if ([int]$remaining -ne 0) { throw 'Disposable disaster-recovery database cleanup failed.' }
    }
    if (Test-Path -LiteralPath $temporary) {
        $resolvedState = (Resolve-Path -LiteralPath $stateRoot).Path.TrimEnd('\') + '\'
        $resolvedTemporary = (Resolve-Path -LiteralPath $temporary).Path
        if (-not $resolvedTemporary.StartsWith($resolvedState, [StringComparison]::OrdinalIgnoreCase)) { throw 'Refusing unsafe disaster-recovery file cleanup.' }
        Remove-Item -LiteralPath $resolvedTemporary -Recurse -Force
    }
}
