[CmdletBinding()]
param(
    [Parameter(Mandatory = $true, Position = 0)]
    [string] $Script,
    [Parameter(ValueFromRemainingArguments = $true)]
    [string[]] $ScriptArguments
)

$ErrorActionPreference = 'Stop'
$fallback = 'C:\Users\dejan\.cache\codex-runtimes\codex-primary-runtime\dependencies\node\bin\node.exe'
$node = if (Get-Command node -ErrorAction SilentlyContinue) { (Get-Command node).Source } elseif (Test-Path -LiteralPath $fallback) { $fallback } else { throw 'Node.js 20 or newer is required.' }
& $node $Script @ScriptArguments
exit $LASTEXITCODE
