[CmdletBinding()]
param()

$ErrorActionPreference = 'Stop'
$repoRoot = Split-Path -Parent $PSScriptRoot
$appRoot = Join-Path $repoRoot 'apps\search-ops-mcp'
$fallback = 'C:\Users\dejan\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
$node = if (Get-Command node -ErrorAction SilentlyContinue) { (Get-Command node).Source } elseif (Test-Path -LiteralPath $fallback) { $fallback } else { throw 'Node.js 24 or newer is required for the MCP application.' }

$major = [int]((& $node -p 'parseInt(process.versions.node,10)') | Select-Object -First 1)
if ($major -lt 24) {
    throw "Node.js 24 or newer is required for the MCP application; found major version $major."
}

$compiler = Join-Path $appRoot 'node_modules\typescript\bin\tsc'
if (-not (Test-Path -LiteralPath $compiler)) {
    throw 'MCP dependencies are missing. Run pnpm install from the repository root.'
}

& $node $compiler -p (Join-Path $appRoot 'tsconfig.json')
if ($LASTEXITCODE -ne 0) { exit $LASTEXITCODE }

$tests = @(Get-ChildItem -LiteralPath (Join-Path $appRoot 'dist\test') -Filter '*.test.js' | ForEach-Object { $_.FullName })
if ($tests.Count -eq 0) {
    throw 'No compiled MCP tests were found.'
}

& $node --test @tests
exit $LASTEXITCODE
