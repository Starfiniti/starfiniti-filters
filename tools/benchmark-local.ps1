[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$runtimeRoot = if ($env:STARFINITI_SEARCH_RUNTIME_ROOT) { $env:STARFINITI_SEARCH_RUNTIME_ROOT } else { Join-Path $env:LOCALAPPDATA 'StarfinitiSearch\starfiniti-filters' }
$maria = Join-Path $runtimeRoot 'mariadb-11.4.10\mariadb-11.4.10-winx64\bin\mariadb.exe'
$secrets = @{}
Get-Content (Join-Path $runtimeRoot 'state\runtime.env') | ForEach-Object { if ($_ -match '^([^=]+)=(.*)$') { $secrets[$matches[1]] = $matches[2] } }
$connection = @('--protocol=tcp', '--host=127.0.0.1', '--port=3307', '--user=root', "--password=$($secrets.MARIADB_ROOT_PASSWORD)", 'starfiniti_search_test', '--batch', '--skip-column-names')
function Invoke-Sql([string] $Sql) {
    & $maria @connection --execute=$Sql
    if ($LASTEXITCODE -ne 0) { throw 'MariaDB benchmark command failed.' }
}

$generation = 1
$suffix = [string]$PID
$documents = "wp_sfs_benchmark_${suffix}_documents"
$terms = "wp_sfs_benchmark_${suffix}_terms"
$postings = "wp_sfs_benchmark_${suffix}_postings"
$cleanup = "DROP TABLE IF EXISTS $postings,$terms,$documents;"
$setup = @"
$cleanup
CREATE TABLE $documents LIKE wp_sfs_documents;
CREATE TABLE $terms LIKE wp_sfs_terms;
CREATE TABLE $postings LIKE wp_sfs_postings;
INSERT INTO $documents (generation_id,document_id,entity_type,entity_id,parent_id,locale,channel,searchable,catalog_visible,password_protected,scope_hash,sku,sku_normalized,title,title_normalized,search_text,price_minor,checksum,document_json,updated_at)
SELECT $generation,CONCAT('benchmark:',seq),'product',seq,NULL,'en_US','storefront',1,1,0,SHA2('public',256),CONCAT('BENCH-',seq),CONCAT('bench',seq),CONCAT('Benchmark Product ',seq),CONCAT('benchmark product ',seq),CONCAT(' benchmark product ',seq,' '),1000 + MOD(seq,9000),SHA2(CONCAT('benchmark:',seq),256),JSON_OBJECT('identity',JSON_OBJECT('title',CONCAT('Benchmark Product ',seq),'sku',CONCAT('BENCH-',seq),'url','/'),'classification',JSON_OBJECT('category_ids',JSON_ARRAY(1),'category_paths',JSON_ARRAY('Benchmark')),'attributes',JSON_OBJECT(),'pricing',JSON_OBJECT('active_min_minor',1000 + MOD(seq,9000)),'inventory',JSON_OBJECT('stock_status','instock'),'media',JSON_OBJECT(),'quality',JSON_OBJECT()),UTC_TIMESTAMP(6)
FROM seq_1_to_100000;
INSERT INTO $terms (generation_id,locale,term,term_hash,document_frequency) VALUES ($generation,'en_US','benchmark',UNHEX(SHA2('benchmark',256)),100000);
INSERT INTO $postings (generation_id,term_id,document_id,field_code,term_frequency,weight)
SELECT $generation,(SELECT term_id FROM $terms WHERE generation_id=$generation AND locale='en_US' AND term_hash=UNHEX(SHA2('benchmark',256))),CONCAT('benchmark:',seq),1,1,12.0 FROM seq_1_to_100000;
"@
$query = "SELECT SQL_NO_CACHE d.document_id,r.raw_score,COUNT(*) OVER() AS bounded_total FROM (SELECT c.document_id,SUM(c.weight) + IF(MAX(c.sku_normalized)='benchmark',1000,0) AS raw_score FROM (SELECT p.document_id,p.weight,d.sku_normalized FROM $terms t STRAIGHT_JOIN $postings p FORCE INDEX (PRIMARY) ON p.term_id=t.term_id AND p.generation_id=t.generation_id STRAIGHT_JOIN $documents d ON d.document_id=p.document_id AND d.generation_id=p.generation_id WHERE p.generation_id=$generation AND d.searchable=1 AND d.password_protected=0 AND d.locale='en_US' AND d.channel='storefront' AND d.scope_hash=SHA2('public',256) AND t.term LIKE 'benchmark%' LIMIT 250) c GROUP BY c.document_id) r INNER JOIN $documents d ON d.generation_id=$generation AND d.document_id=r.document_id ORDER BY r.raw_score DESC,d.entity_id ASC LIMIT 10;"

try {
    $setupTime = Measure-Command { Invoke-Sql $setup | Out-Null }
    $documentCount = (Invoke-Sql "SELECT COUNT(*) FROM $documents WHERE generation_id=$generation;") | Select-Object -First 1
    if ([int]$documentCount -ne 100000) { throw 'Benchmark generation document count mismatch.' }
    Invoke-Sql $query | Out-Null
    $profileSql = "SET profiling_history_size=100; SET profiling=1;" + (($query + [Environment]::NewLine) * 20) + "SHOW PROFILES;"
    $profileOutput = @(Invoke-Sql $profileSql)
    $samples = @($profileOutput | ForEach-Object {
        if ($_ -match '^\d+\t([0-9.]+)\tSELECT SQL_NO_CACHE') { [double]::Parse($matches[1], [Globalization.CultureInfo]::InvariantCulture) * 1000 }
    })
    if ($samples.Count -ne 20) { throw "Expected 20 server-profile samples, received $($samples.Count)." }
    $ordered = @($samples | Sort-Object)
    $median = $ordered[[math]::Floor($ordered.Count / 2)]
    $p95 = $ordered[[math]::Min($ordered.Count - 1, [math]::Ceiling($ordered.Count * 0.95) - 1)]
    if ($median -gt 60 -or $p95 -gt 150) { throw ("Warm server budget failed: median {0:N1} ms (budget 60); p95 {1:N1} ms (budget 150)." -f $median, $p95) }
    Write-Output ("Benchmark documents: 100000; isolated setup: {0:N1} ms; warm server median: {1:N1} ms; p95: {2:N1} ms; 20 samples; budgets: median<=60 ms, p95<=150 ms" -f $setupTime.TotalMilliseconds, $median, $p95)
} finally {
    Invoke-Sql $cleanup | Out-Null
    $remaining = (Invoke-Sql "SELECT COUNT(*) FROM information_schema.tables WHERE table_schema=DATABASE() AND table_name IN ('$documents','$terms','$postings');") | Select-Object -First 1
    if ([int]$remaining -ne 0) { throw 'Benchmark cleanup failed.' }
}
