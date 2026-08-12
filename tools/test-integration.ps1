$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) { $env:STARFINITI_SEARCH_RUNTIME_ROOT } else { Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters' }
$lock = Get-Content (Join-Path $repoRoot 'config\runtime-lock.json') -Raw | ConvertFrom-Json
$php = Join-Path $runtimeRoot "php-$($lock.php.version)\php.exe"
$wp = Join-Path $runtimeRoot "packages\wp-cli-$($lock.wpCli.version).phar"
$site = Join-Path $runtimeRoot 'sites\starfiniti-search\wordpress'
$maria = Join-Path $runtimeRoot "mariadb-$($lock.mariadb.version)\mariadb-$($lock.mariadb.version)-winx64\bin\mariadb.exe"
$env:HTTP_HOST = '127.0.0.1:8088'

function Assert-True([bool] $Condition, [string] $Message) {
    if (-not $Condition) { throw $Message }
}

function Search([string] $Query) {
    $url = 'http://127.0.0.1:8088/wp-json/starfiniti-search/v1/search?q=' + [uri]::EscapeDataString($Query) + '&size=10'
    return Invoke-RestMethod -Uri $url -Method Get -TimeoutSec 15
}

function Run-Actions {
    $pending = (& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\action-scheduler-pending.php')).Trim()
    if ($LASTEXITCODE -ne 0 -or $pending -notmatch '^\d+$') { throw 'Action Scheduler pending-work inspection failed.' }
    if ([int] $pending -eq 0) { return }
    # Scope the runner by the plugin's complete scheduled-hook allowlist instead of
    # by group. On a fresh site, another WordPress request can drain the final
    # action after the inspection above; Action Scheduler then removes/does not
    # resolve the now-empty group and its CLI rejects --group even though no work
    # failed. Hook scoping preserves isolation without that group-existence race.
    $hooks = 'starfiniti_search_build_generation,starfiniti_search_process_outbox,starfiniti_search_reconcile_catalog,starfiniti_search_reconcile_stale,starfiniti_search_seed_catalog'
    & $php $wp --path=$site action-scheduler run --hooks=$hooks --batch-size=100 --batches=10 --force | Out-Null
    if ($LASTEXITCODE -ne 0) {
        $remaining = (& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\action-scheduler-pending.php')).Trim()
        if ($LASTEXITCODE -ne 0 -or $remaining -notmatch '^\d+$' -or [int] $remaining -ne 0) { throw 'Action Scheduler execution failed.' }
    }
}

function Initialize-CatalogSeed {
    & $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\bootstrap-catalog.php') | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Initial catalog seed bootstrap failed.' }
}

powershell -NoProfile -ExecutionPolicy Bypass -File (Join-Path $PSScriptRoot 'wordpress-runtime.ps1') start | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'WordPress runtime did not start.' }

$pluginSource = Join-Path $repoRoot 'plugin\starfiniti-search'
$pluginTarget = Join-Path $site 'wp-content\plugins\starfiniti-search'
New-Item -ItemType Directory -Force -Path $pluginTarget | Out-Null
Copy-Item -Path (Join-Path $pluginSource '*') -Destination $pluginTarget -Recurse -Force
$previousPreference = $ErrorActionPreference
$ErrorActionPreference = 'Continue'
& $php $wp --path=$site --skip-themes plugin is-active starfiniti-search 2>$null | Out-Null
$isActive = $LASTEXITCODE -eq 0
$ErrorActionPreference = $previousPreference
if (-not $isActive) {
    & $php $wp --path=$site --skip-themes plugin activate starfiniti-search | Out-Null
    if ($LASTEXITCODE -ne 0) { throw 'Starfiniti Search activation failed.' }
}
Initialize-CatalogSeed
Run-Actions

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\schema-upgrade-v9-v10.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Schema 9 to 10 upgrade test failed.' }

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\configuration-lifecycle.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Configuration lifecycle test failed.' }

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\custom-fields.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Typed custom-field projection test failed.' }

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\analytics-failure-isolation.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Analytics failure-isolation test failed.' }

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\operation-lifecycle.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Operation lifecycle test failed.' }

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\control-rest.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Control REST test failed.' }

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\storefront-contract.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Storefront contract test failed.' }

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\setup-readiness.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Setup readiness test failed.' }

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\relevance-policy.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Relevance policy test failed.' }

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\admin-relevance.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Administrator relevance surface test failed.' }

Run-Actions
& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\continuation-scheduling.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Action Scheduler continuation regression test failed.' }

$exact = Search 'EXACT-001'
Assert-True ($exact.contract_version -eq '1.0') 'Search response contract version mismatch.'
Assert-True ($exact.provider -eq 'local') 'Local provider was not used.'
Assert-True ($exact.total -ge 1) 'Exact SKU returned no result.'
Assert-True ($exact.hits[0].projection.identity.title -eq 'Blue Alpine Shirt') 'Exact SKU did not rank the correct product first.'

$accent = Search 'Crna Kava'
Assert-True ([int]$accent.hits[0].entity_id -eq 12) 'Accent-folded query failed.'
foreach ($forbidden in @('Hidden Wholesale Belt', 'Password Protected Jacket', 'Private Operations Sample')) {
    Assert-True ((Search $forbidden).total -eq 0) "Forbidden product leaked for query: $forbidden"
}
Assert-True ((Search 'Red Trail Shirt').hits[0].projection.identity.title -eq 'Red Trail Shirt') 'Out-of-stock ranking fixture failed.'

if ((& $php $wp --path=$site option get starfiniti_search_active_index_schema) -ge '2') {
    $facetBody = @{
        query = ''
        filters = @{ field = 'inventory.stock_status'; op = 'eq'; value = 'instock' }
        facets = @('inventory.stock_status', 'classification.category_paths')
        sort = @(@{ field = 'pricing.active_min_minor'; direction = 'asc' })
        page = @{ number = 1; size = 10 }
    } | ConvertTo-Json -Depth 8
    $faceted = Invoke-RestMethod -Uri 'http://127.0.0.1:8088/wp-json/starfiniti-search/v1/search' -Method Post -ContentType 'application/json' -Body $facetBody -TimeoutSec 15
    Assert-True ($faceted.total -eq 3) 'Stock filter returned the wrong visible result count.'
    Assert-True ($faceted.facets.'inventory.stock_status'[0].count -eq 3) 'Filtered stock facet count is incorrect.'
    Assert-True ([int]$faceted.hits[0].entity_id -eq 12) 'Price sort did not return the least expensive in-stock product first.'

    $filteredExactBody = @{
        query = 'EXACT-001'
        filters = @{ field = 'inventory.stock_status'; op = 'eq'; value = 'outofstock' }
        facets = @()
        sort = @()
        page = @{ number = 1; size = 10 }
    } | ConvertTo-Json -Depth 8
    $filteredExact = Invoke-RestMethod -Uri 'http://127.0.0.1:8088/wp-json/starfiniti-search/v1/search' -Method Post -ContentType 'application/json' -Body $filteredExactBody -TimeoutSec 15
    Assert-True ($filteredExact.total -eq 0) 'Exact-SKU inclusion bypassed an active storefront filter.'
}

if ((& $php $wp --path=$site option get starfiniti_search_active_index_schema) -ge '3') {
    $phraseBody = @{
        query = '"Blue Alpine"'
        filters = $null
        facets = @()
        sort = @()
        page = @{ number = 1; size = 10 }
        options = @{ suggestion_mode = 'admin_test'; highlight = $true; include_explanation = $true }
    } | ConvertTo-Json -Depth 8
    $phrase = Invoke-RestMethod -Uri 'http://127.0.0.1:8088/wp-json/starfiniti-search/v1/search' -Method Post -ContentType 'application/json' -Body $phraseBody -TimeoutSec 15
    Assert-True ($phrase.total -eq 1) 'REST quoted phrase returned an incorrect total.'
    Assert-True ($phrase.hits[0].matched_fields -contains 'identity.title') 'REST matched fields omitted the title.'
    $segments = $phrase.hits[0].highlights.'identity.title'
    Assert-True ($segments.Count -ge 2) 'REST safe highlight segments were not returned.'
    Assert-True ((($segments | ConvertTo-Json -Compress) -notmatch '<[^>]+>')) 'Highlight response contained markup.'
    Assert-True ($null -eq $phrase.hits[0].explanation) 'Anonymous request received administrator-only relevance explanation.'

    $typo = Search 'Alpnie'
    Assert-True ($typo.hits[0].projection.identity.title -eq 'Blue Alpine Shirt') 'REST bounded typo recovery failed.'
    Assert-True (($typo.warnings -contains 'fuzzy_expansions_applied:1')) 'REST typo recovery did not declare its bounded expansion.'

    $budgetWatch = [System.Diagnostics.Stopwatch]::StartNew()
    $bounded = Search 'zzqxvbnm qqqwrtyu plmoknij asdfghjk qwertzui yxcvbnma poiuztre lkjhgfds'
    $budgetWatch.Stop()
    $fuzzyWarning = @($bounded.warnings | Where-Object { $_ -like 'fuzzy_expansions_applied:*' }) | Select-Object -First 1
    if ($fuzzyWarning) {
        Assert-True ([int]($fuzzyWarning -replace '^.*:', '') -le 16) 'Fuzzy expansion exceeded its declared global cap.'
    }
    Assert-True ($budgetWatch.ElapsedMilliseconds -lt 5000) 'Bounded fuzzy no-result query exceeded the integration latency guard.'
}

$env:STARFINITI_TEST_ENTITY_ID = '10'
$env:STARFINITI_TEST_PRODUCT_NAME = 'Blue Alpine Shirt Integration Update'
& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\update-product.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Fixture update failed.' }
Run-Actions
Assert-True ((Search 'Integration Update').hits[0].projection.identity.title -eq 'Blue Alpine Shirt Integration Update') 'Durable update did not reach the active index.'

$env:STARFINITI_TEST_PRODUCT_NAME = 'Blue Alpine Shirt'
& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\update-product.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Fixture restore failed.' }
Run-Actions
Assert-True ((Search 'EXACT-001').hits[0].projection.identity.title -eq 'Blue Alpine Shirt') 'Fixture restore did not reach the active index.'

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\reconciliation.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Catalog reconciliation test failed.' }

& $php $wp --path=$site eval-file (Join-Path $repoRoot 'tests\php\integration\outbox-resilience.php') | Out-Null
if ($LASTEXITCODE -ne 0) { throw 'Outbox resilience test failed.' }

$secrets = @{}
Get-Content (Join-Path $runtimeRoot 'state\runtime.env') | ForEach-Object { if ($_ -match '^([^=]+)=(.*)$') { $secrets[$matches[1]] = $matches[2] } }
$sql = "SET @active_generation=(SELECT option_value FROM wp_options WHERE option_name='starfiniti_search_active_generation'); SELECT COUNT(*) FROM wp_sfs_documents WHERE generation_id=@active_generation; SELECT COUNT(*) FROM wp_sfs_documents WHERE generation_id=@active_generation AND searchable=1; SELECT COUNT(*) FROM wp_sfs_sync_outbox; SELECT COUNT(*) FROM wp_sfs_quarantine;"
$counts = @(& $maria --protocol=tcp --host=127.0.0.1 --port=$($lock.mariadb.port) --user=root --password=$($secrets.MARIADB_ROOT_PASSWORD) --database=starfiniti_search_test --batch --skip-column-names --execute=$sql)
Assert-True ([int]$counts[0] -eq 7) 'Expected seven canonical fixture documents.'
Assert-True ([int]$counts[1] -eq 4) 'Expected exactly four public-searchable fixture documents.'
Assert-True ([int]$counts[2] -eq 0) 'Outbox was not drained.'
Assert-True ([int]$counts[3] -eq 0) 'Unexpected quarantined fixture documents.'

try {
    Invoke-WebRequest -Uri 'http://127.0.0.1:8088/wp-json/starfiniti-search/v1/status' -UseBasicParsing -TimeoutSec 10 | Out-Null
    throw 'Anonymous status endpoint access was allowed.'
} catch {
    $statusCode = [int]$_.Exception.Response.StatusCode
    Assert-True ($statusCode -in @(401, 403)) 'Status endpoint did not enforce administrator authorization.'
}

Write-Output 'Integration suite passed: schema, outbox, continuation scheduling, local index, ranking, visibility, REST security, configuration, analytics, capabilities, reconciliation, and immutable operation control.'
