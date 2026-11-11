[CmdletBinding()]
param(
    [ValidateSet('setup', 'start', 'stop', 'status')]
    [string] $Action = 'status'
)

$ErrorActionPreference = 'Stop'
. (Join-Path $PSScriptRoot 'powershell-compat.ps1')
$repoRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) { $env:STARFINITI_SEARCH_RUNTIME_ROOT } else { Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters' }
$lock = Get-Content (Join-Path $repoRoot 'config\runtime-lock.json') -Raw | ConvertFrom-Json
$php = Join-Path $runtimeRoot "php-$($lock.php.version)\php.exe"
$wpCli = Join-Path $runtimeRoot "packages\wp-cli-$($lock.wpCli.version).phar"
$siteRoot = Join-Path $runtimeRoot 'sites\fibofilters-baseline\wordpress'
$siteUrl = 'http://127.0.0.1:8089'
$serverMarker = '127.0.0.1:8089'
$logsRoot = Join-Path $runtimeRoot 'logs'
$env:HTTP_HOST = $serverMarker

function Read-Secrets {
    $values = @{}
    foreach ($line in Get-Content (Join-Path $runtimeRoot 'state\runtime.env')) {
        if ($line -match '^([^#=]+)=(.*)$') { $values[$matches[1]] = $matches[2] }
    }
    return $values
}

function Invoke-Wp {
    $log = Join-Path $logsRoot 'fibofilters-wp-cli.stderr.log'
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try {
        & $php $wpCli --path=$siteRoot @args 2>> $log
        $exitCode = $LASTEXITCODE
    } finally { $ErrorActionPreference = $previousPreference }
    if ($exitCode -ne 0) { throw "WP-CLI failed with exit code $exitCode. See $log." }
}

function Test-Wp {
    $previousPreference = $ErrorActionPreference
    $ErrorActionPreference = 'Continue'
    try { & $php $wpCli --path=$siteRoot @args 2>$null | Out-Null; $exitCode = $LASTEXITCODE }
    finally { $ErrorActionPreference = $previousPreference }
    return $exitCode -eq 0
}

function Get-WebProcess {
    return Get-CimInstance Win32_Process | Where-Object { $_.Name -eq 'php.exe' -and $_.CommandLine -like "*$serverMarker*" -and $_.ExecutablePath -eq $php } | Select-Object -First 1
}

function Setup-Baseline {
    powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'local-runtime.ps1') setup
    if ($LASTEXITCODE -ne 0) { throw 'Local runtime setup failed.' }
    powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'local-runtime.ps1') start
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB startup failed.' }

    if (-not (Test-Path (Join-Path $siteRoot 'wp-settings.php'))) {
        $extractRoot = Split-Path -Parent $siteRoot
        New-Item -ItemType Directory -Force -Path $extractRoot | Out-Null
        Expand-Archive -LiteralPath (Join-Path $runtimeRoot "packages\wordpress-$($lock.wordpress.version).zip") -DestinationPath $extractRoot
    }

    $secrets = Read-Secrets
    $mariaRoot = Join-Path $runtimeRoot "mariadb-$($lock.mariadb.version)\mariadb-$($lock.mariadb.version)-winx64"
    $client = Join-Path $mariaRoot 'bin\mariadb.exe'
    $dbPassword = $secrets.WORDPRESS_DB_PASSWORD.Replace("'", "''")
    $sql = "CREATE DATABASE IF NOT EXISTS starfiniti_filters_baseline CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_520_ci; CREATE USER IF NOT EXISTS 'starfiniti_wp'@'127.0.0.1' IDENTIFIED BY '$dbPassword'; GRANT ALL PRIVILEGES ON starfiniti_filters_baseline.* TO 'starfiniti_wp'@'127.0.0.1'; FLUSH PRIVILEGES;"
    & $client --protocol=tcp --host=127.0.0.1 --port=$($lock.mariadb.port) --user=root --password=$($secrets.MARIADB_ROOT_PASSWORD) --execute=$sql
    if ($LASTEXITCODE -ne 0) { throw 'Baseline database provisioning failed.' }

    if (-not (Test-Path (Join-Path $siteRoot 'wp-config.php'))) {
        Invoke-Wp config create --dbname=starfiniti_filters_baseline --dbuser=starfiniti_wp --dbpass=$($secrets.WORDPRESS_DB_PASSWORD) --dbhost=127.0.0.1:$($lock.mariadb.port) --dbcharset=utf8mb4 --skip-check
    }
    Invoke-Wp config set WP_ENVIRONMENT_TYPE local
    Invoke-Wp config set WP_DEBUG true --raw
    Invoke-Wp config set WP_DEBUG_LOG true --raw
    Invoke-Wp config set WP_DEBUG_DISPLAY false --raw
    if (-not (Test-Wp --skip-plugins --skip-themes core is-installed)) {
        Invoke-Wp core install --url=$siteUrl --title='FiboFilters Real MariaDB Baseline' --admin_user=admin --admin_password=$($secrets.WORDPRESS_ADMIN_PASSWORD) --admin_email=admin@starfiniti.invalid --skip-email
    }
    Invoke-Wp --skip-plugins --skip-themes core verify-checksums --version=$($lock.wordpress.version)

    $wooArchive = Join-Path $repoRoot $lock.woocommerce.source
    if (-not (Test-Wp --skip-plugins --skip-themes plugin is-installed woocommerce)) { Invoke-Wp plugin install $wooArchive }
    if (-not (Test-Wp --skip-plugins --skip-themes plugin is-active woocommerce)) { Invoke-Wp plugin activate woocommerce }

    $fixtureTarget = Join-Path $siteRoot 'wp-content\plugins\starfiniti-golden-fixtures'
    New-Item -ItemType Directory -Force -Path $fixtureTarget | Out-Null
    Copy-Item -LiteralPath (Join-Path $repoRoot 'tests\fixtures\starfiniti-golden-fixtures\starfiniti-golden-fixtures.php') -Destination (Join-Path $fixtureTarget 'starfiniti-golden-fixtures.php') -Force
    if (-not (Test-Wp --skip-plugins --skip-themes plugin is-active starfiniti-golden-fixtures)) { Invoke-Wp plugin activate starfiniti-golden-fixtures }

    $filtersArchive = Join-Path $repoRoot 'audit\packages\fibofilters-pro.1.12.1.zip'
    if ((Get-StarfinitiFileHash $filtersArchive -Algorithm SHA256).Hash -ne '3E8FEFBFE1C1FBA3126E691F67F2D1C5437E39AC33D5AB6DE44BD912456355D2') { throw 'FiboFilters package checksum mismatch.' }
    if (-not (Test-Wp --skip-plugins --skip-themes plugin is-installed fibofilters-pro)) { Invoke-Wp plugin install $filtersArchive }
    if (-not (Test-Wp --skip-plugins --skip-themes plugin is-active fibofilters-pro)) { Invoke-Wp plugin activate fibofilters-pro }
    Invoke-Wp option update permalink_structure '/%postname%/'
    Invoke-Wp rewrite flush
    Write-Output "FiboFilters real-MariaDB baseline is installed at $siteRoot."
}

