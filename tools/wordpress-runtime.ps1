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
$stateRoot = Join-Path $runtimeRoot 'state'
$logsRoot = Join-Path $runtimeRoot 'logs'
$lock = Get-Content (Join-Path $repoRoot 'config\runtime-lock.json') -Raw | ConvertFrom-Json
$php = Join-Path $runtimeRoot "php-$($lock.php.version)\php.exe"
$wpCli = Join-Path $runtimeRoot "packages\wp-cli-$($lock.wpCli.version).phar"
$siteRoot = Join-Path $runtimeRoot 'sites\starfiniti-search\wordpress'
$siteUrl = 'http://127.0.0.1:8088'
$serverMarker = '127.0.0.1:8088'
$env:HTTP_HOST = $serverMarker

function Read-Secrets {
    $path = Join-Path $stateRoot 'runtime.env'
    if (-not (Test-Path -LiteralPath $path)) { throw 'Local runtime is not initialized.' }
    $values = @{}
    foreach ($line in Get-Content -LiteralPath $path) {
        if ($line -match '^([^#=]+)=(.*)$') { $values[$matches[1]] = $matches[2] }
    }
    return $values
}

function Invoke-WpCli {
    $stderrLog = Join-Path $logsRoot 'wp-cli.stderr.log'
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $php $wpCli --path=$siteRoot @args 2>> $stderrLog
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousPreference
    }
    if ($exitCode -ne 0) {
        $details = if (Test-Path -LiteralPath $stderrLog) { (Get-Content -LiteralPath $stderrLog -Tail 20) -join [Environment]::NewLine } else { '' }
        throw "WP-CLI failed with exit code $exitCode.`n$details"
    }
}

function Test-WpCli {
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $php $wpCli --path=$siteRoot @args 2>$null | Out-Null
        $exitCode = $LASTEXITCODE
    } finally {
        $ErrorActionPreference = $previousPreference
    }
    return $exitCode -eq 0
}

function Get-WebProcess {
    return Get-CimInstance Win32_Process | Where-Object {
        $_.Name -eq 'php.exe' -and $_.CommandLine -like "*$serverMarker*" -and $_.ExecutablePath -eq $php
    } | Select-Object -First 1
}

function Setup-WordPress {
    powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'local-runtime.ps1') setup
    if ($LASTEXITCODE -ne 0) { throw 'Local runtime setup failed.' }
    powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'local-runtime.ps1') start
    if ($LASTEXITCODE -ne 0) { throw 'Local MariaDB startup failed.' }

    $archive = Join-Path $runtimeRoot "packages\wordpress-$($lock.wordpress.version).zip"
    if (-not (Test-Path -LiteralPath (Join-Path $siteRoot 'wp-settings.php'))) {
        $extractRoot = Split-Path -Parent $siteRoot
        New-Item -ItemType Directory -Force -Path $extractRoot | Out-Null
        Expand-Archive -LiteralPath $archive -DestinationPath $extractRoot
    }

    $secrets = Read-Secrets
    $mariaRoot = Join-Path $runtimeRoot "mariadb-$($lock.mariadb.version)\mariadb-$($lock.mariadb.version)-winx64"
    $client = Join-Path $mariaRoot 'bin\mariadb.exe'
    $dbPassword = $secrets.WORDPRESS_DB_PASSWORD.Replace("'", "''")
    $sql = @"
