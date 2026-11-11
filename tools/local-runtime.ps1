[CmdletBinding()]
param(
    [ValidateSet('setup', 'start', 'stop', 'status')]
    [string] $Action = 'status'
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'powershell-compat.ps1')
$repoRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) {
    $env:STARFINITI_SEARCH_RUNTIME_ROOT
} else {
    Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters'
}
$lock = Get-Content (Join-Path $repoRoot 'config\runtime-lock.json') -Raw | ConvertFrom-Json
$stateRoot = Join-Path $runtimeRoot 'state'
$logsRoot = Join-Path $runtimeRoot 'logs'
$secretsPath = Join-Path $stateRoot 'runtime.env'

function Assert-Hash {
    param([string] $Path, [string] $Algorithm, [string] $Expected)
    $actual = (Get-StarfinitiFileHash -LiteralPath $Path -Algorithm $Algorithm).Hash
    if ($actual -ne $Expected.ToUpperInvariant()) {
        throw "Checksum mismatch for $Path. Expected $Expected, got $actual."
    }
}

function Get-Package {
    param([string] $Url, [string] $Path, [string] $Algorithm, [string] $Hash)
    if (-not (Test-Path -LiteralPath $Path)) {
        Invoke-WebRequest -Uri $Url -OutFile $Path -UseBasicParsing
    }
    Assert-Hash -Path $Path -Algorithm $Algorithm -Expected $Hash
}

function New-Secret {
    $bytes = [byte[]]::new(32)
    $generator = [System.Security.Cryptography.RandomNumberGenerator]::Create()
    try { $generator.GetBytes($bytes) } finally { $generator.Dispose() }
    return [Convert]::ToBase64String($bytes).TrimEnd('=').Replace('+', '-').Replace('/', '_')
}

function Read-Secrets {
    if (-not (Test-Path -LiteralPath $secretsPath)) {
        throw "Missing $secretsPath. Run tools/local-runtime.ps1 setup."
    }
    $values = @{}
    foreach ($line in Get-Content -LiteralPath $secretsPath) {
        if ($line -match '^([^#=]+)=(.*)$') { $values[$matches[1]] = $matches[2] }
    }
    return $values
}

function Get-MariaRoot {
    return Join-Path $runtimeRoot "mariadb-$($lock.mariadb.version)\mariadb-$($lock.mariadb.version)-winx64"
}

function Get-MariaProcess {
    $dataDir = Join-Path $stateRoot 'mariadb-data'
    return Get-CimInstance Win32_Process | Where-Object {
        $_.Name -eq 'mariadbd.exe' -and $_.CommandLine -like "*$dataDir*"
    } | Select-Object -First 1
}

function Test-MariaReady {
    param([hashtable] $Secrets)
    $client = Join-Path (Get-MariaRoot) 'bin\mariadb.exe'
    if (-not (Test-Path -LiteralPath $client)) { return $false }
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $client --connect-timeout=2 --protocol=tcp --host=127.0.0.1 --port=$($lock.mariadb.port) --user=root --password=$($Secrets.MARIADB_ROOT_PASSWORD) --execute='SELECT 1;' 2>$null | Out-Null
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousPreference
    }
    return $exitCode -eq 0
}

