function Get-StarfinitiFileHash {
    [CmdletBinding()]
    param(
        [Parameter(Mandatory, Position = 0)]
        [Alias('Path')]
        [string] $LiteralPath,

        [ValidateSet('SHA256', 'SHA384', 'SHA512')]
        [string] $Algorithm = 'SHA256'
    )

    $resolvedPath = (Resolve-Path -LiteralPath $LiteralPath -ErrorAction Stop).ProviderPath
    $hasher = switch ($Algorithm) {
        'SHA256' { [System.Security.Cryptography.SHA256]::Create() }
        'SHA384' { [System.Security.Cryptography.SHA384]::Create() }
        'SHA512' { [System.Security.Cryptography.SHA512]::Create() }
    }
    $stream = [System.IO.File]::OpenRead($resolvedPath)
    try {
        $hashBytes = $hasher.ComputeHash($stream)
    } finally {
        $stream.Dispose()
        $hasher.Dispose()
    }

    [pscustomobject]@{
        Algorithm = $Algorithm
        Hash = ([System.BitConverter]::ToString($hashBytes)).Replace('-', '')
        Path = $resolvedPath
    }
}