CREATE DATABASE IF NOT EXISTS starfiniti_search_test CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci;
CREATE USER IF NOT EXISTS 'starfiniti_wp'@'127.0.0.1' IDENTIFIED BY '$dbPassword';
ALTER USER 'starfiniti_wp'@'127.0.0.1' IDENTIFIED BY '$dbPassword';
GRANT ALL PRIVILEGES ON starfiniti_search_test.* TO 'starfiniti_wp'@'127.0.0.1';
FLUSH PRIVILEGES;
"@
    & $client --protocol=tcp --host=127.0.0.1 --port=$($lock.mariadb.port) --user=root --password=$($secrets.MARIADB_ROOT_PASSWORD) --execute=$sql
    if ($LASTEXITCODE -ne 0) { throw 'Unable to provision the WordPress database.' }

    if (-not (Test-Path -LiteralPath (Join-Path $siteRoot 'wp-config.php'))) {
        Invoke-WpCli config create --dbname=starfiniti_search_test --dbuser=starfiniti_wp --dbpass=$($secrets.WORDPRESS_DB_PASSWORD) --dbhost=127.0.0.1:$($lock.mariadb.port) --dbcharset=utf8mb4 --skip-check
    }
    Invoke-WpCli config set WP_ENVIRONMENT_TYPE local
    Invoke-WpCli config set WP_DEBUG true --raw
    Invoke-WpCli config set WP_DEBUG_LOG true --raw
    Invoke-WpCli config set WP_DEBUG_DISPLAY false --raw
    Invoke-WpCli config set DISALLOW_FILE_EDIT true --raw

    if (-not (Test-WpCli core is-installed)) {
        Invoke-WpCli core install --url=$siteUrl --title='Starfiniti Search Qualification' --admin_user=admin --admin_password=$($secrets.WORDPRESS_ADMIN_PASSWORD) --admin_email=admin@starfiniti.invalid --skip-email
    }

    Invoke-WpCli core verify-checksums --version=$($lock.wordpress.version)
    $wooArchive = Join-Path $repoRoot $lock.woocommerce.source
    if (-not (Test-Path -LiteralPath $wooArchive)) {
        $wooArchive = Join-Path $runtimeRoot "packages\woocommerce-$($lock.woocommerce.version)-official.zip"
        if (-not (Test-Path -LiteralPath $wooArchive)) {
            Invoke-WebRequest -Uri $lock.woocommerce.distributionUrl -OutFile $wooArchive -UseBasicParsing
        }
    }
    $wooHash = (Get-StarfinitiFileHash -LiteralPath $wooArchive -Algorithm SHA256).Hash
    if ($wooHash -ne $lock.woocommerce.sha256) { throw 'WooCommerce archive checksum mismatch.' }
    if (-not (Test-WpCli plugin is-installed woocommerce)) { Invoke-WpCli plugin install $wooArchive }
    if (-not (Test-WpCli plugin is-active woocommerce)) { Invoke-WpCli plugin activate woocommerce }

    $pluginCheckArchive = Join-Path $runtimeRoot "packages\plugin-check-$($lock.pluginCheck.version)-official.zip"
    if (-not (Test-Path -LiteralPath $pluginCheckArchive)) {
        Invoke-WebRequest -Uri $lock.pluginCheck.distributionUrl -OutFile $pluginCheckArchive -UseBasicParsing
    }
    $pluginCheckHash = (Get-StarfinitiFileHash -LiteralPath $pluginCheckArchive -Algorithm SHA256).Hash
    if ($pluginCheckHash -ne $lock.pluginCheck.sha256) { throw 'Plugin Check archive checksum mismatch.' }
    if (-not (Test-WpCli plugin is-installed plugin-check)) { Invoke-WpCli plugin install $pluginCheckArchive }
    if (-not (Test-WpCli plugin is-active plugin-check)) { Invoke-WpCli plugin activate plugin-check }

    $fixtureSource = Join-Path $repoRoot 'tests\fixtures\starfiniti-golden-fixtures'
    $fixtureTarget = Join-Path $siteRoot 'wp-content\plugins\starfiniti-golden-fixtures'
    New-Item -ItemType Directory -Force -Path $fixtureTarget | Out-Null
    Copy-Item -LiteralPath (Join-Path $fixtureSource 'starfiniti-golden-fixtures.php') -Destination (Join-Path $fixtureTarget 'starfiniti-golden-fixtures.php') -Force
    if (-not (Test-WpCli plugin is-active starfiniti-golden-fixtures)) { Invoke-WpCli plugin activate starfiniti-golden-fixtures }

    $pluginSource = Join-Path $repoRoot 'plugin\starfiniti-search'
    $pluginTarget = Join-Path $siteRoot 'wp-content\plugins\starfiniti-search'
    New-Item -ItemType Directory -Force -Path $pluginTarget | Out-Null
    Copy-Item -Path (Join-Path $pluginSource '*') -Destination $pluginTarget -Recurse -Force
    if (-not (Test-WpCli plugin is-active starfiniti-search)) { Invoke-WpCli plugin activate starfiniti-search }
    Invoke-WpCli option update permalink_structure '/%postname%/'
    Invoke-WpCli rewrite flush --hard
    Write-Output "WordPress $($lock.wordpress.version) with WooCommerce $($lock.woocommerce.version) and Plugin Check $($lock.pluginCheck.version) is ready at $siteRoot."
}

function Start-WordPress {
    powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'local-runtime.ps1') start
    if ($LASTEXITCODE -ne 0) { throw 'Local MariaDB startup failed.' }
    $existing = Get-WebProcess
    if ($existing) {
        Write-Output "WordPress is already running at $siteUrl (PID $($existing.ProcessId))."
        return
    }
    if (-not (Test-Path -LiteralPath (Join-Path $siteRoot 'wp-config.php'))) { throw 'Run wordpress-runtime.ps1 setup first.' }
    New-Item -ItemType Directory -Force -Path $logsRoot | Out-Null
    $stdout = Join-Path $logsRoot 'wordpress.stdout.log'
    $stderr = Join-Path $logsRoot 'wordpress.stderr.log'
    $process = Start-Process -FilePath $php -ArgumentList '-S', $serverMarker -WorkingDirectory $siteRoot -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
    for ($attempt = 0; $attempt -lt 40; $attempt++) {
        Start-Sleep -Milliseconds 250
        if ($process.HasExited) { throw "PHP web server exited with code $($process.ExitCode). See $stderr." }
        try {
            $response = Invoke-WebRequest -Uri $siteUrl -UseBasicParsing -TimeoutSec 2
            if ($response.StatusCode -ge 200 -and $response.StatusCode -lt 500) {
                Write-Output "WordPress is ready at $siteUrl (PID $($process.Id))."
                return
            }
        } catch { }
    }
    throw "WordPress did not become ready. See $stderr."
}

function Stop-WordPress {
    $process = Get-WebProcess
    if (-not $process) { Write-Output 'WordPress web server is not running.'; return }
    Stop-Process -Id $process.ProcessId
    Write-Output 'WordPress web server stopped.'
}

function Show-Status {
    $process = Get-WebProcess
    if ($process) { Write-Output "WordPress: running at $siteUrl (PID $($process.ProcessId))" }
    elseif (Test-Path -LiteralPath (Join-Path $siteRoot 'wp-config.php')) { Write-Output 'WordPress: installed, web server stopped' }
    else { Write-Output 'WordPress: not installed' }
}

switch ($Action) {
    'setup' { Setup-WordPress }
    'start' { Start-WordPress }
    'stop' { Stop-WordPress }
    'status' { Show-Status }
}