function Setup-Runtime {
    New-Item -ItemType Directory -Force -Path (Join-Path $runtimeRoot 'packages'), $stateRoot, $logsRoot | Out-Null

    $phpArchive = Join-Path $runtimeRoot "packages\php-$($lock.php.version)-nts-Win32-vs16-x64.zip"
    Get-Package $lock.php.url $phpArchive 'SHA256' $lock.php.sha256
    $phpRoot = Join-Path $runtimeRoot "php-$($lock.php.version)"
    if (-not (Test-Path -LiteralPath (Join-Path $phpRoot 'php.exe'))) {
        Expand-Archive -LiteralPath $phpArchive -DestinationPath $phpRoot
    }
    $caBundle = Join-Path $runtimeRoot "packages\cacert-$($lock.caBundle.version).pem"
    Get-Package $lock.caBundle.url $caBundle 'SHA256' $lock.caBundle.sha256
    $phpTemplate = Get-Content (Join-Path $repoRoot 'config\runtime\php.ini.template') -Raw
    $extensionDir = (Join-Path $phpRoot 'ext').Replace('\', '/')
    $caBundlePath = $caBundle.Replace('\', '/')
    $phpTemplate.Replace('{{EXTENSION_DIR}}', $extensionDir).Replace('{{CA_BUNDLE}}', $caBundlePath) | Set-Content -LiteralPath (Join-Path $phpRoot 'php.ini') -Encoding UTF8

    $mariaArchive = Join-Path $runtimeRoot "packages\mariadb-$($lock.mariadb.version)-winx64.zip"
    Get-Package $lock.mariadb.url $mariaArchive 'SHA256' $lock.mariadb.sha256
    $mariaExtractRoot = Join-Path $runtimeRoot "mariadb-$($lock.mariadb.version)"
    if (-not (Test-Path -LiteralPath (Join-Path (Get-MariaRoot) 'bin\mariadbd.exe'))) {
        New-Item -ItemType Directory -Force -Path $mariaExtractRoot | Out-Null
        Expand-Archive -LiteralPath $mariaArchive -DestinationPath $mariaExtractRoot
    }

    if (-not (Test-Path -LiteralPath $secretsPath)) {
        @(
            "MARIADB_ROOT_PASSWORD=$(New-Secret)"
            "WORDPRESS_DB_PASSWORD=$(New-Secret)"
            "WORDPRESS_ADMIN_PASSWORD=$(New-Secret)"
        ) | Set-Content -LiteralPath $secretsPath -Encoding UTF8
    }
    $secrets = Read-Secrets
    $dataDir = Join-Path $stateRoot 'mariadb-data'
    if (-not (Test-Path -LiteralPath (Join-Path $dataDir 'mysql'))) {
        New-Item -ItemType Directory -Force -Path $dataDir | Out-Null
        $installer = Join-Path (Get-MariaRoot) 'bin\mariadb-install-db.exe'
        & $installer --datadir=$dataDir --password=$($secrets.MARIADB_ROOT_PASSWORD) --port=$($lock.mariadb.port)
        if ($LASTEXITCODE -ne 0) { throw 'MariaDB initialization failed.' }
    }

    $wpArchive = Join-Path $runtimeRoot "packages\wordpress-$($lock.wordpress.version).zip"
    Get-Package $lock.wordpress.url $wpArchive 'SHA256' $lock.wordpress.sha256
    $wpCli = Join-Path $runtimeRoot "packages\wp-cli-$($lock.wpCli.version).phar"
    Get-Package $lock.wpCli.url $wpCli 'SHA256' $lock.wpCli.sha256

    Write-Output "Runtime setup verified at ${runtimeRoot}: PHP $($lock.php.version), MariaDB $($lock.mariadb.version), WordPress $($lock.wordpress.version), WP-CLI $($lock.wpCli.version)."
}

function Start-Runtime {
    $secrets = Read-Secrets
    if (Test-MariaReady $secrets) {
        Write-Output "MariaDB is already ready on 127.0.0.1:$($lock.mariadb.port)."
        return
    }
    $server = Join-Path (Get-MariaRoot) 'bin\mariadbd.exe'
    $config = Join-Path $stateRoot 'mariadb-data\my.ini'
    $configArg = '--defaults-file="' + $config + '"'
    $stdout = Join-Path $logsRoot 'mariadb.stdout.log'
    $stderr = Join-Path $logsRoot 'mariadb.stderr.log'
    $process = Start-Process -FilePath $server -ArgumentList $configArg, '--bind-address=127.0.0.1' -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        Start-Sleep -Milliseconds 250
        if ($process.HasExited) { throw "MariaDB exited with code $($process.ExitCode). See $stderr." }
        if (Test-MariaReady $secrets) {
            Write-Output "MariaDB is ready on 127.0.0.1:$($lock.mariadb.port) (PID $($process.Id))."
            return
        }
    }
    throw "MariaDB did not become ready. See $stderr."
}

function Stop-Runtime {
    $process = Get-MariaProcess
    if (-not $process) {
        Write-Output 'MariaDB is not running.'
        return
    }
    $secrets = Read-Secrets
    $admin = Join-Path (Get-MariaRoot) 'bin\mariadb-admin.exe'
    & $admin --connect-timeout=2 --protocol=tcp --host=127.0.0.1 --port=$($lock.mariadb.port) --user=root --password=$($secrets.MARIADB_ROOT_PASSWORD) shutdown
    if ($LASTEXITCODE -ne 0) { throw 'Graceful MariaDB shutdown failed.' }
    Write-Output 'MariaDB stopped cleanly.'
}

function Show-Status {
    $php = Join-Path $runtimeRoot "php-$($lock.php.version)\php.exe"
    if (Test-Path -LiteralPath $php) { & $php -r 'echo PHP_VERSION, PHP_EOL;' }
    else { Write-Output 'PHP: not installed' }
    if (Test-Path -LiteralPath $secretsPath) {
        $secrets = Read-Secrets
        if (Test-MariaReady $secrets) { Write-Output "MariaDB: ready on 127.0.0.1:$($lock.mariadb.port)" }
        else { Write-Output 'MariaDB: stopped' }
    } else { Write-Output 'MariaDB: not initialized' }
}

switch ($Action) {
    'setup' { Setup-Runtime }
    'start' { Start-Runtime }
    'stop' { Stop-Runtime }
    'status' { Show-Status }
}