function Start-Baseline {
    powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'local-runtime.ps1') start
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB startup failed.' }
    $existing = Get-WebProcess
    if ($existing) { Write-Output "FiboFilters baseline is already running at $siteUrl (PID $($existing.ProcessId))."; return }
    if (-not (Test-Path (Join-Path $siteRoot 'wp-config.php'))) { throw 'Run fibofilters-baseline.ps1 setup first.' }
    $stdout = Join-Path $logsRoot 'fibofilters.stdout.log'
    $stderr = Join-Path $logsRoot 'fibofilters.stderr.log'
    $process = Start-Process -FilePath $php -ArgumentList '-S', $serverMarker -WorkingDirectory $siteRoot -WindowStyle Hidden -RedirectStandardOutput $stdout -RedirectStandardError $stderr -PassThru
    for ($attempt = 0; $attempt -lt 40; ++$attempt) {
        Start-Sleep -Milliseconds 250
        if ($process.HasExited) { throw "FiboFilters web server exited. See $stderr." }
        try { if ((Invoke-WebRequest $siteUrl -UseBasicParsing -TimeoutSec 2).StatusCode -eq 200) { Write-Output "FiboFilters baseline is ready at $siteUrl (PID $($process.Id))."; return } } catch { }
    }
    throw "FiboFilters baseline did not become ready. See $stderr."
}

function Stop-Baseline {
    $process = Get-WebProcess
    if ($process) { Stop-Process -Id $process.ProcessId; Write-Output 'FiboFilters baseline stopped.' }
    else { Write-Output 'FiboFilters baseline is not running.' }
}

function Show-Status {
    $process = Get-WebProcess
    if ($process) { Write-Output "FiboFilters baseline: running at $siteUrl (PID $($process.ProcessId))" }
    elseif (Test-Path (Join-Path $siteRoot 'wp-config.php')) { Write-Output 'FiboFilters baseline: installed, web server stopped' }
    else { Write-Output 'FiboFilters baseline: not installed' }
}

switch ($Action) {
    'setup' { Setup-Baseline }
    'start' { Start-Baseline }
    'stop' { Stop-Baseline }
    'status' { Show-Status }
}
