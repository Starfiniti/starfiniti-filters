[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) { $env:STARFINITI_SEARCH_RUNTIME_ROOT } else { Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters' }
$lock = Get-Content -LiteralPath (Join-Path $repoRoot 'config\runtime-lock.json') -Raw | ConvertFrom-Json
$php = Join-Path $runtimeRoot "php-$($lock.php.version)\php.exe"
$wpCli = Join-Path $runtimeRoot "packages\wp-cli-$($lock.wpCli.version).phar"
$siteRoot = Join-Path $runtimeRoot 'sites\starfiniti-search\wordpress'
$fallbackNode = 'C:\Users\dejan\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
$node = if (Get-Command node -ErrorAction SilentlyContinue) { (Get-Command node).Source } elseif (Test-Path -LiteralPath $fallbackNode) { $fallbackNode } else { throw 'Node.js 24 or newer is required.' }
$env:HTTP_HOST = '127.0.0.1:8088'

foreach ($required in @($php, $wpCli, (Join-Path $siteRoot 'wp-settings.php'))) {
    if (-not (Test-Path -LiteralPath $required)) { throw "The pinned WordPress runtime is not ready: $required" }
}

$status = Invoke-WebRequest -Uri 'http://127.0.0.1:8088/wp-json/' -UseBasicParsing -TimeoutSec 5
if ($status.StatusCode -ne 200) { throw 'The localhost WordPress runtime is not responding.' }

powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $repoRoot 'tools\test-mcp.ps1')
if ($LASTEXITCODE -ne 0) { throw 'The MCP compile and unit gate failed.' }

$label = "Starfiniti MCP qualification $([guid]::NewGuid().ToString('N'))"
$credential = $null
$applicationUuid = $null
$tempRoot = Join-Path ([IO.Path]::GetTempPath()) ("starfiniti-mcp-" + [guid]::NewGuid().ToString('N'))
New-Item -ItemType Directory -Path $tempRoot | Out-Null
$sitesFile = Join-Path $tempRoot 'sites.json'

try {
    $credential = (& $php $wpCli --path=$siteRoot --skip-plugins --skip-themes user application-password create admin $label --porcelain 2>$null | Select-Object -Last 1).Trim()
    if (-not $credential) { throw 'WordPress did not issue the temporary application password.' }
    $inventory = & $php $wpCli --path=$siteRoot --skip-plugins --skip-themes user application-password list admin --fields=uuid,name --format=json 2>$null | ConvertFrom-Json
    $applicationUuid = ($inventory | Where-Object { $_.name -eq $label } | Select-Object -First 1).uuid
    if (-not $applicationUuid) { throw 'The temporary application password could not be identified for cleanup.' }

    $registration = @(@{
        tenant_id = 'qualification'
        site_id = 'localhost'
        display_name = 'Local qualification store'
        environment = 'development'
        control_api_base = 'http://127.0.0.1:8088/wp-json/starfiniti-search/v1'
        credential_reference = 'env:STARFINITI_WP_CONTROL_QUALIFICATION'
        auth_type = 'application_password'
        allowed_operations = @('status', 'capabilities', 'configuration', 'operations', 'operation', 'audit', 'plan')
        installation_uuid = '00000000-0000-4000-8000-000000000002'
        control_contract_version = '1.0'
        status = 'active'
    })
    ConvertTo-Json -InputObject $registration -Depth 5 | Set-Content -LiteralPath $sitesFile -Encoding UTF8

    $env:STARFINITI_WP_CONTROL_QUALIFICATION = "admin:$credential"
    $env:STARFINITI_MCP_LOCAL_TRUST = '1'
    $env:STARFINITI_MCP_SITES_FILE = $sitesFile
    $env:STARFINITI_MCP_PRINCIPAL_JSON = '{"tenant_id":"qualification","subject":"localhost-qualification","client_id":"repository-gate","scopes":["search.read","search.config.read","search.index.plan"]}'
    & $node (Join-Path $repoRoot 'apps\search-ops-mcp\dist\test\live-smoke.js')
    if ($LASTEXITCODE -ne 0) { throw 'The live MCP smoke test failed.' }
} finally {
    Remove-Item Env:STARFINITI_WP_CONTROL_QUALIFICATION -ErrorAction SilentlyContinue
    Remove-Item Env:STARFINITI_MCP_LOCAL_TRUST -ErrorAction SilentlyContinue
    Remove-Item Env:STARFINITI_MCP_SITES_FILE -ErrorAction SilentlyContinue
    Remove-Item Env:STARFINITI_MCP_PRINCIPAL_JSON -ErrorAction SilentlyContinue
    if ($applicationUuid) {
        & $php $wpCli --path=$siteRoot --skip-plugins --skip-themes user application-password delete admin $applicationUuid 2>$null | Out-Null
        if ($LASTEXITCODE -ne 0) { Write-Warning 'The temporary WordPress application password could not be revoked automatically.' }
    }
    if ((Test-Path -LiteralPath $tempRoot) -and ([IO.Path]::GetFullPath($tempRoot)).StartsWith([IO.Path]::GetFullPath([IO.Path]::GetTempPath()), [StringComparison]::OrdinalIgnoreCase)) {
        Remove-Item -LiteralPath $tempRoot -Recurse -Force
    }
}
